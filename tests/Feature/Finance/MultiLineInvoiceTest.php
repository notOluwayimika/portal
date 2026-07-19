<?php

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Models\Invoice;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slice 2 — real multi-line invoicing.
 *
 * Lands F6 (total = SUM(lines), derived and snapshotted in the creating
 * transaction), the VOID read-side gate, and the duplicate-invoice guard.
 *
 * Amounts are deliberately DISTINCT and NON-ROUND. [100,100,100] would pass
 * equally under a correct SUM, a count×price bug, or a max() bug — a coincidence
 * that hides exactly the defect this test exists to catch.
 */
uses(RefreshDatabase::class);

/** @return array{0: School, 1: User, 2: Student} */
function slice2Setup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id, 'first_name' => 'Ada', 'last_name' => 'Obi']);

    return [$school, $admin, $student];
}

function slice2Enrollment(School $school, Student $student): StudentCurriculum
{
    return StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]);
}

/** Three distinct, non-round amounts. 12345 + 67891 + 250003 = 330239. */
const SLICE2_LINES = [
    ['description' => 'Tuition', 'amount_minor' => 12345],
    ['description' => 'Boarding', 'amount_minor' => 67891],
    ['description' => 'PTA levy', 'amount_minor' => 250003],
];
const SLICE2_TOTAL = 330239;

// ---------------------------------------------------------------- F6

it('F6 — derives the invoice total as the exact SUM of multiple distinct lines', function () {
    [$school, $admin, $student] = slice2Setup();
    $enrollment = slice2Enrollment($school, $student);

    $response = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment->uuid,
            'lines' => SLICE2_LINES,
        ])->assertCreated();

    $response->assertJsonPath('total.amount_minor', SLICE2_TOTAL)
        ->assertJsonPath('total.currency', 'NGN')
        ->assertJsonCount(3, 'lines');

    $invoice = Invoice::withoutGlobalScopes()->with('lines')->first();

    // The stored snapshot equals the stored lines — not just the response.
    $sumOfLines = (int) DB::table('finance_invoice_lines')->where('invoice_id', $invoice->id)->sum('amount_minor');
    expect($invoice->total->toKobo())->toBe(SLICE2_TOTAL)
        ->and($sumOfLines)->toBe(SLICE2_TOTAL)
        ->and($invoice->lines)->toHaveCount(3)
        // The ledger charge is the same derived total — one charge, not one per line.
        ->and((int) DB::table('finance_ledger_transactions')->where('student_id', $student->id)->sum('amount_minor'))
        ->toBe(SLICE2_TOTAL);
});

it('F6 — the wire has no total field to supply: a client-sent total is ignored, not honoured', function () {
    [$school, $admin, $student] = slice2Setup();
    $enrollment = slice2Enrollment($school, $student);

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment->uuid,
            'lines' => SLICE2_LINES,
            'total_minor' => 1,          // hostile input
            'total' => ['amount_minor' => 1, 'currency' => 'NGN'],
        ])->assertCreated()
        ->assertJsonPath('total.amount_minor', SLICE2_TOTAL); // derived, never accepted
});

it('F6 BITE-PROOF — the snapshotted total cannot be hand-edited afterwards (DB trigger)', function () {
    [$school, $admin, $student] = slice2Setup();
    $enrollment = slice2Enrollment($school, $student);

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => SLICE2_LINES])
        ->assertCreated();

    $id = DB::table('finance_invoices')->value('id');

    // What tinker, a migration, or a compromised path would do. Denied at the DB —
    // NOT by application discipline. Remove the finance_invoices_total_immutable
    // trigger and this test goes red.
    expect(fn () => DB::table('finance_invoices')->where('id', $id)->update(['total_minor' => 1]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_invoices')->where('id', $id)->update(['total_currency' => 'USD']))
        ->toThrow(QueryException::class);

    // The snapshot survived the attempts, so total still equals SUM(lines).
    expect((int) DB::table('finance_invoices')->where('id', $id)->value('total_minor'))->toBe(SLICE2_TOTAL);
});

it('F6 — the status transition is still allowed (the trigger freezes money, not lifecycle)', function () {
    [$school, $admin, $student] = slice2Setup();
    $enrollment = slice2Enrollment($school, $student);

    $uuid = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => SLICE2_LINES])
        ->assertCreated()->json('id');

    // If the immutability trigger were too broad it would block voiding entirely.
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$uuid}/cancel", ['reason' => 'error'])
        ->assertOk()->assertJsonPath('status', 'void');
});

