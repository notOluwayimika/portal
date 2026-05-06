<?php
// database/seeders/UpdateClassLevelTypeSeeder.php

namespace Database\Seeders;

use App\Models\ClassLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UpdateClassLevelTypeSeeder extends Seeder
{
    public function run(): void
    {
        // Update existing class_levels to set level_type based on name
        DB::table('class_levels')
            ->where('name', 'like', 'JS%')
            ->update(['level_type' => 'JSS']);

        DB::table('class_levels')
            ->where('name', 'like', 'SS%')
            ->update(['level_type' => 'SSS']);

        $this->command->info('Class level types updated.');
    }
}