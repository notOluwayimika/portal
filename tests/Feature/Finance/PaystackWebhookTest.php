<?php

use App\Finance\Actions\SettleGatewayTransaction;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\BankAccount;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

beforeEach(function () {
    config(['services.paystack.secret_key' => PWT_SECRET]);

    // NO TEST MAY REACH THE NETWORK, AND A TEST THAT TRIES MUST SAY SO. Every settling arm now goes
    // out to `verify()`, so an arm that forgets its fake would otherwise either hit Paystack or fail
    // with a connection error whose message is about DNS rather than about the missing stub. This
    // turns both into a loud, specific refusal naming the URL.
    Http::preventStrayRequests();
});

/**
 * What Paystack will answer when this system asks about the reference — THE AUTHORITY.
 *
 * The webhook body stays a plain `charge.success` in every arm below and the variation lives HERE,
 * because that is what the code now does: the delivery supplies the trigger and the reference, and
 * every number that reaches a money column comes from this response.
 *
 * ONE `Http::fake()` PER TEST, NEVER A SHARED DEFAULT PLUS AN OVERRIDE. Fakes accumulate and the
 * FIRST match wins, so a second call for the same URL is silently ignored — the trap already
 * recorded in CLAUDE.md, which produced six passing assertions against one response.
 *
 * @param  array<string, mixed>  $dataOverrides  merged into the `data` object
 * @param  list<string>  $drop  keys removed from `data` entirely — `null` and ABSENT are different
 *                              facts and several arms turn on which one they are testing
 */
function pwtVerifyReturns(string $reference, array $dataOverrides = [], array $drop = []): void
{
    $data = pwtBody($reference, ['data' => $dataOverrides])['data'];

    foreach ($drop as $key) {
        unset($data[$key]);
    }

    Http::fake(['*/transaction/verify/*' => Http::response([
        'status' => true,
        'message' => 'Verification successful',
        'data' => $data,
    ], 200)]);
}

/** Paystack is unreachable — the case decided on 2026-08-29 and unreachable in code until now. */
function pwtVerifyUnavailable(): void
{
    Http::fake(['*/transaction/verify/*' => Http::response('upstream is unwell', 503)]);
}

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
        // bank_account_id.
        //
        // TWO ACCOUNTS, AND THE SETTLEMENT ONE IS NOT THE ONLY ONE. `testBankAccountId()` is a
        // firstOrCreate keyed on (school_id, 'TEST-<id>') — one account per school — so with it
        // alone "the configured settlement account" and "any account this school has" are the SAME
        // ROW, and a wrong destination rule passes indistinguishably. The decoy is created FIRST so
        // it takes the lower id: a destination rule that picked "the school's first account" would
        // then land on it and red, which is the whole point of the second row.
        $decoy = BankAccount::withoutGlobalScopes()->create([
            'school_id' => $school->id,
            'account_number' => 'DECOY-'.$school->id,
            'label' => 'Not the settlement account',
            'bank_name' => 'Decoy Bank',
        ]);

        SchoolFinanceSettings::updateOrCreate(
            ['school_id' => $school->id],
            ['settlement_bank_account_id' => testBankAccountId($school->id)],
        );

        // Referenced so the decoy is unmistakably deliberate rather than stray fixture.
        expect($decoy->id)->toBeLessThan(testBankAccountId($school->id));

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

it('refuses an unsigned delivery with 401, records nothing, but leaves a trace', function () {
    $transaction = pwtTransaction();
    Log::spy();

    pwtPost(pwtBody($transaction->reference), signature: 'not-the-signature')->assertStatus(401);

    // A REJECTED DELIVERY LEAVES A TRACE. Not for the attacker case — for the ROTATED SECRET case,
    // where every genuine delivery 401s and the platform would otherwise be silent about why.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'paystack.webhook.signature_rejected')
        ->once();

    // NOT MERELY "no payment": no EVENT ROW either. The table is append-only and DELETE is denied
    // on it, so anyone who learned the URL could otherwise fill it permanently.
    expect(DB::table('finance_gateway_transaction_events')->count())->toBe(0)
        ->and(DB::table('finance_payments')->count())->toBe(0)
        ->and($transaction->fresh()->payment_id)->toBeNull();
});

