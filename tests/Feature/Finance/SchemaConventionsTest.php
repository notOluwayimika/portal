<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces the frozen Finance schema conventions at the DB level, so a future
 * aggregate that forgets one fails CI rather than silently drifting:
 *  - every finance_* table carries school_id (uniform tenanting — the arch rule
 *    only asserts the MODEL uses BelongsToSchool; this asserts the COLUMN exists);
 *  - the 1.4c immutability triggers exist by name on the append-only tables;
 *  - the composite (child_fk, school_id) → parent(id, school_id) FKs exist, so a
 *    child's school_id cannot diverge from its parent's.
 */
uses(RefreshDatabase::class);

function financeTables(): array
{
    return collect(DB::select("SHOW TABLES LIKE 'finance_%'"))
        ->map(fn ($row) => array_values((array) $row)[0])
        ->all();
}

it('every finance_ table carries a school_id column (uniform tenanting)', function () {
    $tables = financeTables();
    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        expect(Schema::hasColumn($table, 'school_id'))
            ->toBeTrue("finance table [{$table}] must carry school_id");
    }
});

it('no stray fee_ Finance table remains after the rename', function () {
    expect(collect(DB::select("SHOW TABLES LIKE 'fee_%'"))->count())->toBe(0);
});

/**
 * Real money rows to bite-test against — a FOR EACH ROW trigger never fires when no row
 * matches (the F4 comment below), so exist-by-name proofs need an actual row to bite.
 * Generates an invoice (invoice + line + ledger charge) and a partial payment (payment +
 * allocation + ledger payment) through the domain actions.
 *
 * @return array{invoice:int, line:int, payment:int, allocation:int}
 */
function schemaMoneyRows(): array
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, function () use ($school, $student) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);
        $invoice = app(GenerateInvoice::class)->handle(
            $enrollment->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo(50000))],
        );
        app(RecordPayment::class)->handle(
            $invoice, Money::fromKobo(20000), 'Payer', User::factory()->create(['school_id' => $school->id]),
            now()->toDateString(), );

        return [
            'invoice' => (int) $invoice->id,
            'line' => (int) DB::table('finance_invoice_lines')->where('invoice_id', $invoice->id)->value('id'),
            'payment' => (int) DB::table('finance_payments')->where('student_id', $student->id)->value('id'),
            'allocation' => (int) DB::table('finance_payment_allocations')->where('invoice_id', $invoice->id)->value('id'),
        ];
    });
}

