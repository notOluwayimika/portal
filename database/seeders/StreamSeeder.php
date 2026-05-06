<?php
// database/seeders/StreamSeeder.php

namespace Database\Seeders;

use App\Models\ClassLevel;
use App\Models\Stream;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StreamSeeder extends Seeder
{
    public function run(): void
    {
        $streams = [
            ['name' => 'Science', 'code' => 'SCI', 'sort_order' => 1],
            ['name' => 'Arts', 'code' => 'ART', 'sort_order' => 2],
            ['name' => 'Commercial', 'code' => 'COM', 'sort_order' => 3],
        ];

        $sssLevels = ClassLevel::where('level_type', 'SSS')->get();

        foreach ($sssLevels as $level) {
            foreach ($streams as $streamData) {
                Stream::updateOrCreate(
                    ['name' => $streamData['name']],
                    array_merge($streamData, ['uuid' => Str::uuid()])
                );
            }
        }

        $this->command->info('Streams seeded for SSS levels.');
    }
}