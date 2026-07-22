<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncUserRolesRequest;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The school-admin Users module (C5): list the ACTIVE School's users and sync
 * their roles within that School's team. First human-driven role write — every
 * prior role mutation was a seeder — so this is where C1's role-mutation audit
 * (LogRbacChange → activity('rbac'), causer = the acting admin) and the
 * User::assignRole null-team invariant run for real for the first time.
 */
class SchoolUserController extends Controller
{
    public function index()
    {
        // getOrFail() returns the School MODEL (not an id) — 403s when no
        // active school is selected.
        $school = ActiveSchool::getOrFail();

        // Scoped to the active School and read from model_has_roles — the S7
        // SINGLE SOURCE — not the school_user pivot or users.school_id (both
        // are removal targets; runtime-zero lint forbids new consumers). For a
        // ROLES module this is also the honest read: it lists exactly the
        // users who hold a role here, i.e. the rows this page manages. Never
        // a global user dump.
        $userIds = DB::table('model_has_roles')
            ->where('school_id', $school->id)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->unique();

        $users = User::whereIn('id', $userIds)
            ->orderBy('first_name')
            ->get();

        return Inertia::render('admin/users/index', [
            'users' => $users->map(fn (User $user): array => [
                'uuid' => $user->getRouteKey(),
                'name' => $user->getAttribute('full_name'),
                'email' => $user->getAttribute('email'),
                'is_super_admin' => $user->isSuperAdmin(),
                'is_self' => $user->getKey() === auth()->id(),
                'roles' => $user->getRoleNames()->values(),
            ])->values(),
            'assignable_roles' => $this->assignableRoles(),
        ]);
    }

    public function syncRoles(SyncUserRolesRequest $request, User $user)
    {
        // D4 — team-context assignment. SetSchoolContext has already set the
        // permissions team to the active School; getOrFail makes the
        // dependency explicit, and spatie's syncRoles routes through the
        // overridden User::assignRole, so a path that ever reached here with
        // no team would throw NullTeamRoleAssignmentException rather than
        // silently writing a global (null-team) role row.
        ActiveSchool::getOrFail();

        $user->unsetRelation('roles');
        $user->syncRoles($request->validated('roles'));
        $user->flushSchoolAccessCache();

        return back()->with('success', 'Roles updated.');
    }

    /**
     * What the CURRENT actor may assign — mirrors the SyncUserRolesRequest
     * rule (D2) so the UI offers exactly what the write will accept.
     *
     * @return list<string>
     */
    private function assignableRoles(): array
    {
        $roles = SyncUserRolesRequest::SCHOOL_ROLES;

        if (auth()->user()?->isSuperAdmin()) {
            $roles[] = 'admin';
        }

        return $roles;
    }
}
