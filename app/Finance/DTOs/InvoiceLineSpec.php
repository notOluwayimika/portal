<?php

namespace App\Finance\DTOs;

use App\Finance\Enums\DiscountBase;
use App\Finance\Enums\InvoiceLineKind;
use App\Support\Money;

/**
 * One requested invoice line, validated and typed at the edge and passed into
 * GenerateInvoice. A line is a SNAPSHOT value (docs/finance-data-ownership.md):
 * the description and amount are captured at billing time and never re-joined to
 * a mutable fee/catalog row, so a historical invoice still reads exactly what was
 * billed after the fee schedule changes.
 *
 * feeItemId is nullable LOOKUP provenance only — where the price came from. It is
 * never load-bearing and never joined for display.
 *
 * The caller supplies lines; it never supplies a total. The invoice total is
 * DERIVED from these specs inside the creating transaction (F6), which is what
 * makes "total = SUM(lines)" true by construction rather than by trust.
 */
final readonly class InvoiceLineSpec
{
    public function __construct(
        public string $description,
        /**
         * The concrete signed amount, OR null for a percentage reduction that has not
         * yet been resolved. GenerateInvoice resolves every $percent spec into a
         * concrete-amount spec before anything reads $amount, so downstream code never
         * sees null — see resolvedAmount().
         */
        public ?Money $amount,
        public ?int $feeItemId = null,
        /**
         * What the line MEANS. The sign of $amount carries the arithmetic; this
         * carries the reason. Defaults to Charge so every existing construction site
         * keeps its exact behaviour.
         */
        public InvoiceLineKind $kind = InvoiceLineKind::Charge,
        /** Optional human "why" for a reduction — free text, never parsed. */
        public ?string $note = null,
        /**
         * A percentage reduction (1–100), resolved against the invoice's gross charges
         * by the Action into a concrete negative $amount. Mutually exclusive with a
         * supplied $amount, and only valid on a reduction kind. Transient input: the
         * STORED line is always the resolved naira figure, never "10%" — snapshot
         * integrity means a historical statement shows the exact reduction, not a
         * percentage recomputed against numbers that may have moved.
         *
         * WHAT it is a percentage OF is $percentBase, below.
         */
        public ?int $percent = null,
        /**
         * The discount policy this REDUCTION line cites (S1 3b) — a LOOKUP id, off the wire because the
         * bursar chooses which active policy backs the reduction. Null on a charge line. The DB
         * finance_invoice_lines_reduction_guard is the guarantee: a reduction with null (or a non-active /
         * approval-requiring / cross-school) policy is refused at INSERT; a charge with a policy is refused.
         * Added AFTER the existing params so positional construction sites (DriveFinanceStates) keep working.
         */
        public ?int $discountPolicyId = null,
        /**
         * Whether this CHARGE line is inside the percentage-reduction base (S1 3.6). A property of the fee
         * ITEM, NOT a wire claim — resolved server-side in GenerateInvoice from finance_fee_items by
         * feeItemId, never read from request input (a client could otherwise shrink or inflate a percentage
         * base). Default TRUE everywhere, so a free-text line and a school that configures nothing behave
         * exactly as before. resolvePercentages() excludes charge lines with isDiscountable === false.
         */
        public bool $isDiscountable = true,
        /**
         * WHAT this percentage reduction is a percentage OF ({@see DiscountBase}) — the discountable
         * charge lines, or every charge line. Read PER SPEC by GenerateInvoice::resolvePercentages(),
         * so two percentage reductions on one invoice may sit on different bases.
         *
         * A PROPERTY OF THE CITED POLICY, NOT A WIRE CLAIM — exactly like $isDiscountable above, and
         * for the same reason. GenerateInvoice::resolveDiscountBase() OVERWRITES whatever a caller
         * put here with `finance_discount_policies.base` for the policy this line cites, before any
         * percentage is resolved. Two consequences worth stating: a client cannot widen its own
         * discount by asserting `total` on a tuition-only policy, and the bulk run and the bursar's
         * modal cannot disagree about the same policy — they do not each decide, so there is nothing
         * to drift. A caller that sets this is describing, not deciding.
         *
         * IT IS MEANINGLESS WITHOUT $percent, AND THE CONSTRUCTOR REFUSES THAT COMBINATION rather
         * than tolerating it. A charge line carrying a base would be a value nothing reads: the
         * resolver only ever consults it on a spec where isPercentage() is true, so a base on a
         * charge line is either a caller's mistake about what this DTO does or a rename away from
         * becoming one. Silently ignoring it is how a field comes to mean nothing while looking
         * load-bearing — the same defect as a control the server never receives. So it throws, at
         * construction, in the caller's own stack frame.
         *
         * THE OTHER DIRECTION IS NOT AN ERROR: $percent WITHOUT $percentBase resolves against the
         * DISCOUNTABLE charges. That is what every percentage did before this field existed, so
         * every construction site that predates it — the bursar's modal, the 27 under tests/ — keeps
         * its exact behaviour without being touched. Null here means "the default base", not
         * "unset and therefore suspect".
         */
        public ?DiscountBase $percentBase = null,
    ) {
        if ($percentBase !== null && $percent === null) {
            throw new \LogicException(
                'InvoiceLineSpec was given a discount base with no percentage. A base is what a '
                .'percentage is taken OF; on a line with no percentage nothing reads it.'
            );
        }
    }

    public function isReduction(): bool
    {
        return $this->kind->isReduction();
    }

    public function isPercentage(): bool
    {
        return $this->percent !== null;
    }

    /**
     * The base this percentage sits on, defaulted. Null $percentBase means the pre-existing
     * behaviour — see the constructor — so the default is expressed HERE, once, rather than by every
     * reader writing `?? DiscountBase::Discountable` and one of them eventually not.
     */
    public function percentBase(): DiscountBase
    {
        return $this->percentBase ?? DiscountBase::Discountable;
    }

    /** The concrete amount once resolved; guards the null window. */
    public function resolvedAmount(): Money
    {
        if ($this->amount === null) {
            throw new \LogicException('InvoiceLineSpec amount was read before its percentage was resolved.');
        }

        return $this->amount;
    }

    /**
     * A copy of this spec with a concrete amount and no pending percentage (carrying the provenance
     * fields).
     *
     * THE BASE IS DROPPED WITH THE PERCENTAGE, and it must be: they are one fact in two fields, and
     * a resolved spec keeping its base while losing its percent would violate this class's own
     * constructor invariant — it would throw here, inside the resolver, on the line it just resolved
     * correctly. Nothing downstream reads it either; the resolved line stores the naira figure.
     */
    public function withAmount(Money $amount): self
    {
        return new self($this->description, $amount, $this->feeItemId, $this->kind, $this->note, null, $this->discountPolicyId, $this->isDiscountable, null);
    }

    /**
     * A copy of this spec with the server-resolved percentage base — never taken from the wire.
     *
     * ONLY EVER CALLED ON A PERCENTAGE SPEC (GenerateInvoice::resolveDiscountBase() skips the rest),
     * because handing a base to a line with no percentage is exactly what the constructor refuses.
     * Passing null is legal and means the default base, so an unresolvable policy id resolves DOWN to
     * `discountable` rather than keeping whatever the caller asked for.
     */
    public function withPercentBase(?DiscountBase $percentBase): self
    {
        return new self($this->description, $this->amount, $this->feeItemId, $this->kind, $this->note, $this->percent, $this->discountPolicyId, $this->isDiscountable, $percentBase);
    }

    /** A copy of this spec with the server-resolved discountability (S1 3.6 — never taken from the wire). */
    public function withDiscountable(bool $isDiscountable): self
    {
        return new self($this->description, $this->amount, $this->feeItemId, $this->kind, $this->note, $this->percent, $this->discountPolicyId, $isDiscountable, $this->percentBase);
    }
}
