<?php

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
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * U7 COMMIT 1 — `kind` ON THE READ PATHS, AND ON THE SURFACES THAT PRECEDE AN IRREVERSIBLE ACT.
 *
 * WHAT WAS MISSING, AND WHY IT IS NOT THE SAME AS THE WIRE TEST BESIDE IT.
 * SupplementaryInvoiceWireTest proves a client can ASK for a supplementary invoice — the WRITE
 * direction, shipped by feat/u7-supplementary-invoice-wire. It says nothing about reading one back,
 * and it could not: InvoiceResource serialised fifteen keys and `kind` was not among them, which
 * that test records in a comment on its own helper ("InvoiceResource does not carry `kind`" — it
 * read the row out of the database instead). So an episode could carry an active term bill AND a
 * live supplementary charge at once, and no client could tell the two apart by any route.
 * docs/handoff/tickets/nothing-shows-which-invoices-are-supplementary.md is that finding; §1 names
 * the resource as the root of the other five read paths.
 *
 * ARMS a AND b GO OVER HTTP because the resource IS the wire — the only invoice serialiser in the
 * codebase, used by both generate 201s and by the per-student read. An arm asserting on the model
 * would be green with the resource key deleted, which is precisely the state being fixed.
 *
 * ARM c READS TYPESCRIPT AS TEXT, which is ugly, and the precedent is FinanceNavCoverageTest's and
 * ApprovalsQueueFeedCoverageTest's: there is no JavaScript test runner in this repository —
 * package.json carries vite, eslint, prettier and tsc, and no vitest or jest — so for the three
 * modal titles the choice is this or nothing. What it can and cannot see is stated on the arm.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: Student} */
function ikSetup(string $roleName = 'ik_bursar', array $abilities = ['finance.access', 'finance.invoice.generate']): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);

    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach ($abilities as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $role->syncPermissions($abilities);
    $admin->assignRole($roleName);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $student = Student::factory()->create(['school_id' => $school->id]);
    ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));

    return [$school, $admin, $student];
}

function ikGenerate($test, School $school, User $admin, Student $student, string $kind, string $what, int $minor = 150000)
{
    return $test->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/invoices", [
            'kind' => $kind,
            'lines' => [['description' => $what, 'amount_minor' => $minor, 'kind' => 'charge']],
        ]);
}

function ikRead(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/'.$relative);
}

// ── a — THE 201, BOTH VALUES ──────────────────────────────────────────────────

it('a — the generate 201 carries `kind`, and carries the value that was asked for', function () {
    [$school, $admin, $student] = ikSetup();

    // The term bill first, then the one-off charge against the same episode — the order a bursar
    // meets them in, and the state the ticket is about: two live invoices on one episode.
    $term = ikGenerate($this, $school, $admin, $student, 'scheduled', 'Tuition');
    $supp = ikGenerate($this, $school, $admin, $student, 'supplementary', 'Damaged locker door');

    $term->assertCreated()->assertJsonPath('kind', 'scheduled');
    $supp->assertCreated()->assertJsonPath('kind', 'supplementary');
});

// ── b — THE PER-STUDENT READ, BOTH VALUES, TOLD APART ─────────────────────────

it('b — the statement feed distinguishes the term bill from the supplementary charge', function () {
    [$school, $admin, $student] = ikSetup();

    ikGenerate($this, $school, $admin, $student, 'scheduled', 'Tuition')->assertCreated();
    ikGenerate($this, $school, $admin, $student, 'supplementary', 'Damaged locker door')->assertCreated();

    $read = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/students/{$student->uuid}/invoices")
        ->assertOk();

    // KEYED BY DESCRIPTION, NOT BY POSITION. Asserting invoices[0] and invoices[1] would pass a
    // resource that hard-coded one kind onto every row as long as the ORDER happened to match; and
    // it would red on an ordering change that is not a defect. The pairing is what matters: the row
    // whose line says "Tuition" is the term bill and the row whose line says "Damaged locker door"
    // is the supplementary charge.
    $byKind = collect($read->json('invoices'))->pluck('kind', 'lines.0.description');

    expect($byKind->all())->toBe([
        'Tuition' => 'scheduled',
        'Damaged locker door' => 'supplementary',
    ]);
});

