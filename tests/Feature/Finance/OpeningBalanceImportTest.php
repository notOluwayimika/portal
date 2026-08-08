<?php

/*
 * §9 step 4a — the read-only WCBS opening-balance validator, on R5's balance-forward file
 * (docs/handoff/opening-balance-import-spec.md Rev 4).
 *
 * Every rule the command claims to enforce has a RED case here, not just a green one: a rule whose
 * only test is the happy path is wallpaper, because a green is equally consistent with the rule
 * working, the rule never running, and the fixture quietly satisfying it. So each block pairs the
 * violation with the neighbouring value that must still be ACCEPTED — "0.00" beside a blank, a
 * NEGATIVE balance beside a rejected one, a blank optional column beside a blank required one.
 *
 * THREE OF THESE ARE BEHAVIOUR CHANGES from what shipped in commit 1, and they are tested as
 * changes rather than as new features, because each one used to fail:
 *   - a blank `wcbs_bill_reference` is ACCEPTED (it was required);
 *   - a NEGATIVE `balance` is ACCEPTED (the retired non-negative rule would have rejected it);
 *   - two lines for one student differing only in the CASE of the fee type are ONE key, at the
 *     database and in the in-PHP duplicate pass alike (§12 decision 3).
 *
 * Nothing here asserts a posting. There is no posting in this commit; the assertions that matter
 * most are the ones proving the tables stay empty of consequence.
 */

use App\Enums\TermStatusEnum;
use App\Finance\Actions\PostOpeningBalanceBatch;
use App\Finance\Console\ImportOpeningBalances;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const OB_HEADER = 'admission_number,wcbs_student_ref,fee_type_label,balance,student_total_balance,wcbs_bill_reference';

/**
 * A School with a current session, an active term, a class level and an arm — the coordinates an
 * enrollment keys off. (No fee schedule: §5's comparison is withdrawn, so the import reaches no
 * price and no episode.)
 *
 * @return array{school: School, term: Term, level: ClassLevel, arm: ClassLevelArm}
 */
function obSchool(): array
{
    $school = School::factory()->create();

    return ActiveSchool::runFor($school->id, function () use ($school) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
            'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
        ]);
        $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);
        $arm = ClassLevelArm::create([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'arm_id' => Arm::create(['school_id' => $school->id, 'label' => strtoupper(Str::random(3))])->id,
        ]);

        return ['school' => $school, 'term' => $term, 'level' => $level, 'arm' => $arm];
    });
}

/** A student in $school with $admission, enrolled ACTIVE on a curriculum for ($term, $arm). */
function obStudent(array $ctx, string $admission, ?Term $term = null): Student
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $admission, $term) {
        $student = Student::factory()->create([
            'school_id' => $ctx['school']->id,
            'admission_number' => $admission,
        ]);

        $curriculum = Curriculum::factory()->create([
            'school_id' => $ctx['school']->id,
            'class_level_arm_id' => $ctx['arm']->id,
            'term_id' => ($term ?? $ctx['term'])->id,
        ]);

        StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $ctx['school']->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        return $student;
    });
}

/** Write $lines (data rows; the header is prepended) to a temp CSV and return its path. */
function obCsv(array $lines): string
{
    $path = tempnam(sys_get_temp_dir(), 'ob').'.csv';
    file_put_contents($path, OB_HEADER."\n".implode("\n", $lines)."\n");

    return $path;
}

/**
 * Run the validator. Returns the exit code.
 *
 * `--control-total` is REQUIRED (§1 L2, §12 decision 2) and defaults here to a value the caller
 * overrides when it is the thing under test — it is the operator's attestation, not a figure derived
 * from the file, so a helper that computed it from the rows would defeat the check it feeds.
 */
function obRun(array $ctx, string $csv, array $overrides = []): int
{
    return test()->artisan('finance:import-opening-balances', array_merge([
        '--file' => $csv,
        '--school' => (string) $ctx['school']->id,
        '--closing-term' => (string) $ctx['term']->id,
        '--as-at' => '2026-08-06',
        '--control-total' => '0.00',
        '--batch-reference' => 'BATCH-'.Str::random(8),
        '--dry-run' => true,
    ], $overrides))->run();
}

/** The staged rows for the School, keyed by line number. */
function obRows(array $ctx): array
{
    return ActiveSchool::runFor($ctx['school']->id,
        fn () => OpeningBalanceRow::query()->orderBy('line_number')->get()->keyBy('line_number')->all());
}

function obBatch(array $ctx): OpeningBalanceBatch
{
    return ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceBatch::query()->latest('id')->firstOrFail());
}

/** The finding codes on a staged row. */
function obCodes(OpeningBalanceRow $row): array
{
    return array_column($row->findings ?? [], 'code');
}

/** The finding codes on the batch. */
function obBatchCodes(OpeningBalanceBatch $batch): array
{
    return array_column($batch->findings ?? [], 'code');
}

// ── §1 L1 — the student's row-group against their stated total ──

