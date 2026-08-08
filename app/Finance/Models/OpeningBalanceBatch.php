<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Support\Money;
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
 * IT CARRIES ONE MONEY COLUMN, and which one is the whole point. The three `total_*` pairs were §5's
 * control totals over columns the balance-forward file does not have, and they retired with it
 * (2026_08_08_100000). What replaces them is `control_total` — §1's L2 witness, and the one figure on
 * this table that no code derived: the operator read it off WCBS's own report and typed it
 * (`--control-total=`, §12 decision 2). That different path is the only reason L2 catches what L1
 * structurally cannot.
 *
 * IT IS RECORDED ON EVERY RUN, passing or failing. A figure kept only when the check succeeds cannot
 * be reviewed after a rejection — which is precisely when someone wants to see what was claimed.
 * §11 asks for the attestation to be held with the go/no-go, and this is where it is held.
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
 * @property Money|null $control_total §1 L2's operator-typed witness — null only on a pre-4a batch
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
        'control_total' => MoneyCast::class.':control_total_minor,control_total_currency',
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
