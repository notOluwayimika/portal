<?php

namespace App\Http\Controllers;

use App\Http\Resources\MarkingComponentResource;
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
    public function index()
    {
        $markingComponents = MarkingComponent::global()->where('school_id', auth()->user()->school_id)->get();
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
            // weight is stored as a fraction: 0.000 - 1.000
            'components.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $components = $validated['components'];

        // Server-side guarantee that the weights add up to exactly 100%.
        $sum = array_sum(array_map(
            static fn($c) => (float) $c['percent'] / 100,
            $components
        ));

        if (abs($sum - 1.0) > self::SUM_TOLERANCE) {
            throw ValidationException::withMessages([
                'components' => 'Weights must add up to exactly 100% (got '
                    . round($sum * 100, 1) . '%).',
            ]);
        }

        // Guard against ids that belong to a curriculum subject being
        // smuggled in -- we only ever touch global components here.
        $submittedIds = collect($components)
            ->pluck('id')
            ->filter()
            ->values();

        $validIds = MarkingComponent::query()
            ->global()
            ->whereIn('uuid', $submittedIds)
            ->pluck('uuid');

        DB::transaction(function () use ($components, $validIds) {
            // Delete global components the user removed on the frontend.
            MarkingComponent::query()
                ->global()
                ->whereNotIn('uuid', $validIds)
                ->delete();

            foreach ($components as $component) {
                $id = $component['id'] ?? null;

                // Only update in place if the id is a known global row.
                if ($id !== null && $validIds->contains($id)) {
                    MarkingComponent::query()
                        ->global()
                        ->where('uuid', $id)
                        ->update([
                            'name' => $component['name'],
                            'weight' => $component['percent'] / 100,
                        ]);
                } else {
                    MarkingComponent::create([
                        'curriculum_subject_id' => null,
                        'name' => $component['name'],
                        'weight' => $component['percent'] / 100,
                        'school_id' => auth()->user()->school_id
                    ]);
                }
            }
        });

        return response()->json(['success', 'Marking components saved.'], 200);
    }
}
