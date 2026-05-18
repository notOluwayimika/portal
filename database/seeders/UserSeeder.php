<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

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

        app(PermissionRegistrar::class)->setPermissionsTeamId($school->id);


        // Create admin user
        $admin = User::firstOrCreate([
            'email' => 'admin@secondary.com',
        ], [
            'first_name' => 'Admin',
            'last_name' => 'User',
            'password' => Hash::make('password'),
            'school_id' => $school->id,
        ]);
        $admin->assignRole('admin');

        // Create head of school user
        $headOfSchool = User::firstOrCreate([
            'email' => 'head@example.com',
        ], [
            'first_name' => 'Head',
            'last_name' => 'of School',
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
        //         'first_name' => $studentData['parent_name'],
        //         'last_name' => 'Parent',
        //         'password' => Hash::make('password'),
        //         'school_id' => $school->id,
        //     ]);
        //     $parent->assignRole('guardian');
        //     $parent->student->create([
        //         "first_name" => $studentData['first_name'],
        //         "last_name" => $studentData['last_name'],
        //         "school_id" => $school->id,
        //         "user_id" => $parent->id,
        //         "admission_number" => $studentData['admission_number'],
        //         "photo" => $studentData['photo']
        //     ]);
        // }


        // seed teachers - Moved to TeacherSeeder

    }
}
