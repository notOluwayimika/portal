<?php

use App\Exceptions\CcmFoldRefused;
use App\Jobs\MoveFromCcmJob;
use App\Services\Rollover\CcmFoldBatchName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * THE FOLD, REACHABLE FROM WHERE THE BLOCK IS FELT.
 *
 * The ccm-active gate has always named the classes and said they "must be moved first" — while the
 * only thing that moves them was an endpoint no screen called. That is a dead end for exactly the
 * operators who will meet it: the ones who configure a CCM slot rather than hand-creating the
 * curriculum, and who have therefore never touched the API or a console.
 *
 * The failure path is the one that matters. A fold can REFUSE — deterministically, on config — and
 * a surface that renders "N folded" over a refusal is the silent-drop defect wearing a success
 * badge. So these arms weigh the abort at least as heavily as the happy path.
 */
function ccmf_world(): array
{
    $w = rc_world();
    $t1 = rc_term($w['source'], 1);
    rc_term($w['source'], 2);

    [$level, $arm] = rc_level($w['school'], 'Year 7', 7, [1, 2]);
    $ccm = rc_curriculum($w['school'], $arm, $t1, $w['examType'], isCcm: true);

    rollover_grant($w['admin'], $w['school']);

    return $w + ['term' => $t1, 'level' => $level, 'arm' => $arm, 'ccm' => $ccm];
}

function ccmf_fold(array $w)
{
    return test()->actingAs($w['admin'])->postJson('/api/rollover/fold-ccm', [
        'term_id' => $w['term']->uuid,
    ]);
}

/**
 * The string Laravel would actually persist for this throwable.
 *
 * `DatabaseFailedJobProvider::log()` stores `(string) $exception`, so a fixture that wants to stand
 * in for a failed job must be stringified the SAME way rather than typed by hand — the hand-typed
 * one differed from reality in exactly the dimension the assertions were about.
 */
function ccmf_stringifiedThrowable(Throwable $e): string
{
    return (string) $e;
}

it('dispatches one fold per blocking CCM class, and says QUEUED rather than done', function () {
    Bus::fake();
    $w = ccmf_world();

    $response = ccmf_fold($w)->assertStatus(202);

    // 202, not 200: the folds have to drain before the gate can clear, and an operator who reads
    // "done" will confirm a rollover against folds still in flight.
    expect($response->json('queued_jobs'))->toBe(1)
        ->and($response->json('message'))->toContain('Queued')
        ->and($response->json('batch_name'))->toStartWith(CcmFoldBatchName::KIND.':');

    Bus::assertBatchCount(1);
});

it('refuses when nothing is blocking, rather than dispatching an empty batch', function () {
    Bus::fake();
    $w = rc_world();
    $t1 = rc_term($w['source'], 1);
    rc_term($w['source'], 2);
    [, $arm] = rc_level($w['school'], 'Year 7', 7, [1, 2]);
    rc_curriculum($w['school'], $arm, $t1, $w['examType']); // NON-CCM: nothing to fold
    rollover_grant($w['admin'], $w['school']);

    test()->actingAs($w['admin'])->postJson('/api/rollover/fold-ccm', ['term_id' => $t1->uuid])
        ->assertStatus(422);

    Bus::assertNothingBatched();
});

it('refuses a seat without academics.rollover', function () {
    Bus::fake();
    $w = ccmf_world();

    $stranger = al_makeUser($w['school']->id);

    test()->actingAs($stranger)->postJson('/api/rollover/fold-ccm', ['term_id' => $w['term']->uuid])
        ->assertStatus(403);

    Bus::assertNothingBatched();
});

// ---------------------------------------------------------------------------
// THE JOB IS BATCHABLE — the trap the rollover jobs shipped with
// ---------------------------------------------------------------------------