it('accepts a student whose fee-type balances sum to their stated total, and rejects the WHOLE row-group of one that is off by a kobo, naming both sides', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-OK');
    obStudent($ctx, 'ADM-BAD');

    // ADM-OK: 100,000 + 45,000 = 145,000 ✓
    // ADM-BAD: 100,000 + 45,000 = 145,000 but the file states 145,000.01.
    $exit = obRun($ctx, obCsv([
        'ADM-OK,W1,Tuition,100000.00,145000.00,BILL-1',
        'ADM-OK,W1,Bus,45000.00,145000.00,BILL-1',
        'ADM-BAD,W2,Tuition,100000.00,145000.01,BILL-2',
        'ADM-BAD,W2,Bus,45000.00,145000.01,BILL-2',
    ]), ['--control-total' => '290000.01']);

    $rows = obRows($ctx);

    // The sound student's rows are untouched by their neighbour's failure.
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and($rows[3]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and(obCodes($rows[2]))->not->toContain('student_total_mismatch');

    // BOTH of the failing student's rows are rejected — not just the one carrying the arithmetic.
    // A partial post is worse than none (§7), so the group is the unit.
    expect($rows[4]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and($rows[5]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[4]))->toContain('student_total_mismatch')
        ->and(obCodes($rows[5]))->toContain('student_total_mismatch')
        ->and($exit)->toBe(1);

    // BOTH sides in the finding — a bare "L1 failed" tells the operator nothing about which line the
    // extract lost.
    $message = collect($rows[4]->findings)->firstWhere('code', 'student_total_mismatch')['message'];
    expect($message)->toContain('145000.00')   // what the lines sum to
        ->and($message)->toContain('145000.01') // what the file states
        ->and($message)->toContain('-1 kobo');
});

it('rejects the whole row-group when the stated total disagrees with itself across a student\'s rows', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-SPLIT');

    $exit = obRun($ctx, obCsv([
        'ADM-SPLIT,W1,Tuition,100000.00,145000.00,BILL-1',
        'ADM-SPLIT,W1,Bus,45000.00,150000.00,BILL-1',   // a different total on the second row
    ]), ['--control-total' => '145000.00']);

    $rows = obRows($ctx);
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and($rows[3]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[2]))->toContain('inconsistent_student_total')
        ->and($exit)->toBe(1);

    // And L2 must not silently sum such a student in: a group with two stated totals has no total.
    $message = collect(obBatch($ctx)->findings)->firstWhere('code', 'control_total_mismatch')['message'];
    expect($message)->toContain('over 0 student(s)')
        ->and($message)->toContain('1 student(s) stated no usable total');
});

it('rejects a student\'s sound rows too when a sibling row has no usable figure, so nothing stages part-checked', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-PARTIAL');

    $exit = obRun($ctx, obCsv([
        'ADM-PARTIAL,W1,Tuition,100000.00,145000.00,BILL-1',
        'ADM-PARTIAL,W1,Bus,,145000.00,BILL-1',          // blank balance — L1 cannot be evaluated
    ]), ['--control-total' => '145000.00']);

    $rows = obRows($ctx);
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[2]))->toContain('l1_not_checkable')
        ->and($rows[3]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[3]))->toContain('blank_required_column')
        ->and($exit)->toBe(1);
});

// ── §1 L2 — the operator's control total, which is not in the file ──

it('raises a BATCH finding when the stated totals do not sum to --control-total, and rejects NO row', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-A');
    obStudent($ctx, 'ADM-B');

    // Both students are internally consistent (L1 passes); the operator's figure is 10,000 short,
    // which is what a student missing from the export looks like.
    $exit = obRun($ctx, obCsv([
        'ADM-A,W1,Tuition,100000.00,100000.00,BILL-1',
        'ADM-B,W2,Tuition,50000.00,50000.00,BILL-2',
    ]), ['--control-total' => '140000.00']);

    $batch = obBatch($ctx);
    $rows = obRows($ctx);

    expect(obBatchCodes($batch))->toContain('control_total_mismatch')
        ->and($batch->status)->toBe(OpeningBalanceBatchStatus::Rejected)
        // NOT a row-level failure: every line may be internally consistent and the file still be
        // missing a student.
        ->and($rows[2]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and($rows[3]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and($exit)->toBe(1);

    $message = collect($batch->findings)->firstWhere('code', 'control_total_mismatch')['message'];
    expect($message)->toContain('150000.00')   // Σ of the stated totals
        ->and($message)->toContain('140000.00') // what the operator typed
        ->and($message)->toContain('1000000 kobo');
});

it('records the operator control total on the batch, on a passing run AND on a rejected one', function () {
    // L2's witness is an ATTESTATION — a human read it off WCBS's own report and typed it — and it
    // is the only figure here no code derived. Kept only on success it could not be reviewed after a
    // rejection, which is exactly when someone asks what was claimed (§11's go/no-go).
    $ctx = obSchool();
    obStudent($ctx, 'ADM-PASS');
    obStudent($ctx, 'ADM-FAIL');

    // Passing: Σ stated = the control total.
    expect(obRun($ctx, obCsv([
        'ADM-PASS,W1,Tuition,100000.00,100000.00,BILL-1',
    ]), ['--control-total' => '100000.00', '--batch-reference' => 'CT-PASS']))->toBe(0);

    $passing = ActiveSchool::runFor($ctx['school']->id,
        fn () => OpeningBalanceBatch::query()->where('batch_reference', 'CT-PASS')->firstOrFail());
    expect($passing->status)->toBe(OpeningBalanceBatchStatus::Validated)
        ->and($passing->control_total->toKobo())->toBe(10000000);

    // Rejected: the operator's figure is wrong, and it is that WRONG figure that must be on record.
    expect(obRun($ctx, obCsv([
        'ADM-FAIL,W2,Tuition,50000.00,50000.00,BILL-2',
    ]), ['--control-total' => '99999.00', '--batch-reference' => 'CT-FAIL']))->toBe(1);

    $rejected = ActiveSchool::runFor($ctx['school']->id,
        fn () => OpeningBalanceBatch::query()->where('batch_reference', 'CT-FAIL')->firstOrFail());
    expect($rejected->status)->toBe(OpeningBalanceBatchStatus::Rejected)
        ->and(obBatchCodes($rejected))->toContain('control_total_mismatch')
        ->and($rejected->control_total->toKobo())->toBe(9999900);
});

it('records a NEGATIVE control total, because a school net in credit has one', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-NEG-CT');

    expect(obRun($ctx, obCsv([
        'ADM-NEG-CT,W1,Tuition,-50000.00,-50000.00,BILL-1',
    ]), ['--control-total' => '-50000.00']))->toBe(0);

    expect(obBatch($ctx)->control_total->toKobo())->toBe(-5000000);
});