// ── c — THE THREE MODAL TITLES ────────────────────────────────────────────────

it('c — every modal that precedes an act on a chosen invoice names the KIND, not the number alone', function () {
    /*
     * TICKET §5, WHICH CALLS THIS THE MOST EXPENSIVE OF THE SIX READ PATHS. All three modals titled
     * themselves with `invoice.display_number` and nothing else, and voiding the wrong invoice
     * discards its payment allocations.
     *
     * MEASURED AS: each file names the shared vocabulary helper, and no file interpolates
     * `display_number` into a Modal title on its own any more. The helper is the single place the
     * words "Term bill" / "Supplementary charge" are defined, so a title routed through it cannot
     * name a document differently from the statement row that opened it.
     *
     * WHAT THIS CANNOT SEE, stated rather than implied — it is a text check on a file, not a
     * behavioural one, and there is no JavaScript test runner in this repository:
     *   • whether the title RENDERS (a modal that never opens still satisfies this);
     *   • whether `kind` reaching the component is the invoice the operator clicked;
     *   • a fourth modal added later that acts on an invoice — nothing here would notice, because
     *     this list is written down and a written list is the thing that goes stale.
     * The browser drive in the report is what covers the first two for the three that exist.
     */
    $modals = [
        'resources/js/components/finance/request-void-modal.tsx',
        'resources/js/components/finance/issue-credit-note-modal.tsx',
        'resources/js/components/finance/record-payment-modal.tsx',
    ];

    $offenders = [];

    foreach ($modals as $modal) {
        $source = ikRead($modal);

        if (! str_contains($source, 'invoiceLabel')) {
            $offenders[] = $modal.' — does not name invoiceLabel()';
        }

        // The pre-U7 shape, exactly: a Modal title interpolating the number with no kind beside it.
        if (preg_match('/title=\{`[^`]*\$\{[^}]*display_number\}/', $source) === 1) {
            $offenders[] = $modal.' — titles itself with display_number alone';
        }
    }

    expect($offenders)->toBe([],
        'A modal that precedes an irreversible act on ONE invoice names it by number alone: '
        .implode('; ', $offenders)
        .'. An episode can carry an active term bill AND live supplementary charges at the same '
        .'time, so a number does not say which document is about to be voided, credited or paid. '
        .'Title it through invoiceLabel() in resources/js/lib/finance/invoice-kind.ts.');
});

// ── d — THE 201 AFTER CARRY-FORWARD CREDIT ────────────────────────────────────

