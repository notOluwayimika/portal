<?php

namespace Database\Seeders;

use App\Enums\Permission as Perm;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class StudentSubjectPermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        Perm::STUDENT_SUBJECT_VIEW->value,
        Perm::STUDENT_SUBJECT_ADD_OPTIONAL->value,
        Perm::STUDENT_SUBJECT_DROP_OPTIONAL->value,
        Perm::STUDENT_SUBJECT_RESTORE->value,
        Perm::STUDENT_SUBJECT_VIEW_HISTORY->value,
        Perm::STUDENT_CURRICULUM_UNENROLL->value,
        Perm::CURRICULUM_SUBJECT_ARCHIVE->value,
        Perm::CURRICULUM_SUBJECT_RESTORE->value,
        Perm::CURRICULUM_SUBJECT_FORCE_DELETE->value,
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $registrar = Role::where('name', 'registrar')->first();
        $teacher = Role::where('name', 'teacher')->first();
        $admin = Role::where('name', 'admin')->first();
        $head = Role::where('name', 'head_of_school')->first();
        $super = Role::where('name', 'super_admin')->first();

        if ($registrar) {
            $registrar->givePermissionTo([
                Perm::STUDENT_SUBJECT_VIEW->value,
                Perm::STUDENT_SUBJECT_ADD_OPTIONAL->value,
                Perm::STUDENT_SUBJECT_DROP_OPTIONAL->value,
                Perm::STUDENT_SUBJECT_RESTORE->value,
                Perm::STUDENT_SUBJECT_VIEW_HISTORY->value,
            ]);
        }

        if ($teacher) {
            $teacher->givePermissionTo([Perm::STUDENT_SUBJECT_VIEW->value]);
        }

        foreach ([$admin, $head] as $role) {
            if ($role) {
                $role->givePermissionTo([
                    Perm::STUDENT_SUBJECT_VIEW->value,
                    Perm::STUDENT_SUBJECT_ADD_OPTIONAL->value,
                    Perm::STUDENT_SUBJECT_DROP_OPTIONAL->value,
                    Perm::STUDENT_SUBJECT_RESTORE->value,
                    Perm::STUDENT_SUBJECT_VIEW_HISTORY->value,
                    Perm::STUDENT_CURRICULUM_UNENROLL->value,
                    Perm::CURRICULUM_SUBJECT_ARCHIVE->value,
                    Perm::CURRICULUM_SUBJECT_RESTORE->value,
                ]);
            }
        }

        if ($super) {
            $super->givePermissionTo(self::PERMISSIONS);
        }
    }
}
