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
            // RBAC (roles + permissions + grants) is one seeder — C1 replaced
            // RoleSeeder + the four *PermissionSeeder classes with RbacSeeder.
            RbacSeeder::class,
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