it('rejects an invoice with no lines, and a line with a non-positive amount (FormRequest layer)', function () {
    [$school, $admin, $student] = slice2Setup();
    $enrollment = slice2Enrollment($school, $student);

    // NOTE the status: FormRequest validation failures return 400 app-wide (the
    // `validation_error` macro), NOT 422. That 422-vs-400 inconsistency is a known
    // parked app-wide decision (walking-skeleton-conventions.md: "Validation error
    // shape — DEFERRED"), deliberately NOT resolved in this slice. Asserting the
    // real number rather than the aspirational one means this test goes red — and
    // prompts an update — the day that convention actually lands.
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => []])
        ->assertStatus(400);

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment->uuid,
            'lines' => [['description' => 'Bad', 'amount_minor' => 0]],
        ])->assertStatus(400);

    expect(DB::table('finance_invoices')->count())->toBe(0);
});

it('rejects an invoice with no lines, and a non-positive line, at the ACTION layer too', function () {
    [$school, $admin, $student] = slice2Setup();
    $enrollment = slice2Enrollment($school, $student);
    $action = app(GenerateInvoice::class);

    // Defence-in-depth: the FormRequest is not the only guard. A non-HTTP caller
    // (the recurring-billing scheduler, the future EnrollmentCreated listener)
    // reaches the Action directly and must be refused the same way.
    expect(fn () => $action->handle($enrollment->uuid, []))
        ->toThrow(BusinessRuleException::class)
        ->and(fn () => $action->handle($enrollment->uuid, [
            new InvoiceLineSpec('Zero', Money::fromKobo(0)),
        ]))->toThrow(BusinessRuleException::class)
        ->and(fn () => $action->handle($enrollment->uuid, [
            new InvoiceLineSpec('Negative', Money::fromKobo(-500)),
        ]))->toThrow(BusinessRuleException::class);

    expect(DB::table('finance_invoices')->count())->toBe(0);
});

it('makes a mixed-currency invoice impossible by construction (Money::plus throws)', function () {
    [$school, $admin, $student] = slice2Setup();
    $enrollment = slice2Enrollment($school, $student);

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment->uuid,
            'lines' => [
                ['description' => 'NGN line', 'amount_minor' => 1000, 'currency' => 'NGN'],
                ['description' => 'USD line', 'amount_minor' => 1000, 'currency' => 'USD'],
            ],
        ])->assertStatus(500); // InvalidArgumentException from the VO — never a silent bad total

    expect(DB::table('finance_invoices')->count())->toBe(0); // nothing persisted
});

// ------------------------------------------------- VOID read-side gate

it('VOID GATE — a voided invoice leaves the balance unchanged, drops out of default totals, and appears only on the audit view', function () {
    [$school, $admin, $student] = slice2Setup();
    $voided = slice2Enrollment($school, $student);
    $live = slice2Enrollment($school, $student);

    $act = fn () => $this->actingAs($admin)->withSession(['school_id' => $school->id]);

    $voidedUuid = $act()->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $voided->uuid, 'lines' => [['description' => 'Voided', 'amount_minor' => 150000]],
    ])->assertCreated()->json('id');

    $act()->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $live->uuid, 'lines' => [['description' => 'Live', 'amount_minor' => 75000]],
    ])->assertCreated();

    $balanceBefore = (int) DB::table('finance_ledger_transactions')->where('student_id', $student->id)->sum('amount_minor');
    expect($balanceBefore)->toBe(225000);

    $act()->postJson("/api/v1/finance/invoices/{$voidedUuid}/cancel", ['reason' => 'entered in error'])->assertOk();

    // 1 — the ledger balance moved by exactly the reversal, and the void's own
    //     charge+reversal net to zero, so only the live invoice remains owed.
    expect((int) DB::table('finance_ledger_transactions')->where('student_id', $student->id)->sum('amount_minor'))
        ->toBe(75000);

    // 2 — default read: the void is absent and does not count toward billed total.
    $default = $act()->getJson("/api/v1/finance/students/{$student->uuid}/invoices")->assertOk();
    $default->assertJsonPath('billed_total.amount_minor', 75000)
        ->assertJsonCount(1, 'invoices')
        ->assertJsonPath('invoices.0.status', 'issued');

    // 3 — audit read: the void IS present, and the row was never deleted.
    $audit = $act()->getJson("/api/v1/finance/students/{$student->uuid}/invoices?include_void=1")->assertOk();
    $audit->assertJsonPath('billed_total.amount_minor', 225000)
        ->assertJsonCount(2, 'invoices');

    expect(collect($audit->json('invoices'))->pluck('status')->sort()->values()->all())
        ->toBe(['issued', 'void']);
});

