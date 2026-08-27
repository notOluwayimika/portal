<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ONE RAW DELIVERY about a gateway transaction, stored verbatim — boundary §5's "raw webhook
 * payloads", and §8.2's reason for capturing them now: they cannot be recovered afterwards.
 *
 * APPEND-ONLY AT THE DATABASE (`_no_update` / `_no_delete`), which is the whole point rather than a
 * convention: a payload that can be edited is not evidence, and evidence is all this table is for. A
 * dispute six months from now is answered by what the provider actually sent, not by what this
 * system concluded from it.
 *
 * IT RECORDS REJECTED DELIVERIES TOO. Nothing here asserts the payload was trusted or its signature
 * verified — a delivery that failed verification is exactly the one an investigation wants to read.
 *
 * WHAT IT CANNOT HOLD, named rather than left to be discovered: a webhook whose reference matches no
 * transaction. Every row hangs off a parent and a school, so an unmatched delivery has nowhere to go
 * here; that log belongs with the webhook handler.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $gateway_transaction_id
 * @property string $source
 * @property string|null $event
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read GatewayTransaction|null $gatewayTransaction
 */
class GatewayTransactionEvent extends Model
{
    use AddUuid, BelongsToSchool;

    /** Provider-initiated. */
    public const SOURCE_WEBHOOK = 'webhook';

    /** Our own verify call's response — not an event, which is why `event` is nullable. */
    public const SOURCE_VERIFY = 'verify';

    protected $table = 'finance_gateway_transaction_events';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
    ];

    /** @return BelongsTo<GatewayTransaction, $this> */
    public function gatewayTransaction(): BelongsTo
    {
        return $this->belongsTo(GatewayTransaction::class);
    }
}
