<?php

namespace App\Notifications\Types;

use App\Notifications\Contracts\Notification;
use App\Notifications\Enums\NotificationType;
use App\Support\ApprovalAbility;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Something needs a second person's sign-off.
 *
 * ONE CLASS FOR EVERY APPROVAL FAMILY. Credit notes, invoice voids,
 * discount-policy changes, fee-schedule changes and result approvals differ only
 * in which checker ability decides them, and `ApprovalAbility` already derives
 * that vocabulary by convention. A family added tomorrow emits this class with
 * its own ability and needs no new type, no new resolver and no new template —
 * the same "convention, not a list" that keeps the super-admin bypass exclusion
 * from drifting (ADR 0040).
 *
 * The ability is validated HERE as well as in the resolver. Constructing an
 * invalid one fails at the call site, where the stack trace names the caller,
 * rather than inside a queued job an hour later.
 */
final class ApprovalRequested implements Notification
{
    public function __construct(
        private readonly string $checkerAbility,
        private readonly Model $subject,
        private readonly int $schoolId,
        private readonly ?int $submittedBy,
        private readonly string $summary,
    ) {
        if (! ApprovalAbility::isExcludedFromSuperAdminBypass($checkerAbility)) {
            throw new LogicException(
                "[{$checkerAbility}] is not a checker ability — its terminal segment is not "
                .'one of ['.implode(', ', ApprovalAbility::CHECKER_SEGMENTS).']. '
                .'ApprovalRequested notifies the holders of a checker grant; an ability '
                .'outside that convention has no checkers to notify.'
            );
        }
    }

    public function type(): NotificationType
    {
        return NotificationType::APPROVAL_REQUESTED;
    }

    public function schoolId(): int
    {
        return $this->schoolId;
    }

    /** Narrower than the contract's `?Model`: an approval request always has one. */
    public function subject(): Model
    {
        return $this->subject;
    }

    /**
     * The submitter. Excluded from the recipients by the registry — and here that
     * is a correctness requirement rather than a courtesy: `submitted_by <>
     * decided_by` is enforced at the database, so notifying them would invite an
     * action the system refuses.
     */
    public function actorId(): ?int
    {
        return $this->submittedBy;
    }

    public function payload(): array
    {
        return [
            'checker_ability' => $this->checkerAbility,
            'maker_ability' => ApprovalAbility::matchingMakerFor($this->checkerAbility),
            'submitted_by' => $this->submittedBy,
        ];
    }

    /**
     * EVENT identity: one pending request, one notification. No recipient id
     * appears — the whole set of checkers shares this key, which is exactly the
     * point (see the Notification contract).
     */
    public function dedupKey(): string
    {
        return 'approval.requested:'.$this->subject->getMorphClass().':'.$this->subject->getKey();
    }

    public function renderedFallback(): string
    {
        return $this->summary;
    }
}
