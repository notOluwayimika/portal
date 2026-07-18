<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Models\Concerns\AppendOnly;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot line on an invoice: description + amount captured at
 * billing time. fee_item_id is nullable provenance only — the display never joins
 * to a mutable fee row (docs/finance-data-ownership.md).
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $invoice_id
 * @property string $description
 * @property Money $amount
 * @property int|null $fee_item_id
 */
class InvoiceLine extends Model
{
    use AddUuid, AppendOnly, BelongsToSchool;

    protected $table = 'fee_invoice_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
