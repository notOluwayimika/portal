<?php

namespace App\Listeners;

use App\Support\Impersonation;
use Illuminate\Auth\Events\Logout;

/**
 * Logging out while impersonating must still close the session on the audit
 * trail, or it shows a start with no end — and "both ends are always logged" is
 * the non-repudiation control ADR 0045 §3 rests on.
 *
 * Hooked on the EVENT rather than in a controller because there are two logout
 * paths — Fortify's web controller (vendor, not editable) and the API one — and
 * both fire Logout via SessionGuard::logout(). One listener covers both, and
 * covers any third path added later. The event fires BEFORE the session is
 * invalidated, so the keys are still readable here.
 *
 * Not queued: it must run inside the request that still has the session.
 */
class EndImpersonationOnLogout
{
    public function handle(Logout $event): void
    {
        $request = request();

        // The one shared helper: writes the exit row AND clears the keys, and
        // no-ops when no session is active.
        Impersonation::endSession($request);
    }
}
