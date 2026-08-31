<?php

use App\Finance\Actions\SettleGatewayTransaction;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\GatewayTransaction;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Finance\Models\SchoolFinanceSettings;
use App\Finance\Services\GatewayEventRedactor;
use App\Finance\Services\GatewayReference;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The Paystack webhook: what it refuses, what it records, and what it may only ever do once.
 *
 * THE FIXTURE IS BUILT SO EACH PROPERTY IS THE ONLY EXPLANATION FOR ITS PASS. The amount and the
 * fee are DIFFERENT non-round numbers, so "amount − fee" cannot be confused with either of them or
 * with a zero. The `paid_at` used for the timezone arm is 23:30 UTC, which is the only hour where
 * Lagos and UTC disagree about the DATE — a fixture at midday would pass under both readings and
 * prove nothing.
 */
uses(RefreshDatabase::class);

const PWT_SECRET = 'sk_test_pwt_fixture_secret';

beforeEach(fn () => config(['services.paystack.secret_key' => PWT_SECRET]));

function pwtTransaction(?School $school = null, ?string $reference = null, int $amountKobo = 4_137_500): GatewayTransaction
{
    $school ??= School::factory()->create();
    // MINTED, never hand-written: the reference is the routing key the webhook reads the school
    // from, so a fixture that spells its own would be testing a format the initialiser will not
    // produce.
    $reference ??= GatewayReference::mint((int) $school->id);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, function () use ($school, $student, $curriculum, $reference, $amountKobo) {
        // A REAL PRECONDITION, not fixture noise: a gateway payment names the settlement account the
        // provider paid out into, and the origin-pairing trigger refuses a `gateway` row with a NULL
        // bank_account_id. A school with no settlement account configured cannot take one — which is
        // the correct refusal, and is asserted on its own further down.
        SchoolFinanceSettings::updateOrCreate(
            ['school_id' => $school->id],
            ['settlement_bank_account_id' => testBankAccountId($school->id)],
        );

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
        ]);

        return GatewayTransaction::create([
            'school_id' => $school->id,
            'invoice_id' => $invoice->id,
            'provider' => 'paystack',
            'reference' => $reference,
            'amount' => Money::fromKobo($amountKobo),
            'status' => 'pending',
        ]);
    });
}

/** @return array<string, mixed> */
function pwtBody(string $reference, array $overrides = []): array
{
    return array_replace_recursive([
        'event' => 'charge.success',
        'data' => [
            'id' => 3_209_876_541,
            'reference' => $reference,
            'amount' => 4_137_500,
            'currency' => 'NGN',
            'status' => 'success',
            // 23:30 UTC on the 14th is 00:30 LAGOS on the 15th. This is the hour the bug lived in.
            'paid_at' => '2026-09-14T23:30:00.000Z',
            'fees' => 72_062,
            'customer' => ['first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.com'],
            'authorization' => [
                'authorization_code' => 'AUTH_pwt_reusable_token',
                'signature' => 'SIG_pwt_card_fingerprint',
                'card_type' => 'visa',
                'last4' => '4081',
                'reusable' => true,
            ],
        ],
    ], $overrides);
}

function pwtPost(array $body, ?string $signature = null): TestResponse
{
    $raw = json_encode($body);

    return test()->call(
        'POST',
        '/api/webhooks/paystack',
        [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $raw, PWT_SECRET)],
        $raw,
    );
}

it('refuses an unsigned delivery with 401 and writes absolutely nothing', function () {
    $transaction = pwtTransaction();

    pwtPost(pwtBody($transaction->reference), signature: 'not-the-signature')->assertStatus(401);

    // NOT MERELY "no payment": no EVENT ROW either. The table is append-only and DELETE is denied
    // on it, so anyone who learned the URL could otherwise fill it permanently.
    expect(DB::table('finance_gateway_transaction_events')->count())->toBe(0)
        ->and(DB::table('finance_payments')->count())->toBe(0)
        ->and($transaction->fresh()->payment_id)->toBeNull();
});

