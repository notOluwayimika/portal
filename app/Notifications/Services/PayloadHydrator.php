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

    /**
     * Student uuid by id — resolved in the SAME pass as the names.
     *
     * The deep link needs it and the title already pays for the query, so a second
     * lookup would be pure waste. It is also why the uuid is safe to expose: the
     * feed already had to know which student the row is about in order to name them.
     */
    private array $studentUuids = [];

    /** @param Collection<int, NotificationRecipient> $recipients */
    public function hydrate(Collection $recipients): void
    {
        $studentIds = $recipients
            ->map(fn (NotificationRecipient $r) => $r->notification?->payload['student_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        // RESET UNCONDITIONALLY, outside the guard. Previously the maps were only
        // assigned when the page HAD student ids, so hydrating a page with none left
        // the PREVIOUS page's names in place — and a later row carrying one of those
        // ids would resolve to a name this hydration never looked up. Stale rather
        // than absent, which is the failure mode that reads as correct.
        $this->studentNames = [];
        $this->studentUuids = [];

        if ($studentIds->isNotEmpty()) {
            $students = Student::query()
                ->whereIn('id', $studentIds)
                ->get(['id', 'uuid', 'first_name', 'last_name']);

            $this->studentNames = $students
                ->mapWithKeys(fn (Student $s) => [(int) $s->id => trim("{$s->first_name} {$s->last_name}")])
                ->all();

            $this->studentUuids = $students
                ->mapWithKeys(fn (Student $s) => [(int) $s->id => (string) $s->uuid])
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

    /**
     * The STUDENT uuid this row navigates to, or null.
     *
     * NULL IS THE DEGRADED CASE AND IS EXPECTED. Payload ids are not foreign keys — a
     * student withdrawn after the notification was raised leaves an id that resolves
     * to nothing. The row must still render as readable history; it simply stops being
     * a link. A missing target is not an error, and must not become a broken URL.
     */
    public function navigationStudentUuid(NotificationRecipient $recipient): ?string
    {
        $studentId = $recipient->notification?->payload['student_id'] ?? null;

        return is_int($studentId) ? ($this->studentUuids[$studentId] ?? null) : null;
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
