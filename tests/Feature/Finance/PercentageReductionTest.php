<?php

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
 * Percentage waiver/discount — the consumer of Money::percentage. A percentage line is
 * resolved AT CREATION into a concrete signed reduction line, stored as the exact naira
 * figure (never "10%"), folded into the total exactly like a fixed-amount reduction.
 * F6 is untouched: still a signed line at creation, frozen by the same trigger.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

function pctSetup(): array
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

    // From S1 3b every reduction line must cite an active, non-approval-requiring policy (the DB
    // reduction_guard). These tests exercise percentage MATH (rounding, gross base, F6), not the
    // citation — postPct injects a valid policy into each reduction line so their bite is unchanged.
    $policy = ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create([
        'school_id' => $school->id, 'name' => 'Reductions', 'basis' => 'percent', 'percent' => 10,
        'requires_approval' => false, 'status' => 'active',
    ]));

    return [$school, $admin, $enrollment, $policy];
}

function postPct(array $lines): TestResponse
{
    [$school, $admin, $enrollment, $policy] = pctSetup();

    // Provenance is filled in HERE and not in each arm's literal, for both fields and for the same
    // reason: the rows they name do not exist until `pctSetup()` has run, so a literal calling
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

it('resolves a percentage waiver into a concrete signed line, stored in naira not percent', function () {
    postPct([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Staff discount', 'kind' => 'discount', 'percent' => 10, 'note' => 'Staff child'],
    ])->assertCreated();

    $invoice = Invoice::query()->firstOrFail();
    // 10% of 500000 = 50000. Total = 500000 - 50000.
    expect($invoice->total->toKobo())->toBe(450000);

    $reduction = DB::table('finance_invoice_lines')->where('kind', 'discount')->first();
    // The STORED value is the concrete naira reduction, negative — never a percent.
    expect($reduction->amount_minor)->toEqual(-50000)
        ->and($reduction->note)->toBe('Staff child');
});

it('UNEVEN percentage reconciles to the penny (reduction + remaining == full charge)', function () {
    // 33% of 1001 kobo = 330.33 → 330 (banker's, ordinary). Full − reduction must be
    // exact: 1001 − 330 = 671, and 330 + 671 = 1001. No penny created or lost.
    postPct([
        ['description' => 'Tuition', 'amount_minor' => 1001],
        ['description' => 'Bursary', 'kind' => 'waiver', 'percent' => 33],
    ])->assertCreated();

    $invoice = Invoice::query()->with('lines')->firstOrFail();
    $charge = $invoice->lines->firstWhere('kind', 'charge')->amount->toKobo();
    $reduction = $invoice->lines->firstWhere('kind', 'waiver')->amount->toKobo();

    expect($reduction)->toBe(-330)
        ->and($charge + $reduction)->toBe($invoice->total->toKobo())   // fold is exact
        ->and($charge + $reduction)->toBe(671);                        // reconciles
});

it('BANKER-ROUNDS the resolved reduction on a .5 boundary', function () {
    // 50% of 5 kobo = 2.5 → 2 (even). A half-up consumer would store -3 and net 2.
    postPct([
        ['description' => 'Tuition', 'amount_minor' => 5],
        ['description' => 'Half waiver', 'kind' => 'waiver', 'percent' => 50],
    ])->assertCreated();

    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->total->toKobo())->toBe(3)   // 5 - 2, not 5 - 3
        ->and(DB::table('finance_invoice_lines')->where('kind', 'waiver')->value('amount_minor'))->toEqual(-2);
});

it('percentage is against GROSS CHARGES, not a single line', function () {
    // 10% of (300000 + 200000) = 50000. Documented semantic: % reduces the whole bill.
    postPct([
        ['description' => 'Tuition', 'amount_minor' => 300000],
        ['description' => 'Boarding', 'amount_minor' => 200000],
        ['description' => 'Sibling discount', 'kind' => 'discount', 'percent' => 10],
    ])->assertCreated();

    expect(Invoice::query()->firstOrFail()->total->toKobo())->toBe(450000)
        ->and(DB::table('finance_invoice_lines')->where('kind', 'discount')->value('amount_minor'))->toEqual(-50000);
});

it('NON-NEGATIVE total holds — 100% brings it to zero, and a percent cannot exceed the charge', function () {
    // 100% waiver = exactly zero. Legitimate (a full scholarship).
    postPct([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Full scholarship', 'kind' => 'waiver', 'percent' => 100],
    ])->assertCreated();
    expect(Invoice::query()->firstOrFail()->total->toKobo())->toBe(0);

    // The FormRequest bounds percent at 100, so a single percent can never push below
    // zero — the input is rejected before it reaches the domain.
    postPct([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Impossible', 'kind' => 'waiver', 'percent' => 150],
    ])->assertStatus(422);
});

it('F6 STILL BITES on an invoice built with a percentage reduction', function () {
    postPct([
        ['description' => 'Tuition', 'amount_minor' => 500000],
        ['description' => 'Discount', 'kind' => 'discount', 'percent' => 20],
    ])->assertCreated();

    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->total->toKobo())->toBe(400000)
        ->and(fn () => DB::table('finance_invoices')->where('id', $invoice->id)->update(['total_minor' => 1]))
        ->toThrow(QueryException::class);
});

it('a percentage on a CHARGE line is rejected — percent is a reduction concept', function () {
    postPct([
        ['description' => 'Tuition', 'percent' => 10],  // kind defaults to charge
    ])->assertStatus(422);
});

it('a percentage with no charge to reduce is rejected', function () {
    // Only a percentage reduction, no charge line: nothing to take a percentage of.
    //
    // ASSERTS THE MESSAGE, NOT THE STATUS, AND THAT CHANGED IN U8. A bare `assertStatus(422)` was
    // sufficient while a reduction line could only ever be refused by the domain — a bogus
    // `discount_policy_id` was an integer nobody validated, so it reached the DB guard, and this arm's
    // 422 could only have come from GenerateInvoice. Since U8 commit 1 the policy id is a uuid with an
    // existence rule, so an unresolvable one is a VALIDATION 422, and a status-only assertion passes
    // whether or not the rule this arm is named for ever fires. Demonstrated: replacing `$policy->uuid`
    // in `postPct()` with a uuid that resolves to nothing reds 12 of the 17 arms in these two files and
    // leaves this one green.
    //
    // The second expectation is the guard against that recurring. If the request is refused at the edge
    // for a policy the fixture is supposed to have made valid, this arm says so instead of quietly
    // reporting success for the wrong reason.
    $response = postPct([
        ['description' => 'Orphan discount', 'kind' => 'discount', 'percent' => 10],
    ])->assertStatus(422);

    // The error bag is keyed by the LITERAL string 'lines.0.discount_policy_id', dots and all, so it is
    // read with array_key_exists rather than a dot path — `json('errors.lines.0.…')` would traverse into
    // nested keys that do not exist and report absence for the wrong reason.
    $errors = (array) $response->json('errors');

    expect((string) $response->json('message'))->toBe('A percentage reduction needs at least one charge line to reduce.')
        ->and(array_key_exists('lines.0.discount_policy_id', $errors))->toBeFalse(
            'The reduction line was refused at the EDGE, over its discount policy — so this arm’s 422 '
            .'says nothing about the rule it is named for. postPct() is supposed to inject a policy that '
            .'resolves.');
});
