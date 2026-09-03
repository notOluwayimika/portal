<?php

/*
 * U11 — THE PAYMENT RECEIPT. The page route, the migrated refusal it owes, the two payment kinds it
 * has to describe correctly, isolation, and the permission floor.
 *
 * Everything here goes through the REAL Actions — RecordPayment, RecordAccountPayment,
 * PostOpeningBalanceBatch — never a hand-written row, because the arms are about what the receipt
 * SAYS about a payment and a planted row can be given a shape the system cannot produce. The one
 * exception is called out where it happens (the credit-forward allocation onto a migrated payment)
 * and is argued there.
 *
 * WHY EVERY ARM ASSERTS PROPS AND NOT ONLY A STATUS. The suite is structurally blind to rendering: a
 * 200 with the document, a 200 with an empty document and a 200 rendering an error are the same
 * assertion. Asserting the Inertia props is as close as this layer gets to the page's content, and
 * the browser drive covers what remains.
 */

use App\Enums\TermStatusEnum;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\PostOpeningBalanceBatch;
use App\Finance\Actions\RecordAccountPayment;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\Invoice;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Finance\Models\Payment;
use App\Models\AcademicSession;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** A user in $school holding exactly $permissions, through a role minted for that set. */
function prUser(School $school, array $permissions): User
{
    $roleName = 'pr_'.substr(md5(implode(',', $permissions)), 0, 10);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $roleName);
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A student with one active enrollment episode in $school. */
function prStudent(School $school): Student
{
    $student = Student::factory()->create(['school_id' => $school->id]);

    ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));

    return $student;
}

function prInvoice(School $school, Student $student, int $kobo): Invoice
{
    return ActiveSchool::runFor($school->id, fn () => app(GenerateInvoice::class)->handle(
        StudentCurriculum::where('student_id', $student->id)->value('uuid'),
        [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo), bankAccountId: testBankAccountId())],
        InvoiceKind::Scheduled,
    ));
}

/**
 * A SUPPLEMENTARY charge against the same episode — a one-off raised outside the schedule while the
 * term bill is live. `prInvoice` above names InvoiceKind::Scheduled and cannot be reused: an
 * episode's active SCHEDULED invoice is unique by index, and a second one is refused.
 */
function prSupplementaryInvoice(School $school, Student $student, int $kobo): Invoice
{
    return ActiveSchool::runFor($school->id, fn () => app(GenerateInvoice::class)->handle(
        StudentCurriculum::where('student_id', $student->id)->value('uuid'),
        [new InvoiceLineSpec('Damaged locker door', Money::fromKobo($kobo), bankAccountId: testBankAccountId())],
        InvoiceKind::Supplementary,
    ));
}

/** A payment recorded AGAINST AN INVOICE — the invoice-allocated door. */
function prInvoicePayment(School $school, Invoice $invoice, User $actor, int $kobo): Payment
{
    return ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)->handle(
        $invoice, Money::fromKobo($kobo), 'Mrs Payer', $actor, SchoolDay::today(), testBankAccountId($school->id),
    ));
}

/** A payment recorded ON THE ACCOUNT — the "money at the window" door. */
function prAccountPayment(School $school, Student $student, User $actor, int $kobo): Payment
{
    return ActiveSchool::runFor($school->id, fn () => app(RecordAccountPayment::class)->handle(
        $student->id, Money::fromKobo($kobo), 'Mrs Payer', $actor, SchoolDay::today(), testBankAccountId($school->id),
    ));
}

/**
 * A MIGRATED payment, produced by the ONLY writer that can produce one: PostOpeningBalanceBatch.
 *
 * There is no factory shortcut here on purpose. `origin = 'migrated'` is what the refusal turns on,
 * and a hand-inserted row with that string would prove the refusal fires on a value this test wrote
 * rather than on the value the cutover writes. The staged batch/rows below are the Action's declared
 * input, and the Action does the rest — the reserved receipt band, the netting, the ledger credit.
 */
