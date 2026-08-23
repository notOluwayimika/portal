<?php

/*
 * The seven rules that stopped being CHECK constraints — 2026_08_17_100000.
 *
 * Production is md-24.webhostbox.net, MySQL 5.7.23-23. MySQL enforces CHECK only from 8.0.16; before
 * that the clause is "parsed and ignored" — accepted by the parser, absent from SHOW CREATE TABLE,
 * never evaluated. So six maker≠checker constraints and the finance_payments origin/bank-account
 * pairing were enforced on this machine and silently absent on the server that holds the money. The
 * full audit of all of them, and why exactly these seven were converted and the rest documented, is
 * docs/finance/check-constraints-on-mysql-5-7.md.
 *
 * WHAT THIS FILE IS FOR, AND WHAT IT DELIBERATELY IS NOT. It pins the MECHANISM: fourteen triggers,
 * by name, timing and event, and the absence of the constraints they replaced. The BITE is proved
 * elsewhere, where it already was, and is not duplicated here — MakerCheckerSeparationTest for five
 * of the six approval tables, OpeningBalanceApprovalGateTest PROOF 1b for the sixth's UPDATE door,
 * BankAccountForeignKeysTest for both arms of the pairing. The one bite this file DOES carry is the
 * one no other file covers: the sixth table's INSERT door, which had no test when it was a CHECK
 * because a CHECK covers both doors with one clause and a trigger needs two objects.
 *
 * The migration verifies all fourteen by shape at apply time (ADR 0052), which proves they were
 * installed. This proves they are still there — a later migration that drops or renames one, or that
 * re-adds a CHECK in the belief that it is the guard, fails here rather than on production.
 */

use App\Enums\TermStatusEnum;
use App\Models\AcademicSession;
use App\Models\School;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\SchoolDay;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The seven rules, as (table, trigger stem, replaced constraint names).
 *
 * @return list<array{0: string, 1: string, 2: list<string>}>
 */
function cctRules(): array
{
    return [
        ['subject_result_statuses', 'subject_result_statuses_maker_ne_checker', ['subject_result_statuses_maker_ne_checker']],
        ['finance_credit_notes', 'finance_credit_notes_maker_ne_checker', ['finance_credit_notes_maker_ne_checker']],
        ['finance_void_requests', 'finance_void_requests_maker_ne_checker', ['finance_void_requests_maker_ne_checker']],
        ['finance_discount_policy_changes', 'finance_discount_policy_changes_maker_ne_checker', ['finance_discount_policy_changes_maker_ne_checker']],
        ['finance_fee_schedule_changes', 'finance_fee_schedule_changes_maker_ne_checker', ['finance_fee_schedule_changes_maker_ne_checker']],
        ['finance_opening_balance_batches', 'finance_opening_balance_batches_maker_ne_checker', ['finance_opening_balance_batches_maker_ne_checker']],
        // Rule 7 replaces TWO constraints with ONE predicate: the pairing subsumes the domain rule,
        // because an origin outside {portal, migrated} fails both of its arms.
        ['finance_payments', 'finance_payments_origin_pairing', ['finance_payments_origin_shape', 'finance_payments_bank_account_origin_shape']],
    ];
}

it('all FOURTEEN triggers exist, with the right timing, event and table', function () {
    $rows = collect(DB::select(
        'SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS tbl, ACTION_TIMING AS timing, EVENT_MANIPULATION AS event
           FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE()'
    ))->keyBy('name');

    $expected = [];
    foreach (cctRules() as [$table, $stem]) {
        $expected[$stem.'_bi'] = [$table, 'INSERT'];
        $expected[$stem.'_bu'] = [$table, 'UPDATE'];
    }

    expect($expected)->toHaveCount(14); // the loop above, not a number anyone maintains by hand

    foreach ($expected as $name => [$table, $event]) {
        $found = $rows->get($name);

        // POSITIVE, not `->not->toBeNull($message)`: Pest discards a message passed to a negated
        // expectation and prints a shortened-export of it instead — pinned by
        // tests/Feature/Quality/PestNegatedExpectationMessagesTest.php, which caught this arm.
        expect($found !== null)->toBeTrue(
            "trigger [{$name}] is missing. On MySQL 5.7 there is no CHECK behind it, so the rule it ".
            'carries is simply not enforced on production.'
        );
        // Timing and event asserted, not just existence: a trigger with the right name and the wrong
        // timing or event fires on writes nobody guarded and misses the ones they did.
        expect([$found->tbl, $found->timing, $found->event])->toBe([$table, 'BEFORE', $event]);
    }
});

