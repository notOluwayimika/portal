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

    /**
     * How this allocation settled its invoice — DERIVED, with NO stored flag (fork 1):
     *
     *   'credit_applied'  the funding payment predates the invoice it settles — a
     *                     carry-forward overpayment auto-applied at invoice generation (W3);
     *   'payment'         an ordinary payment recorded against an already-existing invoice.
     *
     * The discriminator is purely temporal and needs no column: a payment that existed
     * BEFORE its invoice can only be reaching that invoice as carried-forward credit,
     * because you cannot pay an invoice that does not yet exist. Requires `payment` and
     * `invoice` to be loaded. Equal timestamps resolve to the ordinary 'payment' (the
     * carry-forward case is always strictly earlier — the overpayment happened first).
     */
    public function settlementKind(): string
    {
        /** @var Payment $payment */
        $payment = $this->payment;
        /** @var Invoice $invoice */
        $invoice = $this->invoice;

        return $payment->created_at < $invoice->created_at
            ? 'credit_applied'
            : 'payment';
    }
}
