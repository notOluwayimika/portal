<?php

namespace Database\Seeders;

use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class GuardianSeeder extends Seeder
{
    private const GUARDIANS = [
        [
            'first_name'  => 'Chinedu',
            'last_name'   => 'Okafor',
            'email'       => 'chinedu.okafor@brookstone.test',
            'gender'      => 'male',
            'phone'       => '08011112222',
            'occupation'  => 'Engineer',
            'relationship'=> 'father',
            'is_primary'  => true,
            'can_login'   => true,
        ],
        [
            'first_name'  => 'Ngozi',
            'last_name'   => 'Okafor',
            'email'       => 'ngozi.okafor@brookstone.test',
            'gender'      => 'female',
            'phone'       => '08033334444',
            'occupation'  => 'Doctor',
            'relationship'=> 'mother',
            'is_primary'  => false,
            'can_login'   => false,
        ],
    ];

    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->first();
        if (!$school) {
            $this->command?->warn('No school with slug "secondary-school" found — skipping GuardianSeeder.');
            return;
        }

        $students = Student::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->take(2)
            ->get();

        if ($students->isEmpty()) {
            $this->command?->warn('No students found for the school — skipping GuardianSeeder.');
            return;
        }

        foreach (self::GUARDIANS as $data) {
            $user = User::withoutGlobalScopes()->updateOrCreate(
                ['school_id' => $school->id, 'email' => $data['email']],
                [
                    'first_name' => $data['first_name'],
                    'last_name'  => $data['last_name'],
                    'password'   => Hash::make('password'),
                    'school_id'  => $school->id,
                ]
            );

            $user->assignRole('parent');

            $guardian = Guardian::withoutGlobalScopes()->updateOrCreate(
                ['school_id' => $school->id, 'user_id' => $user->id],
                [
                    'first_name' => $data['first_name'],
                    'last_name'  => $data['last_name'],
                    'gender'     => $data['gender'],
                    'phone'      => $data['phone'],
                    'occupation' => $data['occupation'],
                    'status'     => 'active',
                ]
            );

            // Link this guardian to all seeded students (representing siblings sharing a parent).
            foreach ($students as $student) {
                $student->guardians()->syncWithoutDetaching([
                    $guardian->id => [
                        'relationship' => $data['relationship'],
                        'is_primary'   => $data['is_primary'],
                        'can_login'    => $data['can_login'],
                    ],
                ]);
            }
        }

        $this->command?->info('Guardians seeded.');
    }
}
