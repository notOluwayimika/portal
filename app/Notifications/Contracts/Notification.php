<?php

namespace App\Notifications\Contracts;

use App\Notifications\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;

/**
 * What an application action raises. PUBLIC — this is the module's front door.
 *
 * A notification knows WHAT happened. It does not know who receives it (the
 * resolver's job), which channels carry it (the registry's), or how it reads
 * (the channel's). That separation is what lets a new channel be added without
 * touching a single business call site.
 */
interface Notification
{
    public function type(): NotificationType;

    /**
     * The owning School. ALWAYS explicit, never inferred from the ambient
     * context: dispatch may happen inside a job or a command where the request
     * context is absent, and inferring it from `users.school_id` is the legacy
     * fallback the Constitution forbids (rule 13 / ADR 0042).
     */
    public function schoolId(): int;

    /** The record this is about, for deep-linking. Null for events with no one subject. */
    public function subject(): ?Model;

    /**
     * Who caused it. Excluded from the recipient set by default — the commonest
     * bug in a notification system is telling someone what they just did.
     */
    public function actorId(): ?int;

    /**
     * IDs and scalars ONLY — never names, addresses or amounts-as-text. The feed
     * renders from these at read time, so PII never lands in a JSON column that
     * is replicated into every backup and log.
     *
     * @return array<string, scalar|null>
     */
    public function payload(): array;

    /**
     * EVENT identity, for collapsing the same event emitted twice (a retry, a
     * double-click, a replayed listener).
     *
     * MUST NOT CONTAIN A RECIPIENT IDENTIFIER. Recipient-varying keys are a
     * different axis and destroy data: three children of one guardian would
     * collide on the second and third dispatch, leaving two of them with no
     * notification and no delivery record at all. Recipient-level collapse is
     * BUNDLING (v2), which keeps one feed row per child while sending one email.
     * NotificationDedupKeyTest enforces this against the registry.
     */
    public function dedupKey(): ?string;

    /**
     * An immutable one-line summary, stored so a feed row survives the deletion
     * of its subject. Null where the payload alone always renders.
     */
    public function renderedFallback(): ?string;
}
