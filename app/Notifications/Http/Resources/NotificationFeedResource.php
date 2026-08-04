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

        // ONE instance — the SAME one the controller hydrated, now that the container
        // binds it `scoped`. Before that binding this resolved a fresh, empty hydrator
        // and every title fell back to the generic string.
        $hydrator = app(PayloadHydrator::class);

        return [
            'id' => $this->uuid,
            'sort_id' => $this->id,
            'type' => $notification?->type->value,
            'title' => $hydrator->title($this->resource),
            // WHY this person received it. For role-derived recipients the answer
            // is genuinely unobvious, and it is the difference between a feed and
            // a mystery.
            'reason' => $this->reason->value,
            'read_at' => $this->read_at?->toIso8601String(),
            'seen_at' => $this->seen_at?->toIso8601String(),
            'created_at' => $notification?->created_at?->toIso8601String(),
            // FOR DEEP-LINKING. "Type + uuid, never the raw table/id pair" is what
            // this line already claimed — and it emitted the type ALONE, so the
            // frontend had something to switch on and nothing to navigate to.
            'subject_type' => $notification?->subject_type,
            'subject_uuid' => $notification?->subject?->getAttribute('uuid'),
            // The result page is keyed on (student, enrolment), so the subject uuid
            // cannot build it alone. The hydrator resolves this in the SAME pass as the
            // name — the row already had to know which child it is about, so the uuid
            // costs no extra query and reveals nothing the title does not.
            //
            // NULL is the degraded case, and it is expected: payload ids are NOT
            // foreign keys, so a student withdrawn after the notification was raised
            // leaves a row that renders as history and navigates nowhere. A missing
            // target must not become a broken link.
            'student_uuid' => $hydrator->navigationStudentUuid($this->resource),
        ];
    }
}
