<?php

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\CancelInvoice;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\IssueCreditNote;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Models\Invoice;
use App\Finance\Models\PaymentAllocation;
use App\Finance\Models\StudentAccount;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * §10 C1 — credit notes & write-offs, bite-proven.
 *
 * A credit note is its own append-only aggregate posting a compensating ledger credit;
 * the wallet (W1+W2) absorbs the resulting credit balance and the next charge nets
 * against it (the Option-1 carry-forward: credit-note credit rides the ACCOUNT balance,
 * not a per-invoice allocation). One new guard: Σ(credits) ≤ invoice total, independent
 * of #94's Σ(allocations) ≤ total. Concurrency lives in CreditNoteConcurrencyTest.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: Student} */
function cnSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id]);

    return [$school, $admin, $student];
}

function cnInvoice(School $school, Student $student, int $kobo): Invoice
{
    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]);

    return app(GenerateInvoice::class)->handle(
        $enrollment->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo))],
    );
}

function cnBalance(int $studentId): int
{
    return (int) StudentAccount::query()->where('student_id', $studentId)->value('balance_minor');
}

/** Insert a raw credit-note row, bypassing the Action entirely (to exercise the trigger). */
function cnRawInsert(Invoice $invoice, int $kobo, string $currency = 'NGN'): void
{
    DB::table('finance_credit_notes')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $invoice->school_id,
        'student_id' => $invoice->student_id,
        'invoice_id' => $invoice->id,
        'number' => random_int(1, PHP_INT_MAX),
        'amount_minor' => $kobo,
        'amount_currency' => $currency,
        'kind' => 'credit_note',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('PROOF 1 — THE PAYOFF (account-level): pay an invoice fully, credit it, and the next charge nets the credit; no throw', function () {
    [$school, $admin, $student] = cnSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $a = cnInvoice($school, $student, 10000);
        app(RecordPayment::class)->handle($a, Money::fromKobo(10000), 'Payer', $admin); // A fully paid, balance 0
        app(IssueCreditNote::class)->handle($a, Money::fromKobo(3000), CreditNoteKind::CreditNote, null, $admin);

        // The credit note posted −3000 → an account CREDIT balance of 3000.
        expect(cnBalance($student->id))->toBe(-3000);
        $account = StudentAccount::query()->where('student_id', $student->id)->firstOrFail();
        expect($account->availableCredit()->toKobo())->toBe(3000);

        // The next invoice generates without throwing; its charge nets the credit at the
        // ACCOUNT level (−3000 + 12000 = 9000 owed). Credit-note credit is NOT payment-
        // sourced, so it does NOT appear as an allocation on B — it rode the balance.
        $b = cnInvoice($school, $student, 12000);
        expect(cnBalance($student->id))->toBe(9000)
            ->and((int) PaymentAllocation::query()->where('invoice_id', $b->id)->sum('amount_minor'))->toBe(0);
    });
});

it('PROOF 2 — W3 relax: credit-note credit with no payment applies 0 allocations and does NOT throw', function () {
    [$school, $admin, $student] = cnSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        // Credit-note-only credit (3000), zero unallocated payments (the payment was
        // fully allocated to the paid invoice).
        $a = cnInvoice($school, $student, 10000);
        app(RecordPayment::class)->handle($a, Money::fromKobo(10000), 'Payer', $admin);
        app(IssueCreditNote::class)->handle($a, Money::fromKobo(3000), CreditNoteKind::CreditNote, null, $admin);

        // Before the C1 relax this generation THREW ("could not be fully sourced from
        // unallocated payments"). Now it applies 0 allocations and the 3000 rides the
        // balance — proven red-then-green out of band (the relax is a one-line change).
        $b = cnInvoice($school, $student, 12000);
        expect((int) PaymentAllocation::query()->where('invoice_id', $b->id)->sum('amount_minor'))->toBe(0)
            ->and(cnBalance($student->id))->toBe(9000);
    });
});