function prMigratedPayment(School $school, Student $student, User $poster, int $kobo): Payment
{
    return ActiveSchool::runFor($school->id, function () use ($school, $student, $poster, $kobo) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'Third Term',
            'slug' => 'term-'.Str::random(8), 'order' => 3, 'start_date' => now()->subMonths(4),
            'end_date' => now()->subMonth(), 'status' => TermStatusEnum::ACTIVE->value,
        ]);

        // A NEGATIVE balance is the credit side — spec §3 step 2, the half that becomes the netted
        // migrated payment. `submitted_by_user_id` is left null exactly as OpeningBalancePostingTest
        // leaves it: these arms are about the posted result, and a null submitter cannot collide
        // with the maker ≠ checker CHECK.
        $batch = OpeningBalanceBatch::create([
            'batch_reference' => 'WCBS-'.Str::random(6),
            'filename' => 'cutover.csv',
            'status' => OpeningBalanceBatchStatus::Submitted,
            'row_count' => 1,
            'file_row_count' => 1,
            'control_total' => Money::fromKobo(-$kobo),
            'cutover_date' => now()->toDateString(),
            'term_id' => $term->id,
            'uploaded_by_user_id' => null,
        ]);

        OpeningBalanceRow::create([
            'batch_id' => $batch->id,
            'line_number' => 1,
            'admission_number' => $student->admission_number,
            'wcbs_student_ref' => 'WCBS-'.$student->id,
            'fee_type_label' => 'Tuition',
            'balance' => Money::fromKobo(-$kobo),
            'student_total_balance' => Money::fromKobo(-$kobo),
            'student_id' => $student->id,
            'status' => OpeningBalanceRowStatus::Ok,
        ]);

        app(PostOpeningBalanceBatch::class)->handle($batch->refresh(), $poster);

        return Payment::where('student_id', $student->id)
            ->where('origin', Payment::ORIGIN_MIGRATED)
            ->firstOrFail();
    });
}

function prGet(User $actor, School $school, string $paymentUuid)
{
    return test()->actingAs($actor)
        ->withSession(['school_id' => $school->id])
        ->get("/finance/payments/{$paymentUuid}/receipt");
}

// ── 1. The refusal ───────────────────────────────────────────────────────────────────────────────

it('REFUSES a receipt for a migrated payment, server-side, with the reason on the page', function () {
    /*
     * The arm this commit owes (opening-balance spec §4). Asserted on THREE things, because any one
     * of them alone would pass a broken implementation:
     *   • the STATUS is a refusal — a 200 with an empty document is not a refusal;
     *   • `receipt` is null — the document must not be built at all, not merely be hidden;
     *   • the REASON reaches the caller, and is the model's constant rather than a second spelling.
     *
     * WATCHED RED: `isReceiptable()` changed to `return true`. The status flips 403 -> 200 and both
     * remaining expectations fail on a rendered document.
     */
    $school = School::factory()->create();
    $reader = prUser($school, ['finance.access']);
    $checker = prUser($school, ['finance.access', 'finance.opening-balance.approve']);
    $student = prStudent($school);
    $payment = prMigratedPayment($school, $student, $checker, 500_00);

    expect($payment->origin)->toBe(Payment::ORIGIN_MIGRATED);

    prGet($reader, $school, $payment->uuid)
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('admin/finance/receipt')
            ->where('receipt', null)
            ->where('refusal', Payment::RECEIPT_REFUSAL_REASON)
        );
});

it('refuses a migrated payment that HAS acquired allocations, so the predicate is origin and not shape', function () {
    /*
     * WHY THIS SECOND ARM EXISTS. Only one writer can produce `origin = 'migrated'` —
     * PostOpeningBalanceBatch — and it creates NO invoice and NO allocation (spec R6, the Action's
     * step 3). So a migrated payment is born account-shaped, and a refusal keyed accidentally on
     * "has no allocations" would pass the arm above while being wrong. It does not stay
     * account-shaped: GenerateInvoice draws unallocated remainders forward oldest-first, and the
     * migrated row sorts FIRST under that ordering by construction. This arm makes that happen with
     * the real Action and asserts the refusal is unmoved.
     */
    $school = School::factory()->create();
    $reader = prUser($school, ['finance.access']);
    $checker = prUser($school, ['finance.access', 'finance.opening-balance.approve']);
    $student = prStudent($school);
    $payment = prMigratedPayment($school, $student, $checker, 500_00);

    // The invoice that draws the migrated credit forward.
    prInvoice($school, $student, 300_00);

    expect($payment->allocations()->count())->toBeGreaterThan(0);

    prGet($reader, $school, $payment->uuid)
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page->where('refusal', Payment::RECEIPT_REFUSAL_REASON));
});

