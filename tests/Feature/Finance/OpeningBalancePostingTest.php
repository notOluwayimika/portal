<?php

/*
 * §9 step 4b — posting a validated opening-balance batch into the subledger
 * (docs/handoff/opening-balance-import-spec.md Rev 4, §3).
 *
 * FIVE PROOFS, and every one of them was watched RED before it was watched green — the mutation and
 * both outputs are in docs/handoff/reports/feat-finance-ob-posting.md. A guard seen only green is
 * equally consistent with the guard working, the guard never running, and the fixture quietly
 * satisfying it.
 *
 * The two database guards are asserted BY DRIVER CODE, not by message or exit status, because that is
 * the only assertion that distinguishes the database refusing from PHP refusing:
 *   - G1  → 1062 (duplicate key on the generated posted_school_key). No PHP guard can produce 1062.
 *   - G1b → 1644 (SIGNAL 45000 from a trigger), on BOTH doors: UPDATE out of posted, and DELETE.
 *
 * The fifth proof is the reason step 2 writes a payment ROW rather than a bare ledger credit, and it
 * is end to end on purpose: it posts a credit, generates the student's next invoice, and asserts
 * GenerateInvoice::applyCreditForward actually consumed the migrated payment. If that does not hold,
 * §3 step 2's whole shape is wrong — nothing else in this file would tell you.
 */

use App\Enums\TermStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\PostOpeningBalanceBatch;
use App\Finance\Actions\RecordAccountPayment;
use App\Finance\Console\ImportOpeningBalances;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\LedgerTransaction;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Finance\Models\StudentAccount;
use App\Models\AcademicSession;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const OBP_DUPLICATE_ENTRY = 1062;

const OBP_SIGNAL_45000 = 1644;

/**
 * A School with a current session and a term — the term the batch names as the one being CLOSED OUT.
 *
 * @return array{school: School, term: Term, actor: User}
 */
function obpContext(): array
{
    $school = School::factory()->create();
    $actor = User::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, function () use ($school, $actor) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'Third Term',
            'slug' => 'term-'.Str::random(8), 'order' => 3, 'start_date' => now()->subMonths(4),
            'end_date' => now()->subMonth(), 'status' => TermStatusEnum::ACTIVE->value,
        ]);

        return ['school' => $school, 'term' => $term, 'actor' => $actor];
    });
}

/**
 * A SUBMITTED batch and its staged rows, written directly rather than through the validator.
 *
 * Deliberate: the validator is 4a's subject and has its own 43KB of coverage; what 4b is about is what
 * happens to a batch that has already reached the state posting runs from. Building the staged state
 * by hand is what lets a proof state exactly which rows exist — and it is the same state the pipeline
 * produces, since only a `validated` batch (every row ok, no batch-level finding) can be submitted.
 *
 * IT STAGES `submitted`, NOT `validated`, SINCE 4c. The approval gate moved PostOpeningBalanceBatch's
 * entry state, so a helper still staging `validated` would make every proof below assert a refusal
 * instead of a post — and the one test that DOES want the refusal now plants `validated` explicitly,
 * which is a stronger claim than it used to make: it proves the pre-4c door is shut.
 *
 * `submitted_by_user_id` is left NULL: 4b's proofs are about the POST, and a null submitter cannot
 * collide with the maker ≠ checker CHECK. The gate's own proofs (OpeningBalanceApprovalGateTest) set it.
 *
 * @param  list<array{student: Student, label: string, kobo: int, bill?: string|null}>  $rows
 */
function obpBatch(array $ctx, array $rows, string $reference = 'WCBS-BATCH-1'): OpeningBalanceBatch
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $rows, $reference) {
        $batch = OpeningBalanceBatch::create([
            'batch_reference' => $reference,
            'filename' => $reference.'.csv',
            'status' => OpeningBalanceBatchStatus::Submitted,
            'row_count' => count($rows),
            'file_row_count' => count($rows),
            'control_total' => Money::fromKobo(array_sum(array_column($rows, 'kobo'))),
            'cutover_date' => now()->toDateString(),
            'term_id' => $ctx['term']->id,
            'uploaded_by_user_id' => null,
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
                'wcbs_bill_reference' => $row['bill'] ?? null,
                'student_id' => $row['student']->id,
                'status' => OpeningBalanceRowStatus::Ok,
            ]);
        }

        return $batch->refresh();
    });
}

