<?php
// database/seeders/ClassLevelArmSeeder.php

namespace Database\Seeders;

use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\Stream;
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
            $arms = Arm::withoutGlobalScopes()->where('school_id', $school->id)->get();
            $streams = Stream::withoutGlobalScopes()->get();

            foreach ($levels as $level) {
                foreach ($arms as $arm) {
                    if ($level->level_type === 'SSS') {
                        // For SSS levels, we want to create a class_level_arm for each stream
                        foreach ($streams as $stream) {
                            DB::table('class_level_arms')->updateOrInsert(
                                ['class_level_id' => $level->id, 'arm_id' => $arm->id, 'stream_id' => $stream->id, 'school_id' => $school->id],
                                [
                                    'uuid' => Str::uuid(),
                                    'class_level_id' => $level->id,
                                    'arm_id' => $arm->id,
                                    'stream_id' => $stream->id,
                                    'school_id' => $school->id,
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ]
                            );
                        }
                    } else {
                        // For JSS levels, we create a class_level_arm without stream
                        DB::table('class_level_arms')->updateOrInsert(
                            ['class_level_id' => $level->id, 'arm_id' => $arm->id, 'school_id' => $school->id],
                            [
                                'uuid' => Str::uuid(),
                                'class_level_id' => $level->id,
                                'arm_id' => $arm->id,
                                'school_id' => $school->id,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]
                        );
                    }
                }
            }
        }

        $this->command->info('Class level arms seeded.');
    }
}
