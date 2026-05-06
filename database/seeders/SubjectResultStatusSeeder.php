<?php
// database/seeders/SubjectResultStatusSeeder.php

namespace Database\Seeders;

use App\Models\CurriculumSubject;
use App\Models\School;
use App\Models\SubjectResultStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SubjectResultStatusSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->firstOrFail();

        $admin = User::withoutGlobalScopes()
            ->where('email', 'ada.admin@brookstone.test')
            ->firstOrFail();

        $curriculumSubjects = CurriculumSubject::all();

        foreach ($curriculumSubjects as $cs) {
            SubjectResultStatus::updateOrCreate(
                ['curriculum_subject_id' => $cs->id],
                [

                    'status' => 'draft', // All start as draft
                    'updated_by' => $admin->id,
                ]
            );
        }

        $this->command->info('Subject result statuses seeded.');
    }
}
