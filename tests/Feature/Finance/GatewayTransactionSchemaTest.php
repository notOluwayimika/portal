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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const GTX_TABLE = 'finance_gateway_transactions';

const GTX_EVENTS = 'finance_gateway_transaction_events';

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
        'fee_minor' => null,
        'fee_currency' => null,
        'settlement_reference' => null,
        'settled_at' => null,
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
        ->and($columnsOf('finance_gateway_transactions_school_status_index'))->toBe(['school_id', 'status'])
        ->and($columnsOf('finance_gateway_transactions_id_school_unique'))->toBe(['id', 'school_id']);

    // Boundary §5 / §8.2 — the settlement facts. Asserted by NAME here, not only inside the
    // migration, so an ALTER on a later branch that drops one reds in the suite rather than at a
    // reconciliation that finds the column empty for every historical row.
    foreach (['fee_minor', 'fee_currency', 'settlement_reference', 'settled_at'] as $settlement) {
        expect(Schema::hasColumn(GTX_TABLE, $settlement))->toBeTrue("[{$settlement}] is missing");
    }

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
        'finance_gateway_transactions_fee_currency_shape',
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

    $eventTriggers = collect(DB::select(
        'SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
        [GTX_EVENTS],
    ))->pluck('name')->all();

    expect($eventTriggers)->toContain(
        'finance_gateway_transaction_events_insert_guard',
        'finance_gateway_transaction_events_update_guard',
        'finance_gateway_transaction_events_no_delete',
    )
        // The bare append-only names are GONE, not lingering alongside the guards that replaced
        // them — the credit-note relocation idiom. Two objects carrying two halves of one rule is
        // how the halves come to disagree.
        ->and($eventTriggers)->not->toContain('finance_gateway_transaction_events_no_update')
        ->and($eventTriggers)->not->toContain('finance_gateway_transaction_events_source_guard');

    expect(Schema::hasColumn(GTX_EVENTS, 'redacted_at'))->toBeTrue();

    // The payload is JSON, not TEXT. A TEXT column takes a truncated body silently; JSON refuses it
    // at the write, which is the difference between evidence and a string that looks like evidence.
    expect(DB::scalar(
        'SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [GTX_EVENTS, 'payload'],
    ))->toBe('json');
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

it('a settled attempt is final FOR STATUS — the replayed webhook cannot re-settle it', function () {
    $ctx = gtxSchool();
    $id = gtxInsert($ctx);

    DB::table(GTX_TABLE)->where('id', $id)->update([
        'status' => 'success', 'payment_id' => $ctx['payment'], 'paid_at' => now(),
    ]);

    // The status cannot move off success, in any direction. This is the arm that makes a duplicate
    // delivery harmless: it finds a status it cannot change.
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update(['status' => 'failed']));
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update(['status' => 'abandoned']));

    // And the facts already reported cannot be rewritten — including back to NULL, which is the
    // shape a badly-written "reset and retry" would take.
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update(['payment_id' => null]));
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update(['paid_at' => now()->addDay()]));
});

it('SETTLEMENT IS WRITABLE AFTER SUCCESS — the arm the first version of this guard would have failed', function () {
    // WHY THIS TEST EXISTS. The guard originally froze the whole row at `success`. Every other arm in
    // this file passed, because settlement had no writer yet — and boundary §5's three settlement
    // columns would have been physically unwritable on a live table, discovered at the first payout.
    // Settlement happens AFTER success; that is what settlement is.
    $ctx = gtxSchool();
    $id = gtxInsert($ctx);

    DB::table(GTX_TABLE)->where('id', $id)->update([
        'status' => 'success', 'payment_id' => $ctx['payment'], 'paid_at' => now(),
    ]);

    DB::table(GTX_TABLE)->where('id', $id)->update([
        'fee_minor' => 7500,
        'fee_currency' => 'NGN',
        'settlement_reference' => 'PAYOUT-2026-08-31',
        'settled_at' => now()->addDays(3),
    ]);

    $row = DB::table(GTX_TABLE)->where('id', $id)->first();

    expect((int) $row->fee_minor)->toBe(7500)
        ->and($row->fee_currency)->toBe('NGN')
        ->and($row->settlement_reference)->toBe('PAYOUT-2026-08-31')
        ->and($row->settled_at)->not->toBeNull();
});

