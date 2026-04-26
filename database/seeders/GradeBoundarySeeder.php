<?php
// database/seeders/GradeBoundarySeeder.php

namespace Database\Seeders;

use App\Models\GradeBoundary;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GradeBoundarySeeder extends Seeder
{
    // Default boundaries applied to every school on creation (exam_type_id = null)
    private const DEFAULTS = [
        ['min' => 70, 'max' => 101, 'grade' => 'A', 'label' => 'Distinction'],
        ['min' => 60, 'max' => 70, 'grade' => 'B', 'label' => 'Credit'],
        ['min' => 50, 'max' => 60, 'grade' => 'C', 'label' => 'Merit'],
        ['min' => 45, 'max' => 50, 'grade' => 'D', 'label' => 'Pass'],
        ['min' => 40, 'max' => 45, 'grade' => 'E', 'label' => 'Below Average'],
        ['min' => 0, 'max' => 40, 'grade' => 'F', 'label' => 'Fail'],
    ];

    public function run(): void
    {
        foreach (School::all() as $school) {
            foreach (self::DEFAULTS as $boundary) {
                GradeBoundary::firstOrCreate(
                    [
                        'school_id' => $school->id,
                        'exam_type_id' => null,
                        'grade' => $boundary['grade'],
                    ],
                    [
                        'id' => Str::uuid(),
                        'min_score' => $boundary['min'],
                        'max_score' => $boundary['max'],
                        'label' => $boundary['label'],
                    ]
                );
            }
        }
    }
}
