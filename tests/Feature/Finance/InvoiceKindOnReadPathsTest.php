<?php

use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
function ikSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);

    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'ik_bursar', 'guard_name' => 'web']);
    foreach (['finance.access', 'finance.invoice.generate'] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $role->syncPermissions(['finance.access', 'finance.invoice.generate']);
    $admin->assignRole('ik_bursar');
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

function ikGenerate($test, School $school, User $admin, Student $student, string $kind, string $what)
{
    return $test->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/invoices", [
            'kind' => $kind,
            'lines' => [['description' => $what, 'amount_minor' => 150000, 'kind' => 'charge']],
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
