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
        $validated = $request->validate([
            'exam_type_id' => 'required|uuid|exists:exam_types,id',
            'min_score' => 'required|integer|min:0',
            'max_score' => 'required|integer|min:0',
            'grade' => 'required|string|max:10',
            'label' => 'required|string|max:255',
        ]);

        $boundary = GradeBoundary::create($validated);

        return new GradeBoundaryResource($boundary);
    }

    public function update(Request $request, GradeBoundary $gradeBoundary)
    {
        $validated = $request->validate([
            'min_score' => 'required|integer|min:0',
            'max_score' => 'required|integer|min:0',
            'grade' => 'required|string|max:10',
            'label' => 'required|string|max:255',
        ]);

        $gradeBoundary->update($validated);

        return new GradeBoundaryResource($gradeBoundary);
    }

    public function destroy(GradeBoundary $gradeBoundary)
    {
        $gradeBoundary->delete();

        return response()->json(null, 204);
    }
}
