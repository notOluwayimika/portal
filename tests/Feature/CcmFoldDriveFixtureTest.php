<?php

use App\Exceptions\CcmFoldRefused;
use App\Jobs\MoveFromCcmJob;
use App\Jobs\MoveFromTermJob;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\MarkingComponent;
use App\Models\Score;
use App\Models\StudentCurriculum;
use Database\Seeders\CcmFoldDriveSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * THE FIXTURE PROVEN RED BEFORE A BROWSER IS SPENT ON IT.
 *
 * Leg 4 of the CCM fold drive asserts a NEGATIVE: the fold refuses, the gate stays up, the rollover
 * stays 422. That assertion is worth exactly as much as the fixture's ability to produce a refusal
 * — and in a browser a fold that SUCCEEDED and a fold that COULD NEVER HAVE FAILED are the same
 * observation: a green batch over a cleared gate. Neither the screen nor the operator can tell them
 * apart, so the distinction has to be established here, against the SAME builder the drive seeds
 * from ({@see CcmFoldDriveSeeder} — not a lookalike; a lookalike proves nothing about the fixture
 * the browser gets).
 *
 * So both directions are pinned:
 *   - the scheme-asymmetric world REFUSES, naming the component (the arm the drive depends on);
 *   - the same world with ONE counterpart added FOLDS CLEANLY (without which a guard that always
 *     threw would pass the arm above, and the fixture would be proving the guard is broken rather
 *     than that the fixture is live);
 *   - the subject-local world FOLDS CLEANLY (legs 1-3 — where the matcher is handed matches by
 *     construction and the guard must stay silent).
 *
 * Everything runs through the REAL jobs, in the real order the drive will run them: end-of-term
 * arrival first, marks second, fold third. A fixture that plants the CCM curriculum directly would
 * skip the construction the drive is here to observe.
 */

/** Run the end-of-term rollover for one source curriculum, exactly as the batch does. */
function ccmd_rollover(Curriculum $source, int $adminId): void
{
    (new MoveFromTermJob($source, $adminId, (int) $source->school_id))->handle();
}

/** Enter one mark per pupil against a component on the arrival subject — the drive's leg 2. */
function ccmd_mark(CurriculumSubject $subject, MarkingComponent $component, array $students, int $adminId, float $mark): void
{
    foreach ($students as $student) {
        Score::create([
            'student_id' => $student->id,
            'curriculum_subject_id' => $subject->id,
            'marking_component_id' => $component->id,
            'score' => $mark,
            'created_by' => $adminId,
        ]);
    }
}

// Drive the scheme-asymmetric world up to the moment of the fold.
// Returns ['world' => the seeded world, 'ccm' => the CCM arrival, 'subject' => its curriculum subject].
//
function ccmd_scheme_world_at_fold(): array
{
    $w = (new CcmFoldDriveSeeder)->seedSchemeAsymmetricWorld();

    ccmd_rollover($w['source'], (int) $w['operator']->id);

    $ccm = CcmFoldDriveSeeder::arrival($w['source'], $w['terms'][3], isCcm: true);
    // POSITIVE, so the message actually reaches the reader. Under `->not->` Pest runs the positive
    // assertion, discards its exception and composes a generic sentence with every argument passed
    // through Exporter::shortenedExport() — so a custom message written there is exported and
    // truncated rather than printed, which is the one thing it exists to do. The `$message`
    // parameter is accepted without complaint, which is what makes it survive review.
    expect($ccm !== null)->toBeTrue('the rollover did not create a CCM arrival — the rest of this fixture is vacuous');

    // THE SCHEME PATH, ASSERTED RATHER THAN ASSUMED. If the arrival carried no marking scheme it
    // would fall onto the legacy subject-local path, where cloneCurriculumSubjects copies the
    // component names and the matcher is handed matches by construction — the guard could not fire
    // and leg 4 would be untestable while still LOOKING like a CCM arrival.
    expect((int) $ccm->marking_scheme_id)->toBe((int) $w['ccmScheme']->id);

    $subject = CurriculumSubject::where('curriculum_id', $ccm->id)->firstOrFail();

    return ['world' => $w, 'ccm' => $ccm, 'subject' => $subject];
}

// ---------------------------------------------------------------------------
// Leg 4's fixture — it must be able to go RED
// ---------------------------------------------------------------------------

