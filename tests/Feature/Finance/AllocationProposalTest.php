<?php

use App\Enums\Permission;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordAccountPayment;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Models\BankAccount;
use App\Finance\Models\FeeItem;
use App\Finance\Models\Invoice;
use App\Finance\Services\AllocationProposal;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * U10 COMMIT 1 — THE READ SIDE. What the engine would do with a payment's unallocated remainder,
 * and nothing that writes.
 *
 * Every arm below is a proof about a decision that could have gone the other way and been wrong
 * silently: the ORDER of the proposal (oldest invoice first, which is the only settlement order in
 * the system per ADR 0048 D2), what is EXCLUDED from it (settled and void), what is LISTED BUT
 * BLOCKED rather than hidden (cross-currency), and the THREE-VALUED bank-account destination whose
 * middle value — "not recorded" — is the one a two-state flag would render as agreement.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array{0: School, 1: User, 2: Student} */
function apxSetup(): array
{
    $school = School::factory()->create();

    // The seeded `accounts_officer` — the seat that now holds finance.payment.allocate. Using the
    // real role rather than a hand-assembled one is what makes the gate arm below a statement about
    // the SEEDER's grant map and not about a permission this test gave itself.
    $officer = User::factory()->create(['school_id' => $school->id]);
    $officer->grantSchoolAccess($school, 'accounts_officer');
    $officer->flushSchoolAccessCache();

    return [$school, $officer, Student::factory()->create(['school_id' => $school->id])];
}

/**
 * One invoice of $kobo on a FRESH enrollment episode. Fresh every time because "at most one active
 * SCHEDULED invoice per episode" is a unique index, and this file needs several open invoices for
 * one student at once.
 *
 * @param  list<InvoiceLineSpec>|null  $lines
 */
function apxInvoice(School $school, Student $student, int $kobo, ?array $lines = null, string $currency = 'NGN'): Invoice
{
    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]);

    return app(GenerateInvoice::class)->handle(
        $enrollment->uuid,
        $lines ?? [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo, $currency))],
        InvoiceKind::Scheduled,
    );
}

/** A second, DIFFERENT bank account in $school — testBankAccountId() always returns the same one. */
function apxOtherAccount(School $school): BankAccount
{
    return BankAccount::withoutGlobalScopes()->firstOrCreate(
        ['school_id' => $school->id, 'account_number' => 'OTHER-'.$school->id],
        ['label' => 'Second account', 'bank_name' => 'Other Bank'],
    );
}

/** A fee item pointing at $account, so an invoice line citing it has a resolvable destination. */
function apxFeeItem(School $school, BankAccount $account, string $description): FeeItem
{
    return ActiveSchool::runFor($school->id, function () use ($school, $account, $description) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027 '.$description, 'slug' => 'sess-'.Str::random(8), 'is_current' => false,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
            'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2), 'status' => 'active',
        ]);
        $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'Lvl '.Str::random(4), 'order' => 1]);

        $schedule = app(CreateFeeSchedule::class)->handle($term->id, $level->id, 'v1', [
            ['description' => $description, 'amount_minor' => 100000, 'bank_account_id' => $account->uuid],
        ]);

        return $schedule->items->first();
    });
}

it('PROOF 1 — the proposal is OLDEST INVOICE FIRST and capped at each invoice’s outstanding', function () {
    [$school, $officer, $student] = apxSetup();

    $proposal = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $first = apxInvoice($school, $student, 3000);
        $second = apxInvoice($school, $student, 5000);
        apxInvoice($school, $student, 9000);

        // 10000 banked on the ACCOUNT — no invoice named, so nothing is allocated at record time.
        $payment = app(RecordAccountPayment::class)->handle(
            $student->id, Money::fromKobo(10000), 'Parent', $officer, SchoolDay::today(), testBankAccountId($school->id),
        );

        return [app(AllocationProposal::class)->for($payment), $first, $second];
    });

    [$result, $first, $second] = $proposal;

    expect($result['payment']['unallocated']->toKobo())->toBe(10000)
        ->and($result['payment']['allocated']->toKobo())->toBe(0);

    // 3000 → 5000 → 2000 of the third. The ORDER is the assertion: a newest-first walk would have
    // filled the 9000 invoice first and left the two older ones untouched, which is exactly the
    // settlement order ADR 0048 D2 deleted.
    expect(array_map(fn ($row) => [$row['display_number'], $row['proposed']->toKobo()], $result['invoices']))
        ->toBe([
            [$first->displayNumber(), 3000],
            [$second->displayNumber(), 5000],
            [$result['invoices'][2]['display_number'], 2000],
        ]);

    expect($result['proposed_total']->toKobo())->toBe(10000)
        ->and($result['unproposed_remainder']->toKobo())->toBe(0);
});

