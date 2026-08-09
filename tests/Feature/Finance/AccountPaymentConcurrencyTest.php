<?php

use App\Finance\Actions\RecordAccountPayment;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/**
 * The account-scoped payment under REAL concurrency — EXECUTED, not argued (the #94 lesson: a
 * backgrounded-process race proves nothing; a deterministic two-connection interleave does). The
 * real RecordAccountPayment runs on the DEFAULT connection; a SECOND connection holds the contending
 * lock, mirroring WalletConcurrencyTest / WalletW3ConcurrencyTest.
 *
 * PROOF A (§5.5) — two account payments both land, balance is exactly their sum. The path takes NO
 *   lock; the balance is maintained by SubledgerPoster's atomic `balance = balance + delta`, which is
 *   a CURRENT read, so a concurrent balance mutation off a stale snapshot cannot lose an update.
 *
 * PROOF B (§5.6) — the FIRST-EVER-MOVEMENT race, untested until now (every existing wallet proof seeds
 *   an account row first). GenerateInvoice's first statement is StudentAccount::lockForUpdate(); on a
 *   student with NO account row that is a GAP lock on the absent unique (school_id, student_id) row.
 *   A concurrent account payment's INSERT … ON DUPLICATE KEY is an insert-intention into that gap —
 *   the shape insert-intention deadlocks are built from. This measures whether it DEADLOCKS (1213) or
 *   cleanly WAITS (1205): a 1205 proves serialisation (MySQL detects deadlocks immediately and would
 *   return 1213, never wait), and once the gap lock releases the payment commits correctly.
 *
 * DatabaseTruncation, not RefreshDatabase: the latter wraps each test in one never-committed
 * transaction, so a second connection could never see the first's writes.
 */
uses(DatabaseTruncation::class);

beforeEach(fn () => (new RbacSeeder)->run());

afterEach(function () {
    DB::disconnect('account_payment_concurrent');

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

function apcSecondConn(): Connection
{
    $default = config('database.default');
    config(['database.connections.account_payment_concurrent' => config("database.connections.{$default}")]);
    DB::purge('account_payment_concurrent');

    return DB::connection('account_payment_concurrent');
}

/** @return array{0: School, 1: Student, 2: User} */
function apcActors(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id]);

    return [$school, $student, $admin];
}

it('PROOF A (§5.5) — two account payments both land with no lock; balance is exactly their sum (no skew)', function () {
    [$school, $student, $admin] = apcActors();

    // Payment A via the REAL Action (default connection) — creates the account row, balance −300000.
    ActiveSchool::runFor($school->id, fn () => app(RecordAccountPayment::class)
        ->handle($student->id, Money::fromKobo(300000), 'A', $admin, now()->toDateString()));

    $accountId = DB::table('finance_student_accounts')->where('student_id', $student->id)->value('id');
    $second = apcSecondConn();

    // conn2 opens a REPEATABLE READ snapshot (sees −300000). default then commits payment B via the
    // REAL Action (→ −700000). conn2's own increment (a third movement's balance move) is a CURRENT
    // read, so it applies to B's committed value, not conn2's stale snapshot — nothing is lost, and
    // neither payment blocked the other (there is no lock to block on).
    $second->beginTransaction();
    expect((int) $second->selectOne('SELECT balance_minor AS b FROM finance_student_accounts WHERE id = ?', [$accountId])->b)->toBe(-300000);

    ActiveSchool::runFor($school->id, fn () => app(RecordAccountPayment::class)
        ->handle($student->id, Money::fromKobo(400000), 'B', $admin, now()->toDateString())); // default, autocommits → −700000

    $second->update('UPDATE finance_student_accounts SET balance_minor = balance_minor + ? WHERE id = ?', [-100000, $accountId]);
    $second->commit();

    expect((int) DB::table('finance_student_accounts')->where('id', $accountId)->value('balance_minor'))
        ->toBe(-800000); // −300000 −400000 −100000; A's and B's both landed, none lost
    // Both real payments produced a row.
    expect(DB::table('finance_payments')->where('student_id', $student->id)->count())->toBe(2);
});

it('PROOF B (§5.6) — first-ever movement: a concurrent account payment WAITS behind GenerateInvoice’s gap lock (1205), never deadlocks (1213), then commits once released', function () {
    [$school, $student, $admin] = apcActors();

    // No finance_student_accounts row exists for this student yet.
    expect(DB::table('finance_student_accounts')->where('student_id', $student->id)->exists())->toBeFalse();

    $second = apcSecondConn();

    // conn2 = GenerateInvoice's FIRST statement on a student with no account row: a locking read on
    // the absent unique (school_id, student_id) row → a GAP lock.
    $second->beginTransaction();
    $second->select(
        'SELECT id FROM finance_student_accounts WHERE school_id = ? AND student_id = ? FOR UPDATE',
        [$school->id, $student->id],
    );

    // default = the REAL account payment. Its SubledgerPoster INSERT … ON DUPLICATE KEY is an
    // insert-intention into conn2's gap → it must WAIT. A short timeout turns the wait into a 1205
    // we can observe; a DEADLOCK would surface as 1213 immediately instead.
    DB::statement('SET innodb_lock_wait_timeout = 2');
    $code = null;
    try {
        ActiveSchool::runFor($school->id, fn () => app(RecordAccountPayment::class)
            ->handle($student->id, Money::fromKobo(100000), 'X', $admin, now()->toDateString()));
    } catch (QueryException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
    }

    // The load-bearing assertion: it WAITED (1205), it did not DEADLOCK (1213). If this ever reads
    // 1213, stop — the first-ever-movement race deadlocks and needs a real fix, not a workaround.
    expect($code)->toBe(1205);

    // Release the gap lock; the payment now commits cleanly and creates the account row.
    $second->rollBack();
    ActiveSchool::runFor($school->id, fn () => app(RecordAccountPayment::class)
        ->handle($student->id, Money::fromKobo(100000), 'X', $admin, now()->toDateString()));

    expect((int) DB::table('finance_student_accounts')->where('student_id', $student->id)->value('balance_minor'))->toBe(-100000)
        ->and(DB::table('finance_payments')->where('student_id', $student->id)->count())->toBe(1); // only the committed one
});
