<?php

namespace App\Http\Controllers;

use App\Http\Resources\ScholarshipResource;
use App\Models\Scholarship;
use Illuminate\Http\Request;

class ScholarshipController extends Controller
{
    public function index()
    {
        try {
            $scholarships = \App\Support\ActiveSchool::getOrFail()->scholarships;
            return ScholarshipResource::collection($scholarships);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to retrieve scholarships'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate(['name' => 'required|string|max:255']);

            $school = \App\Support\ActiveSchool::getOrFail();
            $existing = $school->scholarships()->where('name', $request->name)->first();
            if ($existing) {
                return response()->json(['error' => 'Scholarship with this name already exists'], 409);
            }

            $scholarship = $school->scholarships()->create(['name' => $request->name]);
            return response()->json(new ScholarshipResource($scholarship), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to create scholarship'], 500);
        }
    }

    public function update(Request $request, Scholarship $scholarship)
    {
        try {
            $request->validate(['name' => 'required|string|max:255']);

            $scholarship->update($request->only('name'));
            return new ScholarshipResource($scholarship);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to update scholarship'], 500);
        }
    }

    public function destroy(Scholarship $scholarship)
    {
        try {
            $scholarship->delete();
            return response()->json(['message' => 'Scholarship deleted successfully']);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to delete scholarship'], 500);
        }
    }
}
