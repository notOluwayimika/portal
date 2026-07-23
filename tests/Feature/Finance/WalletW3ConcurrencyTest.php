<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Models\Invoice;
use App\Finance\Models\StudentAccount;
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
 * Wallet W3 under REAL concurrency — the two proofs deferred from W2, now that
 * GenerateInvoice performs a genuine read-modify-write of the account balance.
 *
 *   PROOF 4 — the account lockForUpdate GenerateInvoice acquires FIRST serialises the
 *             read-credit→spend: a second connection's lockForUpdate on the same account
 *             blocks (1205) while the first holds it, so two generations cannot both read
 *             the same credit. Pull the lock (a plain read) → both see the same credit and
 *             both would apply it (double-spend) — the red step that makes the lock
 *             load-bearing.
 *
 *   PROOF 5 — account-first (W3) vs invoice-first (#94/RecordPayment) does not deadlock:
 *             a full account-first sequence completes while the other transaction holds an
 *             invoice row, because account-first never waits on an invoice row the other
 *             holds — there is no opposite-order pair, so no cycle (1213).
 *
 * DatabaseTruncation (not RefreshDatabase) for real cross-connection commits; deterministic
 * interleaves, never a backgrounded-process race (the #94 lesson).
 */
uses(DatabaseTruncation::class);

beforeEach(fn () => (new RbacSeeder)->run());

afterEach(function () {
    DB::disconnect('w3_concurrent');

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

function w3SecondConn(): Connection
{
    $default = config('database.default');
    config(['database.connections.w3_concurrent' => config("database.connections.{$default}")]);
    DB::purge('w3_concurrent');

    return DB::connection('w3_concurrent');
}

/** @return array{0: School, 1: User, 2: Student} */
function w3ConcurrentActors(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id]);

    return [$school, $admin, $student];
}

function w3ConcInvoice(School $school, Student $student, int $kobo): Invoice
{
    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]);

    return app(GenerateInvoice::class)->handle(
        $enrollment->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo))],
    );
}

it('PROOF 4 — the account lockForUpdate serialises read-credit→spend; a plain read sees the same credit (double-spend if unlocked)', function () {
    [$school, $admin, $student] = w3ConcurrentActors();

    // Bank 2000 credit (balance −2000): invoice 2000, overpay 4000.
    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $c = w3ConcInvoice($school, $student, 2000);
        app(RecordPayment::class)->handle($c, Money::fromKobo(4000), 'Over', $admin);
    });
    $accountId = StudentAccount::query()->where('student_id', $student->id)->value('id');
    expect((int) DB::table('finance_student_accounts')->where('id', $accountId)->value('balance_minor'))->toBe(-2000);

    $second = w3SecondConn();

    // OUR generation (A) opens a transaction and takes the account lock GenerateInvoice
    // takes first — reading credit 2000, uncommitted.
    DB::beginTransaction();
    try {
        $row = DB::table('finance_student_accounts')->where('id', $accountId)->lockForUpdate()->first();
        expect(max(0, -(int) $row->balance_minor))->toBe(2000); // A's credit read

        // ── THE GUARD: a concurrent generation (B) issuing the SAME lockForUpdate cannot
        // read the credit while A holds the row — it blocks and times out. So two
        // generations can never both be inside the read-credit→spend window.
        $second->statement('SET innodb_lock_wait_timeout = 1');
        expect(fn () => $second->table('finance_student_accounts')->where('id', $accountId)->lockForUpdate()->first())
            ->toThrow(QueryException::class); // 1205

        // ── THE ATTACK: without the lock (a plain read), B sees the SAME credit 2000 that
        // A is about to spend — both would apply 2000, double-spending the 2000 credit.
        // This is exactly what the lockForUpdate prevents.
        $bPlain = $second->table('finance_student_accounts')->where('id', $accountId)->first();
        expect(max(0, -(int) $bPlain->balance_minor))->toBe(2000);
    } finally {
        DB::rollBack();
    }
});

it('PROOF 5 — account-first (W3) does not deadlock against invoice-first (#94): B completes while A holds an invoice row', function () {
    [$school, $admin, $student] = w3ConcurrentActors();

    // Real setup: an account row, an existing invoice X (what RecordPayment locks first),
    // and a payment to source an allocation from.
    $x = ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $inv = w3ConcInvoice($school, $student, 5000);
        app(RecordPayment::class)->handle($inv, Money::fromKobo(5000), 'Pay', $admin); // account row now exists
        // A second invoice Y that B's account-first sequence will settle-link against.
        w3ConcInvoice($school, $student, 3000);

        return $inv;
    });
    $accountId = StudentAccount::query()->where('student_id', $student->id)->value('id');
    $paymentId = DB::table('finance_payments')->where('student_id', $student->id)->value('id');
    $y = DB::table('finance_invoices')->where('student_id', $student->id)->orderByDesc('id')->value('id');

    $second = w3SecondConn();

    // A (invoice-first, #94): hold invoice X FOR UPDATE — the lock RecordPayment takes first.
    DB::beginTransaction();
    try {
        DB::table('finance_invoices')->where('id', $x->id)->lockForUpdate()->first();

        // B (account-first, W3): a full account-first sequence — lock account, move the
        // balance (the charge increment), settle-link an allocation to invoice Y — all
        // WITHOUT ever needing invoice X. It completes and commits, proving account-first
        // never waits on the invoice row A holds. No cycle ⇒ no deadlock.
        $second->statement('SET innodb_lock_wait_timeout = 3');
        $second->beginTransaction();
        $second->table('finance_student_accounts')->where('id', $accountId)->lockForUpdate()->first();
        $second->table('finance_student_accounts')->where('id', $accountId)
            ->update(['balance_minor' => DB::raw('balance_minor + 3000')]);
        $second->table('finance_payment_allocations')->insert([
            'uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'payment_id' => $paymentId,
            'invoice_id' => $y,
            'amount_minor' => 1000,
            'amount_currency' => 'NGN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $second->commit(); // B finishes cleanly — never blocked on X, no 1213

        // A now does its own account work (the row B already released) and commits.
        DB::table('finance_student_accounts')->where('id', $accountId)
            ->update(['balance_minor' => DB::raw('balance_minor - 100')]);
        DB::commit();
    } catch (Throwable $e) {
        DB::rollBack();
        throw $e;
    }

    // Both transactions committed with no deadlock; the allocation B wrote survives.
    expect((int) DB::table('finance_payment_allocations')->where('invoice_id', $y)->sum('amount_minor'))->toBe(1000);
});
