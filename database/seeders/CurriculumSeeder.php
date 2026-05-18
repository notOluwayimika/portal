<?php
// database/seeders/CurriculumSeeder.php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\School;
use App\Models\Term;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->firstOrFail();

        $session = AcademicSession::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('is_current', true)
            ->firstOrFail();

        $examType = ExamType::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('name', 'First Term Exam')
            ->firstOrFail();

        // Create a curriculum for SS2 and JS1 for term 1, for each arm
        $classLevelArms = ClassLevelArm::withoutGlobalScopes()
            ->whereHas('classLevel', function ($q) use ($school) {
                $q->where('school_id', $school->id)
                  ->whereIn('name', ['SS2', 'JS1']);
            })
            ->get();

        $term = Term::where('academic_session_id', $session->id)
            ->where('order', 1)
            ->firstOrFail();

        foreach ($classLevelArms as $classLevelArm) {
            Curriculum::withoutGlobalScopes()->updateOrCreate(
                [
                    'school_id' => $school->id,
                    'term_id' => $term->id,
                    'class_level_arm_id' => $classLevelArm->id,
                    'exam_type_id' => $examType->id,
                ],
                [
                    'school_id' => $school->id,
                    'term_id' => $term->id,
                    'class_level_arm_id' => $classLevelArm->id,
                    'exam_type_id' => $examType->id,
                    'min_subjects' => 8,
                    'status' => 'active',
                ]
            );
        }

        $this->command->info('Curricula seeded.');
    }
}
