<?php

namespace App\Http\Resources;

use App\Models\CommentBand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommentBand
 */
class CommentBandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'min_score' => (float) $this->min_score,
            'max_score' => (float) $this->max_score,
            'label' => $this->label,

            // Always the ACTIVE entries, in the school's chosen order. The score-entry page and
            // the admin tab both want exactly this list, and shipping it with the band is what
            // keeps CommentCell free of a fetch per row.
            'comments' => CommentEntryResource::collection(
                $this->whenLoaded('activeComments')
            ),
        ];
    }
}