it('gives an UNRECOGNISED origin a refusal that claims nothing about where the money came from', function () {
    /*
     * THE COLD REVIEW'S FINDING 1, pinned. `isReceiptable()` is an allowlist and refuses an unknown
     * origin correctly. The REASON used to be a denylist — `isReceiptable() ? null : <the WCBS
     * text>` — so a row with any third origin would have been told, on the wire and on the screen,
     * that it was collected by WCBS before the cutover. A specific false claim about a parent's
     * payment, made by a system that had simply not been taught the value.
     *
     * ASSERTED ON AN IN-MEMORY MODEL, and that is the honest limit of this arm rather than a
     * shortcut. The guard is the `finance_payments_origin_pairing_bi` TRIGGER — `finance_payments_
     * origin_shape` was DROPPED by 2026_08_17_100000 and this comment named it for one branch too
     * long — and it admits exactly 'portal', 'migrated' and 'gateway' (the third added by
     * 2026_08_25_100000). So an UNRECOGNISED value still cannot be persisted and the ROUTE still
     * cannot be driven into this branch; the arms below that go through HTTP remain structurally
     * unable to reach it. What is being pinned is the mapping itself, which is where the defect
     * lived. Note that 'gateway' is NOT an unrecognised origin: it is receiptable and answers null,
     * which PaymentOriginGatewayTest pins.
     *
     * WATCHED RED: restore `return $this->isReceiptable() ? null : self::RECEIPT_REFUSAL_REASON;`.
     * The unknown row then answers the WCBS text and the first expectation fails.
     */
    $unknown = new Payment(['origin' => 'reversed']);
    $migrated = new Payment(['origin' => Payment::ORIGIN_MIGRATED]);
    $portal = new Payment(['origin' => Payment::ORIGIN_PORTAL]);

    expect($unknown->receiptRefusalReason())->toBe(Payment::RECEIPT_REFUSAL_REASON_UNKNOWN_ORIGIN)
        // The neutral reason must not smuggle the provenance claim back in by wording.
        ->and(Payment::RECEIPT_REFUSAL_REASON_UNKNOWN_ORIGIN)->not->toContain('WCBS')
        ->and(Payment::RECEIPT_REFUSAL_REASON_UNKNOWN_ORIGIN)->not->toContain('previous system')
        // …and the two branches that ARE reachable keep their answers.
        ->and($migrated->receiptRefusalReason())->toBe(Payment::RECEIPT_REFUSAL_REASON)
        ->and($portal->receiptRefusalReason())->toBeNull()
        // The unknown row is still REFUSED — the predicate was never the defect.
        ->and($unknown->isReceiptable())->toBeFalse();
});

// ── 2. The happy paths — both doors ──────────────────────────────────────────────────────────────

it('renders the allocated invoices for an INVOICE-ALLOCATED payment', function () {
    /*
     * WATCHED RED: `invoice_number` returning `(string) $a->invoice_id` — the raw integer PK, which
     * is what a receipt shows if nobody notices the difference between the key and the number the
     * school prints. Fails with `-'000001' +'2'`, which is the whole reason this asserts the DISPLAY
     * NUMBER rather than the allocation count.
     *
     * A MUTATION THAT DID **NOT** RED, recorded because it is the one a reader would assume covers
     * this: dropping `->with('invoice')` from the query leaves every arm green. Eloquent lazy-loads
     * `$a->invoice` per row, so the eager load is a query-count optimisation here and nothing in
     * this file is sensitive to it. Do not read these arms as pinning it.
     */
    $school = School::factory()->create();
    $bursar = prUser($school, ['finance.access', 'finance.payment.record']);
    $student = prStudent($school);
    $invoice = prInvoice($school, $student, 400_00);
    $payment = prInvoicePayment($school, $invoice, $bursar, 400_00);

    prGet($bursar, $school, $payment->uuid)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/finance/receipt')
            ->where('refusal', null)
            ->where('receipt.amount.amount_minor', 400_00)
            ->where('receipt.allocations.0.invoice_number', $invoice->displayNumber())
            ->where('receipt.allocations.0.amount.amount_minor', 400_00)
            ->where('receipt.allocations.0.applied_on_receipt', true)
            ->where('receipt.allocated_total.amount_minor', 400_00)
            ->where('receipt.unallocated_amount.amount_minor', 0)
            ->where('receipt.fully_applied', true)
            ->where('receipt.held_on_account', false)
            ->where('receipt.nothing_applied', false)
        );
});

