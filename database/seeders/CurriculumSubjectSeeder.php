<?php
// database/seeders/CurriculumSubjectSeeder.php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CurriculumSubjectSeeder extends Seeder
{
    // Subjects per class level: [subject_code => is_compulsory]
    private const LEVEL_SUBJECTS = [
        'SS2' => [
            'ENG' => true,
            'MTH' => true,
            'BIO' => false,
            'CHM' => false,
            'PHY' => false,
            'ECO' => false,
            'GOV' => false,
            'LIT' => false,
            'CIV' => true,
            'CSC' => false,
        ],
        'JS1' => [
            'ENG' => true,
            'MTH' => true,
            'BIO' => false,
            'AGR' => false,
            'CIV' => true,
            'CSC' => false,
        ],
    ];

    public function run(): void
    {
        $curricula = Curriculum::withoutGlobalScopes()->with('classLevelArm.classLevel')->get();

        foreach ($curricula as $curriculum) {
            $levelName = $curriculum->classLevelArm->classLevel->name;
            $subjectMap = self::LEVEL_SUBJECTS[$levelName] ?? [];
            $order = 1;

            foreach ($subjectMap as $code => $isCompulsory) {
                $subject = Subject::withoutGlobalScopes()
                    ->where('school_id', $curriculum->school_id)
                    ->where('code', $code)
                    ->first();

                if (!$subject)
                    continue;

                CurriculumSubject::updateOrCreate(
                    ['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id],
                    [

                        'is_compulsory' => $isCompulsory,
                        'display_order' => $order++,
                    ]
                );
            }
        }

        $this->command->info('Curriculum subjects seeded.');
    }
}
