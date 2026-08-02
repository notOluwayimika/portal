<?php

namespace App\Notifications\Services;

use App\Models\Student;
use App\Notifications\Enums\NotificationType;
use App\Notifications\Models\NotificationRecipient;
use Illuminate\Support\Collection;

/**
 * Turn the id-only payloads of a whole feed page into display text, in a bounded
 * number of queries.
 *
 * THE N+1 THIS EXISTS TO PREVENT. Payloads store ids rather than rendered names —
 * so a name is never written into a JSON column that lands in every backup and log
 * line — but the naive consequence is one lookup per row, i.e. 20 queries per feed
 * open, per user, every poll. This collects every id across the page first and
 * resolves one query PER MODEL TYPE regardless of page size.
 *
 * A missing record is not an error. Payload ids are not foreign keys; a student
 * withdrawn after the notification was raised leaves a row that must still render
 * as readable history, which is what `rendered_fallback` is for.
 */
class PayloadHydrator
{
    /** @var array<int, string> */
    private array $studentNames = [];

    /** @param Collection<int, NotificationRecipient> $recipients */
    public function hydrate(Collection $recipients): void
    {
        $studentIds = $recipients
            ->map(fn (NotificationRecipient $r) => $r->notification?->payload['student_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($studentIds->isNotEmpty()) {
            $this->studentNames = Student::query()
                ->whereIn('id', $studentIds)
                ->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn (Student $s) => [(int) $s->id => trim("{$s->first_name} {$s->last_name}")])
                ->all();
        }
    }

    /**
     * The one-line text for a feed row.
     *
     * Falls back to the stored `rendered_fallback`, then to a generic label — a
     * feed row always renders as something, never as an empty string or a crash.
     */
    public function title(NotificationRecipient $recipient): string
    {
        $notification = $recipient->notification;

        if ($notification === null) {
            return 'Notification';
        }

        $payload = $notification->payload;

        return match ($notification->type) {
            NotificationType::RESULT_READY => $this->resultReadyTitle($payload),
            NotificationType::APPROVAL_REQUESTED => $notification->rendered_fallback
                ?? 'A request is awaiting your approval',
            default => $notification->rendered_fallback ?? 'Notification',
        };
    }

    /** @param array<string, mixed> $payload */
    private function resultReadyTitle(array $payload): string
    {
        $studentId = $payload['student_id'] ?? null;
        $name = is_int($studentId) ? ($this->studentNames[$studentId] ?? null) : null;

        return $name === null
            ? "A student's result is ready"
            : "{$name}'s result is ready";
    }
}