it('can actually be batched, against a REAL bus', function () {
    $w = ccmf_world();

    // NOT Bus::fake(). BusFake::batch() returns a PendingBatchFake that SKIPS
    // ensureJobIsBatchable(), so a faked suite is structurally incapable of noticing a missing
    // Batchable trait — which is exactly how the rollover jobs shipped without it and `--commit`
    // had never once worked. This arm exists to be the one that would have caught that.
    $batch = Bus::batch([new MoveFromCcmJob($w['ccm'], (int) $w['admin']->id, (int) $w['school']->id)])
        ->name('probe')->allowFailures()->dispatch();

    expect($batch->id)->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// THE FAILURE PATH — where the honesty lives
// ---------------------------------------------------------------------------

it('surfaces the guard reason behind a failed fold, not a bare failure count', function () {
    $w = ccmf_world();

    // A batch row carrying a failed job whose exception is the guard's refusal — the shape the
    // panel must read. Written directly because driving a real queue failure through three retries
    // in a test proves the retry machinery, not the reporting this arm is about.
    // The guard's real sentence, held in a variable so the assertion can compare against the
    // MESSAGE itself rather than a re-typed copy of it — the round trip under test is
    // stringify -> persist -> read, and the message is the independent path through it.
    $message = 'Refusing to fold curriculum#7: 1 scored marking component(s) on subject#3 have no'
        .' counterpart on the non-CCM side and their marks would be lost — "Project" (4 score(s)).'
        .' Add matching component(s) to the non-CCM marking scheme, then fold again.';

    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => '{}',
        // A REAL stringified throwable, produced by throwing and catching one — NOT hand-written.
        // The hand-written version this replaces omitted the ` in /abs/path/File.php:LINE` suffix
        // that every real PHP exception carries, so this arm was green about a string the system
        // does not produce, and the panel shipped rendering a server path at the end of the
        // operator's sentence. A drive against the real queue is what found it. The double must be
        // built by the thing it stands in for.
        'exception' => ccmf_stringifiedThrowable(new CcmFoldRefused($message)),
        'failed_at' => now(),
    ]);
    DB::table('job_batches')->insert([
        'id' => (string) Str::uuid(),
        'name' => CcmFoldBatchName::forTerm((int) $w['school']->id, (int) $w['term']->id),
        'total_jobs' => 2, 'pending_jobs' => 0, 'failed_jobs' => 1,
        'failed_job_ids' => json_encode([$uuid]), 'options' => '', 'created_at' => time(), 'finished_at' => time(),
    ]);

    $body = test()->actingAs($w['admin'])->getJson('/api/rollover/batches')->assertOk()->json('data');
    $fold = collect($body)->firstWhere('kind', 'ccm-fold');

    expect($fold)->not->toBeNull()
        ->and($fold['failed_jobs'])->toBe(1)
        // THE REASON, not "1 failed". The refusal is deterministic config — retrying never fixes it
        // — so the operator needs the sentence naming the curriculum and the component, which is an
        // action they can take.
        ->and($fold['failure_reasons'])->toHaveCount(1)
        ->and($fold['failure_reasons'][0])->toContain('Project')
        ->and($fold['failure_reasons'][0])->toContain('no counterpart')
        // And NOT the stack trace: a trace on an operator screen hides the sentence they need.
        ->and($fold['failure_reasons'][0])->not->toContain('#0 ')
        // ── THE ARM THE OLD LOOKALIKE COULD NOT CARRY ──────────────────────────────────────────
        // No absolute server path, and no `:LINE` tail. A real PHP exception stringifies as
        // `Class: message in /abs/path/File.php:265`, so first-line-only strips the FRAMES but not
        // that suffix — which is how a server path reached the panel and stayed invisible to a
        // suite whose fixture omitted it. CcmFoldRefused::__toString() is what keeps this true.
        ->and($fold['failure_reasons'][0])->not->toContain('/app/Jobs')
        ->and($fold['failure_reasons'][0])->not->toContain(base_path())
        // EXACTLY the message and nothing appended. Stronger than a tail check: a path suffix, a
        // class prefix or a truncation all red here, and the expectation is the message the guard
        // was given rather than a restatement of what the reader does to it.
        ->and($fold['failure_reasons'][0])->toBe($message)
        // The FQCN is stripped too — they read the sentence, not the exception class.
        ->and($fold['failure_reasons'][0])->not->toStartWith('RuntimeException');
});

it('reports no failure reasons for a clean fold batch', function () {
    $w = ccmf_world();

    DB::table('job_batches')->insert([
        'id' => (string) Str::uuid(),
        'name' => CcmFoldBatchName::forTerm((int) $w['school']->id, (int) $w['term']->id),
        'total_jobs' => 2, 'pending_jobs' => 0, 'failed_jobs' => 0,
        'failed_job_ids' => '[]', 'options' => '', 'created_at' => time(), 'finished_at' => time(),
    ]);

    $fold = collect(test()->actingAs($w['admin'])->getJson('/api/rollover/batches')->json('data'))
        ->firstWhere('kind', 'ccm-fold');

    // The negative arm: a reason list that was always populated would pass the arm above.
    expect($fold['failure_reasons'])->toBe([])
        ->and($fold['kind'])->toBe('ccm-fold');
});

