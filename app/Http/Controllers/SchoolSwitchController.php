<?php

namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SchoolSwitchController extends Controller
{
    /**
     * School selection page (post-login step / in-app switcher target).
     */
    public function show(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $schools = $user->accessibleSchools();
        $currentId = $request->session()->get('school_id');

        return Inertia::render('auth/select-school', [
            'schools' => $schools->map(fn ($s) => [
                'uuid' => $s->uuid,
                'name' => $s->name,
                'current' => $currentId && (int) $currentId === (int) $s->id,
            ])->values(),
        ]);
    }

    /**
     * Set the active school for the session.
     */
    public function switch(Request $request)
    {
        $request->validate(['school' => 'required|uuid']);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $school = School::where('uuid', $request->school)->firstOrFail();

        if (!$user->canAccessSchool($school->id)) {
            abort(403, 'You are not authorized to login to this school.');
        }

        $request->session()->put('school_id', $school->id);
        $request->session()->save();

        setPermissionsTeamId($school->id);
        $user->unsetRelation('roles');

        return redirect()->intended('/dashboard');
    }
}
