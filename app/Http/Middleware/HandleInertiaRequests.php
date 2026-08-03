<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\EffectivePermissions;
use App\Support\Impersonation;
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
    /**
     * Who is really driving this session, when it is not the acting user.
     *
     * Read from the session rather than from Impersonation's in-process state so
     * it reflects the durable session, and resolved without global scopes: the
     * operator is a super_admin with no school of their own.
     *
     * @return array{operator: string, target: string, school: string|null}|null
     */
    private function impersonationState(Request $request): ?array
    {
        if (! $request->hasSession() || ! $request->session()->has(Impersonation::SESSION_KEY)) {
            return null;
        }

        $state = (array) $request->session()->get(Impersonation::SESSION_KEY);

        $operator = User::withoutGlobalScopes()->find($state['operator_id'] ?? null);
        $target = User::withoutGlobalScopes()->find($state['target_id'] ?? null);

        if (! $operator || ! $target) {
            return null;
        }

        return [
            'operator' => $operator->full_name,
            'target' => $target->full_name,
            'school' => School::find($state['school_id'] ?? null)?->name,
        ];
    }

    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $activeSchoolId = $user ? ActiveSchool::id() : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                // DO NOT use `auth.user.guardian` to identify WHICH Guardian record
                // to act on. A Guardian is a per-School record, so a parent with
                // wards in two Schools has two rows sharing this one User, and this
                // is an unordered hasOne whose global scope (Guardian::applySchoolScope)
                // matches on `school_id = active OR user has access to active` — it
                // can hand back the wrong School's row. The parent portal shipped
                // that bug. Resolve server-side via
                // GuardianService::forUserInActiveSchool instead.
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
            // Non-null ONLY inside an impersonation session. `auth.user` above is
            // already the TARGET (this middleware runs after ApplyImpersonation,
            // deliberately), so without this the UI has no way to tell it is not
            // really that person — which is exactly the state a persistent banner
            // has to make impossible to miss.
            'impersonation' => $this->impersonationState($request),

            // v1 has no push transport (BROADCAST_CONNECTION is `log`; no Reverb),
            // so the poll interval IS the real-time story. Shared from config
            // rather than hard-coded in the hook, so it can be widened when the
            // queue is backed up without a frontend deploy. `enabled` false hides
            // the bell entirely while the subsystem ships dark.
            'notifications' => [
                'enabled' => (bool) config('notifications.enabled'),
                'pollSeconds' => (int) config('notifications.feed.poll_seconds'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
