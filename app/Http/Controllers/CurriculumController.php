<?php

namespace App\Http\Controllers;

use App\Http\Resources\CurriculumResource;
use Illuminate\Http\Request;

class CurriculumController extends Controller
{
    public function store(Request $request)
    {
        try {
            $request->validate([
                'academic_session_id' => 'required|string',
                'class_level_id' => 'required|string',
                'exam_type_id' => 'required|string',
                'term' => 'required|integer|min:1|max:4',
                'min_subjects' => 'required|integer|min:1',
                'registration_deadline' => 'required|date',
                'result_visible_at' => 'required|date',
                'status' => 'required|string|in:active,draft,closed',
            ]);
            $school = auth()->user()->school;
            $session = $school->sessions()->where('id', $request->academic_session_id)->first();
            $classLevel = $school->classLevels()->where('id', $request->class_level_id)->first();
            $examType = $school->examTypes()->where('id', $request->exam_type_id)->first();

            $curriculum = $school->curricula()->create([
                'academic_session_id' => $session->id,
                'class_level_id' => $classLevel->id,
                'exam_type_id' => $examType->id,
                'term' => $request->term,
                'min_subjects' => $request->min_subjects,
                'registration_deadline' => $request->registration_deadline,
                'result_visible_at' => $request->result_visible_at,
                'status' => $request->status,
            ]);
            return response()->json(new CurriculumResource($curriculum), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to create curriculum'], 500);
        }

    }

    public function update(Request $request, $curriculum)
    {
        try {
            $request->validate([
                'academic_session_id' => 'required|string',
                'class_level_id' => 'required|string',
                'exam_type_id' => 'required|string',
                'term' => 'required|integer|min:1|max:4',
                'min_subjects' => 'required|integer|min:1',
                'registration_deadline' => 'required|date',
                'result_visible_at' => 'required|date',
                'status' => 'required|string|in:active,draft,closed',
            ]);

            $school = auth()->user()->school;
            $session = $school->sessions()->where('id', $request->academic_session_id)->first();
            $classLevel = $school->classLevels()->where('id', $request->class_level_id)->first();
            $examType = $school->examTypes()->where('id', $request->exam_type_id)->first();

            $curriculum->update([
                'academic_session_id' => $session->id,
                'class_level_id' => $classLevel->id,
                'exam_type_id' => $examType->id,
                'term' => $request->term,
                'min_subjects' => $request->min_subjects,
                'registration_deadline' => $request->registration_deadline,
                'result_visible_at' => $request->result_visible_at,
                'status' => $request->status,
            ]);

            return response()->json(new CurriculumResource($curriculum), 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to update curriculum'], 500);
        }
    }

    public function destroy($curriculum)
    {
        try {
            $curriculum->delete();
            return response()->json(['message' => 'Curriculum deleted successfully'], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to delete curriculum'], 500);
        }
    }

    public function index()
    {
        $school = auth()->user()->school;
        $curricula = $school->curricula()->paginate(10);
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
}
