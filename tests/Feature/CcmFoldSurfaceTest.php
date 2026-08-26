<?php

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
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => '{}',
        'exception' => 'RuntimeException: Refusing to fold curriculum#7: 1 scored marking component(s)'
            .' on subject#3 have no counterpart on the non-CCM side and their marks would be lost —'
            .' "Project" (4 score(s)).'."\n#0 /app/Jobs/MoveFromCcmJob.php(259)\n#1 stack noise",
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
        ->and($fold['failure_reasons'][0])->not->toContain('#0 /app/Jobs')
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