function obpPost(array $ctx, OpeningBalanceBatch $batch): OpeningBalanceBatch
{
    return ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => app(PostOpeningBalanceBatch::class)->handle($batch, $ctx['actor']),
    );
}

/** The driver code of a QueryException — [1] in PDO's errorInfo. */
function obpDriverCode(QueryException $e): int
{
    return (int) ($e->errorInfo[1] ?? 0);
}

// ── PROOF 1 — G1: a second posted batch for the same school is refused AT THE DATABASE ──

it('PROOF 1 — G1: a second batch reaching posted for the same school is refused by the unique key (1062)', function () {
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $first = obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 100000]], 'WCBS-A');
    obpPost($ctx, $first);

    $second = obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 50000]], 'WCBS-B');

    $code = 0;
    try {
        obpPost($ctx, $second);
    } catch (QueryException $e) {
        $code = obpDriverCode($e);
    }

    // 1062, not an exit code and not a message: a PHP guard cannot produce 1062, so this assertion is
    // what proves the refusal came from ob_batches_posted_school_unique and not from a code path.
    expect($code)->toBe(OBP_DUPLICATE_ENTRY);

    ActiveSchool::runFor($ctx['school']->id, function () use ($first, $second) {
        expect(OpeningBalanceBatch::query()->where('status', OpeningBalanceBatchStatus::Posted)->count())->toBe(1)
            ->and($first->refresh()->status)->toBe(OpeningBalanceBatchStatus::Posted)
            ->and($second->refresh()->status)->toBe(OpeningBalanceBatchStatus::Submitted);
    });
});

it('PROOF 1b — G1 constrains ONLY the posted state: unlimited draft/validated/submitted/rejected batches coexist', function () {
    // The NULL exemption is the whole reason the generated column is shaped IF(posted, school, NULL).
    // Without this case, a key that accidentally constrained every batch would still pass PROOF 1.
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 1]], 'WCBS-C1');
    obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 1]], 'WCBS-C2');
    obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 1]], 'WCBS-C3');

    ActiveSchool::runFor($ctx['school']->id, function () {
        expect(OpeningBalanceBatch::query()->count())->toBe(3);
    });
});

it('PROOF 1c — G1 is PER SCHOOL: another School posting its own batch is unaffected', function () {
    $a = obpContext();
    $b = obpContext();

    $sa = Student::factory()->create(['school_id' => $a['school']->id]);
    $sb = Student::factory()->create(['school_id' => $b['school']->id]);

    obpPost($a, obpBatch($a, [['student' => $sa, 'label' => 'Tuition', 'kobo' => 100000]], 'WCBS-A'));
    obpPost($b, obpBatch($b, [['student' => $sb, 'label' => 'Tuition', 'kobo' => 100000]], 'WCBS-A'));

    expect((int) DB::scalar("SELECT COUNT(*) FROM finance_opening_balance_batches WHERE status = 'posted'"))->toBe(2);
});

// ── PROOF 2 — G1b: the posted state is terminal, at BOTH doors ──

it('PROOF 2 — G1b: UPDATE …SET status=rejected on a posted batch is refused BY THE TRIGGER (1644)', function () {
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obpPost($ctx, obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 100000]]));

    // The EXACT release the Rev 3 finding described: one statement frees the generated key.
    $code = 0;
    try {
        DB::update("UPDATE finance_opening_balance_batches SET status = 'rejected' WHERE id = ?", [$batch->id]);
    } catch (QueryException $e) {
        $code = obpDriverCode($e);
    }

    expect($code)->toBe(OBP_SIGNAL_45000);

    ActiveSchool::runFor($ctx['school']->id, function () use ($batch) {
        expect($batch->refresh()->status)->toBe(OpeningBalanceBatchStatus::Posted);
    });
});

