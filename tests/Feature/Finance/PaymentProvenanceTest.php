<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordAccountPayment;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provenance on finance_payments (opening-balance spec §4) — the `origin` predicate, its CHECK, and the
 * seed trap that makes the reserved migrated receipt band safe.
 *
 * ─── CAN THE SEED TRAP BE ENFORCED? Honestly: NO, not by a mechanism. Only asserted. ───
 *
 * The invariant is the ABSENCE of an optional third argument at two call sites. Everything the project
 * normally reaches for was considered against that shape and each fails on it:
 *
 *  - A DB constraint cannot see it. The database observes the VALUE that lands in `reference`; a seeded
 *    counter produces 900,000,002, which is a perfectly legal unsigned bigint under
 *    UNIQUE (school_id, reference). Nothing at the schema level distinguishes it from an intended
 *    migrated reference. A CHECK forbidding portal rows above the floor is the closest thing that
 *    exists, and it is not available here: `origin` and `reference` are both on the row, so
 *    `CHECK (origin = 'migrated' OR reference < 900000000)` IS expressible — but it converts a silent
 *    permanent corruption into a hard 3819 on every payment the school records after the import, i.e.
 *    it takes the bursar's front door down rather than preventing the mistake. It is recorded here as
 *    considered and rejected, not overlooked; if the project later decides a loud outage beats silent
 *    band corruption, that CHECK is the mechanism and this is the note to reopen.
 *  - A lint cannot see it either, not honestly. `bin/ci-identifier-generation-lint.php` exists and
 *    greps for identifier-generation bypasses, so the machinery is there — but the rule would be
 *    "Sequences::next with scope 'finance_payment' must have exactly two arguments", pinned by a string
 *    literal at a call site, and a rename of the scope or a variable holding it walks straight past it.
 *    A lint that can be evaded by extracting a variable is wallpaper with a build step.
 *  - Static analysis has no opinion: the parameter is genuinely optional and both arities type-check.
 *
 * So THE TESTS BELOW ARE THE ONLY MECHANISM, and that is stated plainly rather than dressed up. They
 * are real ones — they fail the suite, and the suite is bin/quality step 15 — but they catch the
 * mistake at push time, not at write time. The comments at Payment::MIGRATED_REFERENCE_FLOOR and at
 * both Actions are documentation, not enforcement.
 *
 * AND A TEST PINS ONLY THE DOORS IT DRIVES. Both live doors share one counter — scope
 * 'finance_payment', key the school id — so whichever runs FIRST after an import is the one that
 * creates the row, and Sequences evaluates the seed on first use only. A seed added to just one Action
 * would corrupt the band through both, because the counter is shared. So BOTH are driven below, one
 * seed case each, and a red was watched for each. A THIRD call site on this scope — commit 4's posting
 * Action, if it allocates references through Sequences rather than computing them in the band — would
 * be invisible to both, and must arrive with its own case.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: Student, 3: callable(int):Invoice} */
function provenanceSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id]);

    $makeInvoice = fn (int $kobo) => app(GenerateInvoice::class)->handle(
        StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ])->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo))],
    );

    return [$school, $admin, $student, $makeInvoice];
}

/**
 * Raw-insert a finance_payments row, bypassing every PHP path — this is what makes the refusals below
 * proofs about the DATABASE rather than about an Action.
 */
