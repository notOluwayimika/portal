<?php

// database/seeders/ArmsDatabaseSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ArmsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Order matters — respect foreign key dependencies
        $this->call([
            // SchoolSeeder::class,
            RoleSeeder::class,
            StudentSubjectPermissionSeeder::class,
            GuardianPermissionSeeder::class,
            // ActivityLogPermissionSeeder is seeded directly by DatabaseSeeder.
            TeacherAssignmentPermissionSeeder::class,
            // UserSeeder::class,
            // GradeBoundarySeeder::class,

            // TeacherSeeder::class,
            // AcademicSessionSeeder::class,
            // GuardianSeeder::class,
            // ClassLevelSeeder::class,
            // TermSeeder::class,
            // StreamSeeder::class,
            // ArmSeeder::class,
            // ClassLevelArmSeeder::class,
            // ExamTypeSeeder::class,
            // SubjectSeeder::class,
            // CurriculumSeeder::class,
            // CurriculumSubjectSeeder::class,
            // MarkingComponentSeeder::class,
            // StudentSeeder::class,
            // TeacherCurriculumSubjectSeeder::class,
            // StudentCurriculumSeeder::class,
            // StudentSubjectSeeder::class,
            // ScoreSeeder::class,
            // SubjectResultStatusSeeder::class,
            // StudentResultSeeder::class,
            // AuditLogSeeder::class,
        ]);
    }
}
