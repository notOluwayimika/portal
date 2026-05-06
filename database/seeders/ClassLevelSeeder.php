<?php
// database/seeders/ClassLevelSeeder.php

namespace Database\Seeders;

use App\Models\ClassLevel;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ClassLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['name' => 'JS1', 'order' => 1, 'level_type' => 'JSS'],
            ['name' => 'JS2', 'order' => 2, 'level_type' => 'JSS'],
            ['name' => 'JS3', 'order' => 3, 'level_type' => 'JSS'],
            ['name' => 'SS1', 'order' => 4, 'level_type' => 'SSS'],
            ['name' => 'SS2', 'order' => 5, 'level_type' => 'SSS'],
            ['name' => 'SS3', 'order' => 6, 'level_type' => 'SSS'],
        ];

        foreach (School::all() as $school) {
            foreach ($levels as $level) {
                ClassLevel::withoutGlobalScopes()->updateOrCreate(
                    ['school_id' => $school->id, 'name' => $level['name']],
                    array_merge($level, ['school_id' => $school->id])
                );
            }
        }

        $this->command->info('Class levels seeded.');
    }
}
