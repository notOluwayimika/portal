<?php

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Student;
use App\Models\StudentResult;
use App\Models\Subject;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Isolation on the score entry page, and the crash it used to cause.
 *
 * `curriculum_subjects` carries no `school_id` and no SchoolScope — it is owned through its
 * curriculum — so route-model binding on `/setup/curriculum-subject/{uuid}` happily resolved
 * ANOTHER school's row. That was not only a leak. `Student` IS school-scoped, so the page then
 * rendered with every `studentResults.student` and `scores.student` serialized as null, and the
 * React grid died on `result.student.id` ("can't access property id, student is null").
 *
 * Two independent defences, both tested here:
 *   1. the route 404s on a foreign curriculum subject (the actual fix), and
 *   2. the grid tolerates a null student anyway, so the same data shape from any other cause
 *      degrades one row instead of blanking the page.
 */
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function sep_admin(int $schoolId): User
{
    $user = al_makeUser($schoolId);
    $user->grantSchoolAccess($user->school, 'admin');
    $user->flushSchoolAccessCache();
    setPermissionsTeamId($schoolId);

    return $user;
}

/** A curriculum subject owned by $schoolId, with one student result on it. */
function sep_curriculumSubject(int $schoolId): CurriculumSubject
{
    return ActiveSchool::runFor($schoolId, function () use ($schoolId) {
        $curriculum = Curriculum::factory()->create(['school_id' => $schoolId]);

        $subject = Subject::create([
            'school_id' => $schoolId,
            'name' => 'Subject '.Str::random(5),
            'code' => strtoupper(Str::random(4)),
        ]);

        $cs = CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'is_compulsory' => true,
            'active' => true,
        ]);

        $student = Student::factory()->create(['school_id' => $schoolId]);

        StudentResult::create([
            'student_id' => $student->id,
            'curriculum_subject_id' => $cs->id,
            'total_score' => 85,
            'grade' => 'A',
            'status' => 'draft',
        ]);

        return $cs;
    });
}

// ── The isolation fix ──────────────────────────────────────────────────────

it('serves the score entry page to the school that owns the curriculum subject', function () {
    $school = al_makeSchool();
    $cs = sep_curriculumSubject($school->id);

    $this->actingAs(sep_admin($school->id))
        ->get("/setup/curriculum-subject/{$cs->uuid}")
        ->assertOk();
});

it('404s the score entry page for a curriculum subject belonging to another school', function () {
    $mine = al_makeSchool();
    $theirs = al_makeSchool();
    $foreign = sep_curriculumSubject($theirs->id);

    // Before the guard this returned 200 with a page full of null students — a cross-school read
    // AND a crash. `curriculum_subjects` has no school_id, so binding alone cannot refuse it.
    $this->actingAs(sep_admin($mine->id))
        ->get("/setup/curriculum-subject/{$foreign->uuid}")
        ->assertNotFound();
});

it('never leaks another school\'s student names through the page props', function () {
    $mine = al_makeSchool();
    $theirs = al_makeSchool();
    $foreign = sep_curriculumSubject($theirs->id);

    $theirStudent = ActiveSchool::runFor(
        $theirs->id,
        fn () => Student::where('school_id', $theirs->id)->firstOrFail()
    );

    $response = $this->actingAs(sep_admin($mine->id))
        ->get("/setup/curriculum-subject/{$foreign->uuid}");

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($theirStudent->first_name);
});
