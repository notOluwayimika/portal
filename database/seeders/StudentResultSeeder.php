<?php
// database/seeders/StudentResultSeeder.php

namespace Database\Seeders;

use App\Models\GradeBoundary;
use App\Models\School;
use App\Models\Score;
use App\Models\StudentResult;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Manually computes and seeds student_results.
 * In production, the Postgres trigger handles this automatically.
 * This seeder is necessary in development because the trigger may have
 * been fired before grade_boundaries and subject_result_statuses existed.
 */
class StudentResultSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->firstOrFail();

        // Group scores by student + curriculum_subject
        $groups = Score::with('markingComponent', 'curriculumSubject.curriculum')
            ->get()
            ->groupBy(fn($s) => $s->student_id . '|' . $s->curriculum_subject_id);

        foreach ($groups as $key => $scores) {
            [$studentId, $curriculumSubjectId] = explode('|', $key);

            $totalScore = $scores->sum(fn($s) => $s->score * $s->markingComponent->weight);
            $totalScore = round($totalScore, 2);

            $examTypeId = $scores->first()->curriculumSubject->curriculum->exam_type_id;

            $grade = GradeBoundary::resolveGrade($school->id, $examTypeId, $totalScore);

            StudentResult::updateOrCreate(
                [
                    'student_id' => $studentId,
                    'curriculum_subject_id' => $curriculumSubjectId,
                ],
                [

                    'total_score' => $totalScore,
                    'grade' => $grade,
                    'status' => 'draft',
                    'computed_at' => now(),
                ]
            );
        }

        $this->command->info('Student results seeded.');
    }
}
