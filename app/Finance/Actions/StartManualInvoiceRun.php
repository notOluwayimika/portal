<?php

namespace App\Finance\Actions;

use App\Finance\Contracts\BillableEnrollment;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\ManualInvoiceRunStatus;
use App\Finance\Jobs\ProcessManualInvoiceRun;
use App\Finance\Models\ManualInvoiceRun;
use App\Finance\Models\ManualInvoiceRunLine;
use App\Finance\Models\ManualInvoiceRunTarget;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TURNS A SELECTION INTO A RUN — the run row, its lines, and one TARGET per selected student.
 *
 * Nothing here bills anybody. The job does that, and it is dispatched by the controller AFTER this
 * transaction has committed; see {@see ProcessManualInvoiceRun} for the
 * claim-then-bill ordering that makes a re-execution safe.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE RESOLVER IS THE ACL PORT. THERE IS NOT A SECOND ONE, AND THERE MUST NOT BE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `BillableEnrollmentProvider` is the ONLY thing that decides which episode a ticked student is
 * billed for. Finance may not import `StudentCurriculum` (arch rule 3), but the real reason is not
 * the lint: a second expression of "the student's current billable episode" is a second definition,
 * and this repository has already paid for exactly that twice — the adapter's own docblock records a
 * tie-break that was deleted from one of two copies while the other stayed green, and `CurrentTerm` /
 * `ResolvesTermFilter` are a live pair reading `order` and `id` as though they were interchangeable.
 *
 * IT IS CALLED ONCE FOR THE WHOLE LIST — `currentForStudents()`, not `currentForStudent()` in the
 * loop. The loop was the original shape and this docblock defended it on a number that was wrong:
 * it said "N queries for N ticked students", and MEASURED it is 8N. `currentForStudent()` carries
 * the adapter's five snapshot relation paths, which expand to seven eager loads on top of the root
 * select. Over 611 students on a copy of production data the loop cost 4888 queries / 1647 ms and
 * held this transaction open for ~2.6 s; the batch read costs 8 queries / 82.7 ms for the same 611.
 *
 * The rest of that defence — "the consumer is one bursar pressing one button over a list they typed
 * by hand, so the cost is bounded by what a person can tick" — is what stopped the batch being done,
 * and it was reasoning from a bound rather than from a measurement. It is gone. What it was right
 * about survives and is now satisfied rather than traded away: a batch read belongs ON THE PORT,
 * expressed through `billableEpisodes()`, and must not be inlined here. It is
 * {@see BillableEnrollmentProvider::currentForStudents()}, one `whereIn` away from the single-student
 * call, so there is still exactly ONE definition of a current billable episode.
 *
 * A STUDENT THE PORT CANNOT PLACE IS ABSENT FROM THE MAP, which is the batch spelling of the null
 * the single call returned. The `?? null` below is that translation and it is the only behavioural
 * seam between the two shapes; everything downstream of it is unchanged.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE TARGETS ARE WRITTEN IN BATCHES, WHICH MEANS NO MODEL EVENTS FIRE. READ THIS BEFORE EDITING
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `ManualInvoiceRunTarget::query()->insert()` goes to the query builder, so `creating` does not
 * fire and NEITHER TRAIT ON THAT MODEL DOES ITS WORK. That is the whole hazard of this shape: the
 * write succeeds, the row is there, and a column is simply empty. Both traits are accounted for:
 *
 *   `AddUuid` mints `uuid` in `creating` with `Str::orderedUuid()`. It is minted EXPLICITLY below,
 *   with the same call, so the column is never null and the values keep the ordered shape the rest
 *   of the table has. This is the one that would have gone wrong silently — `uuid` is the route key
 *   (`getRouteKeyName`), so an empty one is a row no URL can reach, discovered later and by a user.
 *
 *   `BelongsToSchool` fills `school_id` from the ambient School in `creating`. Nothing is lost: this
 *   Action takes the School as an ARGUMENT and has always written it onto every row explicitly, so
 *   the hook was never the thing supplying it here. It is also why the batch is a straight win —
 *   that hook calls `Schema::hasColumn()` — bootBelongsToSchool (app/Concerns/BelongsToSchool.php:21)
 *   — an UNCACHED `information_schema` query on EVERY model insert in this codebase, and skipping
 *   the event skips 611 of them on a 611-student run without touching a trait 47 models share. See
 *   docs/handoff/tickets/belongs-to-school-issues-a-schema-query-on-every-insert.md for the rest.
 *
 * TIMESTAMPS ARE SUPPLIED, NOT DEFAULTED. `insert()` does not fill `created_at` / `updated_at`, and
 * leaning on a column default would be the wrong repair: production is Percona 5.7.23 with
 * `explicit_defaults_for_timestamp` OFF, where the first `TIMESTAMP` column of a table silently
 * acquires `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` — a server-clock value in a
 * different frame from every timestamp this application writes, and an `ON UPDATE` that rewrites it
 * afterwards (docs/handoff/tickets/implicit-timestamp-defaults-on-rebuild.md).
 *
 * ORDER SURVIVES, AND IT HAD TO BE PROVED RATHER THAN ASSUMED. The job walks targets by id and the
 * report reads them back in the order the bursar submitted, so id order MUST follow payload order.
 * `array_chunk` preserves order, a multi-row `INSERT` assigns AUTO_INCREMENT in `VALUES` order, and
 * the chunks run sequentially inside one transaction — measured on 8.0.43 under
 * `innodb_autoinc_lock_mode = 2` (the interleaved default, which is where this would break if it
 * broke anywhere), with the chunk size forced low enough to span several statements. A concurrent
 * session can take ids between two chunks; that leaves GAPS, never a reordering.
 *
 * A REPEATED STUDENT IS STILL REFUSED, by `UNIQUE(school_id, run_id, student_id)`. What changed is
 * that the 1062 now fails the whole statement rather than the k-th `create()` — the same outcome,
 * since either way the transaction rolls the run back.
 *
 * RESOLUTION IS AN OUTCOME, NOT A PRECONDITION. A student the port cannot place becomes a target row
 * with `enrollment_id` NULL — which is what commit 1's re-key of the targets table bought, and it is
 * the whole reason `target_count` is what the bursar TICKED rather than what survived resolution. A
 * run that quietly skipped the unplaceable would report "90 of 90" on a selection of 96: balanced,
 * complete, and six families short, on a feature Brookstone ruled (30 August 2026) issues DIRECTLY
 * with no maker-checker and therefore with no second human to notice.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * ISOLATION
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `$schoolId` is an ARGUMENT and every row written here carries it. The guards are composite foreign
 * keys, not this method:
 *
 *   targets (student_id, school_id)    -> students (id, school_id)
 *   targets (enrollment_id, school_id) -> student_curricula (id, school_id)
 *   lines   (bank_account_id, school_id) -> finance_bank_accounts (id, school_id)
 *
 * so a foreign student, a foreign episode or a foreign destination account is UNREPRESENTABLE rather
 * than merely unlikely — measured on 8.0.43 in commit 1's report, including on a row whose
 * `enrollment_id` is NULL.
 *
 * THAT IS THE BACKSTOP AND NOT THE ENFORCEMENT, and the difference was measured rather than reasoned
 * about. The HTTP caller resolves student uuids under a School-scoped lookup, so a foreign id never
 * becomes an argument to this method — with `StoreManualInvoiceRunRequest`'s isolation rule removed,
 * the cross-School arm answered **201** over a silently SHORTER selection and no foreign row was
 * ever attempted. An FK refuses what is written; it cannot refuse what is dropped. What these keys
 * genuinely buy is a guarantee over ANY caller of this Action, including one that hands over raw ids
 * it did not resolve — and that is worth having, but it is a narrower claim than "the database
 * refuses a cross-School selection".
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * ONE TRANSACTION, AND THE DISPATCH IS OUTSIDE IT
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * A run whose targets or lines are half-written is a run that bills a subset and reports the wrong
 * denominator. All three tables commit together or none does.
 *
 * The dispatch is deliberately NOT in here. On the `sync` queue a dispatch inside the transaction
 * would run the job — and its invoice writes — inside a transaction that has not committed the run
 * it is reading, and on a real queue a worker can pick the job up before the commit lands. The
 * controller dispatches after this returns.
 *
 * A COLLIDING `active_run_key` SURFACES AS A `QueryException` FROM HERE, unhandled on purpose: the
 * translation into a 422 naming the run already in flight belongs to the controller, which is the
 * layer that has an HTTP response to give.
 */
