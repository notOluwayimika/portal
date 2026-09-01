<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\GatewayTransactionStatus;
use App\Finance\Services\GatewayReference;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

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
 * @property Money|null $fee
 * @property string|null $settlement_reference
 * @property Carbon|null $settled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Money|null $bill what the SCHOOL is owed, as against `amount` which is what the PAYER is
 *                            charged. NULL on rows raised before 2026_09_01_110000 — never zero.
 * @property-read Invoice|null $invoice
 * @property-read Payment|null $payment
 * @property-read Collection<int, GatewayTransactionEvent> $events
 */
class GatewayTransaction extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_gateway_transactions';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
        // WHAT THE PROVIDER KEPT, reported at settlement and never at success. Null means "not
        // reported yet" and can mean nothing else — the update guard makes every provider-reported
        // fact write-once, so a value here was never overwritten by a later delivery.
        'fee' => MoneyCast::class.':fee_minor,fee_currency',
        // What the SCHOOL is owed, as against `amount` which is what the PAYER is charged. Added by
        // 2026_09_01_110000 so settlement credits the bill rather than gross-minus-reported-fee, and
        // so step 7 has something to compare a residual against. NULL on rows raised before it.
        'bill' => MoneyCast::class.':bill_minor,bill_currency',
        'status' => GatewayTransactionStatus::class,
        // A MOMENT, not a business day — unlike finance_payments.received_at, which is a date. This
        // is the instant the PROVIDER says the money was collected, and it is where the payment's
        // received_at date is to be taken from rather than from the clock at webhook time.
        'paid_at' => 'datetime',
        // When the PROVIDER settled — a different true instant from `paid_at`, days apart in the
        // ordinary case. The discrepancy report needs both to say which leg is late.
        'settled_at' => 'datetime',
    ];

    /**
     * THE REFERENCE MUST ROUTE BACK TO THIS ROW'S OWN SCHOOL, and it is refused at CREATION rather
     * than discovered at delivery.
     *
     * The webhook derives the school from the reference (see {@see GatewayReference}) so the lookup
     * can run with `SchoolScope` intact instead of searching across schools. That makes the
     * reference FORMAT a contract between the initialise call that mints one and the webhook that
     * reads it back — two pieces of code written weeks apart by different hands.
     *
     * A DOCBLOCK WOULD MAKE THAT CONTRACT FOLKLORE. Its violation is silent in the worst possible
     * way: a hand-built reference is accepted here, the parent pays, Paystack delivers, and the
     * webhook answers 200 having found nothing — indistinguishable from a delivery for a
     * transaction we never issued. The money is taken and no payment is recorded, and the only
     * evidence is a log line saying the reference was unknown.
     *
     * So the check lives where the mistake is MADE, not where it surfaces. Minting through
     * `GatewayReference::mint()` passes; building the string by hand fails loudly, at the write,
     * with the reason named — before anyone is charged.
     *
     * ⚠️ IT GUARDS THE ELOQUENT WRITE ONLY, AND THAT IS NARROWER THAN IT READS. `static::creating`
     * does not fire on `DB::table()->insert()`, `->upsert()`, or any query-builder write. This
     * repository ALREADY writes past it: `tests/Feature/Finance/GatewayTransactionSchemaTest.php`
     * inserts hand-built references by raw builder and passes.
     *
     * That matters because `bin/ci-boundary-lint.php` forbids `DB::table` on a `finance_` literal
     * only OUTSIDE `app/Finance` — which is exactly where step 3 will live. So the component this
     * guard was written to protect is the one that can walk around it.
     *
     * THE REAL ANSWER IS A TRIGGER, and this project knows it: the sibling rule (`origin` pairing)
     * is a trigger precisely so "a bad argument is a 1644, not a bad row". Tracked in
     * `docs/handoff/tickets/gateway-reference-guard-is-eloquent-only.md` and REQUIRED BEFORE STEP 3,
     * not after. Until then this docblock claims the Eloquent write and nothing wider.
     *
     * This is the collation-tripwire argument one seam over: a rule with no mechanism behind it is
     * a wish — and a rule whose mechanism covers one of two write paths is a smaller version of the
     * same thing, which is harder to see because something IS enforcing something.
     */
    protected static function booted(): void
    {
        // AFTER the traits. BelongsToSchool registers its own `creating` listener during boot(),
        // and booted() runs after every boot{Trait}, so school_id is populated by the time this
        // callback sees the model — which is what makes comparing against it meaningful.
        parent::booted();

        static::creating(function (self $transaction): void {
            $routesTo = GatewayReference::schoolIdFrom((string) $transaction->reference);

            if ($routesTo !== (int) $transaction->school_id) {
                throw new RuntimeException(
                    'A gateway transaction reference must be minted by GatewayReference::mint() for '
                    .'its own school. This one routes to '.var_export($routesTo, true).' but the row '
                    .'belongs to school#'.$transaction->school_id.'. A reference that does not route '
                    .'is accepted by the gateway and then unfindable when the webhook arrives: the '
                    .'payer is charged and no payment is ever recorded.'
                );
            }
        });
    }

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
     * Every raw delivery about this attempt, oldest first — webhooks and verify responses alike,
     * stored verbatim and never edited (boundary §5).
     *
     * A CHILD TABLE RATHER THAN A `payload` COLUMN because there are several, and a column holds one:
     * `charge.success`, then a verify response, then a settlement event days later. The column would
     * destroy each earlier body as the next arrived — the exact loss §8.2 exists to prevent.
     *
     * @return HasMany<GatewayTransactionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(GatewayTransactionEvent::class);
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
