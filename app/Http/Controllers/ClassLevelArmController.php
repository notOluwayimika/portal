<?php

namespace App\Http\Controllers;

use App\Http\Resources\ArmResource;
use App\Http\Resources\ClassLevelResource;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Services\ClassLevelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ClassLevelArmController extends Controller
{
    public function index(Request $request)
    {
        try {
            $school = Auth::user()->school;
            $classLevels = $school->classLevels;
            $arms = $school->arms;
            return response()->json(["class_levels" => ClassLevelResource::collection($classLevels), "arms" => ArmResource::collection($arms)], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to retrieve class levels'], 500);
        }

    }

    public function toggle(Request $request)
    {
        try {
            $school = Auth::user()->school;
            $request->validate([
                'class_level_id' => 'required',
                'arm_id' => 'required'
            ]);
            $classLevel = $school->classLevels()->where('class_levels.id', $request->class_level_id)->orWhere('class_levels.uuid', $request->class_level_id)->first();
            $arm = $school->arms()->where('arms.id', $request->arm_id)->orWhere('arms.uuid', $request->arm_id)->first();
            // check if class level and arm are linked in class level arm if yes unlink if not link
            // Implementation for toggling the relationship would go here
            $isLinked = $classLevel->arms()->where('arms.id', $arm->id)->exists();
            if ($isLinked) {
                $classLevel->arms()->detach($arm);
            } else {
                $classLevel->arms()->attach($arm, ['uuid' => Str::uuid()]);
            }
            return response()->json(['message' => 'Relationship updated successfully'], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to update relationship'], 500);

        }

    }

    public function storeLevel(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'order' => 'nullable|integer'
            ]);
            $school = Auth::user()->school;
            $classLevel = $school->classLevels()->create([
                'name' => $request->name,
                'order' => $request->order,
                'uuid' => Str::uuid()
            ]);
            return response()->json(['message' => 'Level created successfully', 'class_level' => new ClassLevelResource($classLevel)], 201);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to create level'], 500);
        }

    }

    public function updateLevel(Request $request, ClassLevel $classLevel)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'order' => 'nullable|integer'
            ]);

            $classLevel->update([
                'name' => $request->name,
                'order' => $request->order
            ]);

            return response()->json(['message' => 'Level updated successfully', 'class_level' => new ClassLevelResource($classLevel)], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to update level'], 500);
        }

    }

    public function destroyLevel(ClassLevel $classLevel)
    {
        try {
            $classLevel->delete();
            return response()->json(['message' => 'Level deleted successfully'], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to delete level'], 500);
        }

    }

    public function storeArm(Request $request)
    {
        try {
            $request->validate([
                'label' => 'required|string|max:255'
            ]);
            $school = Auth::user()->school;
            $arm = $school->arms()->create([
                'label' => $request->label,
                'uuid' => Str::uuid()
            ]);
            return response()->json(['message' => 'Arm created successfully', 'arm' => new ArmResource($arm)], 201);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to create arm'], 500);
        }
    }

    public function updateArm(Request $request, Arm $arm)
    {
        try {
            $request->validate([
                'label' => 'required|string|max:255'
            ]);

            $arm->update([
                'label' => $request->label
            ]);

            return response()->json(['message' => 'Arm updated successfully', 'arm' => new ArmResource($arm)], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to update arm'], 500);
        }
    }

    public function destroyArm(Arm $arm)
    {
        try {
            $arm->delete();
            return response()->json(['message' => 'Arm deleted successfully'], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to delete arm'], 500);
        }
    }
}