// ------------------------------------------------- cross-School isolation

it('ISOLATION — an enrollment from another School cannot be billed from this one', function () {
    [$schoolA, $adminA] = slice2Setup();

    // A second School with its own student and enrollment.
    $schoolB = School::factory()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id, 'first_name' => 'Bola', 'last_name' => 'Ade']);
    $enrollmentB = slice2Enrollment($schoolB, $studentB);

    // NOTE why this test has to exist: `student_curricula` carries no school_id and
    // StudentCurriculum is unscoped, so the ACL lookup happily resolves School B's
    // ENROLLMENT ROW while acting in School A. Nothing in the schema stops it — the
    // Action's explicit cross-School guard is the only thing that does.
    //
    // Verified mechanism (probed, not assumed): Student and Curriculum are both
    // School-scoped, so under A's context B's relations resolve to null and the
    // adapter reports school 0 rather than B. The rejection therefore comes from
    // the "undeterminable School" branch, not from a literal B ≠ A comparison —
    // fail-closed either way, which is the property that matters.
    $this->actingAs($adminA)->withSession(['school_id' => $schoolA->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollmentB->uuid,
            'lines' => SLICE2_LINES,
        ])->assertStatus(422);

    expect(DB::table('finance_invoices')->count())->toBe(0)
        ->and(DB::table('finance_ledger_transactions')->count())->toBe(0);
});

// ------------------------------------------------- duplicate guard

it('DUPLICATE GUARD — a second active invoice for the same enrollment is rejected', function () {
    [$school, $admin, $student] = slice2Setup();
    $enrollment = slice2Enrollment($school, $student);
    $act = fn () => $this->actingAs($admin)->withSession(['school_id' => $school->id]);

    $act()->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => SLICE2_LINES])
        ->assertCreated();

    $act()->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => SLICE2_LINES])
        ->assertStatus(422);

    expect(DB::table('finance_invoices')->count())->toBe(1);
});

it('DUPLICATE GUARD — after voiding, the enrollment can be billed fresh (policy: repeat bills fresh)', function () {
    [$school, $admin, $student] = slice2Setup();
    $enrollment = slice2Enrollment($school, $student);
    $act = fn () => $this->actingAs($admin)->withSession(['school_id' => $school->id]);

    $first = $act()->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => SLICE2_LINES])
        ->assertCreated()->json('id');

    $act()->postJson("/api/v1/finance/invoices/{$first}/cancel", ['reason' => 'wrong fees'])->assertOk();

    // The voided row still exists (append-only) — a naive UNIQUE(school_id,
    // student_curriculum_id) would forbid this re-bill. The NULL-ing generated
    // key is exactly what permits it.
    $act()->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => SLICE2_LINES])
        ->assertCreated();

    expect(DB::table('finance_invoices')->count())->toBe(2)
        ->and(DB::table('finance_invoices')->whereNotNull('active_enrollment_key')->count())->toBe(1);
});

it('DUPLICATE GUARD BITE-PROOF — the DB unique index rejects it even when the app pre-check is bypassed', function () {
    [$school, $admin, $student] = slice2Setup();
    $enrollment = slice2Enrollment($school, $student);

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => SLICE2_LINES])
        ->assertCreated();

    $existing = DB::table('finance_invoices')->first();

    // Raw insert — the Action's pre-check never runs. Only the generated column +
    // unique index stands between this and a duplicate active invoice.
    expect(fn () => DB::table('finance_invoices')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $existing->school_id,
        'student_id' => $existing->student_id,
        'student_curriculum_id' => $existing->student_curriculum_id,
        'number' => $existing->number + 1,
        'status' => 'issued',
        'billed_to_name' => 'Ada Obi',
        'academic_context' => $existing->academic_context,
        'total_minor' => 1000,
        'total_currency' => 'NGN',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(DB::table('finance_invoices')->count())->toBe(1);
});
