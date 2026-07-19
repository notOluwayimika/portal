<?php

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\CancelInvoice;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Models\Invoice;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slice 2 — the two guards proven under REAL concurrency, EXECUTED not argued.
 * "Local validates the query; production produces the number": a read-then-write
 * guard is only as good as its proof under MySQL's actual isolation level.
 *
 * WHY NOT RefreshDatabase: it wraps each test in a transaction that never commits,
 * so a second connection could never see the first's writes — the very thing under
 * test. DatabaseTruncation gives real commits across connections. (TRUNCATE does
 * not fire triggers, so the append-only DELETE triggers do not block cleanup.)
 *
 * THE SHAPE OF EACH PROOF. The dangerous case is not "B starts after A commits" —
 * that is trivially safe. It is "B already holds a REPEATABLE READ snapshot taken
 * BEFORE A committed", because B's plain reads then still show the pre-A world and
 * any application-level pre-check inside B will happily pass. Each test below
 * demonstrates exactly that stale snapshot, then shows the guard holding anyway —
 * which is only possible because the guard is a CURRENT read (a unique index check,
 * or SELECT … FOR UPDATE) rather than a snapshot read.
 */
uses(DatabaseTruncation::class);

/**
 * Leave the database EMPTY for whatever runs next.
 *
 * DatabaseTruncation cleans up *before* each of its own tests, so after this
 * file's last test its committed rows survive — and the RefreshDatabase files
 * (which only roll back their own transaction, on a database they assume is
 * clean) then start dirty and fail with baffling off-by-N counts. Found exactly
 * that way: this file sorts before the others, so it silently poisoned them.
 */
afterEach(function () {
    DB::disconnect('concurrent');

    $tables = collect(DB::select('SHOW TABLES'))
        ->map(fn ($row) => array_values((array) $row)[0])
        ->reject(fn ($table) => $table === 'migrations')
        ->all();

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        DB::table($table)->truncate();
    }
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
});

/** A second, independent connection to the same database. */
function secondConnection(): Connection
{
    $default = config('database.default');
    config(['database.connections.concurrent' => config("database.connections.{$default}")]);
    DB::purge('concurrent');

    return DB::connection('concurrent');
}

/**
 * Run $cb inside a transaction on a second connection, ALWAYS rolling back.
 *
 * The finally is load-bearing, not hygiene: if an expectation inside $cb fails,
 * the thrown assertion would otherwise skip the rollback, leaving that connection
 * holding row locks — and DatabaseTruncation's cleanup then blocks forever, so a
 * failing test hangs the suite instead of reporting. (Found the hard way while
 * bite-proving this very file: with the unique index removed the insert succeeded,
 * the expectation failed, and the run hung.)
 */
function withSecondTransaction(callable $cb): void
{
    $second = secondConnection();
    $second->beginTransaction();

    try {
        $cb($second);
    } finally {
        try {
            $second->rollBack();
        } catch (Throwable) {
            // The transaction may already be dead; cleanup must not mask the failure.
        }
    }
}

/** @return array{0: School, 1: User, 2: Student} */
function concurrentSetup(): array
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

function concurrentEnrollment(School $school, Student $student): StudentCurriculum
{
    return StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]);
}