it('a fact reported by the provider is WRITE-ONCE — NULL to a value, never a value to another', function () {
    $ctx = gtxSchool();
    $id = gtxInsert($ctx);

    // Each of these is filled in once, legitimately.
    DB::table(GTX_TABLE)->where('id', $id)->update([
        'provider_reference' => 'PSK-1', 'failure_reason' => 'Declined', 'status' => 'failed',
    ]);
    DB::table(GTX_TABLE)->where('id', $id)->update([
        'fee_minor' => 100, 'fee_currency' => 'NGN',
        'settlement_reference' => 'PAYOUT-1', 'settled_at' => now(),
    ]);

    // And none of them may be rewritten afterwards. THIS IS WHAT MAKES A NULL MEAN SOMETHING: a
    // reconciliation reading `settlement_reference` must be able to know it is what the provider
    // said, not what the most recent delivery happened to say last.
    foreach ([
        ['provider_reference' => 'PSK-2'],
        ['failure_reason' => 'Something else'],
        ['fee_minor' => 200],
        ['fee_currency' => 'USD'],
        ['settlement_reference' => 'PAYOUT-2'],
        ['settled_at' => now()->addYear()],
        // Including erasure — a rewrite to NULL is still a rewrite, and `NULL <> NULL` being NULL
        // rather than FALSE is exactly why these comparisons are `<=>` and not `<>`. With a plain
        // `<>` this arm would pass silently while the rule did nothing.
        ['settlement_reference' => null],
        ['fee_minor' => null, 'fee_currency' => null],
    ] as $rewrite) {
        gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update($rewrite));
    }
});

it('payment_id is a one-way door — the predicate step 4\'s compare-and-swap rests on', function () {
    // ISOLATED FROM THE SETTLED-ROW TEST ON PURPOSE. The other arm that touches payment_id sits on a
    // row already in `success`, where the terminal-status clause is also in play; this one runs on a
    // row that is NOT settled, so the write-once clause is the only thing that can produce the
    // refusal. A guard is proven where it acts alone.
    $ctx = gtxSchool();
    $id = gtxInsert($ctx);

    DB::table(GTX_TABLE)->where('id', $id)->update(['payment_id' => $ctx['payment']]);

    // UNIQUE (payment_id) stops two ROWS naming one payment. It says nothing about ONE row going
    // value → NULL → a different value — and step 4's idempotency is a compare-and-swap on
    // `payment_id IS NULL`, so if that predicate can be reopened a replayed delivery unlinks,
    // relinks and settles twice. This is the arm that closes it.
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)->update(['payment_id' => null]));

    $other = gtxSchool();
    gtxExpectCode(1644, fn () => DB::table(GTX_TABLE)->where('id', $id)
        ->update(['payment_id' => $other['payment']]));

    expect((int) DB::table(GTX_TABLE)->where('id', $id)->value('payment_id'))->toBe($ctx['payment']);
});

