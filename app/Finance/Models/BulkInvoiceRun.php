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
 * THE RECONCILIATION, and it is the reason this table exists rather than a log line
 * (docs/handoff/tickets/bulk-run-must-account-for-every-billable-student.md):
 *
 *     unaccounted_count = billable_count
 *                       − (billed_count + already_billed_count + failed_count)   ← the cohort
 *                       − unplaceable_count                                      ← the flagged
 *
 * Non-zero means there are billable students in this School that this run neither billed nor
 * flagged — placeable at coordinates it did not name, or an episode with a NULL `student_id`, which
 * no coordinate reasoning reaches. The figure is written AT RUN TIME. A screen computing it when it
 * opens would describe a roster that has since moved, and would report a number about a School that
 * was never the one billed.
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
 * @property int|null $billed_count NULL until the run finishes
 * @property int|null $already_billed_count
 * @property int|null $failed_count
 * @property int|null $unplaceable_count
 * @property int|null $billable_count the School's billable population, counted independently at run time
 * @property int|null $unaccounted_count SIGNED — see the migration
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
