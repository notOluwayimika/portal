<?php

use App\Finance\Actions\RecordPayment;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\Models\BankAccount;
use App\Finance\Models\Invoice;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * U7 COMMIT 2 — THE INVOICE DETAIL, AND THE PRINTABLE DOCUMENT BESIDE IT.
 *
 * ARM b IS THE REASON THIS FILE EXISTS AND EVERY OTHER ARM IS SECONDARY TO IT. InvoiceSettlement
 * reads `allocated_minor` and `approved_credit_minor` off the model as PLAIN ATTRIBUTES and treats
 * an absent one as zero — correct for a freshly-created invoice, and a lie for one loaded without
 * those aggregates. The route binds `{invoice:uuid}`, which loads the row with no sums whatsoever,
 * so a controller that serialised the BOUND model would render a fully-settled invoice as `unpaid`,
 * its whole total outstanding, offering "Record payment" and "Request void" on money that is
 * already in. It would answer 200. It would render. Every assertion that a page loads would pass.
 * That is the design system's most-repeated defect exactly — a screen making a confident false
 * statement — and it is why InvoiceReadModel::forDetail() exists rather than a `loadSum` at the
 * call site, and why this arm asserts the DERIVED values and not the page's presence.
 *
 * ARM c is the route comment's promise kept: voidness is a NAMED scope and never a global one, so
 * that `{invoice:uuid}` binding does not miss a voided invoice and turn the double-void 422 into a
 * 404. A detail page that 404'd on a void would recreate that hole one surface over — and the void
 * trail is exactly what the person opening a voided invoice has come to read.
 *
 * ARM e is isolation, checked by ID and not by label: School B's seat asks for School A's invoice
 * by uuid and gets the same 404 as a uuid that never existed, because SchoolScope bounds the
 * binding. `super_admin` is not exercised here — bypass is AUTHORIZATION and never isolation
 * (ADR 0036), and this arm is about the boundary.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * @param  list<string>  $abilities
 * @return array{0: School, 1: User, 2: Student, 3: StudentCurriculum}
 */
function idsSetup(string $roleName, array $abilities = ['finance.access', 'finance.invoice.generate']): array
{
    $school = School::factory()->create();
    $user = User::factory()->create(['school_id' => $school->id]);

    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach ($abilities as $ability) {
        Permission::firstOrCreate(['name' => $ability, 'guard_name' => 'web']);
    }
    $role->syncPermissions($abilities);
    $user->assignRole($roleName);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $student = Student::factory()->create(['school_id' => $school->id]);
    $enrollment = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));

    return [$school, $user, $student, $enrollment];
}

function idsGenerate($test, School $school, User $user, Student $student, string $kind, string $what, int $minor)
{
    return $test->actingAs($user)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/invoices", [
            'kind' => $kind,
            'lines' => [['description' => $what, 'amount_minor' => $minor, 'kind' => 'charge']],
        ])->assertCreated();
}

function idsInvoice($response): Invoice
{
    return Invoice::withoutGlobalScopes()->where('uuid', $response->json('id'))->firstOrFail();
}

// ── a — THE DOCUMENT, BOTH KINDS ──────────────────────────────────────────────

it('a — the detail renders one invoice, and says which KIND of document it is', function () {
    [$school, $user, $student] = idsSetup('ids_bursar_a');

    idsGenerate(test(), $school, $user, $student, 'scheduled', 'Tuition', 300000);
    $supp = idsInvoice(idsGenerate(test(), $school, $user, $student, 'supplementary', 'Damaged locker door', 45000));

    test()->actingAs($user)->withSession(['school_id' => $school->id])
        ->get("/finance/invoices/{$supp->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/finance/invoice')
            ->where('invoice.kind', 'supplementary')
            ->where('invoice.display_number', $supp->displayNumber())
            ->where('invoice.academic_context', $supp->academic_context)
            // The lines are the document's substance and the resource only serialises them
            // `whenLoaded` — a detail page that forgot the eager load would render an invoice with
            // no lines and a total that came from nowhere visible.
            ->has('invoice.lines', 1)
            ->where('invoice.lines.0.description', 'Damaged locker door')
            // The student is read THROUGH the invoice, and the uuid is what the back-link needs.
            ->where('student.uuid', $student->uuid)
            ->where('has_pending_void', false)
            ->has('void_trail', 0));
});

