<?php

use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\Invoice;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Finance walking skeleton — the thin vertical driven end to end through the API:
 * enrollment → invoice → ledger charge → payment → allocation → cancellation
 * (reversal). Plus the four guards the slice exists to prove: RESTRICT FK, the
 * append-only ledger, Money's wire shape, and (separately, via bin/) the boundary.
 */
uses(RefreshDatabase::class);

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

it('generates an invoice bound to the enrollment, with a Money wire shape and a ledger charge', function () {
    [$school, $admin, $enrollment] = financeSetup();

    $response = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment->uuid,
            'amount_minor' => 150000,
            'description' => 'Term 1 tuition',
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

it('cancels by REVERSAL: invoice row persists, status flips, ledger nets to zero', function () {
    [$school, $admin, $enrollment] = financeSetup();

    $create = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'amount_minor' => 150000, 'description' => 'Tuition'])
        ->assertCreated();
    $invoiceUuid = $create->json('id');

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/cancel", ['reason' => 'entered in error'])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    $invoice = Invoice::withoutGlobalScopes()->where('uuid', $invoiceUuid)->first();
    expect($invoice)->not->toBeNull()                                  // never deleted
        ->and($invoice->status)->toBe(InvoiceStatus::Cancelled)
        ->and($invoice->cancelled_at)->not->toBeNull()
        ->and($invoice->cancelled_by_user_id)->toBe($admin->id)
        ->and(ledgerBalance($enrollment->student_id))->toBe(0)        // charge + reversal net to zero
        ->and(DB::table('finance_ledger_transactions')->count())->toBe(2); // both entries survive (append-only)
});

it('records a payment allocated to the invoice, crediting the ledger to zero', function () {
    [$school, $admin, $enrollment] = financeSetup();

    $create = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'amount_minor' => 150000, 'description' => 'Tuition'])
        ->assertCreated();
    $invoiceUuid = $create->json('id');

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments", ['amount_minor' => 150000, 'payer_name' => 'Mr Obi'])
        ->assertCreated()
        ->assertJsonPath('amount.amount_minor', 150000);

    expect(DB::table('finance_payment_allocations')->count())->toBe(1)
        ->and(ledgerBalance($enrollment->student_id))->toBe(0); // charge +150000, payment -150000
});

it('GUARD — a payment allocation survives invoice cancellation, leaving a credit balance', function () {
    [$school, $admin, $enrollment] = financeSetup();
    $create = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'amount_minor' => 150000, 'description' => 'Tuition'])->assertCreated();
    $invoiceUuid = $create->json('id');
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments", ['amount_minor' => 150000, 'payer_name' => 'Mr Obi'])->assertCreated();

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/cancel", ['reason' => 'error'])->assertOk();

    // Allocation untouched; charge+payment+reversal = -150000 (a credit owed the student).
    expect(DB::table('finance_payment_allocations')->count())->toBe(1)
        ->and(ledgerBalance($enrollment->student_id))->toBe(-150000);
});

it('GUARD — cancelling an already-cancelled invoice is rejected', function () {
    [$school, $admin, $enrollment] = financeSetup();
    $create = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'amount_minor' => 5000, 'description' => 'x'])->assertCreated();
    $invoiceUuid = $create->json('id');
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/cancel", ['reason' => 'a'])->assertOk();

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/cancel", ['reason' => 'b'])
        ->assertStatus(422);
});

it('GUARD — ON DELETE RESTRICT: once an invoice references it, the enrollment/curriculum cannot be cascaded away', function () {
    [$school, $admin, $enrollment] = financeSetup();
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'amount_minor' => 5000, 'description' => 'x'])->assertCreated();

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
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'amount_minor' => 5000, 'description' => 'x'])->assertCreated();

    $rowId = DB::table('finance_ledger_transactions')->value('id');

    // Triggers fire even against raw DB writes (what tinker / a mass delete would do).
    expect(fn () => DB::table('finance_ledger_transactions')->where('id', $rowId)->update(['amount_minor' => 1]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_ledger_transactions')->where('id', $rowId)->delete())
        ->toThrow(QueryException::class);
});