it('settles a signed charge.success: the payment is amount MINUS the reported fee', function () {
    $transaction = pwtTransaction();

    pwtPost(pwtBody($transaction->reference))->assertOk()->assertJsonPath('outcome', 'settled');

    $payment = ActiveSchool::runFor($transaction->school_id, fn () => Payment::firstOrFail());

    // 4_137_500 − 72_062 = 4_065_438. Three distinguishable numbers: a payment equal to the gross,
    // to the fee, or to zero all fail this, so the subtraction is the only thing that passes it.
    expect($payment->amount->toKobo())->toBe(4_065_438)
        ->and($payment->origin)->toBe(Payment::ORIGIN_GATEWAY)
        ->and($payment->received_by_user_id)->toBeNull();

    $fresh = $transaction->fresh();
    expect($fresh->payment_id)->toBe($payment->id)
        ->and($fresh->status->value)->toBe('success')
        ->and($fresh->fee->toKobo())->toBe(72_062)
        ->and($fresh->provider_reference)->toBe('3209876541');
});

it('ALLOCATES the payment to the invoice the parent chose', function () {
    $transaction = pwtTransaction();

    pwtPost(pwtBody($transaction->reference))->assertOk()->assertJsonPath('outcome', 'settled');

    [$payment, $allocation] = ActiveSchool::runFor($transaction->school_id, fn () => [
        Payment::firstOrFail(),
        PaymentAllocation::firstOrFail(),
    ]);

    // THE ARM THE FIRST VERSION OF THIS FILE DID NOT HAVE, which is why the defect it covers was
    // invisible: the old fixture asserted the Payment row's amount and nothing about the invoice.
    // A payment that banks as unnamed account credit produces an IDENTICAL Payment row — same
    // amount, same origin, same balance movement — and leaves the invoice the parent actually paid
    // sitting outstanding. The two are indistinguishable without this assertion.
    expect($allocation->payment_id)->toBe($payment->id)
        ->and($allocation->invoice_id)->toBe($transaction->invoice_id)
        ->and($allocation->amount->toKobo())->toBe($payment->amount->toKobo())
        ->and($allocation->allocation_rule)->toBe(PaymentAllocation::RULE_PAYMENT_AGAINST_NAMED_INVOICE);
});

it('records the provider reference, so there is a way back to Paystack', function () {
    $transaction = pwtTransaction();

    pwtPost(pwtBody($transaction->reference))->assertOk();

    // The only link from this row to the provider's record of the money.
    expect(ActiveSchool::runFor($transaction->school_id, fn () => Payment::firstOrFail())->external_reference)
        ->toBe($transaction->reference);
});

it('refuses to book against a void invoice, loudly, without a 500', function () {
    $transaction = pwtTransaction();

    ActiveSchool::runFor($transaction->school_id, fn () => voidInvoiceViaApproval(
        (int) $transaction->school_id,
        (string) Invoice::findOrFail($transaction->invoice_id)->uuid,
    ));

    // The payer has already been charged, so this is money with no payment against it. 200 because
    // redelivery cannot fix our data — but recorded, left pending, and left for the discrepancy
    // report. A 500 here would put Paystack into a retry schedule that fails identically forever.
    pwtPost(pwtBody($transaction->reference))->assertOk()->assertJsonPath('outcome', 'could_not_book');

    expect(DB::table('finance_payments')->count())->toBe(0)
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(1)
        ->and($transaction->fresh()->status->value)->toBe('pending');
});

it('files the payment on the LAGOS date, not the UTC one', function () {
    $transaction = pwtTransaction();

    pwtPost(pwtBody($transaction->reference))->assertOk();

    $payment = ActiveSchool::runFor($transaction->school_id, fn () => Payment::firstOrFail());

    // 2026-09-14T23:30Z is 2026-09-15 00:30 in Lagos. Reading the date off the raw UTC string files
    // this payment on the 14th — a day early, into a period it did not happen in, on a table that
    // cannot be corrected by an UPDATE.
    expect($payment->received_at->toDateString())->toBe('2026-09-15');
});

