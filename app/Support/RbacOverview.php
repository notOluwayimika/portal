<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Assembles the whole read-side payload for the super-admin RBAC console.
 *
 * Lives in Support rather than the controller for the same reason {@see DutySeparation} and
 * {@see RouteAccessMap} do: it is a query object with a testable shape, and pinning its output in a
 * unit test beats asserting on rendered Inertia props.
 *
 * FOUR QUERIES, REGARDLESS OF CATALOG SIZE. The grant graph is fetched once as roles-with-
 * permissions and INVERTED IN PHP to answer the permission→roles direction; asking the database
 * per permission would be ~74 queries to learn nothing the first result set did not already
 * contain. RbacOverviewTest pins the query count so that stays true.
 */
final class RbacOverview
{
    /**
     * @return array{
     *     groups: list<array<string, mixed>>,
     *     roles: list<array<string, mixed>>,
     *     sodPairs: list<array{maker: string, checker: string}>,
     *     stats: array<string, int>
     * }
     */
    public static function build(): array
    {
        // (1 + 1) The grant graph. Eager-loaded, so this is two queries total no matter how many
        // roles exist, and it is the ONLY source for both directions of the graph below.
        $roles = Role::with('permissions')
            ->where('guard_name', RbacSeeder::GUARD)
            ->whereNull('school_id')
            ->orderBy('name')
            ->get();

        /** @var array<string, list<string>> $rolePermissions role name => permission names */
        $rolePermissions = [];
        /** @var array<string, list<string>> $permissionRoles permission name => role names */
        $permissionRoles = [];

        foreach ($roles as $role) {
            $names = $role->permissions->pluck('name')->sort()->values()->all();
            $rolePermissions[$role->name] = $names;

            foreach ($names as $permission) {
                $permissionRoles[$permission][] = $role->name;
            }
        }

        $holderCounts = self::holderCounts($roles);
        $lastChanged = self::lastChanged($roles);

        return [
            // Shared with the school-admin console, so both render one taxonomy.
            'groups' => PermissionCatalog::grouped($permissionRoles),
            'roles' => self::roles($roles, $rolePermissions, $holderCounts, $lastChanged),
            'sodPairs' => DutySeparation::pairs(),
            'stats' => self::stats($roles, $rolePermissions, $permissionRoles, $holderCounts),
        ];
    }

    /**
     * (3) How many people hold each role.
     *
     * Queried straight off the pivot rather than through the User model, which matters twice over:
     * User carries a SchoolScope that would silently filter a cross-school count to the acting
     * school, and hydrating users to count them is wasteful. Nothing here needs a User instance.
     *
     * THREE DIFFERENT NUMBERS, because conflating them misleads. A user holding `teacher` in three
     * schools is THREE pivot rows but ONE person: `COUNT(*)` would report a role as three times
     * more widely held than it is. `holders` (distinct people) is the honest headline — the same
     * distinction SuperAdmin\AdminController makes with its `->unique()` — and `assignments` /
     * `schools` are surfaced beside it rather than instead of it.
     *
     * @param  Collection<int, Role>  $roles
     * @return array<int, array{holders: int, assignments: int, schools: int}>
     */
    private static function holderCounts(Collection $roles): array
    {
        return DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->whereIn('role_id', $roles->pluck('id')->all())
            ->groupBy('role_id')
            ->get([
                'role_id',
                DB::raw('COUNT(DISTINCT model_id) as holders'),
                DB::raw('COUNT(*) as assignments'),
                DB::raw('COUNT(DISTINCT school_id) as schools'),
            ])
            ->mapWithKeys(fn ($row) => [(int) $row->role_id => [
                'holders' => (int) $row->holders,
                'assignments' => (int) $row->assignments,
                'schools' => (int) $row->schools,
            ]])
            ->all();
    }

