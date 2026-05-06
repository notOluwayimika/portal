<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExamTypeResource;
use App\Models\ExamType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExamTypeController extends Controller
{
    public function index()
    {
        try {
            $examTypes = auth()->user()->school->examTypes;
            return ExamTypeResource::collection($examTypes);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to retrieve exam types'], 500);
        }

    }

    public function store(Request $request)
    {
        try {
            $school = auth()->user()->school;
            $existing = $school->examTypes()->where('slug', Str::slug(str_replace('/', '-', $request->name), '-'))->first();
            if ($existing) {
                return response()->json(['error' => 'Exam type with this name already exists'], 409);
            }
            $request->validate(['name' => 'required|string|max:255']);
            $examType = $school->examTypes()->create(['name' => $request->name, 'slug' => Str::slug(str_replace('/', '-', $request->name), '-')]);
            return response()->json(new ExamTypeResource($examType), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to create exam type'], 500);
        }
    }

    public function update(Request $request, ExamType $examType)
    {
        try {
            $examType->update($request->only('name'));
            return new ExamTypeResource($examType);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to update exam type'], 500);
        }
    }

    public function destroy(ExamType $examType)
    {
        try {
            $examType->delete();
            return response()->json(['message' => 'Exam type deleted successfully']);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to delete exam type'], 500);
        }
    }
}
