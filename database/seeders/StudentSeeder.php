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
        // [name, admission_number, gender, dob]
        ['Chukwuemeka Joseph Obi', 'GFA/2025/001', 'male', '2010-05-15'],
        ['Amina Suliat Suleiman', 'GFA/2025/002', 'female', '2011-03-22'],
        ['Tunde Tobiloba Bakare', 'GFA/2025/003', 'male', '2010-11-10'],
        ['Ifunanya Chioma Nwachukwu', 'GFA/2025/004', 'female', '2011-07-04'],
        ['Seun Adebola Afolabi', 'GFA/2025/005', 'male', '2010-01-30'],
        ['Blessing Chioma Eze', 'GFA/2025/006', 'female', '2012-02-14'],
        ['Yusuf Kareem Abdullahi', 'GFA/2025/007', 'male', '2011-09-20'],
        ['Chisom Evelyn Okafor', 'GFA/2025/008', 'female', '2010-12-25'],
        ['Adaeze Evelyn Igwe', 'GFA/2025/009', 'female', '2011-06-18'],
        ['Oluwaseun Sunday Adewale', 'GFA/2025/010', 'male', '2012-08-05'],
    ];

    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->firstOrFail();

        foreach (self::STUDENTS as [$name, $admissionNumber, $gender, $dob]) {
            Student::withoutGlobalScopes()->updateOrCreate(
                ['school_id' => $school->id, 'admission_number' => $admissionNumber],
                [
                    'school_id' => $school->id,
                    'first_name' => explode(' ', $name)[0],
                    'last_name' => explode(' ', $name)[2] ?? explode(' ', $name)[1],
                    'middle_name' => count(explode(' ', $name)) > 2 ? explode(' ', $name)[1] : null,
                    'admission_number' => $admissionNumber,
                    'gender' => $gender,
                    'date_of_birth' => $dob,
                ]
            );
        }

        $this->command->info('Students seeded.');
    }
}