it('refuses to run at all without --control-total, and stages nothing', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-A');

    $exit = test()->artisan('finance:import-opening-balances', [
        '--file' => obCsv(['ADM-A,W1,Tuition,100000.00,100000.00,BILL-1']),
        '--school' => (string) $ctx['school']->id,
        '--closing-term' => (string) $ctx['term']->id,
        '--as-at' => '2026-08-06',
        '--dry-run' => true,
    ])->expectsOutputToContain('--control-total is required')->run();

    expect($exit)->toBe(1)
        ->and(ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceBatch::query()->count()))->toBe(0);
});

// ── §12 decision 3 — one fee type, spelled two ways ──

it('treats a fee type differing only in case as ONE key: the second line is reported and never staged', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-CASE');

    $exit = obRun($ctx, obCsv([
        'ADM-CASE,W1,Tuition,100000.00,100000.00,BILL-1',
        'ADM-CASE,W1,tuition,45000.00,100000.00,BILL-1',   // same fee type, different spelling
    ]), ['--control-total' => '100000.00']);

    $batch = obBatch($ctx);
    expect(obRows($ctx))->toHaveCount(1)              // the second line never became a row
        ->and(obBatchCodes($batch))->toContain('duplicate_row_key_in_file')
        ->and($batch->file_row_count)->toBe(2)        // but it WAS read, and is accounted for
        ->and($batch->row_count)->toBe(1)
        ->and(obBatchCodes($batch))->toContain('ingest_incomplete')
        ->and($exit)->toBe(1);

    $message = collect($batch->findings)->firstWhere('code', 'ingest_incomplete')['message'];
    expect($message)->toContain('duplicate_row_key_in_file=1')
        ->and($message)->not->toContain('unattributed');
});

it('and the DATABASE refuses the same pair independently of the in-PHP pass (1062, not a guard clause)', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-CASE');
    obRun($ctx, obCsv(['ADM-CASE,W1,Tuition,100000.00,100000.00,BILL-1']), ['--control-total' => '100000.00']);

    $batch = obBatch($ctx);

    // Insert the case-variant DIRECTLY, bypassing normaliseLabel() entirely. Asserting the driver
    // code — not a message, not an exit code — is what proves the refusal is the index: a PHP guard
    // would raise something else, and a byte-comparing index would accept this row.
    ActiveSchool::runFor($ctx['school']->id, function () use ($batch) {
        try {
            OpeningBalanceRow::create([
                'batch_id' => $batch->id,
                'line_number' => 99,
                'admission_number' => 'ADM-CASE',
                'fee_type_label' => 'TUITION',
                'balance' => Money::fromKobo(1),
                'student_total_balance' => Money::fromKobo(1),
                'status' => OpeningBalanceRowStatus::Ok,
            ]);
            throw new RuntimeException('expected the unique index to refuse the case-variant fee type');
        } catch (QueryException $e) {
            expect((int) ($e->errorInfo[1] ?? 0))->toBe(1062);
        }
    });
});

it('converts an engine-refused duplicate into the same finding and CONTINUES the run', function () {
    // `utf8mb4_unicode_ci` folds accents; `normaliseLabel()` folds case. 'Tuición' and 'Tuicion' are
    // therefore ONE key at the index and TWO keys in PHP — the gap that used to abort the run at the
    // insert, leaving a committed batch whose own counters said it had staged nothing.
    $ctx = obSchool();
    obStudent($ctx, 'ADM-ACCENT');

    $exit = obRun($ctx, obCsv([
        'ADM-ACCENT,W1,Tuición,50000.00,100000.00,BILL-1',
        'ADM-ACCENT,W1,Tuicion,50000.00,100000.00,BILL-1',
    ]), ['--control-total' => '100000.00']);

    $batch = obBatch($ctx);
    $rows = obRows($ctx);

    // THE RUN COMPLETED. `draft` is the state an abort leaves behind, so asserting it is NOT draft
    // is the assertion that the catch did its job — a status and a row count were written at all.
    expect($batch->status)->toBe(OpeningBalanceBatchStatus::Rejected)
        ->and($batch->status)->not->toBe(OpeningBalanceBatchStatus::Draft)
        ->and($exit)->toBe(1);

    // The counters agree with what is actually in the table — the specific thing that was false
    // during the abort (row_count said 0 while a prefix of the file sat in rows).
    expect($rows)->toHaveCount(1)
        ->and($batch->row_count)->toBe(1)
        ->and($batch->file_row_count)->toBe(2)
        ->and($rows[2]->fee_type_label)->toBe('Tuición');

    // Same finding the in-PHP pass would have produced, and the accounting still balances.
    expect(obBatchCodes($batch))->toContain('duplicate_row_key_in_file')
        ->and(obBatchCodes($batch))->toContain('ingest_incomplete');

    $duplicate = collect($batch->findings)->firstWhere('code', 'duplicate_row_key_in_file')['message'];
    expect($duplicate)->toContain('1 row(s) repeat')
        ->and($duplicate)->toContain('0 caught while reading, 1 refused by the unique index');

    $ingest = collect($batch->findings)->firstWhere('code', 'ingest_incomplete')['message'];
    expect($ingest)->toContain('Read 2 data line(s) with content but staged 1')
        ->and($ingest)->toContain('duplicate_row_key_in_file=1')
        ->and($ingest)->not->toContain('unattributed');
});