it('settles a signed charge.success: the payment is amount MINUS the reported fee', function () {
    $transaction = pwtTransaction();
    pwtVerifyReturns($transaction->reference);

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
    pwtVerifyReturns($transaction->reference);

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
    pwtVerifyReturns($transaction->reference);

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

    pwtVerifyReturns($transaction->reference);

    // The payer has already been charged, so this is money with no payment against it. 200 because
    // redelivery cannot fix our data — but recorded, left pending, and left for the discrepancy
    // report. A 500 here would put Paystack into a retry schedule that fails identically forever.
    pwtPost(pwtBody($transaction->reference))->assertOk()->assertJsonPath('outcome', 'could_not_book');

    expect(DB::table('finance_payments')->count())->toBe(0)
        // TWO ROWS, NOT ONE: the delivery that arrived AND the authority's answer to it. The
        // verify response is recorded before the outcome is decided, so a provider that
        // disagrees with the webhook leaves the disagreement on file rather than in a log.
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(2)
        ->and($transaction->fresh()->status->value)->toBe('pending');
});

it('pays into the CONFIGURED settlement account, not merely one of the school\'s accounts', function () {
    $transaction = pwtTransaction();
    pwtVerifyReturns($transaction->reference);

    pwtPost(pwtBody($transaction->reference))->assertOk();

    [$payment, $settlementId] = ActiveSchool::runFor($transaction->school_id, fn () => [
        Payment::firstOrFail(),
        (int) SchoolFinanceSettings::where('school_id', $transaction->school_id)->value('settlement_bank_account_id'),
    ]);

    // The fixture carries a decoy account with a LOWER id, so this cannot pass by picking "the
    // school's first account". Previously untestable: there was only ever one account per school.
    expect($payment->bank_account_id)->toBe($settlementId);
});

it('cannot book when the school has configured no settlement account', function () {
    $transaction = pwtTransaction();

    ActiveSchool::runFor($transaction->school_id, fn () => SchoolFinanceSettings::where('school_id', $transaction->school_id)
        ->update(['settlement_bank_account_id' => null]));

    pwtVerifyReturns($transaction->reference);

    // THE ARM THE FIXTURE CLAIMED EXISTED AND DID NOT. This is the most likely real could_not_book:
    // a school onboarded without a settlement account, discovered only once a parent has already
    // been charged. SettlementBankAccount throws BusinessRuleException; the webhook must catch it
    // into an outcome rather than 500 into Paystack's retry schedule.
    pwtPost(pwtBody($transaction->reference))->assertOk()->assertJsonPath('outcome', 'could_not_book');

    expect(DB::table('finance_payments')->count())->toBe(0)
        // TWO ROWS, NOT ONE: the delivery that arrived AND the authority's answer to it. The
        // verify response is recorded before the outcome is decided, so a provider that
        // disagrees with the webhook leaves the disagreement on file rather than in a log.
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(2)
        ->and($transaction->fresh()->status->value)->toBe('pending');
});

it('files the payment on the LAGOS date, not the UTC one', function () {
    $transaction = pwtTransaction();
    pwtVerifyReturns($transaction->reference);

    pwtPost(pwtBody($transaction->reference))->assertOk();

    $payment = ActiveSchool::runFor($transaction->school_id, fn () => Payment::firstOrFail());

    // 2026-09-14T23:30Z is 2026-09-15 00:30 in Lagos. Reading the date off the raw UTC string files
    // this payment on the 14th — a day early, into a period it did not happen in, on a table that
    // cannot be corrected by an UPDATE.
    expect($payment->received_at->toDateString())->toBe('2026-09-15');
});

it('never stores the reusable authorization code, and says so rather than staying silent', function () {
    $transaction = pwtTransaction();
    pwtVerifyReturns($transaction->reference);

    pwtPost(pwtBody($transaction->reference))->assertOk();

    // NAMED, not `first()`: there are two rows now and the ordering between them is not the point.
    $event = DB::table('finance_gateway_transaction_events')->where('source', 'webhook')->first();
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

    // THE VERIFY ROW CARRIES THE SAME CREDENTIAL AND MUST BE STRIPPED TOO. It is a second copy of
    // the authorization block, arriving by a different door, and a redaction that covered only the
    // webhook would store the reusable token anyway while every assertion above stayed green.
    $verified = DB::table('finance_gateway_transaction_events')->where('source', 'verify')->first();
    $verifiedPayload = json_decode($verified->payload, true);

    expect($verifiedPayload['data']['authorization'])->not->toHaveKey('authorization_code')
        ->and($verifiedPayload['data']['authorization'])->not->toHaveKey('signature')
        ->and($verifiedPayload['data']['authorization']['last4'])->toBe('4081')
        ->and($verified->event)->toBeNull();
});

it('records only what was actually removed, never what was merely looked for', function () {
    // A delivery with no authorization block at all — a bank transfer. Listing a path that was never
    // present would assert the provider sent a credential it did not send.
    $stripped = (new GatewayEventRedactor)->strip(['event' => 'charge.success', 'data' => ['reference' => 'x']]);

    expect($stripped[1])->toBe([]);
});

