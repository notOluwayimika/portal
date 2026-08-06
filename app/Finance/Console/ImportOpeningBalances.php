<?php

namespace App\Finance\Console;

use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Finance\Services\FeeScheduleLookup;
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
 * §9 commit 1 — the READ-ONLY validator for a WCBS opening-balance extract
 * (docs/handoff/opening-balance-import-spec.md Rev 2).
 *
 * IT POSTS NOTHING. No ledger transaction, no payment, no invoice, no account-balance movement.
 * It parses the file, enforces §1's identity, applies §2's and §7's rejection rules, resolves the
 * join key against the School's roster, runs §5's fee-schedule comparison, and stages all of it in
 * finance_opening_balance_batches / _rows for a human to look at. `--dry-run` is the only mode that
 * exists; without it the command refuses and exits non-zero rather than stubbing a posting path.
 * Posting and its approval gate are commit 4.
 *
 * WHY IT LIVES IN App\Finance\Console AND NOT app/Console/Commands. It touches Finance models, and
 * tests/Arch/ArchitectureBoundaryTest.php keeps those private to the module; bin/ci-boundary-lint.php
 * separately forbids a `finance_*` table literal outside app/Finance/. bootstrap/app.php:37-40 already
 * records this and registers the module's other two commands explicitly, because auto-discovery only
 * scans app/Console/Commands. AuditDutySeparation.php — whose shape the brief pointed at — touches no
 * Finance model, which is why it can sit where it does.
 *
 * STUDENTS ARE RESOLVED THROUGH THE ACL PORT, not by reading Academics tables (arch rule 3 — and from
 * inside App\Finance there is no other lawful option). The port's two existing methods cannot serve as
 * a join: `displayFor()` runs ids→display, the wrong direction for a file that has admission numbers;
 * `matchingStudentIds()` is a `LIKE %term%` search box that would resolve "A1" onto "A100" and import
 * one student's arrears against another. So this commit extends the port with
 * `admissionNumberIndex()` — an exact roster, matched in this command — which is also the only thing
 * that can answer §6's pre-flight counts and §7's "in the portal, absent from the file". That
 * extension is consumer-driven: this validator is the consumer, in the same commit.
 *
 * OUTPUT IS AN OPERATOR SURFACE AND IT LEAVES THE BOX. Counts, line numbers and admission numbers
 * only — never a name, never a student's figures. The figures that matter (both sides of a failed
 * identity, both sides of a §5 mismatch) are recorded in the staged row's `findings` JSON, which is
 * where U12b will read them from.
 */
class ImportOpeningBalances extends Command
{
    protected $signature = 'finance:import-opening-balances
        {--file= : path to the WCBS CSV}
        {--school= : the School to import into (numeric id or slug)}
        {--term= : the cutover term T (terms.id)}
        {--as-at= : the cutover date D (Y-m-d)}
        {--batch-reference= : §7 idempotency key; defaults to the CSV filename}
        {--dry-run : the ONLY mode this commit implements}';

    protected $description = 'READ-ONLY: validate a WCBS opening-balance extract into staging and report (§9 commit 1 — posts nothing)';

    /** Columns §2 requires on every row. A blank in any of them rejects the row. */
    private const REQUIRED_COLUMNS = [
        'admission_number',
        'wcbs_student_ref',
        'prior_arrears',
        'wcbs_billed_total',
        'paid_to_date',
        'wcbs_total_balance',
        'wcbs_bill_reference',
    ];

    /** The three figures §7 forbids from being negative. Credit belongs in wcbs_total_balance. */
    private const NON_NEGATIVE_COLUMNS = ['prior_arrears', 'wcbs_billed_total', 'paid_to_date'];

    /** How many identifiers a list prints before it is cut. Truncation is always announced. */
    private const LIST_LIMIT = 50;

    public function handle(BillableEnrollmentProvider $enrollments, FeeScheduleLookup $schedules): int
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

        // §5.4 / Constitution 13: off-request context is ActiveSchool::runFor and nothing else.
        // Every model touched below (the staging tables, the port's roster, the fee schedules) is
        // School-scoped, so the scope — not a where() someone can forget — is what isolates the run.
        return ActiveSchool::runFor($school->id, function () use ($school, $file, $cutoverDate, $enrollments, $schedules): int {
            $termId = $this->resolveTerm((string) $this->option('term'), $school->id);
            if ($termId === null) {
                return self::FAILURE;
            }

            try {
                $records = $this->readCsv($file);
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

            return $this->validateInto($batch, $records, $termId, $enrollments, $schedules);
        });
    }