it('re-throws a unique violation that is NOT the fee-type key, rather than reporting a duplicate that is not there', function () {
    // `finance_opening_balance_rows` carries TWO unique indexes, and both raise the same exception
    // class on the same insert. A uuid collision is a defect in THIS system's identifier generation;
    // reporting it as `duplicate_row_key_in_file` would send the operator to hunt for a repeated line
    // that does not exist in their file. So the catch matches the constraint by NAME and re-throws.
    $ctx = obSchool();
    obStudent($ctx, 'ADM-UUID');

    // Every generated uuid becomes the same one, so the SECOND row insert collides on
    // `finance_opening_balance_rows_uuid_unique` — a unique violation on a different door.
    Str::createUuidsUsing(fn () => '11111111-1111-1111-1111-111111111111');

    try {
        obRun($ctx, obCsv([
            'ADM-UUID,W1,Tuition,100000.00,145000.00,BILL-1',
            'ADM-UUID,W1,Bus,45000.00,145000.00,BILL-1',
        ]), ['--control-total' => '145000.00']);

        throw new RuntimeException('expected the uuid collision to abort the run, not to be converted');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1062)
            // The exception names the uuid index, NOT the fee-type key — which is exactly why
            // matching on the exception class alone would have mislabelled it.
            ->and($e->getMessage())->toContain('finance_opening_balance_rows_uuid_unique')
            ->and($e->getMessage())->not->toContain(ImportOpeningBalances::ROW_KEY_INDEX);
    } finally {
        Str::createUuidsNormally();
    }

    // And nothing pretended the file was at fault: no duplicate finding was recorded.
    expect(obBatchCodes(obBatch($ctx)))->not->toContain('duplicate_row_key_in_file');
});

it('names the FEE-TYPE key specifically, by its columns, not merely a name that resolves', function () {
    // The constant is a second copy of the migration's NEW_KEY (an anonymous class cannot be
    // referenced), so the drift it invites is closed here — read from information_schema, which is
    // neither of the two sources.
    //
    // ASSERTING THE COLUMNS, NOT JUST THE NAME. A name-only check proves the string resolves to some
    // index, which is not what the catch arm depends on: a migration that renames the fee-type key
    // and reuses the old name for a different unique index would pass a name-only check while the
    // arm quietly starts converting violations of the WRONG constraint into a finding about the
    // operator's file. The columns, in order, are the thing that identifies this key.
    $columns = collect(DB::select(
        'SELECT COLUMN_NAME AS c, NON_UNIQUE FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
         ORDER BY SEQ_IN_INDEX',
        ['finance_opening_balance_rows', ImportOpeningBalances::ROW_KEY_INDEX]
    ));

    expect($columns->count())->toBeGreaterThan(0, ImportOpeningBalances::ROW_KEY_INDEX.' is not an index on the table')
        ->and((int) $columns->first()->NON_UNIQUE)->toBe(0)
        ->and($columns->pluck('c')->all())->toBe([
            'school_id', 'batch_id', 'admission_number', 'fee_type_label',
        ]);
});

it('drops an over-length value, names the column, and CONTINUES the run', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-LONG');

    $exit = obRun($ctx, obCsv([
        'ADM-LONG,W1,Tuition,100000.00,100000.00,BILL-1',
        'ADM-LONG,W1,'.str_repeat('X', 300).',50000.00,100000.00,BILL-1',
    ]), ['--control-total' => '100000.00']);

    $batch = obBatch($ctx);

    expect($batch->status)->not->toBe(OpeningBalanceBatchStatus::Draft)
        ->and(obRows($ctx))->toHaveCount(1)
        ->and($batch->row_count)->toBe(1)
        ->and($batch->file_row_count)->toBe(2)
        ->and(obBatchCodes($batch))->toContain('value_too_long')
        ->and($exit)->toBe(1);

    // The column is named — "a value was too long" without saying which cell is not actionable.
    // The row is NOT staged-and-rejected, because there is no row shape that could hold it.
    $tooLong = collect($batch->findings)->firstWhere('code', 'value_too_long')['message'];
    expect($tooLong)->toContain('1 row(s) carry a value longer than its column can hold')
        ->and($tooLong)->toContain('Correct the file; nothing is truncated');

    $ingest = collect($batch->findings)->firstWhere('code', 'ingest_incomplete')['message'];
    expect($ingest)->toContain('value_too_long=1')
        ->and($ingest)->not->toContain('unattributed');
});

it('accepts a label at exactly the limit and REFUSES one character more — the boundary pair', function () {
    // THE LIMIT MOVED IN 4b, from 255 (this column's own width) to 229 (the ledger narration's width
    // minus the 26-character suffix posting appends). A 255-character label used to be pinned HERE as
    // accepted, and it was: it staged perfectly and then aborted the post at 1406. Nothing is
    // truncated — R7 carries the label VERBATIM onto a parent's statement — so the refusal moved to
    // the file, and both sides of the new boundary are pinned rather than just the accepting one.
    $limit = ImportOpeningBalances::COLUMNS['fee_type_label']['max'];
    expect($limit)->toBe(229);

    $accepted = obSchool();
    obStudent($accepted, 'ADM-EXACT');
    $acceptedExit = obRun($accepted, obCsv([
        'ADM-EXACT,W1,'.str_repeat('X', $limit).',100000.00,100000.00,BILL-1',
    ]), ['--control-total' => '100000.00']);

    expect(obRows($accepted))->toHaveCount(1)
        ->and(obBatch($accepted)->findings)->toBeNull()
        ->and($acceptedExit)->toBe(0);

    $refused = obSchool();
    obStudent($refused, 'ADM-OVER');
    $refusedExit = obRun($refused, obCsv([
        'ADM-OVER,W1,'.str_repeat('X', $limit + 1).',100000.00,100000.00,BILL-1',
    ]), ['--control-total' => '100000.00']);

    expect(obRows($refused))->toHaveCount(0)
        ->and(obBatchCodes(obBatch($refused)))->toContain('value_too_long')  // a NAMED finding, not a crash
        ->and($refusedExit)->toBe(1);
});