it('the seven replaced CHECK constraints are GONE, so there is exactly one mechanism', function () {
    // Not tidiness. A CHECK is evaluated AFTER every BEFORE trigger (measured on 8.0.43 against a
    // scratch table violating both: driver code 1644, the trigger's message), so a re-added CHECK
    // would be unreachable on 8.0 and inert on 5.7 — a constraint that appears in the schema, reads
    // as the guard, and enforces nothing anywhere. This fails the moment one comes back.
    $present = collect(DB::select(
        "SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'"
    ))->pluck('name');

    $replaced = collect(cctRules())->flatMap(fn ($rule) => $rule[2]);
    $survivors = $replaced->intersect($present)->values()->all();

    expect($survivors)->toBe(
        [],
        'CHECK constraint(s) ['.implode(', ', $survivors).'] were re-added over the triggers that '.
        'replaced them. MySQL 5.7 parses and ignores CHECK, and on 8.0 the trigger fires first — so '.
        'this is a guard that enforces nothing on either server.'
    );
});

it('the untouched CHECK constraints are NOT collateral damage', function () {
    // The other twelve rules stay as CHECKs by decision, documented in the audit: the currency-shape
    // ones are backed by the CHAR(3) column type, the rest are validated at the edge with a narrow
    // blast radius. Six triggers to buy back a case-and-alphabet rule on a three-character column is
    // not a trade worth making. This names three of them so a broad DROP sweep is caught here.
    $present = collect(DB::select(
        "SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'"
    ))->pluck('name');

    // `student_curricula_promoted_requires_link` USED TO BE the academics canary here. It is no
    // longer a CHECK: 2026_08_20_140000 converted it to a BEFORE INSERT/UPDATE trigger pair, for the
    // same 5.7 reason this whole file exists — it had been inert on production since 2026-07-30, and
    // the promotion jobs were about to become its fourth and fifth writers. This test caught that
    // drop, which is exactly its job; `terms_end_after_start_check` replaces it so the sweep still
    // has a non-finance specimen, and the trigger that took over is asserted below.
    expect($present)->toContain('terms_end_after_start_check')
        ->and($present)->toContain('finance_discount_policy_changes_terms_shape')
        ->and($present)->toContain('finance_payments_amount_currency_shape');

    // The converted one must NOT be re-added as a CHECK over its triggers — the same trap the
    // "re-added over the triggers" test above guards for the finance fourteen.
    expect($present)->not->toContain('student_curricula_promoted_requires_link');

    $triggers = collect(DB::select(
        "SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'student_curricula'"
    ))->pluck('name');

    expect($triggers)->toContain('student_curricula_promoted_requires_link_bi')
        ->and($triggers)->toContain('student_curricula_promoted_requires_link_bu');
});

it('BITE — the sixth table refuses maker = checker on an INSERT, the door a CHECK covered for free', function () {
    // finance_opening_balance_batches names the maker/checker pair submitted_by_user_id /
    // decided_by_user_id. PROOF 1b in OpeningBalanceApprovalGateTest covers its UPDATE door. The
    // INSERT door had no test of its own: one CHECK clause covered both, and two triggers do not.
    $school = School::factory()->create();
    $maker = User::factory()->create(['school_id' => $school->id]);
    $checker = User::factory()->create(['school_id' => $school->id]);

    $term = ActiveSchool::runFor($school->id, function () use ($school) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027', 'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);

        return Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'Third Term',
            'slug' => 'term-'.Str::random(8), 'order' => 3, 'start_date' => now()->subMonths(4),
            'end_date' => now()->subMonth(), 'status' => TermStatusEnum::ACTIVE->value,
        ]);
    });

    $row = fn (?int $submitted, ?int $decided) => [
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'batch_reference' => 'WCBS-'.Str::random(8),
        'filename' => 'cutover.csv',
        'status' => 'submitted',
        'row_count' => 0,
        'file_row_count' => 0,
        'control_total_minor' => 0,
        'control_total_currency' => 'NGN',
        'cutover_date' => now()->toDateString(),
        'term_id' => $term->id,
        'uploaded_by_user_id' => $submitted,
        'submitted_by_user_id' => $submitted,
        'decided_by_user_id' => $decided,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // Asserting the MESSAGE, not merely the class: any SQL mistake in this fixture throws
    // QueryException too, and a bite-proof that passes for the wrong reason proves nothing.
    expect(fn () => DB::table('finance_opening_balance_batches')->insert($row($maker->id, $maker->id)))
        ->toThrow(QueryException::class, 'finance_opening_balance_batches: the checker must not be the maker.');

    // Not simply refusing every insert — distinct identities, and the NULL escape, both land.
    DB::table('finance_opening_balance_batches')->insert($row($maker->id, $checker->id));
    DB::table('finance_opening_balance_batches')->insert($row($maker->id, null));
    expect(DB::table('finance_opening_balance_batches')->count())->toBe(2);
});