it('PROOF 2b — G1b: DELETE of a posted batch is refused BY THE TRIGGER (1644) — the second door', function () {
    // Not in §9's text, which specified BEFORE UPDATE only. DELETE frees the same unique key and
    // additionally CASCADEs the staged rows the posted ledger charges point at.
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obpPost($ctx, obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 100000]]));

    $code = 0;
    try {
        DB::delete('DELETE FROM finance_opening_balance_batches WHERE id = ?', [$batch->id]);
    } catch (QueryException $e) {
        $code = obpDriverCode($e);
    }

    expect($code)->toBe(OBP_SIGNAL_45000)
        ->and((int) DB::scalar('SELECT COUNT(*) FROM finance_opening_balance_batches WHERE id = ?', [$batch->id]))->toBe(1)
        ->and((int) DB::scalar('SELECT COUNT(*) FROM finance_opening_balance_rows WHERE batch_id = ?', [$batch->id]))->toBe(1);
});

it('PROOF 2c — G1b denies the STATUS move only: a posted batch stays otherwise updatable, and a non-posted batch stays deletable', function () {
    // The neighbouring value that must still be ACCEPTED. A trigger that froze the whole row would
    // pass PROOF 2 and 2b and quietly block 4c's approval screen from annotating a posted batch.
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $posted = obpPost($ctx, obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 100000]], 'WCBS-P'));
    $draft = obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 1]], 'WCBS-D');

    DB::update('UPDATE finance_opening_balance_batches SET filename = ? WHERE id = ?', ['renamed.csv', $posted->id]);
    DB::delete('DELETE FROM finance_opening_balance_batches WHERE id = ?', [$draft->id]);

    expect(DB::scalar('SELECT filename FROM finance_opening_balance_batches WHERE id = ?', [$posted->id]))->toBe('renamed.csv')
        ->and((int) DB::scalar('SELECT COUNT(*) FROM finance_opening_balance_batches WHERE id = ?', [$draft->id]))->toBe(0);
});

// ── PROOF 3 — the reserved band, and the live counter it must not touch ──

it('PROOF 3 — the migrated reference comes from the reserved band and the live receipt counter is UNCHANGED', function () {
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    // A real portal receipt first, so there IS a live counter to leave alone.
    $portal = ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => app(RecordAccountPayment::class)->handle($s1->id, Money::fromKobo(1000), 'Parent', $ctx['actor'], SchoolDay::today(), testBankAccountId()),
    );
    expect($portal->reference)->toBe(1);

    $counterBefore = (int) DB::scalar(
        'SELECT value FROM sequences WHERE scope = ? AND `key` = ?', ['finance_payment', (string) $ctx['school']->id]
    );

    obpPost($ctx, obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => -500000, 'bill' => 'WCBS-BILL-9']]));

    $migrated = ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => Payment::query()->where('origin', 'migrated')->sole(),
    );

    $counterAfter = (int) DB::scalar(
        'SELECT value FROM sequences WHERE scope = ? AND `key` = ?', ['finance_payment', (string) $ctx['school']->id]
    );

    expect($migrated->reference)->toBeGreaterThanOrEqual(Payment::MIGRATED_REFERENCE_FLOOR)
        ->and($migrated->reference)->toBe(Payment::MIGRATED_REFERENCE_FLOOR + 1)
        ->and($counterAfter)->toBe($counterBefore)     // the live counter never moved
        ->and($counterAfter)->toBe(1);

    // And the NEXT portal receipt is 2, not 900,000,002 — the seed trap approached from the other
    // side. If the Action had drawn through Sequences, this would be the number that gave it away.
    $next = ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => app(RecordAccountPayment::class)->handle($s1->id, Money::fromKobo(1000), 'Parent', $ctx['actor'], SchoolDay::today(), testBankAccountId()),
    );
    expect($next->reference)->toBe(2);
});

it('PROOF 3b — a second student in the same batch takes the NEXT band reference, and both are migrated', function () {
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);
    $s2 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    obpPost($ctx, obpBatch($ctx, [
        ['student' => $s1, 'label' => 'Tuition', 'kobo' => -100000, 'bill' => 'BILL-1'],
        ['student' => $s2, 'label' => 'Tuition', 'kobo' => -200000, 'bill' => null],
    ]));

    $references = ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => Payment::query()->orderBy('id')->pluck('reference', 'student_id')->all(),
    );

    expect($references[$s1->id])->toBe(Payment::MIGRATED_REFERENCE_FLOOR + 1)
        ->and($references[$s2->id])->toBe(Payment::MIGRATED_REFERENCE_FLOOR + 2);

    $rows = ActiveSchool::runFor($ctx['school']->id, fn () => Payment::query()->orderBy('id')->get());
    foreach ($rows as $row) {
        expect($row->origin)->toBe('migrated')
            ->and($row->method)->toBe('migrated')
            ->and($row->received_by_user_id)->toBeNull(); // nobody in THIS system received it
    }
    expect($rows->firstWhere('student_id', $s1->id)->external_reference)->toBe('BILL-1')
        ->and($rows->firstWhere('student_id', $s2->id)->external_reference)->toBeNull();
});