it('counts a multi-byte label by CHARACTERS, not bytes, so an accented label at the limit is accepted', function () {
    // 229 two-byte characters is 458 BYTES. `strlen` would drop this row; MySQL counts characters
    // in a utf8mb4 varchar and accepts it, so a byte count here would reject a legitimate label.
    $ctx = obSchool();
    obStudent($ctx, 'ADM-MB');

    $exit = obRun($ctx, obCsv([
        'ADM-MB,W1,'.str_repeat('é', ImportOpeningBalances::COLUMNS['fee_type_label']['max']).',100000.00,100000.00,BILL-1',
    ]), ['--control-total' => '100000.00']);

    expect(obRows($ctx))->toHaveCount(1)
        ->and(obBatch($ctx)->findings)->toBeNull()
        ->and($exit)->toBe(0);
});

it('accepts a batch reference at exactly the limit and REFUSES one character more, before staging anything', function () {
    // The reference had NO length rule: it comes from --batch-reference or the filename, its own
    // column holds 255, and it is snapshotted into a posted payment's varchar(255) payer_name behind a
    // 37-character prefix. The refusal is BEFORE the batch insert on purpose — a rejected reference
    // must not spend §7's idempotency key on a run nobody can read.
    $limit = ImportOpeningBalances::BATCH_REFERENCE_MAX;
    expect($limit)->toBe(218);

    $accepted = obSchool();
    obStudent($accepted, 'ADM-REF');
    $acceptedExit = obRun($accepted, obCsv([
        'ADM-REF,W1,Tuition,100000.00,100000.00,BILL-1',
    ]), ['--control-total' => '100000.00', '--batch-reference' => str_repeat('R', $limit)]);

    expect(obRows($accepted))->toHaveCount(1)
        ->and($acceptedExit)->toBe(0);

    $refused = obSchool();
    obStudent($refused, 'ADM-REF2');
    $refusedExit = test()->artisan('finance:import-opening-balances', [
        '--file' => obCsv(['ADM-REF2,W1,Tuition,100000.00,100000.00,BILL-1']),
        '--school' => (string) $refused['school']->id,
        '--closing-term' => (string) $refused['term']->id,
        '--as-at' => '2026-08-06',
        '--control-total' => '100000.00',
        '--batch-reference' => str_repeat('R', $limit + 1),
        '--dry-run' => true,
    ])->expectsOutputToContain('the limit is 218')->run();

    expect($refusedExit)->toBe(1)
        // NOTHING staged — not even the batch row, so the reference is still free for a corrected run.
        ->and(ActiveSchool::runFor($refused['school']->id, fn () => OpeningBalanceBatch::query()->count()))->toBe(0);
});

it('still stages two rows for a student when the fee types genuinely differ', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-TWO');

    $exit = obRun($ctx, obCsv([
        'ADM-TWO,W1,Tuition,100000.00,145000.00,BILL-1',
        'ADM-TWO,W1,Bus,45000.00,145000.00,BILL-1',
    ]), ['--control-total' => '145000.00']);

    expect(obRows($ctx))->toHaveCount(2)
        ->and(obBatch($ctx)->findings)->toBeNull()
        ->and(obBatch($ctx)->status)->toBe(OpeningBalanceBatchStatus::Validated)
        ->and($exit)->toBe(0);
});

// ── R12 — wcbs_bill_reference moved REQUIRED → OPTIONAL. This one used to fail. ──

it('accepts a row with a blank wcbs_bill_reference, and still rejects a blank REQUIRED column beside it', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-NOREF');
    obStudent($ctx, 'ADM-NOREF2');

    $exit = obRun($ctx, obCsv([
        'ADM-NOREF,W1,Tuition,100000.00,100000.00,',           // blank OPTIONAL column — accepted
        'ADM-NOREF2,W2,Tuition,50000.00,50000.00,',            // ditto
    ]), ['--control-total' => '150000.00']);

    $rows = obRows($ctx);
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and($rows[2]->wcbs_bill_reference)->toBeNull()
        ->and(obCodes($rows[2]))->not->toContain('blank_required_column')
        ->and($rows[3]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and($exit)->toBe(0);
});

// ── §2 — blank ≠ zero ──

it('rejects a blank required column but accepts a literal 0.00 as a real zero', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-ZERO');
    obStudent($ctx, 'ADM-BLANK');

    $exit = obRun($ctx, obCsv([
        'ADM-ZERO,W1,Tuition,0.00,0.00,BILL-1',
        'ADM-BLANK,W2,Tuition,,40000.00,BILL-2',
        // The join key itself blank — the most consequential blank required column, and the row
        // shape a reader would be most tempted to drop before it ever becomes a record.
        ',W3,Tuition,40000.00,40000.00,BILL-3',
    ]), ['--control-total' => '40000.00']);

    $rows = obRows($ctx);
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and($rows[2]->balance->toKobo())->toBe(0)        // a stated zero is a value, not an absence
        ->and(obCodes($rows[2]))->toContain('nothing_to_post')
        ->and($rows[3]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[3]))->toContain('blank_required_column')
        ->and($rows[3]->balance)->toBeNull()               // never coerced to zero
        // Blank join key: STAGED and rejected, never dropped. A dropped row is one nobody can see.
        ->and($rows[4]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[4]))->toContain('blank_required_column')
        ->and($rows[4]->admission_number)->toBeNull()
        ->and($exit)->toBe(1);
});

it('rejects a blank fee_type_label rather than staging an unnamed fee type', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-NOLABEL');

    $exit = obRun($ctx, obCsv([
        'ADM-NOLABEL,W1,,100000.00,100000.00,BILL-1',
    ]), ['--control-total' => '100000.00']);

    $rows = obRows($ctx);
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[2]))->toContain('blank_required_column')
        ->and($rows[2]->fee_type_label)->toBe('')
        ->and($exit)->toBe(1);
});

