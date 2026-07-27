<?php

namespace App\Http\Controllers;

use App\Contracts\Commentable;
use App\Http\Resources\CommentEntryResource;
use App\Models\CommentBand;
use App\Models\CommentEntry;
use App\Models\GradingSchemeItem;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The comments inside one parent — a {@see CommentBand} (numeric score range) or a
 * {@see GradingSchemeItem} (categorical rating). One controller for both, so the two grading modes
 * cannot drift apart on length limits, duplicate handling, retirement or ordering.
 *
 * Entries are edited one at a time, unlike the bands themselves: adding a comment cannot make a
 * ladder incoherent, so there is nothing to make atomic across the set.
 *
 * ISOLATION. `CommentEntry` carries no `school_id` and no SchoolScope — it is reachable only
 * through its parent. The two parents prove ownership differently and BOTH are checked here:
 *
 *   - `CommentBand` has a SchoolScope, so route-model binding already fails closed for a foreign
 *     uuid; `assertParentOwned` is belt to that braces.
 *   - `GradingSchemeItem` has NO scope of its own (ownership lives on `grading_schemes.school_id`),
 *     so binding alone would happily resolve another school's rating. The explicit check is the
 *     only thing standing there — the same shape of hole that let a foreign curriculum subject
 *     render on the score entry page.
 */
class CommentEntryController extends Controller
{
    // ── Numeric: comments on a score band ──────────────────────────────────

    public function storeOnBand(Request $request, CommentBand $commentBand): JsonResponse
    {
        return $this->storeFor($request, $commentBand);
    }

    public function updateOnBand(Request $request, CommentBand $commentBand, CommentEntry $entry): JsonResponse
    {
        return $this->updateFor($request, $commentBand, $entry);
    }

    public function destroyOnBand(CommentBand $commentBand, CommentEntry $entry): JsonResponse
    {
        return $this->destroyFor($commentBand, $entry);
    }

    public function reorderOnBand(Request $request, CommentBand $commentBand): JsonResponse
    {
        return $this->reorderFor($request, $commentBand);
    }

    // ── Categorical: comments on a rating ──────────────────────────────────

    public function storeOnRating(Request $request, GradingSchemeItem $gradingSchemeItem): JsonResponse
    {
        return $this->storeFor($request, $gradingSchemeItem);
    }

    public function updateOnRating(Request $request, GradingSchemeItem $gradingSchemeItem, CommentEntry $entry): JsonResponse
    {
        return $this->updateFor($request, $gradingSchemeItem, $entry);
    }

    public function destroyOnRating(GradingSchemeItem $gradingSchemeItem, CommentEntry $entry): JsonResponse
    {
        return $this->destroyFor($gradingSchemeItem, $entry);
    }

    public function reorderOnRating(Request $request, GradingSchemeItem $gradingSchemeItem): JsonResponse
    {
        return $this->reorderFor($request, $gradingSchemeItem);
    }

    // ── The shared implementation ──────────────────────────────────────────

    private function storeFor(Request $request, Model&Commentable $parent): JsonResponse
    {
        $this->assertParentOwned($parent);

        $validated = $request->validate([
            'body' => [
                'required',
                'string',
                'max:'.CommentEntry::MAX_LENGTH,
                // A suggestion the teacher cannot save is worse than no suggestion, so duplicates
                // and over-length bodies are refused at the point of authoring rather than
                // discovered mid-score-entry.
                Rule::unique('comment_entries', 'body')
                    ->where('commentable_type', $parent->getMorphClass())
                    ->where('commentable_id', $parent->getKey()),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $entry = $parent->comments()->create([
            'body' => $validated['body'],
            'sort_order' => $validated['sort_order']
                ?? ((int) $parent->comments()->max('sort_order') + 1),
            'is_active' => true,
        ]);

        return response()->json(['data' => new CommentEntryResource($entry)], 201);
    }

    private function updateFor(Request $request, Model&Commentable $parent, CommentEntry $entry): JsonResponse
    {
        $this->assertParentOwned($parent);
        $this->assertEntryBelongsTo($parent, $entry);

        $validated = $request->validate([
            'body' => [
                'sometimes',
                'required',
                'string',
                'max:'.CommentEntry::MAX_LENGTH,
                Rule::unique('comment_entries', 'body')
                    ->where('commentable_type', $parent->getMorphClass())
                    ->where('commentable_id', $parent->getKey())
                    ->ignore($entry->id),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            // Retiring an entry keeps it out of the datalist without touching the students who
            // already carry that comment on a saved result.
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $entry->update($validated);

        return response()->json(['data' => new CommentEntryResource($entry)]);
    }

    /**
     * Hard delete. It removes the suggestion, never a comment a teacher already saved — those live
     * on `student_subjects.comment` as plain text and are not foreign-keyed to this table.
     */
    private function destroyFor(Model&Commentable $parent, CommentEntry $entry): JsonResponse
    {
        $this->assertParentOwned($parent);
        $this->assertEntryBelongsTo($parent, $entry);

        $entry->delete();

        return response()->json(null, 204);
    }

    /**
     * Teachers read the datalist top-down, so order is editorial, not cosmetic. Takes the full
     * ordered list of entry uuids; anything the payload omits keeps its current position.
     */
    private function reorderFor(Request $request, Model&Commentable $parent): JsonResponse
    {
        $this->assertParentOwned($parent);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['uuid'],
        ]);

        $entries = $parent->comments()->get()->keyBy('uuid');

        foreach ($validated['ids'] as $position => $uuid) {
            $entries->get($uuid)?->update(['sort_order' => $position]);
        }

        return response()->json([
            'data' => CommentEntryResource::collection(
                $parent->activeComments()->get()
            )->resolve(),
        ]);
    }

    /**
     * The parent must belong to the active school.
     *
     * For `GradingSchemeItem` this is the ONLY isolation check on the path — the model has no
     * SchoolScope, so without it any school could author comments onto another school's ratings
     * and teachers there would see them.
     */
    private function assertParentOwned(Model&Commentable $parent): void
    {
        $schoolId = match (true) {
            $parent instanceof CommentBand => $parent->school_id,
            $parent instanceof GradingSchemeItem => $parent->ownerSchoolId(),
            default => null,
        };

        abort_unless($schoolId !== null && (int) $schoolId === ActiveSchool::getOrFail()->id, 404);
    }

    /**
     * The entry must hang off the parent in the URL. Without this a caller could pair their own
     * parent uuid with an entry uuid from another school.
     */
    private function assertEntryBelongsTo(Model&Commentable $parent, CommentEntry $entry): void
    {
        abort_unless(
            $entry->commentable_type === $parent->getMorphClass()
                && (int) $entry->commentable_id === (int) $parent->getKey(),
            404
        );
    }
}
