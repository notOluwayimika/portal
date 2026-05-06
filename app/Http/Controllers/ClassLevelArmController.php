<?php

namespace App\Http\Controllers;

use App\Http\Resources\ArmResource;
use App\Http\Resources\ClassLevelArmResource;
use App\Http\Resources\ClassLevelResource;
use App\Http\Resources\CurriculumResource;
use App\Http\Resources\ExamTypeResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\StreamResource;
use App\Http\Resources\TermResource;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Stream;
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
            $sessions = $school->sessions()->with('terms')->where('is_current', true)->get();
            $classLevels = $school->classLevels;
            $arms = $school->arms;
            $streams = Stream::all();
            $classLevelArms = $school->classLevelArms;
            $examTypes = $school->examTypes;
            return response()->json([
                "class_levels" => ClassLevelResource::collection($classLevels),
                "arms" => ArmResource::collection($arms),
                "streams" => StreamResource::collection($streams),
                "class_level_arms" => ClassLevelArmResource::collection($classLevelArms),
                "exam_types" => ExamTypeResource::collection($examTypes),
                "sessions" => SessionResource::collection($sessions),
                "terms" => TermResource::collection($school->terms ?? collect()),
            ], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to retrieve class levels'], 500);
        }

    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'class_level_id' => 'required|exists:class_levels,uuid',
                'arm_id' => 'required|exists:arms,uuid',
                'stream_id' => 'nullable|exists:streams,uuid'
            ]);
            $school = Auth::user()->school;
            $classLevel = $school->classLevels()->where('class_levels.id', $request->class_level_id)->orWhere('class_levels.uuid', $request->class_level_id)->first();
            $arm = $school->arms()->where('arms.id', $request->arm_id)->orWhere('arms.uuid', $request->arm_id)->first();
            $classLevelArm = $school->classLevelArms()->where('class_level_id', $classLevel->id)->where('arm_id', $arm->id)->first();
            $stream = $request->stream_id ? Stream::where('streams.id', $request->stream_id)->orWhere('streams.uuid', $request->stream_id)->first() : null;
            if (!$classLevelArm) {
                return response()->json(['error' => 'Invalid class level arm'], 400);
            }
            // Check if the combination already exists
            if ($classLevelArm) {
                $existing = ClassLevelArm::where('class_level_id', $classLevelArm->class_level_id)
                    ->where('arm_id', $classLevelArm->arm_id)
                    ->where('stream_id', $stream ? $stream->id : null)
                    ->first();

                if ($existing) {
                    return response()->json(['error' => 'This combination already exists'], 400);
                }

                // Create the new relationship
                $school->classLevelArms()->create([
                    'class_level_id' => $classLevelArm->class_level_id,
                    'arm_id' => $classLevelArm->arm_id,
                    'stream_id' => $stream ? $stream->id : null
                ]);
            }

            return response()->json(['message' => 'Relationship created successfully'], 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to create relationship'], 500);
        }
    }

    public function update(Request $request, ClassLevelArm $classLevelArm)
    {
        try {
            $request->validate([
                'stream_id' => 'nullable|exists:streams,uuid'
            ]);
            $stream = $request->stream_id ? Stream::where('streams.id', $request->stream_id)->orWhere('streams.uuid', $request->stream_id)->first() : null;

            $classLevelArm->update([
                'stream_id' => $stream ? $stream->id : null
            ]);

            return response()->json(['message' => 'Relationship updated successfully', 'class_level_arm' => new ClassLevelArmResource($classLevelArm)], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to update relationship'], 500);
        }
    }

    public function destroy(ClassLevelArm $classLevelArm)
    {
        try {
            $classLevelArm->delete();
            return response()->json(['message' => 'Relationship deleted successfully'], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to delete relationship'], 500);
        }
    }

    public function toggle(Request $request)
    {
        try {
            $school = Auth::user()->school;
            $request->validate([
                'class_level_id' => 'required',
                'arm_id' => 'required',
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

    public function storeStream(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:255',
                'sort_order' => 'nullable|integer'
            ]);
            $stream = Stream::create([
                'name' => $request->name,
                'code' => $request->code,
                'sort_order' => $request->sort_order,
                'uuid' => Str::uuid()
            ]);
            return response()->json(['message' => 'Stream created successfully', 'stream' => new StreamResource($stream)], 201);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to create stream'], 500);
        }
    }

    public function updateStream(Request $request, Stream $stream)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                // dont include current stream code in unique
                'code' => 'required|string|max:255',
                'sort_order' => 'nullable|integer'
            ]);

            $stream->update([
                'name' => $request->name,
                'code' => $request->code,
                'sort_order' => $request->sort_order
            ]);

            return response()->json(['message' => 'Stream updated successfully', 'stream' => new StreamResource($stream)], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to update stream'], 500);
        }
    }

    public function destroyStream(Stream $stream)
    {
        try {
            $stream->delete();
            return response()->json(['message' => 'Stream deleted successfully'], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to delete stream'], 500);
        }
    }
}
