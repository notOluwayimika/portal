<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncUserRolesRequest;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\SchoolRbacOverview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The school-admin RBAC console (C5): who holds which roles in the ACTIVE School, what those roles
 * grant, and the history of changes. Roles are synced within that School's team.
 *
 * First human-driven role write — every prior role mutation was a seeder — so this is where C1's
 * role-mutation audit (LogRbacChange → activity('rbac'), causer = the acting admin) and the
 * User::assignRole null-team invariant run for real.
 *
 * READ-ONLY EXCEPT USER→ROLE. Role→permission editing is super-admin territory
 * (SyncRolePermissionsRequest refuses anyone else), so the roles and catalogue tabs here inform
 * the assignment rather than offering an edit the server would refuse.
 */
class SchoolUserController extends Controller
{
    public function index(Request $request)
    {
        // getOrFail() returns the School MODEL (not an id) — 403s when no
        // active school is selected.
        $school = ActiveSchool::getOrFail();

        $search = $request->string('search')->trim()->value() ?: null;
        $role = $request->string('role')->trim()->value() ?: null;

        return Inertia::render('admin/users/index', [
            ...SchoolRbacOverview::build(
                $school,
                $request->user(),
                $search,
                $role,
                max(1, $request->integer('page', 1)),
                min(100, max(5, $request->integer('per_page', SchoolRbacOverview::PER_PAGE))),
            ),
            'school' => ['name' => $school->name],
            // Tab in the URL, not component state: syncRoles returns back(), so a save from the
            // Users tab has to land back on it with the same search and page.
            'tab' => in_array($request->query('tab'), ['users', 'roles', 'permissions', 'history'], true)
                ? $request->query('tab')
                : 'users',
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

        // TRANSACTIONAL, and load-bearing: spatie's syncRoles holds no
        // transaction of its own (vendor-read, 7.4.1) — with events enabled it
        // is removeRole(current) then assignRole(new), two separate writes.
        // Unwrapped, any failure between them (attach error, listener
        // exception, process death) persists the detach and never attaches:
        // the edit LOCKS THE USER OUT by leaving them role-less. The
        // transaction makes detach+attach one atomic sync; the audit rows
        // (written by sync listeners inside this scope) roll back with it.
        DB::transaction(function () use ($request, $user) {
            $user->unsetRelation('roles');
            $user->syncRoles($request->validated('roles'));
        });

        $user->flushSchoolAccessCache();

        return back()->with('success', 'Roles updated.');
    }
}
