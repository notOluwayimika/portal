<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TeacherSeeder extends Seeder
{
    private const TEACHERS = [
        [
            'first_name' => 'Ada',
            'last_name' => 'Okonkwo',
            'email' => 'ada.admin@brookstone.test',
            'gender' => 'female',
            'staff_number' => 'STF/2025/001',
            'phone' => '08012345678',
            'status' => 'active',
            'qualification' => 'M.Ed',
        ],
        [
            'first_name' => 'Emeka',
            'last_name' => 'Nwosu',
            'email' => 'emeka.teacher@brookstone.test',
            'gender' => 'male',
            'staff_number' => 'STF/2025/002',
            'phone' => '08087654321',
            'status' => 'active',
            'qualification' => 'B.Sc',
        ],
    ];

    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->firstOrFail();

        foreach (self::TEACHERS as $data) {
            $user = User::withoutGlobalScopes()->updateOrCreate(
                ['school_id' => $school->id, 'email' => $data['email']],
                [
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'password' => Hash::make('password'),
                    'school_id' => $school->id,
                ]
            );

            // Establish the School/team context before assigning a school-scoped
            // role (the S7 assignRole invariant; mirrors SetSchoolContext).
            setPermissionsTeamId($school->id);
            $user->assignRole('teacher');
            setPermissionsTeamId(null);

            Teacher::withoutGlobalScopes()->updateOrCreate(
                ['school_id' => $school->id, 'user_id' => $user->id],
                [
                    'staff_number' => $data['staff_number'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'gender' => $data['gender'],
                    'phone' => $data['phone'],
                    'status' => $data['status'],
                    'qualification' => $data['qualification'],
                    'hire_date' => now()->subYears(2)->format('Y-m-d'),
                ]
            );
        }

        $this->command->info('Teachers seeded.');
    }
}
