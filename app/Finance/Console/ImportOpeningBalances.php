<?php

namespace App\Finance\Console;

use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Models\School;
use App\Support\ActiveSchool;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

/**
 * §9 step 4a — the READ-ONLY validator for a WCBS opening-balance extract, realigned onto R5's
 * balance-forward file (docs/handoff/opening-balance-import-spec.md Rev 4).
 *
 * IT POSTS NOTHING. No ledger transaction, no payment, no invoice, no account-balance movement. It
 * parses the file, enforces §1's two-level checksum, applies §2's and §7's rejection rules, resolves
 * the join key against the School's roster, and stages all of it in finance_opening_balance_batches /
 * _rows for a human to look at. `--dry-run` is the only mode that exists; without it the command
 * refuses and exits non-zero rather than stubbing a posting path. Posting is 4b and the approval gate
 * is 4c.
 *
 * WHAT 4a CHANGED, because a reader who knows the old shape will otherwise look for it:
 *
 *  - THE FILE IS ONE ROW PER (STUDENT × FEE TYPE). `fee_type_label` and a SIGNED per-fee-type
 *    `balance` replace the four Rev 2/3 money columns, which R5 withdrew — that file will never be
 *    produced.
 *  - §1'S IDENTITY IS GONE and two levels replace it. L1 (Σ of a student's balances == that
 *    student's stated total) rejects the STUDENT'S WHOLE ROW-GROUP; L2 (Σ of the stated totals ==
 *    the operator's control total) is a finding on the BATCH and rejects no row.
 *  - §5's COMPARISON IS WITHDRAWN. `expected_billed`, `comparison_mismatch` and all three
 *    `not_comparable` reasons are gone, and so is `OpeningBalanceRowStatus::NotComparable`. They lost
 *    their SUBJECT, not merely their input: under R6 the import touches no episode at all.
 *    Consequence, stated rather than left to be found: `BillableEnrollment::termId` /
 *    `classLevelId` now have no production caller until normal-course bulk billing lands.
 *  - `wcbs_bill_reference` IS OPTIONAL. A blank must not reject the row (R12).
 *  - THERE IS NO NON-NEGATIVE RULE. `balance` is signed by design — positive owed, negative credit —
 *    and a non-negative rule pointed at it would reject every student who is in credit.
 *
 * THE COLUMNS MAP IS THE SINGLE SOURCE OF TRUTH FOR THE FORMAT, and it is deliberately the guardian
 * import's shape, whose own docblock states the reason better than a new argument would: "the COLUMNS
 * map drives both the template generator and the row validator, so they cannot drift apart"
 * (app/Services/Validators/GuardianImportRowValidator.php:15-19). The template the platform issues
 * (R13, step 5) renders THIS constant; a hand-authored template beside it would be a second source of
 * truth for a money format, which is how a data team ends up holding two files that both look right.
 *
 * WHY IT LIVES IN App\Finance\Console AND NOT app/Console/Commands. It touches Finance models, and
 * tests/Arch/ArchitectureBoundaryTest.php keeps those private to the module; bin/ci-boundary-lint.php
 * separately forbids a `finance_*` table literal outside app/Finance/. bootstrap/app.php:37-40 already
 * records this and registers the module's other two commands explicitly, because auto-discovery only
 * scans app/Console/Commands.
 *
 * STUDENTS ARE RESOLVED THROUGH THE ACL PORT, not by reading Academics tables (arch rule 3 — and from
 * inside App\Finance there is no other lawful option). `admissionNumberIndex()` is an exact roster;
 * the port's other two methods cannot serve as a join (`displayFor()` runs the wrong direction,
 * `matchingStudentIds()` is a LIKE search that would resolve "A1" onto "A100").
 *
 * OUTPUT IS AN OPERATOR SURFACE AND IT LEAVES THE BOX. Counts, line numbers and admission numbers
 * only — never a name, never a student's figures. The figures that matter (both sides of a failed
 * L1, both sides of a failed L2) are recorded in the staged row's / batch's `findings` JSON, which is
 * where U12b will read them from.
 */
class ImportOpeningBalances extends Command
{
    protected $signature = 'finance:import-opening-balances
        {--file= : path to the WCBS CSV}
        {--school= : the School to import into (numeric id or slug)}
        {--term= : the cutover term T (terms.id)}
        {--as-at= : the cutover date D (Y-m-d)}
        {--control-total= : §1 L2 — Σ of every student stated total, read off WCBS and typed here}
        {--batch-reference= : §7 idempotency key; defaults to the CSV filename}
        {--dry-run : the ONLY mode this commit implements}';

    protected $description = 'READ-ONLY: validate a WCBS opening-balance extract into staging and report (§9 step 4a — posts nothing)';

