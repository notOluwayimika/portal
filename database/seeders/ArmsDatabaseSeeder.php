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
            SchoolSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            AcademicSessionSeeder::class,
            ClassLevelSeeder::class,
            ArmSeeder::class,
            ClassLevelArmSeeder::class,
            ExamTypeSeeder::class,
            GradeBoundarySeeder::class,
            SubjectSeeder::class,
            CurriculumSeeder::class,
            CurriculumSubjectSeeder::class,
            MarkingComponentSeeder::class,
            StudentSeeder::class,
            TeacherCurriculumSubjectSeeder::class,
            StudentCurriculumSeeder::class,
            StudentSubjectSeeder::class,
            ScoreSeeder::class,
            SubjectResultStatusSeeder::class,
            StudentResultSeeder::class,
            AuditLogSeeder::class,
        ]);
    }
}
