<?php
// database/seeders/StudentCurriculumSeeder.php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StudentCurriculumSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->firstOrFail();

        $students = Student::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->get();

        // All students go into SS2 curriculum for this seed
        $curriculum = Curriculum::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->whereHas('classLevelArm.classLevel', fn($q) => $q->where('name', 'SS2'))
            ->firstOrFail();

        foreach ($students as $student) {
            StudentCurriculum::updateOrCreate(
                ['student_id' => $student->id, 'curriculum_id' => $curriculum->id],
                []
            );
        }

        $this->command->info('Student curricula seeded.');
    }
}
