<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticationController extends Controller
{
    public function login(LoginRequest $request)
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        setPermissionsTeamId(null);

        $isSuperAdmin = $user->isSuperAdmin();
        $schools = $user->accessibleSchools();
        $school = null;

        if (! $isSuperAdmin && $schools->isEmpty()) {
            Auth::logout();

            return response()->json(['message' => 'You are not authorized to log in to any school.'], 403);
        }

        if ($schoolUuid = $request->input('school_uuid')) {
            $school = $schools->firstWhere('uuid', $schoolUuid);

            if (! $school) {
                Auth::logout();

                return response()->json(['message' => 'You are not authorized to login to this school.'], 403);
            }
        } elseif (! $isSuperAdmin) {
            if ($schools->count() === 1) {
                $school = $schools->first();
            } else {
                // Multiple schools: the client must retry with school_uuid.
                Auth::logout();

                return response()->json([
                    'message' => 'Select a school to continue.',
                    'requires_school_selection' => true,
                    'schools' => $schools->map(fn ($s) => ['uuid' => $s->uuid, 'name' => $s->name])->values(),
                ], 409);
            }
        }

        $token = $user->createToken('auth_token');

        if ($school) {
            $token->accessToken->forceFill(['school_id' => $school->id])->save();

            if ($request->hasSession()) {
                $request->session()->put('school_id', $school->id);
            }

            setPermissionsTeamId($school->id);
            $user->unsetRelation('roles');
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
            'school' => $school ? ['uuid' => $school->uuid, 'name' => $school->name] : null,
            'schools' => $schools->map(fn ($s) => ['uuid' => $s->uuid, 'name' => $s->name])->values(),
        ]);
    }

    /**
     * Switch the active school for the current session/token.
     */
    public function switchSchool(Request $request)
    {
        $request->validate(['school_uuid' => 'required|uuid']);

        /** @var User $user */
        $user = $request->user();

        $school = School::where('uuid', $request->school_uuid)->first();

        if (! $school || ! $user->canAccessSchool($school->id)) {
            return response()->json(['message' => 'You are not authorized to login to this school.'], 403);
        }

        if ($request->hasSession()) {
            $request->session()->put('school_id', $school->id);
        }

        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->forceFill(['school_id' => $school->id])->save();
        }

        setPermissionsTeamId($school->id);
        $user->unsetRelation('roles');

        return response()->json([
            'message' => 'School switched.',
            'school' => ['uuid' => $school->uuid, 'name' => $school->name],
        ]);
    }

    public function user()
    {
        return new UserResource(Auth::user());
    }

    public function logout(Request $request)
    {
        // Under auth:sanctum the default guard is Sanctum's RequestGuard,
        // which has no logout() — the old Auth::logout() call 500'd for every
        // caller this endpoint ever admitted. Revoke the bearer token when one
        // was used (session logins carry a TransientToken, which has none),
        // and end the session-based login explicitly on the web guard.
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        Auth::guard('web')->logout();

        return response()->json(['message' => 'Logged out successfully']);
    }
}
