<?php

namespace App\Http\Controllers;

use App\Http\Requests\SessionRequest;
use App\Http\Resources\SessionResource;
use App\Models\AcademicSession;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

class SessionController extends Controller
{
    public function index(Request $request)
    // get limit from request
    {
        $school = Auth::user()->school;
        $limit = $request->input('limit', 10);
        // get filter from request param, total, active or inactive
        $filter = $request->input('filter', 'total');
        $search = $request->input('search', '');
        $sessions = $school->sessions();
        if ($filter === 'active') {
            $sessions = $sessions->where('is_current', true);
        } elseif ($filter === 'inactive') {
            $sessions = $sessions->where('is_current', false);
        }
        if ($search) {
            $sessions = $sessions->where('name', 'like', "%$search%");
        }
        $sessions = $sessions->paginate($limit);
        $stats = [
            'total' => $school->sessions()->count(),
            'active' => $school->sessions()->where('is_current', true)->count(),
            'inactive' => $school->sessions()->where('is_current', false)->count()
        ];

        return response()->json([
            "sessions" => SessionResource::collection($sessions),
            "pagination" => [
                "total" => $sessions->total(),
                "per_page" => $sessions->perPage(),
                "current_page" => $sessions->currentPage(),
                "last_page" => $sessions->lastPage(),
            ],
            "stats" => $stats
        ]);
    }

    public function store(SessionRequest $request)
    {
        try {
            $school = Auth::user()->school;
            $is_current = $request->input('is_current', false);
            if ($is_current) {
                $school->sessions()->update(['is_current' => false]);
            }

            $existingSession = $school->sessions()->where('slug', Str::slug(str_replace('/', '-', $request->name), '-'))->first();
            if ($existingSession) {
                return response()->json(['error' => 'A session with this name already exists.'], 500);
            }
            $data = [...$request->validated(), 'slug' => Str::slug(str_replace('/', '-', $request->name), '-'), 'school_id' => $school->id];
            $session = $school->sessions()->create($data);
            if (!$session) {
                throw new \Exception('Failed to create session');
            }

            return response()->json(new SessionResource($session), 201);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to create session'], 500);
        }

    }

    public function update(SessionRequest $request, AcademicSession $session)
    {
        try {
            $school = Auth::user()->school;
            $existingSession = $school->sessions()->where('slug', Str::slug(str_replace('/', '-', $request->name), '-'))->first();
            if ($existingSession && $existingSession->id !== $session->id) {
                return response()->json(['error' => 'A session with this name already exists.'], 500);
            }
            $session->update([...$request->validated(), 'slug' => Str::slug(str_replace('/', '-', $request->name), '-')]);

            return response()->json(new SessionResource($session), 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to update session'], 500);
        }
    }

    public function destroy(AcademicSession $session)
    {
        try {
            $session->delete();

            return response()->json(['message' => 'Session deleted successfully']);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to delete session'], 500);
        }
    }

    public function setCurrent(AcademicSession $session)
    {
        try {
            $school = Auth::user()->school;
            if (!$session) {
                return response()->json(['error' => 'Session not found'], 404);
            } else if ($session->school_id !== $school->id) {
                return response()->json(['error' => 'Session does not belong to the current school'], 403);
            }
            foreach ($school->sessions as $s) {
                $s->update(['is_current' => false]);
            }
            $session->update(['is_current' => true]);

            return response()->json(new SessionResource($session), 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to set session as current'], 500);
        }
    }
}
