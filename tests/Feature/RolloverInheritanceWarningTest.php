<?php

use App\Enums\StudentStatusEnum;
use App\Jobs\MoveToNextYearJob;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Subject;
use App\Services\Rollover\RolloverPlanner;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * THE PREVIEW MUST NOT PROMISE WHAT THE COMMIT WILL NOT DO, NOR WARN ABOUT WHAT IT WILL FIX.
 *
 * End-of-year now seeds a destination's subjects from the same level's PRIOR session (#386). The
 * preview's red "these will land empty — set them up first" warning predates that and was true of
 * every unconfigured destination when it was written. It is now true of only some of them, and for
 * the rest it steers the operator into hand-building a curriculum the job will build itself — which
 * is how a duplicate lands on the wrong slot and the prepared one is orphaned.
 *
 * The property under test is PARITY: the flag the screen renders and the condition the job seeds on
 * are one lookup (ClosingSessionSubjects), so the screen cannot drift from the commit. The arm that
 * actually holds that is `the preview's promise survives the commit`, which runs both for real.
 */

/**
 * Year 7 -> Year 8 where the Y8 destination does NOT exist, plus an optional prior-session Y8
 * curriculum for it to inherit from.
 *
 * The prior instance is built on the SOURCE session's term and the DESTINATION's arm — that pairing
 * is the whole lookup, and getting either half wrong is the failure this file is here to catch.
 */
function riw_world(bool $withPriorY8, bool $priorHasSubjects = true): array
{
    $w = rc_world();

    $sourceTerm = rc_term($w['source'], 1);
    $targetTerm = rc_term($w['target'], 1);

    [$y7, $arm7] = rc_level($w['school'], 'Year 7', 7, [1]);
    [$y8, $arm8] = rc_level($w['school'], 'Year 8', 8, [1], ['default_exam_type_id' => $w['examType']->id]);
    $y7->update(['next_class_level_id' => $y8->id]);

    $curriculum = rc_curriculum($w['school'], $arm7, $sourceTerm, $w['examType']);

    $priorY8 = null;
    if ($withPriorY8) {
        // LAST YEAR'S Year 8 — the source session's term, the destination's arm.
        $priorY8 = rc_curriculum($w['school'], $arm8, $sourceTerm, $w['examType']);

        if ($priorHasSubjects) {
            riw_subject($w, $priorY8);
        }
    }

    $student = Student::create([
        'school_id' => $w['school']->id,
        'first_name' => 'Pupil',
        'last_name' => Str::random(6),
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);
    StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]);

    rollover_grant($w['admin'], $w['school']);

    return $w + compact('sourceTerm', 'targetTerm', 'y7', 'y8', 'arm7', 'arm8', 'curriculum', 'priorY8', 'student');
}

function riw_subject(array $w, Curriculum $curriculum, bool $compulsory = true, bool $active = true): CurriculumSubject
{
    return CurriculumSubject::create([
        'curriculum_id' => $curriculum->id,
        'subject_id' => Subject::create([
            'school_id' => $w['school']->id,
            'name' => 'Subject '.Str::random(6),
            'code' => Str::random(4),
        ])->id,
        'is_compulsory' => $compulsory,
        'active' => $active,
    ]);
}

function riw_plan(array $w)
{
    return ActiveSchool::runFor(
        $w['school']->id,
        fn () => app(RolloverPlanner::class)->planEndOfYear($w['source'], $w['target']),
    );
}

/** Run the real end-of-year job for the source curriculum. */
function riw_commit(array $w): void
{
    (new MoveToNextYearJob($w['curriculum'], $w['target'], $w['admin']->id, (int) $w['school']->id))->handle();
}

/** The destination Y8 curriculum, after a commit has created it. */
function riw_destination(array $w): ?Curriculum
{
    return Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('class_level_arm_id', $w['arm8']->id)
        ->where('term_id', $w['targetTerm']->id)
        ->first();
}

// ---------------------------------------------------------------------------

it('does not count an INHERITABLE destination as one that will land empty', function () {
    // THE REPRODUCTION. Before this change the destination was unconfigured, so it was counted in
    // unconfigured_count and rendered under the red "will land empty / set them up first" warning —
    // even though the commit populates it from last year's Year 8.
    $w = riw_world(withPriorY8: true);

    $plan = riw_plan($w);

    expect($plan->placement->unconfiguredCount())->toBe(0);
    expect($plan->placement->unconfiguredKeys())->toBe([]);
    expect($plan->placement->inheritingCount())->toBe(1);

    // The per-row flags the screen renders, asserted as the pair rather than one of them: a row that
    // is neither, or both, is a badge that contradicts the warning above it.
    $row = $plan->placement->advancers->first();
    expect($row->destinationWillLandEmpty())->toBeFalse();
    expect($row->destinationWillInherit())->toBeTrue();
});

