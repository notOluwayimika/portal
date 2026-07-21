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
        public Money $amount,
        public ?int $feeItemId = null,
        /**
         * What the line MEANS. The sign of $amount carries the arithmetic; this
         * carries the reason. Defaults to Charge so every existing construction site
         * keeps its exact behaviour.
         */
        public InvoiceLineKind $kind = InvoiceLineKind::Charge,
        /** Optional human "why" for a reduction — free text, never parsed. */
        public ?string $note = null,
    ) {}

    public function isReduction(): bool
    {
        return $this->kind->isReduction();
    }
}
