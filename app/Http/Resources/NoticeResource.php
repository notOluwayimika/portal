<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NoticeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'body' => $this->body,
            'target_gender' => $this->target_gender,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->isActive(),
            'created_at' => $this->created_at?->toIso8601String(),
            'category' => $this->whenLoaded('category', fn () => new NoticeCategoryResource($this->category)),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'class_levels' => $this->whenLoaded('classLevels', fn () => $this->classLevels->map(fn ($cl) => [
                'id' => $cl->uuid,
                'name' => $cl->name,
            ])),
            'class_level_arms' => $this->whenLoaded('classLevelArms', fn () => $this->classLevelArms->map(fn ($cla) => [
                'id' => $cla->uuid,
                'name' => $cla->name,
            ])),
            'students' => $this->whenLoaded('students', fn () => $this->students->map(fn ($s) => [
                'id' => $s->uuid,
                'name' => trim("{$s->first_name} {$s->last_name}"),
            ])),
        ];
    }
}