// ── PROOF 4 — the charge: one ledger row per positive fee type, label verbatim, sourced to the row ──

it('PROOF 4 — every positive fee-type balance posts ONE charge carrying the label verbatim and pointing at its staged row', function () {
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obpBatch($ctx, [
        ['student' => $s1, 'label' => 'Tuition', 'kobo' => 300000],
        ['student' => $s1, 'label' => 'PTA levy', 'kobo' => 50000],
        ['student' => $s1, 'label' => 'Bus (Term 3)', 'kobo' => 25000],
    ]);
    obpPost($ctx, $batch);

    ActiveSchool::runFor($ctx['school']->id, function () use ($batch, $s1) {
        $rows = OpeningBalanceRow::query()->where('batch_id', $batch->id)->orderBy('id')->get();
        $charges = LedgerTransaction::query()
            ->where('source_type', 'opening_balance_row')
            ->orderBy('id')
            ->get();

        expect($charges)->toHaveCount(3);

        foreach ($rows as $i => $row) {
            $charge = $charges[$i];
            expect($charge->type)->toBe(LedgerEntryType::Charge)
                ->and($charge->amount->toKobo())->toBe($row->balance->toKobo())
                ->and($charge->source_id)->toBe($row->id)
                // VERBATIM — the label's own spacing, casing and punctuation, not a normalised form.
                ->and($charge->narration)->toBe($row->fee_type_label.' — Balance Brought Forward')
                ->and($charge->student_id)->toBe($s1->id);
        }

        // The account projection moved by the same delta, because post() is the single writer.
        expect((int) StudentAccount::query()->where('student_id', $s1->id)->value('balance_minor'))->toBe(375000);

        // Step 3 — NOTHING ELSE. No invoice (R6), and no payment: nothing here is negative.
        expect((int) DB::scalar('SELECT COUNT(*) FROM finance_invoices'))->toBe(0)
            ->and((int) DB::scalar('SELECT COUNT(*) FROM finance_payments'))->toBe(0);
    });
});

it('PROOF 4b — the credit is NETTED per student, not per fee type: three negative lines, ONE payment', function () {
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    obpPost($ctx, obpBatch($ctx, [
        ['student' => $s1, 'label' => 'Tuition', 'kobo' => -100000],
        ['student' => $s1, 'label' => 'PTA levy', 'kobo' => -20000],
        ['student' => $s1, 'label' => 'Bus', 'kobo' => -30000],
    ]));

    ActiveSchool::runFor($ctx['school']->id, function () use ($s1) {
        $payments = Payment::query()->get();
        $credits = LedgerTransaction::query()->where('type', LedgerEntryType::Payment)->get();

        expect($payments)->toHaveCount(1)
            ->and($payments->first()->amount->toKobo())->toBe(150000)
            ->and($credits)->toHaveCount(1)
            ->and($credits->first()->amount->toKobo())->toBe(-150000)
            ->and($credits->first()->source_type)->toBe('payment')
            ->and($credits->first()->source_id)->toBe($payments->first()->id)
            // Credit is a NEGATIVE account balance.
            ->and((int) StudentAccount::query()->where('student_id', $s1->id)->value('balance_minor'))->toBe(-150000)
            // No charge row: nothing in this batch was positive.
            ->and((int) DB::scalar("SELECT COUNT(*) FROM finance_ledger_transactions WHERE source_type = 'opening_balance_row'"))->toBe(0);
    });
});

// ── PROOF 5 — THE CREDIT, END TO END. The reason step 2 posts a payment row at all. ──

