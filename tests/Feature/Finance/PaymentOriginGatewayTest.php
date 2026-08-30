<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE THIRD PAYMENT ORIGIN — `gateway` — AND THE FOUR REFUSALS THAT BOUND IT.
 *
 * `2026_08_25_100000_finance_payment_origin_admits_gateway.php` REPLACES the trigger pair
 * `finance_payments_origin_pairing_bi` / `_bu` with a three-arm predicate. There is no CHECK behind
 * any of this: both payment CHECKs were dropped by `2026_08_17_100000`, because production is MySQL
 * 5.7.23 and MySQL enforces CHECK only from 8.0.16. Every refusal below is therefore a
 * `SIGNAL SQLSTATE '45000'` from a trigger — driver code **1644**, not 3819 — and every one of them is
 * asserted on the DATABASE error rather than on a PHP exception type this repository controls.
 *
 * EVERY REFUSAL ARM INSERTS RAW, and that is the point of the file rather than a convenience.
 * `RecordPayment` cannot be driven into three of the four violations — it takes a non-null
 * `int $bankAccountId`, so "gateway with no account" and "portal with no account" are not expressible
 * through it, and it never writes a case variant. A test that could only reach these through the
 * Action would be measuring the Action's parameter types and calling it a database guard.
 *
 * WHAT EACH ARM IS FOR:
 *
 *   1. gateway + a bank account LANDS, and the row is receiptable. The negative control: the widened
 *      predicate is not simply refusing the new value, and `isReceiptable()`'s allowlist was actually
 *      extended rather than merely documented.
 *   2. gateway + NO bank account is REFUSED BY THE DATABASE. The new arm mirrors `portal`, not
 *      `migrated` — the settlement account is named. Had the arm been written as `IS NULL`, or as a
 *      bare `origin = 'gateway'` with no bank-account clause, this is what would catch it.
 *   3. portal + NULL is still refused, and 4. migrated + an account is still refused. A widening
 *      migration that rewrites a predicate can weaken the arms it was not about; these two are the
 *      no-regression pair, and they are here rather than left to `CheckConstraintsAsTriggersTest`
 *      because that file pins the pair's SHAPE and only one of its arms.
 *   5. 'Gateway' with a capital G is REFUSED. This measures the `COLLATE utf8mb4_bin` clause ON THE
 *      NEW ARM specifically. It is the quietest possible mistake in this migration: drop the clause
 *      from one arm and the other two keep biting, so the guard still looks alive while
 *      `origin = 'gateway'` filters silently miss rows nobody can see.
 *   6. The Action writes `external_reference` and leaves `received_by_user_id` NULL.
 *   7. Every existing caller still writes 'portal'.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: Student, 3: callable(int):Invoice} */
function gatewaySetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id]);

    $makeInvoice = fn (int $kobo) => app(GenerateInvoice::class)->handle(
        StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ])->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo), bankAccountId: testBankAccountId())],
        InvoiceKind::Scheduled,
    );

    return [$school, $admin, $student, $makeInvoice];
}

/**
 * Raw-insert a `finance_payments` row with an EXPLICIT bank-account state — no Action, no
 * FormRequest, no model, no cast. The pairing is passed in rather than derived from the origin,
 * because the whole point of four of these arms is to write a pairing the rule forbids.
 */
