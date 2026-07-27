<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Concerns\HasCommentEntries;
use App\Contracts\Commentable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A score range a school owns, holding the set of comments teachers are offered for scores in it.
 *
 * `min_score` IS THE AUTHORITATIVE FIELD. A score belongs to the highest band whose `min_score` is
 * at or below it — exactly how the hardcoded ladder this replaces behaved (`score >= 91`, then
 * `>= 80`, …). `max_score` is DERIVED on save (the next band's minimum, or 100 for the top band)
 * and exists for display only; nothing resolves against it.
 *
 * That choice is load-bearing rather than cosmetic. Storing both bounds as independent user input
 * makes gaps and overlaps expressible — a gap means some score resolves to no band and the teacher
 * silently gets no suggestions, an overlap means two bands match and the winner is arbitrary. With
 * one authoritative bound plus "the lowest band starts at 0", neither state can be constructed, so
 * the invariant is structural instead of a validation rule someone can route around. It also fixes
 * the top-edge bug for free: GradeBoundary::resolveGrade uses an EXCLUSIVE upper bound
 * (`max_score > $score`), so a 90-100 band does not match a score of exactly 100 — which is why
 * GradeBoundarySeeder has to write 101. Resolving downward from the minimum has no upper edge to
 * get wrong.
 *
 * Scoped per school (BelongsToSchool + SchoolScope) and optionally per exam type: a NULL
 * `exam_type_id` is the school's default set, used when the exam type has no set of its own —
 * the same fallback shape as `grade_boundaries`.
 */
/**
 * @property-read Collection<int, CommentEntry> $comments
 * @property-read Collection<int, CommentEntry> $activeComments
 */
class CommentBand extends Model implements Commentable
{
    use AddUuid, BelongsToSchool, HasCommentEntries, LogsActivity;

    /**
     * The top of the scale. Bands are stored as [min_score, next band's min), so the highest band
     * runs to here inclusive and this is the only place the ceiling is named.
     */
    public const MAX_SCORE = 100;

    protected $fillable = [
        'school_id',
        'exam_type_id',
        'min_score',
        'max_score',
        'label',
    ];

    protected $casts = [
        'min_score' => 'decimal:2',
        'max_score' => 'decimal:2',
    ];

    protected static $logName = 'academics';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class);
    }

    /**
     * The band set that applies to $examTypeId, highest band first.
     *
     * An exam type's own set wins outright; the school default (`exam_type_id IS NULL`) is used
     * only when that exam type has none. This is a WHOLE-SET fallback, not a per-band one —
     * mixing half of one ladder with half of another would reintroduce the gaps the model exists
     * to make impossible.
     *
     * @return Collection<int, static>
     */
    public static function setFor(?int $examTypeId): Collection
    {
        if ($examTypeId !== null) {
            $own = static::query()
                ->where('exam_type_id', $examTypeId)
                ->with('activeComments')
                ->orderByDesc('min_score')
                ->get();

            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        return static::query()
            ->whereNull('exam_type_id')
            ->with('activeComments')
            ->orderByDesc('min_score')
            ->get();
    }

    /**
     * The comments offered for $score under $examTypeId's set — the highest band whose minimum the
     * score reaches. Returns an empty collection when the school has configured no bands at all,
     * which is the legitimate day-one state: the datalist renders empty and free text still saves.
     *
     * @return Collection<int, CommentEntry>
     */
    public static function commentsFor(?int $examTypeId, float $score): Collection
    {
        foreach (static::setFor($examTypeId) as $band) {
            // The set arrives highest-first, so the first band the score reaches is its band.
            if ((float) $band->min_score <= $score) {
                return $band->activeComments;
            }
        }

        return new Collection;
    }

    /** @param  Builder<self>  $query */
    public function scopeForExamType(Builder $query, ?int $examTypeId): Builder
    {
        return $examTypeId === null
            ? $query->whereNull('exam_type_id')
            : $query->where('exam_type_id', $examTypeId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['min_score', 'max_score', 'label', 'exam_type_id'])
            ->logOnlyDirty();
    }
}