// ── R8 — `balance` is SIGNED. The retired non-negative rule would have rejected this row. ──

it('accepts a NEGATIVE balance as a credit, and nets it into the student\'s stated total', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-CREDIT');

    // A scholarship line in credit against a tuition line owed: 100,000 − 30,000 = 70,000.
    $exit = obRun($ctx, obCsv([
        'ADM-CREDIT,W1,Tuition,100000.00,70000.00,BILL-1',
        'ADM-CREDIT,W1,Scholarship,-30000.00,70000.00,BILL-1',
    ]), ['--control-total' => '70000.00']);

    $rows = obRows($ctx);
    expect($rows[3]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and($rows[3]->balance->toKobo())->toBe(-3000000)
        ->and(obCodes($rows[3]))->not->toContain('negative_amount')   // the rule is GONE, not relaxed
        ->and($exit)->toBe(0);
});

it('accepts a student who is wholly in credit, including a negative stated total', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-ALLCREDIT');

    $exit = obRun($ctx, obCsv([
        'ADM-ALLCREDIT,W1,Tuition,-50000.00,-50000.00,BILL-1',
    ]), ['--control-total' => '-50000.00']);

    $rows = obRows($ctx);
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and($rows[2]->balance->toKobo())->toBe(-5000000)
        ->and($rows[2]->student_total_balance->toKobo())->toBe(-5000000)
        ->and($exit)->toBe(0);
});

// ── §2 — naira→kobo at the boundary, by integer string arithmetic ──

it('parses naira to exact kobo, including a value that loses a kobo through float round-tripping', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-FLOAT');

    // The counter-example is CHECKED, not assumed. 8.07 does NOT break on this PHP — the product
    // rounds up to exactly 807.0 — so a test built on it would pass no matter how the parser worked.
    // 80000.15 does break: the double is a hair under 8000015 and the cast truncates a kobo away.
    expect((int) ((float) '80000.15' * 100))->toBe(8000014);

    obRun($ctx, obCsv([
        'ADM-FLOAT,W1,Tuition,80000.15,80000.15,BILL-1',
    ]), ['--control-total' => '80000.15']);

    $rows = obRows($ctx);
    expect($rows[2]->balance->toKobo())->toBe(8000015)   // not 8000014
        ->and($rows[2]->status)->toBe(OpeningBalanceRowStatus::Ok);
});

it('rejects an unparseable amount rather than coercing it', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-JUNK');

    $exit = obRun($ctx, obCsv([
        'ADM-JUNK,W1,Tuition,100000.005,100000.00,BILL-1',   // three decimals — no rounding is permitted
    ]), ['--control-total' => '100000.00']);

    $rows = obRows($ctx);
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[2]))->toContain('unparseable_amount')
        ->and($rows[2]->balance)->toBeNull()
        ->and($exit)->toBe(1);
});

// ── §6/§7 — the join key ──

it('counts a file row matching no student, still stages the batch, and exits non-zero', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-REAL');

    $exit = obRun($ctx, obCsv([
        'ADM-REAL,W1,Tuition,100000.00,100000.00,BILL-1',
        'ADM-GHOST,W2,Tuition,50000.00,50000.00,BILL-2',
    ]), ['--control-total' => '150000.00']);

    $rows = obRows($ctx);
    $batch = obBatch($ctx);

    expect($rows[3]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[3]))->toContain('student_not_found')
        ->and($rows[3]->student_id)->toBeNull()
        ->and($batch->row_count)->toBe(2)          // the run completed and staged everything
        ->and($exit)->toBe(1);

    // A student is NEVER created from a finance import (§7).
    expect(ActiveSchool::runFor($ctx['school']->id, fn () => Student::query()->count()))->toBe(1);
});

it('raises a BATCH-level finding when the School has admission numbers that collide after trimming', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-DUP');
    obStudent($ctx, ' ADM-DUP');   // distinct at the unique index, identical after trim

    $exit = obRun($ctx, obCsv([
        'ADM-OTHER,W1,Tuition,0.00,0.00,BILL-1',
    ]), ['--control-total' => '0.00']);

    $batch = obBatch($ctx);
    expect(obBatchCodes($batch))->toContain('school_has_duplicate_admission_numbers')
        ->and($batch->status)->toBe(OpeningBalanceBatchStatus::Rejected)
        ->and($exit)->toBe(1);
});

// ── §7 — a student with NO ACTIVE ENROLLMENT still stages. Do not re-add an enrollment check. ──

it('accepts a student who has no enrollment at all, because the cutover exists to carry exactly those debtors', function () {
    $ctx = obSchool();
    // Deliberately NOT obStudent(): no curriculum, no StudentCurriculum, no episode of any kind.
    ActiveSchool::runFor($ctx['school']->id, fn () => Student::factory()->create([
        'school_id' => $ctx['school']->id,
        'admission_number' => 'ADM-NOENROL',
    ]));

    $exit = obRun($ctx, obCsv([
        'ADM-NOENROL,W1,Tuition,120000.00,120000.00,BILL-1',
    ]), ['--control-total' => '120000.00']);

    $rows = obRows($ctx);
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and(obCodes($rows[2]))->toBe([])
        ->and($rows[2]->student_id)->not->toBeNull()
        ->and($exit)->toBe(0);
});

// ── §7 — idempotency, at the DATABASE ──

