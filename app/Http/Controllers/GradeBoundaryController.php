<?php

namespace App\Http\Controllers;

use App\Http\Resources\GradeBoundaryResource;
use App\Models\ExamType;
use App\Models\GradeBoundary;
use Illuminate\Http\Request;

class GradeBoundaryController extends Controller
{
    public function index(ExamType $examType)
    {
        $gradeBoundaries = $examType->gradeBoundaries;

        return GradeBoundaryResource::collection($gradeBoundaries);
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'exam_type_id' => 'required|uuid|exists:exam_types,uuid',
                'min_score' => 'required|decimal:0,2|min:0',
                'max_score' => 'required|decimal:0,2|min:0',
                'grade' => 'required|string|max:10',
                'label' => 'required|string|max:255',
                'grade_point' => 'required|string|max:10'
            ]);
            $school = \App\Support\ActiveSchool::getOrFail();
            $examType = ExamType::where('uuid', $validated['exam_type_id'])->first();

            $boundary = GradeBoundary::create([...$request->except(['exam_type_id']), 'exam_type_id' => $examType->id, 'school_id' => $school->id]);

            return response()->json(new GradeBoundaryResource($boundary), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to create grade boundary'], 500);
        }

    }

    public function update(Request $request, GradeBoundary $gradeBoundary)
    {
        try {
            $validated = $request->validate([
                'min_score' => 'required|decimal:0,2|min:0',
                'max_score' => 'required|decimal:0,2|min:0',
                'grade' => 'required|string|max:10',
                'label' => 'required|string|max:255',
                'grade_point' => 'required|string|max:10'
            ]);

            $gradeBoundary->update($validated);

            return response()->json(new GradeBoundaryResource($gradeBoundary), 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to update grade boundary'], 500);

        }

    }

    public function destroy(GradeBoundary $gradeBoundary)
    {
        try {
            $gradeBoundary->delete();

            return response()->json(null, 204);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to delete grade boundary'], 500);
        }

    }
}