it('leg 4 fixture — a scored CCM-only component makes the fold REFUSE, naming the component', function () {
    ['world' => $w, 'ccm' => $ccm, 'subject' => $subject] = ccmd_scheme_world_at_fold();

    // The mark lands on the component with no non-CCM counterpart. This is the drive's leg 2, and
    // it is what converts an ordinary scheme difference into data about to be destroyed.
    ccmd_mark($subject, $w['ccmOnlyComponent'], $w['pupils'], (int) $w['operator']->id, 18.0);

    $refusal = null;

    try {
        (new MoveFromCcmJob($ccm, (int) $w['operator']->id, (int) $w['school']->id))->handle();
    } catch (Throwable $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(CcmFoldRefused::class)
        ->and($refusal->getMessage())->toContain('Half Term Project')
        // ── THE FORM LARAVEL PERSISTS, ASSERTED AT THE SOURCE ──────────────────────────────────
        // `failed_jobs.exception` is `(string) $throwable`, so THIS is the value that becomes the
        // operator's sentence. Asserting getMessage() alone would pass with the default
        // stringification putting an absolute server path on the panel — which is exactly what
        // shipped, and what a real-queue drive found. The panel arm in CcmFoldSurfaceTest is the
        // other end of the same claim; this one stops the leak being reintroduced here.
        ->and((string) $refusal)->toBe($refusal->getMessage())
        ->and((string) $refusal)->not->toContain(base_path())
        ->and((string) $refusal)->not->toContain('Stack trace');

    // NOTHING HALF-DONE. The job runs inside DB::transaction, so the refusal must leave the CCM
    // curriculum OPEN — a closed source whose marks never carried is the unrecoverable half of the
    // defect this guard exists to prevent, and it is also what would let the gate clear.
    expect($ccm->fresh()->status)->toBe('active')
        ->and($ccm->fresh()->is_ccm)->toBeTrue();

    // And the pupils have not been promoted out of a fold that did not happen — which is what the
    // drive's leg 4 will read off the gate.
    expect(StudentCurriculum::withoutGlobalScopes()
        ->where('curriculum_id', $ccm->id)->where('status', 'promoted')->count())->toBe(0);
});

it('leg 4 fixture — the SAME world folds cleanly once the non-CCM scheme gains the counterpart', function () {
    ['world' => $w, 'ccm' => $ccm, 'subject' => $subject] = ccmd_scheme_world_at_fold();

    ccmd_mark($subject, $w['ccmOnlyComponent'], $w['pupils'], (int) $w['operator']->id, 18.0);

    // ONE COMPONENT, AND NOTHING ELSE, ADDED TO THE NON-CCM SIDE. This is the control that stops the
    // arm above passing for the wrong reason: a guard that refused unconditionally, or a fixture
    // broken in some way that merely happens to throw, would fail here. It also pins that the guard
    // keys on LOSS and not on shape.
    MarkingComponent::create([
        'marking_scheme_id' => $w['nonCcmScheme']->id,
        'school_id' => $w['school']->id,
        'name' => 'Half Term Project',
        'weight' => 0.5,
        'is_ccm' => false,
    ]);

    (new MoveFromCcmJob($ccm, (int) $w['operator']->id, (int) $w['school']->id))->handle();

    expect($ccm->fresh()->status)->toBe('closed');

    // The previously-droppable mark actually ARRIVED, rescaled by the weight ratio 0.5 -> 0.5, i.e.
    // unchanged. Asserting the value rather than the row count is what makes this a parity check
    // instead of a "something was written" check.
    $target = CcmFoldDriveSeeder::arrival($w['source'], $w['terms'][3], isCcm: false);
    $newSubject = CurriculumSubject::where('curriculum_id', $target->id)->firstOrFail();
    $carried = Score::where('curriculum_subject_id', $newSubject->id)
        ->where('marking_component_id', '!=', $w['ccmOnlyComponent']->id)
        ->pluck('score')->map(fn ($s) => (float) $s)->sort()->values()->all();

    expect($carried)->toBe([18.0, 18.0]);
});

it('leg 4 fixture — an UNMATCHED but unscored component does not refuse, so the refusal is about the marks', function () {
    ['world' => $w, 'ccm' => $ccm] = ccmd_scheme_world_at_fold();

    // Same asymmetric schemes, no mark on the CCM-only component. Two schemes that merely differ are
    // ordinary; if this refused, leg 4 would be proving "the schemes differ" rather than "marks
    // would be lost", and the drive's reason line would be about the wrong thing.
    (new MoveFromCcmJob($ccm, (int) $w['operator']->id, (int) $w['school']->id))->handle();

    expect($ccm->fresh()->status)->toBe('closed');
});

// ---------------------------------------------------------------------------
// Legs 1-3's fixture — the guard must stay SILENT here
// ---------------------------------------------------------------------------

it('legs 1-3 fixture — the flag on the landing slot decides CCM, not the rollover', function () {
    $w = (new CcmFoldDriveSeeder)->seedSubjectLocalWorld();

    ccmd_rollover($w['ccmSource'], (int) $w['operator']->id);
    ccmd_rollover($w['plainSource'], (int) $w['operator']->id);

    // THE POSITIVE AND THE NEGATIVE ARM SIDE BY SIDE. Same session, same slot movement 1 -> 3, same
    // exam type, same school — one participation flag differs. Without the sibling, "pupils landed
    // in a CCM curriculum" is equally explained by a rollover that lands everyone in CCM.
    $ccmArrival = CcmFoldDriveSeeder::arrival($w['ccmSource'], $w['terms'][3], isCcm: true);
    $plainArrival = CcmFoldDriveSeeder::arrival($w['plainSource'], $w['terms'][3], isCcm: false);

    expect($ccmArrival)->not->toBeNull()
        ->and($plainArrival)->not->toBeNull()
        // And the counter-arms: neither level landed on the other side of the flag.
        ->and(CcmFoldDriveSeeder::arrival($w['ccmSource'], $w['terms'][3], isCcm: false))->toBeNull()
        ->and(CcmFoldDriveSeeder::arrival($w['plainSource'], $w['terms'][3], isCcm: true))->toBeNull();

    // Pupils actually moved, on both arms — an arrival with nobody in it would satisfy every
    // assertion above while the drive's leg 1 had nothing to look at.
    expect(StudentCurriculum::withoutGlobalScopes()->where('curriculum_id', $ccmArrival->id)->count())->toBe(2)
        ->and(StudentCurriculum::withoutGlobalScopes()->where('curriculum_id', $plainArrival->id)->count())->toBe(1);
});

it('legs 1-3 fixture — the fold SUCCEEDS, because the matcher is handed matches by construction', function () {
    $w = (new CcmFoldDriveSeeder)->seedSubjectLocalWorld();

    ccmd_rollover($w['ccmSource'], (int) $w['operator']->id);
    $ccm = CcmFoldDriveSeeder::arrival($w['ccmSource'], $w['terms'][3], isCcm: true);

    // This world has NO marking scheme, which is the whole reason it is a separate school: schemes
    // are keyed (school, is_ccm, version) school-wide, so leg 4's active CCM scheme would attach
    // itself here too and this fold would refuse.
    expect($ccm->marking_scheme_id)->toBeNull();

    $subject = CurriculumSubject::where('curriculum_id', $ccm->id)->firstOrFail();
    $ca = $subject->effectiveMarkingComponents()->firstWhere('name', 'Continuous Assessment');
    expect($ca)->not->toBeNull();

    ccmd_mark($subject, $ca, $w['ccmPupils'], (int) $w['operator']->id, 30.0);

    (new MoveFromCcmJob($ccm, (int) $w['operator']->id, (int) $w['school']->id))->handle();

    expect($ccm->fresh()->status)->toBe('closed')
        ->and(StudentCurriculum::withoutGlobalScopes()
            ->where('curriculum_id', $ccm->id)->where('status', 'promoted')->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// The precondition the drive's WORKER depends on
// ---------------------------------------------------------------------------

it('MoveFromCcmJob declares no backoff, which is what makes the drive worker safe to stop-when-empty', function () {
    // THE DRIVE RUNS `queue:work --stop-when-empty`. A refused fold is retried $tries times, and
    // with NO backoff each failure re-releases the job immediately, so the queue is never observed
    // empty between attempts and the worker stays alive through all three. Add a backoff and the
    // worker exits during the gap: the drive would see ONE attempt, the batch would sit unfinished,
    // and leg 4's "the reason reached the panel" would silently become untested while still
    // appearing to run.
    //
    // That dependency is invisible at the call site, so it is asserted here rather than assumed.
    $job = new MoveFromCcmJob(new Curriculum, 1, 1);

    expect($job->tries)->toBe(3)
        ->and(property_exists($job, 'backoff'))->toBeFalse()
        ->and(method_exists($job, 'backoff'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// rc_level's CCM slots — the shared helper, and the axis it now has to cross
// ---------------------------------------------------------------------------

it('rc_level writes is_ccm PER SLOT, and defaults every slot to false', function () {
    $w = rc_world();

    // Same level shape twice, one argument apart. Reading is_ccm back per slot is what makes this
    // an assertion about the FLAG rather than about the rows existing — a helper that wrote every
    // slot true, or every slot false, passes a count check and fails here.
    [$ccmLevel] = rc_level($w['school'], 'Year 7', 7, [1, 3], [], [3]);
    [$plainLevel] = rc_level($w['school'], 'Year 8', 8, [1, 3]);

    $flags = fn ($level) => ClassLevelTermParticipation::withoutGlobalScopes()
        ->where('class_level_id', $level->id)
        ->orderBy('term_order')
        ->pluck('is_ccm', 'term_order')
        ->map(fn ($v) => (bool) $v)
        ->all();

    // The CCM slot is slot 3 ONLY — slot 1 on the same level stays false, which is the whole reason
    // the parameter is a per-slot list and not a boolean.
    expect($flags($ccmLevel))->toBe([1 => false, 3 => true])
        // And the DEFAULT is byte-identical to the behaviour all 33 existing call sites had before
        // this parameter existed. Without this arm, a default of "all true" would pass the arm above.
        ->and($flags($plainLevel))->toBe([1 => false, 3 => false]);
});