function gwInsert(int $schoolId, int $studentId, int $reference, string $origin, ?int $bankAccountId): void
{
    DB::table('finance_payments')->insert([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $schoolId,
        'student_id' => $studentId,
        'reference' => $reference,
        'amount_minor' => 5000,
        'amount_currency' => 'NGN',
        'received_at' => SchoolDay::today(),
        'bank_account_id' => $bankAccountId,
        'payer_name' => 'Raw',
        'method' => 'manual',
        'origin' => $origin,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** The message the three-arm predicate signals. 108 characters — MySQL truncates MESSAGE_TEXT at 128. */
const GW_PAIRING_MESSAGE = 'finance_payments: a portal or gateway payment needs a bank account and a migrated payment must not have one.';

/**
 * Assert that a closure is refused BY THE DATABASE, by driver code AND by message.
 *
 * BOTH, not either. The code alone (1644) is shared by every SIGNAL '45000' on this table — including
 * `finance_payments_no_update`, and including any fixture mistake that happens to trip a different
 * trigger — so a code-only assertion passes for the wrong reason. The message alone would pass if the
 * code were 3819, i.e. if someone re-added the CHECK this family exists to have removed.
 */
function gwExpectPairingRefusal(Closure $write): void
{
    try {
        $write();
        throw new RuntimeException('expected the origin pairing trigger to refuse this write');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1644)
            ->and((string) ($e->errorInfo[2] ?? ''))->toContain(GW_PAIRING_MESSAGE);
    }
}

// ── 1. The new arm ACCEPTS, and the row is receiptable ────────────────────────────────────────────

it('gateway — a payment WITH a bank account is written, and it is receiptable', function () {
    [$school, , $student] = gatewaySetup();

    ActiveSchool::runFor($school->id, function () use ($school, $student) {
        gwInsert($school->id, $student->id, 1, Payment::ORIGIN_GATEWAY, testBankAccountId($school->id));

        $row = DB::table('finance_payments')->where('reference', 1)->first();

        expect($row)->not->toBeNull()
            ->and($row->origin)->toBe('gateway')
            ->and($row->bank_account_id)->not->toBeNull();

        // The MODEL half of the same decision. isReceiptable() is an ALLOWLIST, so extending the
        // database predicate does not extend it — this is what proves the constant was added to the
        // list rather than only to the docblock.
        //
        // WATCHED RED: revert isReceiptable() to `$this->origin === self::ORIGIN_PORTAL` and both of
        // these fail — receiptable false, and the refusal reason becomes the UNKNOWN_ORIGIN sentence.
        $payment = Payment::query()->where('reference', 1)->firstOrFail();

        expect($payment->isReceiptable())->toBeTrue()
            ->and($payment->receiptRefusalReason())->toBeNull();
    });
});

// ── 2. The new arm REFUSES a missing settlement account, at the DATABASE ──────────────────────────

it('gateway — a payment WITHOUT a bank account is refused by the DATABASE, 45000/1644', function () {
    [$school, , $student] = gatewaySetup();

    // Nothing in this arm constructs RecordPayment. The Action takes `int $bankAccountId` and cannot
    // express this row at all, so a test that went through it would be measuring a PHP type hint.
    gwExpectPairingRefusal(fn () => gwInsert($school->id, $student->id, 2, Payment::ORIGIN_GATEWAY, null));

    expect(DB::table('finance_payments')->count())->toBe(0);
});

// ── 3 & 4. The two pre-existing arms are not weakened by the widening ─────────────────────────────

it('no regression — portal with a NULL bank account is still refused', function () {
    [$school, , $student] = gatewaySetup();

    gwExpectPairingRefusal(fn () => gwInsert($school->id, $student->id, 3, Payment::ORIGIN_PORTAL, null));

    expect(DB::table('finance_payments')->count())->toBe(0);
});

it('no regression — migrated WITH a bank account is still refused', function () {
    [$school, , $student] = gatewaySetup();

    ActiveSchool::runFor($school->id, function () use ($school, $student) {
        // A migrated row naming an account would assert a fact that is false: WCBS collected that
        // money before the cutover and it never entered one of our accounts.
        gwExpectPairingRefusal(fn () => gwInsert(
            $school->id,
            $student->id,
            Payment::MIGRATED_REFERENCE_FLOOR + 1,
            Payment::ORIGIN_MIGRATED,
            testBankAccountId($school->id),
        ));
    });

    expect(DB::table('finance_payments')->where('origin', 'migrated')->count())->toBe(0);
});

// ── 5. COLLATE utf8mb4_bin, on the NEW arm ────────────────────────────────────────────────────────

it("COLLATE — origin 'Gateway' with a capital G is refused, so the binary collation took on the new arm", function () {
    [$school, , $student] = gatewaySetup();

    ActiveSchool::runFor($school->id, function () use ($school, $student) {
        // Under the table's default utf8mb4_unicode_ci this row INSERTS, and every
        // `origin = 'gateway'` report filter matches it — a guard reading green while admitting a
        // value nobody wrote a filter for. Paired with a bank account deliberately: the ONLY reason
        // this row is refused is the spelling.
        //
        // WATCHED RED: drop `COLLATE utf8mb4_bin` from the gateway arm alone. The other two arms keep
        // biting, every other test in this file stays green, and this one fails — which is the whole
        // reason it exists as its own arm rather than as a line in arm 2.
        gwExpectPairingRefusal(fn () => gwInsert($school->id, $student->id, 4, 'Gateway', testBankAccountId($school->id)));

        // …and the same three arms in the same statement do not refuse the correctly-spelled value,
        // so this is a case test and not an "everything is refused" artefact.
        gwInsert($school->id, $student->id, 5, Payment::ORIGIN_GATEWAY, testBankAccountId($school->id));
    });

    expect(DB::table('finance_payments')->pluck('origin')->all())->toBe(['gateway']);
});

// ── 6. The Action writes the provenance it was given ──────────────────────────────────────────────

it('RecordPayment — a gateway payment records external_reference and a NULL received_by_user_id', function () {
    [$school, , , $makeInvoice] = gatewaySetup();

    $payment = ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)->handle(
        $makeInvoice(10000),
        Money::fromKobo(10000),
        'Paystack payer',
        // NO ACTOR. A gateway payment has no receiver: the provider collected it and settled it, and
        // no member of staff took it. A synthetic "system" user is deliberately not invented — the
        // column would then name a person who did not act, on an append-only row.
        null,
        SchoolDay::today(),
        testBankAccountId($school->id),
        null,
        Payment::ORIGIN_GATEWAY,
        'PSK_REF_9931',
    ));

    // Read from the TABLE, not from the returned model: what matters is what landed, and the model
    // would answer from memory for `origin` even if the column had defaulted underneath it.
    $row = DB::table('finance_payments')->where('id', $payment->id)->first();

    expect($row->origin)->toBe('gateway')
        // The EXISTING column (2026_08_07_110000), not a new one. Its meaning is "the source system's
        // identifier for this money", which is exactly what a Paystack reference is.
        ->and($row->external_reference)->toBe('PSK_REF_9931')
        // NULL says "nobody received it", which is the truth. Not 0, and not a system user id.
        ->and($row->received_by_user_id)->toBeNull()
        ->and($row->bank_account_id)->not->toBeNull();

    // The rest of the Action is untouched by this change and must stay so: the money still settles.
    expect($payment->allocations)->toHaveCount(1)
        ->and($payment->allocations->first()->amount->toKobo())->toBe(10000);

    // And the row a gateway payment produces is receiptable, end to end rather than on a bare model.
    expect(Payment::query()->whereKey($payment->id)->firstOrFail()->isReceiptable())->toBeTrue();
});

// ── 7. Every existing caller is unchanged ─────────────────────────────────────────────────────────

it("default — RecordPayment called the way every existing caller calls it still writes origin 'portal'", function () {
    [$school, $admin, , $makeInvoice] = gatewaySetup();

    // The bursar's front door, arity unchanged: no origin argument and no external reference. The
    // parameter default is what carries it now, where the column DEFAULT used to.
    //
    // WATCHED RED: change the $origin parameter's default to Payment::ORIGIN_GATEWAY. This fails, and
    // so does PaymentProvenanceTest's own default arm.
    $payment = ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)->handle(
        $makeInvoice(10000),
        Money::fromKobo(10000),
        'Counter payer',
        $admin,
        SchoolDay::today(),
        testBankAccountId($school->id),
    ));

    $row = DB::table('finance_payments')->where('id', $payment->id)->first();

    expect($row->origin)->toBe('portal')
        ->and($row->external_reference)->toBeNull()
        // An actor WAS passed here, so the nullable parameter did not quietly stop recording one.
        ->and((int) $row->received_by_user_id)->toBe($admin->id);
});
