<?php

namespace App\Support;

use App\Http\Requests\SyncUserRolesRequest;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The read side of the school-admin RBAC console (/setup/users).
 *
 * The school-scoped sibling of {@see RbacOverview}, and deliberately a different shape in two ways
 * the super-admin console does not have to care about:
 *
 * 1. USERS ARE PAGINATED AND SEARCHED ON THE SERVER. A real school has hundreds of users — 847 in
 *    the first school on the dev database — so shipping them all and filtering client-side would
 *    be a large payload rendering a thousand rows. The catalogue and the roles are still whole and
 *    filtered client-side, because those are small, complete sets where a client filter cannot
 *    disagree with a server count.
 *
 * 2. NOTHING HERE IS EDITABLE EXCEPT USER→ROLE. Role→permission is super-admin territory
 *    (SyncRolePermissionsRequest::authorize refuses anyone else), so the roles and catalogue views
 *    are read-only by construction. They exist to answer "what does this role actually let someone
 *    do in my school" — the question you have when assigning it, and one the old page could not
 *    answer at all.
 */
final class SchoolRbacOverview
{
    /** Users per page. Deliberately modest — this is a working list, not a directory. */
    public const PER_PAGE = 25;

    /**
     * @return array{
     *     users: array<string, mixed>,
     *     roles: list<array<string, mixed>>,
     *     groups: list<array<string, mixed>>,
     *     sodPairs: list<array{maker: string, checker: string}>,
     *     assignableRoles: list<string>,
     *     stats: array<string, int>,
     *     filters: array<string, mixed>
     * }
     */
    public static function build(
        School $school,
        ?User $actor,
        ?string $search,
        ?string $role,
        int $page,
        int $perPage,
    ): array {
        $roles = Role::with('permissions')
            ->where('guard_name', RbacSeeder::GUARD)
            ->whereNull('school_id')
            ->orderBy('name')
            ->get();

        /** @var array<string, list<string>> $permissionRoles */
        $permissionRoles = [];

        foreach ($roles as $roleModel) {
            foreach ($roleModel->permissions->pluck('name') as $permission) {
                $permissionRoles[$permission][] = $roleModel->name;
            }
        }

        // Every (user, role) pair held IN THIS SCHOOL. One query for the whole module: it drives
        // the user list, each user's role chips, and the per-role holder counts. The page it
        // replaces called getRoleNames() per user — 847 queries on the first school alone.
        $assignments = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.school_id', $school->id)
            ->where('model_has_roles.model_type', User::class)
            ->get(['model_has_roles.model_id as user_id', 'roles.name as role']);

        /** @var array<int, list<string>> $rolesByUser */
        $rolesByUser = [];

        foreach ($assignments as $row) {
            $rolesByUser[(int) $row->user_id][] = $row->role;
        }

        $users = self::users($school, $actor, $rolesByUser, $search, $role, $page, $perPage);

        return [
            'users' => $users,
            'roles' => self::roles($roles, $rolesByUser, $actor),
            // Same taxonomy the super-admin console renders — one builder, so the two pages can
            // never disagree about what a permission is.
            'groups' => PermissionCatalog::grouped($permissionRoles),
            'sodPairs' => DutySeparation::pairs(),
            'assignableRoles' => self::assignableRoles($actor),
            'stats' => self::stats($roles, $rolesByUser, $actor),
            'filters' => ['search' => $search, 'role' => $role],
        ];
    }