it('names the KIND of each invoice a payment reached, not only its number', function () {
    /*
     * TICKET §6, and the ticket is careful about what the claim IS — so this arm is too. The two
     * allocation rows a payment across an episode's term bill and its supplementary charge produces
     * were ALWAYS distinguishable: the invoice numbers differ. What was missing was the KIND. A
     * reader could separate the rows without being able to identify either, and both rows carry the
     * SAME `academic_context` because they are the same episode — which is why that column cannot
     * do this job.
     *
     * THE PAYMENT IS ACCOUNT-LEVEL, ON PURPOSE. The invoice-allocated door records against ONE named
     * invoice, so it cannot produce two allocation rows at all; carry-forward credit drawn oldest-
     * first across both invoices is how one payment reaches both, and it is the ordinary shape here
     * (GenerateInvoice::applyCreditForward). The invoices are therefore raised AFTER the payment,
     * which is the reverse of the fixture's allocation state and correct for this one.
     */
    $school = School::factory()->create();
    $bursar = prUser($school, ['finance.access', 'finance.payment.record']);
    $student = prStudent($school);

    // Money at the window with nothing named — it banks as credit and is drawn forward.
    $payment = prAccountPayment($school, $student, $bursar, 500_00);

    $termBill = prInvoice($school, $student, 300_00);
    $supplementary = prSupplementaryInvoice($school, $student, 150_00);

    prGet($bursar, $school, $payment->uuid)
        ->assertOk()
        ->assertInertia(function ($page) use ($termBill, $supplementary) {
            // KEYED BY NUMBER, NOT BY POSITION. Asserting `allocations.0` and `allocations.1` would
            // pass a receipt that stamped one kind onto every row whenever the draw order happened
            // to match, and would red on an ordering change that is not a defect. The PAIRING is
            // the claim: this number is the term bill, that number is the supplementary charge.
            $byNumber = collect($page->toArray()['props']['receipt']['allocations'])
                ->pluck('invoice_kind', 'invoice_number');

            expect($byNumber->all())->toBe([
                $termBill->displayNumber() => 'scheduled',
                $supplementary->displayNumber() => 'supplementary',
            ]);

            return $page;
        });
});

it('says an ACCOUNT-LEVEL payment sits on the account, applied to nothing', function () {
    /*
     * The other door. The receipt must state the position rather than list invoices it cannot name:
     * `nothing_applied` is what makes the page write the account sentence, and `unallocated_amount`
     * is the figure in it — computed by the server, never summed in the browser.
     *
     * WATCHED RED: `nothing_applied` hard-coded false. This arm fails on the flag while the invoice
     * arm above stays green, which is what tells the two apart.
     */
    $school = School::factory()->create();
    $bursar = prUser($school, ['finance.access', 'finance.payment.record']);
    $student = prStudent($school);
    $payment = prAccountPayment($school, $student, $bursar, 250_00);

    prGet($bursar, $school, $payment->uuid)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('refusal', null)
            ->where('receipt.allocations', [])
            ->where('receipt.nothing_applied', true)
            ->where('receipt.held_on_account', true)
            ->where('receipt.fully_applied', false)
            ->where('receipt.allocated_total.amount_minor', 0)
            ->where('receipt.unallocated_amount.amount_minor', 250_00)
        );
});

it('states the PART of an over-payment still held on the account beside the invoice it settled', function () {
    // The shape that would be wrong if the page decided anything by comparing amounts itself: money
    // through the invoice door that exceeds the invoice. Both sentences are true at once.
    $school = School::factory()->create();
    $bursar = prUser($school, ['finance.access', 'finance.payment.record']);
    $student = prStudent($school);
    $invoice = prInvoice($school, $student, 300_00);
    $payment = prInvoicePayment($school, $invoice, $bursar, 500_00);

    prGet($bursar, $school, $payment->uuid)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('receipt.allocated_total.amount_minor', 300_00)
            ->where('receipt.unallocated_amount.amount_minor', 200_00)
            ->where('receipt.held_on_account', true)
            ->where('receipt.fully_applied', false)
            ->where('receipt.nothing_applied', false)
        );
});

// ── 3. Isolation ─────────────────────────────────────────────────────────────────────────────────

