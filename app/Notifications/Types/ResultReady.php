<?php

namespace App\Notifications\Types;

use App\Models\StudentCurriculum;
use App\Notifications\Contracts\Notification;
use App\Notifications\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;

/**
 * A student's result is available to their guardians.
 *
 * THE TYPE THAT PROVES THE DEDUP RULE. One notification PER CHILD — a guardian
 * with three children in the same class gets three, each with its own subject and
 * its own deep-link, because the feed is where a parent clicks through per child.
 *
 * The tempting alternative — a dedup key of
 * `result.approved:{$termId}:{$guardianId}` — is what the first draft of this
 * design proposed, and it loses data: the second and third dispatch collide on
 * the UNIQUE index, leaving child #1's notification standing and children #2 and
 * #3 with no notification, no delivery row, and not even a skip record. The key
 * varies per recipient while the row it constrains is per event; the two are
 * different axes.
 *
 * Collapsing the OUTBOUND message ("3 of your children's results are ready") is a
 * separate mechanism — bundling, v2 — which groups the email while leaving these
 * three feed rows intact.
 */
final class ResultReady implements Notification
{
    public function __construct(
        private readonly StudentCurriculum $enrollment,
        private readonly int $schoolId,
        private readonly ?int $actorId = null,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::RESULT_READY;
    }

    public function schoolId(): int
    {
        return $this->schoolId;
    }

    /** Narrower than the contract's `?Model`: the enrolment is always the subject. */
    public function subject(): Model
    {
        return $this->enrollment;
    }

    public function actorId(): ?int
    {
        return $this->actorId;
    }

    public function payload(): array
    {
        return [
            'student_id' => (int) $this->enrollment->student_id,
            'student_curriculum_id' => (int) $this->enrollment->id,
            'term_id' => $this->enrollment->curriculum?->term_id,
        ];
    }

    /** One enrollment, one notification. No recipient identifier — see above. */
    public function dedupKey(): string
    {
        return 'result.ready:'.$this->enrollment->id;
    }

    /**
     * No stored fallback: the feed renders the child's name from `student_id` at
     * read time, so the row is never stale, and no pupil name is written into a
     * JSON column that lands in every backup.
     */
    public function renderedFallback(): ?string
    {
        return null;
    }
}
