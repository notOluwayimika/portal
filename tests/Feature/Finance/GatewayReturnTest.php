<?php

use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\GatewayTransaction;
use App\Finance\Models\Invoice;
use App\Finance\Models\SchoolFinanceSettings;
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
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * WHERE PAYSTACK SENDS THE PAYER BACK — §6 step 6.
 *
 * THE QUERY STRING IS NOT EVIDENCE. Every arm here proves the same underlying property from a
 * different angle: what the payer's browser carries names a transaction and nothing more, and every
 * fact about the money comes from `verify()`.
 */
uses(RefreshDatabase::class, WithFaker::class);

const GRT_SECRET = 'sk_test_grt_fixture';

beforeEach(function () {
    config(['services.paystack.secret_key' => GRT_SECRET]);
    Http::preventStrayRequests();
    $this->seed(RbacSeeder::class);
});

/** @return array{0: GatewayTransaction, 1: User} */
function grtFixture(int $amountKobo = 10_000_000): array
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

    $guardian = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $guardian->assignRole('guardian');
    setPermissionsTeamId(null);

    // THE WARD LINK IS A REAL PRECONDITION, not fixture noise. The return controller authorises with
    // `mayPay()` — the same predicate the initiate path uses — so a user holding the guardian ROLE
    // but no relationship to this student is correctly told `unknown`. A fixture without the pivot
    // would prove only that the arms can reach a refusal.
    $guardianRecord = al_makeGuardian($school->id, $guardian->id);
    $guardianRecord->students()->attach($student->id, [
        'relationship' => 'mother', 'is_primary' => true, 'can_login' => true,
    ]);

    $transaction = ActiveSchool::runFor($school->id, function () use ($school, $student, $curriculum, $amountKobo) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_curriculum_id' => $enrollment->id,
            'number' => 1,
            'status' => InvoiceStatus::Issued,
            'kind' => InvoiceKind::Scheduled,
            'billed_to_name' => 'Ada Obi',
            'academic_context' => '2026/2027 First Term',
            'total' => Money::fromKobo($amountKobo),
            'reviewed_at' => now(),
        ]);

        SchoolFinanceSettingsForGrt($school->id);

        return GatewayTransaction::create([
            'school_id' => $school->id,
            'invoice_id' => $invoice->id,
            'provider' => 'paystack',
            'reference' => GatewayReference::mint((int) $school->id),
            'amount' => Money::fromKobo($amountKobo),
            'bill' => Money::fromKobo($amountKobo - 160_000),
            'status' => 'pending',
        ]);
    });

    return [$transaction, $guardian];
}

function SchoolFinanceSettingsForGrt(int $schoolId): void
{
    SchoolFinanceSettings::updateOrCreate(
        ['school_id' => $schoolId],
        ['settlement_bank_account_id' => testBankAccountId($schoolId)],
    );
}

/** Paystack's verify answer — THE AUTHORITY, and the only thing these arms vary. */
function grtVerify(GatewayTransaction $transaction, string $status = 'success', ?int $fees = 160_000): void
{
    $data = [
        'id' => 987_654_321,
        'reference' => $transaction->reference,
        'amount' => $transaction->amount->toKobo(),
        'currency' => 'NGN',
        'status' => $status,
        'paid_at' => '2026-09-04T10:00:00.000Z',
        'customer' => ['first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.test'],
    ];

    if ($fees !== null) {
        $data['fees'] = $fees;
    }

    Http::fake(['*/transaction/verify/*' => Http::response(['status' => true, 'data' => $data], 200)]);
}

function grtReturn(User $user, string $reference)
{
    return test()->actingAs($user)
        ->withSession(['school_id' => $user->school_id])
        ->get('/parent/payments/return?reference='.$reference);
}

