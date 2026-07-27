<?php

namespace App\Http\Resources;

use App\Models\CommentEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommentEntry
 */
class CommentEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'body' => $this->body,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
