<?php

namespace App\Finance\Console;

use App\Finance\Actions\ApproveOpeningBalanceBatch;
use App\Finance\Actions\PostOpeningBalanceBatch;
use App\Finance\DTOs\OpeningBalanceValidationResult;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Services\OpeningBalanceFileValidator;
use App\Finance\Services\OpeningBalanceInterpretation;
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
 * refuses and exits non-zero rather than stubbing a posting path.
 *
 * THE POSTING PATH IS BUILT AND THIS COMMAND STILL CANNOT REACH IT — PERMANENTLY, NOT PENDING.
 * {@see PostOpeningBalanceBatch} landed in 4b and 4c gave it its production caller:
 * {@see ApproveOpeningBalanceBatch}, which posts a `submitted` batch once a
 * SECOND user has approved it (§8). So the refusal below is no longer waiting on anything. Posting is
 * not the terminal act of importing — it is what an approval does — and re-routing it to a console
 * flag would put a second, unapproved door onto the one irreversible write in this feature. What this
 * command does is stage a batch for a human to read and, once §9 step 5's screen lands, submit.
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
        {--closing-term= : the term being CLOSED OUT, whose closing position this file carries (terms.id)}
        {--as-at= : the cutover date D (Y-m-d)}
        {--control-total= : §1 L2 — Σ of every student stated total, read off WCBS and typed here}
        {--batch-reference= : §7 idempotency key; defaults to the CSV filename}
        {--dry-run : the ONLY mode this commit implements}';

    protected $description = 'READ-ONLY: validate a WCBS opening-balance extract into staging and report (§9 step 4a — posts nothing)';

    /**
     * THE FORMAT'S THREE CONSTANTS NOW LIVE ON {@see OpeningBalanceFileValidator}, and these are
     * ALIASES kept deliberately rather than a compatibility shim to be tidied away.
     *
     * §9 step 5b-iii moved the validation itself to a service so the operator screen's queued job and
     * this command reach the same verdict from ONE implementation. The constants went with it: they
     * describe the FILE FORMAT, and the format's owner is the thing that parses it, not the thing
     * that happens to have a console signature.
     *
     * They are re-exported here because `ImportOpeningBalances::COLUMNS` is what the template export
     * and two test files already name, and rewriting those references would have put a rename inside
     * a commit about a screen. A PHP constant expression may reference another class's constant, so
     * these ARE the same values, not copies — there is nothing here that can drift.
     */
    public const BATCH_REFERENCE_MAX = OpeningBalanceFileValidator::BATCH_REFERENCE_MAX;

    /** @var array<string, array{required: bool, max: int, format: string, example: string, notes: string, group: string}> */
    public const COLUMNS = OpeningBalanceFileValidator::COLUMNS;

    public const ROW_KEY_INDEX = OpeningBalanceFileValidator::ROW_KEY_INDEX;

    /**
     * How many identifiers a list prints before it is cut. Truncation is always announced.
     *
     * It did NOT move to the service with the three above, and the difference is the point: those
     * describe the FILE and every caller needs them, while this one describes how much of a list
     * fits on a terminal. The screen renders the same lists with no such limit.
     */
    private const LIST_LIMIT = 50;

    public function handle(OpeningBalanceFileValidator $validator): int
    {
        // The refusal comes FIRST, before any option is even read — commit 1's precedent and its
        // reasoning, unchanged by 4b or 4c: there is no posting path to reach FROM HERE, and a run that
        // got as far as opening a file before refusing would suggest there is.
        //
        // 4c BUILT THE GATE AND THE REFUSAL STILL STANDS. PostOpeningBalanceBatch now has a production
        // caller — ApproveOpeningBalanceBatch — so this is no longer "not implemented yet"; it is that
        // the ONE way to post is an approval by a second person, and a console flag beside it would be
        // an unapproved door onto a write that G1b makes permanent. A flag nobody is supposed to use is
        // weaker than no flag.
        if (! $this->option('dry-run')) {
            $this->error(
                'Posting is not reachable from this command, by design. An opening-balance batch posts '
                .'ONLY when a second user approves it (§8) — submit the validated batch for approval '
                .'instead. Re-run with --dry-run.'
            );

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
        return ActiveSchool::runFor($school->id, function () use ($school, $file, $cutoverDate, $controlTotal, $validator): int {
            $termId = $this->resolveTerm((string) $this->option('closing-term'), $school->id);
            if ($termId === null) {
                return self::FAILURE;
            }

            try {
                ['records' => $records, 'blankLines' => $blankLines] = $validator->read($file);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $reference = (string) ($this->option('batch-reference') ?: basename($file));

            // THE BATCH REFERENCE IS OPERATOR INPUT AND IT LANDS IN A POSTED PAYMENT'S payer_name.
            // It had no length rule at all: it comes from --batch-reference or, defaulted, from the
            // filename, and its own column holds 255 — so a long-but-legal reference staged green and
            // then aborted the post at 1406 on a varchar(255) payer_name. Refused HERE, before the
            // batch row is inserted, so a rejected reference does not spend §7's idempotency key on a
            // run nobody can read. Same reasoning as the label above: nothing is truncated, so the
            // refusal belongs at the file/options end.
            if (mb_strlen($reference) > self::BATCH_REFERENCE_MAX) {
                $this->error(sprintf(
                    'The batch reference is %d characters; the limit is %d. It is snapshotted onto every migrated payment at posting, so a longer one cannot be recorded. Pass a shorter --batch-reference.',
                    mb_strlen($reference),
                    self::BATCH_REFERENCE_MAX,
                ));

                return self::FAILURE;
            }

            // Inserted BEFORE a single row is validated, so §7's idempotency key is enforced by the
            // unique index at the engine rather than by a guard clause. A re-run of the same
            // reference throws 1062 here and never reaches the parse.
            $batch = OpeningBalanceBatch::create([
                'batch_reference' => $reference,
                'filename' => basename($file),
                'status' => OpeningBalanceBatchStatus::Draft,
                'cutover_date' => $cutoverDate,
                'term_id' => $termId,
                // §1 L2's witness, recorded HERE — at the batch insert, before a byte is parsed —
                // so it survives every outcome: a passing run, a rejected one, and a run that dies
                // partway. It is the operator's ATTESTATION, not a derived figure, and one kept only
                // when the check passes cannot be reviewed after a rejection, which is exactly when
                // someone wants to see what was claimed (§11's go/no-go).
                'control_total' => $controlTotal,
                'uploaded_by_user_id' => null, // a console run has no authenticated causer
            ]);

            return $this->report($batch, $validator->stage($batch, $records, $blankLines, $controlTotal));
        });
    }

    /**
     * The operator report. Counts, line numbers and admission numbers ONLY — no names, and no
     * per-student figures. The figures live in each staged row's `findings` JSON, which is what the
     * operator screen renders. The two L2 figures ARE printed: they are batch aggregates, not any
     * one student's position.
     *
     * IT TAKES THE RESULT OBJECT AND NOT SIXTEEN PARAMETERS, which is the only shape change §9 step
     * 5b-iii made to this method. The privacy rule is now a property of what
     * {@see OpeningBalanceValidationResult} is allowed to hold rather than of what this signature
     * happens to accept — see that class's docblock.
     */
    private function report(OpeningBalanceBatch $batch, OpeningBalanceValidationResult $r): int
    {
        $this->info("Batch [{$batch->batch_reference}] staged from [{$batch->filename}] — READ-ONLY, nothing was posted.");
        $this->table(['Measure', 'Count'], [
            // Read vs staged, adjacent on purpose: the pair IS the ingest-completeness control, and
            // a reader who sees only one of them cannot tell a complete batch from a short one.
            // The blank count sits with them so the three reconcile BY EYE against the file the
            // operator is holding: content + blank = physical data lines. `file_row_count` counts
            // lines WITH CONTENT, not physical lines — labelled as such rather than left to be
            // discovered when a trailing newline makes the numbers look wrong.
            ['data lines with content', $r->fileRowCount],
            ['blank lines skipped', $r->blankLines],
            ['rows staged', $r->rowCount],
            ['rejected rows', count($r->rejected)],
            ['students failing L1 (whole row-group rejected)', count($r->l1Failures)],
            ['file rows matching no student', count($r->unresolved)],
            ['students in School absent from the file', count($r->absent)],
            ['file rows dropped as duplicate (student, fee type)', count($r->duplicateInFile)],
            ['file rows dropped for an over-length value', count($r->tooLong)],
            ['file rows the engine refused at the write', count($r->refusedAtWrite)],
            ['School students with no admission number', $r->nullAdmissions],
            ['School admission numbers duplicated after trim', $r->duplicateAfterTrim],
        ]);

        // §1's L2, printed whether or not it failed. A check whose figures only appear when it fails
        // is one an operator cannot confirm ran.
        $this->line(sprintf(
            'L2 (kobo): Σ stated student totals=%d, --control-total=%d, Δ=%d',
            $r->statedSum->toKobo(), $r->controlTotal->toKobo(), $r->statedSum->toKobo() - $r->controlTotal->toKobo(),
        ));

        // WHAT THIS BATCH SAYS. Printed on EVERY run, clean or not, and printed after the arithmetic
        // rather than among the findings — it is not a defect report, it is the batch's own reading of
        // the file, in the words the sign convention was agreed in. It is the only control against an
        // inverted convention: L1 and L2 compare the file against itself and pass either way. The
        // operator screen prints the same sentence from the same service before approval.
        $interpretation = app(OpeningBalanceInterpretation::class)->for($batch);
        $this->line('');
        $this->line('WHAT THIS FILE SAYS — read it, and stop if it is wrong:');
        $this->line('  '.$interpretation['convention']);
        $this->line('  '.$interpretation['sentence']);
        $this->line('');

        foreach ($r->batchFindings as $finding) {
            $this->error("BATCH FINDING [{$finding['code']}] {$finding['message']}");
        }

        $this->printList('Rejected rows', array_map(
            fn (array $r) => "line {$r['line']} admission [{$r['admission_number']}]: ".implode(', ', $r['codes']),
            $r->rejected));
        $this->printList('Students failing L1 (every row of theirs is rejected)', array_map(
            fn (array $r) => "admission [{$r['admission_number']}]: {$r['code']}", $r->l1Failures));
        $this->printList('Rows dropped as duplicate (student, fee type)', array_map(
            fn (array $r) => "line {$r['line']} admission [{$r['admission_number']}] fee type [{$r['fee_type_label']}] first staged at line {$r['first']}",
            $r->duplicateInFile));
        $this->printList('Rows dropped for an over-length value', array_map(
            fn (array $r) => "line {$r['line']} admission [{$r['admission_number']}]: ".implode(', ', $r['columns']),
            $r->tooLong));
        // The engine's refusals are listed separately from the reader's drops on purpose: same
        // consequence for the file, different thing to look at. A row here means the in-PHP pass
        // and the column's collation disagree, which is a fact about THIS command, not the extract.
        $this->printList('Rows the engine refused at the write (the in-PHP pass did not catch them)', array_map(
            fn (array $r) => "line {$r['line']} admission [{$r['admission_number']}]: {$r['code']}",
            $r->refusedAtWrite));
        $this->printList('Students absent from the file (opening position would be zero)', $r->absent);

        if ($r->rejected === [] && $r->batchFindings === []) {
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
     * The term being CLOSED OUT, checked to exist AND to belong to the target School. Validated by
     * rule rather than by loading an Academics model: Finance does not import Academics' models
     * (arch rule 3), and the existing Finance precedent for naming `terms` is exactly this —
     * `exists:terms,id` in FeeScheduleRequest.
     *
     * §9's OPEN DECISION IS CLOSED — RULED IN 4b, THE FIRST COMMIT WITH A CALLER. The contradiction
     * was real: R5 puts the cutover on a term boundary, so §1 says there is no cutover term T, while
     * `batches.term_id` is NOT NULL with an FK. The ruling is REPURPOSE, NOT NULLIFY — the column
     * names the LAST term, whose closing position the file carries. The file IS a specific term's
     * closing position, and recording WHICH term is the provenance that lets a reader a year later
     * say what period the opening charges represent; nulling the column discards that, and the FK and
     * this per-School validation with it, for no gain. The option is renamed `--closing-term` and the
     * column carries the meaning as a COMMENT in the schema
     * (2026_08_08_110000_opening_balance_posting_state_and_guards.php) — a repurposed column whose
     * name, option and docs still describe the old meaning is a lie, which is why the rename is not
     * cosmetic.
     */
    private function resolveTerm(string $option, int $schoolId): ?int
    {
        $validator = Validator::make(['term' => $option], [
            'term' => ['required', 'integer', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
        ]);

        if ($validator->fails()) {
            $this->error("Invalid --closing-term [{$option}]: it must be a terms.id belonging to this School.");

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
}
