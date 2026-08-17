<?php

/*
 * §9 step 4c — the approval gate on the opening-balance cutover
 * (docs/handoff/opening-balance-import-spec.md Rev 4, §8).
 *
 * FIVE PROOFS, every one watched RED before it was watched green — the mutation and both outputs are
 * in docs/handoff/reports/feat-finance-ob-approval-gate.md. A guard seen only green is equally
 * consistent with the guard working, the guard never running, and the fixture quietly satisfying it.
 *
 * WHAT IS PROVEN HERE, AND WHAT IS DELIBERATELY NOT. This file proves the GATE: who may post, from
 * which state, and what happens on each of the two decisions. It does NOT re-prove 4b's posting
 * mechanics — G1's 1062, G1b's two 1644s, the reserved receipt band, the netted payment's shape, the
 * carry-forward consumption. Those are OpeningBalancePostingTest's subject and ApproveOpeningBalanceBatch
 * re-implements none of them; asserting them again here would create a second place to keep in step.
 * PROOF 3 checks that approval REACHES that machinery (charges + one netted migrated payment exist),
 * not how it works.
 *
 * The maker ≠ checker refusal is asserted TWICE, at two layers, because they fail independently:
 * the Action's BusinessRuleException (a caller can act on it) and the maker≠checker TRIGGER's driver
 * code 1644 (survives the Action being edited away). Only the second distinguishes the database refusing
 * from PHP refusing.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\TermStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveOpeningBalanceBatch;
use App\Finance\Actions\PostOpeningBalanceBatch;
use App\Finance\Actions\RejectOpeningBalanceBatch;
use App\Finance\Actions\SubmitOpeningBalanceBatch;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\LedgerTransaction;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Finance\Models\Payment;
use App\Models\AcademicSession;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\ApprovalAbility;
use App\Support\DutySeparation;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A house-invariant TRIGGER refusal — `SIGNAL SQLSTATE '45000'`, driver code 1644. No PHP guard can
 * produce it.
 *
 * This was 3819 (a CHECK violation) until `2026_08_17_100000_maker_checker_and_payment_origin_as_triggers`.
 * The rule is unchanged and the NULL escape is unchanged; only the mechanism moved, because production
 * is MySQL 5.7.23 and enforces CHECK from 8.0.16 — so the constraint this proof relied on was real on
 * this machine and absent on the server that holds the money.
 */
const OBG_TRIGGER_VIOLATION = 1644;

/**
 * A School, a term to close out, and TWO DISTINCT users — the maker and the checker. Two users is the
 * whole subject of this file, so they are built together rather than one being conjured per test.
 *
 * @return array{school: School, term: Term, maker: User, checker: User}
 */
function obgContext(): array
{
    $school = School::factory()->create();
    $maker = User::factory()->create(['school_id' => $school->id]);
    $checker = User::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, function () use ($school, $maker, $checker) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'Third Term',
            'slug' => 'term-'.Str::random(8), 'order' => 3, 'start_date' => now()->subMonths(4),
            'end_date' => now()->subMonth(), 'status' => TermStatusEnum::ACTIVE->value,
        ]);

        return ['school' => $school, 'term' => $term, 'maker' => $maker, 'checker' => $checker];
    });
}

/**
 * A VALIDATED batch and its staged rows — the state a maker submits FROM. Written directly rather than
 * through the validator, for 4b's reason: the validator has its own 47KB of coverage and what this file
 * is about is what happens to a batch that has already reached `validated`.
 *
 * @param  list<array{student: Student, label: string, kobo: int}>  $rows
 */
