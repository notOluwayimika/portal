<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
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
 * @property string $allocation_rule
 * @property bool $allocation_overridden
 * @property string|null $allocation_override_reason
 */
class PaymentAllocation extends Model
{
    use AddUuid, AppendOnly, BelongsToSchool;

    /**
     * THE TWO ALLOCATION RULES THAT EXIST IN THE CODE, and there are exactly two — not one, and
     * not a speculative enum of rules nobody has written.
     *
     * There is no configurable allocation policy: finance_school_settings carries one substantive
     * column (invoice_number_prefix) and nothing that selects a rule. These constants therefore
     * record what the two writers DO, so an allocation row can say which of them produced it.
     * A single constant would be worse than none, because it would stamp every credit-draw
     * allocation with the named-invoice rule's identity — a wrong attribution is harder to notice
     * than a missing one, and the table is append-only.
     */

    /**
     * The incoming payment is allocated against the invoice it names, capped at that invoice's
     * outstanding; any remainder is left unallocated and banks as account credit.
     * {@see RecordPayment}
     */
    public const RULE_PAYMENT_AGAINST_NAMED_INVOICE = 'payment_against_named_invoice';

    /**
     * A newly raised invoice draws down EARLIER payments' unallocated remainders, oldest payment
     * first (`orderBy('id')` — monotonic with creation and free of second-precision ties), capped
     * at min(credit, invoice total, Σ unallocated). The money arrived before the charge existed.
     * {@see GenerateInvoice::applyCreditForward()}
     */
    public const RULE_CREDIT_APPLIED_FORWARD_OLDEST_FIRST = 'credit_applied_forward_oldest_first';

    protected $table = 'finance_payment_allocations';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
        'allocation_overridden' => 'boolean',
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