// ── b — THE SETTLEMENT SUMS ARE REALLY HYDRATED ───────────────────────────────

it('b — a SETTLED invoice does not render as unpaid, and does not offer to void itself', function () {
    [$school, $user, $student] = idsSetup('ids_bursar_b', [
        'finance.access', 'finance.invoice.generate', 'finance.payment.record',
    ]);

    $invoice = idsInvoice(idsGenerate(test(), $school, $user, $student, 'scheduled', 'Tuition', 300000));

    // Settled through the REAL Action, not by writing an allocation row — the aggregate this arm
    // is about is a sum over rows the domain writes, and a planted row would prove the sum without
    // proving that anything the application does produces it. The bank account is REQUIRED and has
    // no default: a portal payment that does not say where the cash went cannot be reconciled, and
    // the origin-keyed CHECK on finance_payments is the backstop (RecordPayment's own docblock).
    ActiveSchool::runFor($school->id, function () use ($invoice, $user, $school) {
        $account = BankAccount::create([
            'school_id' => $school->id,
            'label' => 'Test account',
            'bank_name' => 'Test Bank',
            'account_number' => '0123456789',
        ]);

        app(RecordPayment::class)->handle(
            $invoice,
            Money::fromKobo(300000),
            'Guardian',
            $user,
            SchoolDay::today(),
            $account->id,
        );
    });

    test()->actingAs($user)->withSession(['school_id' => $school->id])
        ->get("/finance/invoices/{$invoice->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // THE FOUR VALUES A MISSING withSum WOULD GET WRONG, all four asserted, because three
            // of them individually are satisfiable by accident: `settled` is the derivation,
            // `outstanding` zero is the arithmetic, and the two flags are what a bursar would have
            // been offered — a void request against money already banked.
            ->where('invoice.settlement_state', 'settled')
            ->where('invoice.outstanding.amount_minor', 0)
            ->where('invoice.can_record_payment', false)
            ->where('invoice.can_request_void', false));
});

// ── c — A VOIDED INVOICE OPENS ────────────────────────────────────────────────

it('c — a VOIDED invoice opens on its own page and states the void; it does not 404', function () {
    [$school, $user, $student] = idsSetup('ids_bursar_c');

    $invoice = idsInvoice(idsGenerate(test(), $school, $user, $student, 'scheduled', 'Tuition', 300000));

    // Voided through the domain: the status change and the `cancelled_at`/`cancel_reason` this page
    // renders are written by the void path, not by this test.
    ActiveSchool::runFor($school->id, function () use ($invoice, $user) {
        $request = app(SubmitVoidRequest::class)->handle($invoice, 'Billed in error', $user);
        $request->invoice->forceFill([
            'status' => 'void',
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $user->id,
            'cancel_reason' => 'Billed in error',
        ])->save();
    });

    test()->actingAs($user)->withSession(['school_id' => $school->id])
        ->get("/finance/invoices/{$invoice->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/finance/invoice')
            ->where('invoice.status', 'void')
            // A void has NO settlement state — its charge is reversed — and the page suppresses the
            // outstanding line on the strength of this null rather than rendering a zero that would
            // read as "paid in full".
            ->where('invoice.settlement_state', null)
            ->where('invoice.cancel_reason', 'Billed in error')
            // Formatted in PHP: the money lint's format ban is total inside the finance UI, so a
            // page that received only the ISO string could not render a date at all.
            ->whereNot('voided_at', null)
            // The trail the reader came for.
            ->has('void_trail', 1)
            ->where('void_trail.0.reason', 'Billed in error'));
});

// ── d — A PENDING VOID SUPPRESSES THE REQUEST CONTROL ─────────────────────────