it('PROOF 2b — MIXED: overpayment + credit-note credit → W3 allocates only the payment part, the credit-note part rides the balance', function () {
    [$school, $admin, $student] = cnSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        // Overpay 15000 on a 10000 invoice → 5000 payment-backed credit (5000 unallocated).
        $a = cnInvoice($school, $student, 10000);
        app(RecordPayment::class)->handle($a, Money::fromKobo(15000), 'Over', $admin);
        // …and a 3000 credit note on the same invoice → net credit 8000 (balance −8000).
        app(IssueCreditNote::class)->handle($a, Money::fromKobo(3000), CreditNoteKind::CreditNote, null, $admin);
        expect(cnBalance($student->id))->toBe(-8000);

        // Next invoice 12000: credit 8000, but only the 5000 payment-backed portion can be
        // sourced as an allocation; the 3000 credit-note portion rides the balance.
        $b = cnInvoice($school, $student, 12000);
        expect((int) PaymentAllocation::query()->where('invoice_id', $b->id)->sum('amount_minor'))->toBe(5000)
            ->and(cnBalance($student->id))->toBe(4000); // −8000 + 12000
    });
});

it('PROOF 3 — the ceiling: app 422, cumulative, exact-fill, and the DB trigger rejects a raw over-credit', function () {
    [$school, $admin, $student] = cnSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $inv = cnInvoice($school, $student, 10000);

        // Cumulative: 6000 then 3000 (Σ=9000 ≤ 10000) OK; a further 2000 (→11000) rejected.
        app(IssueCreditNote::class)->handle($inv, Money::fromKobo(6000), CreditNoteKind::CreditNote, null, $admin);
        app(IssueCreditNote::class)->handle($inv, Money::fromKobo(3000), CreditNoteKind::CreditNote, null, $admin);
        expect(fn () => app(IssueCreditNote::class)->handle($inv, Money::fromKobo(2000), CreditNoteKind::CreditNote, null, $admin))
            ->toThrow(BusinessRuleException::class);

        // Exact-fill: the remaining 1000 (Σ=10000 == total) is accepted (≤, not <).
        app(IssueCreditNote::class)->handle($inv, Money::fromKobo(1000), CreditNoteKind::CreditNote, null, $admin);
        expect((int) DB::table('finance_credit_notes')->where('invoice_id', $inv->id)->sum('amount_minor'))->toBe(10000);

        // THE REAL GUARANTEE: a raw insert bypassing the Action is rejected by the trigger.
        expect(fn () => cnRawInsert($inv, 1))->toThrow(QueryException::class);
    });
});

it('PROOF 4 — F6 untouched: issuing a credit note changes no invoice total and adds no line', function () {
    [$school, $admin, $student] = cnSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $inv = cnInvoice($school, $student, 10000);
        $linesBefore = $inv->lines()->count();

        app(IssueCreditNote::class)->handle($inv, Money::fromKobo(3000), CreditNoteKind::CreditNote, null, $admin);

        $fresh = Invoice::query()->whereKey($inv->id)->firstOrFail();
        expect($fresh->total->toKobo())->toBe(10000)                 // unchanged
            ->and($fresh->lines()->count())->toBe($linesBefore);     // no line added

        // F6's total-immutability trigger still holds (a raw edit of the money column dies).
        expect(fn () => DB::table('finance_invoices')->where('id', $inv->id)->update(['total_minor' => 7000]))
            ->toThrow(QueryException::class);
    });
});

it('PROOF 5 — write-off is a kind: same mechanism, same ledger effect, distinct label', function () {
    [$school, $admin, $student] = cnSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $inv = cnInvoice($school, $student, 10000);
        app(RecordPayment::class)->handle($inv, Money::fromKobo(4000), 'Part', $admin); // balance +6000

        $note = app(IssueCreditNote::class)->handle($inv, Money::fromKobo(6000), CreditNoteKind::WriteOff, 'uncollectable', $admin);

        expect($note->kind)->toBe(CreditNoteKind::WriteOff)
            ->and(cnBalance($student->id))->toBe(0)  // +10000 −4000 −6000 → the receivable is cleared
            ->and((int) DB::table('finance_ledger_transactions')->where('type', 'credit_note')->sum('amount_minor'))->toBe(-6000);
    });
});