it('still flags a destination with NO prior instance as landing empty', function () {
    // THE NEGATIVE, UNCHANGED — a first year of operation, or a genuinely new level. The red warning
    // and the acknowledgment gate must behave exactly as they did before.
    $w = riw_world(withPriorY8: false);

    $plan = riw_plan($w);

    expect($plan->placement->unconfiguredCount())->toBe(1);
    expect($plan->placement->unconfiguredKeys())->toHaveCount(1);
    expect($plan->placement->inheritingCount())->toBe(0);

    $row = $plan->placement->advancers->first();
    expect($row->destinationWillLandEmpty())->toBeTrue();
    expect($row->destinationWillInherit())->toBeFalse();
});

it('does not treat an EMPTY prior-session curriculum as something to inherit', function () {
    // The prior row exists but was never configured — the year-before's rollover created it bare.
    // Inheriting emptiness from it would silently shadow the fact that nothing is there.
    $w = riw_world(withPriorY8: true, priorHasSubjects: false);

    $plan = riw_plan($w);

    expect($plan->placement->unconfiguredCount())->toBe(1);
    expect($plan->placement->inheritingCount())->toBe(0);
});

it('calls a destination holding a NON-COMPULSORY subject empty, because the job will not seed it', function () {
    // THE STATE A LITERAL READING OF "does a prior exist?" GETS WRONG, and the reason inheritance is
    // a conjunction rather than a lookup.
    //
    // The destination EXISTS and holds one non-compulsory subject. So:
    //   - it is unconfigured        — destinationIsUnconfigured tests ACTIVE COMPULSORY subjects;
    //   - it will NOT be seeded     — the job's guard refuses any destination with ANY subject row.
    // A prior-session Year 8 with subjects exists, so "a prior exists" is TRUE. If the flag read only
    // that, the screen would say "will inherit — no action needed" about a destination that lands
    // with no compulsory subjects: the same lie this branch removes, pointing the other way.
    $w = riw_world(withPriorY8: true);

    // Pre-create the destination and give it a non-compulsory subject.
    $destination = rc_curriculum($w['school'], $w['arm8'], $w['targetTerm'], $w['examType']);
    riw_subject($w, $destination, compulsory: false);

    $plan = riw_plan($w);

    expect($plan->placement->unconfiguredCount())->toBe(1);
    expect($plan->placement->inheritingCount())->toBe(0);

    $row = $plan->placement->advancers->first();
    expect($row->destinationWillLandEmpty())->toBeTrue();

    // And the job genuinely does not seed it — the preview is not merely being pessimistic.
    riw_commit($w);
    expect(CurriculumSubject::where('curriculum_id', $destination->id)->count())->toBe(1);
    expect(
        CurriculumSubject::where('curriculum_id', $destination->id)->where('is_compulsory', true)->exists()
    )->toBeFalse();
});

it('THE ANTI-LIE ARM — every promise the preview makes survives the real commit', function () {
    // The load-bearing arm, and the only one that can catch the flag and the seeding drifting apart.
    // Both halves run for real: a real preview, then the real job, then the database is read. No
    // stub, because a stub of either side is a second implementation of the thing under test.
    //
    // Both directions in one test on purpose: "inheritable lands non-empty" alone is satisfied by a
    // flag that is always true, and "empty lands empty" alone by one that is always false.

    // ── DIRECTION 1: called INHERITING -> lands non-empty, with last year's subjects.
    $inheritable = riw_world(withPriorY8: true);
    $priorNames = CurriculumSubject::where('curriculum_id', $inheritable['priorY8']->id)
        ->with('subject')->get()->map(fn ($cs) => $cs->subject->name)->sort()->values()->all();

    $plan = riw_plan($inheritable);
    expect($plan->placement->inheritingCount())->toBe(1);
    expect($plan->placement->unconfiguredCount())->toBe(0);

    riw_commit($inheritable);

    $landed = riw_destination($inheritable);
    expect($landed)->not->toBeNull();
    $landedNames = CurriculumSubject::where('curriculum_id', $landed->id)
        ->with('subject')->get()->map(fn ($cs) => $cs->subject->name)->sort()->values()->all();

    // EQUALS the prior's set — the promise was "it inherits last year's", not "it is non-empty".
    expect($landedNames)->toBe($priorNames);
    expect($landedNames)->not->toBe([]);

    // And the pupil landed able to study, which is what the warning is ultimately about.
    $episode = StudentCurriculum::withoutGlobalScopes()
        ->where('student_id', $inheritable['student']->id)
        ->where('curriculum_id', $landed->id)->first();
    expect($episode)->not->toBeNull();
    expect($episode->studentSubjects()->count())->toBe(count($priorNames));

    // ── DIRECTION 2: called EMPTY -> genuinely lands empty.
    $empty = riw_world(withPriorY8: false);

    $emptyPlan = riw_plan($empty);
    expect($emptyPlan->placement->unconfiguredCount())->toBe(1);
    expect($emptyPlan->placement->inheritingCount())->toBe(0);

    riw_commit($empty);

    $emptyLanded = riw_destination($empty);
    expect($emptyLanded)->not->toBeNull();
    expect(CurriculumSubject::where('curriculum_id', $emptyLanded->id)->count())->toBe(0);
});

