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
            'label' => $this->label,
            'status' => $this->status->value,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (FeeItem $item) => [
                'id' => $item->uuid,
                'description' => $item->description,
                'amount' => $item->amount, // Money → {amount_minor, currency}
                'is_mandatory' => $item->is_mandatory,
                'is_discountable' => $item->is_discountable,
                'sort_order' => $item->sort_order,
            ])),
        ];
    }
}
