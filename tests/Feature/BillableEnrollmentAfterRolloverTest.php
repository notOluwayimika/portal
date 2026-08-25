<?php

use App\Academics\BillableEnrollmentAdapter;
use App\Enums\StudentStatusEnum;
use App\Jobs\MoveToNextYearJob;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * THE FIRST MANUAL INVOICE RUN AFTER A ROLLOVER MUST BILL EACH PUPIL ONCE.
 *
 * Billing is not automatic: a bulk-invoice-run is raised by hand, and it reads its cohort through
 * BillableEnrollmentProvider (ProcessBulkInvoiceRun:263). So the risk is DEFERRED, not immediate —
 * a rollover leaves the source episode `promoted` and creates a new `active` one, and the damage
 * only appears when someone bills afterwards and the pupil is charged on both.
 *
 * The four-point transition tests already pin the `transferred` half of this (reassignment). This
 * file pins the `promoted` half (end-of-year rollover), which had no arm.
 *
 * ── WHAT IS ACTUALLY BEING PINNED, STATED HONESTLY ───────────────────────────────────────────────
 * BillableEnrollmentAdapter::billableEpisodes() is PER-STUDENT-LATEST, not per-episode:
 *
 *     ->where('status', ACTIVE)
 *     ->whereIn('id', fn () => selectRaw('MAX(id)')->where('status', ACTIVE)->groupBy('student_id'))
 *
 * `groupBy('student_id')` — one line per STUDENT. So a pupil cannot hold two billable lines however
 * many curricula they appear in, and cross-curriculum double-billing is impossible BY CONSTRUCTION
 * rather than by the status filter catching it. This file therefore pins a construction, and the
 * status allowlist is defence in depth on top of it.
 *
 * It is still worth a test, because the construction is one `groupBy` away from being per-episode,
 * and nothing else would notice.
 *
 * ── THE FIXTURE IS BUILT SO STATUS CARRIES IT, NOT ID ORDERING ───────────────────────────────────
 * A natural rollover creates the new episode AFTER the source, so the new episode holds the higher
 * id and MAX(id) would select it even with every status filter removed. A test built that way
 * passes against a completely unguarded adapter.
 *
 * So the target episode is created FIRST and the source SECOND, giving the PROMOTED source the
 * higher id — it is exactly the row a status-blind selection would pick. Same technique as the
 * four-point test's prior-ended-stint fixture, and the ordering is asserted rather than assumed, so
 * a factory change that flips it cannot silently make this test vacuous again.
 *
 * ── WATCHED REDS, AND WHICH ASSERTION CARRIED EACH ───────────────────────────────────────────────
 * Three mutations of the adapter, each restored after:
 *
 *   1. Allowlist widened to [active, promoted] on BOTH clauses → RED, at the after-block: the
 *      promoted source comes back as the billable line (`toContain(target)` fails, because MAX(id)
 *      now picks the source). This is the literal double-bill risk.
 *   2. Grain changed to per-episode (`groupBy('student_id', 'curriculum_id')`) → RED, at the
 *      BEFORE-block `not->toContain(target)`: with two active episodes the pupil holds two billable
 *      lines at once. The before-state is what pins the grain — an after-only test would have
 *      stayed green here, because by then the status filter alone suffices.
 *   3. Subquery status filter dropped, outer one intact → RED, and instructively: MAX(id) selects
 *      the promoted source, the outer filter then rejects it, and the pupil becomes billable
 *      NOWHERE (`currentForStudent` returns null). Not a double-bill — a silent zero-bill. Both
 *      clauses are load-bearing and neither shadows the other.
 */
function ber_world(): array
{
    $w = rc_world();

    $sourceTerm = rc_term($w['source'], 1);
    $targetTerm = rc_term($w['target'], 1);

    [$y7, $arm7] = rc_level($w['school'], 'Year 7', 7, [1]);
    // The target level must resolve an exam type or `advance()` REFUSES rather than guessing which
    // certificate the pupil sits — a silent no-op that would leave the source active and make this
    // test pass for the wrong reason. Configured explicitly, not inherited.
    [$y8, $arm8] = rc_level($w['school'], 'Year 8', 8, [1], ['default_exam_type_id' => $w['examType']->id]);
    $y7->update(['next_class_level_id' => $y8->id]);

    $sourceCurriculum = rc_curriculum($w['school'], $arm7, $sourceTerm, $w['examType']);
    $targetCurriculum = rc_curriculum($w['school'], $arm8, $targetTerm, $w['examType']);

    $student = Student::create([
        'school_id' => $w['school']->id,
        'first_name' => 'Rolled',
        'last_name' => Str::random(6),
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    // ── ORDER IS THE POINT ───────────────────────────────────────────────────────────────────────
    // TARGET first (low id), SOURCE second (high id). MoveToNextYearJob resolves its target with
    // firstOrCreate((student_id, curriculum_id)) and does NOT rewrite an existing row's status, so
    // the pre-created active target survives the rollover and the source is the one that changes.
    $targetEpisode = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $targetCurriculum->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]);

    $sourceEpisode = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $sourceCurriculum->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]);

    return $w + compact(
        'sourceTerm', 'targetTerm', 'y7', 'y8',
        'sourceCurriculum', 'targetCurriculum', 'student', 'sourceEpisode', 'targetEpisode',
    );
}

