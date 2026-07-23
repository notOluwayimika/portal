<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The single authoritative RBAC seeder (C1): roles + permissions + the
 * role→permission grants map, consolidated from the five seeders it replaces
 * (RoleSeeder, GuardianPermissionSeeder, StudentSubjectPermissionSeeder,
 * TeacherAssignmentPermissionSeeder, ActivityLogPermissionSeeder).
 *
 * Grants are WEB-guard only. The api-guard `super_admin` row created by
 * 2026_07_12_000004_seed_first_super_admin is deliberately untouched — that
 * migration owns the guard pair; this seeder never grants against it.
 *
 * Re-run semantics (non-destructive by default):
 *  - roles/permissions: firstOrCreate — never duplicated, never deleted
 *    (except pruning permissions the enum no longer declares, which by
 *    definition no code checks).
 *  - grants: applied only where the permission OR the role is newly created
 *    this run, so runtime grant/revoke edits survive re-seeding.
 *  - --fresh (via `php artisan rbac:sync --fresh`): exact syncPermissions
 *    reset to this map. Dev/CI/fresh installs.
 *
 * super_admin: granted the fallback set below even though Gate::before
 * (auth.gate_before_superadmin, default on) already passes it everything —
 * stripping the rows would silently couple super-admin access to that flag.
 * It deliberately holds NO maker–checker permission (result.submit/approve/
 * reject): ADR 0040 — super_admin never overrides maker–checker, and one
 * actor must not hold both sides of an SoD pair.
 */
class RbacSeeder extends Seeder
{
    public const GUARD = 'web';

    /**
     * Roles that require two-factor enrolment (C7). super_admin + admin only:
     * the 4 Finance roles are not seeded (step-0), their default is I6/Finance.
     */
    public const TWO_FACTOR_REQUIRED = ['super_admin', 'admin'];

    /**
     * super_admin's explicit PLATFORM-ADMIN set (ADR 0045 A2/A3, slice B2).
     * rbac.impersonate is the MASTER KEY: post-de-bypass its absence strands
     * every super_admin domain capability, which is why super_admin is
     * SELF-HEALED to exactly this set every run — the deliberate,
     * C6-immutability-justified exception to the non-destructive contract
     * (the matrix cannot edit this row, so there are no runtime grants to
     * preserve, and drift here is catastrophic rather than degrading).
     */
    public const SUPER_ADMIN_PLATFORM = [
        'rbac.impersonate',
        'rbac.manage_users',
        'activity_log.view_system',
        'activity_log.view_cross_school',
    ];

    /** Global (null-team) roles. Assignment to users is per-School (teams). */
    public const ROLES = [
        'super_admin',
        'admin',
        'principal',
        'head_of_school',
        'teacher',
        'guardian',
        'boarding_parent',
        'form_teacher',
        'registrar',
    ];

    /**
     * The canonical role→permission map (web guard). Consolidates the exact
     * grants the five legacy seeders produced — including the super_admin
     * grants the old fixture masked (guard-pair name collision, see C1 PR).
     *
     * The route-access tier (C2) gave `guardian` and `principal` their first
     * permissions: each route_access grant below mirrors, exactly, the role
     * list of the pre-swap role: middleware group it replaced —
     * RouteAccessParityTest holds the equivalence.
     *
     * @return array<string, list<string>>
     */
    public static function grantsMap(): array
    {
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_OWN->value,
        ];