it('gives another School’s payment uuid BYTE-IDENTICAL bytes to a nonexistent one', function () {
    /*
     * NOT "both are 404" — both being failures is not the property. The property is that the two
     * responses cannot be told apart, because a caller who can distinguish them has been told that a
     * payment they may not see nevertheless exists. Compared as raw response bodies, the way
     * InvoiceWireIdsTest pins the same property on the invoice wire ids.
     *
     * `app.debug` is NOT touched here, and that was measured rather than assumed. The obvious worry
     * is that a debug-mode 404 renders the `ModelNotFoundException` message, which carries the uuid —
     * so the two responses would differ. It does not arise: `bootstrap/app.php:157` renders EVERY
     * `NotFoundHttpException` as a fixed `{"message":"Resource not found"}` before the debug renderer
     * is reached. The first version of this arm forced `config(['app.debug' => false])` on that
     * theory; removing it left the arm green, so the line was deleted rather than left in looking
     * load-bearing. The browser drive reads the same bytes from the running app.
     *
     * WATCHED RED: a `resolveRouteBinding` on Payment doing
     * `static::withoutGlobalScopes()->where('uuid', $value)->firstOrFail()` — the shape of a
     * well-meant "fix" for a binding that seems not to find things. The foreign payment then
     * resolves and answers 200 while the nonexistent uuid still 404s.
     */
    $mine = School::factory()->create();
    $theirs = School::factory()->create();
    $reader = prUser($mine, ['finance.access']);

    $theirBursar = prUser($theirs, ['finance.access', 'finance.payment.record']);
    $theirStudent = prStudent($theirs);
    $theirPayment = prAccountPayment($theirs, $theirStudent, $theirBursar, 100_00);

    $foreign = prGet($reader, $mine, $theirPayment->uuid);
    $absent = prGet($reader, $mine, (string) Str::uuid());

    expect($foreign->status())->toBe(404)
        ->and($absent->status())->toBe(404)
        ->and($foreign->getContent())->toBe($absent->getContent(),
            'A caller can tell another School’s payment uuid apart from a nonexistent one. That '
            .'difference IS the disclosure: it answers "does this payment exist?" for a row the '
            .'caller may not see.');
});

// ── 4. The permission floor ──────────────────────────────────────────────────────────────────────

it('serves the receipt on finance.access — the same floor the statement it hangs off carries', function () {
    $school = School::factory()->create();
    $bursar = prUser($school, ['finance.access', 'finance.payment.record']);
    $student = prStudent($school);
    $payment = prAccountPayment($school, $student, $bursar, 100_00);

    // A seat holding finance.access and NOTHING else — no payment.record — reads the receipt.
    prGet(prUser($school, ['finance.access']), $school, $payment->uuid)->assertOk();
});

it('refuses the receipt to a signed-in user with no finance.access at all', function () {
    /*
     * The floor itself. WATCHED RED: `->withoutMiddleware('permission:finance.access')` on the route
     * — this arm then answers 200 to a seat with no finance ability whatsoever, and it is the only
     * arm that moves.
     */
    $school = School::factory()->create();
    $bursar = prUser($school, ['finance.access', 'finance.payment.record']);
    $student = prStudent($school);
    $payment = prAccountPayment($school, $student, $bursar, 100_00);

    prGet(prUser($school, ['student_directory.view']), $school, $payment->uuid)->assertForbidden();
});

// ── 5. The wire fields the statement row reads ───────────────────────────────────────────────────

it('carries receiptable + the reason on PaymentResource, and does NOT carry origin', function () {
    /*
     * The statement's payments feed is where the entry point decides whether to state the rule in
     * place. Two halves, and the second is the one that would rot silently: `origin` must stay OFF
     * the wire (PaymentResource's docblock reserves that as a separate decision), so a later "just
     * add origin, it's useful" edit fails here rather than in review.
     */
    $school = School::factory()->create();
    $bursar = prUser($school, ['finance.access', 'finance.payment.record']);
    $checker = prUser($school, ['finance.access', 'finance.opening-balance.approve']);
    $student = prStudent($school);
    prAccountPayment($school, $student, $bursar, 100_00);
    prMigratedPayment($school, $student, $checker, 50_00);

    $payments = test()->actingAs($bursar)
        ->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/students/{$student->uuid}/invoices")
        ->assertOk()
        ->json('payments');

    expect($payments)->toHaveCount(2);

    foreach ($payments as $payment) {
        expect($payment)->not->toHaveKey('origin');
    }

    $portal = collect($payments)->firstWhere('receiptable', true);
    $migrated = collect($payments)->firstWhere('receiptable', false);

    // Asserted as a positive count rather than `->not->toBeNull(...)`: a custom message on a NEGATED
    // Pest expectation is silently dropped (PestNegatedExpectationMessagesTest pins that), so the
    // message would have been a comment nobody would ever read on a failure.
    expect(collect($payments)->pluck('receiptable')->sort()->values()->all())->toBe([false, true],
        'The feed did not carry exactly one receiptable and one non-receiptable payment.')
        ->and($portal['receipt_refusal_reason'])->toBeNull()
        ->and($migrated['receipt_refusal_reason'])->toBe(Payment::RECEIPT_REFUSAL_REASON);
});

