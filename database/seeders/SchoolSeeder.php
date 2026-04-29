<?php
// database/seeders/SchoolSeeder.php

namespace Database\Seeders;

use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        $schools = [
            ['name' => 'Secondary School', 'slug' => Str::slug('Secondary School')],
        ];

        foreach ($schools as $data) {
            School::updateOrCreate(['slug' => $data['slug']], $data);
        }

        $this->command->info('Schools seeded.');
    }
}
