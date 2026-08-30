<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\ManualInvoiceRunOutcome;
use App\Finance\Enums\ManualInvoiceRunStatus;
use App\Finance\Jobs\ProcessManualInvoiceRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE BURSAR-AUTHORED BILLING RUN over a list of enrollments the operator chose, billing lines the
 * operator typed. The row is inserted in `pending` with its targets and its lines already written,
 * and {@see ProcessManualInvoiceRun} resolves it by id.
 *
 * ── THE RUN'S ONLY SELF-CHECK ────────────────────────────────────────────────────────────────────
 *
 *     billed_count + failed_count + unplaceable_count == target_count
 *
 * `target_count` is the size of the list WALKED (`finance_manual_invoice_run_targets`); the three
 * counts beside it are counted from the rows PERSISTED. Two independent sources, which is the only
 * reason the equality can fail and therefore the only reason asserting it is worth anything. There is
 * deliberately no "something went wrong" flag: a flag the job sets is a flag the job can forget to
 * set.
 *
 * `target_count` IS THE NUMBER THE BURSAR TICKED, because the targets are keyed on the STUDENT.
 * Keyed on the enrollment it would count what SURVIVED RESOLUTION, and a run could report "90 of 90"
 * over a selection of 96 — balanced, complete, and six families short. This feature issues DIRECTLY
 * with no maker-checker (Brookstone, 30 August 2026), so this report is the only place a wrong
 * selection can surface.
 *
 * IT FAILS IN EXACTLY TWO WAYS, and both are worth seeing. A row that could not be WRITTEN at all is
 * missing from both counts — the same per-enrollment fault the scheduled run's `attempt()` rules on.
 * And a row still sitting in `claimed` when the run finished is a claim whose process died between
 * the claim and the outcome write: not billed, not failed, not retried, and not on the left-hand
 * side of the sum.
 *
 * `claimed_count` IS THE DIAGNOSIS AND NOT A TERM, while `unplaceable_count` IS a term. The line
 * between them is whether anything is UNKNOWN: an unplaceable student is a finished and correct
 * outcome, a claimed row is a run that does not know what happened. `claimed_count` is exactly the
 * shortfall, recorded so a screen can say WHICH number is missing rather than only that one is.
 * Adding it to the left balances the equality on precisely the runs the equality exists to catch —
 * see {@see ManualInvoiceRunOutcome}.
 *
 * ── AT MOST ONE NON-TERMINAL RUN PER SCHOOL, AT THE ENGINE ───────────────────────────────────────
 *
 * `active_run_key` is a STORED generated column — `school_id` while `status` is `pending` or
 * `running`, NULL otherwise — under a UNIQUE index. Pressing Run twice creates two runs and bills
 * everyone twice; this refuses the second one in the database rather than in a controller, which is
 * the difference between a control and theatre. It does NOT stop two runs raised SEQUENTIALLY over
 * the same list — the first completes, its key goes NULL, the second is admitted — and that stays
 * open in docs/handoff/bulk-manual-invoicing-brief.md §4.
 *
 * The column is not in `$guarded`-bypassable reach: MySQL refuses any write that names a generated
 * column, so it is never assigned and never read as an attribute here.
 *
 * NO MONEY COLUMN, deliberately, for the reason the scheduled run has none: a `total_billed` here
 * would be an unreconciled second copy of what the invoices already say.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property ManualInvoiceRunStatus $status
 * @property int|null $started_by_user_id LOOKUP, not an FK — audit attribution only (Constitution 13)
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $failure_reason PER-RUN only — an enrollment that could not be billed keeps its reason on its row
 * @property int|null $target_count how many targets the run walked; NULL until the run finishes
 * @property int|null $billed_count NULL until the run finishes
 * @property int|null $failed_count NULL until the run finishes
 * @property int|null $unplaceable_count ticked students with no current billable enrollment — a TERM of the equality
 * @property int|null $claimed_count claims left outstanding — the DIAGNOSIS of a short equality, never a term of it
 * @property Carbon|null $created_at when the run was ASKED FOR, which is not when it started
 * @property Carbon|null $updated_at
 */
class ManualInvoiceRun extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_manual_invoice_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => ManualInvoiceRunStatus::class,
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return HasMany<ManualInvoiceRunTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(ManualInvoiceRunTarget::class, 'run_id');
    }

    /**
     * @return HasMany<ManualInvoiceRunLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ManualInvoiceRunLine::class, 'run_id');
    }

    /**
     * @return HasMany<ManualInvoiceRunRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ManualInvoiceRunRow::class, 'run_id');
    }

    /**
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
