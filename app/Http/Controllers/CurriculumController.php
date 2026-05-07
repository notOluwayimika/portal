<?php

namespace App\Http\Controllers;

use App\Http\Resources\CurriculumResource;
use App\Http\Resources\CurriculumSubjectResource;
use App\Models\Curriculum;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurriculumController extends Controller
{
    public function show(Curriculum $curriculum)
    {
        return response()->json(new CurriculumResource($curriculum));
    }
    public function store(Request $request)
    {
        try {
            $request->validate([
                'term_id' => 'required|string|exists:terms,uuid',
                'class_level_id' => 'required|string',
                'exam_type_id' => 'required|string',
                'min_subjects' => 'required|integer|min:1',
                'status' => 'required|string|in:active,draft,closed',
            ]);

            $school = auth()->user()->school;
            $term = Term::where('uuid', $request->term_id)->first();
            $classLevel = $school->classLevelArms()->where('uuid', $request->class_level_id)->first();
            $examType = $school->examTypes()->where('uuid', $request->exam_type_id)->first();

            $curriculum = $school->curricula()->create([
                'term_id' => $term->id,
                'class_level_arm_id' => $classLevel->id,
                'exam_type_id' => $examType->id,
                'min_subjects' => $request->min_subjects,
                'status' => $request->status,
            ]);
            return response()->json(new CurriculumResource($curriculum), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to create curriculum'], 500);
        }

    }

    public function update(Request $request, Curriculum $curriculum)
    {
        try {
            $request->validate([
                'term_id' => 'required|string|exists:terms,uuid',
                'class_level_id' => 'required|string',
                'exam_type_id' => 'required|string',
                'min_subjects' => 'required|integer|min:1',
                'status' => 'required|string|in:active,draft,closed',
            ]);

            $school = auth()->user()->school;
            $term = Term::where('uuid', $request->term_id)->first();
            $classLevel = $school->classLevelArms()->where('uuid', $request->class_level_id)->first();
            $examType = $school->examTypes()->where('uuid', $request->exam_type_id)->first();

            $curriculum->update([
                'term_id' => $term->id,
                'class_level_arm_id' => $classLevel->id,
                'exam_type_id' => $examType->id,
                'min_subjects' => $request->min_subjects,
                'status' => $request->status,
            ]);

            return response()->json(new CurriculumResource($curriculum), 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to update curriculum'], 500);
        }
    }

    public function destroy(Curriculum $curriculum)
    {
        try {
            $curriculum->delete();
            return response()->json(['message' => 'Curriculum deleted successfully'], 204);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to delete curriculum'], 500);
        }
    }

    public function index(Request $request)
    {
        $school = auth()->user()->school;
        $limit = $request->integer('limit', 25);
        $curricula = $school->curricula();
        // apply filters for term_id, class_level_id and status if they exist
        if ($request->has('term_id')) {
            $term = Term::where('uuid', $request->term_id)->first();
            $curricula = $curricula->where('term_id', $term->id);
        }
        if ($request->has('class_level_id')) {
            $classLevel = $school->classLevelArms()->where('uuid', $request->class_level_id)->first();
            $curricula = $curricula->where('class_level_arm_id', $classLevel->id);
        }
        if ($request->has('status')) {
            $curricula = $curricula->where('status', $request->status);
        }

        $curricula = $curricula->paginate($request->integer('per_page', $limit));
        return response()->json([
            "curricula" => CurriculumResource::collection($curricula),
            "pagination" => [
                "total" => $curricula->total(),
                "per_page" => $curricula->perPage(),
                "current_page" => $curricula->currentPage(),
                "last_page" => $curricula->lastPage(),
            ],
        ], 200);
    }

    public function reorder(Request $request, Curriculum $curriculum)
    {
        try {
            $request->validate([
                'order' => 'required|array',
                'order.*.id' => 'required|string',
                'order.*.display_order' => 'required|integer|min:1',
            ]);
            DB::transaction(function () use ($request, $curriculum) {
                $order = $request->order;

                foreach ($order as $item) {
                    $curriculumSubject = $curriculum->curriculumSubjects->where('uuid', $item['id'])->first();
                    if ($curriculumSubject) {
                        $curriculumSubject->update(['display_order' => $item['display_order']]);
                    }
                }

                return response()->json(['message' => 'Subjects reordered successfully'], 200);
            });
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to reorder subjects'], 500);
        }

    }

    public function assignSubject(Curriculum $curriculum, Request $request)
    {
        try {
            $request->validate([
                'subject_id' => 'required|string|exists:subjects,uuid',
                'is_compulsory' => 'boolean',
                'display_order' => 'integer|min:1',
            ]);

            $subject = Subject::where('uuid', $request->subject_id)->first();

            $curriculumSubject = $curriculum->curriculumSubjects()->create([
                'subject_id' => $subject->id,
                'is_compulsory' => $request->is_compulsory,
                'display_order' => $request->display_order,
            ]);

            return response()->json(new CurriculumSubjectResource($curriculumSubject), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to assign subject'], 500);
        }
    }

}