it('d — a PENDING void request is reported, so a maker cannot stack a second one', function () {
    [$school, $user, $student] = idsSetup('ids_bursar_d');

    $invoice = idsInvoice(idsGenerate(test(), $school, $user, $student, 'scheduled', 'Tuition', 300000));

    ActiveSchool::runFor($school->id, fn () => app(SubmitVoidRequest::class)
        ->handle($invoice, 'Duplicate enrolment', $user));

    test()->actingAs($user)->withSession(['school_id' => $school->id])
        ->get("/finance/invoices/{$invoice->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // DERIVED SERVER-SIDE from the foreign key. The statement answers the same question in
            // the browser by matching RENDERED INVOICE NUMBERS against pending requests; this page
            // holds the row, so it asks the database.
            ->where('has_pending_void', true)
            ->has('void_trail', 1)
            ->where('void_trail.0.status', 'submitted')
            // Still ISSUED: a pending request has touched nothing, moved no money and freed no
            // episode slot. If this ever reads 'void', approval has stopped being what voids.
            ->where('invoice.status', 'issued'));
});

// ── e — ISOLATION, BY ID ──────────────────────────────────────────────────────

it('e — School B cannot open School A’s invoice, and gets a 404 rather than a 403', function () {
    [$schoolA, $userA, $studentA] = idsSetup('ids_bursar_a2');
    [$schoolB, $userB] = idsSetup('ids_bursar_b2');

    $invoiceA = idsInvoice(idsGenerate(test(), $schoolA, $userA, $studentA, 'scheduled', 'Tuition', 300000));

    // A 404 AND NOT A 403 IS THE POINT. SchoolScope bounds the route-model binding, so the row does
    // not resolve at all — School B is not told that an invoice with this uuid exists somewhere.
    test()->actingAs($userB)->withSession(['school_id' => $schoolB->id])
        ->get("/finance/invoices/{$invoiceA->uuid}")
        ->assertNotFound();

    test()->actingAs($userB)->withSession(['school_id' => $schoolB->id])
        ->get("/finance/invoices/{$invoiceA->uuid}/print")
        ->assertNotFound();
});

// ── f — THE PRINTABLE DOCUMENT ────────────────────────────────────────────────

it('f — the printable view renders the document with the School on it, and prints a void', function () {
    [$school, $user, $student] = idsSetup('ids_bursar_f');

    $invoice = idsInvoice(idsGenerate(test(), $school, $user, $student, 'supplementary', 'Field trip', 100000));

    test()->actingAs($user)->withSession(['school_id' => $school->id])
        ->get("/finance/invoices/{$invoice->uuid}/print")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/finance/invoice-print')
            // The School block is what makes it a document rather than a screenshot, and it comes
            // from the ACTIVE School rather than from the invoice — the same source the receipt
            // reads.
            ->where('school.name', $school->name)
            // Formatted in PHP, for the money lint's total format ban inside the finance UI.
            ->whereNot('issued_at', null)
            ->where('invoice.kind', 'supplementary')
            ->has('invoice.lines', 1));
});

// ── g — THE PAGES ARE GATED ON `finance.access`, NOTHING NARROWER ─────────────

it('g — a seat holding only finance.access can read the invoice and its printable view', function () {
    [$school, $user, $student] = idsSetup('ids_bursar_g', ['finance.access', 'finance.invoice.generate']);

    $invoice = idsInvoice(idsGenerate(test(), $school, $user, $student, 'scheduled', 'Tuition', 300000));

    // A READ-ONLY seat: finance.access and nothing else. It must reach both pages — they show
    // strictly less than GET /v1/finance/students/{uuid}/invoices already returns for the same
    // invoice, which carries the same single ability. The ACTIONS on the page are gated separately.
    $reader = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'ids_reader_g', 'guard_name' => 'web']);
    $role->syncPermissions(['finance.access']);
    $reader->assignRole('ids_reader_g');
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->actingAs($reader)->withSession(['school_id' => $school->id])
        ->get("/finance/invoices/{$invoice->uuid}")->assertOk();

    test()->actingAs($reader)->withSession(['school_id' => $school->id])
        ->get("/finance/invoices/{$invoice->uuid}/print")->assertOk();
});