it('refuses a re-run of the same batch_reference at the unique index, not in PHP', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-1');
    $csv = obCsv(['ADM-1,W1,Tuition,0.00,0.00,BILL-1']);

    expect(obRun($ctx, $csv, ['--batch-reference' => 'WCBS-2026-T1']))->toBe(0);

    // The SECOND run must die at the engine. Asserting the driver code — not a message, not an
    // exit code — is what proves the refusal is the index and not a guard clause someone can
    // delete: a PHP guard would raise a BusinessRuleException or return FAILURE, never 1062.
    try {
        obRun($ctx, $csv, ['--batch-reference' => 'WCBS-2026-T1']);
        throw new RuntimeException('expected the unique index to refuse the second run');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1062);
    }

    expect(ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceBatch::query()->count()))->toBe(1);
});

// ── The scope boundary of this commit ──

it('refuses to run without --dry-run, names the approval, and writes nothing — this door is closed by design', function () {
    // 4c BUILT the gate and this refusal STILL stands: posting is what an approval does, so the console
    // never gets a `--post` flag. The assertion moved with the message — it used to demand the words
    // "the approval gate is §9 step 4c", which after 4c would have been asserting that the feature is
    // still unbuilt. What must hold now is that the refusal points at the approval, not at a milestone.
    $ctx = obSchool();
    obStudent($ctx, 'ADM-1');

    $exit = test()->artisan('finance:import-opening-balances', [
        '--file' => obCsv(['ADM-1,W1,Tuition,0.00,0.00,BILL-1']),
        '--school' => (string) $ctx['school']->id,
        '--closing-term' => (string) $ctx['term']->id,
        '--as-at' => '2026-08-06',
        '--control-total' => '0.00',
    ])->expectsOutputToContain('ONLY when a second user approves it')->run();

    expect($exit)->toBe(1)
        ->and(ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceBatch::query()->count()))->toBe(0)
        ->and(ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceRow::query()->count()))->toBe(0);
});

// ── Isolation, asserted rather than inherited ──

it('never resolves a row against a student belonging to another School', function () {
    $ctx = obSchool();
    $other = obSchool();
    obStudent($other, 'ADM-ELSEWHERE');   // the SAME admission number exists — in the wrong School

    $exit = obRun($ctx, obCsv([
        'ADM-ELSEWHERE,W1,Tuition,100000.00,100000.00,BILL-1',
    ]), ['--control-total' => '100000.00']);

    $rows = obRows($ctx);
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[2]))->toContain('student_not_found')
        ->and($rows[2]->student_id)->toBeNull()
        ->and($rows[2]->school_id)->toBe($ctx['school']->id)
        ->and($exit)->toBe(1);

    // And the other School saw nothing of this run.
    expect(ActiveSchool::runFor($other['school']->id, fn () => OpeningBalanceRow::query()->count()))->toBe(0);
});

// ── The clean file, end to end ──

it('validates a clean multi-student, multi-fee-type file with exit 0 and no finding anywhere', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-A');
    obStudent($ctx, 'ADM-B');

    $exit = obRun($ctx, obCsv([
        'ADM-A,W1,Tuition,100000.00,145000.00,BILL-1',
        'ADM-A,W1,Bus,45000.00,145000.00,BILL-1',
        'ADM-B,W2,Tuition,60000.00,55000.00,',
        'ADM-B,W2,Scholarship,-5000.00,55000.00,',
    ]), ['--control-total' => '200000.00']);

    $batch = obBatch($ctx);
    $rows = obRows($ctx);

    expect($exit)->toBe(0)
        ->and($batch->status)->toBe(OpeningBalanceBatchStatus::Validated)
        ->and($batch->row_count)->toBe(4)
        ->and($batch->file_row_count)->toBe(4)
        ->and($batch->findings)->toBeNull()
        ->and($rows[2]->fee_type_label)->toBe('Tuition')      // carried VERBATIM (R7)
        ->and($rows[5]->balance->toKobo())->toBe(-500000)
        ->and($rows[5]->student_total_balance->toKobo())->toBe(5500000);
});

// ── Ingest completeness — read vs staged ──

it('leaves file_row_count equal to row_count with no ingest finding on a clean file', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-A');

    $exit = obRun($ctx, obCsv([
        'ADM-A,W1,Tuition,100000.00,100000.00,BILL-1',
    ]), ['--control-total' => '100000.00']);

    $batch = obBatch($ctx);
    expect($batch->file_row_count)->toBe(1)
        ->and($batch->row_count)->toBe(1)
        ->and($batch->findings)->toBeNull()
        ->and($exit)->toBe(0);
});

it('excludes wholly blank lines from file_row_count without raising an ingest finding', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-A');
    obStudent($ctx, 'ADM-B');

    // Two content lines with a blank between them and a blank at the end. `obCsv` appends its own
    // trailing newline, so the file's physical data lines are: content, blank, content, blank.
    $csv = obCsv([
        'ADM-A,W1,Tuition,100000.00,100000.00,BILL-1',
        '',
        'ADM-B,W2,Tuition,50000.00,50000.00,BILL-2',
        '',
    ]);

    // Driven directly rather than through obRun() so the console assertion can ride along: the
    // blank count is NOT persisted, so the operator report is the only place it is observable, and
    // it is what makes content + blank reconcile to the physical file by eye.
    $exit = test()->artisan('finance:import-opening-balances', [
        '--file' => $csv,
        '--school' => (string) $ctx['school']->id,
        '--closing-term' => (string) $ctx['term']->id,
        '--as-at' => '2026-08-06',
        '--control-total' => '150000.00',
        '--batch-reference' => 'BLANKS-'.Str::random(6),
        '--dry-run' => true,
    ])->expectsOutputToContain('blank lines skipped')->run();

    // A blank line is not a row, so it must not read as a missing one. `ingest_incomplete` firing
    // on a trailing newline would be a false positive on every real extract, and a control that
    // cries wolf on every file is one an operator learns to scroll past.
    $batch = obBatch($ctx);
    expect($batch->file_row_count)->toBe(2)     // content lines, not physical lines
        ->and($batch->row_count)->toBe(2)
        ->and($batch->findings)->toBeNull()
        ->and($batch->status)->toBe(OpeningBalanceBatchStatus::Validated)
        ->and($exit)->toBe(0);
});