it('is idempotent: a redelivered webhook does not pay the invoice twice', function () {
    $transaction = pwtTransaction();
    pwtVerifyReturns($transaction->reference);

    pwtPost(pwtBody($transaction->reference))->assertOk()->assertJsonPath('outcome', 'settled');
    pwtPost(pwtBody($transaction->reference))->assertOk()->assertJsonPath('outcome', 'already_settled');

    expect(DB::table('finance_payments')->count())->toBe(1)
        // BOTH deliveries are on file even though only one settled — that is the whole point of
        // committing T1 before T2 decides anything.
        // FOUR: each delivery is recorded, and each asks the authority again. Re-verifying a replay
        // is deliberate rather than wasteful — a redelivery is exactly how a transaction recovers
        // when an EARLIER verify was unreachable, so short-circuiting on `payment_id` would close
        // the recovery path the unavailable-branch depends on.
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(4);
});

it('the compare-and-swap refuses a second claim on an already-claimed row', function () {
    $transaction = pwtTransaction();
    pwtVerifyReturns($transaction->reference);
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

    // DROPPED FROM THE AUTHORITY'S ANSWER, not from the delivery: `settle()` reads the verify body,
    // so a `fees` missing only from the webhook would now prove nothing at all.
    pwtVerifyReturns($transaction->reference, drop: ['fees']);

    pwtPost(pwtBody($transaction->reference))->assertOk()->assertJsonPath('outcome', 'fee_not_reported');

    // §7's fifth case. The net amount is unknowable, so nothing is guessed: the row stays pending
    // for the discrepancy report to find, and the delivery is on file to explain why.
    expect(DB::table('finance_payments')->count())->toBe(0)
        // TWO ROWS, NOT ONE: the delivery that arrived AND the authority's answer to it. The
        // verify response is recorded before the outcome is decided, so a provider that
        // disagrees with the webhook leaves the disagreement on file rather than in a log.
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(2)
        ->and($transaction->fresh()->status->value)->toBe('pending')
        ->and($transaction->fresh()->payment_id)->toBeNull();
});

it('refuses to book when the PROVIDER reports an amount that is not the one we initiated', function (array $override, string $why) {
    $transaction = pwtTransaction();
    pwtVerifyReturns($transaction->reference, $override);

    pwtPost(pwtBody($transaction->reference))
        ->assertOk()
        ->assertJsonPath('outcome', 'amount_mismatch');

    // Nothing booked, delivery on file, row left pending for the discrepancy report. Everything
    // downstream takes the GROSS from our own row, so without this check a provider reporting a
    // different amount would never be noticed — the two numbers simply never meet.
    expect(DB::table('finance_payments')->count())->toBe(0)
        // TWO ROWS, NOT ONE: the delivery that arrived AND the authority's answer to it. The
        // verify response is recorded before the outcome is decided, so a provider that
        // disagrees with the webhook leaves the disagreement on file rather than in a log.
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(2)
        ->and($transaction->fresh()->status->value)->toBe('pending');
})->with([
    'a different amount' => [['amount' => 4_137_499], 'one kobo under'],
    'a different currency' => [['currency' => 'USD'], 'not the invoice currency'],
]);

it('refuses an answer with no amount in it — as unreadable, not as a mismatch', function () {
    $transaction = pwtTransaction();
    pwtVerifyReturns($transaction->reference, ['amount' => null]);

    // ABSENT MUST FAIL, NOT PASS — the rule this arm has always been about. What CHANGED with the
    // authority is WHICH refusal it earns, and the distinction is worth keeping rather than
    // flattening: a body with no amount is an answer this system could not read, not a provider
    // reporting a different charge. `verifyWithPayload` refuses it at the DTO construction, before
    // `matchesCharge` is ever reached, so the outcome is the unreachable-provider one.
    //
    // Asserted by NAME. Both outcomes exit through the same 200 and would satisfy an arm that only
    // checked "nothing was booked", and the two mean very different things to whoever reads the
    // discrepancy report: one says ask Paystack what is wrong with our integration, the other says
    // ask why they charged a different amount.
    pwtPost(pwtBody($transaction->reference))
        ->assertOk()
        ->assertJsonPath('outcome', 'verify_unavailable');

    expect(DB::table('finance_payments')->count())->toBe(0)
        ->and($transaction->fresh()->status->value)->toBe('pending')
        // The unreadable body is NOT recorded: recordDelivery runs only after the answer parses, so
        // there is exactly the webhook row. Nothing is invented into the evidence table.
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(1);
});