final class StartManualInvoiceRun
{
    /** MySQL's two-byte parameter count: the most placeholders one prepared statement may carry. */
    private const PLACEHOLDER_BUDGET = 65535;

    public function __construct(
        private readonly BillableEnrollmentProvider $enrollments,
    ) {}

    /**
     * @param  list<int>  $studentIds  students.id, in the order the bursar submitted them
     * @param  list<array{description: string, amount: Money, bank_account_id: int, sort_order: int}>  $lines
     */
    public function handle(int $schoolId, array $studentIds, array $lines, ?int $actorId): ManualInvoiceRun
    {
        return DB::transaction(function () use ($schoolId, $studentIds, $lines, $actorId): ManualInvoiceRun {
            $run = ManualInvoiceRun::create([
                'school_id' => $schoolId,
                'status' => ManualInvoiceRunStatus::Pending,
                'started_by_user_id' => $actorId,
            ]);

            foreach ($lines as $line) {
                ManualInvoiceRunLine::create([
                    'school_id' => $schoolId,
                    'run_id' => $run->id,
                    'description' => $line['description'],
                    'amount' => $line['amount'],
                    'bank_account_id' => $line['bank_account_id'],
                    'sort_order' => $line['sort_order'],
                ]);
            }

            // ONE read for the whole selection. Placeable students only; the rest are absent, which
            // is what the `?? null` below turns back into the NULL enrollment_id a target carries.
            $enrollments = $this->enrollments->currentForStudents($studentIds);

            // ONE timestamp for the whole batch, and it is the same instant on every row. Eloquent
            // would have stamped each row at its own `freshTimestamp()`; a run's targets are written
            // by one act and reading them back sorted by `created_at` should not depend on how long
            // the loop took.
            $now = now();

            $rows = [];

            foreach ($studentIds as $studentId) {
                $enrollment = $enrollments[$studentId] ?? null;

                $rows[] = [
                    // MINTED HERE BECAUSE THE EVENT THAT USED TO MINT IT DOES NOT FIRE — see the
                    // class docblock. Same call as `AddUuid`'s, so the values keep the same ordered
                    // shape rather than becoming random v4s on this one table.
                    'uuid' => (string) Str::orderedUuid(),
                    'school_id' => $schoolId,
                    'run_id' => $run->id,
                    'student_id' => $studentId,
                    'enrollment_id' => $enrollment instanceof BillableEnrollment ? $enrollment->enrollmentId : null,
                    'enrollment_uuid' => $enrollment instanceof BillableEnrollment ? $enrollment->enrollmentUuid : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, $this->targetChunkSize($rows)) as $chunk) {
                ManualInvoiceRunTarget::query()->insert($chunk);
            }

            return $run;
        });
    }

