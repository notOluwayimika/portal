<?php

namespace App\Http\Controllers;

use App\Http\Resources\MarkingComponentResource;
use App\Models\Curriculum;
use App\Models\MarkingComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarkingComponentController extends Controller
{

    private const SUM_TOLERANCE = 0.0005;
    public function destroy(MarkingComponent $markingComponent)
    {
        try {
            $markingComponent->delete();
            return response()->json("Deleted the marking component successfully", 200);

        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json("Failed to delete the marking component", 500);
        }
    }
    public function index(Request $request)
    {
        $ccm = $request->boolean('ccm');
        \Log::info($ccm);
        $markingComponents = MarkingComponent::global()->where('school_id', auth()->user()->school_id)->where('is_ccm', $ccm)->get();
        return response()->json(MarkingComponentResource::collection($markingComponents));
    }

    public function update(MarkingComponent $markingComponent, Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'weight' => 'required|numeric|min:0',
            ]);
            $markingComponent->update($request->all());
            return response()->json(["Updated the marking component successfully", "data" => new MarkingComponentResource($markingComponent)], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json("Failed to update the marking component", 500);
        }
    }

    public function sync(Request $request)
    {
        $validated = $request->validate([
            'components' => ['required', 'array', 'min:1'],
            'components.*.id' => ['nullable', 'uuid'],
            'components.*.name' => ['required', 'string', 'max:255'],
            'components.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $ccm = $request->boolean('ccm');
        $components = $validated['components'];

        // Ensure total weight = 100%
        $sum = array_sum(
            array_map(
                static fn($component) => (float) $component['percent'] / 100,
                $components
            )
        );

        if (abs($sum - 1.0) > self::SUM_TOLERANCE) {
            throw ValidationException::withMessages([
                'components' => sprintf(
                    'Weights must add up to exactly 100%% (got %.1f%%).',
                    $sum * 100
                ),
            ]);
        }

        $submittedIds = collect($components)
            ->pluck('id')
            ->filter()
            ->values();

        $existingIds = MarkingComponent::query()
            ->global()
            ->where('is_ccm', $ccm)
            ->whereIn('uuid', $submittedIds)
            ->pluck('uuid');

        DB::transaction(function () use ($components, $submittedIds, $existingIds, $ccm) {
            // Delete removed components
            $deleteQuery = MarkingComponent::query()
                ->global()
                ->where('is_ccm', $ccm);

            if ($submittedIds->isNotEmpty()) {
                $deleteQuery->whereNotIn('uuid', $submittedIds);
            }

            $deleteQuery->delete();

            foreach ($components as $component) {
                $attributes = [
                    'name' => $component['name'],
                    'weight' => $component['percent'] / 100,
                    'is_ccm' => $ccm,
                ];

                if (
                    !empty($component['id']) &&
                    $existingIds->contains($component['id'])
                ) {
                    MarkingComponent::query()
                        ->global()
                        ->where('uuid', $component['id'])
                        ->where('is_ccm', $ccm)
                        ->update($attributes);
                } else {
                    MarkingComponent::create([
                        ...$attributes,
                        'curriculum_subject_id' => null,
                        'school_id' => auth()->user()->school_id,
                    ]);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Marking components saved.',
        ]);
    }

    public function getOverlapping(Curriculum $curriculum)
    {
        $hasCcmVersion = Curriculum::query()
            ->where('school_id', $curriculum->school_id)
            ->where('term_id', $curriculum->term_id)
            ->where('class_level_arm_id', $curriculum->class_level_arm_id)
            ->where('exam_type_id', $curriculum->exam_type_id)
            ->where('is_ccm', true)
            ->exists();

        $overlapping = [];

        if ($hasCcmVersion) {
            $ccmMc = MarkingComponent::where('is_ccm', true)->where('curriculum_subject_id', null)->pluck('name');
            $eotMc = MarkingComponent::where('is_ccm', false)->where('curriculum_subject_id', null)->pluck('name');

            $overlapping = $ccmMc->intersect($eotMc)->values();
        }

        return response()->json([
            'overlapping' => $overlapping,
        ]);
    }
}
