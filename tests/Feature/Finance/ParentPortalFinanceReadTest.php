<?php

/*
 * THE PARENT PORTAL'S ONE FINANCE READ — GET /api/parent/finance/wards.
 *
 * The guardian-facing payment portal is being built against the shape this endpoint returns, so the
 * shape freezes when this lands. Three properties are load-bearing and each is planted-and-broken
 * below rather than merely asserted:
 *
 *   1. OWN WARDS ONLY, derived server-side. There is no identifier on the request, so there is no
 *      uuid to tamper with — the test that matters is that a second guardian's child in the same
 *      School never appears.
 *   2. THE FIGURE IS THE REMAINING AMOUNT. InvoiceSettlement reads the settlement aggregates off
 *      the model and treats an ABSENT one as zero, so any invoice that does not come through
 *      InvoiceReadModel serialises `outstanding` equal to its FULL TOTAL, silently. On a payer
 *      surface that is a parent asked twice for the same money, with no error anywhere. The
 *      part-paid case below asserts a KNOWN figure for exactly that reason.
 *   3. NO STAFF FIELDS. can_record_payment / can_submit_credit_note / can_request_void /
 *      void_blocked_reason / lines are the bursar's answers and a payer must never receive them.
 *      Asserted by ABSENCE, explicitly, because a field nobody asserts about is a field that
 *      reappears the first time someone reuses InvoiceResource here.
 *
 * Fixture helpers are `ppf_`-prefixed: Pest hoists test-file functions into one global namespace, so
 * an unprefixed `world()` or `invoice()` collides with another file's identically-named helper.
 */

use App\Finance\Actions\ApproveCreditNote;
use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\Actions\SubmitCreditNote;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Models\Invoice;
use App\Finance\Services\GuardianPaymentAuthorisation;
use App\Models\Curriculum;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/*
|--------------------------------------------------------------------------
| Fixture
|--------------------------------------------------------------------------
*/

/** A student in $school. */
function ppf_student(School $school, string $first): Student
{
    return Student::create([
        'school_id' => $school->id,
        'first_name' => $first,
        'last_name' => 'Child',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);
}

/**
 * A user holding the `guardian` role and a Guardian ROW in $school, with $students attached as
 * wards. Both halves are required: the role carries `parent_portal.access` (RbacSeeder grantsMap),
 * the row is what forUserInActiveSchool() resolves.
 *
 * @param  array<int, Student>  $students
 * @return array{0: User, 1: Guardian}
 */
function ppf_guardian(School $school, array $students): array
{
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('guardian');
    setPermissionsTeamId(null);
    $user->schools()->syncWithoutDetaching([$school->id]);

    $guardian = al_makeGuardian($school->id, $user->id);
    foreach ($students as $student) {
        $guardian->students()->attach($student->id, [
            'relationship' => 'mother', 'is_primary' => true, 'can_login' => true,
        ]);
    }

    return [$user, $guardian];
}

/** An issued invoice for a FRESH enrollment episode of $student, for $kobo. */
function ppf_invoice(School $school, Student $student, int $kobo): Invoice
{
    return ActiveSchool::runFor($school->id, function () use ($school, $student, $kobo) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        return app(GenerateInvoice::class)->handle(
            $enrollment->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo))],
            InvoiceKind::Scheduled,
        );
    });
}

function ppf_pay(School $school, Invoice $invoice, int $kobo): void
{
    ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)->handle(
        $invoice,
        Money::fromKobo($kobo),
        'Payer',
        al_makeUser($school->id),
        SchoolDay::today(),
        testBankAccountId($school->id),
    ));
}

function ppf_void(School $school, Invoice $invoice): void
{
    ActiveSchool::runFor($school->id, function () use ($school, $invoice) {
        $request = app(SubmitVoidRequest::class)->handle($invoice, 'entered in error', al_makeUser($school->id));
        app(ApproveVoidRequest::class)->handle($request, al_makeUser($school->id));
    });
}

/** Settle an invoice with an APPROVED credit note — settled without a payment. */
function ppf_creditOff(School $school, Invoice $invoice, int $kobo): void
{
    ActiveSchool::runFor($school->id, function () use ($school, $invoice, $kobo) {
        $note = app(SubmitCreditNote::class)->handle(
            $invoice, Money::fromKobo($kobo), CreditNoteKind::CreditNote, null,
            al_makeUser($school->id), testBankAccountId($school->id),
        );
        app(ApproveCreditNote::class)->handle($note, al_makeUser($school->id));
    });
}

/** Drive the endpoint as $user in $school. */
function ppf_hit(School $school, User $user)
{
    return test()->actingAs($user)
        ->withSession(['school_id' => $school->id])
        ->getJson('/api/parent/finance/wards');
}

