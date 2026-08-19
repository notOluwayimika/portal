<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\BulkInvoiceRunStatus;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use App\Finance\Services\FeeScheduleLineMapper;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One bulk invoice run (U6 commit 3): "raise the term bill for JSS 1, first term", and what became
 * of every billable student in the School while that happened.
 *
 * IT IS THE JOB RECORD, following {@see OpeningBalanceBatch} — the row is
 * inserted in `pending` before {@see ProcessBulkInvoiceRun} is dispatched, and the
 * job moves it. No `Import` row, no second table tracking the queue: the record of what was asked
 * for IS the record of what happened.
 *
 * IT PINS ONE FEE SCHEDULE FOR THE WHOLE RUN. `fee_schedule_id` is written by the job when it
 * resolves the active schedule at (term, class level), and every invoice the run raises comes from
 * that one version's lines. Re-resolving per student would split a cohort across two price lists if
 * an approval or a supersession landed mid-run — which is why
 * {@see FeeScheduleLineMapper::linesFor()} takes a FeeSchedule and not a pair
 * of coordinates.
 *
 * THE COUNTS ARE THE REPORT; `status` IS ONLY WHETHER THE REPORT IS FINISHED. A `completed` run may
 * have billed nobody. See {@see BulkInvoiceRunStatus}.
 *
 * TWO KINDS OF FIGURE, AND CONFLATING THEM IS THE MISTAKE THIS SHAPE EXISTS TO PREVENT.
 *
 * 1. THE RUN'S OWN ACCOUNTING — EXACT, and the defect signal. TWO LISTS ARE WALKED, SO THERE ARE
 *    TWO EQUALITIES:
 *
 *        billed_count + already_billed_count + failed_count == cohort_count
 *        unplaceable_count                                  == unplaceable_listed_count
 *
 *    On each line the right-hand side is the size of a list the run WALKED and the left is counted
 *    from the rows it PERSISTED, so the two sides have independent sources and either equality can
 *    genuinely fail. Both fail in exactly one way: a per-student row that could not be written (see
 *    ProcessBulkInvoiceRun::attempt(), which rules that such a row is a per-student fault and lets
 *    the run carry on). There is no separate "something went wrong" flag, because a flag the job
 *    sets is a flag the job can forget to set.
 *
 *    THE SECOND EQUALITY WAS MISSING until cold review measured its absence: one list had an alarm
 *    and the other did not, so a blocked unplaceable row left everything green and quietly moved
 *    into the residual below.
 *
 * 2. THE SCHOOL-WIDE FIGURE — `outside_coordinates_count`, which is NOT a defect signal:
 *
 *        outside_coordinates_count = billable_count − cohort_count − unplaceable_listed_count
 *
 *    BOTH SUBTRAHENDS ARE LIST SIZES. The first version subtracted the unplaceable ROW count, so a
 *    lost unplaceable row inflated this figure by one — a defect moving out of an alarm and into a
 *    residual that is large and unalarming by design, which is the exact movement this figure is
 *    shaped to prevent.
 *
 *    Billable students this run did not enumerate because they are priced at other coordinates. On
 *    a seven-class-level school a healthy single-level run leaves roughly six-sevenths of the
 *    roster here, EVERY TIME. It was called `unaccounted_count` and the project lead ruled the name
 *    out: a screen rendering it as "N children were missed" would be wrong on every good run and
 *    would teach an operator to ignore the number that matters.
 *
 *    AND IT UNDER-REPORTS BY A KNOWN AMOUNT: `billableEpisodes()` groups by `student_id` and SQL
 *    collapses every NULL into ONE group, so N student-less episodes contribute 1 between them and
 *    `billable_count` is short by N − 1 whenever N > 1
 *    (docs/handoff/tickets/student-less-episodes-under-report-the-billable-population.md).
 *
 * BOTH ARE WRITTEN AT RUN TIME. A screen computing either when it opens would describe a roster
 * that has since moved, and would report a number about a School that was never the one billed.
 *
 * NO MONEY COLUMN, deliberately — see the migration for why a `total_billed` here would be an
 * unreconciled second copy of what the invoices already say.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $term_id
 * @property int $class_level_id
 * @property int|null $fee_schedule_id the version READ; NULL only when no active schedule existed at the coordinates
 * @property BulkInvoiceRunStatus $status
 * @property int|null $started_by_user_id LOOKUP, not an FK
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $failure_reason PER-RUN only — a student who could not be billed carries its own reason on its row
 * @property int|null $cohort_count how many enrollments listForCohort() returned; NULL until the run finishes
 * @property int|null $billed_count NULL until the run finishes
 * @property int|null $already_billed_count
 * @property int|null $failed_count
 * @property int|null $unplaceable_listed_count how many enrollments listUnplaceableForSchool() returned
 * @property int|null $unplaceable_count
 * @property int|null $billable_count the School's billable population, counted independently at run time
 * @property int|null $outside_coordinates_count SIGNED — billable students priced at other coordinates, NOT a miss count
 */
class BulkInvoiceRun extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_bulk_invoice_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => BulkInvoiceRunStatus::class,
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return HasMany<BulkInvoiceRunRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(BulkInvoiceRunRow::class, 'run_id');
    }

    /**
     * @return BelongsTo<FeeSchedule, $this>
     */
    public function feeSchedule(): BelongsTo
    {
        return $this->belongsTo(FeeSchedule::class, 'fee_schedule_id');
    }

    /**
     * Who started it, for display. `started_by_user_id` carries no database FK (a LOOKUP, matching
     * the opening-balance batch's uploader); an Eloquent belongsTo resolves a name without adding a
     * constraint.
     *
     * @return BelongsTo<User, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
