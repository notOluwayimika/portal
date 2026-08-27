<?php

/*
 * finance_gateway_transactions — the schema and its guards, proven by BITING them.
 *
 * Everything here writes RAW, through DB::table, and never through the model. That is the whole
 * design of this file: the guards it asserts are the DATABASE's, and a proof driven through Eloquent
 * proves only that a cast or a fillable list happened to agree with them. A webhook handler, an
 * artisan command, a job, a `tinker` session and a future second writer all reach the table by paths
 * this file deliberately does not use.
 *
 * TWO SCHOOLS IN THE FIXTURE, ALWAYS. Every isolation arm needs a genuine second school to cross
 * into, and a single-school fixture cannot express the failure it claims to refuse — it would pass
 * whether the composite foreign keys existed or not.
 *
 * The `gtx` prefix on the helpers is the fixture-namespacing discipline: Pest's helper functions are
 * global across the whole suite, and a generic name here collides with one in a file nobody was
 * editing.
 */

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\GatewayTransactionStatus;
use App\Finance\Enums\InvoiceKind;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const GTX_TABLE = 'finance_gateway_transactions';

/**
 * One school with a real invoice and a real payment, built through the domain Actions rather than by
 * hand — the invoice has to satisfy the episode guard and the payment the origin pairing, and a
 * hand-built row that happens to slip past both would make every arm below prove less than it says.
 *
 * @return array{school:int, invoice:int, payment:int}
 */
function gtxSchool(): array
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, function () use ($school, $student) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        $invoice = app(GenerateInvoice::class)->handle(
            $enrollment->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo(500000))],
            InvoiceKind::Scheduled,
        );

        $payment = app(RecordPayment::class)->handle(
            $invoice,
            Money::fromKobo(100000),
            'Payer',
            User::factory()->create(['school_id' => $school->id]),
            now()->toDateString(),
            testBankAccountId($school->id),
        );

        return ['school' => (int) $school->id, 'invoice' => (int) $invoice->id, 'payment' => (int) $payment->id];
    });
}

/**
 * A well-formed attempt row. Every arm below starts from THIS and changes exactly the one thing it
 * is about, so a refusal can only be attributed to that one thing.
 *
 * @param  array{school:int, invoice:int, payment:int}  $ctx
 * @return array<string, mixed>
 */
