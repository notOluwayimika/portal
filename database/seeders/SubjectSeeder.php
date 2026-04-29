<?php
// database/seeders/SubjectSeeder.php

namespace Database\Seeders;

use App\Models\School;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SubjectSeeder extends Seeder
{
    private const SUBJECTS = [
        ['name' => 'English Language', 'code' => 'ENG'],
        ['name' => 'Mathematics', 'code' => 'MTH'],
        ['name' => 'Biology', 'code' => 'BIO'],
        ['name' => 'Chemistry', 'code' => 'CHM'],
        ['name' => 'Physics', 'code' => 'PHY'],
        ['name' => 'Further Mathematics', 'code' => 'FMT'],
        ['name' => 'Economics', 'code' => 'ECO'],
        ['name' => 'Government', 'code' => 'GOV'],
        ['name' => 'Literature in English', 'code' => 'LIT'],
        ['name' => 'Civic Education', 'code' => 'CIV'],
        ['name' => 'Agricultural Science', 'code' => 'AGR'],
        ['name' => 'Computer Science', 'code' => 'CSC'],
    ];

    public function run(): void
    {
        foreach (School::all() as $school) {
            foreach (self::SUBJECTS as $subject) {
                Subject::withoutGlobalScopes()->updateOrCreate(
                    ['school_id' => $school->id, 'name' => $subject['name']],
                    array_merge($subject, ['id' => Str::uuid(), 'school_id' => $school->id])
                );
            }
        }

        $this->command->info('Subjects seeded.');
    }
}
