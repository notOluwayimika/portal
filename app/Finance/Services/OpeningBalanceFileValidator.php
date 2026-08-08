<?php

namespace App\Finance\Services;

use App\Finance\Console\ImportOpeningBalances;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\DTOs\OpeningBalanceValidationResult;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Jobs\ProcessOpeningBalanceImport;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * §9 step 4a's validator, LIFTED OUT OF THE CONSOLE COMMAND by step 5b-iii and otherwise unchanged.
 *
 * WHY IT MOVED, because a mechanical extraction still needs a reason. The operator screen (spec §2's
 * U12b) uploads the same file and must reach the same verdict, and the only two lawful ways to do
 * that are one implementation or two. Two is how a data team ends up with a console run and a screen
 * run disagreeing about the same extract — the exact defect R13 refuses one level up, where the
 * COLUMNS map drives both the template and the row validator so they cannot drift. So the logic is
 * here and both callers are thin: {@see ImportOpeningBalances} parses options,
 * creates the batch and renders the operator report; {@see ProcessOpeningBalanceImport}
 * validates into a batch the controller already created.
 *
 * THE BEHAVIOUR IS NOT CHANGED BY THE MOVE, and the oracle for that claim is the command's own 47KB
 * of coverage (tests/Feature/Finance/OpeningBalanceImportTest.php), which drives the command and was
 * not edited. If this extraction altered a verdict, that file goes red.
 *
 * IT IS DELIBERATELY TWO CALLS, NOT ONE. `read()` throws on a file whose SHAPE is wrong — no header,
 * a missing required column — and it runs BEFORE the batch row exists on the console path, because a
 * file with no rows worth staging must not spend §7's idempotency key on a run nobody can read. The
 * HTTP path creates the batch first (the operator needs something to poll), so it calls `read()`
 * inside the job and converts the throw into a batch finding. One method that did both could not
 * serve both orders.
 *
 * IT ASSUMES ITS SCHOOL CONTEXT. Every model it touches is School-scoped, so the caller establishes
 * context — `ActiveSchool::runFor()` on the console path, the `SchoolAware` job middleware on the
 * queued one — and the scope, not a `where()` anyone can forget, is what isolates the run.
 */
class OpeningBalanceFileValidator
{
    /**
     * The longest `--batch-reference` (or defaulted filename) a batch may carry.
     *
     * 218 = 255 − 37. `finance_payments`.`payer_name` is varchar(255) and §9 step 4b snapshots
     * PostOpeningBalanceBatch::PAYER_NAME_PREFIX . <this reference> . ::PAYER_NAME_SUFFIX into it
     * (36 + 1 = 37 fixed characters). It is NOT this column's own width — `batch_reference` holds 255
     * and always will; the binding constraint is downstream, exactly as it is for `fee_type_label`.
     *
     * A PHP const expression cannot call mb_strlen, so the number is written with its arithmetic
     * beside it and OpeningBalancePostingTest asserts the two agree. Edit either affix and that test
     * goes red — which is the whole point, because the alternative is a cutover aborting at 1406.
     */
    public const BATCH_REFERENCE_MAX = 218;

