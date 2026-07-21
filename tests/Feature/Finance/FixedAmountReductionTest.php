<?php

use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Http\Resources\InvoiceResource;
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
use Illuminate\Testing\TestResponse;

/**
 * Fixed-amount, BILLING-TIME reductions (§5) — waiver/discount as signed lines.
 *
 * The sign carries the arithmetic, `kind` carries the meaning, and the total fold is a
 * literal signed SUM that never branches on kind. F6 is untouched: the equality is
 * established in GenerateInvoice's transaction and frozen by the same trigger as before.
 */
uses(RefreshDatabase::class);

// Routes authorize by GRANT (finance.access), not role name — the locally-fabricated
// admin needs the canonical grant map to reach the code under test.
beforeEach(fn () => (new RbacSeeder)->run());

function reductionSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id]);
    $enrollment = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));

    return [$school, $admin, $enrollment];
}

/** POST an invoice with the given raw line payloads. */
function postInvoice(array $lines): TestResponse
{
    [$school, $admin, $enrollment] = reductionSetup();

    return test()->actingAs($admin)
        ->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment->uuid,
            'lines' => $lines,
        ]);
}

it('FOLD — total is the SIGNED sum; both lines persist, neither is netted away', function () {
    postInvoice([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Sibling discount', 'amount_minor' => -150000, 'kind' => 'discount', 'note' => 'Two siblings enrolled'],
    ])->assertCreated();

    $invoice = Invoice::query()->firstOrFail();

    // 500000 + (-150000). No special case, no branch on kind.
    expect($invoice->total->toKobo())->toBe(350000);

    // §5: the FULL FEE survives as its own snapshot line. A netted single line would
    // satisfy the total and violate the statement contract — so assert both rows.
    $lines = DB::table('finance_invoice_lines')->where('invoice_id', $invoice->id)->get();
    expect($lines)->toHaveCount(2)
        ->and($lines->firstWhere('kind', 'charge')->amount_minor)->toEqual(500000)
        ->and($lines->firstWhere('kind', 'discount')->amount_minor)->toEqual(-150000)
        ->and($lines->firstWhere('kind', 'discount')->note)->toBe('Two siblings enrolled')
        // …and the total equals the signed sum of what is actually stored.
        ->and($lines->sum('amount_minor'))->toEqual($invoice->total->toKobo());
});

it('F6 STILL BITES on an invoice that HAS reduction lines', function () {
    // The point: the new line shape did not weaken the invariant it sits next to.
    postInvoice([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Waiver', 'amount_minor' => -100000, 'kind' => 'waiver'],
    ])->assertCreated();

    $invoice = Invoice::query()->firstOrFail();

    expect(fn () => DB::table('finance_invoices')
        ->where('id', $invoice->id)
        ->update(['total_minor' => 1]))
        ->toThrow(QueryException::class);

    expect(Invoice::query()->firstOrFail()->total->toKobo())->toBe(400000);
});

it('NON-NEGATIVE INVARIANT — zero is allowed, below zero is rejected', function () {
    // Exactly zero: a fully-waived fee is legitimate.
    postInvoice([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Full scholarship', 'amount_minor' => -500000, 'kind' => 'waiver'],
    ])->assertCreated();

    expect(Invoice::query()->firstOrFail()->total->toKobo())->toBe(0);

    // One kobo below: the School would owe the student, which is a credit note, not an
    // invoice. Rejected under the settled 422 convention.
    postInvoice([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Over-waiver', 'amount_minor' => -500001, 'kind' => 'waiver'],
    ])->assertStatus(422);

    expect(DB::table('finance_invoices')->count())->toBe(1);
});

