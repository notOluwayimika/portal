<?php

use App\Finance\Actions\ApproveCreditNote;
use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\Actions\SubmitCreditNote;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bite-proof for finance:audit-ledger-coherence (ADR 0047). A detector that finds nothing is
 * indistinguishable from a detector that does not work — so EVERY assertion (I1–I7) gets a planted
 * incoherence, and each test asserts the command not only FAILS but reports EXACTLY that assertion's
 * code and no other (isolation, via lcCodes). The clean baseline exits SUCCESS.
 *
 * The append-only triggers shape the plants: INSERT into the ledger is permitted, UPDATE/DELETE are
 * refused — so every plant is a raw INSERT of a ledger row, or a direct UPDATE of a document's
 * (mutable) status, never a ledger edit. No ALTER is involved, so the usual RefreshDatabase
 * schema-heal false-green does not apply here: we plant DATA, which the transaction rolls back
 * cleanly between tests.
 *
 * The watched-red discipline is per-assertion and lives OUTSIDE this file: disable exactly one
 * check method in AuditLedgerCoherence, run this suite, and confirm only that assertion's test(s)
 * go red. Recorded in the slice's report, not asserted here.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: User, 3: Student} maker ≠ checker (the DB CHECK needs it). */
function lcSetup(): array
{
    $school = School::factory()->create();
    $maker = User::factory()->create(['school_id' => $school->id]);
    $checker = User::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);

    return [$school, $maker, $checker, $student];
}

/** A real, coherent issued invoice (GenerateInvoice posts exactly one charge = total). */
function lcInvoice(School $school, Student $student, int $kobo = 10000): Invoice
{
    return ActiveSchool::runFor($school->id, function () use ($school, $student, $kobo) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        return app(GenerateInvoice::class)->handle(
            $enrollment->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo))],
        );
    });
}

/** Void an invoice the RIGHT way: submit (maker) → approve (checker) posts exactly one reversal. */
function lcVoid(School $school, Invoice $invoice, User $maker, User $checker): void
{
    ActiveSchool::runFor($school->id, function () use ($invoice, $maker, $checker) {
        $request = app(SubmitVoidRequest::class)->handle($invoice, 'entered in error', $maker);
        app(ApproveVoidRequest::class)->handle($request, $checker);
    });
}

function lcApprovedCredit(School $school, Invoice $invoice, int $kobo, User $maker, User $checker): CreditNote
{
    return ActiveSchool::runFor($school->id, function () use ($invoice, $kobo, $maker, $checker) {
        $cn = app(SubmitCreditNote::class)->handle($invoice, Money::fromKobo($kobo), CreditNoteKind::CreditNote, null, $maker);

        return app(ApproveCreditNote::class)->handle($cn, $checker);
    });
}

function lcSubmittedCredit(School $school, Invoice $invoice, int $kobo, User $maker): CreditNote
{
    return ActiveSchool::runFor($school->id, fn () => app(SubmitCreditNote::class)
        ->handle($invoice, Money::fromKobo($kobo), CreditNoteKind::CreditNote, null, $maker));
}

function lcPayment(School $school, Invoice $invoice, int $kobo, User $actor): Payment
{
    return ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)
        ->handle($invoice, Money::fromKobo($kobo), 'Payer', $actor));
}