/*
|--------------------------------------------------------------------------
| 1 · Own wards, and only own wards
|--------------------------------------------------------------------------
*/

it('returns the guardian OWN wards and no other parent child in the same school', function () {
    $school = al_makeSchool();
    $mine = ppf_student($school, 'Ada');
    $theirs = ppf_student($school, 'Zoe');

    // Both children are billed identically, so the only difference between them is the pivot row.
    ppf_invoice($school, $mine, 300000);
    ppf_invoice($school, $theirs, 300000);

    [$me] = ppf_guardian($school, [$mine]);
    ppf_guardian($school, [$theirs]);

    $response = ppf_hit($school, $me)->assertOk();

    $ids = collect($response->json('data'))->pluck('student.id')->all();
    expect($ids)->toBe([$mine->uuid])
        ->and($ids)->not->toContain($theirs->uuid);

    // …and the other parent gets the mirror image. Without this control the assertion above could
    // pass on an endpoint that returns the first student in the school.
    [$them] = ppf_guardian($school, [$theirs]);
    expect(collect(ppf_hit($school, $them)->assertOk()->json('data'))->pluck('student.id')->all())
        ->toBe([$theirs->uuid]);
});

it('returns BOTH wards for a guardian with two', function () {
    $school = al_makeSchool();
    $one = ppf_student($school, 'Ada');
    $two = ppf_student($school, 'Bim');
    ppf_invoice($school, $one, 100000);
    ppf_invoice($school, $two, 250000);

    [$me] = ppf_guardian($school, [$one, $two]);

    $data = collect(ppf_hit($school, $me)->assertOk()->json('data'));

    expect($data)->toHaveCount(2)
        ->and($data->pluck('student.id')->sort()->values()->all())
        ->toBe(collect([$one->uuid, $two->uuid])->sort()->values()->all());
});

it('returns an EMPTY collection, not an error, for a guardian with no wards', function () {
    $school = al_makeSchool();
    ppf_student($school, 'Ada');            // a child exists in the school; it is not theirs
    [$me] = ppf_guardian($school, []);      // guardian ROW, no pivot rows

    ppf_hit($school, $me)->assertOk()->assertExactJson(['data' => []]);
});

it('returns an EMPTY collection for a guardian-role user with no guardian row in this school', function () {
    $school = al_makeSchool();
    ppf_student($school, 'Ada');

    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('guardian');
    setPermissionsTeamId(null);
    $user->schools()->syncWithoutDetaching([$school->id]);

    ppf_hit($school, $user)->assertOk()->assertExactJson(['data' => []]);
});

/*
|--------------------------------------------------------------------------
| 2 · Which invoices appear
|--------------------------------------------------------------------------
*/

it('keeps a ward with NO outstanding invoices, carrying an empty invoice list', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $data = ppf_hit($school, $me)->assertOk()->json('data');

    // Absence of debt is information — "paid up" must not read as "not your child".
    expect($data)->toHaveCount(1)
        ->and($data[0]['student']['id'])->toBe($ward->uuid)
        ->and($data[0]['invoices'])->toBe([]);
});

it('EXCLUDES a fully settled invoice — by payment, and by approved credit note', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $paidOff = ppf_invoice($school, $ward, 300000);
    ppf_pay($school, $paidOff, 300000);

    $creditedOff = ppf_invoice($school, $ward, 150000);
    ppf_creditOff($school, $creditedOff, 150000);

    $open = ppf_invoice($school, $ward, 80000);

    $invoices = ppf_hit($school, $me)->assertOk()->json('data.0.invoices');

    // The open invoice is the control: without it an empty list would also pass a broken read.
    expect(collect($invoices)->pluck('id')->all())->toBe([$open->uuid]);
});

it('EXCLUDES a void invoice', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $voided = ppf_invoice($school, $ward, 300000);
    ppf_void($school, $voided);
    $open = ppf_invoice($school, $ward, 80000);

    $invoices = ppf_hit($school, $me)->assertOk()->json('data.0.invoices');

    expect(collect($invoices)->pluck('id')->all())->toBe([$open->uuid]);
});

/*
|--------------------------------------------------------------------------
| 3 · The figure — the forStudent() trap
|--------------------------------------------------------------------------
*/

it('reports the REMAINING amount on a part-paid invoice, not the full total', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $invoice = ppf_invoice($school, $ward, 300000);
    ppf_pay($school, $invoice, 120000);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0.invoices.0');

    // 300000 − 120000 = 180000. THE known figure: an invoice that skips the read model's settlement
    // aggregates reports 300000 here, 200 OK, and nothing else in the response changes.
    expect($row['id'])->toBe($invoice->uuid)
        ->and($row['total'])->toBe(['amount_minor' => 300000, 'currency' => 'NGN'])
        ->and($row['outstanding'])->toBe(['amount_minor' => 180000, 'currency' => 'NGN']);
});