function obgValidatedBatch(array $ctx, array $rows, string $reference = 'WCBS-GATE-1'): OpeningBalanceBatch
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $rows, $reference) {
        $batch = OpeningBalanceBatch::create([
            'batch_reference' => $reference,
            'filename' => $reference.'.csv',
            'status' => OpeningBalanceBatchStatus::Validated,
            'row_count' => count($rows),
            'file_row_count' => count($rows),
            'control_total' => Money::fromKobo(array_sum(array_column($rows, 'kobo'))),
            'cutover_date' => now()->toDateString(),
            'term_id' => $ctx['term']->id,
            'uploaded_by_user_id' => $ctx['maker']->id,
        ]);

        foreach ($rows as $i => $row) {
            OpeningBalanceRow::create([
                'batch_id' => $batch->id,
                'line_number' => $i + 1,
                'admission_number' => $row['student']->admission_number,
                'wcbs_student_ref' => 'WCBS-'.$row['student']->id,
                'fee_type_label' => $row['label'],
                'balance' => Money::fromKobo($row['kobo']),
                'student_total_balance' => Money::fromKobo($row['kobo']),
                'wcbs_bill_reference' => null,
                'student_id' => $row['student']->id,
                'status' => OpeningBalanceRowStatus::Ok,
            ]);
        }

        return $batch->refresh();
    });
}

/** Submit through the real Action, so `submitted_by_user_id` is set the way production sets it. */
function obgSubmit(array $ctx, OpeningBalanceBatch $batch, ?User $maker = null): OpeningBalanceBatch
{
    return ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => app(SubmitOpeningBalanceBatch::class)->handle($batch, $maker ?? $ctx['maker']),
    );
}

function obgApprove(array $ctx, OpeningBalanceBatch $batch, ?User $checker = null): OpeningBalanceBatch
{
    return ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => app(ApproveOpeningBalanceBatch::class)->handle($batch, $checker ?? $ctx['checker']),
    );
}

function obgReject(array $ctx, OpeningBalanceBatch $batch, string $reason, ?User $checker = null): OpeningBalanceBatch
{
    return ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => app(RejectOpeningBalanceBatch::class)->handle($batch, $reason, $checker ?? $ctx['checker']),
    );
}

/** The driver code of a QueryException — [1] in PDO's errorInfo. */
function obgDriverCode(QueryException $e): int
{
    return (int) ($e->errorInfo[1] ?? 0);
}

// ── PROOF 1 — the maker cannot approve their own submission ──

it('PROOF 1 — the MAKER who submitted a batch cannot approve it: refused, and NOTHING posts', function () {
    $ctx = obgContext();
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obgSubmit($ctx, obgValidatedBatch($ctx, [
        ['student' => $student, 'label' => 'Tuition', 'kobo' => 250000],
    ]));

    expect($batch->status)->toBe(OpeningBalanceBatchStatus::Submitted)
        ->and($batch->submitted_by_user_id)->toBe($ctx['maker']->id);

    // The maker, approving their own batch. This is the ONE act the whole gate exists to refuse.
    expect(fn () => obgApprove($ctx, $batch->refresh(), $ctx['maker']))
        ->toThrow(BusinessRuleException::class, 'maker ≠ checker');

    // Not merely refused — nothing moved. A refusal that had already written the decision or half the
    // ledger would satisfy an exception assertion and be a disaster.
    ActiveSchool::runFor($ctx['school']->id, function () use ($batch) {
        expect($batch->refresh()->status)->toBe(OpeningBalanceBatchStatus::Submitted)
            ->and($batch->refresh()->decided_by_user_id)->toBeNull()
            ->and($batch->refresh()->posted_at)->toBeNull();
    });

    expect((int) DB::scalar('SELECT COUNT(*) FROM finance_ledger_transactions'))->toBe(0)
        ->and((int) DB::scalar('SELECT COUNT(*) FROM finance_payments'))->toBe(0);
});

