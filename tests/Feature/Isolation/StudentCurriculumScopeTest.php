<?php

use App\Http\Requests\BehavioralAssessmentRequest;
use App\Http\Requests\PromoteStudentRequest;
use App\Http\Requests\PsychomotorSkillRequest;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Slice (ii) — the read side. Slice (i) made a cross-School episode
 * unrepresentable AT CREATION and, by its own boundary sentence, closed none of
 * the read-side holes: `StudentCurriculum` carried no scope at all, so every
 * `{studentCurriculum:uuid}` route binding and every
 * `where('uuid')->firstOrFail()` resolved ANY School's episode.
 *
 * Every test below proves BOTH directions. A scope that rejects a foreign episode
 * but also breaks a legitimate same-School read is not a fix — it trades a leak
 * for an outage. The "still works" half is the discriminating half.
 */
uses(RefreshDatabase::class);

/** @return array{0: School, 1: StudentCurriculum} a School and one episode inside it */
function scopedEpisode(): array
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

    // Created under its own School's context so the episode is well-formed.
    $episode = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]));

    return [$school, $episode];
}

it('ANTI-INERT — the scope is registered AND actually filters (not silently absent)', function () {
    [$schoolA, $episodeA] = scopedEpisode();

    // 1. Registered at all. A model that "adopted" a scope which never attached is
    //    the wallpaper failure this project keeps finding (cf. the 1.3b.1 halting
    //    defect, where BelongsToSchool's hook was inert on 9 models).
    expect(array_keys((new StudentCurriculum)->getGlobalScopes()))
        ->toContain(SchoolScope::class);

    // 2. And it changes the SQL — the filter is present, not just the object.
    $sql = ActiveSchool::runFor($schoolA->id, fn () => StudentCurriculum::query()->toSql());
    expect($sql)->toContain('school_id');

    // 3. And it changes results.
    expect(ActiveSchool::runFor($schoolA->id, fn () => StudentCurriculum::find($episodeA->id)))->not->toBeNull();
});

it('BINDING RESOLUTION — a foreign episode does not resolve, an own-School one does', function () {
    [$schoolA, $episodeA] = scopedEpisode();
    [$schoolB, $episodeB] = scopedEpisode();

    // Route-model binding for every {studentCurriculum:uuid} route resolves through
    // exactly this query (getRouteKeyName() = 'uuid'), so this covers all of them.
    $resolve = fn (School $ctx, string $uuid) => ActiveSchool::runFor(
        $ctx->id,
        fn () => StudentCurriculum::where('uuid', $uuid)->first(),
    );

    // Leak closed …
    expect($resolve($schoolA, $episodeB->uuid))->toBeNull()
        ->and($resolve($schoolB, $episodeA->uuid))->toBeNull()
        // … and legitimate reads preserved (the discriminating half).
        ->and($resolve($schoolA, $episodeA->uuid))->not->toBeNull()
        ->and($resolve($schoolB, $episodeB->uuid))->not->toBeNull();
});

it('ASSESSMENT LOOKUPS — where(uuid)->firstOrFail() now fails closed on a foreign episode', function () {
    [$schoolA] = scopedEpisode();
    [, $episodeB] = scopedEpisode();

    // BehavioralAssessmentController:50 / PsychomotorSkillController:22 resolved any
    // School's row and relied on a post-hoc guard. Now the row is simply not found.
    expect(fn () => ActiveSchool::runFor(
        $schoolA->id,
        fn () => StudentCurriculum::where('uuid', $episodeB->uuid)->firstOrFail(),
    ))->toThrow(ModelNotFoundException::class);
});

it('EXISTS RULES — a foreign uuid is a validation error; an own-School uuid passes', function () {
    [$schoolA, $episodeA] = scopedEpisode();
    [, $episodeB] = scopedEpisode();

    // Laravel's presence verifier queries the DB directly and does NOT apply
    // Eloquent global scopes — so the scope alone does NOT fix these. They were
    // scoped explicitly; this proves that, for all three rules.
    $requests = [
        BehavioralAssessmentRequest::class => 'student_curriculum_id',
        PsychomotorSkillRequest::class => 'student_curriculum_id',
        PromoteStudentRequest::class => 'from_student_curriculum_id',
    ];

    foreach ($requests as $class => $field) {
        $check = fn (string $uuid) => ActiveSchool::runFor($schoolA->id, function () use ($class, $field, $uuid) {
            $rules = (new $class)->rules();

            return Validator::make([$field => $uuid], [$field => $rules[$field]])->fails();
        });

        expect($check($episodeB->uuid))->toBeTrue("{$class} accepted a FOREIGN episode uuid")
            ->and($check($episodeA->uuid))->toBeFalse("{$class} rejected its OWN School's episode uuid");
    }
});

it('DASHBOARD LEG — the raw student_curricula join carries an explicit School predicate', function () {
    // STRUCTURAL guard, and deliberately labelled as such — same idiom as
    // SchemaConventionsTest asserting triggers exist BY NAME.
    //
    // Why not behavioural: this join sits five tables deep (class_levels →
    // class_level_arms → curricula → curriculum_subjects → student_subjects →
    // student_curricula) and there are no factories for four of them, while
    // CurriculumFactory leaves class_level_arm_id null — so the query returns
    // nothing without ~5 hand-built tables. Worse, provoking the actual leak needs
    // a CROSS-School student_subject, which is only plantable with FK checks
    // disabled. The cost of that fixture exceeds its value here; what matters is
    // that the predicate cannot be removed unnoticed.
    //
    // An earlier version of this test asserted a hand-written query instead of the
    // service, and stayed GREEN while the service regressed — caught by bite-proof.
    // This version goes red when the predicate is dropped.
    $source = file_get_contents(app_path('Services/Dashboard/DashboardAnalysisService.php'));

    expect($source)->toContain("\$join->on('student_curricula.id', '=', 'student_subjects.student_curriculum_id')")
        ->and($source)->toContain("->where('student_curricula.school_id', '=', \$schoolId)")
        // …and NOT the old unfiltered two-arg form.
        ->and($source)->not->toContain("->leftJoin('student_curricula', 'student_curricula.id'");
});

it('MASS WRITE — PrincipalApproval-style bulk update cannot reach another School', function () {
    [$schoolA, $episodeA] = scopedEpisode();
    [, $episodeB] = scopedEpisode();

    // PrincipalApprovalController::updateApproval builds StudentCurriculum::query()
    // and calls ->update(); it was narrowed only by whereHas('curriculum'). The
    // global scope now constrains the UPDATE itself.
    $updated = ActiveSchool::runFor(
        $schoolA->id,
        fn () => StudentCurriculum::query()->update(['principal_approval' => true]),
    );

    expect($updated)->toBe(1)
        ->and((bool) DB::table('student_curricula')->where('id', $episodeA->id)->value('principal_approval'))->toBeTrue()
        ->and((bool) DB::table('student_curricula')->where('id', $episodeB->id)->value('principal_approval'))->toBeFalse();
});
