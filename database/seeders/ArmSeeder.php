<?php
// database/seeders/ArmSeeder.php

namespace Database\Seeders;

use App\Models\Arm;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ArmSeeder extends Seeder
{
    public function run(): void
    {
        $arms = ['A', 'B', 'C'];

        foreach (School::all() as $school) {
            foreach ($arms as $label) {
                Arm::withoutGlobalScopes()->updateOrCreate(
                    ['school_id' => $school->id, 'label' => $label],
                    ['school_id' => $school->id, 'label' => $label]
                );
            }
        }

        $this->command->info('Arms seeded.');
    }
}
