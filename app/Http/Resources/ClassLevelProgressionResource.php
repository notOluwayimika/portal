<?php

namespace App\Http\Resources;

use App\Models\ClassLevel;
use App\Models\ClassLevelExamType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassLevel
 *
 * One class level's progression configuration, as the panel needs it.
 *
 * UUIDS, NEVER IDS, on every reference — the frontend submits uuids back and the controller resolves
 * them through the school relation, which is the pattern that keeps a cross-school id out. Leaking
 * an internal id here would invite a caller to send one.
 *
 * `is_terminal` is COMPUTED here rather than left to the UI. "No next level" is a meaningful
 * configuration answer (a graduating year, out of which nobody is promoted) and not the same thing
 * as "not configured yet" — a distinction the screen has to render differently, and one it should
 * not have to re-derive from a null.
 */
class ClassLevelProgressionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'order' => $this->order,

            'next_class_level' => $this->whenLoaded('nextClassLevel', fn () => $this->nextClassLevel
                ? ['id' => $this->nextClassLevel->uuid, 'name' => $this->nextClassLevel->name]
                : null),
            'is_terminal' => $this->next_class_level_id === null,

            'default_exam_type' => $this->whenLoaded('defaultExamType', fn () => $this->defaultExamType
                ? ['id' => $this->defaultExamType->uuid, 'name' => $this->defaultExamType->name]
                : null),

            'arm_distribution_strategy' => $this->arm_distribution_strategy,

            'participation' => $this->whenLoaded('termParticipation', fn () => $this->termParticipation
                ->sortBy('term_order')
                ->values()
                ->map(fn ($row) => [
                    'id' => $row->uuid,
                    'term_order' => (int) $row->term_order,
                    'is_ccm' => (bool) $row->is_ccm,
                ])),

            // Reject the rows whose exam type is gone BEFORE mapping, so the shape this emits is
            // uniformly `{id, name}` with no nullable id for the frontend to guard.
            'exam_types' => $this->whenLoaded('examTypes', fn () => $this->examTypes
                ->filter(fn (ClassLevelExamType $row) => $row->examType !== null)
                ->map(fn (ClassLevelExamType $row) => [
                    'id' => $row->examType->uuid,
                    'name' => $row->examType->name,
                ])
                ->values()),
        ];
    }
}
