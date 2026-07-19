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
 * The append-only money→invoice link. Survives invoice cancellation (a cancelled
 * invoice with a prior allocation leaves a credit on the account — the payment is
 * never un-linked, only the charge is reversed in the ledger).
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $payment_id
 * @property int $invoice_id
 * @property Money $amount
 */
class PaymentAllocation extends Model
{
    use AddUuid, AppendOnly, BelongsToSchool;

    protected $table = 'finance_payment_allocations';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
