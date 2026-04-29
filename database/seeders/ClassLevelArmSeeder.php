<?php
// database/seeders/ClassLevelArmSeeder.php

namespace Database\Seeders;

use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ClassLevelArmSeeder extends Seeder
{
    public function run(): void
    {
        foreach (School::all() as $school) {
            $levels = ClassLevel::withoutGlobalScopes()->where('school_id', $school->id)->get();
            $arms   = Arm::withoutGlobalScopes()->where('school_id', $school->id)->get();

            foreach ($levels as $level) {
                foreach ($arms as $arm) {
                    DB::table('class_level_arms')->updateOrInsert(
                        ['class_level_id' => $level->id, 'arm_id' => $arm->id],
                        ['id' => Str::uuid(), 'class_level_id' => $level->id, 'arm_id' => $arm->id, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
        }

        $this->command->info('Class level arms seeded.');
    }
}
