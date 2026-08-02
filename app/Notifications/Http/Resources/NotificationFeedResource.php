<?php

namespace App\Notifications\Http\Resources;

use App\Notifications\Models\NotificationRecipient;
use App\Notifications\Services\PayloadHydrator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One feed row.
 *
 * `id` on the wire is the UUID, matching the rest of this codebase — an
 * auto-increment id is never exposed. `sort_id` carries the integer separately
 * because "mark everything up to here read" needs an ordered bound, and a uuid v7
 * ordering is not something the client should be asked to reason about.
 *
 * @mixin NotificationRecipient
 */
class NotificationFeedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $notification = $this->notification;

        return [
            'id' => $this->uuid,
            'sort_id' => $this->id,
            'type' => $notification?->type->value,
            'title' => app(PayloadHydrator::class)->title($this->resource),
            // WHY this person received it. For role-derived recipients the answer
            // is genuinely unobvious, and it is the difference between a feed and
            // a mystery.
            'reason' => $this->reason->value,
            'read_at' => $this->read_at?->toIso8601String(),
            'seen_at' => $this->seen_at?->toIso8601String(),
            'created_at' => $notification?->created_at?->toIso8601String(),
            // For deep-linking. Type + uuid, never the raw table/id pair.
            'subject_type' => $notification?->subject_type,
        ];
    }
}
