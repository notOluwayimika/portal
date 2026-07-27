<?php

namespace App\Models;

use App\Concerns\AddUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One comment offered to a teacher entering a result.
 *
 * Its parent is polymorphic because the two grading modes band on different things: a
 * {@see CommentBand} (a score range) for numeric curricula, a {@see GradingSchemeItem} (a rating)
 * for categorical ones. One entry table so both modes get the same length rule, the same retire
 * and reorder behaviour, and the same admin affordance — which is what "categorical should work
 * the same way numeric does" actually requires.
 *
 * No `school_id` and no SchoolScope: an entry is reachable only through its parent, which carries
 * the school (CommentBand) or reaches it through its own parent (GradingSchemeItem →
 * GradingScheme). Every read path goes parent-first, so isolation is inherited rather than
 * duplicated, and there is no second copy of `school_id` to drift out of agreement with the first.
 * The write paths enforce that pairing explicitly — see CommentEntryController.
 *
 * `body` is capped at 100 to match `student_subjects.comment` exactly. That pairing is the whole
 * point of the widening migration that precedes this table: a suggestion a teacher cannot save is
 * worse than no suggestion, and that is precisely what shipped when the column was 50 and one of
 * the hardcoded defaults was 52 characters.
 */
class CommentEntry extends Model
{
    use AddUuid, LogsActivity;

    /** Must equal the `student_subjects.comment` column width. Pinned by CommentBandTest. */
    public const MAX_LENGTH = 100;

    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'body',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static $logName = 'academics';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['body', 'sort_order', 'is_active'])
            ->logOnlyDirty();
    }
}
