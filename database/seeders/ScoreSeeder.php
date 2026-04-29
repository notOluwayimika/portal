<?php
// database/seeders/ScoreSeeder.php

namespace Database\Seeders;

use App\Models\CurriculumSubject;
use App\Models\MarkingComponent;
use App\Models\School;
use App\Models\Score;
use App\Models\StudentSubject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScoreSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->firstOrFail();

        $teacher = User::withoutGlobalScopes()
            ->where('email', 'emeka.teacher@brookstone.test')
            ->firstOrFail();

        // Disable the approved-check trigger while seeding (no statuses exist yet)
        // DB::statement('ALTER TABLE scores DISABLE TRIGGER block_approved_score_edit');

        $studentSubjects = StudentSubject::with([
            'studentCurriculum.student',
            'curriculumSubject.markingComponents',
        ])->get();

        foreach ($studentSubjects as $ss) {
            $student = $ss->studentCurriculum->student;
            $components = $ss->curriculumSubject->markingComponents;

            foreach ($components as $component) {
                // Generate a realistic score
                $score = $this->generateScore($component->name);

                Score::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'marking_component_id' => $component->id,
                    ],
                    [
                        'id' => Str::uuid(),
                        'curriculum_subject_id' => $ss->curriculum_subject_id,
                        'score' => $score,
                        'created_by' => $teacher->id,
                    ]
                );
            }
        }

        // DB::statement('ALTER TABLE scores ENABLE TRIGGER block_approved_score_edit');

        $this->command->info('Scores seeded.');
    }

    private function generateScore(string $componentName): float
    {
        // CA tends to be slightly higher than exam scores
        return match (true) {
            str_contains($componentName, 'Assessment') => round(fake()->randomFloat(2, 55, 28), 2), // /30
            str_contains($componentName, 'Examination') => round(fake()->randomFloat(2, 35, 68), 2), // /70
            default => round(fake()->randomFloat(2, 40, 95), 2),
        };
    }
}
