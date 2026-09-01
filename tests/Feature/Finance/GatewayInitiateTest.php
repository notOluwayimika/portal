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
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        // The minimum has NO default in config/finance.php — deliberately. Every arm that is not
        // about the minimum sets it explicitly, so an unset value can never be the silent reason a
        // test passes or fails.
        'finance.gateway.minimum_part_payment_minor' => 300_000,
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
            // NULL means Internal Audit has not released it to the payer.
            'reviewed_at' => $reviewed ? now() : null,
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

    $response->assertJsonPath('bill.amount_minor', 10_000_000)
        ->assertJsonPath('amount.amount_minor', 10_162_437);
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

    gitPost($invoice, $guardian, 299_999)->assertStatus(422);
    expect(DB::table('finance_gateway_transactions')->count())->toBe(0);

    // The boundary from the accepting side, so an off-by-one reds in both directions.
    gitPost($invoice, $guardian, 300_000)->assertCreated();
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
