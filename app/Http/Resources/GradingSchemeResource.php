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
            ]),
        ];
    }
}
