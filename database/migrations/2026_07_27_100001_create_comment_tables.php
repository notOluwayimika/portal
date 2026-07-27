<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school comment suggestions for score entry.
 *
 * Replaces seven hardcoded arrays in `resources/js/components/score-entry-page.tsx` with content a
 * school owns. TWO tables, because "a set of comments for each thing" is a one-to-many: the parent
 * says WHICH result a comment applies to, the entries are the comments.
 *
 * THE PARENT IS POLYMORPHIC, because the two grading modes band on different things:
 *
 *   - NUMERIC curricula band on a score range   → parent is a `comment_bands` row
 *   - CATEGORICAL curricula band on a rating    → parent is a `grading_scheme_items` row
 *
 * Categorical ratings deliberately do NOT get mapped onto a 0-100 scale. `grading_scheme_items`
 * carries only `code`, `label` and `display_order`, and a real scheme includes entries like "Not
 * Applicable" — so ordinal position is not a quality ranking, and mapping it to a score would file
 * "Not Applicable" under the lowest band and offer "This result is below expectation" for it.
 * Hanging comments off the rating itself is the only reading that is true to the data.
 *
 * One entry table means one 100-character rule, one retire mechanism, one reorder endpoint and one
 * admin affordance for both modes — which is what "categorical should work the same way numeric
 * does" actually requires.
 *
 * WHY COMMENT BANDS DO NOT HANG OFF `grade_boundaries`. The obvious move for the numeric side —
 * attach comments to the existing grade bands — loses granularity the hardcoded ladder already
 * had, in BOTH directions. Against the seeded default scale (`GradeBoundarySeeder::DEFAULTS`):
 *
 *   score 95, 85, 75  → all grade A (70-101), but Outstanding / Excellent / Very good
 *   score 47          → grade D (45-50) ┐ both "Needs improvement" (40-50)
 *   score 42          → grade E (40-45) ┘
 *
 * Comments are FINER than grades above 70 and COARSER below 50. Sharing ranges would silently
 * merge three suggestion sets into one for every A student — a regression against what teachers
 * see today, pinned by "keeps three distinct comment sets across 70-100" in CommentBandTest.
 *
 * COVERAGE IS STRUCTURAL, NOT VALIDATED. `min_score` is the only authoritative bound on a band: a
 * score belongs to the highest band whose minimum it reaches, exactly as the hardcoded ladder
 * behaved. `max_score` is DERIVED on save (the next band's minimum, or 100 at the top) and is
 * stored for display only. With one bound per band plus "the lowest band starts at 0", a gap or an
 * overlap cannot be expressed at all — so the invariant needs no CHECK and no validation rule that
 * a later write path could sail past.
 *
 * It also disposes of an upper-edge bug rather than inheriting it: GradeBoundary::resolveGrade
 * matches on `max_score > $score`, so a 90-100 band misses a score of exactly 100 — which is why
 * GradeBoundarySeeder writes 101 for its top band. Resolving downward from the minimum has no
 * upper edge to get wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_bands', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();

            // NULL = the school's default set, used when an exam type has no set of its own.
            // Mirrors `grade_boundaries.exam_type_id` and the `exam_type_id IS NULL ASC` fallback
            // in GradeBoundary::resolveGrade, rather than inventing a second fallback rule.
            //
            // CASCADE, not nullOnDelete: an exam type's overrides are meaningless once the exam
            // type is gone, and nulling them would silently PROMOTE them to the school default,
            // overwriting the set the school actually configured there.
            $table->foreignId('exam_type_id')->nullable()->constrained('exam_types')->cascadeOnDelete();

            // Same precision as grade_boundaries, so the two ladders compare exactly when the
            // admin UI draws them against each other.
            //
            // min_score is authoritative and user-supplied; max_score is DERIVED server-side on
            // every save and is never read during resolution. It is stored rather than computed on
            // read so the admin UI and the API can render "70 - 79" without re-deriving it.
            $table->decimal('min_score', 5, 2);
            $table->decimal('max_score', 5, 2);

            // The school's own name for the range ("Outstanding", "Needs improvement").
            $table->string('label', 50);

            $table->timestamps();

            $table->unique(['school_id', 'exam_type_id', 'min_score'], 'comment_bands_set_min_unique');
            $table->index(['school_id', 'exam_type_id']);
        });

        Schema::create('comment_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // CommentBand (numeric) or GradingSchemeItem (categorical). No `school_id` here: an
            // entry is reachable only through its parent, which carries the school on it or on its
            // own parent, so isolation is inherited rather than duplicated — there is no second
            // copy to drift out of agreement with the first.
            $table->morphs('commentable');

            // 100 to match `student_subjects.comment` exactly (widened in the migration directly
            // before this one). A suggestion the teacher cannot save is worse than no suggestion,
            // which is the bug this pairing exists to prevent.
            $table->string('body', 100);

            // Teachers scan the datalist top-down, so schools want to control what comes first.
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Retire a comment without destroying the history of students who already have it.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Duplicate suggestions under one parent are noise, not a feature.
            $table->unique(
                ['commentable_type', 'commentable_id', 'body'],
                'comment_entries_body_unique'
            );
            $table->index(
                ['commentable_type', 'commentable_id', 'is_active', 'sort_order'],
                'comment_entries_lookup'
            );
        });
    }

    public function down(): void
    {
        // Child first. The parent link is polymorphic so there is no FK forcing the order, but the
        // read direction is the same and dropping in this order keeps that obvious.
        Schema::dropIfExists('comment_entries');
        Schema::dropIfExists('comment_bands');
    }
};