it('aborts before writing a batch when a required column is missing from the header', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-A');

    $path = tempnam(sys_get_temp_dir(), 'ob').'.csv';
    // No `student_total_balance` — the L1 witness. A file without it cannot be checked at all.
    file_put_contents($path, "admission_number,wcbs_student_ref,fee_type_label,balance,wcbs_bill_reference\nADM-A,W1,Tuition,100000.00,BILL-1\n");

    $exit = test()->artisan('finance:import-opening-balances', [
        '--file' => $path,
        '--school' => (string) $ctx['school']->id,
        '--closing-term' => (string) $ctx['term']->id,
        '--as-at' => '2026-08-06',
        '--control-total' => '100000.00',
        '--dry-run' => true,
    ])->expectsOutputToContain('Missing required column(s): student_total_balance')->run();

    expect($exit)->toBe(1)
        ->and(ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceBatch::query()->count()))->toBe(0);
});

// ── The format map is the single source of truth (R13) ──

it('freezes R12\'s six columns in the COLUMNS map, with wcbs_bill_reference the only optional one', function () {
    // The template the platform issues (R13, step 5) renders THIS constant, so a column added here
    // reaches the data team's spreadsheet and the validator together or not at all.
    expect(array_keys(ImportOpeningBalances::COLUMNS))->toBe([
        'admission_number',
        'wcbs_student_ref',
        'fee_type_label',
        'balance',
        'student_total_balance',
        'wcbs_bill_reference',
    ]);

    $optional = array_keys(array_filter(
        ImportOpeningBalances::COLUMNS,
        fn (array $spec) => ! $spec['required'],
    ));
    expect($optional)->toBe(['wcbs_bill_reference']);

    // Every entry carries what a template needs — a map missing `notes` renders a template whose
    // rules the person filling it in never sees.
    foreach (ImportOpeningBalances::COLUMNS as $column => $spec) {
        expect(array_keys($spec))->toBe(['required', 'max', 'format', 'example', 'notes', 'group'], $column)
            // Lengths, not `->not->toBe('', $column)`: the column name is the whole diagnostic here
            // and `->not->` throws it away. The length puts the 0 in the output as well.
            ->and(mb_strlen($spec['notes']))->toBeGreaterThan(0, $column)
            ->and(mb_strlen($spec['example']))->toBeGreaterThan(0, $column)
            // The limit is held as a number AND stated in `format`, because `format` is what the
            // data team reads. Two copies of one fact drift, so the agreement is asserted rather
            // than trusted: change one and this goes red.
            ->and(str_contains($spec['format'], 'max '.$spec['max'].' characters'))
            ->toBeTrue("COLUMNS[{$column}].format must state its own max ({$spec['max']})");
    }
});

it('holds a max for every column that matches the storage column it lands in', function () {
    // Read from information_schema, not from the migration source: the rule exists to keep a value
    // out of a column it does not fit, so the number has to be the column's, not a remembered one.
    $lengths = collect(DB::select(
        "SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_opening_balance_rows'"
    ))->pluck('len', 'COLUMN_NAME');

    foreach (['admission_number', 'wcbs_student_ref', 'wcbs_bill_reference'] as $column) {
        expect(ImportOpeningBalances::COLUMNS[$column]['max'])
            ->toBe((int) $lengths[$column], "COLUMNS[{$column}].max must equal the column's own limit");
    }

    // `fee_type_label` IS THE ONE EXCEPTION, and it is stricter than the rule above rather than an
    // escape from it. Its own staging column holds 255, and it fits — but §9 step 4b posts the label
    // VERBATIM into a varchar(255) ledger narration with a 26-character suffix appended (R7: the label
    // is what a parent reads, so it is never truncated). A label sized to its OWN column therefore
    // stages green and aborts the post at 1406. The binding limit is the downstream one, and both
    // halves are asserted: it still fits its own column, AND it leaves room for the suffix.
    expect(ImportOpeningBalances::COLUMNS['fee_type_label']['max'])
        ->toBeLessThanOrEqual((int) $lengths['fee_type_label'])
        ->and(ImportOpeningBalances::COLUMNS['fee_type_label']['max'])
        ->toBe(PostOpeningBalanceBatch::SNAPSHOT_COLUMN_MAX - mb_strlen(PostOpeningBalanceBatch::NARRATION_SUFFIX));

    // The two money cells land in bigint, so they inherit no varchar limit; theirs is the widest
    // naira figure a signed bigint holds in kobo. Pinned so it cannot be quietly widened past it.
    expect(ImportOpeningBalances::COLUMNS['balance']['max'])->toBe(21)
        ->and(ImportOpeningBalances::COLUMNS['student_total_balance']['max'])->toBe(21)
        ->and(strlen('92233720368547758.07') + 1)->toBe(21);
});

// ── The ACL port extension, exercised through its own consumer ──

it('resolves the enrollment term and class level through the port, one hop each', function () {
    // NOTE: with §5 withdrawn, `termId` / `classLevelId` have NO production caller until
    // normal-course bulk billing lands — this test is the only thing exercising them, and it is
    // kept deliberately rather than deleted with the comparison that used to consume them.
    $ctx = obSchool();
    $student = obStudent($ctx, 'ADM-PORT');

    $enrollment = ActiveSchool::runFor($ctx['school']->id,
        fn () => app(BillableEnrollmentProvider::class)->currentForStudent($student->id));

    expect($enrollment)->not->toBeNull()
        ->and($enrollment->termId)->toBe($ctx['term']->id)
        ->and($enrollment->classLevelId)->toBe($ctx['level']->id);
});
