<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One staged WCBS opening-balance extract (§9 commit 1). A batch is the unit of approval (§8) and,
 * today, the unit of validation: it names the cutover term T and date D ONCE, carries §5's control
 * totals, and owns the rows.
 *
 * IT POSTS NOTHING. There is no ledger row, payment, invoice or account movement behind any of
 * this — the posting Action is commit 4.
 *
 * The control totals are NULLABLE and written when the run completes. A batch that aborted
 * mid-parse must present no total rather than a total nobody summed; `MoneyCast` returns null only
 * when both storage columns are null, so "not yet totalled" and "totalled to zero" stay distinct.
 *
 * `unique(school_id, batch_reference)` is §7's idempotency key AT THE DATABASE. The validator
 * inserts this row before it reads a byte of the file, so a re-run of the same batch is refused by
 * the engine (1062) rather than by a guard clause someone can delete.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property string $batch_reference
 * @property string $filename
 * @property OpeningBalanceBatchStatus $status
 * @property int $row_count
 * @property Money|null $total_prior_arrears
 * @property Money|null $total_paid_to_date
 * @property Money|null $total_wcbs_billed
 * @property \Illuminate\Support\Carbon $cutover_date
 * @property int $term_id
 * @property int|null $uploaded_by_user_id
 * @property array<int, array<string, mixed>>|null $findings
 */
class OpeningBalanceBatch extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_opening_balance_batches';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => OpeningBalanceBatchStatus::class,
        'cutover_date' => 'date',
        'findings' => 'array',
        'total_prior_arrears' => MoneyCast::class.':total_prior_arrears_minor,total_prior_arrears_currency',
        'total_paid_to_date' => MoneyCast::class.':total_paid_to_date_minor,total_paid_to_date_currency',
        'total_wcbs_billed' => MoneyCast::class.':total_wcbs_billed_minor,total_wcbs_billed_currency',
    ];

    /**
     * @return HasMany<OpeningBalanceRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(OpeningBalanceRow::class, 'batch_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
