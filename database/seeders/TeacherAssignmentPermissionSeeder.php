<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeacherAssignmentPermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        'manage_teacher_assignments',
        'view_behavioral_assessments',
        'create_behavioral_assessments',
        'edit_behavioral_assessments',
        'view_psychomotor_skills',
        'create_psychomotor_skills',
        'edit_psychomotor_skills',
        'manage_form_teacher_comments',
        'manage_head_of_school_comments',
    ];

    private const BEHAVIORAL_ASSESSMENT_PERMISSIONS = [
        'view_behavioral_assessments',
        'create_behavioral_assessments',
        'edit_behavioral_assessments',
    ];

    private const PSYCHOMOTOR_SKILL_PERMISSIONS = [
        'view_psychomotor_skills',
        'create_psychomotor_skills',
        'edit_psychomotor_skills',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $admin = Role::where('name', 'admin')->first();
        $headOfSchool = Role::where('name', 'head_of_school')->first();
        $boardingParent = Role::where('name', 'boarding_parent')->first();
        $formTeacher = Role::where('name', 'form_teacher')->first();

        if ($admin) {
            $this->syncPermissions($admin, [
                'manage_teacher_assignments',
                ...self::BEHAVIORAL_ASSESSMENT_PERMISSIONS,
                ...self::PSYCHOMOTOR_SKILL_PERMISSIONS,
                'manage_head_of_school_comments',
            ]);
        }

        if ($headOfSchool) {
            $this->syncPermissions($headOfSchool, [
                ...self::BEHAVIORAL_ASSESSMENT_PERMISSIONS,
                ...self::PSYCHOMOTOR_SKILL_PERMISSIONS,
                'manage_head_of_school_comments',
            ]);
        }

        if ($boardingParent) {
            $this->syncPermissions($boardingParent, [
                ...self::BEHAVIORAL_ASSESSMENT_PERMISSIONS,
                ...self::PSYCHOMOTOR_SKILL_PERMISSIONS,
            ]);
        }

        if ($formTeacher) {
            // Form teachers can record assessments when the school has no
            // boarding parents; the fallback rule itself is enforced
            // server-side (ResolvesAssessmentAccess::canRecordAssessmentFor).
            $this->syncPermissions($formTeacher, [
                'manage_form_teacher_comments',
                ...self::BEHAVIORAL_ASSESSMENT_PERMISSIONS,
                ...self::PSYCHOMOTOR_SKILL_PERMISSIONS,
            ]);
        }
    }

    private function syncPermissions(Role $role, array $permissionNames): void
    {
        $permissionIds = Permission::whereIn('name', $permissionNames)->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('role_has_permissions')->updateOrInsert(
                ['permission_id' => $permissionId, 'role_id' => $role->id],
                ['permission_id' => $permissionId, 'role_id' => $role->id]
            );
        }
    }
}
