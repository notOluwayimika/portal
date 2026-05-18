<?php

namespace App\Http\Controllers;

use App\Http\Resources\MarkingComponentResource;
use App\Models\MarkingComponent;
use Illuminate\Http\Request;

class MarkingComponentController extends Controller
{
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
}
