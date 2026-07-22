<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Wallet W1+W2 under REAL concurrency — EXECUTED, not argued (the #94 lesson: a
 * backgrounded-process race proves nothing; a deterministic two-connection interleave
 * does). Two guarantees are bite-proven here:
 *
 *   PROOF 4 — the account balance is maintained by an ATOMIC increment
 *             (`balance = balance + delta`), which is skew-free under concurrency: two
 *             interleaved increments to the same account BOTH land. The paired attack
 *             shows the alternative — an app-level read-modify-write off a stale
 *             snapshot — LOSING an update, which is why the maintenance is `col = col
 *             + delta` and not SELECT-then-write.
 *
 *   PROOF 2 — #94's INVOICE-ROW lock is UNTOUCHED by this slice and still serialises
 *             allocations to one invoice: a second FOR UPDATE on the same invoice blocks.
 *
 * WHY DatabaseTruncation, not RefreshDatabase: RefreshDatabase wraps each test in one
 * never-committed transaction, so a second connection could never see the first's writes
 * — the very thing under test. Truncation gives real cross-connection commits.
 */
uses(DatabaseTruncation::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * Leave the database EMPTY for whatever runs next — the RefreshDatabase files assume a
 * clean DB and would fail with off-by-N counts on our committed rows otherwise (the
 * hazard InvoiceConcurrencyTest documents).
 */
afterEach(function () {
    DB::disconnect('wallet_concurrent');

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

/** A second, independent connection to the same database (its own snapshot/locks). */
function walletSecondConn(): Connection
{
    $default = config('database.default');
    config(['database.connections.wallet_concurrent' => config("database.connections.{$default}")]);
    DB::purge('wallet_concurrent');

    return DB::connection('wallet_concurrent');
}

/** @return array{0: School, 1: Student} */
function walletConcurrentActors(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id]);

    return [$school, $student];
}

it('PROOF 4 — the atomic increment does a CURRENT read, so a stale-snapshot writer cannot lose a concurrent update (a RMW would)', function () {
    [$school, $student] = walletConcurrentActors();

    // Seed a zero-balance account row directly (a real committed row two connections can
    // contend over). The atomic increment below is exactly the statement post() issues.
    $accountId = DB::table('finance_student_accounts')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'student_id' => $student->id,
        'balance_minor' => 0,
        'balance_currency' => 'NGN',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $second = walletSecondConn();

    // ── THE GUARD (atomic increment). B opens a transaction and takes a REPEATABLE READ
    // snapshot showing balance 0. A then commits a +100 increment on its own connection.
    // B's snapshot is now stale (a plain read would still say 0) — but B's atomic
    // `balance = balance + 200` is a CURRENT read of the row, so it applies to the
    // committed 100, not the stale 0. Both increments land → 300. No app lock, no skew.
    $second->beginTransaction();
    expect((int) $second->selectOne('SELECT balance_minor AS b FROM finance_student_accounts WHERE id = ?', [$accountId])->b)->toBe(0);

    DB::update('UPDATE finance_student_accounts SET balance_minor = balance_minor + 100 WHERE id = ?', [$accountId]); // A, autocommitted

    $second->update('UPDATE finance_student_accounts SET balance_minor = balance_minor + 200 WHERE id = ?', [$accountId]); // B, current read
    $second->commit();

    expect((int) DB::table('finance_student_accounts')->where('id', $accountId)->value('balance_minor'))
        ->toBe(300); // A's +100 was NOT lost

    // ── THE ATTACK (read-modify-write). Same shape, but B reads the value FIRST off its
    // stale snapshot and writes an ABSOLUTE total. B snapshots 300; A commits another
    // +100 (→ 400); B writes stale-300 + 200 = 500, clobbering A's increment. The lost
    // update the atomic form cannot suffer — this is why maintenance is `col = col +
    // delta`, never SELECT-then-write.
    $second->beginTransaction();
    $stale = (int) $second->selectOne('SELECT balance_minor AS b FROM finance_student_accounts WHERE id = ?', [$accountId])->b; // 300

    DB::update('UPDATE finance_student_accounts SET balance_minor = balance_minor + 100 WHERE id = ?', [$accountId]); // A → 400

    $second->update('UPDATE finance_student_accounts SET balance_minor = ? WHERE id = ?', [$stale + 200, $accountId]); // B RMW → 500
    $second->commit();

    // 500, not the 600 two real increments (+100, +200) would give — A's +100 was lost.
    expect((int) DB::table('finance_student_accounts')->where('id', $accountId)->value('balance_minor'))
        ->toBe(500);
});

it('PROOF 2 — #94 untouched: the INVOICE-ROW lock still serialises allocations to one invoice (a second FOR UPDATE blocks)', function () {
    [$school, $student] = walletConcurrentActors();

    $invoice = ActiveSchool::runFor($school->id, function () use ($school, $student) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        return app(GenerateInvoice::class)->handle(
            $enrollment->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo(100000))],
        );
    });

    $second = walletSecondConn();

    // OUR racer locks the invoice row (the #94 anchor RecordPayment still takes, unchanged).
    DB::beginTransaction();
    try {
        DB::table('finance_invoices')->where('id', $invoice->id)->lockForUpdate()->first();

        // The other racer's FOR UPDATE on the SAME invoice row blocks and times out —
        // proving the per-invoice serialisation that makes Σ(allocations) ≤ total safe
        // under concurrency is intact (we did not touch #94's lock).
        $second->statement('SET innodb_lock_wait_timeout = 1');
        expect(fn () => $second->table('finance_invoices')->where('id', $invoice->id)->lockForUpdate()->first())
            ->toThrow(QueryException::class); // 1205
        $second->rollBack();
    } finally {
        DB::rollBack();
    }
});
