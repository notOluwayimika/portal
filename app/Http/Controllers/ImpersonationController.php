<?php

namespace App\Http\Controllers;

use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Start and stop a super-admin impersonation session (ADR 0045).
 */
class ImpersonationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_uuid' => ['required', 'string'],
        ]);

        /** @var User $operator */
        $operator = $request->user();

        // THE REAL GATE. The route also carries permission:rbac.impersonate,
        // but that resolves through the Gate, which Gate::before answers true
        // for ANY super_admin while the bypass is on — grant held or not. So
        // the route check is decorative today and would flip to stranding
        // every super_admin the moment 0045-C removes the bypass. This one is
        // flag-independent (spatie's hasPermissionTo, in the null team).
        // Do not delete it because the route "already guards".
        abort_unless(Impersonation::operatorMayImpersonate($operator), 403,
            'Impersonation is a platform-admin capability.');

        abort_if($request->session()->has(Impersonation::SESSION_KEY), 409,
            'You are already impersonating. Stop the current session first.');

        $schoolId = ActiveSchool::id();

        abort_unless((bool) $schoolId, 422,
            'Select a school before impersonating — a session is always scoped to one school.');

        // Users are school-scoped; the operator is a super_admin whose own
        // scope is global, so resolve without the scope and prove access
        // explicitly below rather than relying on the binding to fail closed.
        $target = User::withoutGlobalScope(SchoolScope::class)
            ->where('uuid', $data['user_uuid'])
            ->first();

        abort_unless((bool) $target, 404);

        abort_if($target->is($operator), 422, 'You cannot impersonate yourself.');

        // No lateral escalation into another platform-admin identity. Checked
        // structurally (isSuperAdmin), not via a grant the bypass could answer.
        abort_if($target->isSuperAdmin(), 403, 'A super admin cannot be impersonated.');

        // The session must start in a context the target could legitimately
        // occupy: impersonating someone INTO a school they cannot reach would
        // manufacture access that does not exist.
        abort_unless($target->canAccessSchool($schoolId), 422,
            'That user has no access to the active school.');

        Impersonation::startSession($request, $operator, $target, $schoolId);

        return redirect()->route('dashboard')
            ->with('status', 'You are now acting as '.$target->full_name.'.');
    }

    /**
     * Stop — authorized by SESSION STATE, never by the acting user's grants.
     *
     * While impersonating, the acting user IS the target, who does not hold
     * rbac.impersonate. A permission- or role-gated stop route would therefore
     * 403 the operator and strand them inside the session with no way out. The
     * presence of an impersonation session is itself the authorization: only
     * someone already inside one can end one.
     */
    public function destroy(Request $request): RedirectResponse
    {
        if (! Impersonation::endSession($request)) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('super-admin.home')
            ->with('status', 'Impersonation ended.');
    }
}
