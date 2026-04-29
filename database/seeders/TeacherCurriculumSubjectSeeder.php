<?php
// database/seeders/TeacherCurriculumSubjectSeeder.php

namespace Database\Seeders;

use App\Models\CurriculumSubject;
use App\Models\School;
use App\Models\Subject;
use App\Models\TeacherCurriculumSubject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TeacherCurriculumSubjectSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::where('slug', 'secondary-school')->firstOrFail();

        $emeka = User::withoutGlobalScopes()
            ->where('email', 'emeka.teacher@brookstone.test')->firstOrFail();

        $ngozi = User::withoutGlobalScopes()
            ->where('email', 'ada.admin@brookstone.test')->firstOrFail();

        // Emeka teaches ENG and MTH; Ngozi teaches BIO, CHM, PHY
        $emekaSubjectCodes = ['ENG', 'MTH'];
        $ngoziSubjectCodes = ['BIO', 'CHM', 'PHY'];

        $this->assignTeacher($school->id, $emeka->teacher->id, $emekaSubjectCodes);
        $this->assignTeacher($school->id, $ngozi->teacher->id, $ngoziSubjectCodes);

        $this->command->info('Teacher curriculum subject assignments seeded.');
    }

    private function assignTeacher(string $schoolId, string $teacherId, array $codes): void
    {
        $subjects = Subject::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('code', $codes)
            ->pluck('id');

        $curriculumSubjects = CurriculumSubject::whereIn('subject_id', $subjects)->get();

        foreach ($curriculumSubjects as $cs) {
            TeacherCurriculumSubject::updateOrCreate(
                ['teacher_id' => $teacherId, 'curriculum_subject_id' => $cs->id],
                ['id' => Str::uuid()]
            );
        }
    }
}