// ---------------------------------------------------------------------------
// THE TERMINAL STATE — derived from counts, because the failing case emits no completion event
// ---------------------------------------------------------------------------

/** Insert a batch row with the counts a real bus would have written. */
function ccmf_batchRow(array $w, int $total, int $pending, int $failed, array $failedIds = [], ?int $finishedAt = null, ?int $cancelledAt = null): void
{
    DB::table('job_batches')->insert([
        'id' => (string) Str::uuid(),
        'name' => CcmFoldBatchName::forTerm((int) $w['school']->id, (int) $w['term']->id),
        'total_jobs' => $total, 'pending_jobs' => $pending, 'failed_jobs' => $failed,
        'failed_job_ids' => json_encode($failedIds), 'options' => '',
        'created_at' => time(), 'finished_at' => $finishedAt, 'cancelled_at' => $cancelledAt,
    ]);
}

/** A failed_jobs row for $uuid — its PRESENCE is what makes a listed failure outstanding. */
function ccmf_failedJobRow(string $uuid, string $message = 'Refusing to fold curriculum#7.'): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => '{}',
        'exception' => ccmf_stringifiedThrowable(new CcmFoldRefused($message)),
        'failed_at' => now(),
    ]);
}

function ccmf_readBatch(array $w): array
{
    return collect(test()->actingAs($w['admin'])->getJson('/api/rollover/batches')->json('data'))
        ->firstWhere('kind', 'ccm-fold');
}

it('reads a batch whose every job has RESOLVED as stopped, not as perpetually draining', function () {
    $w = ccmf_world();

    // THE SHAPE A REAL BUS LEAVES BEHIND, and it is not hypothetical — a drive produced exactly
    // this: one job, three attempts exhausted, finished_at NULL. Laravel sets finished_at only from
    // recordSuccessfulJob when pending hits zero, and incrementFailedJobs holds pending constant,
    // so a batch with a failure NEVER emits the completion signal the panel used to wait for.
    $uuid = (string) Str::uuid();
    ccmf_failedJobRow($uuid);
    ccmf_batchRow($w, total: 1, pending: 1, failed: 1, failedIds: [$uuid]);

    $fold = ccmf_readBatch($w);

    expect($fold['is_draining'])->toBeFalse()
        ->and($fold['settled_state'])->toBe('stopped');
});

it('still reads as DRAINING between retries, so the retry window stays honest', function () {
    $w = ccmf_world();

    // The counter-arm, and the reason the rule is `pending === failed` rather than `failed > 0`:
    // a doomed job mid-retry has not recorded its failure yet, so pending still EXCEEDS failed.
    // Without this, a fix for the arm above would report "stopped" the moment anything failed and
    // would flip a still-working batch to terminal — the opposite lie, equally invisible.
    ccmf_batchRow($w, total: 3, pending: 3, failed: 0);

    expect(ccmf_readBatch($w)['is_draining'])->toBeTrue()
        ->and(ccmf_readBatch($w)['settled_state'])->toBeNull();
});

it('is still DRAINING when one job has already failed but others are still working', function () {
    $w = ccmf_world();

    // THE AXIS NEITHER OTHER ARM CROSSES: failures > 0 AND work outstanding. This is allowFailures'
    // ordinary mid-flight state — one fold refused, two still going — and it is what stops the fix
    // being written as "failed > 0 means stopped", which would flip a live batch to terminal and
    // invite a rollover confirmation against folds still in the air. pending(3) > failed(1).
    $uuid = (string) Str::uuid();
    ccmf_failedJobRow($uuid);
    ccmf_batchRow($w, total: 3, pending: 3, failed: 1, failedIds: [$uuid]);

    expect(ccmf_readBatch($w)['is_draining'])->toBeTrue()
        ->and(ccmf_readBatch($w)['settled_state'])->toBeNull();
});

it('reads a MIXED batch as stopped once the survivors have landed and the rest have failed', function () {
    $w = ccmf_world();

    // Two succeeded (pending 3 -> 1), one failed and holds pending at 1. pending === failed, so
    // every job has resolved. A count-only rule that keyed on `pending === 0` would call this
    // draining forever too — the mixed case is where allowFailures actually lives.
    $uuid = (string) Str::uuid();
    ccmf_failedJobRow($uuid);
    ccmf_batchRow($w, total: 3, pending: 1, failed: 1, failedIds: [$uuid]);

    expect(ccmf_readBatch($w)['settled_state'])->toBe('stopped')
        ->and(ccmf_readBatch($w)['done_jobs'])->toBe(2);
});

