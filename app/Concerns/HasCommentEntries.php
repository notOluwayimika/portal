<?php

namespace App\Concerns;

use App\Models\CommentBand;
use App\Models\CommentEntry;
use App\Models\GradingSchemeItem;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Something a teacher's comment suggestions hang off: a {@see CommentBand} (a score
 * range, for numeric curricula) or a {@see GradingSchemeItem} (a rating, for
 * categorical ones).
 *
 * The trait exists so both sides expose the SAME two relations under the same names. The score
 * entry page, the resources and the entry controller are then written once against
 * `activeComments` and never branch on grading mode — which is the whole point of giving the two
 * modes one comment table.
 */
trait HasCommentEntries
{
    /**
     * A polymorphic parent has NO foreign key, so nothing in the database cleans up after it —
     * deleting a band or a rating would leave its comments behind as rows pointing at an id that
     * no longer exists, and the next parent to reuse that id would inherit a dead school's
     * suggestions. The `comment_band_id` FK this replaced cascaded for free; this listener is what
     * pays for the polymorphism. Caught by "deletes bands the payload omits, along with their
     * comments" going red on the refactor.
     */
    protected static function bootHasCommentEntries(): void
    {
        static::deleting(function (self $parent) {
            $parent->comments()->delete();
        });
    }

    /** @return MorphMany<CommentEntry, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(CommentEntry::class, 'commentable');
    }

    /**
     * Active comments only, in the order the school arranged them.
     *
     * `id` is the tiebreaker so a set of entries that all share `sort_order` (everything the
     * starter import writes into one parent, before anyone reorders) still comes back in a stable
     * order rather than whatever the storage engine feels like returning.
     *
     * @return MorphMany<CommentEntry, $this>
     */
    public function activeComments(): MorphMany
    {
        return $this->comments()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