it('PROOF 5 — a posted credit is CONSUMED by the next invoice: applyCreditForward draws the migrated payment', function () {
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    obpPost($ctx, obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => -500000, 'bill' => 'BILL-7']]));

    ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $s1) {
        $migrated = Payment::query()->where('origin', 'migrated')->sole();

        expect((int) StudentAccount::query()->where('student_id', $s1->id)->value('balance_minor'))->toBe(-500000);

        $enrollment = StudentCurriculum::create([
            'student_id' => $s1->id,
            'school_id' => $ctx['school']->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $ctx['school']->id])->id,
            'status' => 'active',
        ]);

        $invoice = app(GenerateInvoice::class)->handle(
            $enrollment->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo(1200000))],
            InvoiceKind::Scheduled,
        );

        $allocations = PaymentAllocation::query()->where('invoice_id', $invoice->id)->get();
        $allocated = (int) $allocations->sum('amount_minor');

        expect($allocations)->toHaveCount(1)
            // Drawn from the MIGRATED payment — this is the load-bearing assertion. A bare ledger
            // credit would net the balance identically and leave this at zero.
            ->and($allocations->first()->payment_id)->toBe($migrated->id)
            ->and($allocated)->toBe(500000)
            // The invoice's outstanding FELL by the credit.
            ->and($invoice->total->toKobo() - $allocated)->toBe(700000)
            // …and applying it moved NO money: the balance is the pre-credit −500000 plus the new
            // 1,200,000 charge. Applying credit is a settlement link, not a ledger movement.
            ->and((int) StudentAccount::query()->where('student_id', $s1->id)->value('balance_minor'))->toBe(700000);
    });
});

// ── The Action's own refusals ──

it('refuses to post a batch that is not submitted — including a VALIDATED one — and one belonging to another School', function () {
    // The `validated` arm is the 4c gate seen from below: before the approval gate existed, this was
    // precisely the state that posted. It must now be refused, or the gate has a door beside it.
    $ctx = obpContext();
    $other = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 100000]]);
    ActiveSchool::runFor($ctx['school']->id, fn () => $batch->update(['status' => OpeningBalanceBatchStatus::Validated]));

    expect(fn () => obpPost($ctx, $batch->refresh()))
        ->toThrow(BusinessRuleException::class);

    ActiveSchool::runFor($ctx['school']->id, fn () => $batch->update(['status' => OpeningBalanceBatchStatus::Rejected]));

    expect(fn () => obpPost($ctx, $batch->refresh()))
        ->toThrow(BusinessRuleException::class);

    $posted = obpBatch($other, [['student' => Student::factory()->create(['school_id' => $other['school']->id]), 'label' => 'T', 'kobo' => 1]]);
    expect(fn () => ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => app(PostOpeningBalanceBatch::class)->handle($posted, $ctx['actor']),
    ))->toThrow(BusinessRuleException::class);

    expect((int) DB::scalar("SELECT COUNT(*) FROM finance_opening_balance_batches WHERE status = 'posted'"))->toBe(0)
        ->and((int) DB::scalar('SELECT COUNT(*) FROM finance_ledger_transactions'))->toBe(0);
});

// ── PROOF 6 — the staged rows a posted charge cites are terminal too ──

it('PROOF 6 — G1b at the ROW level: UPDATE and DELETE of a posted batch staged row are both refused (1644)', function () {
    // 2026_08_08_110000 closed both exits from the posted BATCH. The rows its charges point at carried
    // no trigger at all, so the same end state — ledger charges citing source_ids that no longer exist
    // — was reachable one table down, by the front door.
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obpPost($ctx, obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 100000]]));
    $rowId = (int) DB::scalar('SELECT id FROM finance_opening_balance_rows WHERE batch_id = ?', [$batch->id]);

    $updateCode = 0;
    try {
        DB::update('UPDATE finance_opening_balance_rows SET balance_minor = 1 WHERE id = ?', [$rowId]);
    } catch (QueryException $e) {
        $updateCode = obpDriverCode($e);
    }

    $deleteCode = 0;
    try {
        DB::delete('DELETE FROM finance_opening_balance_rows WHERE id = ?', [$rowId]);
    } catch (QueryException $e) {
        $deleteCode = obpDriverCode($e);
    }

    expect($updateCode)->toBe(OBP_SIGNAL_45000)
        ->and($deleteCode)->toBe(OBP_SIGNAL_45000)
        ->and((int) DB::scalar('SELECT COUNT(*) FROM finance_opening_balance_rows WHERE id = ?', [$rowId]))->toBe(1)
        ->and((int) DB::scalar('SELECT balance_minor FROM finance_opening_balance_rows WHERE id = ?', [$rowId]))->toBe(100000);
});