it('PROOF 2 — a remainder larger than every open invoice reports what it could NOT place', function () {
    [$school, $officer, $student] = apxSetup();

    $result = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        apxInvoice($school, $student, 2000);

        $payment = app(RecordAccountPayment::class)->handle(
            $student->id, Money::fromKobo(7000), 'Parent', $officer, SchoolDay::today(), testBankAccountId($school->id),
        );

        return app(AllocationProposal::class)->for($payment);
    });

    // 2000 placed, 5000 stated as unplaceable rather than silently dropped: the operator is not
    // left subtracting two figures to find out where the rest of their money went.
    expect($result['proposed_total']->toKobo())->toBe(2000)
        ->and($result['unproposed_remainder']->toKobo())->toBe(5000);
});

it('PROOF 3 — a SETTLED invoice and a payment’s own prior allocations are both excluded', function () {
    [$school, $officer, $student] = apxSetup();

    $result = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $settled = apxInvoice($school, $student, 2000);
        $open = apxInvoice($school, $student, 6000);

        // 8000 against the 2000 invoice: 2000 allocates, 6000 banks as the remainder to direct.
        $payment = app(RecordPayment::class)->handle(
            $settled, Money::fromKobo(8000), 'Parent', $officer, SchoolDay::today(), testBankAccountId($school->id),
        );

        return [app(AllocationProposal::class)->for($payment), $open];
    });

    [$proposal, $open] = $result;

    expect($proposal['payment']['allocated']->toKobo())->toBe(2000)
        ->and($proposal['payment']['unallocated']->toKobo())->toBe(6000);

    // The settled invoice is gone from the list entirely — an allocation to it is arithmetic with
    // no meaning and the invoice-axis trigger would refuse it.
    expect(array_column($proposal['invoices'], 'display_number'))->toBe([$open->displayNumber()])
        ->and($proposal['invoices'][0]['proposed']->toKobo())->toBe(6000);
});

it('PROOF 4 — the bank-account destination is THREE-VALUED: matches / differs / unrecorded', function () {
    [$school, $officer, $student] = apxSetup();

    $result = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $landed = BankAccount::withoutGlobalScopes()->find(testBankAccountId($school->id));
        $other = apxOtherAccount($school);

        $itemHere = apxFeeItem($school, $landed, 'Tuition');
        $itemThere = apxFeeItem($school, $other, 'Transport');

        // Same account the money lands in.
        apxInvoice($school, $student, 1000, [new InvoiceLineSpec('Tuition', Money::fromKobo(1000), $itemHere->id)]);
        // A DIFFERENT account — cut brief line 307's ordinary term-one occurrence.
        apxInvoice($school, $student, 1000, [new InvoiceLineSpec('Transport', Money::fromKobo(1000), $itemThere->id)]);
        // Free text, no fee item behind it: the shape EVERY line has today, because line entry is
        // manual with no catalog (new-invoice-modal.tsx). This must NOT read as agreement.
        apxInvoice($school, $student, 1000, [new InvoiceLineSpec('Textbooks', Money::fromKobo(1000))]);

        $payment = app(RecordAccountPayment::class)->handle(
            $student->id, Money::fromKobo(3000), 'Parent', $officer, SchoolDay::today(), $landed->id,
        );

        return app(AllocationProposal::class)->for($payment);
    });

    expect(array_column(array_column($result['invoices'], 'destination'), 'state'))
        ->toBe(['matches', 'differs', 'unrecorded']);

    // The differing row NAMES the account it was destined for — "there is a mismatch" without
    // saying which account is a warning the operator cannot act on.
    expect($result['invoices'][1]['destination']['accounts'])
        ->toBe([['label' => 'Second account', 'bank_name' => 'Other Bank']]);

    // And the unrecorded row says how much of the invoice it could not read, so `matches` can never
    // be rendered unqualified on an invoice that is only partly resolvable.
    expect($result['invoices'][2]['destination']['charge_lines_without_destination'])->toBe(1)
        ->and($result['invoices'][0]['destination']['charge_lines_without_destination'])->toBe(0);
});

it('PROOF 4b — an invoice resolving to TWO accounts names only the DIFFERING one as the mismatch', function () {
    /*
     * THE COLD REVIEW'S SEVENTH FINDING. `state` is `differs` as soon as ONE resolved destination
     * disagrees, and the screen rendered the WHOLE of `accounts` under the sentence "Not the account
     * this money landed in." An invoice whose lines cite fee items on two accounts — one of them the
     * payment's — therefore named the MATCHING account under a claim that is false of it.
     *
     * § 8 of this branch's report recorded two-account invoices as handled by the read model and never
     * rendered. This is the arm that renders them, and `differing_accounts` is what the screen lists.
     */
    [$school, $officer, $student] = apxSetup();

    $result = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $landed = BankAccount::withoutGlobalScopes()->find(testBankAccountId($school->id));
        $other = apxOtherAccount($school);

        $itemHere = apxFeeItem($school, $landed, 'Tuition');
        $itemThere = apxFeeItem($school, $other, 'Transport');

        // ONE invoice, TWO charge lines, two different destinations — one of them the account the
        // money lands in.
        apxInvoice($school, $student, 2000, [
            new InvoiceLineSpec('Tuition', Money::fromKobo(1000), $itemHere->id),
            new InvoiceLineSpec('Transport', Money::fromKobo(1000), $itemThere->id),
        ]);

        $payment = app(RecordAccountPayment::class)->handle(
            $student->id, Money::fromKobo(2000), 'Parent', $officer, SchoolDay::today(), $landed->id,
        );

        return app(AllocationProposal::class)->for($payment);
    });

    $destination = $result['invoices'][0]['destination'];

    // One account disagrees, so the invoice as a whole differs.
    expect($destination['state'])->toBe('differs');

    // `accounts` is still the FULL picture — both destinations, because that is where this invoice's
    // money was meant to go and an operator directing it should see all of it.
    expect(collect($destination['accounts'])->pluck('label')->sort()->values()->all())
        ->toBe(['Second account', 'Test account']);

    // …and `differing_accounts` is only the one the sentence is TRUE of. Before this fix the screen
    // rendered the line above under "Not the account this money landed in", which named the account
    // the money IS in as the account it is not in.
    expect($destination['differing_accounts'])
        ->toBe([['label' => 'Second account', 'bank_name' => 'Other Bank']]);
});