    /**
     * THE FILE FORMAT (§2, frozen by R12) — required flag, max length, format, example, notes and
     * group per column, in the guardian import's shape and for its reason: one constant drives both
     * the template the platform issues (R13) and this validator, so they cannot drift apart.
     *
     * `notes` and `format` carry the OPERATOR-FACING rules, not notes to a developer, because those
     * are the columns the data team actually reads. A rule that lives only in the spec is a rule the
     * person filling in the sheet never sees — which is why `max` is stated in `format` as well as
     * held as a number, and why a test asserts the two agree rather than trusting them to.
     *
     * `max` IS THE STORAGE COLUMN'S OWN LIMIT for the four columns that land in a `varchar(255)`,
     * and it is checked in PHP *before* anything tries to write, because MySQL's answer to an
     * over-length value is to abort the statement — which used to abort the whole run. See the catch
     * in the write loop: the rule here is the defence, the catch is the backstop, and both ship.
     *
     * For the two MONEY cells there is no varchar to inherit from — they land in `bigint` minor
     * units — so their limit is derived instead: 21 characters is the widest naira figure a signed
     * bigint can hold in kobo (`92233720368547758.07`, plus a sign). It is a sanity bound on the
     * cell, not a column width, and it is stated as such rather than dressed up as one.
     *
     * R12's columns and NOTHING ELSE. `last_payment_date` is not among them, and its staging column
     * was retired with the four withdrawn money pairs
     * (2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file.php).
     *
     * @var array<string, array{required: bool, max: int, format: string, example: string, notes: string, group: string}>
     */
    public const COLUMNS = [
        // Linking
        'admission_number' => [
            'required' => true,
            'max' => 255,
            'format' => 'string, max 255 characters',
            'example' => 'STU2025001',
            'notes' => 'The join key. Must already exist in this School — a student is NEVER created from a finance import.',
            'group' => 'Linking',
        ],
        'wcbs_student_ref' => [
            'required' => true,
            'max' => 255,
            'format' => 'string, max 255 characters',
            'example' => 'WCBS-10233',
            'notes' => "WCBS's own id, stored for traceability. Never used to join.",
            'group' => 'Linking',
        ],
        'fee_type_label' => [
            'required' => true,
            // 229 = 255 − 26: the ledger narration column is varchar(255) and posting appends
            // PostOpeningBalanceBatch::NARRATION_SUFFIX (' — Balance Brought Forward', 26 characters)
            // to this label VERBATIM. It is NOT the storage column's own width for once — that is 255,
            // and a 255-character label stages perfectly well and then aborts the post at 1406. See the
            // constant's docblock: nothing is truncated, so the refusal moves here, to the file. A PHP
            // const expression cannot call mb_strlen, so the number is written with its arithmetic
            // beside it and OpeningBalancePostingTest asserts the two agree — an edit to the suffix
            // that forgets this number fails there rather than on cutover day.
            'max' => 229,
            'format' => 'string, max 229 characters',
            'example' => 'Tuition',
            'notes' => 'The fee type as WCBS names it, carried verbatim onto the statement. One row per student PER FEE TYPE. Spelling is matched case-insensitively — and also ignoring accents and trailing spaces — so "Tuition", "tuition" and "Tuitión" are ONE fee type, and a second row for it is refused.',
            'group' => 'Amounts',
        ],
        'balance' => [
            'required' => true,
            'max' => 21,
            'format' => 'naira with two decimal places, SIGNED (120000.00 / -5000.00), max 21 characters',
            'example' => '120000.00',
            'notes' => 'That fee type\'s closing balance for that student. POSITIVE is owed, NEGATIVE is credit. Blank is not zero — write 0.00 if the balance really is nil.',
            'group' => 'Amounts',
        ],
        'student_total_balance' => [
            'required' => true,
            'max' => 21,
            'format' => 'naira with two decimal places, SIGNED, max 21 characters',
            'example' => '145000.00',
            'notes' => "The student's total across ALL their fee types. Write the SAME figure on every one of that student's rows — it is the independent check that no line of theirs went missing.",
            'group' => 'Amounts',
        ],
        'wcbs_bill_reference' => [
            'required' => false,
            'max' => 255,
            'format' => 'string, max 255 characters',
            'example' => 'BILL-2026-0912',
            'notes' => 'OPTIONAL. The reference on the last paper bill, if WCBS carries one. A blank here does NOT reject the row.',
            'group' => 'Provenance',
        ],
    ];

    /**
     * The ONE unique index whose violation the write loop is allowed to convert into a finding
     * about the operator's file.
     *
     * IT IS A SECOND COPY OF A NAME, and that is stated rather than hidden: the definition lives in
     * `2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file.php`'s `NEW_KEY`
     * constant, which is unreachable from here — that migration is an anonymous class, and pointing
     * a migration at an application constant would let a later edit change what an old migration
     * builds on a fresh install. So the copy is deliberate, and the drift it invites is closed by a
     * test that asserts this string names a unique index that really exists on the table, read from
     * `information_schema` rather than from either source.
     */
    public const ROW_KEY_INDEX = 'ob_rows_school_batch_admission_fee_type_unique';

    public function __construct(private readonly BillableEnrollmentProvider $enrollments) {}

