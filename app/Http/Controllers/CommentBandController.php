<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCommentBandsRequest;
use App\Http\Resources\CommentBandResource;
use App\Http\Resources\CommentEntryResource;
use App\Models\CommentBand;
use App\Models\ExamType;
use App\Models\GradingScheme;
use App\Models\GradingSchemeItem;
use App\Services\CommentBandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The school's comment-band ladder, as edited from the Score Comments tab in school setup.
 *
 * Every route here lives inside the `permission:academic_setup.manage` group in routes/api.php,
 * which is where the authorization for this surface is declared.
 */
class CommentBandController extends Controller
{
    public function __construct(private CommentBandService $service) {}

    /**
     * GET /api/comment-bands?exam_type={uuid}
     *
     * The set for one exam type, highest band first, WITHOUT the school-default fallback that
     * CommentBand::setFor applies. The admin editing an exam type's override needs to see that it
     * is currently empty; falling back here would show them the default set and let them "edit"
     * rows that belong to a different ladder.
     */
    public function index(Request $request): JsonResponse
    {
        $examTypeId = $this->resolveExamTypeId($request->query('exam_type'));

        $bands = CommentBand::query()
            ->forExamType($examTypeId)
            ->with('activeComments')
            ->orderByDesc('min_score')
            ->get();

        return response()->json([
            'data' => CommentBandResource::collection($bands)->resolve(),
        ]);
    }

    /**
     * PUT /api/comment-bands — replace the whole set for one exam type.
     */
    public function save(SaveCommentBandsRequest $request): JsonResponse
    {
        $examTypeId = $this->resolveExamTypeId($request->validated('exam_type_id'));

        $bands = $this->service->saveSet($examTypeId, $request->validated('bands'));

        return response()->json([
            'data' => CommentBandResource::collection($bands)->resolve(),
        ]);
    }

    /**
     * POST /api/comment-bands/load-defaults — import the starter set.
     *
     * Only into an empty set. Merging into a configured ladder would either duplicate the school's
     * own wording or overwrite it, and neither is what "load the defaults" asks for once they have
     * made editorial decisions.
     */
    public function loadDefaults(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exam_type_id' => ['nullable', 'uuid', 'exists:exam_types,uuid'],
        ]);

        $examTypeId = $this->resolveExamTypeId($validated['exam_type_id'] ?? null);

        if (CommentBand::query()->forExamType($examTypeId)->exists()) {
            return response()->json([
                'message' => 'This set already has bands. Delete them first to load the defaults.',
            ], 422);
        }

        $bands = $this->service->loadDefaults($examTypeId);

        return response()->json([
            'data' => CommentBandResource::collection($bands)->resolve(),
        ], 201);
    }

    /**
     * GET /api/grading-schemes/{gradingScheme:uuid}/rating-comments
     *
     * The categorical counterpart of `index`: every rating in the scheme with its comments, for the
     * Score Comments tab. Ratings themselves are not editable here — they belong to the grading
     * scheme — so this returns the ladder the school already defined and lets them fill it in.
     */
    public function ratingComments(GradingScheme $gradingScheme): JsonResponse
    {
        // `grading_scheme_items` has no SchoolScope; ownership lives on the scheme, and
        // GradingScheme IS scoped, so binding has already failed closed for a foreign uuid.
        $items = $gradingScheme->items()->with('activeComments')->orderBy('display_order')->get();

        $data = [];

        /** @var GradingSchemeItem $item */
        foreach ($items as $item) {
            $data[] = [
                'id' => $item->uuid,
                'code' => $item->code,
                'label' => $item->label,
                'display_order' => $item->display_order,
                'comments' => CommentEntryResource::collection($item->activeComments)->resolve(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Map the public uuid to the internal key.
     *
     * NULL means the school's default set — a legitimate target, not a missing value, so an absent
     * uuid is not an error. But a uuid that does NOT resolve must 404 rather than fall through to
     * null: silently treating an unknown or another school's exam type as "the default set" would
     * let an admin who thinks they are creating an override overwrite the ladder every exam type
     * without one falls back to. `exists:exam_types,uuid` does not cover this — it is unscoped, so
     * another school's uuid passes validation and then resolves to null here under the SchoolScope.
     */
    private function resolveExamTypeId(?string $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $id = ExamType::where('uuid', $uuid)->value('id');

        abort_if($id === null, 404);

        return $id;
    }
}