function gtxRow(array $ctx, array $overrides = []): array
{
    return array_merge([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $ctx['school'],
        'invoice_id' => $ctx['invoice'],
        'provider' => 'paystack',
        'reference' => 'REF-'.Str::random(12),
        'provider_reference' => null,
        'amount_minor' => 500000,
        'amount_currency' => 'NGN',
        'status' => 'pending',
        'paid_at' => null,
        'failure_reason' => null,
        'initiated_by_user_id' => null,
        'payment_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/** Insert raw and return the new id. */
function gtxInsert(array $ctx, array $overrides = []): int
{
    return (int) DB::table(GTX_TABLE)->insertGetId(gtxRow($ctx, $overrides));
}

/** Assert the closure throws a QueryException carrying exactly this MySQL driver code. */
function gtxExpectCode(int $code, Closure $fn): void
{
    try {
        $fn();
        throw new RuntimeException("expected a QueryException carrying {$code}, none thrown");
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe($code);
    }
}

// ── The shape, asserted by NAME, so a later migration that silently drops one fails HERE ──────────
//
// The migration reads its own shape back and refuses to record itself if it is wrong; that protects
// the moment it runs. This protects every moment after — an ALTER on another branch, a table rebuild
// that loses an index, a trigger dropped and not re-created.

it('the table carries the columns, indexes, foreign keys and CHECK the migration claims', function () {
    $indexes = collect(DB::select(
        'SELECT INDEX_NAME AS name, COLUMN_NAME AS col, NON_UNIQUE AS non_unique
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
        [GTX_TABLE],
    ));

    // Column SETS, not just names. A UNIQUE index with the right name and the wrong columns is
    // created just as successfully and enforces a different rule — `(payment_id, school_id)` would
    // let one attempt settle twice, under a name that reads as though it could not.
    $columnsOf = fn (string $name) => $indexes->where('name', $name)->pluck('col')->values()->all();

    expect($columnsOf('finance_gateway_transactions_provider_reference_unique'))->toBe(['provider', 'reference'])
        ->and($columnsOf('finance_gateway_transactions_provider_ref_unique'))->toBe(['provider', 'provider_reference'])
        ->and($columnsOf('finance_gateway_transactions_payment_unique'))->toBe(['payment_id'])
        ->and($columnsOf('finance_gateway_transactions_school_status_index'))->toBe(['school_id', 'status']);

    foreach ([
        'finance_gateway_transactions_provider_reference_unique',
        'finance_gateway_transactions_provider_ref_unique',
        'finance_gateway_transactions_payment_unique',
    ] as $unique) {
        expect((int) $indexes->where('name', $unique)->first()->non_unique)->toBe(0, "[{$unique}] is not UNIQUE");
    }

    $constraints = collect(DB::select(
        'SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [GTX_TABLE],
    ))->pluck('name')->all();

    expect($constraints)->toContain(
        'finance_gateway_transactions_invoice_school_foreign',
        'finance_gateway_transactions_payment_school_foreign',
        'finance_gateway_transactions_amount_currency_shape',
    );

    $triggers = collect(DB::select(
        'SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
        [GTX_TABLE],
    ))->pluck('name')->all();

    expect($triggers)->toContain(
        'finance_gateway_transactions_insert_guard',
        'finance_gateway_transactions_update_guard',
        'finance_gateway_transactions_no_delete',
    );
});

// ── The status domain ─────────────────────────────────────────────────────────────────────────────

it('the status domain admits exactly the four enum values, case-sensitively, on INSERT', function () {
    $ctx = gtxSchool();

    // POSITIVE ARM FIRST, and it is the one that makes the negatives mean something: each of the four
    // is driven from the ENUM, so a case renamed in PHP and not in the trigger reds here rather than
    // drifting silently. This is the pin that ties the two spellings of the domain together.
    foreach (GatewayTransactionStatus::cases() as $case) {
        $id = gtxInsert($ctx, ['status' => $case->value]);
        expect(DB::table(GTX_TABLE)->where('id', $id)->value('status'))->toBe($case->value);
    }

    // A value outside the set.
    gtxExpectCode(1644, fn () => gtxInsert($ctx, ['status' => 'settled']));

    // A CASE VARIANT — the arm that measures COLLATE utf8mb4_bin. Without it the table's
    // utf8mb4_unicode_ci makes this match `success`, the row inserts, and every `status = 'success'`
    // report filter matches it too, so the guard reads green while admitting a value nobody wrote.
    gtxExpectCode(1644, fn () => gtxInsert($ctx, ['status' => 'Success']));
    gtxExpectCode(1644, fn () => gtxInsert($ctx, ['status' => 'SUCCESS']));
});

it('the status domain is enforced on UPDATE too, not only on INSERT', function () {
    $ctx = gtxSchool();
    $id = gtxInsert($ctx);

    // An UPDATE puts a value in a column exactly as an INSERT does. A guard on one door only is the
    // failure this arm exists to refuse.
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update(['status' => 'settled']));
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update(['status' => 'Failed']));
});

// ── Amount and currency ───────────────────────────────────────────────────────────────────────────

it('an attempt for nothing, or for a negative amount, is refused', function () {
    $ctx = gtxSchool();

    gtxExpectCode(1644, fn () => gtxInsert($ctx, ['amount_minor' => 0]));
    gtxExpectCode(1644, fn () => gtxInsert($ctx, ['amount_minor' => -1]));

    // Negative arm: one kobo is a legitimate checkout, so the guard is a floor at zero and not a
    // minimum somebody invented.
    expect(gtxInsert($ctx, ['amount_minor' => 1]))->toBeGreaterThan(0);
});

it('a wrong-case currency is refused by the TRIGGER (1644), not only by the CHECK', function () {
    $ctx = gtxSchool();

    // WHY THE CODE MATTERS HERE. A CHECK violation is 3819 and a trigger SIGNAL is 1644. Production
    // is MySQL 5.7.23, which parses and IGNORES CHECK entirely — so a refusal arriving as 3819 would
    // mean this rule is enforced on the developer's machine and absent on the machine holding real
    // money. Asserting the code is what tells the two apart; asserting "it throws" cannot.
    gtxExpectCode(1644, fn () => gtxInsert($ctx, ['amount_currency' => 'ngn']));
    gtxExpectCode(1644, fn () => gtxInsert($ctx, ['amount_currency' => 'NG']));

    expect(gtxInsert($ctx, ['amount_currency' => 'NGN']))->toBeGreaterThan(0);
});

// ── The idempotency keys ──────────────────────────────────────────────────────────────────────────

it('one attempt per (provider, reference) — and the uniqueness is NOT school-scoped', function () {
    $a = gtxSchool();
    $b = gtxSchool();

    gtxInsert($a, ['reference' => 'REF-COLLIDE']);

    // Same school, same provider, same reference.
    gtxExpectCode(1062, fn () => gtxInsert($a, ['reference' => 'REF-COLLIDE']));

    // ANOTHER SCHOOL, same reference. This is the axis that matters and the one a school-scoped
    // index would get wrong: the reference crosses the wire to a third party, so two schools sharing
    // one would collide at the provider, not here. A `(school_id, provider, reference)` index would
    // pass every other arm in this file and fail exactly this one.
    gtxExpectCode(1062, fn () => gtxInsert($b, ['reference' => 'REF-COLLIDE']));

    // Negative arm: a different provider is a different namespace, so the same string is free there.
    expect(gtxInsert($a, ['provider' => 'flutterwave', 'reference' => 'REF-COLLIDE']))->toBeGreaterThan(0);
});

it('one attempt per (provider, provider_reference) — but many attempts may have none yet', function () {
    $ctx = gtxSchool();

    gtxInsert($ctx, ['provider_reference' => 'PSK-1']);
    gtxExpectCode(1062, fn () => gtxInsert($ctx, ['provider_reference' => 'PSK-1']));

    // THE ARM THAT MATTERS MOST, because getting it wrong breaks the ordinary path rather than an
    // edge: every pending attempt has a NULL provider_reference, and a UNIQUE index that refused a
    // second NULL would let each school run exactly one checkout, ever. MySQL admits many NULLs;
    // this proves the schema relies on that rather than on a hope.
    expect(gtxInsert($ctx))->toBeGreaterThan(0)
        ->and(gtxInsert($ctx))->toBeGreaterThan(0)
        ->and(DB::table(GTX_TABLE)->whereNull('provider_reference')->count())->toBe(2);
});

it('one payment per attempt — the UNIQUE the webhook is to be made idempotent by', function () {
    $ctx = gtxSchool();

    $first = gtxInsert($ctx);
    $second = gtxInsert($ctx);

    DB::table(GTX_TABLE)->where('id', $first)->update([
        'status' => 'success', 'payment_id' => $ctx['payment'], 'paid_at' => now(),
    ]);

    // A second attempt claiming the same payment is refused BY THE DATABASE. Without this index the
    // only thing standing between a duplicate webhook and a duplicate settlement is handler code.
    gtxExpectCode(1062, fn () => DB::table(GTX_TABLE)->where('id', $second)->update([
        'status' => 'success', 'payment_id' => $ctx['payment'], 'paid_at' => now(),
    ]));

    // Negative arm: the unsettled rows all carry NULL and do not collide with each other.
    expect(gtxInsert($ctx))->toBeGreaterThan(0);
});

// ── Isolation: the composite foreign keys ─────────────────────────────────────────────────────────

it('an attempt cannot name another school\'s invoice or another school\'s payment', function () {
    $a = gtxSchool();
    $b = gtxSchool();

    // school A + school B's invoice. Both rows exist and both ids are valid on their own; it is the
    // PAIR that has no referent, which is exactly what a composite (child, school_id) FK is for.
    gtxExpectCode(1452, fn () => gtxInsert($a, ['invoice_id' => $b['invoice']]));

    $id = gtxInsert($a);
    gtxExpectCode(1452, fn () => DB::table(GTX_TABLE)->where('id', $id)->update([
        'status' => 'success', 'payment_id' => $b['payment'], 'paid_at' => now(),
    ]));
});

// ── The update guard ──────────────────────────────────────────────────────────────────────────────

it('identity and money are immutable once the attempt exists', function () {
    $ctx = gtxSchool();
    $other = gtxSchool();
    $id = gtxInsert($ctx);

    foreach ([
        ['amount_minor' => 1],
        ['amount_currency' => 'USD'],
        ['reference' => 'REF-REWRITTEN'],
        ['provider' => 'flutterwave'],
        ['uuid' => (string) Str::orderedUuid()],
        ['school_id' => $other['school'], 'invoice_id' => $other['invoice']],
    ] as $mutation) {
        gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update($mutation));
    }

    // THE POSITIVE ARM, and without it this test would pass just as well against a guard that froze
    // the whole row — which would break the only write path the table has. What the provider tells
    // us moves; what we told the provider does not.
    DB::table(GTX_TABLE)->where('id', $id)->update([
        'status' => 'failed',
        'provider_reference' => 'PSK-9',
        'failure_reason' => 'Insufficient funds',
    ]);

    expect(DB::table(GTX_TABLE)->where('id', $id)->value('provider_reference'))->toBe('PSK-9');
});

