<?php
// database/seeders/StudentSubjectSeeder.php

namespace Database\Seeders;

use App\Models\CurriculumSubject;
use App\Models\School;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StudentSubjectSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->firstOrFail();

        $studentCurricula = StudentCurriculum::with('curriculum.curriculumSubjects')->get();

        foreach ($studentCurricula as $sc) {
            $curriculumSubjects = $sc->curriculum->curriculumSubjects;

            foreach ($curriculumSubjects as $cs) {
                // Auto-enroll compulsory; also enroll optionals for seed completeness
                StudentSubject::updateOrCreate(
                    [
                        'student_curriculum_id' => $sc->id,
                        'curriculum_subject_id' => $cs->id,
                    ],
                    ['id' => Str::uuid()]
                );
            }
        }

        $this->command->info('Student subjects seeded.');
    }
}