/** Raw-insert a ledger row, bypassing SubledgerPoster (INSERT is the one write the triggers allow). */
function lcInsertLedger(int $schoolId, int $studentId, string $type, int $amountMinor, string $sourceType, int $sourceId, string $currency = 'NGN'): void
{
    DB::table('finance_ledger_transactions')->insert([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $schoolId,
        'student_id' => $studentId,
        'type' => $type,
        'amount_minor' => $amountMinor,
        'amount_currency' => $currency,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'narration' => 'planted incoherence',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function lcRun(): int
{
    return Artisan::call('finance:audit-ledger-coherence');
}

/** The distinct assertion codes present in the last command output — the isolation lens. */
function lcCodes(): array
{
    preg_match_all('/\[(I\d)\]/', Artisan::output(), $m);
    sort($m[1]);

    return array_values(array_unique($m[1]));
}

// ── The negative: a coherent fixture with all four movement kinds exits SUCCESS ─────

it('a CLEAN fixture (charge + payment + credit note + reversal) exits SUCCESS', function () {
    [$school, $maker, $checker, $student] = lcSetup();

    $paid = lcInvoice($school, $student, 10000);
    lcPayment($school, $paid, 4000, $maker);                 // a payment row
    lcApprovedCredit($school, $paid, 3000, $maker, $checker); // a credit_note row

    $voided = lcInvoice($school, $student, 5000);
    lcVoid($school, $voided, $maker, $checker);               // charge + reversal

    expect(lcRun())->toBe(0)
        ->and(Artisan::output())->toContain('no incoherence');
});

// ── I1 — type vocabulary ────────────────────────────────────────────────────────

it('I1 — a ledger row with an unknown `type` is caught (and nothing else fires)', function () {
    [$school, , , $student] = lcSetup();
    $invoice = lcInvoice($school, $student, 10000);

    // Valid source + currency, only the type is wrong ('CHARGE' ≠ 'charge').
    lcInsertLedger($school->id, $student->id, 'CHARGE', 10000, 'invoice', $invoice->id);

    expect(lcRun())->toBe(1)
        ->and(lcCodes())->toBe(['I1']);
});

// ── I2 — source integrity ────────────────────────────────────────────────────────

it('I2 — an unknown source_type is caught', function () {
    [$school, , , $student] = lcSetup();
    $invoice = lcInvoice($school, $student, 10000);

    lcInsertLedger($school->id, $student->id, 'charge', 10000, 'invoyce', $invoice->id);

    expect(lcRun())->toBe(1)
        ->and(lcCodes())->toBe(['I2']);
});

it('I2 — a ledger row pointing at a non-existent document (dangling source) is caught', function () {
    [$school, , , $student] = lcSetup();
    lcInvoice($school, $student, 10000); // a coherent invoice so only the dangling row is wrong

    lcInsertLedger($school->id, $student->id, 'charge', 10000, 'invoice', 999999);

    expect(lcRun())->toBe(1)
        ->and(lcCodes())->toBe(['I2']);
});

// ── I3 — an issued invoice has NO reversal ───────────────────────────────────────

it('I3 — a reversal against an ISSUED invoice is caught', function () {
    [$school, , , $student] = lcSetup();
    $invoice = lcInvoice($school, $student, 10000); // stays issued

    lcInsertLedger($school->id, $student->id, 'reversal', -10000, 'invoice', $invoice->id);

    expect(lcRun())->toBe(1)
        ->and(lcCodes())->toBe(['I3']);
});

// ── I4 — a void invoice has EXACTLY ONE reversal = −Σcharges ──────────────────────

it('I4(i) — a void invoice with NO reversal (status flipped directly, bypassing the Action) is caught', function () {
    [$school, , , $student] = lcSetup();
    $invoice = lcInvoice($school, $student, 10000);

    // Flip to void by raw UPDATE — status is mutable; no reversal posts.
    DB::table('finance_invoices')->where('id', $invoice->id)->update([
        'status' => 'void', 'cancelled_at' => now(), 'updated_at' => now(),
    ]);

    expect(lcRun())->toBe(1)
        ->and(lcCodes())->toBe(['I4']);
});

it('I4(ii) — a properly-voided invoice with a SECOND reversal planted is caught', function () {
    [$school, $maker, $checker, $student] = lcSetup();
    $invoice = lcInvoice($school, $student, 10000);
    lcVoid($school, $invoice, $maker, $checker); // one legitimate reversal

    lcInsertLedger($school->id, $student->id, 'reversal', -10000, 'invoice', $invoice->id); // the extra

    expect(lcRun())->toBe(1)
        ->and(lcCodes())->toBe(['I4']);
});

it('I4(iii) — a reversal whose amount does NOT negate the charge is caught, and it ALSO drifts the balance (reconcile fires too)', function () {
    [$school, , , $student] = lcSetup();
    $invoice = lcInvoice($school, $student, 10000);

    // Wrong-amount reversal, then flip to void — one reversal, but −10500 ≠ −10000.
    lcInsertLedger($school->id, $student->id, 'reversal', -10500, 'invoice', $invoice->id);
    DB::table('finance_invoices')->where('id', $invoice->id)->update([
        'status' => 'void', 'cancelled_at' => now(), 'updated_at' => now(),
    ]);

    expect(lcRun())->toBe(1)
        ->and(lcCodes())->toBe(['I4']);

    // The planted ledger row is not mirrored in balance_minor, so the INDEPENDENT drift detector
    // fires too — both detectors flagging a genuinely double-wrong state is correct, not redundant.
    expect(Artisan::call('finance:reconcile-accounts'))->toBe(1);
});

// ── I5 — credit-note posting matches credit-note status ──────────────────────────

it('I5 — an APPROVED credit note with no posting (status flipped directly) is caught', function () {
    [$school, $maker, , $student] = lcSetup();
    $invoice = lcInvoice($school, $student, 10000);
    $cn = lcSubmittedCredit($school, $invoice, 3000, $maker); // submitted → no posting

    // Flip to approved by raw UPDATE, bypassing ApproveCreditNote — so no compensating credit posts.
    DB::table('finance_credit_notes')->where('id', $cn->id)->update([
        'status' => 'approved', 'updated_at' => now(),
    ]);

    expect(lcRun())->toBe(1)
        ->and(lcCodes())->toBe(['I5']);
});

it('I5 — a posting sourced to a REJECTED/submitted credit note is caught', function () {
    [$school, $maker, , $student] = lcSetup();
    $invoice = lcInvoice($school, $student, 10000);
    $cn = lcSubmittedCredit($school, $invoice, 3000, $maker); // submitted → must have no posting

    lcInsertLedger($school->id, $student->id, 'credit_note', -3000, 'credit_note', $cn->id);

    expect(lcRun())->toBe(1)
        ->and(lcCodes())->toBe(['I5']);
});

// ── I6 — exactly one charge = total ──────────────────────────────────────────────

it('I6 — a second charge row against an invoice is caught', function () {
    [$school, , , $student] = lcSetup();
    $invoice = lcInvoice($school, $student, 10000);

    lcInsertLedger($school->id, $student->id, 'charge', 10000, 'invoice', $invoice->id); // duplicate charge

    expect(lcRun())->toBe(1)
        ->and(lcCodes())->toBe(['I6']);
});

// ── I7 — currency coherence ──────────────────────────────────────────────────────

it('I7 — a ledger row whose currency differs from its source document is caught', function () {
    [$school, $maker, , $student] = lcSetup();
    $invoice = lcInvoice($school, $student, 10000);
    $payment = lcPayment($school, $invoice, 4000, $maker); // real NGN payment + account

    // A second payment-sourced row in the WRONG currency: no assertion counts payment rows per
    // payment, so this isolates I7 (mismatch vs the payment's NGN and vs the account's NGN).
    lcInsertLedger($school->id, $student->id, 'payment', -1000, 'payment', $payment->id, 'USD');

    expect(lcRun())->toBe(1)
        ->and(lcCodes())->toBe(['I7']);
});
