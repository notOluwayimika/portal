<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradingSchemeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'mode' => $this->mode,
            'version' => $this->version,
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->uuid,
                'code' => $item->code,
                'label' => $item->label,
                'display_order' => $item->display_order,
                // The comment suggestions for this rating, shipped WITH the rating for the same
                // reason numeric bands ship with the page: the categorical grid must not fetch
                // per student. Empty when the relation was not eager-loaded (report cards, the
                // setup matrix) so no consumer pays for comments it does not render.
                'comments' => CommentEntryResource::collection(
                    $item->relationLoaded('activeComments') ? $item->activeComments : collect()
                ),
            ]),
        ];
    }
}