it('BITE-PROOF — the append-only triggers on the money tables actually BITE (raw UPDATE/DELETE denied)', function () {
    // The tests below assert these triggers EXIST by name; this proves they BITE. Before this,
    // the append-only guards on finance_invoices (DELETE), finance_invoice_lines,
    // finance_payments and finance_payment_allocations were exist-only — a dropped trigger
    // would have left the name-check red only if someone remembered to also assert behaviour.
    // (finance_ledger_transactions is already bite-proven in WalkingSkeletonTest; credit
    // notes / void requests in CreditNoteTest / the acceptance harness.)
    $ids = schemaMoneyRows();

    // finance_invoices — DELETE denied. (status is intentionally MUTABLE, so only DELETE is
    // guarded here; total-immutability on UPDATE is bite-proven in MultiLineInvoiceTest.)
    expect(fn () => DB::table('finance_invoices')->where('id', $ids['invoice'])->delete())
        ->toThrow(QueryException::class);

    // finance_invoice_lines — fully append-only: UPDATE and DELETE both denied.
    expect(fn () => DB::table('finance_invoice_lines')->where('id', $ids['line'])->update(['amount_minor' => 1]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_invoice_lines')->where('id', $ids['line'])->delete())
        ->toThrow(QueryException::class);

    // finance_payments — append-only.
    expect(fn () => DB::table('finance_payments')->where('id', $ids['payment'])->update(['amount_minor' => 1]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_payments')->where('id', $ids['payment'])->delete())
        ->toThrow(QueryException::class);

    // finance_payment_allocations — append-only.
    expect(fn () => DB::table('finance_payment_allocations')->where('id', $ids['allocation'])->update(['amount_minor' => 1]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_payment_allocations')->where('id', $ids['allocation'])->delete())
        ->toThrow(QueryException::class);
});

it('the 1.4c immutability triggers exist by name on the finance_ append-only tables', function () {
    $names = collect(DB::select(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE LIKE ?', ['finance_%']
    ))->pluck('TRIGGER_NAME')->all();

    expect($names)->toContain(
        'finance_invoices_no_delete',
        'finance_invoice_lines_no_update',
        'finance_invoice_lines_no_delete',
        'finance_ledger_transactions_no_update',
        'finance_ledger_transactions_no_delete',
        'finance_payments_no_update',
        'finance_payments_no_delete',
        'finance_payment_allocations_no_update',
        'finance_payment_allocations_no_delete',
        // Ph3 — a credit note is a money DOCUMENT, so (like finance_invoices) it is
        // money-immutable but STATUS-mutable, not fully append-only. DELETE stays denied;
        // UPDATE is guarded to permit ONLY the decision columns (see the update_guard test).
        'finance_credit_notes_no_delete',
        // Ph3b — a void request is a lifecycle DOCUMENT, same shape: DELETE denied, UPDATE
        // guarded to permit only the decision columns (see the void update_guard test).
        'finance_void_requests_no_delete',
    );
});

it('the void-request guards enforce request-immutability + decision-mutability by NAME (Ph3b)', function () {
    // Ph3b mirrors the credit-note lifecycle: an UPDATE guard (invoice/reason/maker/identity
    // immutable, only the decision columns move) plus a DELETE deny. There is deliberately NO
    // insert guard — a void request carries no money of its own (only ApproveVoidRequest posts
    // the reversal), so a raw insert forges an audit row but moves nothing.
    $triggers = collect(DB::select(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', ['finance_void_requests']
    ))->pluck('TRIGGER_NAME')->all();

    expect($triggers)->toContain('finance_void_requests_update_guard')
        ->and($triggers)->toContain('finance_void_requests_no_delete')
        // No insert guard by design, and no bare no_update (relocated into the update guard).
        ->and($triggers)->not->toContain('finance_void_requests_insert_guard')
        ->and($triggers)->not->toContain('finance_void_requests_no_update');
});

it('the credit-note guards enforce money-immutability + status-mutability + the ceiling by NAME (Ph3)', function () {
    // Asserted by name — a migration that silently drops one fails here. Ph3 relocated the
    // C1 append-only no_update / BEFORE-INSERT ceiling to two guards: an UPDATE guard
    // (money/identity immutable, only decision columns move, ceiling at the approval
    // transition) and an INSERT guard (currency integrity + ceiling for a raw approved insert).
    $triggers = collect(DB::select(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', ['finance_credit_notes']
    ))->pluck('TRIGGER_NAME')->all();

    expect($triggers)->toContain('finance_credit_notes_update_guard')
        ->and($triggers)->toContain('finance_credit_notes_insert_guard')
        ->and($triggers)->toContain('finance_credit_notes_no_delete')
        // The C1 one-step guards are GONE (relocated), not lingering.
        ->and($triggers)->not->toContain('finance_credit_notes_no_update')
        ->and($triggers)->not->toContain('finance_credit_note_not_over_invoice_total');
});

it('finance_student_accounts is the ONE intentionally-mutable finance table (append-only exemption, pinned)', function () {
    // HOW #95 asserts append-only (checked against HEAD): the immutability test above
    // keys on a HARDCODED toContain(...) list of trigger NAMES on specific tables — it
    // does NOT loop financeTables() demanding a no_update/no_delete trigger per table.
    // So the account table lacking those triggers does NOT fail that assertion today;
    // this test is the POSITIVE pin that records the exemption deliberately, so a future
    // tightening to "every finance table must be append-only" is forced to confront this
    // row rather than a green suite hiding that the account was never made immutable.
    expect(financeTables())->toContain('finance_student_accounts');

    $accountTriggers = collect(DB::select(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', ['finance_student_accounts']
    ))->pluck('TRIGGER_NAME')->all();

    // No immutability trigger — mutation (the atomic balance increment) is the point.
    expect($accountTriggers)->not->toContain(
        'finance_student_accounts_no_update',
        'finance_student_accounts_no_delete',
    );

    // It still obeys the conventions that DO apply: school_id (uniform tenanting), the
    // signed balance column, and the grain UNIQUE(school_id, student_id). There is no
    // `version` column — W2 has no optimistic read-modify-write to guard.
    expect(Schema::hasColumn('finance_student_accounts', 'school_id'))->toBeTrue()
        ->and(Schema::hasColumn('finance_student_accounts', 'balance_minor'))->toBeTrue()
        ->and(Schema::hasColumn('finance_student_accounts', 'version'))->toBeFalse();

    $indexes = collect(DB::select('SHOW INDEX FROM finance_student_accounts'))
        ->pluck('Key_name')->unique()->all();
    expect($indexes)->toContain('finance_student_accounts_school_id_student_id_unique');
});

it('slice-2 guards exist by NAME (F6 total immutability + the active-enrollment uniqueness)', function () {
    // Confirmed by name rather than by behaviour, so a migration that silently
    // drops one fails here even if some other mechanism happens to mask it.
    $triggers = collect(DB::select(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', ['finance_invoices']
    ))->pluck('TRIGGER_NAME')->all();

    expect($triggers)->toContain('finance_invoices_total_immutable')
        ->and($triggers)->toContain('finance_invoices_no_delete'); // F4 survived slice 2

    $indexes = collect(DB::select('SHOW INDEX FROM finance_invoices'))->pluck('Key_name')->unique()->all();
    expect($indexes)->toContain('finance_invoices_active_enrollment_unique');

    // The uniqueness must be backed by the GENERATED column — an app-maintained
    // column would reintroduce the "someone forgets to clear it" failure mode.
    $column = collect(DB::select(
        "SELECT EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_invoices'
           AND COLUMN_NAME = 'active_enrollment_key'"
    ))->first();

    expect($column)->not->toBeNull()
        ->and(strtoupper((string) $column->EXTRA))->toContain('STORED GENERATED');
});

it('F1–F4 survive slice 2: prefix, school_id, composite FKs and append-only are all intact', function () {
    // F1 — every Finance table still carries the finance_ prefix (and no fee_ ones).
    expect(financeTables())->not->toBeEmpty()
        ->and(collect(DB::select("SHOW TABLES LIKE 'fee_%'"))->count())->toBe(0);

    // F2 — school_id on every finance_ table, including the ones slice 2 touched.
    foreach (financeTables() as $table) {
        expect(Schema::hasColumn($table, 'school_id'))->toBeTrue("[{$table}] lost school_id");
    }

    // F3 — the composite (child_fk, school_id) FKs still exist after slice 2
    // altered finance_invoices (adding a column rebuilds the table in MySQL).
    $composite = collect(DB::select(
        "SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'
           AND CONSTRAINT_NAME LIKE '%_school_foreign'"
    ))->pluck('CONSTRAINT_NAME')->all();
    expect($composite)->toContain('finance_invoice_lines_invoice_school_foreign');

    // The parent unique key those composite FKs reference must also survive.
    expect(collect(DB::select('SHOW INDEX FROM finance_invoices'))->pluck('Key_name')->unique()->all())
        ->toContain('finance_invoices_id_school_unique');

    // F4 — invoice DELETE-deny still present alongside slice 2's new UPDATE
    // trigger (multiple triggers coexist on one table). Asserted by NAME: a
    // behavioural DELETE on a non-existent id proves nothing, because a
    // FOR EACH ROW trigger never fires when no row matches.
    expect(collect(DB::select(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', ['finance_invoices']
    ))->pluck('TRIGGER_NAME')->all())->toContain('finance_invoices_no_delete');
});

it('composite child.school_id = parent.school_id FKs are present', function () {
    $composite = collect(DB::select(
        "SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'
           AND CONSTRAINT_NAME LIKE '%_school_foreign'"
    ))->pluck('CONSTRAINT_NAME')->all();

    expect($composite)->toContain(
        'finance_invoice_lines_invoice_school_foreign',
        'finance_payment_allocations_invoice_school_foreign',
        'finance_payment_allocations_payment_school_foreign',
    );
});

// ── Collation uniformity (the trigger-floor hardening, 2026-07) ──────────────
//
// A trigger's DECLARE variable inherits the DATABASE default collation, while a
// Laravel-created column is always utf8mb4_unicode_ci (the connection collation). When
// the two differ — a database created with the MySQL-8 server default
// (utf8mb4_0900_ai_ci) rather than the app's — any trigger comparing a variable to a
// string column raises 1267 "Illegal mix of collations" on EVERY write, a total outage
// of the guard. The over-allocation guard was green on the dev DB and dead on a fresh
// one for exactly this reason. These two assertions make that unrepresentable: a
// mis-created DB or a divergent column becomes a red test, not a prod incident.
//
// Verified empirically that the variable collation is frozen at trigger CREATION, so the
// database must carry the canonical default BEFORE migrations run — which is why the fix
// lives in how databases are created (bin/quality-clean-db, docs/testing.md), not in a
// migration.

const CANONICAL_COLLATION = 'utf8mb4_unicode_ci';

it('every finance_ string column uses the canonical collation (no divergence)', function () {
    $offenders = collect(DB::select(
        'SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME LIKE ?
            AND COLLATION_NAME IS NOT NULL
            AND COLLATION_NAME <> ?',
        ['finance_%', CANONICAL_COLLATION]
    ))->map(fn ($r) => "{$r->TABLE_NAME}.{$r->COLUMN_NAME}={$r->COLLATION_NAME}")->all();

    // Reads information_schema (real column state), not the migration source (intent).
    expect($offenders)->toBe([], 'finance_* string columns must all be '.CANONICAL_COLLATION);
});

it('the test database default collation matches the canonical one (the trigger trap guard)', function () {
    // THIS is the assertion that maps to the bug: if your DB default is not canonical,
    // every trigger DECLARE variable is off-collation and the guards are silently dead.
    // A green suite on a mis-collated DB proves nothing — so make the DB itself the
    // thing under test.
    $default = DB::selectOne('SELECT @@collation_database AS c')->c;

    expect($default)->toBe(
        CANONICAL_COLLATION,
        "Database default collation is {$default}; triggers' DECLARE variables inherit it and will "
        .'1267 against '.CANONICAL_COLLATION.' columns. Recreate the DB with '
        .'CHARACTER SET utf8mb4 COLLATE '.CANONICAL_COLLATION.'.'
    );
});

it('SCHEMA INVARIANT — every table with submitted_by AND decided_by carries a maker≠checker CHECK', function () {
    // Decision 4: the maker≠checker guarantee must be true for the THIRD approval table (refunds)
    // and every one after, by construction — not because a brief remembered to add it. A migration
    // that ships an approval document without the CHECK leaves every layer above it looking correct
    // (Policy refuses, Action refuses, tests pass) while the guarantee is silently absent. This
    // reads information_schema, so it sees the REAL schema, and it is general (finance_* AND
    // subject_result_statuses).
    //
    // BOTH arms watched red on a scratch table before this commit (see the PR body / commit message
    // for the pasted output): arm 1 — a table with NO check at all; arm 2 — a check naming only ONE
    // of the two columns (the likelier real mistake, caught by requiring BOTH names in the clause).
    // The prior comment claimed "bite-proven by a planted constraint-less table in a migration"; no
    // such migration exists in history (git log -S found none), so that was an unverifiable claim —
    // replaced by the watched scratch-table proof recorded with THIS commit.
    $tables = collect(DB::select(
        "SELECT DISTINCT c1.TABLE_NAME AS t
           FROM information_schema.COLUMNS c1
           JOIN information_schema.COLUMNS c2
             ON c1.TABLE_SCHEMA = c2.TABLE_SCHEMA AND c1.TABLE_NAME = c2.TABLE_NAME
          WHERE c1.TABLE_SCHEMA = DATABASE()
            AND c1.COLUMN_NAME = 'submitted_by' AND c2.COLUMN_NAME = 'decided_by'"
    ))->pluck('t');

    expect($tables)->not->toBeEmpty(); // there ARE approval tables, or this test is vacuous

    // Containment FLOOR, not a count pin. A table that DISAPPEARS (decided_by renamed away in a
    // refactor) drops out of the derived set and the loop below would iterate fewer tables, still
    // green — ->not->toBeEmpty() only catches ALL of them vanishing. This names the specific
    // expected table that went missing. Deliberately NO upper bound: approval table six must be
    // covered by the loop without anyone bumping a number, so its arrival is not a failure here.
    $expectedFloor = [
        'finance_discount_policy_changes',
        'finance_void_requests',
        'finance_fee_schedule_changes',
        'subject_result_statuses',
        'finance_credit_notes',
    ];
    $missing = array_diff($expectedFloor, $tables->all());
    expect($missing)->toBe(
        [],
        'expected approval table(s) no longer carry submitted_by + decided_by: ['.implode(', ', $missing).
        '] — a rename/refactor silently dropped one out of the invariant loop.'
    );

    foreach ($tables as $table) {
        $clauses = collect(DB::select(
            'SELECT cc.CHECK_CLAUSE AS clause
               FROM information_schema.CHECK_CONSTRAINTS cc
               JOIN information_schema.TABLE_CONSTRAINTS tc
                 ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
              WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = ?',
            [$table, 'CHECK']
        ))->pluck('clause');

        $hasMakerNeChecker = $clauses->contains(
            fn ($clause) => str_contains((string) $clause, 'submitted_by') && str_contains((string) $clause, 'decided_by')
        );

        // Print the CHECK clauses actually found. Arm 1 (no check) shows "(none)"; arm 2 (a check
        // naming one column) shows the clause, so the reader can tell a typo'd constraint from a
        // different constraint entirely without re-querying information_schema by hand.
        $found = $clauses->isEmpty() ? '(none)' : $clauses->implode(' | ');
        expect($hasMakerNeChecker)->toBeTrue(
            "table [{$table}] has submitted_by + decided_by but no CHECK naming BOTH — the act-level ".
            "guarantee is silently absent there. CHECK clauses found on it: {$found}"
        );
    }
});
