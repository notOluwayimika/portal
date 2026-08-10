<?php

/*
 * S2 + S3 — THE CAPTURE COLUMNS, PROVED AT THE ROWS THE WRITERS ACTUALLY WRITE.
 *
 * Five NOT NULL columns landed on three append-only tables. NOT NULL means a writer that forgets
 * fails at the database rather than writing a silent gap — but "fails at the database" is only a
 * protection if every writer supplies a value that is CORRECT, and NOT NULL cannot check that. A
 * writer that stamps today onto a migrated opening balance satisfies the constraint perfectly and
 * records a lie that no UPDATE can ever fix.
 *
 * So these arms assert the VALUES, per writer, read back out of the database after the real Action
 * ran. Not that the column is populated — what it was populated WITH.
 *
 * WHY EVERY ARM DRIVES A REAL ACTION. The columns exist to describe money movements, and the only
 * authority on what a movement's business date is, is the code that records the movement. A test
 * that inserted rows itself would be asserting its own opinion.
 */

use App\Enums\TermStatusEnum;
use App\Finance\Actions\ApproveCreditNote;
use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\PostOpeningBalanceBatch;
use App\Finance\Actions\RecordAccountPayment;
use App\Finance\Actions\RecordPayment;
use App\Finance\Actions\SubmitCreditNote;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\Invoice;
use App\Finance\Models\LedgerTransaction;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Models\AcademicSession;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: Student, 2: User} */
function capSetup(): array
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $actor = User::factory()->create(['school_id' => $school->id]);

    return [$school, $student, $actor];
}

/**
 * A seat in $school holding EXACTLY $permissions, through a dedicated role — the same shape
 * PaymentRecordGateTest uses, and for the same reason: spatie's grants are team-scoped, so a bare
 * givePermissionTo outside a team context grants nothing and the route 403s.
 *
 * @param  list<string>  $permissions
 */
function capSeatWith(School $school, array $permissions): User
{
    $roleName = 'cap_'.substr(md5(implode(',', $permissions)), 0, 10);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $roleName);
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function capInvoice(School $school, Student $student, int $kobo): Invoice
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

// ── The shape itself. Pins the DECISION, not just the behaviour. ────────────────────────────────

it('ships the five capture columns NOT NULL with no defaults, and the two reasons nullable', function (string $table, string $column, string $nullable) {
    // THE ARM THAT PROTECTS THE OTHERS. Every behavioural arm below would still pass if someone
    // made these columns nullable — the writers would keep supplying values and the tests would
    // keep seeing them. What would change is that a FUTURE writer could omit one silently, which
    // is precisely the failure the NOT NULL was chosen to prevent. A default would do the same
    // damage more quietly: the writer omits, the database fills in, nobody is told.
    $row = DB::selectOne(
        'SELECT IS_NULLABLE n, COLUMN_DEFAULT d FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column],
    );

    expect($row)->not->toBeNull();
    expect($row->n)->toBe($nullable, "{$table}.{$column} nullability changed.");

    if ($nullable === 'NO') {
        expect($row->d)->toBeNull(
            "{$table}.{$column} gained a DEFAULT. A default lets a writer omit the value and get one "
            .'anyway, which reintroduces the silent gap NOT NULL was chosen to close — on an '
            .'append-only table where it can never be corrected.');
    }
})->with([
    ['finance_payments', 'received_at', 'NO'],
    ['finance_payments', 'received_at_reason', 'YES'],
    ['finance_ledger_transactions', 'posted_at', 'NO'],
    ['finance_ledger_transactions', 'effective_at', 'NO'],
    ['finance_payment_allocations', 'allocation_rule', 'NO'],
    ['finance_payment_allocations', 'allocation_overridden', 'NO'],
    ['finance_payment_allocations', 'allocation_override_reason', 'YES'],
]);

// ── Per writer, the values. ─────────────────────────────────────────────────────────────────────

