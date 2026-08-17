<?php

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\Invoice;
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
 * SUPPLEMENTARY INVOICES — `finance_invoices.kind`, and the one-per-episode guard re-keyed to
 * constrain only the SCHEDULED invoice. Migration `2026_08_18_100000`.
 *
 * WHAT THIS FILE IS ACTUALLY TESTING, and why each arm reaches past the Action to the database.
 * The invariant here is SET-based and its authority is a unique index over a STORED generated
 * column — not the Action's pre-check, which cannot hold under concurrency and is only there to
 * produce a friendly 422. So an arm that only drives `GenerateInvoice` proves the pre-check and
 * says nothing about the guard. Every refusal arm below therefore ALSO writes raw, and asserts the
 * DRIVER CODE rather than the exception class: `QueryException` is what 1062 (duplicate), 1364
 * (missing NOT NULL column), 1452 (foreign key) and 1644 (a trigger's SIGNAL) all arrive as, so
 * `toThrow(QueryException::class)` on this table is close to `toThrow(Throwable::class)`.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: Student} */
function supSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id, 'first_name' => 'Ada', 'last_name' => 'Obi']);

    return [$school, $admin, $student];
}

function supEnrollment(School $school, Student $student): StudentCurriculum
{
    return StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]);
}

function supRaise(School $school, StudentCurriculum $enrollment, InvoiceKind $kind, int $kobo, string $what = 'Tuition'): Invoice
{
    return ActiveSchool::runFor($school->id, fn () => app(GenerateInvoice::class)->handle(
        $enrollment->uuid,
        [new InvoiceLineSpec($what, Money::fromKobo($kobo))],
        $kind,
    ));
}

/**
 * A raw INSERT into finance_invoices, bypassing GenerateInvoice entirely. `$overrides` is merged
 * last so an arm can omit `kind` (to reach 1364) or plant a value outside the domain.
 */