/** Every billable enrollment uuid across BOTH cohorts — the only way to see a double-bill. */
function ber_billable(array $w): array
{
    return ActiveSchool::runFor($w['school']->id, function () use ($w) {
        $adapter = app(BillableEnrollmentAdapter::class);

        return array_map(
            fn ($e) => $e->enrollmentUuid,
            array_merge(
                $adapter->listForCohort((int) $w['school']->id, (int) $w['sourceTerm']->id, (int) $w['y7']->id),
                $adapter->listForCohort((int) $w['school']->id, (int) $w['targetTerm']->id, (int) $w['y8']->id),
            ),
        );
    });
}

it('bills a rolled-over pupil once, on the new curriculum and never on the promoted one', function () {
    $w = ber_world();

    // ── THE ORDERING THIS FIXTURE DEPENDS ON, PINNED ─────────────────────────────────────────────
    // The source must hold the HIGHER id, so that a status-blind MAX(id) would select the PROMOTED
    // row. Without this the test would pass against an adapter with no status filter at all, which
    // is the failure mode it exists to detect.
    expect($w['sourceEpisode']->id)->toBeGreaterThan($w['targetEpisode']->id);

    // ── BEFORE ───────────────────────────────────────────────────────────────────────────────────
    // Both episodes are active, so per-student-latest admits the higher id: the SOURCE is the
    // billable line. That is what makes the after-state a genuine transition rather than a
    // restatement of the starting position.
    $before = ber_billable($w);

    expect($before)->toContain($w['sourceEpisode']->uuid)
        ->and($before)->not->toContain($w['targetEpisode']->uuid)
        ->and($before)->toHaveCount(1);

    // ── THE ROLLOVER ─────────────────────────────────────────────────────────────────────────────
    ActiveSchool::runFor($w['school']->id, fn () => (new MoveToNextYearJob(
        $w['sourceCurriculum'], $w['target'], (int) $w['admin']->id, (int) $w['school']->id,
    ))->handle());

    expect($w['sourceEpisode']->fresh()->status)->toBe(StudentStatusEnum::PROMOTED)
        ->and($w['targetEpisode']->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);

    // ── AFTER ────────────────────────────────────────────────────────────────────────────────────
    $after = ber_billable($w);

    // WHICH one, not merely how many. A count alone would pass if the adapter billed the promoted
    // row INSTEAD of the new one — the same single invoice, raised against the class the pupil has
    // left, on the old session's fee schedule.
    expect($after)->toContain($w['targetEpisode']->uuid)
        ->and($after)->not->toContain($w['sourceEpisode']->uuid)
        // THE DOUBLE-BILL ASSERTION: one line across BOTH cohorts, not one line per cohort.
        ->and($after)->toHaveCount(1);
});

/**
 * The same claim through the single-invoice path, which reads the same definition.
 *
 * `currentForStudent` is what the per-student invoice screen calls, so a pupil whose promoted
 * episode answered here would be billed on the class they have left, one invoice at a time.
 */
it('resolves the new episode as the pupils current billable enrollment after a rollover', function () {
    $w = ber_world();

    ActiveSchool::runFor($w['school']->id, fn () => (new MoveToNextYearJob(
        $w['sourceCurriculum'], $w['target'], (int) $w['admin']->id, (int) $w['school']->id,
    ))->handle());

    $current = ActiveSchool::runFor(
        $w['school']->id,
        fn () => app(BillableEnrollmentAdapter::class)->currentForStudent((int) $w['student']->id),
    );

    expect($current)->not->toBeNull()
        ->and($current->enrollmentUuid)->toBe($w['targetEpisode']->uuid)
        ->and($current->enrollmentUuid)->not->toBe($w['sourceEpisode']->uuid);
});
