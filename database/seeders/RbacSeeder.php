<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
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
     * `guardian` and `principal` intentionally hold no permissions today:
     * their routes are still role:-gated; their permissions arrive with the
     * ADR 0044 / route-swap slices.
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
            ],
            'head_of_school' => [
                ...$guardianFull,
                ...$studentSubjectFull,
                ...$enrollmentAdmin,
                ...$assessments,
                ...$activityAdmin,
                ...$resultChecker,
                PermissionEnum::MANAGE_HEAD_OF_SCHOOL_COMMENTS->value,
            ],
            'teacher' => [
                PermissionEnum::STUDENT_SUBJECT_VIEW->value,
                ...$activityStaff,
                // ADR 0044 maker side: submit + read, never approve/reject.
                PermissionEnum::RESULT_SUBMIT->value,
                PermissionEnum::RESULT_VIEW_SCORES->value,
            ],
            'registrar' => [
                PermissionEnum::GUARDIAN_VIEW->value,
                PermissionEnum::GUARDIAN_UPDATE->value,
                PermissionEnum::GUARDIAN_DETACH->value,
                PermissionEnum::GUARDIAN_CREATE->value,
            ],
            'boarding_parent' => $assessments,
            'form_teacher' => [
                PermissionEnum::MANAGE_FORM_TEACHER_COMMENTS->value,
                ...$assessments,
            ],
            // Exactly the legacy 15 — super_admin gains NONE of the ADR 0044
            // permissions (bypass covers it; deliberate grants only). Listed
            // explicitly, not via the shared arrays, so extending those arrays
            // can never silently widen this role again.
            'super_admin' => [
                ...$studentSubjectFull,
                PermissionEnum::STUDENT_CURRICULUM_UNENROLL->value,
                PermissionEnum::CURRICULUM_SUBJECT_ARCHIVE->value,
                PermissionEnum::CURRICULUM_SUBJECT_RESTORE->value,
                PermissionEnum::ACTIVITY_LOG_VIEW->value,
                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
                PermissionEnum::ACTIVITY_LOG_VIEW_OWN->value,
                PermissionEnum::ACTIVITY_LOG_VIEW_SYSTEM->value,
                PermissionEnum::ACTIVITY_LOG_VIEW_CROSS_SCHOOL->value,
                PermissionEnum::ACTIVITY_LOG_EXPORT->value,
                PermissionEnum::ACTIVITY_LOG_VIEW_SENSITIVE->value,
            ],
            'guardian' => [],
            'principal' => [],
        ];
    }

    public function run(): void
    {
        $this->sync(fresh: false);
    }

    public function sync(bool $fresh): void
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
            Role::firstOrCreate([
                'name' => $name,
                'guard_name' => self::GUARD,
                'school_id' => null,
            ]);
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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
