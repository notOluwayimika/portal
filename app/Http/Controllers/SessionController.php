<?php

namespace App\Http\Controllers;

use App\Http\Requests\SessionRequest;
use App\Models\AcademicSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
        // get count of total sessions, active sessions and inactive sessions for stats
        $total = $school->sessions()->count();
        $active = $school->sessions()->where('is_current', true)->count();
        $inactive = $school->sessions()->where('is_current', false)->count();

        return response()->json([
            "sessions" => $sessions,
            "stats" => [
                "total" => $total,
                "active" => $active,
                "inactive" => $inactive
            ]
        ]);
    }

    public function store(SessionRequest $request)
    {
        try {
            $school = Auth::user()->school;
            $existingSession = $school->sessions()->where('slug', Str::slug(str_replace('/', '-', $request->name), '-'))->first();
            if ($existingSession) {
                return response()->json(['error' => 'A session with this name already exists.'], 500);
            }
            $session = $school->sessions()->create([...$request->validated(), 'slug' => Str::slug(str_replace('/', '-', $request->name), '-')]);

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
            $existingSession = $school->sessions()->where('slug', Str::slug(str_replace('/', '-', $request->name), '-'))->first();
            if ($existingSession && $existingSession->id !== $session->id) {
                return response()->json(['error' => 'A session with this name already exists.'], 500);
            }
            $session->update([...$request->validated(), 'slug' => Str::slug(str_replace('/', '-', $request->name), '-')]);

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

    public function setCurrent($id)
    {
        try {
            $school = Auth::user()->school;
            $session = $school->sessions()->findOrFail($id);
            $school->sessions()->update(['is_current' => false]);
            $session->update(['is_current' => true]);

            return response()->json(['message' => 'Session set as current successfully'], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to set session as current'], 500);
        }
    }
}
