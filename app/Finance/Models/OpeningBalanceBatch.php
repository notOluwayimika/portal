<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One staged WCBS opening-balance extract (§9 step 4a). A batch is the unit of approval (§8) and,
 * today, the unit of validation: it names the cutover date D ONCE and owns the rows.
 *
 * IT POSTS NOTHING. There is no ledger row, payment, invoice or account movement behind any of
 * this — the posting Action is 4b.
 *
 * IT CARRIES NO MONEY COLUMN, and that is R5/R10 rather than an omission. The three `total_*` pairs
 * were §5's control totals over columns the balance-forward file does not have, and they retired with
 * it (2026_08_08_100000). §1's L2 — Σ(student stated totals) against the operator's control total —
 * is checked at validation time and reported as a BATCH finding; the control total itself is
 * OPERATOR-ENTERED (`--control-total=`, §12 decision 2) and is not a column, because a figure
 * carried in the file was produced by the same export run as the rows and would share their failure
 * mode.
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
 * @property int $row_count rows STAGED
 * @property int $file_row_count data lines READ — the ingest-completeness counterpart
 * @property Carbon $cutover_date
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
