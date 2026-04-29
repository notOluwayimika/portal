<?php
// database/seeders/GradeBoundarySeeder.php

namespace Database\Seeders;

use App\Models\GradeBoundary;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GradeBoundarySeeder extends Seeder
{
    // Default school-wide boundaries (exam_type_id = null)
    private const DEFAULTS = [
        ['min_score' => 70, 'max_score' => 101, 'grade' => 'A', 'label' => 'Distinction'],
        ['min_score' => 60, 'max_score' => 70, 'grade' => 'B', 'label' => 'Credit'],
        ['min_score' => 50, 'max_score' => 60, 'grade' => 'C', 'label' => 'Merit'],
        ['min_score' => 45, 'max_score' => 50, 'grade' => 'D', 'label' => 'Pass'],
        ['min_score' => 40, 'max_score' => 45, 'grade' => 'E', 'label' => 'Below Average'],
        ['min_score' => 0, 'max_score' => 40, 'grade' => 'F', 'label' => 'Fail'],
    ];

    public function run(): void
    {
        foreach (School::all() as $school) {
            foreach (self::DEFAULTS as $boundary) {
                GradeBoundary::withoutGlobalScopes()->firstOrCreate(
                    [
                        'school_id' => $school->id,
                        'exam_type_id' => null,
                        'grade' => $boundary['grade'],
                    ],
                    array_merge($boundary, ['id' => Str::uuid(), 'school_id' => $school->id])
                );
            }
        }

        $this->command->info('Grade boundaries seeded.');
    }
}
