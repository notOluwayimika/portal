<?php

namespace App\Http\Controllers;

use App\Http\Resources\SportHouseResource;
use App\Models\SportHouse;
use Illuminate\Http\Request;

class SportHouseController extends Controller
{
    public function index()
    {
        try {
            $sportHouses = auth()->user()->school->sportHouses;
            return SportHouseResource::collection($sportHouses);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to retrieve sport houses'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate(['name' => 'required|string|max:255']);

            $school = auth()->user()->school;
            $existing = $school->sportHouses()->where('name', $request->name)->first();
            if ($existing) {
                return response()->json(['error' => 'Sport house with this name already exists'], 409);
            }

            $sportHouse = $school->sportHouses()->create(['name' => $request->name]);
            return response()->json(new SportHouseResource($sportHouse), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to create sport house'], 500);
        }
    }

    public function update(Request $request, SportHouse $sportHouse)
    {
        try {
            $request->validate(['name' => 'required|string|max:255']);

            $sportHouse->update($request->only('name'));
            return new SportHouseResource($sportHouse);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to update sport house'], 500);
        }
    }

    public function destroy(SportHouse $sportHouse)
    {
        try {
            $sportHouse->delete();
            return response()->json(['message' => 'Sport house deleted successfully']);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to delete sport house'], 500);
        }
    }
}
