<?php

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\Invoice;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Finance walking skeleton — the thin vertical driven end to end through the API:
 * enrollment → invoice → ledger charge → payment → allocation → void (reversal).
 * Plus the four guards the slice exists to prove: RESTRICT FK, the append-only
 * ledger, Money's wire shape, and (separately, via bin/) the boundary.
 *
 * Slice 2 updated the wire (lines[] instead of a single amount) and the
 * vocabulary (cancelled → void). The assertions are otherwise unchanged, which is
 * the point: the template's guarantees survived the change.
 */
uses(RefreshDatabase::class);

// C2 (role:->permission: swap): routes now authorize by GRANTS, not role
// names, so the locally-fabricated roles need the canonical grant map to
// reach the code under test.
beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: StudentCurriculum} */
function financeSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id, 'first_name' => 'Ada', 'last_name' => 'Obi']);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);
    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]);

    return [$school, $admin, $enrollment];
}

function ledgerBalance(int $studentId): int
{
    return (int) DB::table('finance_ledger_transactions')->where('student_id', $studentId)->sum('amount_minor');
}

/** One-line invoice payload — the skeleton's shape, expressed as a single line. */
function oneLine(int $amountMinor, string $description = 'Tuition'): array
{
    return [['description' => $description, 'amount_minor' => $amountMinor]];
}

it('generates an invoice bound to the enrollment, with a Money wire shape and a ledger charge', function () {
    [$school, $admin, $enrollment] = financeSetup();

    $response = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment->uuid,
            'lines' => oneLine(150000, 'Term 1 tuition'),
        ])
        ->assertCreated();

    // Money crosses the wire as {amount_minor, currency} — never a decimal.
    $response->assertJsonPath('total.amount_minor', 150000)
        ->assertJsonPath('total.currency', 'NGN')
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('billed_to_name', 'Ada Obi')
        ->assertJsonPath('lines.0.amount.amount_minor', 150000);

    $invoice = Invoice::withoutGlobalScopes()->first();
    expect($invoice->student_curriculum_id)->toBe($enrollment->id)  // bound to the durable referent
        ->and(ledgerBalance($enrollment->student_id))->toBe(150000); // one charge posted
});

it('voids by REVERSAL: invoice row persists, status flips, ledger nets to zero', function () {
    [$school, $admin, $enrollment] = financeSetup();

    $create = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => oneLine(150000)])
        ->assertCreated();
    $invoiceUuid = $create->json('id');

    // Ph3b: void is now the two-person maker-checker path (the one-step cancel is retired).
    // Approval is what flips the invoice + posts the reversal; the CHECKER is recorded.
    $checker = voidInvoiceViaApproval($school->id, $invoiceUuid, 'entered in error');

    $invoice = Invoice::withoutGlobalScopes()->where('uuid', $invoiceUuid)->first();
    expect($invoice)->not->toBeNull()                                  // never deleted
        ->and($invoice->status)->toBe(InvoiceStatus::Void)
        ->and($invoice->cancelled_at)->not->toBeNull()
        ->and($invoice->cancelled_by_user_id)->toBe($checker->id)     // the approver, not the maker
        ->and(ledgerBalance($enrollment->student_id))->toBe(0)        // charge + reversal net to zero
        ->and(DB::table('finance_ledger_transactions')->count())->toBe(2); // both entries survive (append-only)
});

it('records a payment allocated to the invoice, crediting the ledger to zero', function () {
    [$school, $admin, $enrollment] = financeSetup();

    $create = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => oneLine(150000)])
        ->assertCreated();
    $invoiceUuid = $create->json('id');

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments", ['amount_minor' => 150000, 'payer_name' => 'Mr Obi'])
        ->assertCreated()
        ->assertJsonPath('amount.amount_minor', 150000);

    expect(DB::table('finance_payment_allocations')->count())->toBe(1)
        ->and(ledgerBalance($enrollment->student_id))->toBe(0); // charge +150000, payment -150000
});

