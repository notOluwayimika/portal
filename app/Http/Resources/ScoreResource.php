<?php

namespace App\Http\Resources;

use App\Models\Score;
use App\Support\ScoreUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Score
 */
class ScoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'student' => new StudentResource($this->whenLoaded('student')),
            'marking_component' => new MarkingComponentResource($this->whenLoaded('markingComponent')),
            // The WEIGHTED value as stored. Unchanged, and still what every existing consumer
            // (totals, report sheets, student_results) reads.
            'score' => $this->score,

            // The percentage the teacher actually typed — computed HERE rather than by dividing in
            // the browser, which is what let a stale bundle show a 100 as 10.0. Absent when the
            // component is not loaded, so this can never trigger an N+1; the entry grid already
            // skips a score whose marking_component is missing.
            'score_percent' => $this->whenLoaded(
                'markingComponent',
                fn () => ScoreUnit::toPercent($this->score, $this->markingComponent),
            ),
            'created_by' => new UserResource($this->whenLoaded('created_by')),
        ];
    }
}
