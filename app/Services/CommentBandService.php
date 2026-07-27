<?php

namespace App\Services;

use App\Models\CommentBand;
use App\Support\ActiveSchool;
use App\Support\CommentBandDefaults;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes to a school's comment-band ladder.
 *
 * Everything here runs inside one transaction and rewrites a whole set at a time. A comment ladder
 * with a half-applied edit in it is worse than one that failed to save: the teacher sees plausible
 * suggestions drawn from a state nobody chose.
 */
class CommentBandService
{
    /**
     * Replace the band set for one exam type with $bands.
     *
     * Bands are matched by uuid so their comments survive a range or label edit — the entries hang
     * off the band, and re-creating the row would take them with it. A band the payload omits is
     * one the admin deleted, and its entries go with it (the FK cascades); that is the intent of
     * removing a band, and the UI warns before sending it.
     *
     * `max_score` is derived here and only here: sort descending by minimum, and each band runs up
     * to the next one's minimum, with the top band running to MAX_SCORE. No caller supplies it, so
     * no caller can put the two bounds out of agreement.
     *
     * @param  list<array{id?: string|null, min_score: float|int|string, label: string}>  $bands
     * @return Collection<int, CommentBand>
     */
    public function saveSet(?int $examTypeId, array $bands): Collection
    {
        return DB::transaction(function () use ($examTypeId, $bands) {
            $schoolId = ActiveSchool::getOrFail()->id;

            usort($bands, static fn ($a, $b) => (float) $b['min_score'] <=> (float) $a['min_score']);

            $keptIds = [];

            foreach ($bands as $index => $band) {
                $min = (float) $band['min_score'];

                // The band above this one starts where this one ends. The first band in a
                // descending list has nothing above it, so it runs to the top of the scale.
                $max = $index === 0
                    ? (float) CommentBand::MAX_SCORE
                    : (float) $bands[$index - 1]['min_score'];

                $attributes = [
                    'min_score' => $min,
                    'max_score' => $max,
                    'label' => $band['label'],
                ];

                $existing = ! empty($band['id'])
                    ? CommentBand::where('uuid', $band['id'])->first()
                    : null;

                if ($existing) {
                    $existing->update($attributes);
                    $keptIds[] = $existing->id;

                    continue;
                }

                $created = CommentBand::create([
                    'school_id' => $schoolId,
                    'exam_type_id' => $examTypeId,
                    ...$attributes,
                ]);

                $keptIds[] = $created->id;
            }

            // Whatever the payload did not mention is gone. The SchoolScope on the model keeps
            // this delete inside the active school even though the ids came off the request.
            CommentBand::query()
                ->forExamType($examTypeId)
                ->whereNotIn('id', $keptIds)
                ->get()
                ->each->delete();

            return CommentBand::query()
                ->forExamType($examTypeId)
                ->with('activeComments')
                ->orderByDesc('min_score')
                ->get();
        });
    }

    /**
     * Import {@see CommentBandDefaults} into an exam type that has no bands yet.
     *
     * Refuses when a set already exists rather than merging or overwriting: the school has made
     * editorial decisions by then, and neither outcome of a blind import is one they asked for.
     * The caller surfaces that as a 422, not a silent no-op.
     *
     * @return Collection<int, CommentBand>
     */
    public function loadDefaults(?int $examTypeId): Collection
    {
        return DB::transaction(function () use ($examTypeId) {
            $schoolId = ActiveSchool::getOrFail()->id;

            $bands = CommentBandDefaults::bands();

            foreach ($bands as $index => $band) {
                $created = CommentBand::create([
                    'school_id' => $schoolId,
                    'exam_type_id' => $examTypeId,
                    'min_score' => $band['min'],
                    'max_score' => $index === 0
                        ? CommentBand::MAX_SCORE
                        : $bands[$index - 1]['min'],
                    'label' => $band['label'],
                ]);

                foreach ($band['comments'] as $position => $body) {
                    $created->comments()->create([
                        'body' => $body,
                        'sort_order' => $position,
                        'is_active' => true,
                    ]);
                }
            }

            return CommentBand::query()
                ->forExamType($examTypeId)
                ->with('activeComments')
                ->orderByDesc('min_score')
                ->get();
        });
    }
}