it('SCOPED RELAXATION — a negative CHARGE is still rejected; a negative reduction is not', function () {
    // The discriminating test. The old rule was "every line must be positive"; relaxing
    // it wholesale would let a negative charge through. Each half must hold separately.
    postInvoice([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Sneaky negative charge', 'amount_minor' => -1000],
    ])->assertStatus(422);

    // …while the same amount, declared as a reduction, is accepted.
    postInvoice([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Bursary', 'amount_minor' => -1000, 'kind' => 'waiver'],
    ])->assertCreated();

    // And the mirror: a POSITIVE reduction is rejected — a "waiver" that adds money is
    // a sign error, not a waiver.
    postInvoice([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Backwards waiver', 'amount_minor' => 1000, 'kind' => 'waiver'],
    ])->assertStatus(422);
});

it('a ZERO line is rejected for either kind — it carries no arithmetic and no meaning', function () {
    postInvoice([['description' => 'Nothing', 'amount_minor' => 0]])->assertStatus(422);
    postInvoice([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Empty waiver', 'amount_minor' => 0, 'kind' => 'waiver'],
    ])->assertStatus(422);
});

it('DISPLAY — the API returns full fee and reduction as separate tagged lines, never a net', function () {
    postInvoice([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Staff discount', 'amount_minor' => -50000, 'kind' => 'discount'],
    ])->assertCreated();

    $invoice = Invoice::query()->with('lines')->firstOrFail();
    $payload = (new InvoiceResource($invoice))->toArray(request());
    $lines = collect($payload['lines']->resolve());

    // Two lines out, each carrying its kind, so the client groups charges above and
    // reductions beneath WITHOUT recomputing — §5 satisfied structurally.
    expect($lines)->toHaveCount(2)
        ->and($lines->firstWhere('kind', 'charge')['amount']->toKobo())->toBe(500000)
        ->and($lines->firstWhere('kind', 'discount')['amount']->toKobo())->toBe(-50000)
        // The net exists ONLY as the invoice total, never as a line.
        ->and($payload['total']->toKobo())->toBe(450000)
        ->and($lines->pluck('amount')->map->toKobo()->contains(450000))->toBeFalse();
});

it('APPEND-ONLY intact — a reduction is a new line, and lines still cannot be mutated', function () {
    postInvoice([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Waiver', 'amount_minor' => -100000, 'kind' => 'waiver'],
    ])->assertCreated();

    $charge = DB::table('finance_invoice_lines')->where('kind', 'charge')->first();

    // The fee line was never touched to apply the reduction…
    expect($charge->amount_minor)->toEqual(500000);

    // …and the immutability trigger still refuses any line UPDATE.
    expect(fn () => DB::table('finance_invoice_lines')
        ->where('id', $charge->id)
        ->update(['amount_minor' => 1]))
        ->toThrow(QueryException::class);
});

it('BILLING-TIME ONLY — there is exactly one line-INSERT path, inside the creation flow', function () {
    // A STRUCTURAL guard, and deliberately labelled as such. The whole slice is cheap
    // only because no route adds a line to an issued invoice: such a route would insert
    // after the total is frozen, turning F6's residual gap (b) from a tamper vector into
    // an operational path and forcing the deferred seal. This test fails the moment a
    // future slice adds one, which is exactly when someone needs to stop and think.
    $insertSites = [];
    foreach (glob(app_path('Finance/**/*.php'), GLOB_BRACE) + glob(app_path('Finance/*.php')) as $file) {
        foreach (file($file) as $line) {
            if (preg_match('/lines\(\)->create|InvoiceLine::(create|insert|forceCreate)/', $line)) {
                $insertSites[] = basename($file).': '.trim($line);
            }
        }
    }

    expect($insertSites)->toHaveCount(1)
        ->and($insertSites[0])->toStartWith('GenerateInvoice.php');
});

it('kind defaults to charge — every pre-existing line is one, which is why the column needed no backfill', function () {
    postInvoice([['description' => 'Tuition', 'amount_minor' => 500000]])->assertCreated();

    $line = DB::table('finance_invoice_lines')->first();
    expect($line->kind)->toBe(InvoiceLineKind::Charge->value);
});
