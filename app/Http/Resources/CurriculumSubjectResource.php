<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurriculumSubjectResource extends JsonResource
{
    protected bool $includeStudentResults = true;

    public function withoutStudentResults(): static
    {
        $this->includeStudentResults = false;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'curriculum' => (new CurriculumResource($this->curriculum))->withoutSubjects(),
            'subject' => new SubjectResource($this->subject),
            'is_compulsory' => $this->is_compulsory,
            'display_order' => $this->display_order,
            'active' => $this->active ?? true,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'teachers' => TeacherCurriculumSubjectResource::collection($this->whenLoaded('teacherAssignments')),
            'marking_components' => MarkingComponentResource::collection($this->effectiveMarkingComponents()),
            'students' => StudentSubjectResource::collection($this->whenLoaded('studentAssignments')),
            'scores' => ScoreResource::collection($this->whenLoaded('scores')),
            'student_results' => $this->when(
                $this->includeStudentResults,
                StudentResultResource::collection($this->whenLoaded('studentResults')),
            ),
            'result_status' => new SubjectResultStatusResource($this->whenLoaded('resultStatus')),
            'class_average' => $this->when($this->relationLoaded('studentResults'), function () {
                $scores = $this->studentResults
                    ->map(fn ($result) => (float) $result->total_score)
                    ->filter(fn ($score) => $score !== 0.0 && ! is_nan($score));

                return $scores->isNotEmpty() ? round($scores->avg(), 2) : null;
            }),
        ];
    }
}
