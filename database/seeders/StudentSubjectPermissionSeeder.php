<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class StudentSubjectPermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        'student_subject.view',
        'student_subject.add_optional',
        'student_subject.drop_optional',
        'student_subject.restore',
        'student_subject.view_history',
        'student_curriculum.unenroll',
        'curriculum_subject.archive',
        'curriculum_subject.restore',
        'curriculum_subject.force_delete',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $registrar = Role::where('name', 'registrar')->first();
        $teacher   = Role::where('name', 'teacher')->first();
        $admin     = Role::where('name', 'admin')->first();
        $head      = Role::where('name', 'head_of_school')->first();
        $super     = Role::where('name', 'super_admin')->first();

        if ($registrar) {
            $registrar->givePermissionTo([
                'student_subject.view',
                'student_subject.add_optional',
                'student_subject.drop_optional',
                'student_subject.restore',
                'student_subject.view_history',
            ]);
        }

        if ($teacher) {
            $teacher->givePermissionTo(['student_subject.view']);
        }

        foreach ([$admin, $head] as $role) {
            if ($role) {
                $role->givePermissionTo([
                    'student_subject.view',
                    'student_subject.add_optional',
                    'student_subject.drop_optional',
                    'student_subject.restore',
                    'student_subject.view_history',
                    'student_curriculum.unenroll',
                    'curriculum_subject.archive',
                    'curriculum_subject.restore',
                ]);
            }
        }

        if ($super) {
            $super->givePermissionTo(self::PERMISSIONS);
        }
    }
}