    /**
     * The whole validation pass, inside the School context. Returns the process exit code.
     *
     * @param  list<array{line: int, values: array<string, string>}>  $records
     */
    private function validateInto(
        OpeningBalanceBatch $batch,
        array $records,
        int $termId,
        BillableEnrollmentProvider $enrollments,
        FeeScheduleLookup $schedules,
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
        // §6.1 and §3d: either of these means the join key itself is unsafe, so it is a finding on
        // the BATCH — the file format is not yet frozen and no amount of row-level cleanliness
        // rescues it. `students.admission_number` has been NOT NULL since
        // 2026_07_18_100000_make_identifier_columns_not_null.php:36, so the first count can only be
        // non-zero if that is ever relaxed; it is computed rather than assumed away.
        if ($nullAdmissions > 0) {
            $batchFindings[] = $this->finding('school_has_null_admission_numbers',
                "{$nullAdmissions} student(s) in this School have no admission number — the join key is unsafe.");
        }
        if ($duplicateAfterTrim > 0) {
            $batchFindings[] = $this->finding('school_has_duplicate_admission_numbers',
                "{$duplicateAfterTrim} admission number(s) in this School are duplicated after trimming — the join key is unsafe.");
        }

        $rowCount = 0;
        $rejected = [];
        $exceptions = [];
        $notComparable = [];
        $unresolved = [];
        $seenInFile = [];        // trimmed admission number → first line number staged
        $duplicateInFile = [];   // lines dropped because their key was already staged
        $matchedStudentIds = [];

        $totals = [
            'prior_arrears' => Money::fromKobo(0),
            'paid_to_date' => Money::fromKobo(0),
            'wcbs_billed_total' => Money::fromKobo(0),
        ];

        $scheduleTotals = []; // class_level_id → Money|null (null = no active schedule)

        foreach ($records as $record) {
            $line = $record['line'];
            $values = $record['values'];
            $findings = [];

            $rawAdmission = $values['admission_number'] ?? '';
            $key = trim($rawAdmission);

            // A repeat of the same key inside ONE file would collide on
            // unique(school_id, batch_id, admission_number) mid-loop and abort the run. The first
            // occurrence is staged; the rest are reported as a batch finding naming their lines.
            // Nothing is dropped silently — the count and the lines are printed.
            if ($key !== '' && isset($seenInFile[$key])) {
                $duplicateInFile[] = ['line' => $line, 'admission_number' => $key, 'first' => $seenInFile[$key]];

                continue;
            }

            // ── §2: required columns. Blank ≠ zero; reject, never coerce. ──
            foreach (self::REQUIRED_COLUMNS as $column) {
                if (trim($values[$column] ?? '') === '') {
                    $findings[] = $this->finding('blank_required_column', "Column [{$column}] is blank; a blank is not a zero.");
                }
            }

            // ── Amounts: naira-with-2dp → integer kobo, by integer string arithmetic. ──
            // Money::fromNaira parses the digits itself (Money.php:74-77); no float multiplication
            // is involved, so a value like "80000.15" — which (int) ((float) '80000.15' * 100)
            // reads as 8000014 — parses to exactly 8000015. (Measured, not assumed: 8.07 is the
            // usual example and it does NOT break — 8.07 * 100 is exactly float(807).)
            $amounts = [];
            foreach (['prior_arrears', 'wcbs_billed_total', 'paid_to_date', 'wcbs_total_balance'] as $column) {
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

            // ── §7: negatives. A negative wcbs_total_balance is legitimate (student in credit). ──
            foreach (self::NON_NEGATIVE_COLUMNS as $column) {
                if ($amounts[$column] !== null && $amounts[$column]->isNegative()) {
                    $findings[] = $this->finding('negative_amount',
                        "Column [{$column}] is negative ({$amounts[$column]->toNaira()}); credit belongs in wcbs_total_balance.");
                }
            }

            // ── §1: the identity. The whole defence against a mis-split extract. ──
            $identityChecked = ! in_array(null, $amounts, true);
            if ($identityChecked) {
                $derived = $amounts['prior_arrears']->plus($amounts['wcbs_billed_total'])->minus($amounts['paid_to_date']);
                if (! $derived->equals($amounts['wcbs_total_balance'])) {
                    // BOTH sides in the finding, and the row is rejected — never corrected.
                    $findings[] = $this->finding('identity_mismatch', sprintf(
                        'prior_arrears + wcbs_billed_total − paid_to_date = %s but wcbs_total_balance = %s (Δ %d kobo).',
                        $derived->toNaira(),
                        $amounts['wcbs_total_balance']->toNaira(),
                        $derived->toKobo() - $amounts['wcbs_total_balance']->toKobo(),
                    ));
                }
            }

            // ── last_payment_date is optional, but a malformed one is not a blank. ──
            $lastPayment = null;
            $rawDate = trim($values['last_payment_date'] ?? '');
            if ($rawDate !== '') {
                $lastPayment = $this->parseDate($rawDate);
                if ($lastPayment === null) {
                    $findings[] = $this->finding('unparseable_date', "Column [last_payment_date] value [{$rawDate}] is not Y-m-d.");
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

            $isRejected = $findings !== [];
            $status = $isRejected ? OpeningBalanceRowStatus::Rejected : OpeningBalanceRowStatus::Ok;
            $expected = null;

            // ── §5: the comparison. Only for a row that is otherwise sound — comparing a row whose
            // arithmetic is already known wrong produces a finding about a finding. ──
            if (! $isRejected && $studentId !== null) {
                $enrollment = $enrollments->currentForStudent($studentId);

                if ($enrollment === null) {
                    $status = OpeningBalanceRowStatus::NotComparable;
                    $findings[] = $this->finding('no_active_enrollment',
                        'The student has no active enrollment, so no class level to price against.');
                    $notComparable[] = ['line' => $line, 'admission_number' => $key, 'reason' => 'no_active_enrollment'];
                } elseif ($enrollment->classLevelId === null) {
                    $status = OpeningBalanceRowStatus::NotComparable;
                    $findings[] = $this->finding('enrollment_has_no_class_level',
                        'The student\'s enrollment names no class level (nullable link), so nothing can be priced.');
                    $notComparable[] = ['line' => $line, 'admission_number' => $key, 'reason' => 'enrollment_has_no_class_level'];
                } else {
                    // Informational, NOT a rejection: the episode the class level came from is not
                    // the cutover term. It is still that student's class level, but it is the
                    // reason a comparison could be against the wrong year's price, and V2 will bill
                    // T against an episode that does not exist yet.
                    if ($enrollment->termId !== null && $enrollment->termId !== $termId) {
                        $findings[] = $this->finding('enrollment_term_differs_from_cutover_term',
                            'The student\'s active enrollment is for a different term than the batch\'s cutover term.');
                    }

                    if (! array_key_exists($enrollment->classLevelId, $scheduleTotals)) {
                        $scheduleTotals[$enrollment->classLevelId] = $this->scheduleTotalFor($schedules, $termId, $enrollment->classLevelId);
                    }
                    $expected = $scheduleTotals[$enrollment->classLevelId];

                    if ($expected === null) {
                        // §5: NOT an error. U1 has not priced this class level, and must before V2.
                        $status = OpeningBalanceRowStatus::NotComparable;
                        $findings[] = $this->finding('no_active_fee_schedule',
                            'No ACTIVE fee schedule for this (term, class level); U1 must price it before V2 runs.');
                        $notComparable[] = ['line' => $line, 'admission_number' => $key, 'reason' => 'no_active_fee_schedule'];
                    } elseif ($amounts['wcbs_billed_total'] !== null && ! $expected->equals($amounts['wcbs_billed_total'])) {
                        // An EXCEPTION, not a defect: the row stays `ok` and carries both figures
                        // and the signed difference for a human. It is counted separately from
                        // not_comparable and from rejections, and never conflated with either.
                        $findings[] = $this->finding('comparison_mismatch', sprintf(
                            'Portal would bill %s for T; WCBS billed %s (signed difference %d kobo, portal − WCBS).',
                            $expected->toNaira(),
                            $amounts['wcbs_billed_total']->toNaira(),
                            $expected->toKobo() - $amounts['wcbs_billed_total']->toKobo(),
                        ));
                        $exceptions[] = ['line' => $line, 'admission_number' => $key];
                    }
                }
            }

            // §7: all three figures zero — nothing for commit 4 to post. Recorded, not rejected.
            if ($status === OpeningBalanceRowStatus::Ok && $identityChecked
                && $amounts['prior_arrears']->isZero() && $amounts['wcbs_billed_total']->isZero() && $amounts['paid_to_date']->isZero()) {
                $findings[] = $this->finding('nothing_to_post', 'All three figures are zero; the posting commit will skip this row.');
            }

            OpeningBalanceRow::create([
                'batch_id' => $batch->id,
                'line_number' => $line,
                // Stored EXACTLY as it appeared — the trim happens in the comparison, never in
                // what is kept, so a whitespace defect stays visible to the operator.
                'admission_number' => $rawAdmission === '' ? null : $rawAdmission,
                'wcbs_student_ref' => $this->blankToNull($values['wcbs_student_ref'] ?? ''),
                'prior_arrears' => $amounts['prior_arrears'],
                'wcbs_billed_total' => $amounts['wcbs_billed_total'],
                'paid_to_date' => $amounts['paid_to_date'],
                'wcbs_total_balance' => $amounts['wcbs_total_balance'],
                'wcbs_bill_reference' => $this->blankToNull($values['wcbs_bill_reference'] ?? ''),
                'last_payment_date' => $lastPayment,
                'student_id' => $studentId,
                'status' => $status,
                'findings' => $findings === [] ? null : $findings,
                'expected_billed' => $expected,
            ]);

            if ($key !== '') {
                $seenInFile[$key] = $line;
            }
            $rowCount++;

            if ($status === OpeningBalanceRowStatus::Rejected) {
                $rejected[] = ['line' => $line, 'admission_number' => $key, 'codes' => array_column($findings, 'code')];
            }

            // §5's control totals cover every staged row whose three summed figures all parsed. A
            // row that could not produce a figure contributes nothing rather than a zero — a zero
            // would be this command asserting an amount the file never stated.
            if ($amounts['prior_arrears'] !== null && $amounts['paid_to_date'] !== null && $amounts['wcbs_billed_total'] !== null) {
                $totals['prior_arrears'] = $totals['prior_arrears']->plus($amounts['prior_arrears']);
                $totals['paid_to_date'] = $totals['paid_to_date']->plus($amounts['paid_to_date']);
                $totals['wcbs_billed_total'] = $totals['wcbs_billed_total']->plus($amounts['wcbs_billed_total']);
            }
        }

        if ($duplicateInFile !== []) {
            $batchFindings[] = $this->finding('duplicate_admission_number_in_file',
                count($duplicateInFile).' row(s) repeat an admission number already staged in this batch and were NOT staged.');
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
            'total_prior_arrears' => $totals['prior_arrears'],
            'total_paid_to_date' => $totals['paid_to_date'],
            'total_wcbs_billed' => $totals['wcbs_billed_total'],
            'findings' => $batchFindings === [] ? null : $batchFindings,
            'status' => ($rejected === [] && $batchFindings === [])
                ? OpeningBalanceBatchStatus::Validated
                : OpeningBalanceBatchStatus::Rejected,
        ]);

        return $this->report($batch, $rowCount, $rejected, $exceptions, $notComparable, $unresolved,
            $absent, $duplicateInFile, $nullAdmissions, $duplicateAfterTrim, $batchFindings);
    }

    /**
     * The operator report. Counts, line numbers and admission numbers ONLY — no names, and no
     * per-student figures. The figures live in each staged row's `findings` JSON, which is what
     * U12b will render.
     *
     * @param  list<array{line: int, admission_number: string, codes: list<string>}>  $rejected
     * @param  list<array{line: int, admission_number: string}>  $exceptions
     * @param  list<array{line: int, admission_number: string, reason: string}>  $notComparable
     * @param  list<array{line: int, admission_number: string}>  $unresolved
     * @param  list<string>  $absent
     * @param  list<array{line: int, admission_number: string, first: int}>  $duplicateInFile
     * @param  list<array{code: string, message: string}>  $batchFindings
     */
    private function report(
        OpeningBalanceBatch $batch,
        int $rowCount,
        array $rejected,
        array $exceptions,
        array $notComparable,
        array $unresolved,
        array $absent,
        array $duplicateInFile,
        int $nullAdmissions,
        int $duplicateAfterTrim,
        array $batchFindings,
    ): int {
        $this->info("Batch [{$batch->batch_reference}] staged from [{$batch->filename}] — READ-ONLY, nothing was posted.");
        $this->table(['Measure', 'Count'], [
            ['rows staged', $rowCount],
            ['rejected rows', count($rejected)],
            ['comparison exceptions (§5 different)', count($exceptions)],
            ['not comparable (§5 — NOT an exception)', count($notComparable)],
            ['file rows matching no student', count($unresolved)],
            ['students in School absent from the file', count($absent)],
            ['file rows dropped as duplicate keys', count($duplicateInFile)],
            ['School students with no admission number', $nullAdmissions],
            ['School admission numbers duplicated after trim', $duplicateAfterTrim],
        ]);

        // §5's control totals, printed as the batch aggregate the approval screen re-asserts. These
        // are batch sums, not any one student's figures.
        $this->line('Control totals (kobo): '
            .'Σ prior_arrears='.$batch->total_prior_arrears?->toKobo()
            .' Σ paid_to_date='.$batch->total_paid_to_date?->toKobo()
            .' Σ wcbs_billed_total='.$batch->total_wcbs_billed?->toKobo()
            ." row_count={$rowCount}");

        foreach ($batchFindings as $finding) {
            $this->error("BATCH FINDING [{$finding['code']}] {$finding['message']}");
        }

        $this->printList('Rejected rows', array_map(
            fn (array $r) => "line {$r['line']} admission [{$r['admission_number']}]: ".implode(', ', $r['codes']),
            $rejected));
        $this->printList('Comparison exceptions (figures are on the staged row)', array_map(
            fn (array $r) => "line {$r['line']} admission [{$r['admission_number']}]", $exceptions));
        $this->printList('Not comparable', array_map(
            fn (array $r) => "line {$r['line']} admission [{$r['admission_number']}]: {$r['reason']}", $notComparable));
        $this->printList('Rows dropped as duplicate keys', array_map(
            fn (array $r) => "line {$r['line']} admission [{$r['admission_number']}] first staged at line {$r['first']}", $duplicateInFile));
        $this->printList('Students absent from the file (opening position would be zero)', $absent);

        if ($rejected === [] && $batchFindings === []) {
            $this->info('Clean: every row validated and the join key is safe. Batch status: '.$batch->status->value);

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
     * is wrong has no rows worth staging.
     *
     * @return list<array{line: int, values: array<string, string>}>
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

            $missing = array_diff(self::REQUIRED_COLUMNS, $header);
            if ($missing !== []) {
                throw new InvalidArgumentException('Missing required column(s): '.implode(', ', $missing).'.');
            }

            $records = [];
            $line = 1; // the header is line 1; data starts at 2, which is what an operator sees
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($row === [null]) {
                    continue; // a wholly blank line carries no claim
                }
                $values = [];
                foreach ($header as $index => $name) {
                    $values[$name] = (string) ($row[$index] ?? '');
                }
                $records[] = ['line' => $line, 'values' => $values];
            }

            return $records;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The portal's expected total for (term, class level) — the sum of the ACTIVE schedule's items.
     * Null when no active schedule exists, which is §5's "not comparable" and not an error.
     *
     * The single `status = active` filter lives in FeeScheduleLookup and nowhere else (that class's
     * docblock explains why); this reads through it rather than re-querying schedules.
     */
    private function scheduleTotalFor(FeeScheduleLookup $schedules, int $termId, int $classLevelId): ?Money
    {
        $schedule = $schedules->activeFor($termId, $classLevelId);
        if ($schedule === null) {
            return null;
        }

        $total = Money::fromKobo(0);
        foreach ($schedule->items as $item) {
            $total = $total->plus($item->amount);
        }

        return $total;
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
     * The cutover term, checked to exist AND to belong to the target School. Validated by rule
     * rather than by loading an Academics model: Finance does not import Academics' models
     * (arch rule 3), and the existing Finance precedent for naming `terms` is exactly this —
     * `exists:terms,id` in FeeScheduleRequest.
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