it('still reads a clean finished batch as finished, by the same one rule', function () {
    $w = ccmf_world();

    // The clean path is SUBSUMED, not special-cased: zero failures drives pending to zero and the
    // bus writes finished_at. If this reds, the derivation broke the ordinary case to fix the
    // exceptional one.
    ccmf_batchRow($w, total: 2, pending: 0, failed: 0, finishedAt: time());

    expect(ccmf_readBatch($w)['is_draining'])->toBeFalse()
        ->and(ccmf_readBatch($w)['settled_state'])->toBe('finished');
});

// ── THE RETRY PATH: `failed_jobs` is MONOTONE, and both sides of it lied ────────────────────────

it('reads a batch whose failure was RETRIED AND SUCCEEDED as finished, not as stopped', function () {
    $w = ccmf_world();

    // THE SHAPE LARAVEL ACTUALLY LEAVES after `queue:retry` succeeds: decrementPendingJobs prunes the
    // uuid out of failed_job_ids and writes `failed_jobs => $batch->failed_jobs` UNCHANGED, then
    // markAsFinished stamps finished_at. So the counter still reads 1 over a batch that is complete.
    // Keying the panel on `failed_jobs > 0` rendered this as "Stopped … will not resume on its own",
    // with NO reason beside it because the ids were pruned — a finished batch reported dead.
    ccmf_batchRow($w, total: 3, pending: 0, failed: 1, failedIds: [], finishedAt: time());

    $fold = ccmf_readBatch($w);

    expect($fold['settled_state'])->toBe('finished')
        ->and($fold['is_draining'])->toBeFalse()
        // AND the count the panel renders comes from the live reasons, not the monotone counter.
        ->and($fold['failure_reasons'])->toBe([]);
});

it('is still DRAINING while a retried job is in flight, so the session warning is not withdrawn', function () {
    $w = ccmf_world();

    // `queue:retry` pushes the job back and then DELETES the failed_jobs row, while the uuid stays in
    // failed_job_ids until that retry resolves. So the id is listed with no row behind it — and a
    // rule counting ids alone still calls this stopped while a worker is executing the job, dropping
    // "do not change the current session yet" at exactly the wrong moment. No failed_jobs row planted.
    $uuid = (string) Str::uuid();
    ccmf_batchRow($w, total: 3, pending: 1, failed: 1, failedIds: [$uuid]);

    expect(ccmf_readBatch($w)['is_draining'])->toBeTrue()
        ->and(ccmf_readBatch($w)['settled_state'])->toBeNull();
});

it('is stopped again once the retried job fails a second time', function () {
    $w = ccmf_world();

    // The worker re-creates the failed_jobs row, so the failure is outstanding again and the batch is
    // terminal again. Without this, "in flight means draining" could be written as "any listed id
    // with no row means draining forever", which never settles.
    $uuid = (string) Str::uuid();
    ccmf_failedJobRow($uuid);
    ccmf_batchRow($w, total: 3, pending: 1, failed: 1, failedIds: [$uuid]);

    expect(ccmf_readBatch($w)['settled_state'])->toBe('stopped');
});

// ── THE TWO OUTCOMES THAT HAD NO ARM ───────────────────────────────────────────────────────────

it('reads a cancelled batch as cancelled, even though cancel() also stamps finished_at', function () {
    $w = ccmf_world();

    // DatabaseBatchRepository::cancel() writes BOTH cancelled_at and finished_at, so the guard ORDER
    // is load-bearing: swap the two checks and every cancelled batch reads "finished" with nothing
    // going red, and the panel's Cancelled branch becomes dead code.
    ccmf_batchRow($w, total: 2, pending: 1, failed: 0, finishedAt: time(), cancelledAt: time());

    expect(ccmf_readBatch($w)['settled_state'])->toBe('cancelled')
        ->and(ccmf_readBatch($w)['is_draining'])->toBeFalse();
});

it('does not call an empty batch stopped', function () {
    $w = ccmf_world();

    // total_jobs = 0 has no outstanding failure and nothing in flight. It reads as draining, which is
    // what it did before this derivation existed — pinned so the `total > 0` guard is not dropped as
    // redundant.
    ccmf_batchRow($w, total: 0, pending: 0, failed: 0);

    expect(ccmf_readBatch($w)['settled_state'])->toBeNull();
});
