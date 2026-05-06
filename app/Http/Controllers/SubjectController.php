<?php

namespace App\Http\Controllers;

use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubjectController extends Controller
{
    //

    public function index(Request $request)
    {
        try {
            $school = auth()->user()->school;
            $limit = $request->input('limit', 10);
            $search = $request->input('search', '');
            $subjects = $school->subjects();
            if ($search) {
                $subjects = $subjects->where('name', 'like', "%$search%");
            }
            $subjects = $subjects->paginate($limit);

            return response()->json([
                "subjects" => SubjectResource::collection($subjects),
                "pagination" => [
                    "total" => $subjects->total(),
                    "per_page" => $subjects->perPage(),
                    "current_page" => $subjects->currentPage(),
                    "last_page" => $subjects->lastPage(),
                ],
            ], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to fetch subjects'], 500);
        }

    }

    public function store(Request $request)
    {
        try {
            // Implementation for storing a new subject
            $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:255|unique:subjects,code',
            ]);

            $school = auth()->user()->school;
            $subject = $school->subjects()->create([...$request->only(['name', 'code']), 'uuid' => Str::uuid()]);

            return response()->json(new SubjectResource($subject), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to create subject'], 500);
        }

    }

    public function update(Request $request, Subject $subject)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:255',
            ]);

            $school = auth()->user()->school;
            $subject = $school->subjects()->findOrFail($subject->id);
            $subject->update($request->only(['name', 'code']));

            return response()->json(new SubjectResource($subject), 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to update subject'], 500);
        }

    }

    public function destroy(Subject $subject)
    {
        try {
            $school = auth()->user()->school;
            $subject = $school->subjects()->findOrFail($subject->id);
            $subject->delete();

            return response()->json(null, 204);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to delete subject'], 500);
        }

    }
}
