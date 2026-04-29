<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create or get a school
        $school = School::firstOrCreate([
            'slug' => 'secondary-school',
        ], [
            'name' => 'Secondary School',
        ]);

        // Create admin user
        $admin = User::firstOrCreate([
            'email' => 'admin@secondary.com',
        ], [
            'name' => 'Admin User',
            'password' => Hash::make('password'),
            'school_id' => $school->id,
        ]);
        $admin->assignRole('admin');

        // Create head of school user
        $headOfSchool = User::firstOrCreate([
            'email' => 'head@example.com',
        ], [
            'name' => 'Head of School',
            'password' => Hash::make('password'),
            'school_id' => $school->id,
        ]);
        $headOfSchool->assignRole('head_of_school');



        // Create parent users
        // $students = [["name"=> "Student One", "admission_number" => "ADM-001", "photo" => null, "parent_name" => "Parent One", "parent_email" => "parent1@example.com"]];
        // foreach ($students as $studentData) {
        //     // Create student user
        //     $parent = User::firstOrCreate([
        //         'email' => $studentData['parent_email'],
        //     ], [
        //         'name' => $studentData['parent_name'],
        //         'password' => Hash::make('password'),
        //         'school_id' => $school->id,
        //     ]);
        //     $parent->assignRole('parent');
        //     $parent->student->create([
        //         "name" => $studentData['name'],
        //         "school_id" => $school->id,
        //         "user_id" => $parent->id,
        //         "admission_number" => $studentData['admission_number'],
        //         "photo" => $studentData['photo']
        //     ]);
        // }


        // seed teachers
        $teachers = [
            // Brookstone Schools
            [
                'uuid' => Str::uuid(),
                'school_id' => $school->id,
                'name' => 'Ada Okonkwo',
                'email' => 'ada.admin@brookstone.test',
                'password' => Hash::make('password'),
            ],
            [
                'uuid' => Str::uuid(),
                'school_id' => $school->id,
                'name' => 'Emeka Nwosu',
                'email' => 'emeka.teacher@brookstone.test',
                'password' => Hash::make('password'),
            ],

        ];

        foreach ($teachers as $data) {
            $role = 'teacher';
            unset($data['role']);

            // Bypass global SchoolScope since we're seeding
            $user = User::withoutGlobalScopes()->updateOrCreate(
                ['school_id' => $data['school_id'], 'email' => $data['email']],
                $data
            );
            $user->assignRole('teacher');
            $user->teacher()->create([
                "uuid" => Str::uuid(),
                "school_id" => $data['school_id'],
                "user_id" => $user->id,
                "name" => $data['name'],
                "staff_number" => "STF-" . uniqid()
            ]);


            $user->assignRole($role);
        }

    }
}