        $activityAdmin = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_OWN->value,
            PermissionEnum::ACTIVITY_LOG_EXPORT->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_SENSITIVE->value,
        ];

        $guardianFull = [
            PermissionEnum::GUARDIAN_VIEW->value,
            PermissionEnum::GUARDIAN_UPDATE->value,
            PermissionEnum::GUARDIAN_UPDATE_CREDENTIALS->value,
            PermissionEnum::GUARDIAN_DETACH->value,
            PermissionEnum::GUARDIAN_ENABLE_LOGIN->value,
            PermissionEnum::GUARDIAN_CREATE->value,
            PermissionEnum::GUARDIAN_EXPORT->value,
            PermissionEnum::GUARDIAN_MESSAGE->value,
            PermissionEnum::GUARDIAN_VIEW_AUDIT->value,
            PermissionEnum::GUARDIAN_IMPORT->value,
        ];

        $studentSubjectFull = [
            PermissionEnum::STUDENT_SUBJECT_VIEW->value,
            PermissionEnum::STUDENT_SUBJECT_ADD_OPTIONAL->value,
            PermissionEnum::STUDENT_SUBJECT_DROP_OPTIONAL->value,
            PermissionEnum::STUDENT_SUBJECT_RESTORE->value,
            PermissionEnum::STUDENT_SUBJECT_VIEW_HISTORY->value,
        ];

        $assessments = [
            PermissionEnum::VIEW_BEHAVIORAL_ASSESSMENTS->value,
            PermissionEnum::CREATE_BEHAVIORAL_ASSESSMENTS->value,
            PermissionEnum::EDIT_BEHAVIORAL_ASSESSMENTS->value,
            PermissionEnum::VIEW_PSYCHOMOTOR_SKILLS->value,
            PermissionEnum::CREATE_PSYCHOMOTOR_SKILLS->value,
            PermissionEnum::EDIT_PSYCHOMOTOR_SKILLS->value,
        ];

        $enrollmentAdmin = [
            PermissionEnum::STUDENT_CURRICULUM_UNENROLL->value,
            PermissionEnum::CURRICULUM_SUBJECT_ARCHIVE->value,
            PermissionEnum::CURRICULUM_SUBJECT_RESTORE->value,
            // ADR 0044 enrollment lifecycle — admin + head_of_school.
            PermissionEnum::STUDENT_CURRICULUM_REGISTER->value,
            PermissionEnum::STUDENT_CURRICULUM_PROMOTE->value,
            PermissionEnum::STUDENT_CURRICULUM_UPDATE_STATUS->value,
        ];

        // ADR 0044 result checker side. Admin follows the ADR's recommendation
        // (a): approve/reject + view, and deliberately NOT result.submit — one
        // actor holding maker AND checker for the same result defeats SoD.
        $resultChecker = [
            PermissionEnum::RESULT_APPROVE->value,
            PermissionEnum::RESULT_REJECT->value,
            PermissionEnum::RESULT_VIEW_SCORES->value,
        ];

        // C2 route-access tier: each permission's holder set below reproduces
        // the role list of the pre-swap role: middleware group it replaced —
        // NOT a redesign of who should see what. RouteAccessParityTest diffs
        // live route access against the pre-swap fixture, so any drift here
        // (or in the route files) from the pre-swap sets is a red test.
        // super_admin deliberately gets none: its passage is Gate::before.

        return [
            'admin' => [
                ...$guardianFull,
                ...$studentSubjectFull,
                ...$enrollmentAdmin,
                ...$assessments,
                ...$activityAdmin,
                ...$resultChecker,
                PermissionEnum::MANAGE_TEACHER_ASSIGNMENTS->value,
                PermissionEnum::MANAGE_HEAD_OF_SCHOOL_COMMENTS->value,
                // Route access (C2)
                PermissionEnum::ADMIN_AREA_ACCESS->value,
                PermissionEnum::STUDENT_DIRECTORY_VIEW->value,
                PermissionEnum::RESULT_REVIEW_ACCESS->value,
                PermissionEnum::REPORT_VIEW->value,
                PermissionEnum::CURRICULUM_SUBJECT_VIEW->value,
                PermissionEnum::STUDENT_CURRICULUM_VIEW->value,
                PermissionEnum::DASHBOARD_VIEW->value,
                PermissionEnum::RESULT_VIEW->value,
                PermissionEnum::ACADEMIC_SETUP_MANAGE->value,
                PermissionEnum::PRINCIPAL_APPROVAL_MANAGE->value,
                PermissionEnum::FINANCE_ACCESS->value,
                PermissionEnum::ACADEMIC_DATA_VIEW->value,
                PermissionEnum::SCORE_MANAGE->value,
                PermissionEnum::STUDENT_STATUS_VIEW->value,
                PermissionEnum::STUDENT_VIEW->value,
                // RBAC administration (C5): the school-admin Users module.
                PermissionEnum::RBAC_MANAGE_USERS->value,
            ],
            'head_of_school' => [
                ...$guardianFull,
                ...$studentSubjectFull,
                ...$enrollmentAdmin,
                ...$assessments,
                ...$activityAdmin,
                ...$resultChecker,
                PermissionEnum::MANAGE_HEAD_OF_SCHOOL_COMMENTS->value,
                // Route access (C2)
                PermissionEnum::RESULT_REVIEW_ACCESS->value,
                PermissionEnum::REPORT_VIEW->value,
                PermissionEnum::CURRICULUM_SUBJECT_VIEW->value,
                PermissionEnum::STUDENT_CURRICULUM_VIEW->value,
                PermissionEnum::DASHBOARD_VIEW->value,
                PermissionEnum::RESULT_SIGNATURE_MANAGE->value,
                PermissionEnum::RESULT_VIEW->value,
                PermissionEnum::ACADEMIC_SETUP_MANAGE->value,
                PermissionEnum::ACADEMIC_DATA_VIEW->value,
                PermissionEnum::SCORE_MANAGE->value,
                PermissionEnum::STUDENT_STATUS_VIEW->value,
                PermissionEnum::STUDENT_VIEW->value,
            ],
            'teacher' => [
                PermissionEnum::STUDENT_SUBJECT_VIEW->value,
                ...$activityStaff,
                // ADR 0044 maker side: submit + read, never approve/reject.
                PermissionEnum::RESULT_SUBMIT->value,
                PermissionEnum::RESULT_VIEW_SCORES->value,
                // Route access (C2)
                PermissionEnum::CURRICULUM_SUBJECT_VIEW->value,
                PermissionEnum::STUDENT_CURRICULUM_VIEW->value,
                PermissionEnum::DASHBOARD_VIEW->value,
                PermissionEnum::ACADEMIC_DATA_VIEW->value,
                PermissionEnum::SCORE_MANAGE->value,
                PermissionEnum::STUDENT_STATUS_VIEW->value,
            ],
            'registrar' => [
                PermissionEnum::GUARDIAN_VIEW->value,
                PermissionEnum::GUARDIAN_UPDATE->value,
                PermissionEnum::GUARDIAN_DETACH->value,
                PermissionEnum::GUARDIAN_CREATE->value,
                // No route access: registrar appeared in no pre-swap role:
                // group, so it reaches no role-gated route — unchanged.
            ],
            'guardian' => [
                // Route access (C2) — guardian's first grants; exactly the
                // groups that listed `guardian` pre-swap.
                PermissionEnum::CURRICULUM_SUBJECT_VIEW->value,
                PermissionEnum::STUDENT_CURRICULUM_VIEW->value,
                PermissionEnum::DASHBOARD_VIEW->value,
                PermissionEnum::RESULT_VIEW->value,
                PermissionEnum::PARENT_PORTAL_ACCESS->value,
                PermissionEnum::STUDENT_STATUS_VIEW->value,
            ],
            'principal' => [
                // Route access (C2) — principal's first grants; exactly the
                // groups that listed `principal` pre-swap.
                PermissionEnum::STUDENT_DIRECTORY_VIEW->value,
                PermissionEnum::REPORT_VIEW->value,
                PermissionEnum::STUDENT_CURRICULUM_VIEW->value,
                PermissionEnum::DASHBOARD_VIEW->value,
                PermissionEnum::RESULT_SIGNATURE_MANAGE->value,
                PermissionEnum::RESULT_VIEW->value,
                PermissionEnum::PRINCIPAL_APPROVAL_MANAGE->value,
                PermissionEnum::STUDENT_STATUS_VIEW->value,
                PermissionEnum::STUDENT_VIEW->value,
            ],
            'boarding_parent' => [
                ...$assessments,
                // Route access (C2)
                PermissionEnum::BOARDING_PORTAL_ACCESS->value,
                PermissionEnum::ASSESSMENT_RECORD->value,
            ],
            'form_teacher' => [
                PermissionEnum::MANAGE_FORM_TEACHER_COMMENTS->value,
                ...$assessments,
                // Route access (C2)
                PermissionEnum::ACADEMIC_SETUP_MANAGE->value,
                PermissionEnum::ASSESSMENT_RECORD->value,
                PermissionEnum::STUDENT_VIEW->value,
            ],
            // ADR 0045 (B2): the explicit set IS the platform-admin set — no
            // ambient domain grants. Self-healed every run (see const).
            'super_admin' => self::SUPER_ADMIN_PLATFORM,
        ];
    }

    public function run(): void
    {
        $this->sync(fresh: false);
    }

    public function sync(bool $fresh): void
    {
        // Seed-time mutations are provenance-by-code-review, not audit events:
        // without this, every fresh seed writes hundreds of 'rbac' activity
        // rows through LogRbacChange + LogsActivity. Runtime mutations (the
        // matrix UI, artisan tinkering) remain fully audited.
        activity()->withoutLogs(fn () => $this->syncLogged($fresh));
    }

    private function syncLogged(bool $fresh): void
    {
        // Roles are global; make the null-team context explicit.
        setPermissionsTeamId(null);

        $enumValues = PermissionEnum::values();

        $existingPermissions = Permission::where('guard_name', self::GUARD)
            ->pluck('name')->all();
        $existingRoles = Role::where('guard_name', self::GUARD)
            ->whereNull('school_id')->pluck('name')->all();

        foreach ($enumValues as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);
        }

        // Prune permissions the enum no longer declares (enum-exactness): by
        // definition no code checks them, so the rows are dead weight. Pivot
        // rows go with them.
        Permission::where('guard_name', self::GUARD)
            ->whereNotIn('name', $enumValues)
            ->get()
            ->each(fn (Permission $p) => $p->delete());

        foreach (self::ROLES as $name) {
            $role = Role::firstOrCreate([
                'name' => $name,
                'guard_name' => self::GUARD,
                'school_id' => null,
            ], [
                // C7 defaults apply at creation; on re-runs the flag is a
                // runtime-editable value (the matrix toggle) and is preserved
                // unless --fresh resets it below.
                'two_factor_required' => in_array($name, self::TWO_FACTOR_REQUIRED, true),
            ]);

            if ($fresh) {
                // forceFill, not update(): mass-assignment silently drops this
                // key on existing Role instances (see RbacMatrixController).
                $role->forceFill(['two_factor_required' => in_array($name, self::TWO_FACTOR_REQUIRED, true)])->save();
            }
        }

        $newPermissions = array_diff($enumValues, $existingPermissions);

        foreach (self::grantsMap() as $roleName => $permissions) {
            $role = Role::where('name', $roleName)
                ->where('guard_name', self::GUARD)
                ->whereNull('school_id')
                ->firstOrFail();

            if ($fresh) {
                $role->syncPermissions($permissions);

                continue;
            }

            // Non-destructive: only grants involving something newly created
            // this run — runtime matrix edits (grants AND revokes) survive.
            $toGrant = in_array($roleName, $existingRoles, true)
                ? array_values(array_intersect($permissions, $newPermissions))
                : $permissions;

            if ($toGrant !== []) {
                $role->givePermissionTo($toGrant);
            }
        }

        // Self-heal super_admin to canonical (ADR 0045 A3): syncPermissions in
        // a transaction (the C6 vendor lesson — the trait holds none), inside
        // withoutLogs like all seed-time mutations.
        DB::transaction(function () {
            Role::where('name', 'super_admin')
                ->where('guard_name', self::GUARD)
                ->whereNull('school_id')
                ->firstOrFail()
                ->syncPermissions(self::SUPER_ADMIN_PLATFORM);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
