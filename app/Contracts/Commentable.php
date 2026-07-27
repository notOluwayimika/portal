<?php

namespace App\Contracts;

use App\Concerns\HasCommentEntries;
use App\Models\CommentBand;
use App\Models\CommentEntry;
use App\Models\GradingSchemeItem;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Something a teacher's comment suggestions hang off — a {@see CommentBand} (a score
 * range, for numeric curricula) or a {@see GradingSchemeItem} (a rating, for
 * categorical ones).
 *
 * The interface exists so shared code can be typed against the capability rather than against a
 * union of the two models: `CommentEntryController` takes `Model&Commentable` and never branches
 * on grading mode. {@see HasCommentEntries} is the one implementation.
 */
interface Commentable
{
    /** @return MorphMany<CommentEntry, covariant \Illuminate\Database\Eloquent\Model> */
    public function comments(): MorphMany;

    /** @return MorphMany<CommentEntry, covariant \Illuminate\Database\Eloquent\Model> */
    public function activeComments(): MorphMany;
}