it('PROOF 6b — the row guards are conditional on the PARENT, so an unposted batch stays freely writable', function () {
    // The neighbouring value that must still be ACCEPTED. Unconditional row triggers would pass PROOF
    // 6 and break every validation run, because staging is where rows are written and rewritten.
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 100000]], 'WCBS-OPEN');
    $rowId = (int) DB::scalar('SELECT id FROM finance_opening_balance_rows WHERE batch_id = ?', [$batch->id]);

    DB::update('UPDATE finance_opening_balance_rows SET balance_minor = 7 WHERE id = ?', [$rowId]);
    expect((int) DB::scalar('SELECT balance_minor FROM finance_opening_balance_rows WHERE id = ?', [$rowId]))->toBe(7);

    DB::delete('DELETE FROM finance_opening_balance_rows WHERE id = ?', [$rowId]);
    expect((int) DB::scalar('SELECT COUNT(*) FROM finance_opening_balance_rows WHERE id = ?', [$rowId]))->toBe(0);
});

// ── PROOF 7 — G1's key is two columns, and both doors out are shut ──

it('PROOF 7 — G1b: moving a posted batch to another School is refused (1644), on a batch with NO staged rows', function () {
    // ZERO staged rows deliberately. finance_opening_balance_rows_batch_school_foreign is NO ACTION on
    // update, so it refuses this statement with 1451 for any batch that HAS rows — incidentally, for a
    // different purpose, and one `ON UPDATE CASCADE` away from being lost. A zero-row posted batch is
    // outside that FK entirely, so this case is the one that proves the TRIGGER is doing the work.
    $ctx = obpContext();
    $other = obpContext();

    $batch = obpPost($ctx, obpBatch($ctx, [], 'WCBS-EMPTY'));
    expect((int) DB::scalar('SELECT COUNT(*) FROM finance_opening_balance_rows WHERE batch_id = ?', [$batch->id]))->toBe(0);

    $code = 0;
    try {
        DB::update(
            'UPDATE finance_opening_balance_batches SET school_id = ? WHERE id = ?',
            [$other['school']->id, $batch->id],
        );
    } catch (QueryException $e) {
        $code = obpDriverCode($e);
    }

    expect($code)->toBe(OBP_SIGNAL_45000)
        ->and((int) DB::scalar('SELECT school_id FROM finance_opening_balance_batches WHERE id = ?', [$batch->id]))
        ->toBe($ctx['school']->id)
        ->and((int) DB::scalar('SELECT posted_school_key FROM finance_opening_balance_batches WHERE id = ?', [$batch->id]))
        ->toBe($ctx['school']->id);
});

// ── PROOF 8 — the derived length limits, and the arithmetic behind them ──

it('PROOF 8 — the validator limits are DERIVED from the strings this Action builds, not remembered', function () {
    // A PHP const expression cannot call mb_strlen, so both limits are literals with their arithmetic
    // written beside them. This is what makes that safe: edit an affix without moving its limit and
    // this goes red, rather than a cutover aborting at 1406 on the day.
    expect(ImportOpeningBalances::COLUMNS['fee_type_label']['max'])
        ->toBe(PostOpeningBalanceBatch::SNAPSHOT_COLUMN_MAX - mb_strlen(PostOpeningBalanceBatch::NARRATION_SUFFIX))
        ->and(ImportOpeningBalances::BATCH_REFERENCE_MAX)
        ->toBe(PostOpeningBalanceBatch::SNAPSHOT_COLUMN_MAX - mb_strlen(
            PostOpeningBalanceBatch::PAYER_NAME_PREFIX.PostOpeningBalanceBatch::PAYER_NAME_SUFFIX
        ));

    // And the columns those numbers are sized against really are 255 — read from information_schema,
    // not from a migration's source, because the rule exists to keep a value out of a real column.
    $narration = (int) DB::scalar(
        "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_ledger_transactions' AND COLUMN_NAME = 'narration'"
    );
    $payerName = (int) DB::scalar(
        "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_payments' AND COLUMN_NAME = 'payer_name'"
    );

    expect($narration)->toBe(PostOpeningBalanceBatch::SNAPSHOT_COLUMN_MAX)
        ->and($payerName)->toBe(PostOpeningBalanceBatch::SNAPSHOT_COLUMN_MAX);
});

