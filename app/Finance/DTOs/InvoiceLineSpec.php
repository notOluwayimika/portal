<?php

namespace App\Finance\DTOs;

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
    ) {}

    public function isReduction(): bool
    {
        return $this->kind->isReduction();
    }

    public function isPercentage(): bool
    {
        return $this->percent !== null;
    }

    /** The concrete amount once resolved; guards the null window. */
    public function resolvedAmount(): Money
    {
        if ($this->amount === null) {
            throw new \LogicException('InvoiceLineSpec amount was read before its percentage was resolved.');
        }

        return $this->amount;
    }

    /** A copy of this spec with a concrete amount and no pending percentage (carrying the provenance fields). */
    public function withAmount(Money $amount): self
    {
        return new self($this->description, $amount, $this->feeItemId, $this->kind, $this->note, null, $this->discountPolicyId, $this->isDiscountable);
    }

    /** A copy of this spec with the server-resolved discountability (S1 3.6 — never taken from the wire). */
    public function withDiscountable(bool $isDiscountable): self
    {
        return new self($this->description, $this->amount, $this->feeItemId, $this->kind, $this->note, $this->percent, $this->discountPolicyId, $isDiscountable);
    }
}
