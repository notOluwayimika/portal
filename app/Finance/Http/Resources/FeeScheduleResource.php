<?php

namespace App\Finance\Http\Resources;

use App\Finance\Http\Controllers\FeeScheduleController;
use App\Finance\Models\FeeItem;
use App\Finance\Models\FeeSchedule;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin FeeSchedule
 */
class FeeScheduleResource extends JsonResource
{
    /**
     * Opt-in for `total` — see {@see self::withTotal()} for why this is a FLAG and not a whenLoaded.
     */
    private bool $withTotal = false;

    /**
     * Render `total` on this resource — the CATALOG shape, which the fee-schedules screen reads.
     *
     * THIS IS A FLAG AND NOT `whenLoaded('items', …)`, AND THE DIFFERENCE IS NOT COSMETIC. The
     * obvious move is to hang `total` off the same `whenLoaded('items')` the items themselves use,
     * on the reasoning that the billing read path would then not grow a key. That reasoning is
     * false: `prefill()` loads items — `loadMissing('items.bankAccount')`,
     * {@see FeeScheduleController::prefill()} — so `whenLoaded('items')`
     * is SATISFIED there and a total keyed on it appears on the billing payload, which the prefill
     * key-list arm pins precisely to stop.
     *
     * And it must not appear there. `prefill` hands the bursar's generate form a `lines` array to
     * confirm and post; a `schedule.total` sitting beside it is a figure that looks like "what to
     * charge this student" on the one payload where that reading is wrong — the schedule's total is
     * the price of a SLOT, not of an invoice, and the invoice's total is GenerateInvoice's to
     * compute from the lines the bursar actually confirms.
     *
     * Keying it on some other relation the billing path happens not to load (`term`, `classLevel`)
     * would give the right shape today for a reason that has nothing to do with totals, and would
     * silently move the day an eager-load changed. The four catalog responses ask for it by name.
     */
    public function withTotal(): static
    {
        $this->withTotal = true;

        return $this;
    }

    /**
     * The catalog shape for a LIST — `index()`'s response.
     *
     * Returns a Collection of resources rather than an AnonymousResourceCollection because each
     * member has to be flagged individually; `response()->json()` serialises both to the same bare
     * array (Collection and JsonResource are each JsonSerializable), so the wire shape is unchanged.
     *
     * @param  Collection<int, FeeSchedule>  $schedules
     * @return Collection<int, self>
     */
    public static function catalog(Collection $schedules): Collection
    {
        return $schedules->map(fn (FeeSchedule $schedule) => (new self($schedule))->withTotal());
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'term_id' => $this->term_id,
            'class_level_id' => $this->class_level_id,
            // The two ids above name a slot; they do not name it to a HUMAN. A list rendered from them
            // alone reads "Term 7 / Class level 12". BOTH labels go through whenLoaded deliberately:
            // `prefill()` builds this resource with only `items` loaded, on the billing read path, and an
            // unconditional accessor would lazy-load a term and its session there — per schedule, for a
            // payload that never displays either.
            //
            // whenLoaded: relation unloaded → key ABSENT (this is prefill). Relation loaded to NULL →
            // key PRESENT and null, returned before the closure runs (vendor
            // ConditionallyLoadsAttributes.php:284-286). No write path here produces a loaded-null term or
            // class level — both `exists` rules are School-scoped — so that second case is a shape
            // guarantee, not an observed one, and the `?->` is inert, kept only for coherence with
            // `$item->bankAccount?->uuid` below.
            //
            // term_label comes from Term::displayLabel(), which the opening-balance operator screen and
            // the approvals queue also read. Two screens naming the same term differently is how an
            // operator picks the wrong one — so the string is one method, not three expressions.
            'term_label' => $this->whenLoaded('term', fn () => $this->term?->displayLabel()),
            'class_level_label' => $this->whenLoaded('classLevel', fn () => $this->classLevel?->name),
            'label' => $this->label,
            'status' => $this->status->value,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (FeeItem $item) => [
                'id' => $item->uuid,
                'description' => $item->description,
                'amount' => $item->amount, // Money → {amount_minor, currency}
                // THE DESTINATION, AS A UUID — not the integer id. The uuid is the wire form everywhere
                // else: EditFeeScheduleDraftRequest's exists rule keys on uuid and EditFeeScheduleDraft
                // resolves uuid → id. Without this field an operator opening a draft to fix one typo
                // would have to re-pick the bank account for every line, from nothing, because the
                // screen was never told what those lines currently point at — and a wrong pick lands
                // money in the wrong account.
                'bank_account_id' => $item->bankAccount?->uuid,
                'is_mandatory' => $item->is_mandatory,
                'is_discountable' => $item->is_discountable,
                'sort_order' => $item->sort_order,
                // ->values()->all() rather than the bare Collection: Larastan reads the map's inferred
                // array shape as a Collection TValue, which is invariant, and rejects the closure's
                // return against itself. A list array says the same thing to json_encode and does not
                // ask PHPStan to unify two identical shapes across an invariant template.
            ])->values()->all()),
            // WHAT THE SCHEDULE COSTS, SUMMED IN PHP. The frontend performs no monetary arithmetic
            // (resources/js/lib/format.ts states it, bin/ci-money-lint.php enforces it), so a list of
            // schedules with no total is a list the person deciding whether to submit one for the ED's
            // approval cannot read — they would be adding eight lines in their head.
            //
            // NULL WHEN THE ITEMS DISAGREE ON CURRENCY, and that is a surfaced condition rather than a
            // silent one. Nothing makes a mixed-currency schedule unrepresentable: `items.*.currency`
            // accepts any /^[A-Z]{3}$/ (HasFeeScheduleItemRules) and the database CHECK on
            // finance_fee_items.amount_currency is a SHAPE check, deliberately not `= 'NGN'`
            // (2026_08_01_120000_add_currency_shape_checks). So two items in one schedule may legally
            // be NGN and USD today. Money::plus THROWS on a currency mismatch — which on this path
            // would be an uncaught InvalidArgumentException inside index(), i.e. one malformed
            // schedule 500s the WHOLE list for the School. Adding the minor units anyway would be
            // worse: a number that is not an amount of anything. Null is the third answer, and the
            // screen renders it as "Mixed currencies" rather than as a blank or a zero.
            'total' => $this->when($this->withTotal, fn () => $this->scheduleTotal()),
        ];
    }

    /**
     * The sum of the loaded items' amounts, or null if they do not agree on a currency.
     *
     * Summed THROUGH Money::plus rather than by adding `toKobo()` ints: the mismatch is the whole
     * reason this method exists, and integer addition is exactly the operation that cannot see it.
     * An item-less schedule totals zero — it is not billable, and zero says so; only a raw insert can
     * produce one (`items` is `required|min:1` on both write requests).
     */
    private function scheduleTotal(): ?Money
    {
        /** @var Collection<int, FeeItem> $items */
        $items = $this->items;

        if ($items->map(fn (FeeItem $item) => $item->amount->currency)->unique()->count() > 1) {
            return null;
        }

        return $items->reduce(
            fn (?Money $carry, FeeItem $item) => $carry === null ? $item->amount : $carry->plus($item->amount),
        ) ?? Money::fromKobo(0);
    }
}
