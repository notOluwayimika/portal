<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\GatewayTransactionStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One checkout attempt at an online payment provider — the mutable conversation whose single
 * successful outcome becomes an immutable `finance_payments` row.
 *
 * NOT APPEND-ONLY, AND THE EXEMPTION IS THE REASON THE TABLE EXISTS. Every other money table here is
 * append-only because it records what happened; this one records what is HAPPENING, and a
 * conversation with a third party is nothing but state changes. What it is NOT is freely mutable:
 * `finance_gateway_transactions_update_guard` freezes the identity and the amount from insert, makes
 * `success` terminal, and refuses a return to `pending`. So the row moves in exactly one direction
 * and stops, which is what makes a replayed webhook harmless. See
 * 2026_08_27_100000_create_finance_gateway_transactions.php for the guards themselves — this class
 * is a reader of them, never a second copy.
 *
 * AND IT IS NEVER DELETED (`_no_delete`). The failed and abandoned rows are the entire input to the
 * discrepancy report; a settled one is the only thing that explains a `gateway` payment.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $invoice_id
 * @property string $provider
 * @property string $reference
 * @property string|null $provider_reference
 * @property Money $amount
 * @property GatewayTransactionStatus $status
 * @property Carbon|null $paid_at
 * @property string|null $failure_reason
 * @property int|null $initiated_by_user_id
 * @property int|null $payment_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Invoice|null $invoice
 * @property-read Payment|null $payment
 */
class GatewayTransaction extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_gateway_transactions';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
        'status' => GatewayTransactionStatus::class,
        // A MOMENT, not a business day — unlike finance_payments.received_at, which is a date. This
        // is the instant the PROVIDER says the money was collected, and it is where the payment's
        // received_at date is to be taken from rather than from the clock at webhook time.
        'paid_at' => 'datetime',
    ];

    /**
     * The invoice this attempt was raised to settle.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The money this attempt produced, or null while it has produced none. NOT a HasOne dressed the
     * other way round: `payment_id` is on THIS row and carries the UNIQUE that makes one attempt
     * settle at most once, so the pointer belongs here.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