    /**
     * The user page: searched and paginated in SQL.
     *
     * Only users who hold a role in this school appear — read from `model_has_roles`, the S7
     * single source, rather than `users.school_id` or the `school_user` pivot (both removal
     * targets the runtime-zero lint forbids new consumers of). For a roles module that is also
     * the honest list: exactly the rows this page manages, never a global user dump.
     *
     * @param  array<int, list<string>>  $rolesByUser
     * @return array<string, mixed>
     */
    private static function users(
        School $school,
        ?User $actor,
        array $rolesByUser,
        ?string $search,
        ?string $role,
        int $page,
        int $perPage,
    ): array {
        $ids = array_keys($rolesByUser);

        if ($role !== null && $role !== '') {
            $ids = array_values(array_filter(
                $ids,
                fn (int $id) => in_array($role, $rolesByUser[$id], true),
            ));
        }

        $query = User::query()
            ->whereIn('id', $ids)
            ->when($search, fn ($q, string $term) => $q->where(function ($inner) use ($term) {
                $like = '%'.$term.'%';
                $inner->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            }))
            ->orderBy('first_name')
            ->orderBy('last_name');

        /** @var LengthAwarePaginator<int, User> $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $superAdminIds = self::superAdminIds();

        // Resolved ONCE for the page, not per row: the check flips the
        // permissions team and clears cached relations, which is the same
        // per-row cost the $superAdminIds set exists to avoid. Flag-independent
        // by design (see Impersonation::operatorMayImpersonate) — a can()-shaped
        // check here would show the action to every super_admin under the
        // bypass, including one whose grant is missing, who would then be
        // refused by the controller.
        $actorMayImpersonate = $actor !== null && Impersonation::operatorMayImpersonate($actor);

        return [
            'data' => collect($paginator->items())->map(function (User $user) use ($rolesByUser, $actor, $school, $superAdminIds, $actorMayImpersonate) {
                // Looked up from a prepared set rather than calling $user->isSuperAdmin() per row:
                // that method flips the permissions team, clears cached relations and re-queries,
                // so on a 25-row page it was 25 extra queries — the same shape of problem as the
                // per-user getRoleNames() this module previously did 847 times.
                $isSuperAdmin = in_array($user->getKey(), $superAdminIds, true);
                $isSelf = $actor !== null && $user->getKey() === $actor->getKey();

                return [
                    'uuid' => $user->getRouteKey(),
                    'name' => $user->getAttribute('full_name'),
                    'email' => $user->getAttribute('email'),
                    'roles' => $rolesByUser[$user->getKey()] ?? [],
                    // The UI never offers a write the server would refuse — these mirror the
                    // structural guards in SyncUserRolesRequest (D1 super_admin, D3 self), which
                    // are target-identity rules precisely because Gate::before would bypass a
                    // permission-shaped version of them.
                    // FAIL CLOSED with no actor: an off-request caller (a job, a command, a
                    // test) has nobody to be, and "everybody's roles are editable" is the wrong
                    // default for a hint the UI acts on. The server guards remain authoritative
                    // either way — this only decides what the page offers.
                    'editable' => $actor !== null && ! $isSuperAdmin && ! $isSelf,
                    'lockReason' => match (true) {
                        $isSuperAdmin => 'Super admin accounts are not editable from a school module.',
                        $isSelf => 'You cannot change your own roles.',
                        $actor === null => 'No acting user.',
                        default => null,
                    },
                    // Mirrors ImpersonationController's refusals exactly, for the
                    // same reason as `editable`: never offer a write the server
                    // will refuse. A super_admin target would be lateral
                    // escalation and self is a no-op; both are refused there.
                    // These users all hold a role in this school, so the
                    // target-can-access-the-school condition is satisfied by
                    // construction.
                    'impersonable' => $actorMayImpersonate && ! $isSuperAdmin && ! $isSelf,
                    'schoolId' => $school->id,
                ];
            })->all(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * Every super admin, in one query.
     *
     * Read straight off the pivot with a NULL school_id, because that is what a super_admin
     * assignment IS: the one legitimately team-less role (User::assignRole throws
     * NullTeamRoleAssignmentException for any other role assigned without a team). Going through
     * the model would re-apply the SchoolScope this deliberately sits outside of.
     *
     * @return list<int>
     */
    private static function superAdminIds(): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('roles.name', 'super_admin')
            ->where('roles.guard_name', RbacSeeder::GUARD)
            ->pluck('model_has_roles.model_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Roles as they apply HERE: what each grants, and how many people in this school hold it.
     *
     * @param  Collection<int, Role>  $roles
     * @param  array<int, list<string>>  $rolesByUser
     * @return list<array<string, mixed>>
     */
    private static function roles(Collection $roles, array $rolesByUser, ?User $actor): array
    {
        $holders = [];

        foreach ($rolesByUser as $names) {
            foreach (array_unique($names) as $name) {
                $holders[$name] = ($holders[$name] ?? 0) + 1;
            }
        }

        $assignable = self::assignableRoles($actor);

        return $roles->map(function (Role $role) use ($holders, $assignable) {
            $name = (string) $role->getAttribute('name');
            $permissions = $role->permissions->pluck('name')->sort()->values()->all();

            return [
                'name' => $name,
                'permissions' => $permissions,
                'permissionCount' => count($permissions),
                // Counted from the pivot rows of THIS school only — a role held widely elsewhere
                // reads as zero here, which is the useful local truth.
                'holderCount' => $holders[$name] ?? 0,
                'assignable' => in_array($name, $assignable, true),
                'unassignableReason' => in_array($name, $assignable, true)
                    ? null
                    : ($name === 'super_admin'
                        ? 'Platform role — never assignable from a school module.'
                        : 'Only a super admin may assign this role.'),
                'twoFactorRequired' => (bool) $role->getAttribute('two_factor_required'),
                'holdsMaker' => self::holdsSide($permissions, checker: false),
                'holdsChecker' => self::holdsSide($permissions, checker: true),
            ];
        })->values()->all();
    }

    /**
     * What the CURRENT actor may assign — mirrors SyncUserRolesRequest's rule (D2) so the UI
     * offers exactly what the write will accept, rather than presenting a control that 422s.
     *
     * @return list<string>
     */
    public static function assignableRoles(?User $actor): array
    {
        $roles = SyncUserRolesRequest::SCHOOL_ROLES;

        if ($actor?->isSuperAdmin()) {
            $roles[] = 'admin';
            $roles[] = 'executive_director';
        }

        return $roles;
    }

    /** @param  list<string>  $permissions */
    private static function holdsSide(array $permissions, bool $checker): bool
    {
        foreach ($permissions as $permission) {
            $isChecker = ApprovalAbility::isExcludedFromSuperAdminBypass($permission);

            if ($checker && $isChecker) {
                return true;
            }

            if (! $checker && ! $isChecker && self::isMaker($permission)) {
                return true;
            }
        }

        return false;
    }

    private static function isMaker(string $permission): bool
    {
        foreach (DutySeparation::pairs() as $pair) {
            if ($pair['maker'] === $permission) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  array<int, list<string>>  $rolesByUser
     * @return array<string, int>
     */
    private static function stats(Collection $roles, array $rolesByUser, ?User $actor): array
    {
        $held = collect($rolesByUser)->flatten()->unique();

        return [
            'userCount' => count($rolesByUser),
            'roleCount' => $roles->count(),
            'assignableRoleCount' => count(self::assignableRoles($actor)),
            // Roles nobody here holds. Not a problem in itself — it is the answer to "is anyone
            // covering this?", which is the staffing question a school admin actually has.
            'unusedRoleCount' => $roles->count() - $held->count(),
            'multiRoleUserCount' => count(array_filter(
                $rolesByUser,
                fn (array $names) => count(array_unique($names)) > 1,
            )),
        ];
    }
}