it('PROOF 1b — the same refusal at the DATABASE (1644), independent of the Action', function () {
    // The Action's `if` is one deletable line. This asserts the DATABASE refuses the row itself, by
    // DRIVER CODE — the only assertion that tells a database refusal apart from a PHP one. The guard
    // is finance_opening_balance_batches_maker_ne_checker_bu (2026_08_17_100000), which replaced the
    // CHECK added by 2026_08_09_100000 because MySQL 5.7 never enforced it.
    $ctx = obgContext();
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obgSubmit($ctx, obgValidatedBatch($ctx, [
        ['student' => $student, 'label' => 'Tuition', 'kobo' => 250000],
    ]));

    $code = 0;
    try {
        DB::update(
            'UPDATE finance_opening_balance_batches SET decided_by_user_id = ? WHERE id = ?',
            [$ctx['maker']->id, $batch->id],
        );
    } catch (QueryException $e) {
        $code = obgDriverCode($e);
    }

    expect($code)->toBe(OBG_TRIGGER_VIOLATION);

    // …and the SAME statement naming a different user is accepted, so the trigger is not simply
    // refusing every write to the column.
    DB::update(
        'UPDATE finance_opening_balance_batches SET decided_by_user_id = ? WHERE id = ?',
        [$ctx['checker']->id, $batch->id],
    );

    expect((int) DB::scalar('SELECT decided_by_user_id FROM finance_opening_balance_batches WHERE id = ?', [$batch->id]))
        ->toBe($ctx['checker']->id);
});

it('PROOF 1c — the pair is what the CONVENTION derives, not a list anyone maintains', function () {
    // If this triple were named differently — `finance.opening-balance.sign-off`, or a fourth segment
    // in the wrong place — DutySeparation would derive no pair from it, the grant-time SoD guard would
    // permit one role holding both sides, and nothing above would notice. That is what makes the
    // terminal segment load-bearing rather than cosmetic.
    $pairs = collect(DutySeparation::pairs());

    expect($pairs->firstWhere('checker', PermissionEnum::FINANCE_OPENING_BALANCE_APPROVE->value))
        ->toBe([
            'checker' => PermissionEnum::FINANCE_OPENING_BALANCE_APPROVE->value,
            'maker' => PermissionEnum::FINANCE_OPENING_BALANCE_SUBMIT->value,
        ])
        ->and($pairs->firstWhere('checker', PermissionEnum::FINANCE_OPENING_BALANCE_REJECT->value))
        ->toBe([
            'checker' => PermissionEnum::FINANCE_OPENING_BALANCE_REJECT->value,
            'maker' => PermissionEnum::FINANCE_OPENING_BALANCE_SUBMIT->value,
        ])
        // enforcedPairs() filters to `finance.` checkers — the set the finance migrations guard on.
        ->and(collect(DutySeparation::enforcedPairs())->pluck('checker'))
        ->toContain(PermissionEnum::FINANCE_OPENING_BALANCE_APPROVE->value);
});

// ── PROOF 2 — super_admin is on the maker side and off the checker side ──

it('PROOF 2 — super_admin CANNOT approve or reject a cutover, and CAN still hold the maker side', function () {
    // ADR 0040 mechanism 1, on this triple specifically. The exclusion is derived from the terminal
    // segment, so it covered these three abilities the moment they existed — this pins that it did,
    // by name, rather than trusting the enum-wide test to be read as covering the newest instance.
    $this->seed(DatabaseSeeder::class);
    config(['auth.gate_before_superadmin' => true]);

    $super = User::factory()->create();
    setPermissionsTeamId(null);
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();

    // Precondition: super_admin HOLDS none of the three (ADR 0045 — no ambient domain grants). Any
    // `true` below could therefore only have come from the Gate::before bypass.
    $granted = $super->getAllPermissions()->pluck('name');
    expect($granted)->not->toContain(PermissionEnum::FINANCE_OPENING_BALANCE_SUBMIT->value)
        ->and($granted)->not->toContain(PermissionEnum::FINANCE_OPENING_BALANCE_APPROVE->value)
        ->and($granted)->not->toContain(PermissionEnum::FINANCE_OPENING_BALANCE_REJECT->value);

    expect($super->can(PermissionEnum::FINANCE_OPENING_BALANCE_APPROVE->value))->toBeFalse()
        ->and($super->can(PermissionEnum::FINANCE_OPENING_BALANCE_REJECT->value))->toBeFalse()
        // The maker side is NOT a checker action, so the bypass still reaches it — asserted because
        // an over-broad exclusion (prefix-matching `finance.opening-balance.`) would fail HERE and
        // pass every assertion above, and would lock the platform authority out of a screen it needs.
        ->and($super->can(PermissionEnum::FINANCE_OPENING_BALANCE_SUBMIT->value))->toBeTrue();

    // And the classification is by terminal segment, on these exact strings.
    expect(ApprovalAbility::isExcludedFromSuperAdminBypass(PermissionEnum::FINANCE_OPENING_BALANCE_APPROVE->value))->toBeTrue()
        ->and(ApprovalAbility::isExcludedFromSuperAdminBypass(PermissionEnum::FINANCE_OPENING_BALANCE_SUBMIT->value))->toBeFalse();
});