it('CONCURRENCY — two racing GenerateInvoice for one enrollment yield exactly ONE invoice', function () {
    [$school, $admin, $student] = concurrentSetup();
    $enrollment = concurrentEnrollment($school, $student);

    withSecondTransaction(function ($second) use ($school, $enrollment) {
        // ── B takes its snapshot BEFORE A writes.
        expect((int) $second->table('finance_invoices')->count())->toBe(0);

        // ── A creates the invoice and COMMITS.
        ActiveSchool::runFor($school->id, fn () => app(GenerateInvoice::class)->handle(
            $enrollment->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo(150000))],
        ));
        expect((int) DB::table('finance_invoices')->count())->toBe(1);

        // ── THE DANGER, demonstrated: B's snapshot is stale. It still sees zero
        //    invoices, so an application-level "does an active invoice already
        //    exist?" pre-check running inside B would PASS and permit a duplicate.
        expect((int) $second->table('finance_invoices')->count())->toBe(0);

        // ── THE GUARD, holding: the UNIQUE(school_id, active_enrollment_key)
        //    check is a CURRENT read, not a snapshot read, so B's insert is
        //    rejected outright.
        $row = DB::table('finance_invoices')->first();
        expect(fn () => $second->table('finance_invoices')->insert([
            'uuid' => (string) Str::uuid(),
            'school_id' => $row->school_id,
            'student_id' => $row->student_id,
            'student_curriculum_id' => $row->student_curriculum_id,
            'number' => $row->number + 1,
            'status' => 'issued',
            'billed_to_name' => 'Ada Obi',
            'academic_context' => $row->academic_context,
            'total_minor' => 150000,
            'total_currency' => 'NGN',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    // Exactly one invoice, and exactly one charge on the ledger.
    expect((int) DB::table('finance_invoices')->count())->toBe(1)
        ->and((int) DB::table('finance_ledger_transactions')->where('type', 'charge')->count())->toBe(1);
});

it('CONCURRENCY — a void racing another void is REFUSED, so no second reversal is posted', function () {
    [$school, $admin, $student] = concurrentSetup();
    $enrollment = concurrentEnrollment($school, $student);

    $invoice = ActiveSchool::runFor($school->id, fn () => app(GenerateInvoice::class)->handle(
        $enrollment->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo(150000))],
    ));

    $second = secondConnection();

    // OUR transaction opens and takes its snapshot, showing the invoice as ISSUED.
    // Running the Action inside this transaction is what makes this a real
    // bite-proof of CancelInvoice: its own DB::transaction nests as a savepoint,
    // so it inherits THIS stale snapshot — exactly the position the losing racer
    // is in.
    DB::beginTransaction();

    try {
        expect(DB::table('finance_invoices')->where('id', $invoice->id)->value('status'))->toBe('issued');

        // ── The OTHER racer voids the invoice and commits, on its own connection.
        $second->table('finance_invoices')->where('id', $invoice->id)->update([
            'status' => 'void',
            'cancelled_at' => now(),
            'cancel_reason' => 'the other racer',
        ]);

        // ── THE DANGER, demonstrated: our snapshot is now stale — a plain read
        //    inside our transaction STILL reports 'issued'. A guard written as a
        //    plain read-then-write passes here and posts a SECOND reversing entry,
        //    double-crediting the student.
        expect(DB::table('finance_invoices')->where('id', $invoice->id)->value('status'))->toBe('issued');

        // ── THE GUARD, holding: CancelInvoice re-reads with lockForUpdate(), a
        //    CURRENT read that bypasses the snapshot, sees the committed VOID and
        //    refuses. Swap that lockForUpdate() for a plain read and this fails.
        expect(fn () => ActiveSchool::runFor($school->id, fn () => app(CancelInvoice::class)->handle(
            Invoice::withoutGlobalScopes()->find($invoice->id), 'ours', $admin,
        )))->toThrow(BusinessRuleException::class);
    } finally {
        DB::rollBack();
    }

    // The losing racer posted NOTHING: no second reversing entry exists, so the
    // student was not double-credited. (The winning racer here is simulated with a
    // raw status flip, so it posts no ledger row of its own — which is precisely
    // why a reversal count of 0 proves OUR attempt was refused rather than merely
    // deduplicated.) The sequential double-void path is covered in
    // WalkingSkeletonTest and the void gate in MultiLineInvoiceTest.
    expect((int) DB::table('finance_ledger_transactions')->where('type', 'reversal')->count())->toBe(0)
        ->and(DB::table('finance_invoices')->where('id', $invoice->id)->value('status'))->toBe('void');
});
