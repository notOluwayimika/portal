<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\EffectivePermissions;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $activeSchoolId = $user ? ActiveSchool::id() : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? $user->load(['teacher', 'guardian']) : null,
                'school' => $activeSchoolId ? School::with('currentSession')->find($activeSchoolId) : null,
                'schools' => $user
                    ? $user->accessibleSchools()->map(fn ($s) => ['uuid' => $s->uuid, 'name' => $s->name])->values()
                    : [],
                'isSuperAdmin' => $user ? $user->isSuperAdmin() : false,
                'roles' => $user ? $user->getRoleNames() : [],
                // Effective authority (c4-brief D1): what can() grants in the
                // active School's team, so the UI reflects what the Gate will do
                // — including the super-admin bypass and ADR 0040's checker
                // exclusion — not the literal grant table. Replaces rolesFull
                // (dropped: no frontend read; bite-proven).
                'permissions' => $user ? EffectivePermissions::for($user) : [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
