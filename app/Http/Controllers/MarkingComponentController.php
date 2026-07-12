<?php

namespace App\Http\Controllers;

use App\Http\Resources\MarkingComponentResource;
use App\Models\Curriculum;
use App\Models\MarkingComponent;
use App\Models\MarkingScheme;
use App\Support\ActiveSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkingComponentController extends Controller
{
    private const SUM_TOLERANCE = 0.0005;

    public function destroy(MarkingComponent $markingComponent)
    {
        if ($markingComponent->marking_scheme_id) {
            return response()->json('Published scheme components are immutable. Create a new scheme version instead.', 409);
        }
        if ($markingComponent->scores()->exists()) {
            return response()->json('A marking component with scores cannot be deleted.', 409);
        }

        try {
            $markingComponent->delete();

            return response()->json('Deleted the marking component successfully', 200);

        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json('Failed to delete the marking component', 500);
        }
    }

    public function index(Request $request)
    {
        $ccm = $request->boolean('ccm');
        \Log::info($ccm);
        $scheme = MarkingScheme::query()
            ->active()
            ->where('school_id', ActiveSchool::id())
            ->where('is_ccm', $ccm)
            ->latest('version')
            ->first();
        $markingComponents = $scheme?->components
            ?? MarkingComponent::global()->where('school_id', ActiveSchool::id())->where('is_ccm', $ccm)->get();

        return response()->json(MarkingComponentResource::collection($markingComponents));
    }

    public function update(MarkingComponent $markingComponent, Request $request)
    {
        if ($markingComponent->marking_scheme_id) {
            return response()->json('Published scheme components are immutable. Save the setup to create a new version.', 409);
        }

        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'weight' => 'required|numeric|min:0',
            ]);
            $markingComponent->update($request->all());

            return response()->json(['Updated the marking component successfully', 'data' => new MarkingComponentResource($markingComponent)], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json('Failed to update the marking component', 500);
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
                static fn ($component) => (float) $component['percent'] / 100,
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

        DB::transaction(function () use ($components, $ccm) {
            $schoolId = ActiveSchool::id();
            MarkingScheme::query()
                ->where('school_id', $schoolId)
                ->where('is_ccm', $ccm)
                ->where('status', 'active')
                ->update(['status' => 'retired']);

            $version = ((int) MarkingScheme::query()
                ->where('school_id', $schoolId)
                ->where('is_ccm', $ccm)
                ->max('version')) + 1;

            $scheme = MarkingScheme::create([
                'school_id' => $schoolId,
                'is_ccm' => $ccm,
                'version' => $version,
                'status' => 'active',
            ]);

            foreach ($components as $component) {
                $attributes = [
                    'name' => $component['name'],
                    'weight' => $component['percent'] / 100,
                    'is_ccm' => $ccm,
                    'school_id' => $schoolId,
                    'marking_scheme_id' => $scheme->id,
                    'curriculum_subject_id' => null,
                ];
                MarkingComponent::create($attributes);
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
            $schoolId = $curriculum->school_id;
            $ccmMc = MarkingScheme::active()->where('school_id', $schoolId)->where('is_ccm', true)
                ->latest('version')->first()?->components()->pluck('name')
                ?? MarkingComponent::where('school_id', $schoolId)->where('is_ccm', true)->global()->pluck('name');
            $eotMc = MarkingScheme::active()->where('school_id', $schoolId)->where('is_ccm', false)
                ->latest('version')->first()?->components()->pluck('name')
                ?? MarkingComponent::where('school_id', $schoolId)->where('is_ccm', false)->global()->pluck('name');

            $overlapping = $ccmMc->intersect($eotMc)->values();
        }

        return response()->json([
            'overlapping' => $overlapping,
        ]);
    }
}