it('never stores the reusable authorization code, and says so rather than staying silent', function () {
    $transaction = pwtTransaction();

    pwtPost(pwtBody($transaction->reference))->assertOk();

    $event = DB::table('finance_gateway_transaction_events')->first();
    $payload = json_decode($event->payload, true);

    expect($payload['data']['authorization'])->not->toHaveKey('authorization_code')
        ->and($payload['data']['authorization'])->not->toHaveKey('signature')
        // The rest of the delivery survives — stripping is narrow, not a blanket drop of the block.
        ->and($payload['data']['authorization']['last4'])->toBe('4081')
        ->and($payload['data']['amount'])->toBe(4_137_500);

    // THE ABSENCE IS A RECORDED ACT. Without this, a stripped payload is indistinguishable from a
    // bank-transfer payload that never had an authorization code, and a reader would take our
    // redaction as a fact about the payment.
    expect(json_decode($event->redacted_fields, true))->toBe([
        'data.authorization.authorization_code',
        'data.authorization.signature',
    ]);

    // And it is a STRIP, not a retention redaction: those are different operations with different
    // signals, and conflating them would claim the payload is gone while it is sitting right there.
    expect($event->redacted_at)->toBeNull();
});

it('records only what was actually removed, never what was merely looked for', function () {
    // A delivery with no authorization block at all — a bank transfer. Listing a path that was never
    // present would assert the provider sent a credential it did not send.
    $stripped = (new GatewayEventRedactor)->strip(['event' => 'charge.success', 'data' => ['reference' => 'x']]);

    expect($stripped[1])->toBe([]);
});

it('is idempotent: a redelivered webhook does not pay the invoice twice', function () {
    $transaction = pwtTransaction();

    pwtPost(pwtBody($transaction->reference))->assertOk()->assertJsonPath('outcome', 'settled');
    pwtPost(pwtBody($transaction->reference))->assertOk()->assertJsonPath('outcome', 'already_settled');

    expect(DB::table('finance_payments')->count())->toBe(1)
        // BOTH deliveries are on file even though only one settled — that is the whole point of
        // committing T1 before T2 decides anything.
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(2);
});

it('the compare-and-swap refuses a second claim on an already-claimed row', function () {
    $transaction = pwtTransaction();
    pwtPost(pwtBody($transaction->reference))->assertOk();

    $settled = $transaction->fresh();
    $payment = ActiveSchool::runFor($transaction->school_id, fn () => Payment::firstOrFail());

    // A DIFFERENT payment, so the attempted claim would genuinely CHANGE the row.
    //
    // The first version of this arm re-claimed with the SAME payment and asserted 0 affected rows.
    // It passed with `AND payment_id IS NULL` REMOVED, because MySQL reports 0 affected when an
    // UPDATE matches a row and writes identical values — so the 0 was proving "nothing changed",
    // not "the predicate refused". The fixture had collapsed to where a wrong implementation passed
    // for a reason that had nothing to do with the rule under test.
    $rival = ActiveSchool::runFor($transaction->school_id, fn () => Payment::create([
        'school_id' => $transaction->school_id,
        'student_id' => $payment->student_id,
        'reference' => $payment->reference + 1,
        'amount' => Money::fromKobo(1),
        'payer_name' => 'Rival delivery',
        'received_at' => '2026-09-15',
        'origin' => Payment::ORIGIN_GATEWAY,
        'bank_account_id' => $payment->bank_account_id,
    ]));

    // THE PRODUCTION SWAP, called directly against a row that is already claimed, with a payment
    // that WOULD change it. It must affect ZERO rows.
    //
    // An earlier version of this arm issued its OWN hand-written UPDATE and claimed to be the
    // mutation guard for `AND payment_id IS NULL`. It was not: deleting that clause from the action
    // changed nothing the test executed, so it asserted a property of MySQL under a comment
    // asserting the opposite. This calls the real method, so removing the clause reds HERE.
    //
    // Reflection rather than widening the method to public: the swap is an internal seam, and the
    // fix for an untestable private is not to make the class lie about its surface.
    $method = new ReflectionMethod(SettleGatewayTransaction::class, 'claim');
    $affected = ActiveSchool::runFor(
        $transaction->school_id,
        fn () => $method->invoke(
            app(SettleGatewayTransaction::class),
            $settled,
            $rival,
            pwtBody($transaction->reference)['data'],
            Money::fromKobo(99_999),
        ),
    );

    expect($affected)->toBe(0)
        // And the row is untouched — a refused claim must not have rewritten the winner's fields.
        ->and($transaction->fresh()->payment_id)->toBe($payment->id);
});

