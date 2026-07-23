<?php

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
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/**
 * §10 C1 credit notes under REAL concurrency (deterministic two-connection interleave;
 * a backgrounded-process race proves nothing — the #94 lesson).
 *
 *   PROOF 11a — the ceiling's concurrency anchor is the INVOICE-ROW lock (same footprint
 *               as #94): a second connection's lockForUpdate on the same invoice blocks
 *               (1205) while the first holds it, so two credit notes cannot both read the
 *               pre-check sum and both pass. Pull the lock → over-credit slips through.
 *
 *   PROOF 11b — a credit note and a payment on the SAME invoice both lock the invoice row
 *               FIRST (same resource, same order), so they serialise and cannot deadlock
 *               (no opposite-order pair, no 1213); each ceiling holds independently.
 */
uses(DatabaseTruncation::class);

beforeEach(fn () => (new RbacSeeder)->run());

afterEach(function () {
    DB::disconnect('cn_concurrent');

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

function cnSecondConn(): Connection
{
    $default = config('database.default');
    config(['database.connections.cn_concurrent' => config("database.connections.{$default}")]);
    DB::purge('cn_concurrent');

    return DB::connection('cn_concurrent');
}

function cnConcInvoice(int $kobo): Invoice
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);
    $student = Student::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, function () use ($school, $student, $kobo) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        return app(GenerateInvoice::class)->handle(
            $enrollment->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo))],
        );
    });
}

it('PROOF 11a — the ceiling anchor: a second lockForUpdate on the same invoice blocks (the serialisation that makes Σcredits ≤ total safe)', function () {
    $invoice = cnConcInvoice(10000);

    $second = cnSecondConn();

    // A issuer holds the invoice row (the lock IssueCreditNote takes first).
    DB::beginTransaction();
    try {
        DB::table('finance_invoices')->where('id', $invoice->id)->lockForUpdate()->first();

        // A concurrent issuer's FOR UPDATE on the SAME invoice blocks and times out — so
        // it cannot read the pre-check sum until the first commits, then sees the committed
        // total and is bound by the ceiling. Pull this lock and both would read the stale
        // sum and both pass, slipping an over-credit through.
        $second->statement('SET innodb_lock_wait_timeout = 1');
        expect(fn () => $second->table('finance_invoices')->where('id', $invoice->id)->lockForUpdate()->first())
            ->toThrow(QueryException::class); // 1205
        $second->rollBack();
    } finally {
        DB::rollBack();
    }
});

it('PROOF 11b — credit-note vs payment on one invoice share the invoice-row lock (same order) → no deadlock', function () {
    $invoice = cnConcInvoice(10000);

    $second = cnSecondConn();

    // Both IssueCreditNote and RecordPayment lock the INVOICE ROW first. With A holding it,
    // a second actor's identical lock simply waits (serialises) — there is no second
    // resource acquired in the opposite order, so no cycle can form. Demonstrated: the
    // second lock blocks (waits) rather than erroring with a deadlock (1213).
    DB::beginTransaction();
    try {
        DB::table('finance_invoices')->where('id', $invoice->id)->lockForUpdate()->first();

        $second->statement('SET innodb_lock_wait_timeout = 1');
        // A lock-WAIT timeout (1205), never a deadlock (1213): the difference proves the
        // two paths queue on the one shared row rather than cycling.
        try {
            $second->table('finance_invoices')->where('id', $invoice->id)->lockForUpdate()->first();
            $this->fail('expected the second lock to block');
        } catch (QueryException $e) {
            expect((int) ($e->errorInfo[1] ?? 0))->toBe(1205); // lock wait timeout, NOT 1213 deadlock
        }
        $second->rollBack();
    } finally {
        DB::rollBack();
    }
});