it('PROOF 5 — a cross-currency invoice is LISTED AND BLOCKED, never hidden and never proposed', function () {
    [$school, $officer, $student] = apxSetup();

    $result = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        // THE USD INVOICE IS INSERTED RAW, and that is not a shortcut around a guard — it is the only
        // way to construct the state. SubledgerPoster refuses to post a charge in a currency other
        // than the account balance's ("Ledger currency NGN does not match account balance currency
        // USD"), so no sequence of real Actions can put a USD invoice and an NGN payment on one
        // student. The same reasoning PaymentAxisGuardTest states for its raw allocations: the row is
        // reachable by a restored dump, a bulk correction or a SQL console, and a read model that a
        // future cross-currency school will meet must not silently propose an allocation the
        // finance_allocation_not_over_payment_amount trigger will refuse.
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        DB::table('finance_invoices')->insert([
            'uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_curriculum_id' => $enrollment->id,
            'number' => 90501,
            'status' => 'issued',
            'kind' => 'supplementary',
            'billed_to_name' => 'Cross currency',
            'academic_context' => 'Raw',
            'total_minor' => 4000,
            'total_currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ngn = apxInvoice($school, $student, 4000);

        $payment = app(RecordAccountPayment::class)->handle(
            $student->id, Money::fromKobo(9000), 'Parent', $officer, SchoolDay::today(), testBankAccountId($school->id),
        );

        return [app(AllocationProposal::class)->for($payment), $ngn];
    });

    [$proposal, $ngn] = $result;

    expect($proposal['invoices'])->toHaveCount(2);

    $usd = $proposal['invoices'][0];
    expect($usd['allocatable'])->toBeFalse()
        ->and($usd['proposed']->toKobo())->toBe(0)
        ->and($usd['blocked_reason'])->toContain('USD');

    // The NGN invoice behind it still gets the proposal — one blocked row does not stop the walk.
    expect($proposal['invoices'][1]['display_number'])->toBe($ngn->displayNumber())
        ->and($proposal['invoices'][1]['proposed']->toKobo())->toBe(4000)
        ->and($proposal['unproposed_remainder']->toKobo())->toBe(5000);
});

it('PROOF 6 — the endpoint is gated on finance.payment.allocate, which accounts_officer holds and admin does not', function () {
    [$school, $officer, $student] = apxSetup();

    $payment = ActiveSchool::runFor($school->id, fn () => app(RecordAccountPayment::class)->handle(
        $student->id, Money::fromKobo(5000), 'Parent', $officer, SchoolDay::today(), testBankAccountId($school->id),
    ));

    $url = '/api/v1/finance/payments/'.$payment->uuid.'/allocation-proposal';

    // The seeded holder.
    $this->actingAs($officer)->withSession(['school_id' => $school->id])->getJson($url)->assertOk();

    // A finance-capable seat that does NOT hold it. `admin` carries finance.access, so this arm is
    // the one that shows the route is not merely behind the group gate.
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->grantSchoolAccess($school, 'admin');
    $admin->flushSchoolAccessCache();

    expect($admin->can(Permission::FINANCE_PAYMENT_ALLOCATE->value))->toBeFalse();

    $this->actingAs($admin)->withSession(['school_id' => $school->id])->getJson($url)->assertForbidden();
});

it('PROOF 7 — a payment in another School 404s at the binding, not at a check inside', function () {
    [$schoolA, $officerA, $studentA] = apxSetup();
    [$schoolB, $officerB] = apxSetup();

    $paymentA = ActiveSchool::runFor($schoolA->id, fn () => app(RecordAccountPayment::class)->handle(
        $studentA->id, Money::fromKobo(5000), 'Parent', $officerA, SchoolDay::today(), testBankAccountId($schoolA->id),
    ));

    // B's officer holds the ability. Isolation is not authorization (ADR 0036) and must refuse anyway.
    $this->actingAs($officerB)->withSession(['school_id' => $schoolB->id])
        ->getJson('/api/v1/finance/payments/'.$paymentA->uuid.'/allocation-proposal')
        ->assertNotFound();
});