    /**
     * THE FILE FORMAT (§2, frozen by R12) — required flag, format, example, notes and group per
     * column, in the guardian import's shape and for its reason: one constant drives both the
     * template the platform issues (R13) and this validator, so they cannot drift apart.
     *
     * `notes` carries the OPERATOR-FACING rule, not a note to a developer, because `notes` is the
     * column the data team actually reads. A rule that lives only in the spec is a rule the person
     * filling in the sheet never sees.
     *
     * R12's columns and NOTHING ELSE. In particular there is no `last_payment_date` here: it is not
     * in R5's file, so this validator no longer reads it (the staging column survives, unwritten,
     * until a diff scoped to retire it).
     *
     * @var array<string, array{required: bool, format: string, example: string, notes: string, group: string}>
     */
    public const COLUMNS = [
        // Linking
        'admission_number' => [
            'required' => true,
            'format' => 'string',
            'example' => 'STU2025001',
            'notes' => 'The join key. Must already exist in this School — a student is NEVER created from a finance import.',
            'group' => 'Linking',
        ],
        'wcbs_student_ref' => [
            'required' => true,
            'format' => 'string',
            'example' => 'WCBS-10233',
            'notes' => "WCBS's own id, stored for traceability. Never used to join.",
            'group' => 'Linking',
        ],
        'fee_type_label' => [
            'required' => true,
            'format' => 'string',
            'example' => 'Tuition',
            'notes' => 'The fee type as WCBS names it, carried verbatim onto the statement. One row per student PER FEE TYPE. Spelling is matched case-insensitively, so "Tuition" and "tuition" are the same fee type and two rows for it are refused.',
            'group' => 'Amounts',
        ],
        'balance' => [
            'required' => true,
            'format' => 'naira with two decimal places, SIGNED (120000.00 / -5000.00)',
            'example' => '120000.00',
            'notes' => 'That fee type\'s closing balance for that student. POSITIVE is owed, NEGATIVE is credit. Blank is not zero — write 0.00 if the balance really is nil.',
            'group' => 'Amounts',
        ],
        'student_total_balance' => [
            'required' => true,
            'format' => 'naira with two decimal places, SIGNED',
            'example' => '145000.00',
            'notes' => "The student's total across ALL their fee types. Write the SAME figure on every one of that student's rows — it is the independent check that no line of theirs went missing.",
            'group' => 'Amounts',
        ],
        'wcbs_bill_reference' => [
            'required' => false,
            'format' => 'string',
            'example' => 'BILL-2026-0912',
            'notes' => 'OPTIONAL. The reference on the last paper bill, if WCBS carries one. A blank here does NOT reject the row.',
            'group' => 'Provenance',
        ],
    ];

    /** How many identifiers a list prints before it is cut. Truncation is always announced. */
    private const LIST_LIMIT = 50;