    /**
     * (4) When each role's grants last changed, from the audit trail the C1 listener already
     * writes. This is the "who touched this and when" the console exists to answer; without it an
     * admin has to leave the page to find out.
     *
     * @param  Collection<int, Role>  $roles
     * @return array<int, array{at: string, by: string|null}>
     */
    private static function lastChanged(Collection $roles): array
    {
        return Activity::query()
            ->where('log_name', 'rbac')
            ->where('subject_type', Role::class)
            ->whereIn('subject_id', $roles->pluck('id')->all())
            ->orderByDesc('id')
            ->get(['subject_id', 'created_at', 'causer_id'])
            // Newest first, so the first row seen per role wins and later ones are discarded.
            ->reduce(function (array $carry, Activity $activity) {
                $key = (int) $activity->subject_id;

                $carry[$key] ??= [
                    'at' => $activity->created_at?->toIso8601String(),
                    'by' => $activity->causer_id !== null ? (string) $activity->causer_id : null,
                ];

                return $carry;
            }, []);
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  array<string, list<string>>  $rolePermissions
     * @param  array<int, array{holders: int, assignments: int, schools: int}>  $holderCounts
     * @param  array<int, array{at: string, by: string|null}>  $lastChanged
     * @return list<array<string, mixed>>
     */
    private static function roles(
        Collection $roles,
        array $rolePermissions,
        array $holderCounts,
        array $lastChanged,
    ): array {
        return $roles->map(function (Role $role) use ($rolePermissions, $holderCounts, $lastChanged) {
            $name = (string) $role->getAttribute('name');
            $permissions = $rolePermissions[$name] ?? [];
            $counts = $holderCounts[$role->getKey()] ?? ['holders' => 0, 'assignments' => 0, 'schools' => 0];

            $isSuperAdmin = $name === 'super_admin';

            return [
                'name' => $name,
                // D1: the super_admin row is structurally immutable — SyncRolePermissionsRequest
                // refuses it in authorize(). It is now RENDERED read-only rather than hidden: it
                // holds four platform grants, not everything, and people should be able to see
                // that its authority comes from the Gate::before bypass instead.
                'editable' => ! $isSuperAdmin,
                'immutableReason' => $isSuperAdmin
                    ? 'super_admin is immutable here (ADR 0045). It holds only these platform grants — its wider authority is the Gate::before bypass, which never covers approve/reject actions (ADR 0040).'
                    : null,
                'twoFactorRequired' => (bool) $role->getAttribute('two_factor_required'),
                'permissions' => $permissions,
                'permissionCount' => count($permissions),
                'holderCount' => $counts['holders'],
                'assignmentCount' => $counts['assignments'],
                'schoolCount' => $counts['schools'],
                'holdsMaker' => self::holdsSide($permissions, checker: false),
                'holdsChecker' => self::holdsSide($permissions, checker: true),
                'lastChangedAt' => $lastChanged[$role->getKey()]['at'] ?? null,
            ];
        })->values()->all();
    }

    /**
     * Does this role hold any maker (or checker) ability? Drives the badge that makes a role's
     * position in a maker-checker flow visible before someone edits it into a violation.
     *
     * @param  list<string>  $permissions
     */
    private static function holdsSide(array $permissions, bool $checker): bool
    {
        foreach ($permissions as $permission) {
            $isChecker = ApprovalAbility::isExcludedFromSuperAdminBypass($permission);

            if ($checker && $isChecker) {
                return true;
            }

            // A maker is anything that is the matching maker of some checker — derived from the
            // convention, so a new pair counts the day it exists.
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
     * @param  array<string, list<string>>  $rolePermissions
     * @param  array<string, list<string>>  $permissionRoles
     * @param  array<int, array{holders: int, assignments: int, schools: int}>  $holderCounts
     * @return array<string, int>
     */
    private static function stats(
        Collection $roles,
        array $rolePermissions,
        array $permissionRoles,
        array $holderCounts,
    ): array {
        $catalog = Permission::values();

        return [
            'permissionCount' => count($catalog),
            'groupCount' => count(PermissionGroup::cases()),
            'roleCount' => $roles->count(),
            'grantCount' => array_sum(array_map('count', $rolePermissions)),
            'unusedPermissionCount' => count(array_filter(
                $catalog,
                fn (string $permission) => ! isset($permissionRoles[$permission]),
            )),
            'rolesWithoutHolders' => $roles->filter(
                fn (Role $role) => ($holderCounts[$role->getKey()]['holders'] ?? 0) === 0,
            )->count(),
            'twoFactorRoleCount' => $roles->filter(
                fn (Role $role) => (bool) $role->getAttribute('two_factor_required'),
            )->count(),
        ];
    }
}