it('RecordPayment: the payment carries the stated received date and its ledger credit shares it', function () {
    [$school, $student, $actor] = capSetup();
    $invoice = capInvoice($school, $student, 100000);
    $backdated = now()->subDays(4)->toDateString();

    $payment = ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)->handle(
        $invoice, Money::fromKobo(100000), 'Payer', $actor, $backdated, testBankAccountId(), 'Handed over on the 5th, keyed today'));

    expect($payment->fresh()->received_at->toDateString())->toBe($backdated)
        ->and($payment->fresh()->received_at_reason)->toBe('Handed over on the 5th, keyed today');

    // THE POINT OF TWO DATES. The ledger credit is effective when the CASH ARRIVED, and posted when
    // the row was written — today. If these collapsed into one column, either the audit trail would
    // claim the system knew on the 5th, or the period totals would put the money in the wrong month.
    $credit = LedgerTransaction::query()->where('source_type', 'payment')
        ->where('source_id', $payment->id)->firstOrFail();

    expect($credit->effective_at->toDateString())->toBe($backdated,
        'The ledger credit landed in a different period from the payment it records.')
        ->and($credit->posted_at->toDateString())->toBe(now()->toDateString(),
            'posted_at must be when the row was written, never the business date.');
});

it('RecordPayment: its allocation is attributed to the named-invoice rule, not overridden', function () {
    [$school, $student, $actor] = capSetup();
    $invoice = capInvoice($school, $student, 100000);

    $payment = ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)->handle(
        $invoice, Money::fromKobo(60000), 'Payer', $actor, SchoolDay::today(), testBankAccountId()));

    $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail();

    expect($allocation->allocation_rule)->toBe(PaymentAllocation::RULE_PAYMENT_AGAINST_NAMED_INVOICE)
        ->and($allocation->allocation_overridden)->toBeFalse()
        ->and($allocation->allocation_override_reason)->toBeNull();
});

it('GenerateInvoice: the charge is effective today, and a credit-draw allocation names the OTHER rule', function () {
    // THE ARM THAT MAKES THE SECOND CONSTANT EARN ITS KEEP. An overpayment leaves unallocated money;
    // a later invoice draws it forward. That allocation links cash that arrived BEFORE the charge
    // existed — a different provenance from RecordPayment's, and the whole reason one constant would
    // have been a false attribution rather than a missing one.
    [$school, $student, $actor] = capSetup();
    $first = capInvoice($school, $student, 10000);

    ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)->handle(
        $first, Money::fromKobo(30000), 'Overpayer', $actor, SchoolDay::today(), testBankAccountId()));

    $second = capInvoice($school, $student, 10000);

    $charge = LedgerTransaction::query()->where('source_type', 'invoice')
        ->where('source_id', $second->id)->firstOrFail();

    // SchoolDay::today(), not now()->toDateString(): GenerateInvoice takes the SCHOOL's day, and
    // between 00:00 and 01:00 WAT the server is still on the previous one. Comparing against the
    // server clock made this arm fail for one hour a day — which is the bug SchoolDay exists to fix,
    // reappearing in the test that checks the fix.
    expect($charge->effective_at->toDateString())->toBe(SchoolDay::today(),
        'A charge comes into existence when the invoice is raised; it has no earlier business date.');

    $drawn = PaymentAllocation::query()->where('invoice_id', $second->id)->first();

    expect($drawn instanceof PaymentAllocation)->toBeTrue('The overpayment was not drawn forward, so this arm proves nothing.');
    expect($drawn->allocation_rule)->toBe(PaymentAllocation::RULE_CREDIT_APPLIED_FORWARD_OLDEST_FIRST,
        'A credit-draw allocation is claiming RecordPayment’s provenance. The two rules are different '
        .'questions and the row is append-only, so a wrong attribution is permanent.');
});

it('RecordAccountPayment: an account payment carries its own received date into the ledger', function () {
    [$school, $student, $actor] = capSetup();
    $backdated = now()->subDays(2)->toDateString();

    $payment = ActiveSchool::runFor($school->id, fn () => app(RecordAccountPayment::class)->handle(
        $student->id, Money::fromKobo(50000), 'Payer', $actor, $backdated, testBankAccountId(), 'Received at the desk on Monday'));

    $credit = LedgerTransaction::query()->where('source_type', 'payment')
        ->where('source_id', $payment->id)->firstOrFail();

    expect($payment->fresh()->received_at->toDateString())->toBe($backdated)
        ->and($credit->effective_at->toDateString())->toBe($backdated,
            'An account payment has no invoice to anchor it, so received_at is the ONLY statement of '
            .'when this money belongs — the ledger must not disagree with it.');
});