    /**
     * Read the CSV into line-numbered associative records. The header row is REQUIRED (§2) and a
     * missing required column aborts BEFORE the batch row is written on the console path — a file
     * whose shape is wrong has no rows worth staging. The required set is read off the COLUMNS map,
     * so a change to the format cannot leave the header check behind.
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
    public function read(string $path): array
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
                    .'A drop path was added to read() that neither stages a row nor counts a blank, so '
                    .'file_row_count can no longer detect a missing row. Register it, or do not drop it.',
                    count($records), $blankLines, $physical,
                ));
            }

            return ['records' => $records, 'blankLines' => $blankLines];
        } finally {
            fclose($handle);
        }
    }

    /**
     * The whole validation pass, inside the caller's School context. Stages the rows, updates the
     * batch's counters, findings and status, and returns everything an operator report is rendered
     * from.
     *
     * THREE PHASES, and the order is the point. Rows are parsed first and held in memory; L1 is a
     * check on a STUDENT'S ROW-GROUP, so it cannot be decided while streaming one row at a time, and
     * a row cannot be written before its group's verdict is known. Only then is anything inserted.
     *
     * @param  list<array{line: int, values: array<string, string>}>  $records
     * @param  int  $blankLines  wholly blank physical lines the reader dropped — carried through so
     *                           read + blank reconciles to the physical file on the operator report
     */
    public function stage(
        OpeningBalanceBatch $batch,
        array $records,
        int $blankLines,
        Money $controlTotal,
    ): OpeningBalanceValidationResult {
        $roster = $this->enrollments->admissionNumberIndex();

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
        $tooLong = [];           // lines dropped because a value exceeds what its column can hold
        $refusedAtWrite = [];    // lines the ENGINE refused at the insert — the backstop's catch
        $matchedStudentIds = [];
        $staged = [];            // parsed rows awaiting their group's L1 verdict

        // ── PHASE 1 — per-row parsing and the row-level rules. Nothing is written yet. ──
        foreach ($records as $record) {
            // FIRST statement in the body, before every `continue` below and before any added
            // later. It is never conditioned on a row being valid, resolvable or parseable — the
            // moment it is, it stops being able to detect that a row went missing.
            //
            // It counts the reader's records, so it is "every data line CARRYING CONTENT", not
            // every physical line: read() drops wholly blank lines one frame up, deliberately
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

            // ── LENGTH, checked BEFORE anything tries to write. ──
            // A value longer than its storage column cannot be staged at all: MySQL answers an
            // over-length insert with 1406 and aborts the statement. So this row is DROPPED and
            // reported, exactly as an in-file duplicate is, rather than staged as rejected — there
            // is no row shape that could hold it. `mb_strlen`, not `strlen`, because a utf8mb4
            // varchar counts CHARACTERS and a byte count would reject a legitimate accented label.
            // The catch in the write loop remains the backstop for anything this misses.
            $overLength = [];
            foreach (self::COLUMNS as $column => $spec) {
                if (mb_strlen(trim($values[$column] ?? '')) > $spec['max']) {
                    $overLength[] = $column;
                }
            }
            if ($overLength !== []) {
                $tooLong[] = ['line' => $line, 'admission_number' => $key, 'columns' => $overLength];
                $skipReasons['value_too_long'] = ($skipReasons['value_too_long'] ?? 0) + 1;

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

            try {
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
            } catch (UniqueConstraintViolationException $e) {
                // THE TYPE IS NOT ENOUGH, AND A FUTURE READER MUST NOT WIDEN THIS BACK.
                // `finance_opening_balance_rows` carries TWO unique indexes: the fee-type key this
                // arm is about, and `..._uuid_unique` from the AddUuid concern. Both raise this same
                // exception class on this same insert. Converting on the class alone would report a
                // uuid collision — a defect in THIS SYSTEM's identifier generation — to the operator
                // as `duplicate_row_key_in_file`, which is a confident, wrong statement about their
                // file, and it would send them to look for a duplicate line that is not there.
                //
                // So the constraint is matched by NAME and anything else re-throws, the same shape
                // the 1406 arm has. Matching a name we own is not the message-text matching that arm
                // refuses: the prose around it ("Duplicate entry …") is localised and version-
                // dependent, while `ob_rows_school_batch_admission_fee_type_unique` is an identifier
                // this repository chose and a migration would have to rename deliberately.
                //
                // AND IT FAILS CLOSED, which is what a reader worried about a string match needs to
                // know: if MySQL's message shape changes or the index is renamed, the match MISSES,
                // the exception re-throws, and the run aborts. The failure of this comparison costs
                // an aborted run — never a wrong finding printed as fact about someone's file.
                if (! str_contains((string) ($e->errorInfo[2] ?? $e->getMessage()), self::ROW_KEY_INDEX)) {
                    throw $e;
                }

                // THE ENGINE CAUGHT A DUPLICATE THE IN-PHP PASS DID NOT — and §7 already rules that
                // two lines for one (student, fee type) are a fact about the FILE, not an error, so
                // this converts to the same finding the in-PHP pass would have produced and the run
                // CONTINUES. It used to abort here, leaving a committed batch whose own counters
                // said it had staged nothing while an arbitrary prefix of the file sat in the rows
                // table, and the §7 idempotency reference spent on a run nobody could read.
                //
                // The gap this closes is real and permanent: `fee_type_label` is utf8mb4_unicode_ci,
                // which folds case AND accents AND trailing spaces, while normaliseLabel() folds
                // case and trims. Every equivalence the collation has and the fold does not arrives
                // here.
                $refusedAtWrite[] = ['line' => $row['line'], 'admission_number' => $row['key'], 'code' => 'duplicate_row_key_in_file'];
                $skipReasons['duplicate_row_key_in_file'] = ($skipReasons['duplicate_row_key_in_file'] ?? 0) + 1;

                continue;
            } catch (QueryException $e) {
                // Classified by DRIVER CODE, never by matching the message text — a message is a
                // localised, version-dependent string and a guard that reads one is a guard that
                // silently stops matching. 1406 is "data too long for column".
                if ((int) ($e->errorInfo[1] ?? 0) !== 1406) {
                    // NOT swallowed. Anything unclassified aborts the run, which is the correct
                    // outcome for a failure nobody has decided the meaning of.
                    throw $e;
                }

                $refusedAtWrite[] = ['line' => $row['line'], 'admission_number' => $row['key'], 'code' => 'value_too_long'];
                $skipReasons['value_too_long'] = ($skipReasons['value_too_long'] ?? 0) + 1;

                continue;
            }

            $rowCount++;

            if ($isRejected) {
                $rejected[] = [
                    'line' => $row['line'],
                    'admission_number' => $row['key'],
                    'codes' => array_column($findings, 'code'),
                ];
            }
        }

        // The two not-staged classes, each reported ONCE with both of its sources counted. A caught
        // row and a dropped row are the same fact to the operator — the file has a line the batch
        // does not — so they are one finding, with the split named for whoever has to debug it.
        $engineDuplicates = $this->refusalsWithCode($refusedAtWrite, 'duplicate_row_key_in_file');
        $engineTooLong = $this->refusalsWithCode($refusedAtWrite, 'value_too_long');

        if ($duplicateInFile !== [] || $engineDuplicates !== []) {
            $batchFindings[] = $this->finding('duplicate_row_key_in_file', sprintf(
                '%d row(s) repeat an (admission number, fee type) already staged in this batch and were NOT staged '
                .'(%d caught while reading, %d refused by the unique index at the write — those spell one fee type '
                .'two ways in a manner the column collation folds and a case fold does not).',
                count($duplicateInFile) + count($engineDuplicates), count($duplicateInFile), count($engineDuplicates),
            ));
        }

        if ($tooLong !== [] || $engineTooLong !== []) {
            $batchFindings[] = $this->finding('value_too_long', sprintf(
                '%d row(s) carry a value longer than its column can hold and were NOT staged '
                .'(%d caught while reading, %d refused at the write). Correct the file; nothing is truncated.',
                count($tooLong) + count($engineTooLong), count($tooLong), count($engineTooLong),
            ));
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

        return new OpeningBalanceValidationResult(
            rowCount: $rowCount,
            fileRowCount: $fileRowCount,
            blankLines: $blankLines,
            rejected: $rejected,
            l1Failures: $l1Failures,
            unresolved: $unresolved,
            absent: $absent,
            duplicateInFile: $duplicateInFile,
            tooLong: $tooLong,
            refusedAtWrite: $refusedAtWrite,
            nullAdmissions: $nullAdmissions,
            duplicateAfterTrim: $duplicateAfterTrim,
            statedSum: $statedSum,
            controlTotal: $controlTotal,
            batchFindings: $batchFindings,
        );
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
     * THE RESIDUAL IS WIDER THAN THIS FOLD, and the earlier wording here understated it. This
     * function reproduces CASE and padding. `utf8mb4_unicode_ci` is the full UCA folding: it also
     * equates accents ('Tuición' = 'Tuicion'), expansions ('Straße' = 'Strasse') and everything else
     * the collation's tertiary weights ignore, and it is PAD SPACE. **Every equivalence it has and
     * this fold does not still reaches the INSERT**, where the unique index refuses it.
     *
     * That is not a correctness hole and it is no longer an aborted run either: the write loop
     * CATCHES the refusal and converts it into the same `duplicate_row_key_in_file` finding this
     * pass would have produced, counted in the same not-ingested accounting. The previous claim in
     * this docblock — "nothing is staged wrong either way" — was made without executing the case,
     * and executing it showed a committed batch reporting row_count=0 over a partially-written rows
     * table. The catch is what makes the sentence true; the fold is only what keeps the common case
     * out of the engine.
     */
    private function normaliseLabel(string $label): string
    {
        return mb_strtolower(trim($label));
    }

    /** @return array{code: string, message: string} */
    private function finding(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /**
     * @param  list<array{line: int, admission_number: string, code: string}>  $refusals
     * @return list<array{line: int, admission_number: string, code: string}>
     */
    private function refusalsWithCode(array $refusals, string $code): array
    {
        return array_values(array_filter($refusals, fn (array $r) => $r['code'] === $code));
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