it('BITE — the origin/bank-account pairing refuses a mismatched pair and accepts a legal one', function () {
    // WHAT THIS MEASURES, PRECISELY, AND WHAT IT DOES NOT. It measures the PAIRING: `portal` with no
    // bank account is refused by name, `migrated` with none lands, and a NULL origin does not land.
    // It does NOT measure the `COALESCE(…, 0)` in the trigger body, and an earlier title of this test
    // claimed it did. That claim was corrected by this branch's cold review.
    //
    // WHY THE COALESCE CANNOT BE MEASURED FROM HERE. `finance_payments.origin` is `NOT NULL`, so a
    // NULL insert is refused 1048 by the COLUMN before the trigger body ever runs. The NULL arm below
    // asserts only that the row does not land, which is true either way and therefore tells you
    // nothing about which guard spoke. The COALESCE is the belt behind a brace that is currently
    // holding: it is what would survive someone relaxing the column, and it is unreachable until
    // someone does.
    //
    // IT IS STILL LOAD-BEARING, AND THAT WAS ESTABLISHED — just not here. This branch's cold review
    // ran the shipped trigger body and a no-COALESCE control side by side on a scratch table, with a
    // nullable `origin`, and the control accepted the NULL row the shipped body refused. The reasoning
    // it confirms: a NULL origin makes both arms of the pairing NULL, `NULL OR NULL` is NULL, and
    // `NOT NULL` is NULL — which is not true, so a bare `IF NOT (…)` lets it straight through. (The
    // CHECK being replaced did not need the guard because SQL treats an unknown CHECK result as
    // SATISFIED — the same three-valued logic arriving at the same permissive answer from the other
    // direction. It is the one place a faithful translation would have been a behaviour change.)
    //
    // THAT CONTROL IS DELIBERATELY NOT ADDED TO THIS SUITE. It exercises a COPY of the predicate on a
    // scratch table, so it would pass unchanged if someone deleted the COALESCE from the real trigger
    // — a test of a copy is not a test of the trigger, and a green that cannot see the object it names
    // is the exact shape this whole branch exists to remove. The honest state is: reasoned, and
    // confirmed once by hand, on a copy.
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    $insert = fn (?string $origin) => DB::table('finance_payments')->insert([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $school->id,
        'student_id' => $student->id,
        'reference' => random_int(1, 800_000_000),
        'amount_minor' => 5000,
        'amount_currency' => 'NGN',
        'received_at' => SchoolDay::today(),
        'bank_account_id' => null,
        'payer_name' => 'Raw',
        'method' => 'manual',
        'origin' => $origin,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // NOT NULL on the column answers first here (1048). Either refusal is the database's, and this
    // asserts only that the row does not land — NOT which of the two guards spoke. See the header:
    // this arm is a floor, not evidence about the COALESCE.
    expect(fn () => $insert(null))->toThrow(QueryException::class);
    expect(DB::table('finance_payments')->count())->toBe(0);

    // The reachable half of the same predicate: a legal origin with the WRONG pairing is the trigger's.
    expect(fn () => $insert('portal')) // portal with no bank account
        ->toThrow(QueryException::class, 'finance_payments: a portal payment needs a bank account and a migrated payment must not have one.');

    // …and the legal pairing lands, so the trigger is not simply refusing everything.
    ActiveSchool::runFor($school->id, fn () => $insert('migrated'));
    expect(DB::table('finance_payments')->count())->toBe(1);
});