it('ApproveVoidRequest: the reversal is effective in the ORIGINAL charge’s period, not today', function () {
    // THE ACCOUNTING DECISION, PINNED. A void says the invoice should never have existed, and
    // VoidEligibility guarantees no payment ever touched it — so the honest record is one period in
    // which charge and reversal net to zero. Dating the reversal today would leave the original
    // period overstated forever and understate this one by the same amount.
    [$school, $student, $maker] = capSetup();
    $checker = User::factory()->create(['school_id' => $school->id]);

    // THE INVOICE IS RAISED A MONTH AGO, by travelling rather than by back-dating the row after the
    // fact. The first draft of this arm did the latter and the database refused it — 1644, the
    // append-only trigger, this commit's own subject biting its own test. That refusal is the
    // reason the columns had to be got right at the write, and a test that edited history to set
    // itself up would have been proving something the production path can never do.
    // THE EXPECTATION IS READ AT THE TRAVELLED INSTANT, IN THE SCHOOL'S TIMEZONE, because that is
    // what GenerateInvoice stamps. Computed before travelling, from now()->subMonth() in UTC, it
    // disagreed with the writer for one hour a day — the boundary SchoolDay exists for, reappearing
    // inside the test that checks it.
    $this->travelTo(now()->subMonth());
    $expected = SchoolDay::today();
    $invoice = capInvoice($school, $student, 100000);
    $this->travelBack();

    ActiveSchool::runFor($school->id, function () use ($invoice, $maker, $checker) {
        $request = app(SubmitVoidRequest::class)->handle($invoice, 'Raised in error', $maker);
        app(ApproveVoidRequest::class)->handle($request, $checker);
    });

    $reversal = LedgerTransaction::query()->where('source_type', 'invoice')
        ->where('source_id', $invoice->id)->orderByDesc('id')->firstOrFail();

    expect($reversal->effective_at->toDateString())->toBe($expected,
        'The void reversal landed in a different period from the charge it reverses, leaving both '
        .'periods wrong about an invoice that never should have existed.')
        // posted_at against the SERVER clock, not the school's — SubledgerPoster stamps it with
        // now() by design, because it records when the row was WRITTEN rather than which period it
        // belongs to. Comparing it to SchoolDay::today() failed at the boundary hour, which is the
        // two columns proving they are genuinely different questions.
        ->and($reversal->posted_at->toDateString())->toBe(now()->toDateString());
});

it('ApproveCreditNote: the credit is effective TODAY, deliberately unlike a void', function () {
    // THE OTHER HALF OF THE SAME DECISION, AND IT GOES THE OTHER WAY ON PURPOSE. A credit note does
    // not assert the charge was wrong; it is a new decision to forgive part of it, taken now.
    // CreditNoteKind's own docblock is the authority: both values are present-tense judgements —
    // "the money is forgiven", "the receivable is JUDGED uncollectable". Back-dating that would
    // assert the receivable was never collectable and restate a period that was correct.
    [$school, $student, $maker] = capSetup();
    $checker = User::factory()->create(['school_id' => $school->id]);

    // Same construction as the void arm: the charge genuinely belongs to last month, so "today" and
    // "the original's period" are distinguishable and the assertion cannot pass by coincidence.
    $this->travelTo(now()->subMonth());
    $invoice = capInvoice($school, $student, 100000);
    $this->travelBack();

    ActiveSchool::runFor($school->id, function () use ($invoice, $maker, $checker) {
        $note = app(SubmitCreditNote::class)->handle(
            $invoice, Money::fromKobo(20000), CreditNoteKind::CreditNote, 'Goodwill', $maker, testBankAccountId());
        app(ApproveCreditNote::class)->handle($note, $checker);
    });

    $credit = LedgerTransaction::query()->where('source_type', 'credit_note')->firstOrFail();

    expect($credit->effective_at->toDateString())->toBe(SchoolDay::today(),
        'A credit note was back-dated to the invoice’s period. If that is Brookstone’s accounting '
        .'policy the change belongs in ApproveCreditNote with its reasoning — but it must not '
        .'happen by matching ApproveVoidRequest, which answers a different question.');
});

// ── Required going forward, at the edge. ────────────────────────────────────────────────────────

