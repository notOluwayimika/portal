<?php

namespace Database\Seeders;

use App\Enums\Permission as Perm;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeacherAssignmentPermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        Perm::MANAGE_TEACHER_ASSIGNMENTS->value,
        Perm::VIEW_BEHAVIORAL_ASSESSMENTS->value,
        Perm::CREATE_BEHAVIORAL_ASSESSMENTS->value,
        Perm::EDIT_BEHAVIORAL_ASSESSMENTS->value,
        Perm::VIEW_PSYCHOMOTOR_SKILLS->value,
        Perm::CREATE_PSYCHOMOTOR_SKILLS->value,
        Perm::EDIT_PSYCHOMOTOR_SKILLS->value,
        Perm::MANAGE_FORM_TEACHER_COMMENTS->value,
        Perm::MANAGE_HEAD_OF_SCHOOL_COMMENTS->value,
    ];

    private const BEHAVIORAL_ASSESSMENT_PERMISSIONS = [
        Perm::VIEW_BEHAVIORAL_ASSESSMENTS->value,
        Perm::CREATE_BEHAVIORAL_ASSESSMENTS->value,
        Perm::EDIT_BEHAVIORAL_ASSESSMENTS->value,
    ];

    private const PSYCHOMOTOR_SKILL_PERMISSIONS = [
        Perm::VIEW_PSYCHOMOTOR_SKILLS->value,
        Perm::CREATE_PSYCHOMOTOR_SKILLS->value,
        Perm::EDIT_PSYCHOMOTOR_SKILLS->value,
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
                Perm::MANAGE_TEACHER_ASSIGNMENTS->value,
                ...self::BEHAVIORAL_ASSESSMENT_PERMISSIONS,
                ...self::PSYCHOMOTOR_SKILL_PERMISSIONS,
                Perm::MANAGE_HEAD_OF_SCHOOL_COMMENTS->value,
            ]);
        }

        if ($headOfSchool) {
            $this->syncPermissions($headOfSchool, [
                ...self::BEHAVIORAL_ASSESSMENT_PERMISSIONS,
                ...self::PSYCHOMOTOR_SKILL_PERMISSIONS,
                Perm::MANAGE_HEAD_OF_SCHOOL_COMMENTS->value,
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
                Perm::MANAGE_FORM_TEACHER_COMMENTS->value,
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