it('d — the generate 201 reports the credit it just applied, and does not offer to void a settled invoice', function () {
    /*
     * THE SAME LIE THE DETAIL ROUTE WAS FIXED FOR, ONE CALLER OVER, AND THIS ONE SHIPPED.
     *
     * GenerateInvoice applies carry-forward credit INSIDE its own transaction: it writes
     * PaymentAllocation rows against the invoice it has just created, through
     * `applyCreditForward` (app/Finance/Actions/GenerateInvoice.php:576), and then returns
     * `$invoice->load('lines')`. That model was built by `create()`, so it
     * carries no `allocated_minor` and no `approved_credit_minor`, and InvoiceSettlement reads an
     * absent aggregate as zero — see `for` (app/Finance/Services/InvoiceSettlement.php:51). Both
     * generate routes serialise that model through InvoiceResource.
     *
     * So the 201 for an invoice that was SETTLED on the way in says `unpaid`, its whole total
     * outstanding, `can_record_payment: true` and `can_request_void: true` with no blocked reason —
     * a response offering to void an invoice that carries a payment allocation.
     *
     * IT IS REACHABLE ON THE ORDINARY CUTOVER PATH, which is what makes it worth an arm.
     * `PostOpeningBalanceBatch` (app/Finance/Actions/PostOpeningBalanceBatch.php:114)
     * turns every
     * negative migrated balance into a real payment row, so a student arriving from WCBS in credit
     * has an unallocated payment waiting, and the FIRST invoice a bursar raises for them is exactly this
     * case.
     *
     * THE GROUND TRUTH IS ASSERTED FROM THE DATABASE FIRST, deliberately. If this arm read the
     * allocation off the same 201 it is testing, a response that reported nothing would satisfy it
     * by agreeing with itself. The allocation row is the fact; the payload is the claim.
     */
    [$school, $admin, $student] = ikSetup('ik_bursar_d', [
        'finance.access', 'finance.invoice.generate', 'finance.payment.record',
    ]);

    // A small term bill, massively overpaid — the overpayment banks as account credit (W2).
    $termBill = ikGenerate($this, $school, $admin, $student, 'scheduled', 'Tuition', 2000);
    $termBillId = Invoice::withoutGlobalScopes()->where('uuid', $termBill->json('id'))->value('id');

    $account = ActiveSchool::runFor($school->id, fn () => BankAccount::create([
        'school_id' => $school->id,
        'label' => 'Test account',
        'bank_name' => 'Test Bank',
        'account_number' => '0123456789',
    ]));

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$termBill->json('id')}/payments", [
            'amount_minor' => 22000,
            'payer_name' => 'Guardian',
            // SchoolDay::today(), NOT now() — the two are a DIFFERENT DAY for one hour every day.
            // RecordPaymentRequest's `required_unless:received_at,<today>` builds its comparand from
            // SchoolDay (Africa/Lagos); now() is app.timezone (UTC). Between 23:00 and 00:00 UTC this
            // posted 2026-08-22 against a rule expecting 2026-08-23 and 422'd on received_at_reason —
            // caught by a quality run that happened to start at 00:07. 36 of the 39 'received_at'
            // fixtures in the suite already use SchoolDay::today(); this was one of the three that
            // did not, and the only one asserting SAME-DAY semantics, where the skew is load-bearing.
            'received_at' => SchoolDay::today(),
            'bank_account_id' => $account->uuid,
        ])->assertCreated();

    // The supplementary charge. 20000 of credit is waiting; this invoice is 12000, so
    // applyCreditForward settles it entirely on the way in.
    $supp = ikGenerate($this, $school, $admin, $student, 'supplementary', 'Damaged locker door', 12000);
    $suppId = Invoice::withoutGlobalScopes()->where('uuid', $supp->json('id'))->value('id');

    // GROUND TRUTH, FROM THE DATABASE, BEFORE ANY ASSERTION ABOUT THE PAYLOAD.
    $allocatedToSupp = (int) DB::table('finance_payment_allocations')
        ->where('invoice_id', $suppId)
        ->sum('amount_minor');

    expect($allocatedToSupp)->toBe(12000,
        'the fixture did not reach the state this arm is about — no carry-forward credit was '
        .'applied to the supplementary invoice, so the 201 has nothing to under-report');
    expect($termBillId)->not->toBe($suppId);

    // THE CLAIM. Five fields, and none of them is satisfiable by accident once the first two are
    // right: the derivation, the arithmetic, and the three eligibility flags a bursar acts on.
    $supp->assertJsonPath('settlement_state', 'settled')
        ->assertJsonPath('outstanding.amount_minor', 0)
        ->assertJsonPath('can_record_payment', false)
        ->assertJsonPath('can_request_void', false);

    // POSITIVE, AND DELIBERATELY SO. This was `->not->toBeNull($message)` and
    // PestNegatedExpectationMessagesTest reds on it: Pest's `->not->` is a proxy, and a custom
    // message written under it is exported and truncated into a generic sentence rather than
    // printed — the assertion holds, the diagnostic is lost. The positive form below both keeps the
    // message and says more: not merely non-null, but a non-empty string, which is what the UI puts
    // in the disabled button's tooltip.
    $reason = $supp->json('void_blocked_reason');

    expect(is_string($reason) && $reason !== '')->toBeTrue(
        'a settled invoice must say WHY it cannot be voided — the UI disables-with-reason rather '
        .'than hiding the control, and an empty reason renders a disabled button with no tooltip');
});