/*
|--------------------------------------------------------------------------
| The refusal CODE — the operational twin of the refusal sentence
|--------------------------------------------------------------------------
|
| `notification_deliveries.skip_reason` is `string(64)` and its vocabulary is
| `hard_bounce` / `no_address` / `sms_invalid_number`. The ~250-character parent-facing sentence does
| not fit and would be the wrong kind of value there if it did — so the refusal has two strings, one
| per audience, and they must not be able to disagree.
*/

it('issues no refusal code for a receiptable payment, on both receiptable origins', function (string $origin) {
    // BOTH, not just one. `isReceiptable()` is an allowlist of two, and an arm covering only
    // `portal` would pass against a version that had quietly dropped `gateway` — which is every
    // payment this workstream creates.
    $payment = new Payment(['origin' => $origin]);

    expect($payment->receiptRefusalCode())->toBeNull()
        ->and($payment->receiptRefusalReason())->toBeNull();
})->with([Payment::ORIGIN_PORTAL, Payment::ORIGIN_GATEWAY]);

it('names WCBS in the code only for a migrated payment', function () {
    $payment = new Payment(['origin' => Payment::ORIGIN_MIGRATED]);

    expect($payment->receiptRefusalCode())->toBe(Payment::RECEIPT_REFUSAL_CODE_MIGRATED)
        ->and($payment->receiptRefusalReason())->toBe(Payment::RECEIPT_REFUSAL_REASON);
});

it('refuses an origin in NEITHER list, and says it cannot confirm rather than blaming WCBS', function () {
    // THE ONLY INPUT THAT SEPARATES AN ALLOWLIST FROM A DENYLIST. `gateway` and `migrated` are
    // classified identically by both shapes; a denylist on `migrated` would let this through as
    // receiptable, and `isReceiptable()` is an allowlist precisely so it does not.
    //
    // IN MEMORY, NOT PERSISTED, AND THAT IS NOT A SHORTCUT. The
    // `finance_payments_origin_pairing_bi` trigger admits exactly portal/migrated/gateway, so this
    // row CANNOT be written — the branch is unreachable in production today and exists to catch the
    // day a fourth origin is added. Do not "fix" this into a database fixture; the trigger will
    // refuse it, correctly.
    $payment = new Payment(['origin' => 'nonsense']);

    // TWO AXES ON ONE INPUT, catching two different defects.
    //
    //   REFUSED AT ALL          — separates allowlist from denylist.
    //   REFUSED WITH WHICH ONE  — separates the corrected mapping from the defect
    //                             `receiptRefusalReason()`'s docblock records: an allowlist
    //                             predicate with a denylist explanation, which refused correctly and
    //                             then told a bursar the money came from WCBS. Both versions refuse;
    //                             only one tells the truth. An arm asserting `not null` passes the
    //                             version that reaches a parent with a false claim about their money.
    expect($payment->receiptRefusalCode())->toBe(Payment::RECEIPT_REFUSAL_CODE_UNKNOWN_ORIGIN)
        ->and($payment->receiptRefusalReason())->toBe(Payment::RECEIPT_REFUSAL_REASON_UNKNOWN_ORIGIN);
});

it('keeps every refusal code inside the column that will store it', function () {
    // 64 IS THE SCHEMA'S NUMBER, asserted here rather than trusted: a code added later that does not
    // fit is truncated or refused at write time, in a job, in production — the worst place to find
    // out. Read from information_schema rather than restated, so this cannot drift from the column.
    $length = (int) DB::selectOne(
        "SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_deliveries'
            AND COLUMN_NAME = 'skip_reason'"
    )->n;

    expect($length)->toBeGreaterThan(0)
        ->and(strlen(Payment::RECEIPT_REFUSAL_CODE_MIGRATED))->toBeLessThanOrEqual($length)
        ->and(strlen(Payment::RECEIPT_REFUSAL_CODE_UNKNOWN_ORIGIN))->toBeLessThanOrEqual($length);
});
