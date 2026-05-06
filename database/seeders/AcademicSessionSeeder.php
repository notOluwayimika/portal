<?php
// database/seeders/AcademicSessionSeeder.php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AcademicSessionSeeder extends Seeder
{
    public function run(): void
    {
        $schools = School::all();

        $sessionData = [
            ['name' => '2023/2024', 'is_current' => false, 'slug' => '2023-2024'],
            ['name' => '2024/2025', 'is_current' => false, 'slug' => '2024-2025'],
            ['name' => '2025/2026', 'is_current' => true, 'slug' => '2025-2026'],
        ];

        foreach ($schools as $school) {
            foreach ($sessionData as $data) {
                AcademicSession::withoutGlobalScopes()->updateOrCreate(
                    ['school_id' => $school->id, 'name' => $data['name']],
                    array_merge($data, ['school_id' => $school->id])
                );
            }
        }

        $this->command->info('Academic sessions seeded.');
    }
}
