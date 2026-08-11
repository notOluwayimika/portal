<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\FeeItem;
use App\Finance\Models\FeeSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FeeSchedule
 */
class FeeScheduleResource extends JsonResource
{
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
        ];
    }
}
