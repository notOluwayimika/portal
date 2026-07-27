<?php

namespace App\Models;

use App\Concerns\HasCommentEntries;
use App\Contracts\Commentable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One rating in a categorical grading scheme ("Good Progress", "Working on Skills", …).
 *
 * Carries the comment suggestions a teacher is offered when they give a student this rating —
 * the categorical counterpart of a {@see CommentBand}, through the same {@see HasCommentEntries}
 * relations so the score entry page never branches on grading mode.
 *
 * Ratings are deliberately NOT mapped onto a 0-100 scale to reuse the numeric bands. This table
 * holds only `code`, `label` and `display_order`, and real schemes include entries like "Not
 * Applicable" — so ordinal position is not a quality ranking, and any mapping would file "Not
 * Applicable" at the bottom and suggest "This result is below expectation" for it.
 */
class GradingSchemeItem extends Model implements Commentable
{
    use HasCommentEntries;

    protected $fillable = ['grading_scheme_id', 'code', 'label', 'display_order'];

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class);
    }

    /**
     * The school that owns this rating, reached through its scheme.
     *
     * Neither this table nor `grading_scheme_items` carries `school_id` — ownership lives on
     * `grading_schemes`. Comment writes against a rating must check this rather than assume route
     * binding scoped it, because `GradingSchemeItem` has no SchoolScope of its own.
     */
    public function ownerSchoolId(): ?int
    {
        return $this->gradingScheme()->withoutGlobalScopes()->value('school_id');
    }
}
