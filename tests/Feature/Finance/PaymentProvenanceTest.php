<?php

use App\Finance\Actions\GenerateInvoice;
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
 * So THE TEST BELOW IS THE ONLY MECHANISM, and it is stated plainly as such rather than dressed up. It
 * is a real one — it fails the suite, and the suite is bin/quality step 14 — but it catches the mistake
 * at push time, not at write time, and it protects the invariant only for the scope and shape it
 * exercises. That is the whole guarantee. The comments at Payment::MIGRATED_REFERENCE_FLOOR,
 * RecordPayment.php:79 and RecordAccountPayment.php:82 are documentation, not enforcement.
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
        'payer_name' => 'Raw',
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
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(10000), 'Payer', $admin);

        $row = DB::table('finance_payments')->latest('id')->first();
        expect($row->origin)->toBe('portal')
            ->and($row->external_reference)->toBeNull();
    });
});

// ── 3. The seed trap ───────────────────────────────────────────────────────────────────────────────

it('seed trap — a migrated row in the reserved band does NOT drag the live receipt sequence up with it', function () {
    [$school, $admin, $student, $makeInvoice] = provenanceSetup();

    // Plant the import: one migrated payment holding a reference inside the reserved band, with the
    // school's `finance_payment` counter row not yet created — precisely the state a school is in the
    // moment after its opening balances post and before its first portal payment.
    insertPaymentRow($school->id, $student->id, Payment::MIGRATED_REFERENCE_FLOOR + 1, 'migrated', 'migrated');
    expect(DB::table('sequences')->where('scope', 'finance_payment')->where('key', (string) $school->id)->exists())
        ->toBeFalse();

    ActiveSchool::runFor($school->id, function () use ($admin, $makeInvoice) {
        $invoice = $makeInvoice(10000);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(10000), 'Payer', $admin);
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
