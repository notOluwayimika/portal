<?php

namespace App\Http\Controllers;

use App\Http\Requests\SessionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SessionController extends Controller
{
    public function index()
    {
        return Inertia::render('settings/sessions', []);
    }

    public function indexApi(Request $request)
    // get limit from request
    {
        $limit = $request->input('limit', 10);
        // get filter from request param, total, active or inactive
        $filter = $request->input('filter', 'total');
        $search = $request->input('search', '');
        $school = Auth::user()->school;
        $sessions = $school->sessions()->paginate($limit);
        if ($filter === 'active') {
            $sessions = $school->sessions()->where('is_current', true)->paginate($limit);
        } elseif ($filter === 'inactive') {
            $sessions = $school->sessions()->where('is_current', false)->paginate($limit);
        }
        if ($search) {
            $sessions = $school->sessions()->where('name', 'like', "%$search%")->paginate($limit);
        }

        return response()->json($sessions);
    }

    public function store(SessionRequest $request)
    {
        try {
            $school = Auth::user()->school;
            $session = $school->sessions()->create($request->validated());

            return response()->json($session, 201);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to create session'], 500);
        }

    }

    public function update(SessionRequest $request, $id)
    {
        try {
            $school = Auth::user()->school;
            $session = $school->sessions()->findOrFail($id);
            $session->update($request->validated());

            return response()->json($session);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to update session'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $school = Auth::user()->school;
            $session = $school->sessions()->findOrFail($id);
            $session->delete();

            return response()->json(['message' => 'Session deleted successfully']);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to delete session'], 500);
        }
    }
}
