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
}
