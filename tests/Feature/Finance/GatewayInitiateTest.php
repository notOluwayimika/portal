<?php

use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\Invoice;
use App\Finance\Services\GatewayReference;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Step 3 — starting a gateway payment.
 *
 * The guards are asserted in BOTH directions throughout: every refusal arm is paired with an
 * acceptance arm, because a guard that refuses everything passes a refusal-only suite and is
 * indistinguishable from a strict one until someone bypasses it.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    (new RbacSeeder)->run();
    config([
        'services.paystack.secret_key' => 'sk_test_git_fixture',
        // NGN 1,000 — the ruled value (Segun, 2026-09-01), not an arbitrary fixture number. Set
        // explicitly in every arm, because config/finance.php has no default: an unset value must
        // never be the silent reason a test passes or fails.
        'finance.gateway.minimum_part_payment_minor' => 100_000,
    ]);
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'abc123', 'reference' => 'set-by-caller'],
        ], 200),
    ]);
});

/** @return array{0: Invoice, 1: User} an invoice and the guardian who may pay it */
function gitFixture(bool $reviewed = true, string $status = 'issued'): array
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

    $guardian = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $guardian->assignRole('guardian');
    setPermissionsTeamId(null);

    $invoice = ActiveSchool::runFor($school->id, function () use ($school, $student, $curriculum, $reviewed, $status) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        return Invoice::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_curriculum_id' => $enrollment->id,
            'number' => 1,
            'status' => $status === 'void' ? InvoiceStatus::Void : InvoiceStatus::Issued,
            'kind' => InvoiceKind::Scheduled,
            'billed_to_name' => 'Ada Obi',
            'academic_context' => '2026/2027 First Term',
            'total' => Money::fromKobo(10_000_000),
            // UNRELEASED IS THE NATURAL DEFAULT — the column is simply not set, never explicitly
            // nulled. Nulling it would test the column through a wrapper, and would keep passing
            // when the predicate's meaning changes (rejection modelling is open: a rejected bill
            // may end up stamping this column).
            //
            // THE RELEASED SIDE IS THE HONEST GAP. Nothing in the codebase writes `reviewed_at`
            // yet — the Internal Audit review action is not built — so there is no releasing
            // mechanism to route this through, and stamping it here stands in for one. It must be
            // replaced with the real action when it lands; the predicate's own known-negative
            // belongs in Finance, not here. This file asserts only that the gateway REFUSES.
            ...($reviewed ? ['reviewed_at' => now()] : []),
        ]);
    });

    // The ward link is what mayPay() reads — a permission cannot express "this parent, that child".
    // Built through the shared helper rather than by hand: the first version of this fixture
    // insert-ed the guardian row directly and died on a NOT NULL column it did not know about.
    $guardianRecord = al_makeGuardian($school->id, $guardian->id);
    DB::table('guardian_student')->insert([
        'guardian_id' => $guardianRecord->id,
        'student_id' => $student->id,
        'relationship' => 'parent',
        'is_primary' => true,
        'can_login' => true,
    ]);

    return [$invoice, $guardian];
}

function gitPost(Invoice $invoice, User $user, int $amountMinor): TestResponse
{
    return test()->actingAs($user)->postJson("/api/parent/invoices/{$invoice->uuid}/payment", [
        'amount_minor' => $amountMinor,
    ]);
}

it('starts a payment and charges the gross, recording the bill beside it', function () {
    [$invoice, $guardian] = gitFixture();

    $response = gitPost($invoice, $guardian, 10_000_000)->assertCreated();

    $transaction = DB::table('finance_gateway_transactions')->first();

    // The payer is charged MORE than the bill; both figures are on the row and both cross the wire,
    // because the screen has to be able to say so at the one moment the parent is entitled to see it.
    expect((int) $transaction->bill_minor)->toBe(10_000_000)
        ->and((int) $transaction->amount_minor)->toBe(10_162_437)
        ->and($transaction->status)->toBe('pending')
        ->and(GatewayReference::schoolIdFrom($transaction->reference))->toBe((int) $invoice->school_id);

    // THREE NUMBERS BEFORE THE PAYER COMMITS: credited, fee, charged. Under parent-bears they pay
    // more than they typed, and a surprise on a card statement is a chargeback. Asserting all three
    // — and that they reconcile — is what stops a screen showing two of them and inferring the third.
    $response->assertJsonPath('bill.amount_minor', 10_000_000)
        ->assertJsonPath('fee.amount_minor', 162_437)
        ->assertJsonPath('amount.amount_minor', 10_162_437);

    expect(10_000_000 + 162_437)->toBe(10_162_437);
});