it('a settled attempt is final — the replayed webhook has nothing it can move', function () {
    $ctx = gtxSchool();
    $id = gtxInsert($ctx);

    DB::table(GTX_TABLE)->where('id', $id)->update([
        'status' => 'success', 'payment_id' => $ctx['payment'], 'paid_at' => now(),
    ]);

    // EVEN A HARMLESS-LOOKING UPDATE. Terminality is the property, not "the money columns are
    // frozen": a second delivery that could set failure_reason could also have set status.
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update(['failure_reason' => 'x']));
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update(['status' => 'failed']));
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update(['payment_id' => null]));
});

it('status may not return to pending, but a failed attempt may still succeed later', function () {
    $ctx = gtxSchool();
    $id = gtxInsert($ctx);

    DB::table(GTX_TABLE)->where('id', $id)->update(['status' => 'failed']);
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update(['status' => 'pending']));

    // THE ARM THAT KEEPS THE GUARD FROM BEING "NOTHING MOVES". A payer whose card declines may
    // complete the same reference by transfer minutes later and the provider then reports success on
    // a reference it previously reported failed. Freezing `failed` would leave that money visible
    // only on a bank statement — the discrepancy the reconciliation report exists to hunt.
    DB::table(GTX_TABLE)->where('id', $id)->update([
        'status' => 'success', 'payment_id' => $ctx['payment'], 'paid_at' => now(),
    ]);

    expect(DB::table(GTX_TABLE)->where('id', $id)->value('status'))->toBe('success');
});

// ── Retention ─────────────────────────────────────────────────────────────────────────────────────

it('an attempt is never deleted, settled or not', function () {
    $ctx = gtxSchool();
    $pending = gtxInsert($ctx);
    $abandoned = gtxInsert($ctx, ['status' => 'abandoned']);

    // The abandoned and failed rows are the entire input to the discrepancy report; deleting them is
    // deleting the evidence of the thing being reconciled.
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $pending)->delete());
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $abandoned)->delete());
});
