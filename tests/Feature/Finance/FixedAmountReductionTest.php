<?php

use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Http\Resources\InvoiceResource;
use App\Finance\Models\DiscountPolicy;
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

    // From S1 3b every reduction line must cite an ACTIVE, non-approval-requiring policy (the DB
    // reduction_guard). These tests exercise the fixed-amount SIGN/FOLD arithmetic, not the citation —
    // so postInvoice injects a valid policy into each reduction line and the bite is unchanged.
    $policy = ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create([
        'school_id' => $school->id, 'name' => 'Reductions', 'basis' => 'percent', 'percent' => 10,
        'requires_approval' => false, 'status' => 'active',
    ]));

    return [$school, $admin, $enrollment, $policy];
}

/** POST an invoice with the given raw line payloads; reduction lines are auto-backed by an active policy. */
function postInvoice(array $lines): TestResponse
{
    [$school, $admin, $enrollment, $policy] = reductionSetup();

    // Provenance is filled in HERE and not in each arm's literal, for both fields and for the same
    // reason: the rows they name do not exist until `reductionSetup()` has run, so a literal calling
    // `testBankAccountUuid()` in an argument list would resolve against an empty `schools` table.
    // A reduction gets the active policy; a CHARGE gets a destination, which
    // `finance_invoice_lines_destination_guard` has required since S11 commit 2. Arms that set
    // either key explicitly keep their own value — that is what lets an arm aim at the field.
    $lines = array_map(function (array $line) use ($policy, $school) {
        if (($line['kind'] ?? 'charge') !== 'charge' && ! isset($line['discount_policy_id'])) {
            $line['discount_policy_id'] = $policy->uuid;
        }

        if (($line['kind'] ?? 'charge') === 'charge' && ! array_key_exists('bank_account_id', $line)) {
            $line['bank_account_id'] = testBankAccountUuid($school->id);
        }

        return $line;
    }, $lines);

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
    // NAMES THE FIELD, NOT JUST THE STATUS, AND THAT CHANGED IN U8. Both halves are refused by
    // `lines.*.amount_minor`'s `not_in:0` at the edge, and the arm now says so. A bare
    // `assertStatus(422)` was enough while nothing else about a reduction line could produce a
    // validation error; since U8 commit 1 an unresolvable `discount_policy_id` uuid can, so the waiver
    // half would have gone on passing with the zero rule deleted. Demonstrated: replacing
    // `$policy->uuid` in `postInvoice()` with a uuid that resolves to nothing reds 12 of the 17 arms in
    // these two files and leaves this one green.
    //
    // The `discount_policy_id` check on the second half is what keeps that from coming back: the
    // fixture's policy is supposed to resolve, so an error on that key means this 422 arrived for a
    // reason the arm is not about.
    postInvoice([['description' => 'Nothing', 'amount_minor' => 0]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.amount_minor');

    $waiver = postInvoice([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Empty waiver', 'amount_minor' => 0, 'kind' => 'waiver'],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.amount_minor');

    // Keyed by the literal string, dots included — a dot path would traverse instead of looking up.
    $errors = (array) $waiver->json('errors');

    expect(array_key_exists('lines.1.discount_policy_id', $errors))->toBeFalse(
        'The waiver line was refused over its discount policy rather than its zero amount, so this arm’s '
        .'422 says nothing about the rule it is named for. postInvoice() is supposed to inject a policy '
        .'that resolves.');
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