function insertPaymentRow(int $schoolId, int $studentId, int $reference, string $origin, string $method = 'manual'): int
{
    return DB::table('finance_payments')->insertGetId([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $schoolId,
        'student_id' => $studentId,
        'reference' => $reference,
        'amount_minor' => 5000,
        'amount_currency' => 'NGN',
        'received_at' => SchoolDay::today(), // PAIRED WITH origin, because finance_payments_bank_account_origin_shape requires it: a portal
        // payment must name an account and a migrated one must not. This helper is called with a
        // deliberately invalid origin in some arms, so the pairing is derived rather than fixed.
        'bank_account_id' => $origin === 'migrated' ? null : testBankAccountId(), 'payer_name' => 'Raw',
        'method' => $method,
        'origin' => $origin,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ── 1. The CHECK, at the database ──────────────────────────────────────────────────────────────────

it('origin — a third value is refused 3819 at the INSERT; an UPDATE to one is refused 1644 by the append-only trigger', function () {
    [$school, , $student] = provenanceSetup();

    // A third value, straight into the table with no Action, no FormRequest, no model. 3819 is a CHECK
    // violation: the refusal is the constraint, not PHP.
    try {
        insertPaymentRow($school->id, $student->id, 1, 'wcbs');
        throw new RuntimeException('expected the origin CHECK to refuse a third value');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(3819);
    }

    // COLLATE utf8mb4_bin: a case variant of a LEGAL value is still a third value. Under the table's
    // default utf8mb4_unicode_ci this would have inserted, and `origin = 'migrated'` filters would have
    // matched it — a green CHECK admitting a value nobody wrote a filter for.
    try {
        insertPaymentRow($school->id, $student->id, 2, 'Migrated');
        throw new RuntimeException('expected the origin CHECK to refuse a case variant');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(3819);
    }

    // Negative: both legal values insert, so the CHECK is not simply refusing everything.
    $portalId = insertPaymentRow($school->id, $student->id, 3, 'portal');
    insertPaymentRow($school->id, $student->id, Payment::MIGRATED_REFERENCE_FLOOR + 1, 'migrated');
    expect(DB::table('finance_payments')->whereIn('origin', ['portal', 'migrated'])->count())->toBe(2);

    // The UPDATE door on THIS table was already sealed harder than a CHECK: finance_payments is
    // append-only, so `finance_payments_no_update` (BEFORE UPDATE, SIGNAL 45000 → driver 1644) fires
    // ahead of any CHECK evaluation. Asserting 3819 here would be asserting the wrong mechanism — the
    // refusal is real and it is at the database, it is just the trigger's. Same shape as
    // CurrencyShapeConstraintTest's path 3.
    try {
        DB::table('finance_payments')->where('id', $portalId)->update(['origin' => 'wcbs']);
        throw new RuntimeException('expected the append-only trigger to refuse the UPDATE');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1644);
    }
});

// ── 2. The default, through an untouched write path ────────────────────────────────────────────────

it("default — the existing payment path writes origin = 'portal' with no code change", function () {
    [$school, $admin, , $makeInvoice] = provenanceSetup();

    ActiveSchool::runFor($school->id, function () use ($admin, $makeInvoice) {
        $invoice = $makeInvoice(10000);

        // RecordPayment does not mention `origin` anywhere. The column's NOT NULL DEFAULT is what makes
        // every row this system issues self-describing without a single edit to the write path.
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(10000), 'Payer', $admin, SchoolDay::today(), testBankAccountId());

        $row = DB::table('finance_payments')->latest('id')->first();
        expect($row->origin)->toBe('portal')
            ->and($row->external_reference)->toBeNull();
    });
});

// ── 3. The seed trap, at BOTH doors onto the shared counter ────────────────────────────────────────

/**
 * Put a school in the exact state that makes the trap reachable: migrated payments sitting in the
 * reserved band, and NO `finance_payment` counter row yet — the moment after opening balances post and
 * before that school's first portal payment. Sequences evaluates a seed on first use only, so this is
 * the one moment at which a seed closure could adopt the band.
 */
function plantImportedBandRow(int $schoolId, int $studentId): void
{
    insertPaymentRow($schoolId, $studentId, Payment::MIGRATED_REFERENCE_FLOOR + 1, 'migrated', 'migrated');

    expect(DB::table('sequences')->where('scope', 'finance_payment')->where('key', (string) $schoolId)->exists())
        ->toBeFalse();
}

it('seed trap, INVOICE door — a migrated row in the reserved band does NOT drag the live receipt sequence up with it', function () {
    [$school, $admin, $student, $makeInvoice] = provenanceSetup();

    plantImportedBandRow($school->id, $student->id);

    ActiveSchool::runFor($school->id, function () use ($admin, $makeInvoice) {
        $invoice = $makeInvoice(10000);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(10000), 'Payer', $admin, SchoolDay::today(), testBankAccountId());
    });

    // Selected by payer_name, NOT by origin: this test is about the COUNTER, and keying it on the
    // column the previous test owns would make it fail for that test's reason instead of its own.
    $reference = (int) DB::table('finance_payments')->where('payer_name', 'Payer')->value('reference');

    // Not merely "below the floor" — the counter must have started at 0, which is what the ABSENT seed
    // closure buys. A seeded counter would return MIGRATED_REFERENCE_FLOOR + 2 here and this assertion
    // is the one that catches it.
    expect($reference)->toBe(1)
        ->and($reference)->toBeLessThan(Payment::MIGRATED_REFERENCE_FLOOR);
});

it('seed trap, ACCOUNT door — the same counter, reached without an invoice, must not adopt the band either', function () {
    // RecordAccountPayment is the OTHER door onto scope 'finance_payment', keyed on the same school
    // (RecordAccountPayment's own comment: "Same sequence scope and key as RecordPayment — one receipt
    // series per school across both doors"). It is the door a bursar reaches through
    // POST …/students/{student:uuid}/payments, under the same finance.payment.record permission as the
    // invoice door, so it is exactly as likely to be the FIRST payment a school takes after an import —
    // and first use is the only moment a seed closure is evaluated.
    //
    // The invoice-door test above cannot stand in for this one. A seed added to RecordAccountPayment
    // alone leaves that test green, because it never calls this Action; the counter it corrupts is then
    // shared, so every subsequent receipt through BOTH doors lands in the reserved band.
    [$school, $admin, $student] = provenanceSetup();

    plantImportedBandRow($school->id, $student->id);

    ActiveSchool::runFor($school->id, function () use ($admin, $student) {
        app(RecordAccountPayment::class)->handle($student->id, Money::fromKobo(10000), 'AccountPayer', $admin, SchoolDay::today(), testBankAccountId());
    });

    $reference = (int) DB::table('finance_payments')->where('payer_name', 'AccountPayer')->value('reference');

    expect($reference)->toBe(1)
        ->and($reference)->toBeLessThan(Payment::MIGRATED_REFERENCE_FLOOR);
});