it('PROOF 8b — the mirror at the posting end: a label at the limit posts, and the narration is EXACTLY 255', function () {
    // The assertion that proves the arithmetic instead of restating it. A label one character longer
    // could not reach here — the validator refuses it — and this shows why that limit is the right one.
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);
    $label = str_repeat('X', ImportOpeningBalances::COLUMNS['fee_type_label']['max']);

    obpPost($ctx, obpBatch($ctx, [['student' => $s1, 'label' => $label, 'kobo' => 100000]]));

    ActiveSchool::runFor($ctx['school']->id, function () use ($label) {
        $charge = LedgerTransaction::query()->where('source_type', 'opening_balance_row')->sole();

        expect(mb_strlen($charge->narration))->toBe(PostOpeningBalanceBatch::SNAPSHOT_COLUMN_MAX)
            ->and($charge->narration)->toBe($label.PostOpeningBalanceBatch::NARRATION_SUFFIX);
    });
});

it('PROOF 8c — the mirror for payer_name: a batch reference at the limit posts, and payer_name is EXACTLY 255', function () {
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);
    $reference = str_repeat('R', ImportOpeningBalances::BATCH_REFERENCE_MAX);

    obpPost($ctx, obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => -100000]], $reference));

    ActiveSchool::runFor($ctx['school']->id, function () use ($reference) {
        $payment = Payment::query()->where('origin', 'migrated')->sole();
        $credit = LedgerTransaction::query()->where('type', LedgerEntryType::Payment)->sole();

        expect(mb_strlen($payment->payer_name))->toBe(PostOpeningBalanceBatch::SNAPSHOT_COLUMN_MAX)
            ->and($payment->payer_name)->toBe(
                PostOpeningBalanceBatch::PAYER_NAME_PREFIX.$reference.PostOpeningBalanceBatch::PAYER_NAME_SUFFIX
            )
            // The credit narration carries NO operator input, so its length is bounded by the Action
            // alone and the batch reference cannot overflow it. That is why one limit, not two.
            ->and(mb_strlen($credit->narration))->toBeLessThan(PostOpeningBalanceBatch::SNAPSHOT_COLUMN_MAX)
            ->and($credit->narration)->not->toContain($reference);
    });
});

// ── The Action's fail-closed context guard ──

it('refuses to post with NO active School context — the fail-closed branch, exercised', function () {
    // Rule 13. Without it, every SchoolScope-bound read in the Action returns an empty set and the
    // post is a successful-looking no-op that marks the batch posted and moves no money.
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);
    $batch = obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 100000]]);

    expect(ActiveSchool::id())->toBeNull(); // outside any runFor — the precondition, asserted

    expect(fn () => app(PostOpeningBalanceBatch::class)->handle($batch, $ctx['actor']))
        ->toThrow(BusinessRuleException::class, 'No active School context');

    ActiveSchool::runFor($ctx['school']->id, function () use ($batch) {
        expect($batch->refresh()->status)->toBe(OpeningBalanceBatchStatus::Submitted);
    });

    expect((int) DB::scalar('SELECT COUNT(*) FROM finance_ledger_transactions'))->toBe(0)
        ->and((int) DB::scalar('SELECT COUNT(*) FROM finance_payments'))->toBe(0);
});

it('records WHO posted and WHEN, on the batch', function () {
    $ctx = obpContext();
    $s1 = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obpPost($ctx, obpBatch($ctx, [['student' => $s1, 'label' => 'Tuition', 'kobo' => 100000]]));

    expect($batch->status)->toBe(OpeningBalanceBatchStatus::Posted)
        ->and($batch->posted_by_user_id)->toBe($ctx['actor']->id)
        ->and($batch->posted_at)->not->toBeNull();
});
