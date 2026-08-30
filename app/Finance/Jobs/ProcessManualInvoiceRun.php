<?php

namespace App\Finance\Jobs;

use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Enums\ManualInvoiceRunOutcome;
use App\Finance\Enums\ManualInvoiceRunStatus;
use App\Finance\Models\ManualInvoiceRun;
use App\Finance\Models\ManualInvoiceRunLine;
use App\Finance\Models\ManualInvoiceRunRow;
use App\Finance\Models\ManualInvoiceRunTarget;
use App\Jobs\Middleware\SchoolAware;
use App\Models\User;
use App\Support\ActiveSchool;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\CauserResolver;
use Throwable;

/**
 * THE MANUAL INVOICE RUN — a bursar's own list of enrollments, one SUPPLEMENTARY invoice each, from
 * lines the operator typed. Slice 1 of docs/handoff/bulk-manual-invoicing-brief.md.
 *
 * NOTHING DISPATCHES THIS YET. The screen, the route and the student-to-enrollment resolver are the
 * second commit; the only caller today is the test suite, deliberately.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * CLAIM, THEN BILL — AND THIS IS THE ONLY REASON THE JOB IS A SIBLING RATHER THAN A FLAG
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *
 * {@see ProcessBulkInvoiceRun} bills first and records after: the invoice is created at `:446` and
 * the row that records it is written at `:593`. So `UNIQUE(school_id, run_id, enrollment_id)` on that
 * table sits DOWNSTREAM OF THE MONEY. On a re-execution the invoice commits, the row insert then
 * collides with 1062, and `attempt()` (`:386`) only LOGS it — leaving a duplicate invoice that NO ROW
 * RECORDS, which also breaks that run's own cohort equality. `tries = 1` (`:147`) is the only thing
 * that has kept it theoretical, and it is one flag.
 *
 * The scheduled run survives that because `UNIQUE(school_id, active_enrollment_key)` on
 * `finance_invoices` refuses the second SCHEDULED invoice per episode anyway. **This path has no such
 * backstop and cannot be given one.** A supplementary invoice computes NULL for that generated
 * column, NULLs do not collide, and unbounded supplementary invoices are the intended semantics —
 * there is no natural uniqueness key to add
 * (docs/handoff/tickets/a-supplementary-invoice-has-no-duplicate-backstop.md, proved positively at
 * `SupplementaryInvoiceWireTest:217-218`: two raw identical supplementary inserts, both driver code
 * NULL). That ticket priced its exposure as "one student's balance". Over a list of ninety it is
 * ninety duplicate charges, each recoverable only by its own two-signature void.
 *
 * So the order is inverted here, and ONLY here:
 *
 *   1. INSERT the row as a CLAIM (`outcome = claimed`), in its own committed write.
 *   2. The unique index refuses a second claim BEFORE any invoice exists.
 *   3. Bill.
 *   4. UPDATE the row with the real outcome.
 *
 * THE SCHEDULED JOB IS DELIBERATELY UNTOUCHED. It issues Term 1's bills on 5 September 2026 and has
 * never run on production; inverting its write ordering days before its first real execution is risk
 * it does not need and is already protected against.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT THIS BUYS AND WHAT IT COSTS, STATED AS A TRADE
 *
 * A death between step 1 and step 4 leaves the row `claimed` forever. That enrollment is not billed,
 * `tries = 1` means nothing retries it, and THERE IS NO SWEEPER — a human has to look. It is
 * nonetheless strictly better than what it replaces:
 *
 *   BEFORE  an invoice with no row: money on a family's balance, absent from the run's counts,
 *           reported by nothing, and turned into a SECOND charge by any re-execution.
 *   AFTER   a row with no invoice: nobody charged, the enrollment named, and
 *           `billed + failed < target_count` — the run's own alarm, red.
 *
 * A visible unknown in place of a silent double charge. A reviewer meeting a stuck `claimed` row is
 * meeting the intended failure mode, not a bug.
 *
 * A NOTE ON WHERE THE CLAIM COMMITS. Step 1 is its own statement outside any transaction this job
 * opens; {@see GenerateInvoice} opens its own. If a CALLER ever wrapped `handle()` in a transaction,
 * the claim and the invoice would commit together — which changes the failure mode but not for the
 * worse, because the invoice then cannot commit alone either. The unique index still refuses the
 * duplicate claim inside a transaction, so the guard itself is unaffected.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE RUN'S ONLY SELF-CHECK, AND THE TERM THAT IS DELIBERATELY MISSING FROM IT
 *
 *     billed_count + failed_count + unplaceable_count == target_count
 *
 * `target_count` is the size of the target list WALKED — and, because a target is keyed on the
 * STUDENT, it is what the bursar TICKED rather than what survived resolution. The three counts are
 * counted from the rows PERSISTED. Two independent sources — the only reason the equality can fail
 * and therefore the only reason asserting it is worth anything.
 *
 * `unplaceable_count` IS A TERM; `claimed_count` IS NOT. The line between them is whether anything is
 * unknown: a student with no current billable enrollment is a finished, correct, reported outcome,
 * so leaving them off the left would fire the alarm on a healthy run. A `claimed` row is a run that
 * does not know what happened, so it is recorded BESIDE the equality as the diagnosis — adding it to
 * the left balances the sum on precisely the runs the sum exists to catch. Same discipline as the
 * scheduled run's, and the same refusal to add a flag the job sets.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `tries = 1`, AND HERE IT IS A BELT RATHER THAN THE BRACE. On the scheduled path it is the whole
 * defence against re-execution; here the claim index is, and `tries = 1` merely keeps a retried job
 * from spending its time collecting 1062s. It is kept for a second reason: a retry after a partial
 * run would re-walk targets that already have `billed` rows, and every one of those attempts is a
 * refusal the log has to carry.
 */
class ProcessManualInvoiceRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $runId,
        public readonly int $schoolId,
    ) {}

    public function middleware(): array
    {
        return [new SchoolAware];
    }

    /**
     * THE QUEUE'S OWN TERMINAL HOOK — the only writer for the deaths `handle()` cannot see, and the
     * same shape and the same reasoning as {@see ProcessBulkInvoiceRun::failed()}.
     *
     * IT ESTABLISHES ITS OWN School CONTEXT. Job middleware does NOT wrap `failed()`, so `SchoolAware`
     * has not run here, and `ManualInvoiceRun` is not in `rbac.fail_closed_models` — its `SchoolScope`
     * would read UNSCOPED rather than refuse. Resting a write on that fail-open behaviour is what
     * Constitution 13 forbids, so the School the job carries is named explicitly.
     *
     * IT REFUSES TO OVERWRITE A TERMINAL STATE, and here that matters more than it does on the
     * scheduled path: `status` is what drives `active_run_key`, so rewriting a `completed` run back
     * to `failed` is harmless to the guard but rewriting the REPORT of a run that finished is not.
     *
     * WHAT IT DOES NOT COVER, and this is not a caveat to skim: a SIGKILL, an OOM kill or the machine
     * going away leave the run in `running` AND leave the last claim `claimed`, because nothing in
     * this process runs afterwards. The run then also holds `active_run_key`, so the School cannot
     * start another manual run until a human resolves it — which is the correct direction to fail,
     * and is stated here because someone will meet it as "the button is stuck".
     */
    public function failed(Throwable $e): void
    {
        ActiveSchool::runFor($this->schoolId, function () use ($e) {
            $run = ManualInvoiceRun::find($this->runId);

            if (! $run instanceof ManualInvoiceRun) {
                return;
            }

            if ($run->status === ManualInvoiceRunStatus::Completed || $run->status === ManualInvoiceRunStatus::Failed) {
                return;
            }

            $this->writeFailure(
                $run->id,
                'The run did not finish: the worker died before it could report. Invoices already '
                .'raised have been kept. Read the rows before re-running — anything still marked '
                .'claimed was not billed, and anything marked billed WILL be billed again. ('.$e->getMessage().')'
            );
        });
    }

    public function handle(GenerateInvoice $generate): void
    {
        $run = ManualInvoiceRun::find($this->runId);

        if (! $run instanceof ManualInvoiceRun) {
            Log::error('ProcessManualInvoiceRun: run not found', ['id' => $this->runId, 'school_id' => $this->schoolId]);

            return;
        }

        // Audit attribution only — never an execution identity (Constitution 13).
        $causer = $run->started_by_user_id === null ? null : User::find($run->started_by_user_id);
        if ($causer instanceof User) {
            app(CauserResolver::class)->setCauser($causer);
        }

        try {
            $this->process($run, $generate);
        } catch (Throwable $e) {
            // A fact about US, not about their list. `tries = 1`, so a rethrow would leave the run in
            // `running` with nothing said, a screen polling it forever, AND `active_run_key` held —
            // which would block every future manual run for this School.
            Log::error('ProcessManualInvoiceRun: failed', [
                'run_id' => $run->id, 'school_id' => $this->schoolId, 'error' => $e->getMessage(),
            ]);

            $this->failRun($run, 'The run did not finish. This is a fault in the portal, not in the '
                .'list or the lines: '.$e->getMessage());
        } finally {
            app(CauserResolver::class)->setCauser(null);
        }
    }

    /**
     * BOTH PER-RUN CONDITIONS ARE SETTLED BEFORE THE FIRST CLAIM, which is what keeps the property
     * every per-run refusal on the scheduled path has: zero rows exist and nothing was billed.
     *
     * A run with no lines and a run with no targets are both caller defects rather than domain cases
     * — the second commit's controller writes all three tables in one transaction — but they are
     * REFUSED here rather than assumed away, because a run with no lines would otherwise claim every
     * target and then fail every one of them at the Action, turning a caller's mistake into a list of
     * per-student failures that reads like an outage.
     */
    private function process(ManualInvoiceRun $run, GenerateInvoice $generate): void
    {
        $run->update(['status' => ManualInvoiceRunStatus::Running, 'started_at' => now()]);

        $lines = $this->lines($run);

        if ($lines === []) {
            $this->failRun($run, 'This run has no lines, so there is nothing to bill. A manual run '
                .'carries the lines the operator typed; without them no invoice can be raised.');

            return;
        }

        $targets = ManualInvoiceRunTarget::query()
            ->where('run_id', $run->id)
            ->orderBy('id')
            ->get();

        if ($targets->isEmpty()) {
            $this->failRun($run, 'This run has no targets, so there is nobody to bill.');

            return;
        }

        foreach ($targets as $target) {
            // A TARGET THAT RESOLVED TO NOTHING IS RECORDED IN ONE WRITE AND NEVER CLAIMED. The
            // claim exists to bracket a call that moves money and there is none here, so there is no
            // window to protect and no second write that could strand the row — an `unplaceable`
            // target cannot become a stuck claim. The unique index still refuses a second one.
            $this->attempt($run, $target, fn () => $target->enrollment_id === null
                ? $this->recordUnplaceable($run, $target)
                : $this->claimThenBill($run, $target, $lines, $generate));
        }

        $this->reconcile($run, $targets->count());
    }

    /**
     * ONE ENROLLMENT'S WHOLE UNIT OF WORK. FOUR STEPS, AND THE ORDER IS THE FEATURE.
     *
     * STEP 1 IS THE GUARD. The INSERT is what `UNIQUE(school_id, run_id, enrollment_id)` refuses on a
     * re-execution, and it happens before {@see GenerateInvoice} is reached — so the 1062 arrives
     * while there is still nothing to undo. It propagates out of here to {@see attempt()}, which is
     * the one place that decides what an unwritable row means; catching it here would be a second
     * place deciding the same thing.
     *
     * STEP 4 IS TWO WRITES AND NOT ONE, deliberately. The failure branch writes `reason` and the
     * success branch writes `invoice_id`; neither writes the other's column, so a `failed` row can
     * never carry an invoice id and a `billed` row can never carry a reason. That is the same
     * property the scheduled run's `record()` gets from having one writer with two nullable
     * arguments, reached the other way because this row already exists by the time we know which it
     * is.
     *
     * A FAILURE OF STEP 4 ITSELF LEAVES THE ROW `claimed`, and that is correct rather than
     * unfortunate: the run genuinely does not know what happened to that enrollment, and the equality
     * says so. It is the one case where the row's state and the truth are the same kind of uncertain.
     *
     * `school_id` IS STATED EXPLICITLY rather than left to BelongsToSchool's creating-fill, the same
     * decision and the same reason as `ProcessBulkInvoiceRun::record()`: the run's School is the job's
     * declared argument, and a row that took it from ambient context would be trusting the very thing
     * `SchoolAware` exists to set.
     *
     * @param  list<InvoiceLineSpec>  $lines  the run's SHARED lines — one set for the whole list.
     */
    private function claimThenBill(
        ManualInvoiceRun $run,
        ManualInvoiceRunTarget $target,
        array $lines,
        GenerateInvoice $generate,
    ): void {
        // ── 1. THE CLAIM ─────────────────────────────────────────────────────────────────────
        $row = ManualInvoiceRunRow::create([
            'school_id' => $this->schoolId,
            'run_id' => $run->id,
            'student_id' => $target->student_id,
            'enrollment_id' => $target->enrollment_id,
            'enrollment_uuid' => $target->enrollment_uuid,
            'outcome' => ManualInvoiceRunOutcome::Claimed,
        ]);

        // ── 2. THE BILL ──────────────────────────────────────────────────────────────────────
        try {
            $invoice = $generate->handle(
                $target->enrollment_uuid,
                $lines,
                InvoiceKind::Supplementary,
                $run->started_by_user_id,
            );
        } catch (Throwable $e) {
            // NO RE-INTERROGATION OF A RACE, unlike the scheduled path. There, a refusal might be the
            // per-episode unique index and the invoice that won the race is the right answer to
            // record. Here there is no such index and no such race: a supplementary invoice is never
            // refused for already existing, so every throw is a genuine failure of THIS attempt.
            $row->update([
                'outcome' => ManualInvoiceRunOutcome::Failed,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        // ── 3. THE OUTCOME ───────────────────────────────────────────────────────────────────
        $row->update([
            'outcome' => ManualInvoiceRunOutcome::Billed,
            'invoice_id' => $invoice->id,
        ]);
    }

    /**
     * A TICKED STUDENT WHO RESOLVED TO NO CURRENT BILLABLE ENROLLMENT. One INSERT, terminal
     * immediately, `enrollment_id` and `enrollment_uuid` and `invoice_id` and `reason` all NULL.
     *
     * IT IS RECORDED RATHER THAN DROPPED, which is the whole reason the target table is keyed on the
     * student: brief §2 requires the unresolved be reported, and a bursar who ticked ninety and
     * billed eighty-four must be told WHICH six and why, on the run report — not left to count. This
     * feature issues directly with no maker-checker, so there is no second human who would notice.
     *
     * NO CLAIM PRECEDES IT. See the call site: the claim brackets a money-moving call and there is
     * none here, so a second write that could strand this row does not exist.
     *
     * IT IS A TERM OF THE COHORT EQUALITY, unlike a claim — nothing about it is unknown.
     */
    private function recordUnplaceable(ManualInvoiceRun $run, ManualInvoiceRunTarget $target): void
    {
        ManualInvoiceRunRow::create([
            'school_id' => $this->schoolId,
            'run_id' => $run->id,
            'student_id' => $target->student_id,
            'enrollment_id' => null,
            'enrollment_uuid' => null,
            'outcome' => ManualInvoiceRunOutcome::Unplaceable,
        ]);
    }

    /**
     * ONE ENROLLMENT MUST NOT TAKE THE RUN DOWN — the same ruling
     * {@see ProcessBulkInvoiceRun::attempt()} makes, for the same reason, and it is what catches the
     * claim's own 1062.
     *
     * A CLAIM REFUSED BY THE UNIQUE INDEX ARRIVES HERE, and arriving here is the correct outcome for
     * it: the refusal means this run already has a row for this enrollment, so the work is either
     * done or already claimed, and there is nothing to do and nothing to record. The log line is the
     * whole report, because the row that would carry a reason is the row that could not be written.
     *
     * IT IS NOT SILENT. An enrollment whose row could not be written at all is missing from both
     * counts, so `billed + failed < target_count` and the run's own alarm is red. There is
     * deliberately no extra flag saying "something went wrong": a flag the job sets is a flag the job
     * can forget to set, whereas the equality is over numbers counted from two independent sources.
     */
    private function attempt(ManualInvoiceRun $run, ManualInvoiceRunTarget $target, Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::error('ProcessManualInvoiceRun: could not complete one enrollment', [
                'run_id' => $run->id,
                'school_id' => $this->schoolId,
                'enrollment_id' => $target->enrollment_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * THE RUN'S LINES, mapped once for the whole list — the manual run's counterpart to the scheduled
     * run's single call to `FeeScheduleLineMapper::linesFor()`.
     *
     * EVERY LINE IS A CHARGE AND EVERY LINE NAMES A DESTINATION. There are no reductions here: a
     * scholarship covers termly fees and does not apply to a mid-term charge
     * (scholarship-and-cutover-decisions.md §11), and {@see GenerateInvoice} contains no reference to
     * `StudentDiscountAward` for exactly that reason. `bank_account_id` is NOT NULL on the table, so
     * S11's required-destination rule is satisfied structurally rather than by this method
     * remembering to pass it.
     *
     * @return list<InvoiceLineSpec>
     */
    private function lines(ManualInvoiceRun $run): array
    {
        return ManualInvoiceRunLine::query()
            ->where('run_id', $run->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ManualInvoiceRunLine $line) => new InvoiceLineSpec(
                description: $line->description,
                amount: $line->amount,
                kind: InvoiceLineKind::Charge,
                bankAccountId: $line->bank_account_id,
            ))
            ->all();
    }

    /**
     * THE CLOSING WRITE — the counts, and the word on the run.
     *
     * THE COUNTS ARE RE-DERIVED FROM THE ROWS ACTUALLY PERSISTED, never from an in-memory tally: a
     * tally says what the job believes it did and could not disagree with itself. `target_count` comes
     * from the other side — the size of the list walked — which is what makes the equality
     * `billed + failed + unplaceable == target_count` a comparison of two sources rather than a
     * restatement. And because a target is keyed on the STUDENT, `target_count` is the number the
     * bursar ticked rather than the number that survived resolution.
     *
     * `unplaceable_count` IS A TERM AND `claimed_count` IS NOT. An unplaceable student is finished
     * and correct — nothing about them is unknown — so leaving them off the left would fire the
     * alarm on every healthy run that has one, which is how an alarm gets learned-around. A claim is
     * the opposite, so `claimed_count` is written as the DIAGNOSIS: it is exactly the shortfall, so a
     * screen can name which students are unresolved instead of only reporting that some are. Adding
     * it to the left-hand side is how this alarm gets switched off while appearing to be completed.
     *
     * THE NOBODY-BILLED RULE, inherited from {@see ProcessBulkInvoiceRun::reconcile()} and it is a
     * HEURISTIC ABOUT SHAPE, not a diagnosis. A run over a non-empty list where every single target
     * failed is recorded as `failed`, not `completed`: the per-enrollment catch that lets one bad
     * episode be survived is also what lets a total outage wear the costume of N ordinary per-student
     * failures, and "Completed — 0 billed, 40 failed" is a green word over an outage.
     *
     * `$failed === $targetCount` IS LOAD-BEARING BESIDE `$billed === 0`, and the case that needs both
     * is a re-execution over targets that are ALL stranded in `claimed`: every claim is refused, no
     * row moves, and the counts read `billed = 0, failed = 0`. That is not an outage — it is the
     * guard doing its job over a run that already died once — so the rule must stay silent on it,
     * and `failed === targetCount` is what keeps it silent. The short equality reports that run
     * instead, which is the honest place for it.
     *
     * A RE-EXECUTION OF A COMPLETED RUN IS NOT THAT CASE, and the difference is worth stating because
     * it looks like it should be. The counts are re-derived from the rows PERSISTED, so the `billed`
     * rows the first pass wrote are still counted: the run reports itself, not the pass, and the
     * equality still balances.
     *
     * `$failed === $targetCount` ALSO KEEPS UNPLACEABLE STUDENTS OUT OF THE RULE, without a clause of
     * its own — an unplaceable row is not a failed row, so a selection where nobody could be placed
     * has `failed = 0`, the equality does not hold, and the rule stays silent on a run that correctly
     * billed nobody. That is the right answer and it is worth stating because the rule reads as
     * "billed nothing" and is in fact "every target failed". Exactly the property
     * `ProcessBulkInvoiceRun::reconcile()` gets from sponsored rows, reached the same way.
     *
     * WHAT IT DOES NOT CATCH, stated so nobody mistakes it for a health check: a PARTIAL outage (half
     * the list failing still reports `completed`, and that is the realistic shape of a flaky
     * connection), and an outage that lands after the loop (that throws, and is a run-level failure by
     * the ordinary path).
     */
    private function reconcile(ManualInvoiceRun $run, int $targetCount): void
    {
        $counts = ManualInvoiceRunRow::query()
            ->where('run_id', $run->id)
            ->selectRaw('outcome, COUNT(*) AS total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $of = fn (ManualInvoiceRunOutcome $outcome): int => (int) ($counts[$outcome->value] ?? 0);

        $billed = $of(ManualInvoiceRunOutcome::Billed);
        $failed = $of(ManualInvoiceRunOutcome::Failed);
        $unplaceable = $of(ManualInvoiceRunOutcome::Unplaceable);
        $claimed = $of(ManualInvoiceRunOutcome::Claimed);

        $nobodyBilled = $targetCount > 0 && $billed === 0 && $failed === $targetCount;

        $run->update([
            'status' => $nobodyBilled ? ManualInvoiceRunStatus::Failed : ManualInvoiceRunStatus::Completed,
            'finished_at' => now(),
            'failure_reason' => $nobodyBilled
                ? 'Every one of the '.$targetCount.' enrollments on this list failed and none was '
                    .'billed. That is far more likely to be a fault in the portal than a fault in '
                    .$targetCount.' separate enrollments — read the rows for the repeated reason. '
                    .'RE-RUNNING IS NOT FREE: a supplementary invoice has no duplicate backstop, so '
                    .'a second run over the same list bills again anyone the first run billed.'
                : null,

            'target_count' => $targetCount,
            'billed_count' => $billed,
            'failed_count' => $failed,
            'unplaceable_count' => $unplaceable,
            'claimed_count' => $claimed,
        ]);
    }

    /**
     * A PER-RUN failure. An enrollment that could not be billed keeps its reason on its row and never
     * reaches here.
     *
     * NOTHING IT WRITES COMES FROM THE MODEL'S ATTRIBUTE BAG — see
     * {@see ProcessBulkInvoiceRun::failRun()} for the two states that discipline was paid for.
     * `Model::update()` is `fill()` then `save()`, so a refused write leaves its payload sitting dirty
     * on the model and the next `update()` flushes it; naming the columns in a query-builder update
     * makes inheriting a figure unrepresentable rather than merely unlikely.
     */
    private function failRun(ManualInvoiceRun $run, string $reason): void
    {
        $this->writeFailure($run->id, $reason);

        // Bring the in-memory model back in step with the row, discarding whatever a refused write
        // left on it. Callers and tests holding this instance must see what is actually stored.
        $run->refresh();
    }

    /**
     * Mark ONE run failed, on a FRESH builder, reading nothing from any model instance.
     *
     * THE `WHERE` IS NOT JUST THE KEY: `ManualInvoiceRun` carries `BelongsToSchool`, so `SchoolScope`
     * adds `school_id = <active school>` beside `whereKey()`. Under `SchoolAware` that is the job's
     * own School, and in {@see failed()} it is the one that method sets for itself, so the extra
     * predicate can only ever match the row already named by its primary key.
     *
     * `status` is passed as its backed VALUE: an Eloquent mass update does not run casts. The status
     * column's BEFORE UPDATE trigger fires on this write exactly as on a model save — the guard is on
     * the table, not on the ORM — and `active_run_key` is recomputed by the engine, releasing the
     * School's one-active-run slot.
     */
    private function writeFailure(int $runId, string $reason): void
    {
        ManualInvoiceRun::query()->whereKey($runId)->update([
            'status' => ManualInvoiceRunStatus::Failed->value,
            'finished_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