// ── PROOF 3 — approval IS the post ──

it('PROOF 3 — APPROVE posts the batch: the charges and the netted migrated payment exist, status is posted', function () {
    $ctx = obgContext();
    $owing = Student::factory()->create(['school_id' => $ctx['school']->id]);
    $inCredit = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obgSubmit($ctx, obgValidatedBatch($ctx, [
        ['student' => $owing, 'label' => 'Tuition', 'kobo' => 400000],
        ['student' => $owing, 'label' => 'Bus', 'kobo' => 100000],
        ['student' => $inCredit, 'label' => 'Tuition', 'kobo' => -150000],
    ]));

    // Precondition, asserted: the subledger is empty BEFORE the approval. Without this, a proof that
    // rows exist afterwards would pass against rows some earlier step wrote.
    expect((int) DB::scalar('SELECT COUNT(*) FROM finance_ledger_transactions'))->toBe(0);

    $posted = obgApprove($ctx, $batch->refresh());

    expect($posted->status)->toBe(OpeningBalanceBatchStatus::Posted)
        ->and($posted->decided_by_user_id)->toBe($ctx['checker']->id)
        ->and($posted->decided_at)->not->toBeNull()
        ->and($posted->posted_by_user_id)->toBe($ctx['checker']->id)
        ->and($posted->posted_at)->not->toBeNull();

    ActiveSchool::runFor($ctx['school']->id, function () use ($owing, $inCredit) {
        // Two positive fee-type lines → two charges, sourced to the staged rows.
        $charges = LedgerTransaction::query()
            ->where('student_id', $owing->id)
            ->where('type', LedgerEntryType::Charge)
            ->where('source_type', 'opening_balance_row')
            ->get();

        expect($charges)->toHaveCount(2)
            ->and($charges->sum(fn ($t) => $t->amount->toKobo()))->toBe(500000);

        // ONE netted migrated payment for the student in credit, plus its ledger credit.
        $payments = Payment::query()->where('student_id', $inCredit->id)->get();

        expect($payments)->toHaveCount(1)
            ->and($payments->first()->origin)->toBe('migrated')
            ->and($payments->first()->amount->toKobo())->toBe(150000)
            ->and($payments->first()->reference)->toBeGreaterThan(Payment::MIGRATED_REFERENCE_FLOOR);

        expect(LedgerTransaction::query()
            ->where('student_id', $inCredit->id)
            ->where('type', LedgerEntryType::Payment)
            ->count())->toBe(1);
    });
});

it('PROOF 3b — approval is ONE transaction: a post that throws leaves the batch submitted and undecided', function () {
    // The decision columns are written BEFORE the post inside the same transaction. If that rollback
    // did not happen, a school whose post failed would carry an approval for a cutover that never
    // occurred — and G1 would still be free, so a SECOND approval could post a different batch while
    // the first one still reads "approved by".
    //
    // The failure is induced the way production would meet it: this school already has a posted batch,
    // so G1's unique key refuses the second post with 1062.
    $ctx = obgContext();
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    obgApprove($ctx, obgSubmit($ctx, obgValidatedBatch($ctx, [
        ['student' => $student, 'label' => 'Tuition', 'kobo' => 100000],
    ], 'WCBS-GATE-FIRST'))->refresh());

    $second = obgSubmit($ctx, obgValidatedBatch($ctx, [
        ['student' => $student, 'label' => 'Tuition', 'kobo' => 50000],
    ], 'WCBS-GATE-SECOND'));

    $code = 0;
    try {
        obgApprove($ctx, $second->refresh());
    } catch (QueryException $e) {
        $code = obgDriverCode($e);
    }

    expect($code)->toBe(1062);

    ActiveSchool::runFor($ctx['school']->id, function () use ($second) {
        $fresh = $second->refresh();
        expect($fresh->status)->toBe(OpeningBalanceBatchStatus::Submitted)
            ->and($fresh->decided_by_user_id)->toBeNull()
            ->and($fresh->decided_at)->toBeNull();
    });
});

