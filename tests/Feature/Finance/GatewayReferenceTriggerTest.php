<?php

use App\Finance\Services\GatewayReference;
use App\Models\School;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The reference format is enforced by the DATABASE, not only by the model.
 *
 * `GatewayTransaction::booted()` refuses a malformed reference on `creating`, which fires on the
 * Eloquent write and on nothing else. Every arm here writes with the RAW QUERY BUILDER, because
 * that is the path the model guard cannot see and the path step 3 is permitted to use — the
 * boundary lint bans `DB::table` on a `finance_` literal only OUTSIDE `app/Finance`, and step 3
 * lives inside it.
 *
 * A test that exercised the model would prove the guard that was never in question.
 */
uses(RefreshDatabase::class);

/** Insert straight past Eloquent. Returns the driver code, or 0 when the row was ACCEPTED. */
function grtRawInsert(int $schoolId, string $reference): int
{
    try {
        DB::table('finance_gateway_transactions')->insert([
            'uuid' => (string) Str::uuid(),
            'school_id' => $schoolId,
            'invoice_id' => 1,
            'provider' => 'paystack',
            'reference' => $reference,
            'amount_minor' => 100000,
            'amount_currency' => 'NGN',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 0;
    } catch (QueryException $e) {
        return (int) ($e->errorInfo[1] ?? -1);
    }
}

it('refuses a hand-built reference on the RAW path the model guard cannot see', function (string $reference, string $why) {
    $school = School::factory()->create();

    // 1644 is SIGNAL SQLSTATE '45000' — the trigger. NOT 1452 (the invoice FK), which would mean the
    // row was rejected for the wrong reason and the reference arm was never reached.
    expect(grtRawInsert((int) $school->id, sprintf($reference, $school->id)))->toBe(1644, $why);
})->with([
    'no prefix' => ['INV-000042', 'the natural mistake in step 3'],
    'wrong prefix' => ['psk-1-abcdef', 'near-miss prefix'],
    'empty random segment' => ['bpsk-%d-', 'LIKE alone would admit this'],
    'four segments' => ['bpsk-%d-abc-def', 'the parser rejects it for segment count'],
    'upper-case prefix' => ['BPSK-%d-abcdef', 'passes under the table default collation'],
    'upper-case random' => ['bpsk-%d-ABCDEF', 'mint() lower-cases; the shape says so'],
    'leading zero on the school' => ['bpsk-0%d-abcdef', 'two spellings of one school in a routing key'],
]);

it('refuses a well-formed reference minted for ANOTHER school', function () {
    $a = School::factory()->create();
    $b = School::factory()->create();

    // Shape-valid and routable — to somebody else. A shape-only guard admits this, which is why the
    // binding clause exists alongside the REGEXP.
    expect(grtRawInsert((int) $a->id, GatewayReference::mint((int) $b->id)))->toBe(1644);
});

it('ACCEPTS a properly minted reference — the known negative', function () {
    $school = School::factory()->create();

    // THE ARM THAT MATTERS. A guard refusing everything is indistinguishable from a strict one
    // until someone bypasses it. `bin/db-exclusive` shipped broken-closed for exactly this reason.
    //
    // 1452 rather than 0: this fixture plants no invoice, so the composite (invoice_id, school_id)
    // foreign key refuses the row AFTER the trigger has accepted it. That is the proof — reaching
    // the FK means the reference arm passed. Asserting 0 would require an invoice fixture whose
    // only purpose is to let this arm reach a different error.
    expect(grtRawInsert((int) $school->id, GatewayReference::mint((int) $school->id)))->toBe(1452);
});