it('records the delivery but writes no payment when the provider does not report its fee', function () {
    $transaction = pwtTransaction();

    $body = pwtBody($transaction->reference);
    unset($body['data']['fees']);

    pwtPost($body)->assertOk()->assertJsonPath('outcome', 'fee_not_reported');

    // §7's fifth case. The net amount is unknowable, so nothing is guessed: the row stays pending
    // for the discrepancy report to find, and the delivery is on file to explain why.
    expect(DB::table('finance_payments')->count())->toBe(0)
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(1)
        ->and($transaction->fresh()->status->value)->toBe('pending')
        ->and($transaction->fresh()->payment_id)->toBeNull();
});

it('refuses to book a delivery whose amount is not the one we initiated', function (array $override, string $why) {
    $transaction = pwtTransaction();

    pwtPost(pwtBody($transaction->reference, ['data' => $override]))
        ->assertOk()
        ->assertJsonPath('outcome', 'amount_mismatch');

    // Nothing booked, delivery on file, row left pending for the discrepancy report. Everything
    // downstream takes the GROSS from our own row, so without this check a provider reporting a
    // different amount would never be noticed — the two numbers simply never meet.
    expect(DB::table('finance_payments')->count())->toBe(0)
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(1)
        ->and($transaction->fresh()->status->value)->toBe('pending');
})->with([
    'a different amount' => [['amount' => 4_137_499], 'one kobo under'],
    'a different currency' => [['currency' => 'USD'], 'not the invoice currency'],
    // ABSENT MUST FAIL, NOT PASS. Treating a missing field as matching puts the check back where it
    // started, which is the usual way a validation of this shape is written wrong.
    'no amount at all' => [['amount' => null], 'missing is not matching'],
]);

it('acknowledges a reference it never minted without writing anything', function () {
    // Someone else's integration on the same Paystack account, or the dashboard's test button.
    // It does not parse as ours, so there is no school to enter and nothing to look up.
    pwtPost(pwtBody('a-reference-we-never-issued'))
        ->assertOk()
        ->assertJsonPath('reason', 'unknown reference');

    expect(DB::table('finance_gateway_transaction_events')->count())->toBe(0)
        ->and(DB::table('finance_payments')->count())->toBe(0);
});

it('acknowledges a well-formed reference for a transaction that does not exist', function () {
    $school = School::factory()->create();

    // THE OTHER HALF, and a different code path: this one parses, enters a real school's context,
    // runs a scoped lookup and finds nothing. Without this arm the unknown-reference test above
    // could pass on the parse refusal alone and the scoped miss would be unexercised.
    pwtPost(pwtBody(GatewayReference::mint((int) $school->id)))
        ->assertOk()
        ->assertJsonPath('reason', 'unknown reference');

    expect(DB::table('finance_payments')->count())->toBe(0);
});

it('cannot be routed into another school by a forged reference', function () {
    $a = School::factory()->create();
    $b = School::factory()->create();
    $transaction = pwtTransaction($a);

    // School B's id, school A's random segment. The lookup runs INSIDE school B's context, so
    // A's row is invisible to it — the scope is never switched off, which is the whole point of
    // routing on the reference rather than searching across schools and adopting what turns up.
    $forged = str_replace('-'.$a->id.'-', '-'.$b->id.'-', $transaction->reference);

    pwtPost(pwtBody($forged))->assertOk()->assertJsonPath('reason', 'unknown reference');

    expect(DB::table('finance_payments')->count())->toBe(0)
        ->and($transaction->fresh()->payment_id)->toBeNull();
});

it('records a non-settlement event without settling on it', function () {
    $transaction = pwtTransaction();

    pwtPost(pwtBody($transaction->reference, ['event' => 'charge.failed']))
        ->assertOk()
        ->assertJsonPath('outcome', 'not_a_settlement_event');

    // On file, not acted on. A future Paystack event type must arrive with a deliberate decision
    // about what it means rather than inheriting the success path.
    expect(DB::table('finance_gateway_transaction_events')->count())->toBe(1)
        ->and(DB::table('finance_payments')->count())->toBe(0)
        ->and($transaction->fresh()->status->value)->toBe('pending');
});