it('reports the remaining amount when BOTH a payment and an approved credit note have landed', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $invoice = ppf_invoice($school, $ward, 300000);
    ppf_pay($school, $invoice, 120000);
    ppf_creditOff($school, $invoice, 30000);

    // 300000 − 120000 − 30000 = 150000.
    expect(ppf_hit($school, $me)->assertOk()->json('data.0.invoices.0.outstanding'))
        ->toBe(['amount_minor' => 150000, 'currency' => 'NGN']);
});

/*
|--------------------------------------------------------------------------
| 4 · The shape, and what it must never carry
|--------------------------------------------------------------------------
*/

it('carries NONE of the staff eligibility flags or internal state', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);
    ppf_invoice($school, $ward, 300000);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0.invoices.0');

    foreach (['can_record_payment', 'can_submit_credit_note', 'can_request_void', 'void_blocked_reason', 'lines'] as $leak) {
        expect($row)->not->toHaveKey($leak);
    }

    // Pinned as an exact key set, so a field ADDED later fails here rather than reaching the portal
    // unreviewed — the shape is a contract a second developer is building against.
    expect(array_keys($row))->toBe(['id', 'display_number', 'kind', 'academic_context', 'total', 'outstanding']);
});

it('carries ward identity as uuid and name ONLY', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0');

    expect(array_keys($row))->toBe(['student', 'invoices', 'account'])
        ->and(array_keys($row['student']))->toBe(['id', 'name'])
        ->and($row['student']['name'])->toBe('Ada Child');

    foreach (['date_of_birth', 'admission_number', 'current_class', 'gender', 'photo'] as $leak) {
        expect($row['student'])->not->toHaveKey($leak);
    }
});

it('carries the account position in the Money wire shape', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    // Overpay: 300000 billed, 350000 paid — the invoice settles and 50000 carries on the ACCOUNT,
    // where an invoice-only response would report the parent's position as zero.
    $invoice = ppf_invoice($school, $ward, 300000);
    ppf_pay($school, $invoice, 350000);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0');

    expect($row['invoices'])->toBe([])
        ->and($row['account']['balance'])->toBe(['amount_minor' => -50000, 'currency' => 'NGN'])
        ->and($row['account']['available_credit'])->toBe(['amount_minor' => 50000, 'currency' => 'NGN']);
});

/*
|--------------------------------------------------------------------------
| 5 · The gate
|--------------------------------------------------------------------------
*/

it('refuses a user who is not a guardian', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    // Control: the guardian is served, so the refusal below is about the caller and not the URL.
    ppf_hit($school, $me)->assertOk();

    $teacher = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $teacher->assignRole('teacher');
    setPermissionsTeamId(null);
    $teacher->schools()->syncWithoutDetaching([$school->id]);

    ppf_hit($school, $teacher)->assertForbidden();
});

it('refuses an unauthenticated caller', function () {
    $school = al_makeSchool();

    test()->withSession(['school_id' => $school->id])
        ->getJson('/api/parent/finance/wards')
        ->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| 6 · The write-side authorisation helper
|--------------------------------------------------------------------------
*/

it('mayPay() is TRUE for a ward invoice and FALSE for another parent child invoice', function () {
    $school = al_makeSchool();
    $mine = ppf_student($school, 'Ada');
    $theirs = ppf_student($school, 'Zoe');

    $mineInvoice = ppf_invoice($school, $mine, 300000);
    $theirsInvoice = ppf_invoice($school, $theirs, 300000);

    [$me] = ppf_guardian($school, [$mine]);
    ppf_guardian($school, [$theirs]);

    ActiveSchool::runFor($school->id, function () use ($me, $mineInvoice, $theirsInvoice) {
        $authorisation = app(GuardianPaymentAuthorisation::class);

        expect($authorisation->mayPay($me, $mineInvoice))->toBeTrue()
            ->and($authorisation->mayPay($me, $theirsInvoice))->toBeFalse();
    });
});

it('mayPay() is FALSE for a user with no guardian row in the active school', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    $invoice = ppf_invoice($school, $ward, 300000);
    ppf_guardian($school, [$ward]);

    $stranger = al_makeUser($school->id);

    ActiveSchool::runFor($school->id, fn () => expect(
        app(GuardianPaymentAuthorisation::class)->mayPay($stranger, $invoice)
    )->toBeFalse());
});