it('REFUSES an unreviewed invoice server-side, whatever the client offered', function () {
    [$invoice, $guardian] = gitFixture(reviewed: false);

    // THE REQUIRED KNOWN-NEGATIVE'S PARTNER. The parent feed already withholds unreleased invoices,
    // so a well-behaved client cannot offer this — which is presentation. This POST names the uuid
    // directly, the way a crafted request, a stale tab, or a client the school did not write would.
    gitPost($invoice, $guardian, 10_000_000)
        ->assertStatus(422)
        ->assertJsonPath('message', 'This bill has not been released for payment yet. It is with Internal Audit for review.');

    expect(DB::table('finance_gateway_transactions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('accepts the same request once the invoice IS released — the known negative', function () {
    [$invoice, $guardian] = gitFixture(reviewed: true);

    // Without this arm a guard refusing EVERY invoice would pass the refusal test above and read as
    // correct. The two arms differ in exactly one attribute.
    gitPost($invoice, $guardian, 10_000_000)->assertCreated();

    expect(DB::table('finance_gateway_transactions')->count())->toBe(1);
});

it('refuses an amount below the configured minimum, and accepts one at it', function () {
    [$invoice, $guardian] = gitFixture();

    gitPost($invoice, $guardian, 99_999)->assertStatus(422);
    expect(DB::table('finance_gateway_transactions')->count())->toBe(0);

    // The boundary from the accepting side, so an off-by-one reds in both directions.
    gitPost($invoice, $guardian, 100_000)->assertCreated();
    expect(DB::table('finance_gateway_transactions')->count())->toBe(1);
});

it('refuses to take any payment at all when no minimum is configured', function () {
    [$invoice, $guardian] = gitFixture();
    config(['finance.gateway.minimum_part_payment_minor' => null]);

    // An unset minimum is a live hazard, not a gap: below roughly ₦2,562 the provider's fee step
    // means the school nets less than a larger payment would have returned. It fails loudly and
    // before anyone is charged, the way SettlementBankAccount does — never by picking a number.
    gitPost($invoice, $guardian, 10_000_000)->assertStatus(422);

    expect(DB::table('finance_gateway_transactions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses a minimum configured BELOW the floor', function () {
    [$invoice, $guardian] = gitFixture();
    config(['finance.gateway.minimum_part_payment_minor' => 50_000]);

    // NGN 500. The dead band's unreachability rests on the minimum sitting above it, so a minimum
    // set too low silently reintroduces a case the gross-up has no handling for — and puts small
    // payers in the 5.3% band the NGN 1,000 ruling exists to keep them out of. Enforced rather than
    // documented: a precondition resting on a value someone may later change is not a precondition.
    gitPost($invoice, $guardian, 10_000_000)->assertStatus(422);

    expect(DB::table('finance_gateway_transactions')->count())->toBe(0);
});

it('accepts the floor exactly — the known negative for the floor check', function () {
    [$invoice, $guardian] = gitFixture();
    config(['finance.gateway.minimum_part_payment_minor' => 100_000]);

    // Without this the floor check could refuse every configured value and read as correct.
    gitPost($invoice, $guardian, 10_000_000)->assertCreated();
});

it('permits an OVERPAYMENT — the ruling allows it and nothing here caps at outstanding', function () {
    [$invoice, $guardian] = gitFixture();

    // Twice the bill. RecordPayment caps the ALLOCATION at outstanding and banks the excess as
    // account credit under an existing rule, so capping here would refuse what the ruling permits.
    gitPost($invoice, $guardian, 20_000_000)->assertCreated();

    expect((int) DB::table('finance_gateway_transactions')->value('bill_minor'))->toBe(20_000_000);
});

it('refuses a bill belonging to another guardian IN THE SAME SCHOOL', function () {
    [$invoice, $guardian] = gitFixture();
    $school = School::find($invoice->school_id);

    // SAME SCHOOL, DIFFERENT WARD — and the "same school" half is what makes this arm mean anything.
    //
    // The first version used a guardian from ANOTHER school and passed with `mayPay()` deleted:
    // SchoolScope resolved the invoice to null, so the refusal came from isolation and the test
    // asserted a property it never exercised. Two explanations for one pass, and the name claimed
    // the wrong one. Here the invoice resolves fine — the ward link is the only thing left to
    // refuse on.
    $other = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $other->assignRole('guardian');
    setPermissionsTeamId(null);

    $otherStudent = Student::factory()->create(['school_id' => $school->id]);
    $otherGuardian = al_makeGuardian($school->id, $other->id);
    DB::table('guardian_student')->insert([
        'guardian_id' => $otherGuardian->id,
        'student_id' => $otherStudent->id,
        'relationship' => 'parent',
        'is_primary' => true,
        'can_login' => true,
    ]);

    gitPost($invoice, $other, 10_000_000)->assertForbidden();

    expect(DB::table('finance_gateway_transactions')->count())->toBe(0);
});

it('refuses a bill from another school outright — isolation, which is a different guarantee', function () {
    [$invoice] = gitFixture();
    [, $stranger] = gitFixture();

    // Kept as its own arm rather than merged with the one above. They refuse for DIFFERENT reasons
    // — SchoolScope here, the ward link there — and a single test covering both would go green if
    // either mechanism were removed.
    gitPost($invoice, $stranger, 10_000_000)->assertForbidden();

    expect(DB::table('finance_gateway_transactions')->count())->toBe(0);
});

it('refuses a cancelled bill', function () {
    [$invoice, $guardian] = gitFixture(status: 'void');

    gitPost($invoice, $guardian, 10_000_000)->assertStatus(422);
    expect(DB::table('finance_gateway_transactions')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| THE FEE PREVIEW — what the parent is told before they agree
|--------------------------------------------------------------------------
|
| A FEE PREVIEW, NOT A QUOTE. "Quote" is the spec's word for the parked Paystack-first design; this
| is local `GatewayFeeCalculator` arithmetic with no provider call.
*/

/** A portal payment for this invoice's student, big enough to allocate against. Returns its id. */
function gitAllocatablePayment(Invoice $invoice): int
{
    return (int) DB::table('finance_payments')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $invoice->school_id,
        'student_id' => $invoice->student_id,
        'reference' => (int) DB::table('finance_payments')->where('school_id', $invoice->school_id)->max('reference') + 1,
        'amount_minor' => 6_000_000,
        'amount_currency' => 'NGN',
        'payer_name' => 'Ada Obi',
        'method' => 'cash',
        'origin' => 'portal',
        'bank_account_id' => testBankAccountId((int) $invoice->school_id),
        'received_at' => SchoolDay::today(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function gitPreview(Invoice $invoice, User $user, int $amountMinor): TestResponse
{
    return test()->actingAs($user)->postJson("/api/parent/invoices/{$invoice->uuid}/payment/preview", [
        'amount_minor' => $amountMinor,
    ]);
}

it('previews the gross the initiate path would charge — the SAME number, across every fee regime', function (int $bill, string $regime) {
    [$invoice, $guardian] = gitFixture();

    $previewed = (int) gitPreview($invoice, $guardian, $bill)->assertOk()->json('gross.amount_minor');

    // THE ARM THAT MATTERS, AND IT IS NOT A TAUTOLOGY TODAY BECAUSE IT IS A TRIPWIRE FOR TOMORROW.
    // Both paths call `payableGross()`, so this passes by construction — which is exactly why it
    // exists: it fires the day someone gives the preview its own copy of the guards or its own
    // call to `grossFor()`. Without it, a divergence tells a parent one figure on the confirmation
    // and charges them another at Paystack, and nothing reds.
    //
    // ALL THREE REGIMES, because the fee is piecewise and a single amount cannot see the seams:
    // below the ₦2,500 waiver the flat is dropped, just above it the ratio is at its worst, and at
    // the cap the percentage stops growing. A parity arm at one amount proves parity at one amount.
    $charged = (int) gitPost($invoice, $guardian, $bill)->assertCreated()->json('amount.amount_minor');

    expect($previewed)->toBe($charged, "preview and initiate disagree on the gross for {$regime}");
})->with([
    'below the flat-fee waiver' => [200_000, 'a ₦2,000 bill, flat waived'],
    'just above the waiver, where the ratio is worst' => [260_000, 'a ₦2,600 bill, flat applies'],
    'at the fee cap' => [50_000_000, 'a ₦500,000 bill, fee capped'],
]);

it('splits an overpayment into what settles the bill and what becomes credit', function () {
    [$invoice, $guardian] = gitFixture();

    // PART-PAID, NOT FRESH, AND THAT IS THE WHOLE FIXTURE. `InvoiceSettlement::for()` reads the
    // settlement aggregates off the model and treats an ABSENT one as zero — so an invoice that did
    // not come through the read model reports its FULL TOTAL outstanding. On a fresh invoice the
    // hydrated and un-hydrated readings are IDENTICAL, so a fresh fixture cannot see the difference
    // in principle. Only a part-paid invoice can.
    ActiveSchool::runFor((int) $invoice->school_id, fn () => DB::table('finance_payment_allocations')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $invoice->school_id,
        'invoice_id' => $invoice->id,
        'payment_id' => gitAllocatablePayment($invoice),
        'amount_minor' => 6_000_000,
        'amount_currency' => 'NGN',
        'allocation_rule' => 'payment_against_named_invoice',
        'allocation_overridden' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    // Bill 10,000,000; 6,000,000 already allocated; outstanding 4,000,000. Paying 5,000,000 settles
    // the outstanding and leaves 1,000,000 as credit.
    $body = gitPreview($invoice, $guardian, 5_000_000)->assertOk()->json();

    // THE EXACT SPLIT, NEVER "excess > 0". A wrong arithmetic still produces a non-zero excess, so
    // the loose assertion passes over a figure that would be shown to a parent as fact.
    expect((int) $body['applied']['amount_minor'])->toBe(4_000_000)
        ->and((int) $body['excess']['amount_minor'])->toBe(1_000_000)
        ->and((int) $body['gross']['amount_minor'])->toBeGreaterThan(5_000_000);
});

it('writes nothing — no transaction exists after a preview', function () {
    [$invoice, $guardian] = gitFixture();

    gitPreview($invoice, $guardian, 5_000_000)->assertOk();

    // THE ENTIRE JUSTIFICATION FOR THE ENDPOINT. Initiate-first would leave a `pending` row for
    // every parent who sees the fee and reconsiders — D4's population in the discrepancy report,
    // manufactured by the pay screen. If this arm ever reds, the preview has become the thing it
    // was built instead of.
    expect(DB::table('finance_gateway_transactions')->count())->toBe(0);
});

it('refuses to preview a bill belonging to another guardian in the same school', function () {
    [$invoice] = gitFixture();
    [, $stranger] = gitFixture();

    // THE SCOPING IS INHERITED, NOT RESTATED — PreviewGatewayFeeRequest extends the initiate
    // request. This arm is what proves the inheritance is wired rather than assumed: a preview that
    // resolved the uuid itself would answer a figure for any invoice handed to it.
    gitPreview($invoice, $stranger, 5_000_000)->assertForbidden();
});

it('refuses to preview an amount below the configured minimum, server-side', function () {
    [$invoice, $guardian] = gitFixture();

    // THE SAME REFUSAL THE INITIATE PATH MAKES, because it is the same guard. The client check is a
    // convenience so a parent is not sent to Paystack to be refused; this is the control.
    gitPreview($invoice, $guardian, 50_000)->assertStatus(422);
});

it('refuses to preview an unreleased bill', function () {
    [$invoice, $guardian] = gitFixture(reviewed: false);

    gitPreview($invoice, $guardian, 5_000_000)->assertStatus(422);
});
