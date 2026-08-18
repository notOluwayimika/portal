<?php

namespace App\Finance\Services;

use App\Exceptions\BusinessRuleException;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Models\FeeSchedule;

/**
 * A fee schedule in, the lines of one term bill out (U6 commit 2). This is the first thing in the
 * application that can say WHAT A TERM BILL CONTAINS: {@see InvoiceLineSpec} was constructed in exactly
 * one place before this file — GenerateInvoiceRequest::lineSpecs(), from a body a human typed into the
 * bursar's modal — and {@see FeeScheduleLookup::activeFor()} returned a schedule that nothing turned
 * into lines. The bulk-generation job (commits 3 and 4) is not writable until this exists.
 *
 * PURE. No HTTP, no queue, no write, and no Money arithmetic of its own — each line carries the item's
 * own Money value unchanged, and the total is still DERIVED inside GenerateInvoice's transaction (F6).
 *
 * MANDATORY ITEMS ONLY, and this is a RULING, not an oversight to be tidied up later. Nothing in the
 * schema records which student takes the bus or eats lunch — `finance_fee_items.is_mandatory` is a
 * property of the PRICE LIST, not of a child — so a cohort run has no way to know who owes an optional
 * item. Guessing would bill transport to a child who walks. Optional items are therefore added per
 * child afterwards, singly through the generate modal or as a supplementary invoice
 * ({@see InvoiceKind::Supplementary}). Do not "fix" this into billing everything.
 *
 * CHARGE LINES ONLY. Every line is {@see InvoiceLineKind::Charge}; `discountPolicyId` and `percent` are
 * never set. U8's discount AWARD (which student gets which policy) does not exist, so there is no fact
 * from which a bulk reduction line could be justified — and keeping reductions out means the
 * finance_invoice_lines_reduction_guard trigger, which refuses a reduction whose policy is absent /
 * non-active / cross-School, is never reached from this path at all rather than being reached and
 * satisfied by accident.
 *
 * `isDiscountable` is read from the ITEM, never left to the DTO's `true` default — GenerateInvoice
 * re-resolves it server-side anyway (S1 3.6), so a wrong value here would be silently corrected there
 * and the bug would only ever show up in something that read these specs without the Action.
 *
 * DETERMINISTIC. Ordered by `sort_order` then `id`. `sort_order` alone is not a total order — the
 * column carries no uniqueness constraint and CreateFeeSchedule defaults it to the array index, so two
 * items can share one — and MySQL is free to return equal-key rows in any order. Two runs over the same
 * schedule must produce byte-identical lines in identical order, because a bulk run that is re-driven
 * after a partial failure must not produce a differently-ordered bill for the students it reaches
 * twice.
 *
 * IT TAKES A FeeSchedule, NOT (term, class level). The caller pins ONE version for the whole batch.
 * Resolving the schedule in here would re-read it per student, so an approval or a supersession landing
 * mid-batch would split one cohort run silently across two price lists.
 */
final class FeeScheduleLineMapper
{
    /**
     * @return list<InvoiceLineSpec>
     *
     * @throws BusinessRuleException when the schedule cannot produce a bill — refused ONCE for the
     *                               batch, naming the schedule, rather than N times naming students.
     */
    public function linesFor(FeeSchedule $schedule): array
    {
        // (c) NEVER-APPROVED, OR NO-LONGER-CURRENT. `active` is the only case a bill may be raised
        // from, and it is the same single filter FeeScheduleLookup::activeFor() applies — one rule, not
        // two. `draft` and `pending_approval` were never approved by the ED, so billing from either
        // would let a Head price a term unilaterally, which is the failure the S1 approval path exists
        // to prevent (a rejected publish returns the schedule to `draft`, so `pending_approval` is not
        // "nearly active" — it is undecided). `superseded` and `retired` WERE approved once, but the
        // school has since replaced or withdrawn them: raising a cohort's bills from one prices a whole
        // year group off a list the school has retired, and does it silently, N invoices wide.
        if ($schedule->status !== FeeScheduleStatus::Active) {
            throw new BusinessRuleException(
                "Fee schedule [{$schedule->uuid}] is {$schedule->status->value}; only an active schedule may be billed from."
            );
        }

        // The relation query, ordered here rather than trusting whatever the caller eager-loaded —
        // `->with('items')` anywhere upstream would otherwise decide this method's output order.
        $items = $schedule->items()
            ->where('is_mandatory', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // (a) NOTHING MANDATORY. A schedule of purely optional items is a real, authorable thing, and it
        // yields zero lines — which GenerateInvoice would refuse per student with "An invoice must have
        // at least one line", once for every child in the cohort. Refusing here reports the schedule
        // once and names the schedule, which is what an operator can actually act on.
        if ($items->isEmpty()) {
            throw new BusinessRuleException(
                "Fee schedule [{$schedule->uuid}] has no mandatory items, so it cannot produce a term bill."
            );
        }

        // (b) MIXED CURRENCY. finance_fee_items.amount_currency carries a SHAPE check only (three
        // uppercase letters — 2026_08_01_120000_add_currency_shape_checks.php), and nothing constrains
        // the items of one schedule to agree, so a mixed schedule is constructible. Left alone it
        // detonates inside GenerateInvoice's transaction, where Money::plus throws on a currency
        // mismatch while folding the total — naming a student, mid-batch, for a defect in the price
        // list. Caught here it names the schedule, before the first invoice is written.
        $currencies = $items->map(fn ($item) => $item->amount->currency)->unique()->values();

        if ($currencies->count() > 1) {
            throw new BusinessRuleException(
                "Fee schedule [{$schedule->uuid}] mixes currencies (".$currencies->implode(', ').'); its mandatory items must agree.'
            );
        }

        return array_values($items->map(fn ($item) => new InvoiceLineSpec(
            description: $item->description,
            amount: $item->amount,
            // The INTEGER id. uuids are the wire convention (U8 commit 1 made the generate endpoint
            // refuse integers on the way in); ids are what finance_invoice_lines stores, and this
            // mapper is server-side on both ends — it never crosses the wire.
            feeItemId: $item->id,
            kind: InvoiceLineKind::Charge,
            isDiscountable: $item->is_discountable,
        ))->all());
    }
}
