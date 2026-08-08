<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Actions\PostOpeningBalanceBatch;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One staged WCBS opening-balance extract (§9 step 4a). A batch is the unit of approval (§8) and,
 * today, the unit of validation: it names the cutover date D ONCE and owns the rows.
 *
 * IT IS POSTED BY {@see PostOpeningBalanceBatch} (§9 step 4b), and by nothing
 * else. `posted` is TERMINAL at the database: `posted_school_key` (generated) holds at most one posted
 * batch per school under a unique index, and two triggers deny both exits from the state — UPDATE and
 * DELETE alike (G1/G1b, 2026_08_08_110000). The approval gate that will PRECEDE the post is 4c.
 *
 * `term_id` NAMES THE TERM BEING CLOSED OUT — the last term, whose closing position the file carries.
 * It is NOT a cutover term T: R5 puts the cutover on a term boundary, so no such term exists. §9's
 * open decision was ruled this way in 4b (repurpose, do not nullify) because the column is the one
 * fact that says what period the opening charges represent, and the schema carries the meaning in the
 * column comment. The command option is `--closing-term`.
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
 * @property int $term_id the term being CLOSED OUT
 * @property int|null $uploaded_by_user_id
 * @property array<int, array<string, mixed>>|null $findings
 * @property Carbon|null $posted_at
 * @property int|null $posted_by_user_id LOOKUP, not an FK
 */
class OpeningBalanceBatch extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_opening_balance_batches';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => OpeningBalanceBatchStatus::class,
        'cutover_date' => 'date',
        'posted_at' => 'datetime',
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