it('the fee is both halves or neither, never negative, and always in the amount\'s currency', function () {
    $ctx = gtxSchool();

    // ADR 0038 — the pair IS the value. Half a money value is not a money value.
    gtxExpectCode(1644, fn () => gtxInsert($ctx, ['fee_minor' => 100]));
    gtxExpectCode(1644, fn () => gtxInsert($ctx, ['fee_currency' => 'NGN']));

    // A fee in a different currency cannot be subtracted from the amount it was taken out of —
    // Money::minus throws on a mismatch, so this must be refused at the write and not at the screen.
    gtxExpectCode(1644, fn () => gtxInsert($ctx, ['fee_minor' => 100, 'fee_currency' => 'USD']));

    gtxExpectCode(1644, fn () => gtxInsert($ctx, ['fee_minor' => -1, 'fee_currency' => 'NGN']));

    // NEGATIVE ARMS. Note zero is legitimate here and is NOT legitimate for amount_minor: nobody
    // checks out for nothing, but plenty of transactions settle at no charge.
    expect(gtxInsert($ctx, ['fee_minor' => 0, 'fee_currency' => 'NGN']))->toBeGreaterThan(0)
        ->and(gtxInsert($ctx, ['fee_minor' => 2500, 'fee_currency' => 'NGN']))->toBeGreaterThan(0)
        ->and(gtxInsert($ctx))->toBeGreaterThan(0);
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

// ── The raw deliveries (boundary §5 / §8.2) ───────────────────────────────────────────────────────

/** @param array{school:int, invoice:int, payment:int} $ctx */
function gtxEvent(array $ctx, int $transactionId, array $overrides = []): int
{
    return (int) DB::table(GTX_EVENTS)->insertGetId(array_merge([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $ctx['school'],
        'gateway_transaction_id' => $transactionId,
        'source' => 'webhook',
        'event' => 'charge.success',
        'payload' => json_encode(['data' => ['status' => 'success', 'customer' => ['email' => 'payer@example.test']]]),
        'redacted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('EVERY delivery is kept — the reason this is a child table and not a payload column', function () {
    $ctx = gtxSchool();
    $id = gtxInsert($ctx);

    // Three messages about one transaction: the webhook, the verify response when the payer returns,
    // and the settlement event days later. A single `payload` column holds the last of these and
    // destroys the other two — the exact loss §8.2 exists to prevent, arriving through the mechanism
    // meant to prevent it. This arm is what makes the column shape a tested decision.
    gtxEvent($ctx, $id, ['source' => 'webhook', 'event' => 'charge.success']);
    gtxEvent($ctx, $id, ['source' => 'verify', 'event' => null]);
    gtxEvent($ctx, $id, ['source' => 'webhook', 'event' => 'transfer.success']);

    expect(DB::table(GTX_EVENTS)->where('gateway_transaction_id', $id)->count())->toBe(3)
        ->and(DB::table(GTX_EVENTS)->whereNull('event')->count())->toBe(1);
});

it('a stored delivery can never be edited or deleted — it is evidence, not a working note', function () {
    $ctx = gtxSchool();
    $id = gtxInsert($ctx);
    $event = gtxEvent($ctx, $id);

    gtxExpectCode(1644, fn () => DB::table(GTX_EVENTS)->where('id', $event)
        ->update(['payload' => json_encode(['data' => ['status' => 'failed']])]));
    gtxExpectCode(1644, fn () => DB::table(GTX_EVENTS)->where('id', $event)->update(['event' => 'rewritten']));
    gtxExpectCode(1644, fn () => DB::table(GTX_EVENTS)->where('id', $event)->delete());
});

// ── Retention: the one door through the append-only guard ─────────────────────────────────────────
//
// The payload carries payer PII and this table cannot be purged, so the ability to redact has to
// exist before there is anything to purge — retrofitting it later means dropping guards on a live
// table holding that data. These arms are what make "exactly one door" a tested claim rather than a
// sentence in a docblock.

it('a payload may be redacted EXACTLY ONCE, and a raw row cannot be edited any other way', function () {
    $ctx = gtxSchool();
    $id = gtxInsert($ctx);
    $event = gtxEvent($ctx, $id);

    $redacted = json_encode(['data' => ['status' => 'success', 'customer' => ['email' => null]]]);

    // The redaction — the ONE update this table admits.
    DB::table(GTX_EVENTS)->where('id', $event)->update([
        'payload' => $redacted, 'redacted_at' => now(), 'updated_at' => now(),
    ]);

    $row = DB::table(GTX_EVENTS)->where('id', $event)->first();
    expect($row->redacted_at)->not->toBeNull()
        ->and(json_decode($row->payload, true)['data']['customer']['email'])->toBeNull();

    // A SECOND redaction is refused, and refused for BEING a second one rather than for whatever
    // column it touched — which is why the already-redacted arm is first in the guard.
    gtxExpectCode(1644, fn () => DB::table(GTX_EVENTS)->where('id', $event)->update([
        'payload' => json_encode(['data' => []]), 'redacted_at' => now(),
    ]));

    // And the row is otherwise still frozen: a redacted row is not an editable row.
    gtxExpectCode(1644, fn () => DB::table(GTX_EVENTS)->where('id', $event)->update(['event' => 'rewritten']));
});

it('a redaction may change the payload and nothing else, and no row is born redacted', function () {
    $a = gtxSchool();
    $b = gtxSchool();
    $id = gtxInsert($a);
    $event = gtxEvent($a, $id);

    // Redaction is not a licence to rewrite the delivery's identity. Without these arms, `redacted_at`
    // would be a general-purpose unlock on an append-only table.
    foreach ([
        ['event' => 'transfer.success'],
        ['source' => 'verify'],
        ['school_id' => $b['school']],
        ['created_at' => now()->subYear()],
    ] as $smuggled) {
        gtxExpectCode(1644, fn () => DB::table(GTX_EVENTS)->where('id', $event)
            ->update(array_merge(['redacted_at' => now()], $smuggled)));
    }

    // A row cannot arrive already redacted — that would be a write-time redaction wearing the
    // retention path's clothes, and it would make `redacted_at` stop meaning "this was purged".
    gtxExpectCode(1644, fn () => gtxEvent($a, $id, ['redacted_at' => now()]));
});

it('a delivery names a known source and cannot be filed against another school\'s transaction', function () {
    $a = gtxSchool();
    $b = gtxSchool();
    $inA = gtxInsert($a);

    gtxExpectCode(1644, fn () => gtxEvent($a, $inA, ['source' => 'guess']));
    gtxExpectCode(1644, fn () => gtxEvent($a, $inA, ['source' => 'Webhook'])); // the collation arm

    // School B's row pointing at school A's transaction. Both ids are valid alone; the PAIR has no
    // referent, which is what the composite FK is for.
    gtxExpectCode(1452, fn () => gtxEvent($b, $inA));
});
