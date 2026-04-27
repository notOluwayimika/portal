<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create or get a school
        $school = School::firstOrCreate([
            'slug' => 'brookstone-school',
        ], [
            'name' => 'Brookstone School',
        ]);

        // Create admin user
        $admin = User::firstOrCreate([
            'email' => 'admin@example.com',
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

        // Create teacher users
        $teachers = [
            ['name' => 'Teacher One', 'email' => 'teacher1@example.com'],
            ['name' => 'Teacher Two', 'email' => 'teacher2@example.com'],
            ['name' => 'Teacher Three', 'email' => 'teacher3@example.com'],
        ];

        foreach ($teachers as $teacherData) {
            $teacher = User::firstOrCreate([
                'email' => $teacherData['email'],
            ], [
                'name' => $teacherData['name'],
                'password' => Hash::make('password'),
                'school_id' => $school->id,
            ]);
            $teacher->assignRole('teacher');
        }

        // Create parent users
        $parents = [
            ['name' => 'Parent One', 'email' => 'parent1@example.com'],
            ['name' => 'Parent Two', 'email' => 'parent2@example.com'],
            ['name' => 'Parent Three', 'email' => 'parent3@example.com'],
        ];

        foreach ($parents as $parentData) {
            $parent = User::firstOrCreate([
                'email' => $parentData['email'],
            ], [
                'name' => $parentData['name'],
                'password' => Hash::make('password'),
                'school_id' => $school->id,
            ]);
            $parent->assignRole('parent');
        }
    }
}