// ── PROOF 4 — rejection moves no money ──

it('PROOF 4 — REJECT leaves the batch rejected with ZERO ledger rows and ZERO payments', function () {
    $ctx = obgContext();
    $owing = Student::factory()->create(['school_id' => $ctx['school']->id]);
    $inCredit = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obgSubmit($ctx, obgValidatedBatch($ctx, [
        ['student' => $owing, 'label' => 'Tuition', 'kobo' => 400000],
        ['student' => $inCredit, 'label' => 'Tuition', 'kobo' => -150000],
    ]));

    $rejected = obgReject($ctx, $batch->refresh(), 'The control total does not match the WCBS report.');

    expect($rejected->status)->toBe(OpeningBalanceBatchStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('The control total does not match the WCBS report.')
        ->and($rejected->decided_by_user_id)->toBe($ctx['checker']->id)
        ->and($rejected->decided_at)->not->toBeNull()
        ->and($rejected->posted_at)->toBeNull()
        ->and($rejected->posted_by_user_id)->toBeNull();

    // Both credit-side and charge-side: a rejection that wrote either would be the whole hazard.
    expect((int) DB::scalar('SELECT COUNT(*) FROM finance_ledger_transactions'))->toBe(0)
        ->and((int) DB::scalar('SELECT COUNT(*) FROM finance_payments'))->toBe(0);

    // The staged rows are RETAINED — a rejected cutover must still be readable.
    expect(ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceRow::query()->where('batch_id', $batch->id)->count()))
        ->toBe(2);
});

it('PROOF 4b — a reject with a blank reason is refused, and the batch stays submitted', function () {
    $ctx = obgContext();
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obgSubmit($ctx, obgValidatedBatch($ctx, [
        ['student' => $student, 'label' => 'Tuition', 'kobo' => 100000],
    ]));

    expect(fn () => obgReject($ctx, $batch->refresh(), '   '))
        ->toThrow(BusinessRuleException::class, 'A reason is required');

    ActiveSchool::runFor($ctx['school']->id, function () use ($batch) {
        expect($batch->refresh()->status)->toBe(OpeningBalanceBatchStatus::Submitted);
    });
});

it('PROOF 4c — the maker cannot reject their own batch either (both checker abilities, one rule)', function () {
    $ctx = obgContext();
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obgSubmit($ctx, obgValidatedBatch($ctx, [
        ['student' => $student, 'label' => 'Tuition', 'kobo' => 100000],
    ]));

    expect(fn () => obgReject($ctx, $batch->refresh(), 'I changed my mind.', $ctx['maker']))
        ->toThrow(BusinessRuleException::class, 'maker ≠ checker');

    ActiveSchool::runFor($ctx['school']->id, function () use ($batch) {
        expect($batch->refresh()->status)->toBe(OpeningBalanceBatchStatus::Submitted)
            ->and($batch->refresh()->rejection_reason)->toBeNull();
    });
});

// ── PROOF 5 — the state machine has no side doors ──