    /**
     * Rows per `INSERT`, derived rather than chosen.
     *
     * THE CEILING IS THE PROTOCOL, AND IT WAS MEASURED RATHER THAN READ. MySQL encodes a prepared
     * statement's parameter count in two bytes, so one statement carries at most 65,535 placeholders.
     * Probed on 8.0.43 against a temporary table of this row's exact shape: 8,191 eight-column rows
     * (65,528 placeholders) are ACCEPTED and 8,192 are REFUSED with
     * `1390 Prepared statement contains too many placeholders`. The boundary is exact and it is a
     * hard error, not a truncation — which is why a number is picked below it rather than at it.
     *
     * THE DIVISOR IS THE ROW'S OWN WIDTH, taken from the row instead of written down, so the ceiling
     * cannot drift the day a column is added to `finance_manual_invoice_run_targets`. A constant
     * would have been the same number today and silently wrong then.
     *
     * THE HALVING IS THE MARGIN, and it is the packet ceiling it buys room against. Measured on the
     * real statement, a target row costs 160.2 bytes on the wire (26.2 of SQL text plus 134 of bound
     * values), so 4,095 rows is ~656 KB. This developer machine reports `max_allowed_packet` = 64 MiB
     * (the 8.0 default); PRODUCTION IS PERCONA 5.7.23, whose default is 4 MiB, and that is the number
     * this has to be safe against — 656 KB is ~16 % of it, and a row twice as wide would halve the
     * chunk rather than double the statement. The halving costs one extra round trip per 4,095
     * students, which is nothing against a cohort a school actually has.
     *
     * ORDER SURVIVES THE CHUNKING, and that was measured too rather than assumed — see the class
     * docblock.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function targetChunkSize(array $rows): int
    {
        if ($rows === []) {
            return 1;
        }

        return max(1, intdiv(self::PLACEHOLDER_BUDGET, count($rows[0]) * 2));
    }
}