it('refuses a negative fee rather than crediting more than the payer was charged', function () {
    $transaction = pwtTransaction();

    pwtVerifyReturns($transaction->reference, ['fees' => -50_000]);

    pwtPost(pwtBody($transaction->reference))
        ->assertOk()
        ->assertJsonPath('outcome', 'fee_is_negative');

    // The range guard was one-sided: FeeExceedsAmount catches a fee too LARGE, and nothing caught
    // one below zero. A negative fee makes `amount - fee` GREATER than the gross, so the invoice is
    // credited more than the payer ever paid — money invented, on an append-only table.
    expect(DB::table('finance_payments')->count())->toBe(0)
        // TWO ROWS, NOT ONE: the delivery that arrived AND the authority's answer to it. The
        // verify response is recorded before the outcome is decided, so a provider that
        // disagrees with the webhook leaves the disagreement on file rather than in a log.
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(2)
        ->and($transaction->fresh()->status->value)->toBe('pending');
});

it('still refuses a fee at or above the amount — the OTHER side of the same guard', function () {
    $transaction = pwtTransaction();

    // BOTH SIDES ASSERTED TOGETHER, so a future edit cannot fix one direction by breaking the other
    // and see a green suite. This is the arm that existed; the one above is the one that did not.
    pwtVerifyReturns($transaction->reference, ['fees' => 4_137_500]);

    pwtPost(pwtBody($transaction->reference))
        ->assertOk()
        ->assertJsonPath('outcome', 'fee_exceeds_amount');

    expect(DB::table('finance_payments')->count())->toBe(0);
});

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

// ── THE AUTHORITY ─────────────────────────────────────────────────────────────────────────────
//
// These three are the fix. Step 4 shipped settling from the webhook body, which three docblocks and
// a decision document forbade; nothing in the suite could tell, because every fixture made the two
// bodies identical and a wrong implementation therefore passed by coincidence.

it('takes the money facts from VERIFY, not from the delivery that announced them', function () {
    $transaction = pwtTransaction();

    // THE ONLY ARM THAT CAN SEE WHICH BODY WAS READ. Every other test in this file passes whichever
    // one settle() takes, because their webhook and their verify response agree — the fixture's
    // degrees of freedom collapse and the axis under test disappears. Here they DISAGREE, so the
    // payment amount names the source: 4_137_500 − 72_062 = 4_065_438 from the authority, against
    // 4_137_500 − 999_999 = 3_137_501 from the wire.
    pwtVerifyReturns($transaction->reference, ['fees' => 72_062]);

    pwtPost(pwtBody($transaction->reference, ['data' => ['fees' => 999_999]]))
        ->assertOk()
        ->assertJsonPath('outcome', 'settled');

    $payment = ActiveSchool::runFor($transaction->school_id, fn () => Payment::firstOrFail());

    expect($payment->amount->toKobo())->toBe(4_065_438)
        ->and($transaction->fresh()->fee->toKobo())->toBe(72_062);
});

it('books nothing when the delivery claims success and the provider will not corroborate it', function () {
    $transaction = pwtTransaction();

    // THE CASE THE VERIFY CALL EXISTS FOR. The signature on this delivery is valid — it is computed
    // with the real secret, exactly as a holder of that secret would. What it cannot do is make
    // Paystack's own API agree, and that is the whole difference between trusting the wire and
    // trusting the provider.
    pwtVerifyReturns($transaction->reference, ['status' => 'failed']);

    pwtPost(pwtBody($transaction->reference))
        ->assertOk()
        ->assertJsonPath('outcome', 'not_successful_at_provider');

    expect(DB::table('finance_payments')->count())->toBe(0)
        ->and($transaction->fresh()->payment_id)->toBeNull()
        ->and($transaction->fresh()->status->value)->toBe('pending')
        // BOTH bodies are kept. The disagreement between them is the evidence, and it is the thing
        // a human will want when they are asked why a parent says they paid and the portal says not.
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(2);
});

it('leaves the row pending, and answers 200, when the provider cannot be reached', function () {
    $transaction = pwtTransaction();
    pwtVerifyUnavailable();

    // §7's fifth failure row, decided 2026-08-29 in
    // docs/handoff/decisions/webhook-arrives-but-verify-is-unreachable.md and UNREACHABLE IN CODE
    // until the verify call it depends on was actually made. "We could not find out" is not "it
    // failed": marking this failed would strand a parent who has genuinely paid.
    pwtPost(pwtBody($transaction->reference))
        ->assertOk()
        ->assertJsonPath('outcome', 'verify_unavailable');

    expect(DB::table('finance_payments')->count())->toBe(0)
        ->and($transaction->fresh()->status->value)->toBe('pending')
        ->and($transaction->fresh()->payment_id)->toBeNull()
        // ONE row, and asserting WHICH: the delivery arrived and is on file; no verify body exists
        // to record, because none came back. A second row here would mean something was invented.
        ->and(DB::table('finance_gateway_transaction_events')->count())->toBe(1)
        ->and(DB::table('finance_gateway_transaction_events')->value('source'))->toBe('webhook');
});
