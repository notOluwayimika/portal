<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Models\Concerns\AppendOnly;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot line on an invoice: description + amount captured at
 * billing time. fee_item_id is nullable provenance only — the display never joins
 * to a mutable fee row (docs/finance-data-ownership.md).
 *
 * `bank_account_id` is WHERE THIS LINE'S MONEY WAS DESTINED, snapshotted at issue (S11). It is the
 * same discipline as `description` and `amount` one field further: captured here, never re-joined
 * to the mutable catalog row it came from. The live lookup it replaces —
 * fee_item_id → finance_fee_items.bank_account_id — could only ever answer "where would this go if
 * it were billed today", which stops being the same question the moment a fee item is repointed.
 *
 * NULL MEANS "NOT RECORDED", never "no destination" and never "the default account". Every line
 * issued before the column existed is null and stays null — there is no backfill and there will not
 * be one, because writing today's catalog reading into a column that claims to record issue time
 * manufactures a false history on a table that cannot be corrected. A REDUCTION line is also null,
 * and legitimately so: it sends money nowhere.
 *
 * `kind` says what the line MEANS (charge / waiver / discount); the SIGN of `amount`
 * carries the arithmetic. A reduction is a negative line, so the invoice total stays a
 * literal signed SUM(lines) that never branches on kind. §5's "full fee above,
 * reduction beneath" is then a grouping the client can do without recomputing.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $invoice_id
 * @property string $description
 * @property InvoiceLineKind $kind
 * @property string|null $note
 * @property Money $amount
 * @property int|null $fee_item_id
 * @property int|null $bank_account_id
 */
class InvoiceLine extends Model
{
    use AddUuid, AppendOnly, BelongsToSchool;

    protected $table = 'finance_invoice_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
        'kind' => InvoiceLineKind::class,
    ];

    public function isReduction(): bool
    {
        return $this->kind->isReduction();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The account this line was destined for, as recorded AT ISSUE.
     *
     * Nullable for two independent reasons and a reader must not collapse them: the column itself
     * admits null (history, and every reduction line), and BankAccount carries SchoolScope, so a
     * read from another School's context resolves to null rather than leaking the account. Same
     * shape and same reason as {@see FeeItem::bankAccount()}.
     *
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