it('GUARD — an invoice with an allocated payment cannot be voided (Ph3b: reverse/refund the payment instead)', function () {
    [$school, $admin, $enrollment] = financeSetup();
    $create = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => oneLine(150000)])->assertCreated();
    $invoiceUuid = $create->json('id');
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments", ['amount_minor' => 150000, 'payer_name' => 'Mr Obi'])->assertCreated();

    // The old one-step cancel let you void a PAID invoice, stranding the payment as a credit.
    // Ph3b VoidEligibility forbids that: a settled invoice is not voidable, so even SUBMITTING a
    // void request is refused outright — the money must be reversed/refunded through its own path.
    ActiveSchool::runFor($school->id, function () use ($invoiceUuid, $school) {
        $invoice = Invoice::withoutGlobalScopes()->where('uuid', $invoiceUuid)->firstOrFail();
        $maker = User::factory()->create(['school_id' => $school->id]);
        expect(fn () => app(SubmitVoidRequest::class)->handle($invoice, 'error', $maker))
            ->toThrow(BusinessRuleException::class);
    });

    // Nothing moved: allocation intact, no reversal posted (charge + payment net to zero, not
    // -150000), and the invoice is still issued.
    expect(DB::table('finance_payment_allocations')->count())->toBe(1)
        ->and(ledgerBalance($enrollment->student_id))->toBe(0)
        ->and(DB::table('finance_invoices')->where('uuid', $invoiceUuid)->value('status'))->toBe('issued');
});

it('GUARD — an already-voided invoice cannot be voided again', function () {
    [$school, $admin, $enrollment] = financeSetup();
    $invoiceUuid = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => oneLine(5000, 'x')])
        ->assertCreated()->json('id');

    voidInvoiceViaApproval($school->id, $invoiceUuid, 'a');

    // A second void is refused at SUBMIT — the invoice is already void, so no new request is
    // even created (the one-open-request UNIQUE never comes into play for a decided invoice).
    ActiveSchool::runFor($school->id, function () use ($invoiceUuid, $school) {
        $invoice = Invoice::withoutGlobalScopes()->where('uuid', $invoiceUuid)->firstOrFail();
        $maker = User::factory()->create(['school_id' => $school->id]);
        expect(fn () => app(SubmitVoidRequest::class)->handle($invoice, 'b', $maker))
            ->toThrow(BusinessRuleException::class);
    });

    expect((int) DB::table('finance_void_requests')->count())->toBe(1); // only the approved one
});

it('GUARD — ON DELETE RESTRICT: once an invoice references it, the enrollment/curriculum cannot be cascaded away', function () {
    [$school, $admin, $enrollment] = financeSetup();
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => oneLine(5000, 'x')])->assertCreated();

    // curricula ← student_curricula is CASCADE; finance_invoices ← student_curricula is
    // RESTRICT — so deleting the curriculum fails the whole statement at the DB.
    expect(fn () => DB::table('curricula')->where('id', $enrollment->curriculum_id)->delete())
        ->toThrow(QueryException::class);

    // And deleting the enrollment directly is refused too.
    expect(fn () => DB::table('student_curricula')->where('id', $enrollment->id)->delete())
        ->toThrow(QueryException::class);
});

it('GUARD — the subledger is append-only: raw UPDATE and DELETE are denied at the DB', function () {
    [$school, $admin, $enrollment] = financeSetup();
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => oneLine(5000, 'x')])->assertCreated();

    $rowId = DB::table('finance_ledger_transactions')->value('id');

    // Triggers fire even against raw DB writes (what tinker / a mass delete would do).
    expect(fn () => DB::table('finance_ledger_transactions')->where('id', $rowId)->update(['amount_minor' => 1]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_ledger_transactions')->where('id', $rowId)->delete())
        ->toThrow(QueryException::class);
});