it('PROOF 5 — only a VALIDATED batch may be submitted, and only a SUBMITTED batch decided', function () {
    $ctx = obgContext();
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obgValidatedBatch($ctx, [['student' => $student, 'label' => 'Tuition', 'kobo' => 100000]]);

    // A batch that has NOT been submitted cannot be approved or rejected…
    expect(fn () => obgApprove($ctx, $batch))
        ->toThrow(BusinessRuleException::class, 'Only a submitted opening-balance batch can be approved')
        ->and(fn () => obgReject($ctx, $batch, 'no'))
        ->toThrow(BusinessRuleException::class, 'Only a submitted opening-balance batch can be rejected');

    // …and PostOpeningBalanceBatch itself refuses it, which is the gate seen from below: before 4c
    // this exact state posted. If this arm goes green as a post, the gate has a door beside it.
    expect(fn () => ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => app(PostOpeningBalanceBatch::class)->handle($batch, $ctx['checker']),
    ))->toThrow(BusinessRuleException::class, 'Only a submitted opening-balance batch can be posted');

    // Submitting twice is refused: the second submission would overwrite the first maker's attribution.
    $submitted = obgSubmit($ctx, $batch->refresh());
    expect(fn () => obgSubmit($ctx, $submitted->refresh()))
        ->toThrow(BusinessRuleException::class, 'Only a validated opening-balance batch can be submitted');

    // And a decided batch cannot be decided again.
    obgReject($ctx, $submitted->refresh(), 'Wrong file.');
    expect(fn () => obgApprove($ctx, $submitted->refresh()))
        ->toThrow(BusinessRuleException::class, 'Only a submitted opening-balance batch can be approved');

    expect((int) DB::scalar('SELECT COUNT(*) FROM finance_ledger_transactions'))->toBe(0);
});

it('PROOF 5b — submission fails closed with no active School context, and refuses another School batch', function () {
    // Rule 13. Every read in the Action goes through SchoolScope, so a missing context would find
    // nothing and look like a clean outcome.
    $ctx = obgContext();
    $other = obgContext();
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obgValidatedBatch($ctx, [['student' => $student, 'label' => 'Tuition', 'kobo' => 100000]]);

    expect(ActiveSchool::id())->toBeNull(); // outside any runFor — the precondition, asserted

    expect(fn () => app(SubmitOpeningBalanceBatch::class)->handle($batch, $ctx['maker']))
        ->toThrow(BusinessRuleException::class, 'No active School context');

    expect(fn () => ActiveSchool::runFor(
        $other['school']->id,
        fn () => app(SubmitOpeningBalanceBatch::class)->handle($batch, $other['maker']),
    ))->toThrow(BusinessRuleException::class, 'belongs to another School');

    ActiveSchool::runFor($ctx['school']->id, function () use ($batch) {
        expect($batch->refresh()->status)->toBe(OpeningBalanceBatchStatus::Validated)
            ->and($batch->refresh()->submitted_by_user_id)->toBeNull();
    });
});

// ── The grants, read from the seeder rather than restated ──

it('grants the maker side to the roles that hold fee-schedule.change.submit, and the checker side to the role that holds its approve', function () {
    // The rule this asserts is "the opening-balance triple follows the fee-schedule-change triple",
    // which is what the grantsMap edit claims. Written as a COMPARISON rather than as four role names,
    // so a future seat realignment that moves fee-schedule.change also moves this — or fails here,
    // loudly, instead of leaving the two triples silently diverged.
    $this->seed(DatabaseSeeder::class);

    $holders = function (string $ability): array {
        return Role::query()
            ->where('guard_name', 'web')
            ->whereHas('permissions', fn ($q) => $q->where('name', $ability))
            ->pluck('name')->sort()->values()->all();
    };

    expect($holders(PermissionEnum::FINANCE_OPENING_BALANCE_SUBMIT->value))
        ->toBe($holders(PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT->value))
        ->and($holders(PermissionEnum::FINANCE_OPENING_BALANCE_APPROVE->value))
        ->toBe($holders(PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_APPROVE->value))
        ->and($holders(PermissionEnum::FINANCE_OPENING_BALANCE_REJECT->value))
        ->toBe($holders(PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_REJECT->value));

    // Not vacuous: an ability nobody holds would make all three comparisons trivially equal.
    expect($holders(PermissionEnum::FINANCE_OPENING_BALANCE_SUBMIT->value))->not->toBeEmpty()
        ->and($holders(PermissionEnum::FINANCE_OPENING_BALANCE_APPROVE->value))->not->toBeEmpty();

    // And no role holds both sides — the thing DutySeparation forbids, checked on THIS pair.
    foreach ($holders(PermissionEnum::FINANCE_OPENING_BALANCE_SUBMIT->value) as $role) {
        expect($holders(PermissionEnum::FINANCE_OPENING_BALANCE_APPROVE->value))->not->toContain($role);
    }
});
