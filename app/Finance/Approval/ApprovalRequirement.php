<?php

namespace App\Finance\Approval;

use App\Support\Money;

/**
 * The seam for "does this transaction need a second signature" (ADR 0051).
 *
 * Four Finance submit actions decide this today — SubmitCreditNote, SubmitVoidRequest,
 * SubmitDiscountPolicyChange, SubmitFeeScheduleChange — each implicitly and unconditionally (always yes),
 * and six more of Segun's ten business items are coming. This puts that decision behind ONE named place so
 * that when it becomes configurable (a school saying "under ₦50,000 the Officer just does it") it changes
 * here, not in ten call sites.
 *
 * It changes NO behaviour today: {@see for()} always returns required=true. Lives under App\Finance (not
 * App\Support) because ArchitectureBoundaryTest forbids App\Support from using App\Finance, and this needs
 * Finance types.
 *
 * ── Why NOT a bare bool (the return type is the one deliberate shaping-ahead) ──
 * The seam's whole purpose is SIGNATURE STABILITY. When finance_approval_rules lands, a straight-through
 * (no-checker) row must record WHICH rule authorised it — because every approval table carries
 * `CHECK (submitted_by IS NULL OR decided_by IS NULL OR submitted_by <> decided_by)`, so "approval not
 * required" can NEVER be "the maker auto-approves their own row" (unrepresentable, by design). A
 * straight-through row is `decided_by IS NULL` with the approval attributed to a RULE, not a person — and
 * the audit trail must answer "who approved this?" with "rule #N at school X, in force that day". A bool
 * return would force every call site to change AGAIN to carry the rule id — the exact churn the seam exists
 * to avoid. `$amount` is on the signature for the same reason: a threshold rule needs it, and retrofitting
 * an argument later touches every caller.
 */
final readonly class ApprovalRequirement
{
    public function __construct(
        public bool $required,
        public ?int $ruleId = null,   // the finance_approval_rules row that decided it; NULL while the table does not exist
    ) {}

    /**
     * Does this submission need a checker? Keyed on the MAKER ABILITY (e.g. 'finance.credit-note.submit'),
     * because that is exactly what DutySeparation::pairs() already derives via the ApprovalAbility
     * convention — a hand-maintained enum of transaction types would be a second list to keep in step.
     *
     * FAIL CLOSED, and this is the guarantee: until finance_approval_rules exists, EVERY finance maker
     * action requires a checker — including an ability string that matches no pair at all. When the table
     * lands, this body becomes a lookup on (school_id, maker ability) where an ABSENT row still means
     * "approval required" — never the reverse. F-4 pins this; do not break it.
     */
    public static function for(string $makerAbility, ?Money $amount = null): self
    {
        return new self(required: true);
    }
}