it('refuses a payment with no received date, on both payment routes', function (string $route) {
    // NOT ENFORCEABLE BY A CHECK — there is no way to express "rows created after this migration" —
    // so the requirement lives in the FormRequest and the Action. This is the arm that keeps it
    // there. Without it the column is NOT NULL and the API simply 500s instead of 422ing, which is
    // a worse answer to the same mistake.
    [$school, $student, $actor] = capSetup();
    $invoice = capInvoice($school, $student, 100000);

    $url = $route === 'invoice'
        ? "/api/v1/finance/invoices/{$invoice->uuid}/payments"
        : "/api/v1/finance/students/{$student->uuid}/payments";

    $payer = capSeatWith($school, ['finance.access', 'finance.payment.record']);

    $this->actingAs($payer)->withSession(['school_id' => $school->id])
        ->postJson($url, ['amount_minor' => 10000, 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['received_at']);
})->with(['invoice', 'account']);

it('refuses a BACK-DATED payment with no reason', function () {
    // U9's spec. A back-dated receipt is legitimate and common; a back-dated receipt nobody
    // explained is the first thing an auditor asks about, and the table is append-only so the
    // explanation cannot be added later.
    [$school, $student, $actor] = capSetup();
    $invoice = capInvoice($school, $student, 100000);

    $payer = capSeatWith($school, ['finance.access', 'finance.payment.record']);

    $this->actingAs($payer)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/payments", [
            'amount_minor' => 10000,
            'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X',
            'received_at' => now()->subDays(3)->toDateString(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['received_at_reason']);
});

// ── The migrated writer — the one the brief called the interesting case. ────────────────────────

it('PostOpeningBalanceBatch: the migrated payment and its ledger rows are dated CUTOVER, not today', function () {
    // THE ARM THAT WAS MISSING, AND ITS ABSENCE WAS INVISIBLE. A mutation stamping now() onto the
    // migrated payment's received_at left the entire opening-balance suite green — because
    // OpeningBalancePostingTest builds its batches with `cutover_date => now()`, so cutover and
    // today are the same value there and no assertion can tell them apart. A fixture that cannot
    // distinguish the right answer from the wrong one is the quietest kind of coverage gap.
    //
    // So this arm cuts over in the PAST, which is also what a real cutover looks like: WCBS balances
    // are imported after the fact.
    $cutover = now()->subMonths(2)->startOfMonth()->toDateString();

    $school = School::factory()->create();
    $actor = User::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $batch = ActiveSchool::runFor($school->id, function () use ($school, $student, $cutover) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'Third Term',
            'slug' => 'term-'.Str::random(8), 'order' => 3, 'start_date' => now()->subMonths(4),
            'end_date' => now()->subMonth(), 'status' => TermStatusEnum::ACTIVE->value,
        ]);

        // One NEGATIVE balance — a credit — because that is the branch that writes a migrated
        // PAYMENT row. A positive balance writes only a charge.
        $batch = OpeningBalanceBatch::create([
            'batch_reference' => 'WCBS-CAP-1',
            'filename' => 'WCBS-CAP-1.csv',
            'status' => OpeningBalanceBatchStatus::Submitted,
            'row_count' => 1,
            'file_row_count' => 1,
            'control_total' => Money::fromKobo(-50000),
            'cutover_date' => $cutover,
            'term_id' => $term->id,
            'uploaded_by_user_id' => null,
        ]);

        OpeningBalanceRow::create([
            'batch_id' => $batch->id,
            'line_number' => 1,
            'admission_number' => $student->admission_number,
            'wcbs_student_ref' => 'WCBS-'.$student->id,
            'fee_type_label' => 'Tuition',
            'balance' => Money::fromKobo(-50000),
            'student_total_balance' => Money::fromKobo(-50000),
            'wcbs_bill_reference' => null,
            'student_id' => $student->id,
            'status' => OpeningBalanceRowStatus::Ok,
        ]);

        return $batch->refresh();
    });

    ActiveSchool::runFor($school->id, fn () => app(PostOpeningBalanceBatch::class)->handle($batch, $actor));

    $payment = Payment::query()->where('origin', 'migrated')->firstOrFail();

    expect($payment->received_at->toDateString())->toBe($cutover,
        'A migrated payment was dated the day the batch was posted. The money reached WCBS at '
        .'cutover; dating it today moves a term of cash into the import period and the table is '
        .'append-only, so it can never be corrected.')
        ->and($payment->received_at_reason)->toBeNull();

    $credit = LedgerTransaction::query()->where('source_type', 'payment')
        ->where('source_id', $payment->id)->firstOrFail();

    expect($credit->effective_at->toDateString())->toBe($cutover,
        'The migrated payment and its own ledger credit landed in different periods.')
        ->and($credit->posted_at->toDateString())->toBe(now()->toDateString(),
            'posted_at is when the import ran, which is genuinely today — that is the column doing '
            .'its job, and it is what makes effective_at safe to back-date.');
});
