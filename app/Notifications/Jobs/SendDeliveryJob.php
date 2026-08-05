<?php

namespace App\Notifications\Jobs;

use App\Jobs\Middleware\SchoolAware;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\NotificationDelivery;
use App\Notifications\Services\ChannelRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transmit ONE pending delivery through its channel.
 *
 * CHANNEL-AGNOSTIC BY DESIGN. It resolves the channel from the row's own `channel`
 * column and calls `send()`; it knows nothing about email, SMS or their providers.
 * SMS reuses this unchanged — the next chapter adds a provider and a STOP handler,
 * not a second send mechanism.
 *
 * ⚠️ THIS IS WHERE `queue:work` STOPS BEING AUDIT-LAG AND BECOMES DELIVERY. In-app
 * needed no transmission, so a dead worker cost only the audit rows while the feed
 * kept working. From here a dead worker means email genuinely does not send, and the
 * only symptom is deliveries sitting at PENDING — which looks like nothing happening.
 *
 * IDEMPOTENT ON STATUS, which is the whole of the no-double-send guarantee. The row
 * is re-read and its status checked inside the job: anything other than PENDING means
 * another attempt already terminated it, and this one returns. A retry after a
 * provider timeout therefore cannot send twice.
 *
 * IN-APP IS DELIBERATELY NOT ROUTED THROUGH HERE. It has nothing to transmit, so a
 * queue hop would only ever flip a status column — and would make the feed depend on
 * a worker it currently does not need.
 */
class SendDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $deliveryId,
        public readonly int $schoolId,
    ) {
        $this->onConnection(config('notifications.queue.connection'));
        $this->onQueue(config('notifications.queue.send', config('notifications.queue.fanout')));
    }

    public function middleware(): array
    {
        return [new SchoolAware];
    }

    public function handle(ChannelRegistry $channels): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        // THE IDEMPOTENCY GUARD. Read fresh and check status here rather than
        // trusting the dispatch: a retry after a provider timeout arrives with the
        // row already terminal, and re-sending is the failure this prevents.
        if ($delivery->status !== DeliveryStatus::PENDING) {
            return;
        }

        $channel = $channels->get($delivery->channel);

        try {
            // The channel performs the send AND writes the terminal state, because
            // only it holds the provider's response. See EmailChannel: the status
            // transition and the provider message id are ONE write, so
            // "SENT ⟹ correlatable" holds by construction.
            $channel->send($delivery);
        } catch (Throwable $e) {
            // A FAILURE IS A ROW, never a silent drop — the same contract the skip
            // reasons keep on the fan-out side.
            $delivery->forceFill([
                'status' => DeliveryStatus::FAILED,
                'failed_at' => now(),
                'attempts' => (int) $delivery->attempts + 1,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            Log::warning('notification delivery failed', [
                'delivery_id' => $delivery->id,
                'channel' => $delivery->channel->value,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