it('settles the payment and tells the payer it is received', function () {
    [$transaction, $guardian] = grtFixture();
    grtVerify($transaction);

    grtReturn($guardian, $transaction->reference)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('parent/payment-return')->where('state', 'settled'));

    expect($transaction->fresh()->payment_id)->not->toBeNull();
});

it('settles from VERIFY, not from the query string', function () {
    [$transaction, $guardian] = grtFixture();

    // The provider says this transaction FAILED. The payer's browser is carrying the reference of a
    // transaction they may well believe succeeded — and the query string cannot overrule the answer
    // the provider gives, because the query string is not consulted for anything but the reference.
    grtVerify($transaction, status: 'failed');

    grtReturn($guardian, $transaction->reference)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'failed'));

    expect($transaction->fresh()->payment_id)->toBeNull()
        ->and(DB::table('finance_payments')->count())->toBe(0);
});

it('tells the payer we are still confirming when the provider cannot be reached — never that it failed', function () {
    [$transaction, $guardian] = grtFixture();
    Http::fake(['*/transaction/verify/*' => Http::response('upstream is unwell', 503)]);

    // THE ARM THAT PROTECTS THE SCHOOL FROM A DOUBLE PAYMENT. "We could not find out" is not "it
    // failed", and a parent told it failed pays again — the second payment is real money the school
    // then has to return.
    grtReturn($guardian, $transaction->reference)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'pending'));

    expect($transaction->fresh()->status->value)->toBe('pending');
});

it('does not call the provider at all when the webhook already settled it', function () {
    [$transaction, $guardian] = grtFixture();
    grtVerify($transaction);

    // First return settles it.
    grtReturn($guardian, $transaction->reference)->assertOk();

    // NO FAKE FOR THE SECOND CALL, and `preventStrayRequests()` is on — so if this path asked the
    // provider again the test would fail naming the URL. Answering `recorded` from the row alone is
    // the assertion: there is nothing left to decide, and a round trip would reach the same
    // conclusion at the payer's expense.
    Http::fake([]);
    Http::preventStrayRequests();

    grtReturn($guardian, $transaction->reference)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'recorded'));

    expect(DB::table('finance_payments')->count())->toBe(1);
});

it('is idempotent against the webhook — a return after a settled webhook writes no second payment', function () {
    [$transaction, $guardian] = grtFixture();
    grtVerify($transaction);

    grtReturn($guardian, $transaction->reference)->assertOk();
    grtReturn($guardian, $transaction->reference)->assertOk();
    grtReturn($guardian, $transaction->reference)->assertOk();

    // THE COMPARE-AND-SWAP IS WHAT MAKES THIS SAFE, and it is `settleFromProvider`'s, not this
    // controller's. Three returns, one payment — the same guarantee the webhook has, because it is
    // literally the same code path.
    expect(DB::table('finance_payments')->count())->toBe(1);
});

it('answers unknown for a reference this system never minted, without naming it back', function () {
    [, $guardian] = grtFixture();

    grtReturn($guardian, 'not-one-of-ours')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'unknown')->where('amount', null));
});

it('answers unknown for a well-formed reference belonging to another school', function () {
    [$mine, $guardian] = grtFixture();
    [$theirs] = grtFixture();

    // ISOLATION, and it is a DIFFERENT guarantee from the relationship one. The reference routes to
    // the school that minted it, so the lookup runs inside that school's scope and this guardian's
    // own school never sees the row.
    expect($mine->school_id)->not->toBe($theirs->school_id);

    grtReturn($guardian, $theirs->reference)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'unknown'));

    expect($theirs->fresh()->payment_id)->toBeNull();
});

it('shows the payer what the BILL was credited, not the gross they were charged', function () {
    [$transaction, $guardian] = grtFixture();
    grtVerify($transaction);

    // 10,000,000 charged, 9,840,000 credited to the bill. Showing the gross here would misstate what
    // the school received, and the gross was already explained on the confirmation screen.
    grtReturn($guardian, $transaction->reference)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('amount.amount_minor', 9_840_000));
});