function supRawInsert(Invoice $like, array $overrides = []): void
{
    DB::table('finance_invoices')->insert(array_merge([
        'uuid' => (string) Str::uuid(),
        'school_id' => $like->school_id,
        'student_id' => $like->student_id,
        'student_curriculum_id' => $like->student_curriculum_id,
        'number' => $like->number + random_int(1000, 9999),
        'status' => 'issued',
        'kind' => 'scheduled',
        'billed_to_name' => 'Ada Obi',
        'academic_context' => $like->academic_context,
        'total_minor' => 5000,
        'total_currency' => 'NGN',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** The driver code of the QueryException a closure raises, or null if it did not raise one. */
function supDriverCode(Closure $write): ?int
{
    try {
        $write();
    } catch (QueryException $e) {
        return (int) $e->errorInfo[1];
    }

    return null;
}

// ─────────────────────────────────────────────────────── the two-scheduled refusal

it('TWO SCHEDULED on one episode are refused BY THE INDEX, not by the Action pre-check', function () {
    [$school, , $student] = supSetup();
    $enrollment = supEnrollment($school, $student);

    $first = supRaise($school, $enrollment, InvoiceKind::Scheduled, 150000);

    // (a) Through the Action: the friendly 422. This is the pre-check, and on its own it is NOT
    //     evidence of the invariant — it is a snapshot read that a concurrent racer defeats.
    expect(fn () => supRaise($school, $enrollment, InvoiceKind::Scheduled, 99000))
        ->toThrow(BusinessRuleException::class);

    // (b) THE ACTUAL GUARD. Raw insert, pre-check never runs, and the refusal must be 1062 naming
    //     the unique index — not some other error that happens to also be a QueryException.
    $code = supDriverCode(fn () => supRawInsert($first));

    expect($code)->toBe(1062);

    try {
        supRawInsert($first);
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('finance_invoices_active_enrollment_unique');
    }

    expect(DB::table('finance_invoices')->count())->toBe(1);
});

// ─────────────────────────────────────────────────────── the whole point of the change

it('a SCHEDULED plus ANY NUMBER of SUPPLEMENTARY invoices coexist on one episode', function () {
    [$school, , $student] = supSetup();
    $enrollment = supEnrollment($school, $student);

    $scheduled = supRaise($school, $enrollment, InvoiceKind::Scheduled, 330239);

    // Four supplementary charges, of the shape the feature exists for. Distinct, non-round
    // amounts so a count×price or max() bug cannot pass as a correct sum.
    $extras = [
        ['Damaged locker door', 12345],
        ['Lagos museum trip', 67891],
        ['Replacement Physics textbook', 8703],
        ['Late payment fee', 2500],
    ];

    $raised = [];
    foreach ($extras as [$what, $kobo]) {
        $raised[] = supRaise($school, $enrollment, InvoiceKind::Supplementary, $kobo, $what);
    }

    expect($raised)->toHaveCount(4);

    // All five are live, and all five belong to the SAME episode — the thing the old guard
    // made impossible.
    $rows = DB::table('finance_invoices')
        ->where('student_curriculum_id', $enrollment->id)
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(5)
        ->and($rows->where('status', 'issued')->count())->toBe(5)
        ->and($rows->where('kind', 'scheduled')->count())->toBe(1)
        ->and($rows->where('kind', 'supplementary')->count())->toBe(4);

    // THE MECHANISM, asserted directly rather than inferred from "it did not blow up": the
    // generated key is the episode id for the scheduled invoice and NULL for every supplementary
    // one, and NULLs do not collide in a MySQL unique index.
    expect($rows->firstWhere('kind', 'scheduled')->active_enrollment_key)->toBe($enrollment->id)
        ->and($rows->where('kind', 'supplementary')->pluck('active_enrollment_key')->all())
        ->toBe([null, null, null, null]);

    // Each supplementary charge posted its own ledger charge — the money reached the subledger,
    // which is the reason the void-and-rebill workaround was unacceptable.
    expect((int) DB::table('finance_ledger_transactions')->where('type', 'charge')->count())->toBe(5);

    // And the scheduled invoice is untouched: its allocations and its total are exactly where
    // they were, which void-and-rebill would have destroyed.
    expect((int) DB::table('finance_invoices')->where('id', $scheduled->id)->value('total_minor'))->toBe(330239)
        ->and(DB::table('finance_invoices')->where('id', $scheduled->id)->value('status'))->toBe('issued');
});

it('a SUPPLEMENTARY invoice raised FIRST does not block the term bill', function () {
    // The mirror of the arm above, and the one the Action's pre-check gets wrong if it forgets
    // the `kind` filter: `excludingVoid()` alone would see the supplementary invoice and refuse
    // the scheduled one with "void it before billing again" — for an invoice that is not the
    // term bill and must not be voided.
    [$school, , $student] = supSetup();
    $enrollment = supEnrollment($school, $student);

    supRaise($school, $enrollment, InvoiceKind::Supplementary, 12345, 'Damaged locker door');

    $scheduled = supRaise($school, $enrollment, InvoiceKind::Scheduled, 330239);

    expect($scheduled->kind)->toBe(InvoiceKind::Scheduled)
        ->and($scheduled->status)->toBe(InvoiceStatus::Issued)
        ->and(DB::table('finance_invoices')->where('student_curriculum_id', $enrollment->id)->count())->toBe(2);
});

// ─────────────────────────────────────────────────────── slice 2's re-bill property, preserved

it('VOIDING the scheduled invoice still frees the slot for a fresh scheduled re-bill', function () {
    [$school, , $student] = supSetup();
    $enrollment = supEnrollment($school, $student);

    $first = supRaise($school, $enrollment, InvoiceKind::Scheduled, 330239);

    // A supplementary charge is standing at the same time, and must not interfere either way.
    supRaise($school, $enrollment, InvoiceKind::Supplementary, 12345, 'Lagos museum trip');

    voidInvoiceViaApproval($school->id, $first->uuid, 'wrong fees');

    // The voided row still exists (append-only). Its generated key recomputed to NULL because
    // the STATUS arm went false — the same mechanism slice 2 recorded, unchanged.
    expect(DB::table('finance_invoices')->where('id', $first->id)->value('active_enrollment_key'))->toBeNull();

    $second = supRaise($school, $enrollment, InvoiceKind::Scheduled, 400000);

    expect(DB::table('finance_invoices')->where('student_curriculum_id', $enrollment->id)->count())->toBe(3)
        ->and((int) DB::table('finance_invoices')->where('id', $second->id)->value('active_enrollment_key'))
        ->toBe($enrollment->id)
        // Exactly ONE row in the episode holds a non-null key: the live term bill.
        ->and(DB::table('finance_invoices')
            ->where('student_curriculum_id', $enrollment->id)
            ->whereNotNull('active_enrollment_key')->count())->toBe(1);
});

// ─────────────────────────────────────────────────────── kind is immutable

it('an UPDATE changing kind is REFUSED by finance_invoices_kind_immutable (1644)', function () {
    [$school, , $student] = supSetup();
    $enrollment = supEnrollment($school, $student);

    $invoice = supRaise($school, $enrollment, InvoiceKind::Scheduled, 150000);

    // THE ATTACK THIS TRIGGER EXISTS TO REFUSE, spelled out: flipping the live scheduled invoice
    // to supplementary recomputes its key to NULL, freeing the episode's slot while it is still
    // issued and still collecting payments — after which a SECOND scheduled invoice inserts
    // cleanly and the episode is double-billed.
    $code = supDriverCode(fn () => DB::table('finance_invoices')
        ->where('id', $invoice->id)
        ->update(['kind' => 'supplementary']));

    expect($code)->toBe(1644);

    try {
        DB::table('finance_invoices')->where('id', $invoice->id)->update(['kind' => 'supplementary']);
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('kind is fixed at creation');
    }

    // Unchanged on disk, and the slot is still held.
    expect(DB::table('finance_invoices')->where('id', $invoice->id)->value('kind'))->toBe('scheduled')
        ->and((int) DB::table('finance_invoices')->where('id', $invoice->id)->value('active_enrollment_key'))
        ->toBe($enrollment->id);

    // And the guard the immutability protects still bites, so the attack is closed end to end.
    expect(supDriverCode(fn () => supRawInsert($invoice)))->toBe(1062);
});

it('the issued -> void status flip still works: immutability constrains kind, not status', function () {
    // A guard that refuses every UPDATE would look identical to a correct one in the arm above
    // while breaking voiding entirely. This is the arm that tells them apart.
    [$school, , $student] = supSetup();
    $enrollment = supEnrollment($school, $student);

    $invoice = supRaise($school, $enrollment, InvoiceKind::Supplementary, 12345, 'Damaged locker door');

    voidInvoiceViaApproval($school->id, $invoice->uuid, 'raised against the wrong student');

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->value('status'))->toBe('void')
        ->and(DB::table('finance_invoices')->where('id', $invoice->id)->value('kind'))->toBe('supplementary');
});

// ─────────────────────────────────────────────────────── the domain of kind

it('an INSERT with a kind outside the domain is REFUSED by the domain trigger (1644)', function () {
    [$school, , $student] = supSetup();
    $enrollment = supEnrollment($school, $student);

    $invoice = supRaise($school, $enrollment, InvoiceKind::Scheduled, 150000);

    // Outright nonsense.
    expect(supDriverCode(fn () => supRawInsert($invoice, ['kind' => 'sundry'])))->toBe(1644);

    // Empty string — what `ADD COLUMN … NOT NULL` with no default would have written into every
    // existing row had this migration not used a transient default.
    expect(supDriverCode(fn () => supRawInsert($invoice, ['kind' => ''])))->toBe(1644);

    // A CASE VARIANT. Under the table's utf8mb4_unicode_ci this compares EQUAL to 'supplementary',
    // so a domain trigger without `COLLATE utf8mb4_bin` accepts it — and then no enum from(),
    // no Eloquent cast and no report filter matches the stored value. This arm is the one that
    // goes red if the COLLATE clause is ever dropped as noise.
    expect(supDriverCode(fn () => supRawInsert($invoice, ['kind' => 'Supplementary'])))->toBe(1644);

    // Both real values are accepted, so the trigger is not simply refusing everything.
    expect(supDriverCode(fn () => supRawInsert($invoice, ['kind' => 'supplementary'])))->toBeNull();

    expect(DB::table('finance_invoices')->where('kind', 'supplementary')->count())->toBe(1);
});

it('kind is NOT NULL with NO DEFAULT: an INSERT omitting it fails loudly with 1364', function () {
    [$school, , $student] = supSetup();
    $enrollment = supEnrollment($school, $student);

    $invoice = supRaise($school, $enrollment, InvoiceKind::Scheduled, 150000);

    $row = DB::selectOne(
        "SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_invoices' AND COLUMN_NAME = 'kind'"
    );

    expect($row->IS_NULLABLE)->toBe('NO')
        ->and($row->COLUMN_DEFAULT)->toBeNull();

    // A writer that forgets kind meets 1364, never a silently-chosen side. This is what makes it
    // safe for the generated key to key on the column at all.
    $write = fn () => DB::table('finance_invoices')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $invoice->school_id,
        'student_id' => $invoice->student_id,
        'student_curriculum_id' => $invoice->student_curriculum_id,
        'number' => $invoice->number + 1,
        'status' => 'void', // void, so this cannot be refused by the duplicate index instead
        'billed_to_name' => 'Ada Obi',
        'academic_context' => $invoice->academic_context,
        'total_minor' => 5000,
        'total_currency' => 'NGN',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(supDriverCode($write))->toBe(1364);
});

// ─────────────────────────────────────────────────────── the shape, read from information_schema

it('SHAPE — the re-keyed guard is present, UNIQUE, over a STORED generated column that names kind', function () {
    // ADR 0052. The migration asserts this at run time; asserting it again here is what catches a
    // LATER migration quietly changing it, which the migration's own read-back cannot see.
    $column = DB::selectOne(
        "SELECT EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_invoices'
            AND COLUMN_NAME = 'active_enrollment_key'"
    );

    expect(strtoupper((string) $column->EXTRA))->toContain('STORED GENERATED')
        ->and((string) $column->GENERATION_EXPRESSION)->toContain('kind')
        ->and((string) $column->GENERATION_EXPRESSION)->toContain('scheduled')
        ->and((string) $column->GENERATION_EXPRESSION)->toContain('issued');

    $index = DB::select(
        "SELECT NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_invoices'
            AND INDEX_NAME = 'finance_invoices_active_enrollment_unique'
          ORDER BY SEQ_IN_INDEX"
    );

    expect($index)->toHaveCount(2)
        ->and((int) $index[0]->NON_UNIQUE)->toBe(0)
        ->and(array_map(fn ($r) => $r->COLUMN_NAME, $index))->toBe(['school_id', 'active_enrollment_key']);

    // The three triggers, by name, timing and event — a name alone does not say what it fires on.
    $triggers = collect(DB::select(
        "SELECT TRIGGER_NAME AS n, ACTION_TIMING AS t, EVENT_MANIPULATION AS e
           FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'finance_invoices'"
    ))->mapWithKeys(fn ($r) => [$r->n => $r->t.' '.$r->e]);

    expect($triggers->get('finance_invoices_kind_domain_bi'))->toBe('BEFORE INSERT')
        ->and($triggers->get('finance_invoices_kind_domain_bu'))->toBe('BEFORE UPDATE')
        ->and($triggers->get('finance_invoices_kind_immutable'))->toBe('BEFORE UPDATE')
        // Slice 2's guards are untouched by the re-key.
        ->and($triggers->get('finance_invoices_total_immutable'))->toBe('BEFORE UPDATE')
        ->and($triggers->get('finance_invoices_no_delete'))->toBe('BEFORE DELETE');
});

it('ISOLATION — the guard is per-School: two Schools each hold their own live scheduled invoice', function () {
    // The index is UNIQUE(school_id, active_enrollment_key), and the episode id is globally unique
    // anyway — so this arm is really checking that the re-key did not drop school_id from the
    // index, which the shape arm asserts and this one exercises.
    [$schoolA, , $studentA] = supSetup();
    [$schoolB, , $studentB] = supSetup();

    $a = supRaise($schoolA, supEnrollment($schoolA, $studentA), InvoiceKind::Scheduled, 150000);
    $b = supRaise($schoolB, supEnrollment($schoolB, $studentB), InvoiceKind::Scheduled, 150000);

    expect($a->school_id)->not->toBe($b->school_id)
        ->and(DB::table('finance_invoices')->whereNotNull('active_enrollment_key')->count())->toBe(2);
});
