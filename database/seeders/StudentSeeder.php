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
        ['Chukwuemeka Joseph Obi', 'GFA/2025/001'],
        ['Amina Suliat Suleiman', 'GFA/2025/002'],
        ['Tunde Tobiloba Bakare', 'GFA/2025/003'],
        ['Ifunanya Chioma Nwachukwu', 'GFA/2025/004'],
        ['Seun Adebola Afolabi', 'GFA/2025/005'],
        ['Blessing Chioma Eze', 'GFA/2025/006'],
        ['Yusuf Kareem Abdullahi', 'GFA/2025/007'],
        ['Chisom Evelyn Okafor', 'GFA/2025/008'],
        ['Adaeze Evelyn Igwe', 'GFA/2025/009'],
        ['Oluwaseun Sunday Adewale', 'GFA/2025/010'],
    ];

    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->firstOrFail();

        foreach (self::STUDENTS as [$name, $admissionNumber]) {
            Student::withoutGlobalScopes()->updateOrCreate(
                ['school_id' => $school->id, 'admission_number' => $admissionNumber],
                [

                    'school_id' => $school->id,
                    'first_name' => explode(' ', $name)[0],
                    'last_name' => explode(' ', $name)[2],
                    'middle_name' => explode(' ', $name)[1] ?? null,
                    'admission_number' => $admissionNumber,
                ]
            );
        }

        $this->command->info('Students seeded.');
    }
}