    public function handle(BillableEnrollmentProvider $enrollments): int
    {
        // The refusal comes FIRST, before any option is even read: there is no posting path to
        // reach, and a run that got as far as opening a file before refusing would suggest there is.
        if (! $this->option('dry-run')) {
            $this->error('Posting is not implemented in this commit. Re-run with --dry-run.');

            return self::FAILURE;
        }

        $file = (string) $this->option('file');
        $asAt = (string) $this->option('as-at');

        if ($file === '' || ! is_readable($file)) {
            $this->error("Unreadable --file [{$file}].");

            return self::FAILURE;
        }

        $school = $this->resolveSchool((string) $this->option('school'));
        if ($school === null) {
            return self::FAILURE;
        }

        $cutoverDate = $this->parseDate($asAt);
        if ($cutoverDate === null) {
            $this->error("Invalid --as-at [{$asAt}]: expected Y-m-d.");

            return self::FAILURE;
        }

        $controlTotal = $this->resolveControlTotal((string) $this->option('control-total'));
        if ($controlTotal === null) {
            return self::FAILURE;
        }

        // §5.4 / Constitution 13: off-request context is ActiveSchool::runFor and nothing else.
        // Every model touched below (the staging tables, the port's roster) is School-scoped, so the
        // scope — not a where() someone can forget — is what isolates the run.
        return ActiveSchool::runFor($school->id, function () use ($school, $file, $cutoverDate, $controlTotal, $enrollments): int {
            $termId = $this->resolveTerm((string) $this->option('term'), $school->id);
            if ($termId === null) {
                return self::FAILURE;
            }

            try {
                ['records' => $records, 'blankLines' => $blankLines] = $this->readCsv($file);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $reference = (string) ($this->option('batch-reference') ?: basename($file));

            // Inserted BEFORE a single row is validated, so §7's idempotency key is enforced by the
            // unique index at the engine rather than by a guard clause. A re-run of the same
            // reference throws 1062 here and never reaches the parse.
            $batch = OpeningBalanceBatch::create([
                'batch_reference' => $reference,
                'filename' => basename($file),
                'status' => OpeningBalanceBatchStatus::Draft,
                'cutover_date' => $cutoverDate,
                'term_id' => $termId,
                'uploaded_by_user_id' => null, // a console run has no authenticated causer
            ]);

            return $this->validateInto($batch, $records, $blankLines, $controlTotal, $enrollments);
        });
    }

    /**
     * The whole validation pass, inside the School context. Returns the process exit code.
     *
     * THREE PHASES, and the order is the point. Rows are parsed first and held in memory; L1 is a
     * check on a STUDENT'S ROW-GROUP, so it cannot be decided while streaming one row at a time, and
     * a row cannot be written before its group's verdict is known. Only then is anything inserted.
     *
     * @param  list<array{line: int, values: array<string, string>}>  $records
     * @param  int  $blankLines  wholly blank physical lines the reader dropped — carried through so
     *                           read + blank reconciles to the physical file on the operator report
     */
    private function validateInto(
        OpeningBalanceBatch $batch,
        array $records,
        int $blankLines,
        Money $controlTotal,
        BillableEnrollmentProvider $enrollments,
    ): int {
        $roster = $enrollments->admissionNumberIndex();

        // The join key, built once. Keyed by the TRIMMED admission number; the value is the list of
        // student ids that trim onto it, so an ambiguous key rejects rather than picking one.
        $byAdmission = [];
        $nullAdmissions = 0;
        foreach ($roster as $entry) {
            if ($entry['admission_number'] === null || trim($entry['admission_number']) === '') {
                $nullAdmissions++;

                continue;
            }
            $byAdmission[trim($entry['admission_number'])][] = $entry['student_id'];
        }
        $duplicateAfterTrim = count(array_filter($byAdmission, fn (array $ids) => count($ids) > 1));

        $batchFindings = [];
        // §6.1: either of these means the join key itself is unsafe, so it is a finding on the
        // BATCH — no amount of row-level cleanliness rescues it. `students.admission_number` has been
        // NOT NULL since 2026_07_18_100000_make_identifier_columns_not_null.php:36, so the first
        // count can only be non-zero if that is ever relaxed; it is computed rather than assumed away.
        if ($nullAdmissions > 0) {
            $batchFindings[] = $this->finding('school_has_null_admission_numbers',
                "{$nullAdmissions} student(s) in this School have no admission number — the join key is unsafe.");
        }
        if ($duplicateAfterTrim > 0) {
            $batchFindings[] = $this->finding('school_has_duplicate_admission_numbers',
                "{$duplicateAfterTrim} admission number(s) in this School are duplicated after trimming — the join key is unsafe.");
        }

        $fileRowCount = 0;  // data lines WITH CONTENT — incremented before any skip in this loop
        $skipReasons = [];  // reason → count, for the ingest-completeness breakdown
        $unresolved = [];
        $seenInFile = [];        // trimmed admission → normalised label → first line staged
        $duplicateInFile = [];   // lines dropped because their (student, fee type) key was already seen
        $matchedStudentIds = [];
        $staged = [];            // parsed rows awaiting their group's L1 verdict

        // ── PHASE 1 — per-row parsing and the row-level rules. Nothing is written yet. ──
        foreach ($records as $record) {
            // FIRST statement in the body, before every `continue` below and before any added
            // later. It is never conditioned on a row being valid, resolvable or parseable — the
            // moment it is, it stops being able to detect that a row went missing.
            //
            // It counts the reader's records, so it is "every data line CARRYING CONTENT", not
            // every physical line: readCsv() drops wholly blank lines one frame up, deliberately
            // and by an invariant that refuses to let a second drop path join it. The blank count
            // comes through with the records so the operator report reconciles to the physical file.
            $fileRowCount++;

            $line = $record['line'];
            $values = $record['values'];
            $findings = [];

            $rawAdmission = $values['admission_number'] ?? '';
            $key = trim($rawAdmission);
            $rawLabel = $values['fee_type_label'] ?? '';
            $labelKey = $this->normaliseLabel($rawLabel);

            // A repeat of the same (student, fee type) inside ONE file would collide on
            // unique(school_id, batch_id, admission_number, fee_type_label) at the insert and abort
            // the run. The first occurrence is staged; the rest are reported as a batch finding
            // naming their lines. Nothing is dropped silently — the count and the lines are printed.
            // Guarded on a non-blank admission because NULL admission numbers are exempt from the
            // index (MySQL), so two blank-key rows do not collide and must not be dropped either.
            if ($key !== '' && isset($seenInFile[$key][$labelKey])) {
                $duplicateInFile[] = [
                    'line' => $line,
                    'admission_number' => $key,
                    'fee_type_label' => $rawLabel,
                    'first' => $seenInFile[$key][$labelKey],
                ];
                // Every skip must register a reason here. An unregistered one still shows up —
                // as `unattributed` in the ingest-completeness finding — which is the point.
                $skipReasons['duplicate_row_key_in_file'] = ($skipReasons['duplicate_row_key_in_file'] ?? 0) + 1;

                continue;
            }

            // ── §2: required columns, read from the COLUMNS map. Blank ≠ zero; reject, never
            // coerce. `wcbs_bill_reference` is NOT among them — R12 made it optional. ──
            foreach (self::COLUMNS as $column => $spec) {
                if ($spec['required'] && trim($values[$column] ?? '') === '') {
                    $findings[] = $this->finding('blank_required_column', "Column [{$column}] is blank; a blank is not a zero.");
                }
            }

            // ── Amounts: naira-with-2dp → integer kobo, by integer string arithmetic. ──
            // Money::fromNaira parses the digits itself (Money.php:74-77); no float multiplication
            // is involved, so a value like "80000.15" — which (int) ((float) '80000.15' * 100)
            // reads as 8000014 — parses to exactly 8000015. BOTH are signed: a leading '-' is
            // legitimate on either, and there is no non-negative rule anywhere near them.
            $amounts = [];
            foreach (['balance', 'student_total_balance'] as $column) {
                $raw = trim($values[$column] ?? '');
                if ($raw === '') {
                    $amounts[$column] = null;

                    continue;
                }
                try {
                    $amounts[$column] = Money::fromNaira($raw);
                } catch (InvalidArgumentException) {
                    $amounts[$column] = null;
                    $findings[] = $this->finding('unparseable_amount',
                        "Column [{$column}] value [{$raw}] is not naira with up to two decimal places.");
                }
            }

            // ── §6: resolve the join key, School-scoped by the port. ──
            $studentId = null;
            if ($key === '') {
                // already reported as a blank required column
            } elseif (! isset($byAdmission[$key])) {
                $findings[] = $this->finding('student_not_found',
                    "No student in this School has admission number [{$key}]. A student is never created from a finance import.");
                $unresolved[] = ['line' => $line, 'admission_number' => $key];
            } elseif (count($byAdmission[$key]) > 1) {
                $findings[] = $this->finding('ambiguous_admission_number',
                    "Admission number [{$key}] matches ".count($byAdmission[$key]).' students after trimming.');
            } else {
                $studentId = $byAdmission[$key][0];
                $matchedStudentIds[$studentId] = true;
            }

            $staged[] = [
                'line' => $line,
                'admission_raw' => $rawAdmission,
                'key' => $key,
                'label_raw' => $rawLabel,
                'wcbs_student_ref' => $values['wcbs_student_ref'] ?? '',
                'wcbs_bill_reference' => $values['wcbs_bill_reference'] ?? '',
                'balance' => $amounts['balance'],
                'student_total_balance' => $amounts['student_total_balance'],
                'student_id' => $studentId,
                'findings' => $findings,
            ];

            if ($key !== '') {
                $seenInFile[$key][$labelKey] = $line;
            }
        }

        // ── PHASE 2 — §1's L1, per student row-group. ──
        $groups = [];
        foreach ($staged as $index => $row) {
            if ($row['key'] !== '') {
                $groups[$row['key']][] = $index;
            }
        }

        $l1Failures = [];   // admission number → the reason code, for the operator report
        foreach ($groups as $admission => $indexes) {
            $codeAndMessage = $this->l1Verdict($staged, $indexes);
            if ($codeAndMessage === null) {
                continue;
            }

            // The WHOLE row-group is rejected, not the row that happens to carry the arithmetic:
            // posting three of a student's four lines is worse than posting none (§7).
            foreach ($indexes as $index) {
                $staged[$index]['findings'][] = $codeAndMessage;
            }
            $l1Failures[] = ['admission_number' => (string) $admission, 'code' => $codeAndMessage['code']];
        }

        // ── PHASE 3 — write the rows, now that every group's verdict is known. ──
        $rowCount = 0;
        $rejected = [];
        foreach ($staged as $row) {
            $isRejected = $row['findings'] !== [];
            $findings = $row['findings'];

            // §7: a single line whose balance is zero has no movement to post. Recorded AFTER the
            // status is decided, because it is information for 4b and not a defect — the line still
            // stages and still counts toward L1.
            if (! $isRejected && $row['balance'] !== null && $row['balance']->isZero()) {
                $findings[] = $this->finding('nothing_to_post', 'This line\'s balance is zero; the posting commit will skip it.');
            }

            OpeningBalanceRow::create([
                'batch_id' => $batch->id,
                'line_number' => $row['line'],
                // Stored EXACTLY as it appeared — the trim happens in the comparison, never in
                // what is kept, so a whitespace defect stays visible to the operator. Same for the
                // fee-type label, which R7 carries verbatim onto the statement narration.
                'admission_number' => $row['admission_raw'] === '' ? null : $row['admission_raw'],
                'wcbs_student_ref' => $this->blankToNull($row['wcbs_student_ref']),
                'fee_type_label' => $row['label_raw'],
                'balance' => $row['balance'],
                'student_total_balance' => $row['student_total_balance'],
                'wcbs_bill_reference' => $this->blankToNull($row['wcbs_bill_reference']),
                'student_id' => $row['student_id'],
                'status' => $isRejected ? OpeningBalanceRowStatus::Rejected : OpeningBalanceRowStatus::Ok,
                'findings' => $findings === [] ? null : $findings,
            ]);

            $rowCount++;

            if ($isRejected) {
                $rejected[] = [
                    'line' => $row['line'],
                    'admission_number' => $row['key'],
                    'codes' => array_column($findings, 'code'),
                ];
            }
        }

        if ($duplicateInFile !== []) {
            $batchFindings[] = $this->finding('duplicate_row_key_in_file',
                count($duplicateInFile).' row(s) repeat an (admission number, fee type) already staged in this batch and were NOT staged.');
        }

        // ── §1's L2 — Σ(student stated totals) against the operator's control total. ──
        [$statedSum, $contributing, $excluded] = $this->statedTotalSum($staged, $groups);
        if (! $statedSum->equals($controlTotal)) {
            $batchFindings[] = $this->finding('control_total_mismatch', sprintf(
                'Σ of the stated student totals = %s over %d student(s) but --control-total = %s (Δ %d kobo).%s',
                $statedSum->toNaira(),
                $contributing,
                $controlTotal->toNaira(),
                $statedSum->toKobo() - $controlTotal->toKobo(),
                $excluded > 0
                    ? " {$excluded} student(s) stated no usable total and are NOT in the sum."
                    : '',
            ));
        }

        // INGEST COMPLETENESS — read vs staged. This is a different control from L1 and L2: those
        // check the arithmetic of what was staged, and they can be perfectly self-consistent over a
        // batch that is short of the file.
        //
        // The breakdown must account for the WHOLE difference. Anything it cannot explain is named
        // `unattributed`, which is what makes a future skip that forgets to register a reason
        // surface as an unexplained gap rather than as silence.
        if ($fileRowCount !== $rowCount) {
            $attributed = array_sum($skipReasons);
            $unattributed = ($fileRowCount - $rowCount) - $attributed;
            $breakdown = $skipReasons;
            if ($unattributed !== 0) {
                $breakdown['unattributed'] = $unattributed;
            }

            $parts = [];
            foreach ($breakdown as $reason => $count) {
                $parts[] = "{$reason}={$count}";
            }

            $batchFindings[] = $this->finding('ingest_incomplete', sprintf(
                'Read %d data line(s) with content but staged %d — %d not ingested (%s).',
                $fileRowCount, $rowCount, $fileRowCount - $rowCount, implode(', ', $parts),
            ));
        }

        // §7's other side: a student in the portal and absent from the file has an opening position
        // of zero, and that is a claim somebody has to make deliberately rather than inherit from a
        // missing line.
        // Counted PER STUDENT, not per admission number, so a duplicated key cannot hide a second
        // unimported student behind a first one that matched. Students with no admission number at
        // all are outside this count and are reported by the batch finding above instead — they are
        // unimportable by construction, not merely absent.
        $absent = [];
        foreach ($byAdmission as $admission => $ids) {
            foreach ($ids as $id) {
                if (! isset($matchedStudentIds[$id])) {
                    $absent[] = (string) $admission;
                }
            }
        }

        $batch->update([
            'row_count' => $rowCount,
            'file_row_count' => $fileRowCount,
            'findings' => $batchFindings === [] ? null : $batchFindings,
            'status' => ($rejected === [] && $batchFindings === [])
                ? OpeningBalanceBatchStatus::Validated
                : OpeningBalanceBatchStatus::Rejected,
        ]);

        return $this->report($batch, $rowCount, $fileRowCount, $blankLines, $rejected, $l1Failures,
            $unresolved, $absent, $duplicateInFile, $nullAdmissions, $duplicateAfterTrim,
            $statedSum, $controlTotal, $batchFindings);
    }

    /**
     * §1's L1 for one student's row-group: Σ(that student's per-fee-type balances) against the total
     * the file states for them. Returns the finding to stamp on EVERY row of the group, or null when
     * the group passes.
     *
     * THREE OUTCOMES, and the middle one is the reason this returns a finding rather than a bool:
     *
     *  - the stated total disagrees with itself across the group → `inconsistent_student_total`.
     *    §2 requires the same figure on every one of a student's rows; two different figures mean
     *    there is no stated total to check against, and picking one would be this command inventing
     *    the witness it is supposed to be checking.
     *  - some row of the group has no usable balance or no usable stated total →
     *    `l1_not_checkable`. That row is already rejected on its own account, but its NEIGHBOURS
     *    would otherwise stage as `ok` with the group's arithmetic never checked — and 4b would then
     *    post part of a student whose total nothing verified.
     *  - the sum differs from the stated total → `student_total_mismatch`, both sides named.
     *
     * @param  list<array<string, mixed>>  $staged
     * @param  list<int>  $indexes
     * @return array{code: string, message: string}|null
     */
    private function l1Verdict(array $staged, array $indexes): ?array
    {
        $stated = [];
        $missing = 0;
        $sum = Money::fromKobo(0);

        foreach ($indexes as $index) {
            $balance = $staged[$index]['balance'];
            $total = $staged[$index]['student_total_balance'];

            if ($balance === null || $total === null) {
                $missing++;

                continue;
            }

            $sum = $sum->plus($balance);
            $stated[$total->toKobo()] = $total;
        }

        if (count($stated) > 1) {
            $figures = implode(', ', array_map(fn (Money $m) => $m->toNaira(), $stated));

            return $this->finding('inconsistent_student_total', sprintf(
                'This student\'s rows state %d different totals (%s); §2 requires the SAME total on every row of a student.',
                count($stated), $figures,
            ));
        }

        if ($missing > 0) {
            return $this->finding('l1_not_checkable', sprintf(
                '%d of this student\'s %d row(s) carry no usable balance or stated total, so L1 could not be checked; '
                .'the whole row-group is rejected rather than staged part-checked.',
                $missing, count($indexes),
            ));
        }

        $total = reset($stated);
        if ($total === false) {
            // Unreachable while $missing === 0 and the group is non-empty; a group can only be
            // empty if it was never created, and it is created by its first member.
            return null;
        }

        if ($sum->equals($total)) {
            return null;
        }

        // BOTH sides in the finding, and the group is rejected — never corrected (§1).
        return $this->finding('student_total_mismatch', sprintf(
            'Σ of this student\'s %d fee-type balance(s) = %s but student_total_balance = %s (Δ %d kobo).',
            count($indexes), $sum->toNaira(), $total->toNaira(), $sum->toKobo() - $total->toKobo(),
        ));
    }

    /**
     * §1's L2 input: Σ over STUDENTS (not rows) of the total each one states, counted once per
     * student. A student whose group states no usable total, or states more than one, contributes
     * nothing and is counted as excluded — a zero would be this command asserting a figure the file
     * never stated, and silently summing over fewer students than the file names is how L2 goes green
     * on an incomplete set.
     *
     * @param  list<array<string, mixed>>  $staged
     * @param  array<string, list<int>>  $groups
     * @return array{0: Money, 1: int, 2: int}
     */
    private function statedTotalSum(array $staged, array $groups): array
    {
        $sum = Money::fromKobo(0);
        $contributing = 0;
        $excluded = 0;

        foreach ($groups as $indexes) {
            $stated = [];
            foreach ($indexes as $index) {
                $total = $staged[$index]['student_total_balance'];
                if ($total !== null) {
                    $stated[$total->toKobo()] = $total;
                }
            }

            if (count($stated) !== 1) {
                $excluded++;

                continue;
            }

            $sum = $sum->plus(reset($stated));
            $contributing++;
        }

        return [$sum, $contributing, $excluded];
    }

    /**
     * The in-PHP duplicate key for a fee-type label — §12 decision 3, closed in
     * 2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file.php's docblock:
     * 'Tuition' and 'tuition' are THE SAME FEE TYPE.
     *
     * The point of folding case HERE is that the index folds it anyway. `fee_type_label` is
     * utf8mb4_unicode_ci, so unique(school_id, batch_id, admission_number, fee_type_label) collides
     * the two whatever PHP believes; a byte comparison would let the second row through the in-PHP
     * pass and into the insert, where 1062 aborts the run mid-batch instead of reporting a named
     * finding. So the detection is made to AGREE with the index rather than disagree with it.
     *
     * THE RESIDUAL, stated rather than implied: utf8mb4_unicode_ci also folds accents and is PAD
     * SPACE, and `mb_strtolower` + `trim` reproduces only the case and the padding. An accent-only
     * pair ('Tuición' / 'Tuicion') is therefore still caught by the INDEX and not by this pass — the
     * run aborts with 1062 rather than reporting a duplicate. That is a worse operator experience and
     * not a correctness hole: nothing is staged wrong either way.
     */
    private function normaliseLabel(string $label): string
    {
        return mb_strtolower(trim($label));
    }

    /**
     * The operator report. Counts, line numbers and admission numbers ONLY — no names, and no
     * per-student figures. The figures live in each staged row's `findings` JSON, which is what
     * U12b will render. The two L2 figures ARE printed: they are batch aggregates, not any one
     * student's position.
     *
     * @param  list<array{line: int, admission_number: string, codes: list<string>}>  $rejected
     * @param  list<array{admission_number: string, code: string}>  $l1Failures
     * @param  list<array{line: int, admission_number: string}>  $unresolved
     * @param  list<string>  $absent
     * @param  list<array{line: int, admission_number: string, fee_type_label: string, first: int}>  $duplicateInFile
     * @param  list<array{code: string, message: string}>  $batchFindings
     */
    private function report(
        OpeningBalanceBatch $batch,
        int $rowCount,
        int $fileRowCount,
        int $blankLines,
        array $rejected,
        array $l1Failures,
        array $unresolved,
        array $absent,
        array $duplicateInFile,
        int $nullAdmissions,
        int $duplicateAfterTrim,
        Money $statedSum,
        Money $controlTotal,
        array $batchFindings,
    ): int {
        $this->info("Batch [{$batch->batch_reference}] staged from [{$batch->filename}] — READ-ONLY, nothing was posted.");
        $this->table(['Measure', 'Count'], [
            // Read vs staged, adjacent on purpose: the pair IS the ingest-completeness control, and
            // a reader who sees only one of them cannot tell a complete batch from a short one.
            // The blank count sits with them so the three reconcile BY EYE against the file the
            // operator is holding: content + blank = physical data lines. `file_row_count` counts
            // lines WITH CONTENT, not physical lines — labelled as such rather than left to be
            // discovered when a trailing newline makes the numbers look wrong.
            ['data lines with content', $fileRowCount],
            ['blank lines skipped', $blankLines],
            ['rows staged', $rowCount],
            ['rejected rows', count($rejected)],
            ['students failing L1 (whole row-group rejected)', count($l1Failures)],
            ['file rows matching no student', count($unresolved)],
            ['students in School absent from the file', count($absent)],
            ['file rows dropped as duplicate (student, fee type)', count($duplicateInFile)],
            ['School students with no admission number', $nullAdmissions],
            ['School admission numbers duplicated after trim', $duplicateAfterTrim],
        ]);

        // §1's L2, printed whether or not it failed. A check whose figures only appear when it fails
        // is one an operator cannot confirm ran.
        $this->line(sprintf(
            'L2 (kobo): Σ stated student totals=%d, --control-total=%d, Δ=%d',
            $statedSum->toKobo(), $controlTotal->toKobo(), $statedSum->toKobo() - $controlTotal->toKobo(),
        ));

        foreach ($batchFindings as $finding) {
            $this->error("BATCH FINDING [{$finding['code']}] {$finding['message']}");
        }

        $this->printList('Rejected rows', array_map(
            fn (array $r) => "line {$r['line']} admission [{$r['admission_number']}]: ".implode(', ', $r['codes']),
            $rejected));
        $this->printList('Students failing L1 (every row of theirs is rejected)', array_map(
            fn (array $r) => "admission [{$r['admission_number']}]: {$r['code']}", $l1Failures));
        $this->printList('Rows dropped as duplicate (student, fee type)', array_map(
            fn (array $r) => "line {$r['line']} admission [{$r['admission_number']}] fee type [{$r['fee_type_label']}] first staged at line {$r['first']}",
            $duplicateInFile));
        $this->printList('Students absent from the file (opening position would be zero)', $absent);

        if ($rejected === [] && $batchFindings === []) {
            $this->info('Clean: every row validated, both checksum levels hold, and the join key is safe. Batch status: '.$batch->status->value);

            return self::SUCCESS;
        }

        $this->error('Batch status: '.$batch->status->value.' — resolve the findings above and re-run with a new --batch-reference.');

        return self::FAILURE;
    }

    /** @param list<string> $lines */
    private function printList(string $heading, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $this->line('');
        $this->warn($heading.' ('.count($lines).'):');
        foreach (array_slice($lines, 0, self::LIST_LIMIT) as $line) {
            $this->line('  - '.$line);
        }
        // Announced, never silent — a cut list that looked complete is how a partial report becomes
        // a false all-clear.
        if (count($lines) > self::LIST_LIMIT) {
            $this->line('  … '.(count($lines) - self::LIST_LIMIT).' more not shown; the staged rows carry all of them.');
        }
    }

    /**
     * Read the CSV into line-numbered associative records. The header row is REQUIRED (§2) and a
     * missing required column aborts the run before the batch row is written — a file whose shape
     * is wrong has no rows worth staging. The required set is read off the COLUMNS map, so a change
     * to the format cannot leave the header check behind.
     *
     * WHOLLY BLANK LINES ARE DROPPED HERE, and this is the only place in the run where a physical
     * line disappears without reaching `$skipReasons`. That is deliberate — a blank line carries no
     * claim, and raising `ingest_incomplete` over a trailing newline would be a false positive on
     * every real extract, which teaches an operator to ignore the one control that says a row went
     * missing. But an exemption that nothing measures is an exemption that grows, so the count is
     * returned rather than swallowed, and the invariant below refuses to let a SECOND drop path
     * join it silently.
     *
     * @return array{records: list<array{line: int, values: array<string, string>}>, blankLines: int}
     *
     * @throws InvalidArgumentException
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException("Could not open [{$path}].");
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false || $header === [null]) {
                throw new InvalidArgumentException('The file is empty; a header row is required.');
            }

            // A UTF-8 BOM on the first cell would make the first header name unmatchable.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

            $required = array_keys(array_filter(self::COLUMNS, fn (array $spec) => $spec['required']));
            $missing = array_diff($required, $header);
            if ($missing !== []) {
                throw new InvalidArgumentException('Missing required column(s): '.implode(', ', $missing).'.');
            }

            $records = [];
            $blankLines = 0;
            $line = 1; // the header is line 1; data starts at 2, which is what an operator sees
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($row === [null]) {
                    $blankLines++; // a wholly blank line carries no claim — counted, not swallowed

                    continue;
                }
                $values = [];
                foreach ($header as $index => $name) {
                    $values[$name] = (string) ($row[$index] ?? '');
                }
                $records[] = ['line' => $line, 'values' => $values];
            }

            // THE POINT OF THIS METHOD'S BOOKKEEPING. Every physical data line must have become
            // either a record or a counted blank; those are the only two outcomes this loop is
            // allowed to have. It holds today by construction, which is exactly why it is asserted:
            // the day someone adds a third `continue` here — `if ($values['admission_number'] ===
            // '') continue;` is the plausible one — the drop lands upstream of $skipReasons, where
            // `file_row_count` cannot see it and the ingest-completeness finding reports nothing.
            // This throw is what turns that silent narrowing into a stopped run.
            $physical = $line - 1; // $line counts the header
            if (count($records) + $blankLines !== $physical) {
                throw new InvalidArgumentException(sprintf(
                    'Reader accounting failed: %d record(s) + %d blank line(s) != %d physical data line(s). '
                    .'A drop path was added to readCsv() that neither stages a row nor counts a blank, so '
                    .'file_row_count can no longer detect a missing row. Register it, or do not drop it.',
                    count($records), $blankLines, $physical,
                ));
            }

            return ['records' => $records, 'blankLines' => $blankLines];
        } finally {
            fclose($handle);
        }
    }

    private function resolveSchool(string $option): ?School
    {
        if ($option === '') {
            $this->error('--school is required (numeric id or slug).');

            return null;
        }

        $school = ctype_digit($option)
            ? School::query()->find((int) $option)
            : School::query()->where('slug', $option)->first();

        if ($school === null) {
            $this->error("No School matches --school [{$option}].");
        }

        return $school;
    }

    /**
     * §1's L2 witness, and REQUIRED — §2 makes it so, and the check has no second input.
     *
     * IT IS AN OPTION AND NOT A COLUMN, and that is the only reason the figure is worth having
     * (§12 decision 2, CLOSED by R12). A total carried inside the file was produced by the same
     * export run as the rows: drop a student on the way out of WCBS and they vanish from the rows
     * AND from the total, the two still agree, and L2 goes green on an incomplete file. A witness
     * that shares a failure mode with the thing it witnesses is not a witness, it is a second copy.
     * The figure earns its place by travelling a different path — read off WCBS's own report and
     * typed by the person doing the upload, who thereby ATTESTS to it.
     *
     * SIGNED, for the same reason `balance` is: a school whose students are net in credit has a
     * negative Σ, and a non-negative rule here would refuse the file rather than the mistake.
     */
    private function resolveControlTotal(string $option): ?Money
    {
        if (trim($option) === '') {
            $this->error('--control-total is required: Σ of every student stated total, read off WCBS\'s own report (§1 L2).');

            return null;
        }

        try {
            return Money::fromNaira(trim($option));
        } catch (InvalidArgumentException) {
            $this->error("Invalid --control-total [{$option}]: expected naira with up to two decimal places.");

            return null;
        }
    }

    /**
     * The cutover term, checked to exist AND to belong to the target School. Validated by rule
     * rather than by loading an Academics model: Finance does not import Academics' models
     * (arch rule 3), and the existing Finance precedent for naming `terms` is exactly this —
     * `exists:terms,id` in FeeScheduleRequest.
     *
     * STILL REQUIRED, AND STILL OPEN. §9 records the contradiction: R5 puts the cutover on a term
     * boundary, so §1 says there is no cutover term T, while `batches.term_id` is NOT NULL with an
     * FK. Either answer — nullable, or repurposed to name the term being CLOSED OUT — changes a
     * migration, and 4a is the file format. 4b/4c closes it; this stays as it shipped rather than
     * being half-changed here.
     */
    private function resolveTerm(string $option, int $schoolId): ?int
    {
        $validator = Validator::make(['term' => $option], [
            'term' => ['required', 'integer', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
        ]);

        if ($validator->fails()) {
            $this->error("Invalid --term [{$option}]: it must be a terms.id belonging to this School.");

            return null;
        }

        return (int) $option;
    }

    /**
     * Strict Y-m-d, or null. Two guards, and both are needed: Carbon 3 THROWS on an unparseable
     * string rather than returning false, and it silently OVERFLOWS a real-looking impossible date
     * (2026-02-30 → 2026-03-02), which only the round-trip comparison catches. A date the operator
     * did not write is worse than no date.
     */
    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return ($date !== null && $date->format('Y-m-d') === $value) ? $date : null;
    }

    /** @return array{code: string, message: string} */
    private function finding(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /**
     * Trim, and store a blank as NULL. An explicit `=== ''` rather than `?:` — the falsy shortcut
     * would turn a legitimate reference of "0" into a NULL, which is the same class of coercion §2
     * forbids on amounts.
     */
    private function blankToNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