it('does not inherit across the is_ccm boundary', function () {
    // is_ccm is part of the lookup key: a CCM destination must not take a non-CCM sibling's subjects,
    // whose weights mean something different. The Y8 slot here is the CCM variant, and the only
    // prior-session Y8 is non-CCM — so there is nothing to inherit and the destination lands empty.
    $w = rc_world();
    $sourceTerm = rc_term($w['source'], 1);
    $targetTerm = rc_term($w['target'], 1);

    [$y7, $arm7] = rc_level($w['school'], 'Year 7', 7, [1]);
    [$y8, $arm8] = rc_level($w['school'], 'Year 8', 8, [1], ['default_exam_type_id' => $w['examType']->id], [1]);
    $y7->update(['next_class_level_id' => $y8->id]);

    $curriculum = rc_curriculum($w['school'], $arm7, $sourceTerm, $w['examType']);

    // Last year's Year 8, NON-CCM, with subjects — the decoy.
    $priorNonCcm = rc_curriculum($w['school'], $arm8, $sourceTerm, $w['examType'], isCcm: false);
    CurriculumSubject::create([
        'curriculum_id' => $priorNonCcm->id,
        'subject_id' => Subject::create([
            'school_id' => $w['school']->id, 'name' => 'Decoy '.Str::random(5), 'code' => Str::random(4),
        ])->id,
        'is_compulsory' => true,
        'active' => true,
    ]);

    $student = Student::create([
        'school_id' => $w['school']->id, 'first_name' => 'Pupil', 'last_name' => Str::random(6),
        'gender' => 'female', 'admission_number' => 'ADM-'.Str::random(8),
    ]);
    StudentCurriculum::create([
        'student_id' => $student->id, 'curriculum_id' => $curriculum->id, 'status' => StudentStatusEnum::ACTIVE,
    ]);
    rollover_grant($w['admin'], $w['school']);

    $plan = ActiveSchool::runFor(
        $w['school']->id,
        fn () => app(RolloverPlanner::class)->planEndOfYear($w['source'], $w['target']),
    );

    // The CCM destination has nothing of its own to inherit — flagged empty, not inheriting.
    expect($plan->placement->inheritingCount())->toBe(0);
    expect($plan->placement->unconfiguredCount())->toBe(1);

    // And the commit agrees: the CCM destination lands empty rather than taking the non-CCM list.
    (new MoveToNextYearJob($curriculum, $w['target'], $w['admin']->id, (int) $w['school']->id))->handle();

    $landed = Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('class_level_arm_id', $arm8->id)->where('term_id', $targetTerm->id)->first();
    expect((bool) $landed->is_ccm)->toBeTrue();
    expect(CurriculumSubject::where('curriculum_id', $landed->id)->count())->toBe(0);
});

it('computing inheritability writes nothing — the preview stays read-only', function () {
    // The hard constraint. The flag is resolved for every unconfigured destination on every render,
    // so a write here would build a year's worth of curricula from a registrar opening the screen.
    $w = riw_world(withPriorY8: true);

    $curriculaBefore = Curriculum::withoutGlobalScope(SchoolScope::class)->count();
    $subjectsBefore = CurriculumSubject::count();

    riw_plan($w);
    riw_plan($w);

    expect(Curriculum::withoutGlobalScope(SchoolScope::class)->count())->toBe($curriculaBefore);
    expect(CurriculumSubject::count())->toBe($subjectsBefore);

    // And the commit DOES write — so the assertion above is measuring something that can move.
    riw_commit($w);
    expect(CurriculumSubject::count())->toBeGreaterThan($subjectsBefore);
});
