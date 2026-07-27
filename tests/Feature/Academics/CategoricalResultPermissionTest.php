<?php

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\GradingScheme;
use App\Models\GradingSchemeItem;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Recording a categorical rating is SCORE ENTRY, so it sits behind `score.manage` — the same
 * ability as `assignScore`, its numeric twin.
 *
 * It used to sit in the `academic_setup.manage` group, alongside approve/reject and the curriculum
 * matrix. In the seeded grant map those two abilities do not overlap the way the old placement
 * assumed:
 *
 *     teacher       score.manage = YES   academic_setup.manage = no
 *     form_teacher  score.manage = no    academic_setup.manage = YES
 *
 * So a teacher who could enter numeric scores for a class got a 403 recording a categorical rating
 * for it. The route move is what these tests pin; without it the first one below 403s.
 *
 * The requests use REAL rows because `SubstituteBindings` runs OUTSIDE the permission middleware:
 * a made-up uuid 404s before authorization is ever consulted, so a bogus-uuid test would report
 * 404 for every role and prove nothing.
 */
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/**
 * @return array{0: User, 1: CurriculumSubject, 2: Student, 3: GradingSchemeItem}
 */
function crp_fixture(string $role): array
{
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, $role);
    $user->flushSchoolAccessCache();
    setPermissionsTeamId($school->id);

    [$cs, $student, $rating] = ActiveSchool::runFor($school->id, function () use ($school) {
        $scheme = GradingScheme::create([
            'school_id' => $school->id,
            'family_uuid' => (string) Str::uuid(),
            'name' => 'Progress '.Str::random(4),
            'mode' => 'categorical',
            'version' => 1,
            'status' => 'active',
        ]);

        $rating = GradingSchemeItem::create([
            'grading_scheme_id' => $scheme->id,
            'code' => 'GP',
            'label' => 'Good Progress',
            'display_order' => 1,
        ]);

        $curriculum = Curriculum::factory()->create([
            'school_id' => $school->id,
            'grading_scheme_id' => $scheme->id,
        ]);

        $subject = Subject::create([
            'school_id' => $school->id,
            'name' => 'Subject '.Str::random(5),
            'code' => strtoupper(Str::random(4)),
        ]);

        $cs = CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'is_compulsory' => true,
            'active' => true,
        ]);

        return [$cs, Student::factory()->create(['school_id' => $school->id]), $rating];
    });

    return [$user, $cs, $student, $rating];
}

function crp_hit(string $role)
{
    [$user, $cs, $student, $rating] = crp_fixture($role);

    return test()->actingAs($user)->putJson(
        "/api/curriculum-subjects/{$cs->uuid}/categorical-results/{$student->uuid}",
        ['grading_scheme_item_id' => $rating->uuid],
    );
}

it('lets a teacher — who can enter numeric scores — past authorization on the categorical route', function () {
    // The bug: a flat 403 here while `POST .../scores` was fine for the same user. Past the guard
    // the controller's own enrolment rule replies 422, which is the point — it got to run at all.
    crp_hit('teacher')->assertStatus(422);
});

it('lets an admin past authorization', function () {
    crp_hit('admin')->assertStatus(422);
});

it('refuses a role holding neither score.manage nor academic_setup.manage', function () {
    crp_hit('guardian')->assertForbidden();
});

it('refuses an unauthenticated caller', function () {
    [, $cs, $student, $rating] = crp_fixture('teacher');

    $this->putJson(
        "/api/curriculum-subjects/{$cs->uuid}/categorical-results/{$student->uuid}",
        ['grading_scheme_item_id' => $rating->uuid],
    )->assertUnauthorized();
});
