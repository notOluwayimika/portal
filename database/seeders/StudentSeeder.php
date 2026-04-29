<?php
// database/seeders/StudentSeeder.php

namespace Database\Seeders;

use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StudentSeeder extends Seeder
{
    private const STUDENTS = [
        // [name, admission_number]
        ['Chukwuemeka Obi', 'GFA/2025/001'],
        ['Amina Suleiman', 'GFA/2025/002'],
        ['Tunde Bakare', 'GFA/2025/003'],
        ['Ifunanya Nwachukwu', 'GFA/2025/004'],
        ['Seun Afolabi', 'GFA/2025/005'],
        ['Blessing Eze', 'GFA/2025/006'],
        ['Yusuf Abdullahi', 'GFA/2025/007'],
        ['Chisom Okafor', 'GFA/2025/008'],
        ['Adaeze Igwe', 'GFA/2025/009'],
        ['Oluwaseun Adewale', 'GFA/2025/010'],
    ];

    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->firstOrFail();

        foreach (self::STUDENTS as [$name, $admissionNumber]) {
            Student::withoutGlobalScopes()->updateOrCreate(
                ['school_id' => $school->id, 'admission_number' => $admissionNumber],
                [
                    'id' => Str::uuid(),
                    'school_id' => $school->id,
                    'name' => $name,
                    'admission_number' => $admissionNumber,
                ]
            );
        }

        $this->command->info('Students seeded.');
    }
}