it('PROOF 8 — append-only: raw UPDATE and DELETE on a credit note are denied at the DB', function () {
    [$school, $admin, $student] = cnSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $inv = cnInvoice($school, $student, 10000);
        $note = app(IssueCreditNote::class)->handle($inv, Money::fromKobo(3000), CreditNoteKind::CreditNote, null, $admin);

        expect(fn () => DB::table('finance_credit_notes')->where('id', $note->id)->update(['amount_minor' => 1]))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('finance_credit_notes')->where('id', $note->id)->delete())
            ->toThrow(QueryException::class);
    });
});

it('PROOF 9 — crediting a VOID invoice is rejected', function () {
    [$school, $admin, $student] = cnSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $inv = cnInvoice($school, $student, 10000);
        app(CancelInvoice::class)->handle($inv, 'mistake', $admin); // void

        expect(fn () => app(IssueCreditNote::class)->handle(
            Invoice::withoutGlobalScopes()->findOrFail($inv->id), Money::fromKobo(1000), CreditNoteKind::CreditNote, null, $admin
        ))->toThrow(BusinessRuleException::class);

        expect((int) DB::table('finance_credit_notes')->count())->toBe(0);
    });
});

it('PROOF 10 — overpayment path unchanged: payment-backed credit still shows as a visible per-invoice allocation', function () {
    [$school, $admin, $student] = cnSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $a = cnInvoice($school, $student, 2000);
        app(RecordPayment::class)->handle($a, Money::fromKobo(5000), 'Over', $admin); // 3000 unallocated credit

        $b = cnInvoice($school, $student, 12000);
        // Pure overpayment credit → W3 still applies it as a visible allocation on B.
        expect((int) PaymentAllocation::query()->where('invoice_id', $b->id)->sum('amount_minor'))->toBe(3000);
    });
});

it('PROOF 6 & 12 (HTTP) — the statement shows invoice, credit note, and account credit balance distinctly; issuing needs the permission', function () {
    [$school, $admin, $student] = cnSetup();

    $invoice = ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $inv = cnInvoice($school, $student, 10000);
        app(RecordPayment::class)->handle($inv, Money::fromKobo(10000), 'Payer', $admin);
        app(IssueCreditNote::class)->handle($inv, Money::fromKobo(3000), CreditNoteKind::CreditNote, null, $admin);

        return $inv;
    });

    // ── PROOF 12: a user WITHOUT finance.credit-note.issue is forbidden (has finance.access).
    $blocked = User::factory()->create(['school_id' => $school->id]);
    $blocked->grantSchoolAccess($school, 'registrar');
    Permission::firstOrCreate(['name' => 'finance.access', 'guard_name' => 'web']);
    Role::findByName('registrar', 'web')->givePermissionTo('finance.access');
    $blocked->flushSchoolAccessCache();

    $this->actingAs($blocked)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/credit-notes", ['amount_minor' => 1000])
        ->assertForbidden();

    // ── PROOF 6: the statement read shows the three things distinctly, never netted.
    $reader = User::factory()->create(['school_id' => $school->id]);
    $reader->grantSchoolAccess($school, 'admin');
    Role::findByName('admin', 'web')->givePermissionTo('finance.access');
    $reader->flushSchoolAccessCache();

    $this->actingAs($reader)->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/students/{$student->uuid}/invoices")
        ->assertOk()
        ->assertJsonPath('invoices.0.total.amount_minor', 10000)      // invoice full, not netted
        ->assertJsonPath('credit_notes.0.amount.amount_minor', 3000)  // credit note as its own document
        ->assertJsonPath('credit_notes.0.kind', 'credit_note')
        ->assertJsonPath('account.available_credit.amount_minor', 3000); // account credit balance surfaced
});
