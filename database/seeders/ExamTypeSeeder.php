<?php
// database/seeders/ExamTypeSeeder.php

namespace Database\Seeders;

use App\Models\ExamType;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ExamTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = ['First Term Exam', 'Second Term Exam', 'Third Term Exam', 'WAEC Mock', 'NECO Mock'];

        foreach (School::all() as $school) {
            foreach ($types as $name) {
                ExamType::withoutGlobalScopes()->updateOrCreate(
                    ['school_id' => $school->id, 'name' => $name, 'slug' => Str::slug($name)],
                    ['id' => Str::uuid(), 'school_id' => $school->id, 'name' => $name, 'slug' => Str::slug($name)]
                );
            }
        }

        $this->command->info('Exam types seeded.');
    }
}
