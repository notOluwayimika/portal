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

            foreach ($studentIds as $studentId) {
                $enrollment = $enrollments[$studentId] ?? null;

                ManualInvoiceRunTarget::create([
                    'school_id' => $schoolId,
                    'run_id' => $run->id,
                    'student_id' => $studentId,
                    'enrollment_id' => $enrollment instanceof BillableEnrollment ? $enrollment->enrollmentId : null,
                    'enrollment_uuid' => $enrollment instanceof BillableEnrollment ? $enrollment->enrollmentUuid : null,
                ]);
            }

            return $run;
        });
    }
}
