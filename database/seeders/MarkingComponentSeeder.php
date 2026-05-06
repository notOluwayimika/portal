<?php
// database/seeders/MarkingComponentSeeder.php

namespace Database\Seeders;

use App\Models\CurriculumSubject;
use App\Models\MarkingComponent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarkingComponentSeeder extends Seeder
{
    // Standard breakdown: CA = 30%, Exam = 70%
    private const COMPONENTS = [
        ['name' => 'Continuous Assessment', 'weight' => 0.300],
        ['name' => 'Examination', 'weight' => 0.700],
    ];

    public function run(): void
    {
        // Temporarily disable the weight-sum trigger while seeding
        // because rows are inserted one by one (trigger fires after each row)
        // DB::statement('ALTER TABLE marking_components DISABLE TRIGGER enforce_weight_sum');

        foreach (CurriculumSubject::all() as $curriculumSubject) {
            // Skip if components already exist
            if (MarkingComponent::where('curriculum_subject_id', $curriculumSubject->id)->exists()) {
                continue;
            }

            foreach (self::COMPONENTS as $component) {
                MarkingComponent::create([

                    'curriculum_subject_id' => $curriculumSubject->id,
                    'name' => $component['name'],
                    'weight' => $component['weight'],
                ]);
            }
        }

        // DB::statement('ALTER TABLE marking_components ENABLE TRIGGER enforce_weight_sum');

        $this->command->info('Marking components seeded.');
    }
}
