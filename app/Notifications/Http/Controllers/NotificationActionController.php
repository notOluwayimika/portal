<?php

namespace App\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\Models\Notification;
use App\Notifications\Models\NotificationAction;
use App\Notifications\Models\NotificationRecipient;
use App\Notifications\Services\NotificationActionResolver;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tap. THE ONLY NEW TRUST BOUNDARY in this feature.
 *
 * ⚠️ AUTHORIZATION IS EXPLICIT BECAUSE THERE IS NOTHING UNDERNEATH IT. This
 * application has no row-level security — isolation is BelongsToSchool plus
 * ActiveSchool, in application code — so a scope omitted here is a cross-tenant hole
 * with no database backstop to catch it. Every predicate below is written out rather
 * than inherited from a global scope, because a global scope is one
 * `withoutGlobalScopes()` or one raw builder away from absent, and a security control
 * that a refactor can silently remove is not a control.
 *
 * TWO CHECKS, ONE STATUS CODE:
 *   a. tenant    — the action's school_id must equal the ACTIVE school
 *   b. recipient — the caller must be a recipient of the parent notification
 *
 * BOTH RETURN 404, never 403. A 403 confirms the resource exists, which is exactly
 * the fact being protected: "this action belongs to another school" and "you were
 * never sent this" are both answers a caller must not be able to distinguish from
 * "no such action". Same reasoning as the feed's mark-read endpoint.
 *
 * EVERY TERMINAL STATE IS A 200. `resolved`, `expired`, `rejected` and `unconfirmed`
 * are RESULTS of a well-formed, authorized request — the client renders them. Mapping
 * `expired` to 410 or `rejected` to 422 would make the client parse HTTP status to
 * learn a domain outcome, and would make a legitimate "your co-parent got there
 * first" indistinguishable from a bug. The only non-200 is the authorization wall.
 *
 * IT BLOCKS ON THE CALLBACK, BY DESIGN. Up to the transport's ten seconds — that is
 * the tap-and-see-the-outcome UX. A timeout returns 200 + `unconfirmed`, which is the
 * truthful "we do not know", reconciled later.
 */
class NotificationActionController extends Controller
{
    public function __construct(private readonly NotificationActionResolver $resolver) {}

    public function store(
        Request $request,
        Notification $notification,
        NotificationAction $action,
    ): JsonResponse {
        $user = $request->user();
        $schoolId = ActiveSchool::getOrFail()->id;

        $this->authorizeTap($action, $notification, $user, $schoolId);

        // Straight to the resolver. NO read-then-write around it: the conditional
        // UPDATE inside is the concurrency guarantee, and re-checking claimability
        // here would reintroduce exactly the race it exists to close — the endpoint
        // would look correct and fire the callback twice under real concurrency.
        $resolved = $this->resolver->resolve($action, (int) $user->id);

        return response()->json([
            'id' => $resolved->uuid,
            'status' => $resolved->status->value,
            'outcome' => $resolved->outcome?->value,
            // The uuid, never the integer id — and it answers "who acted", which for
            // a losing co-guardian is the whole content of the response.
            'resolved_by' => $resolved->resolvedBy?->getAttribute('uuid'),
            'resolved_at' => $resolved->resolved_at?->toIso8601String(),
        ]);
    }

    /**
     * Tenant, then recipient. 404 on either.
     *
     * `abort(404)` rather than a policy, because both checks are ownership
     * predicates over rows rather than ability questions, and expressing them as a
     * policy would invite `Gate::allows` — which for a `super_admin` returns TRUE by
     * bypass (ADR 0040) and would hand platform staff the ability to tap a parent's
     * revocation on their behalf. Isolation is never bypassed by authority
     * (Constitution 1 / ADR 0036), so it must not be expressed as one.
     */
    private function authorizeTap(
        NotificationAction $action,
        Notification $notification,
        ?User $user,
        int $schoolId,
    ): void {
        if ($user === null) {
            abort(404);
        }

        // (a) TENANT. Explicit, on the action's own denormalised column — not through
        // the notification, so no future refactor of the relationship can drop it.
        if ((int) $action->school_id !== $schoolId) {
            abort(404);
        }

        // Nested-route integrity: the action must belong to the notification named in
        // the URL. Without this, a valid action uuid could be tapped through any
        // notification the caller does happen to be a recipient of — the recipient
        // check below would pass against the WRONG notification.
        if ((int) $action->notification_id !== (int) $notification->id) {
            abort(404);
        }

        if ((int) $notification->school_id !== $schoolId) {
            abort(404);
        }

        // (b) RECIPIENT. You cannot act on a notification you were never sent.
        // Scoped by school again rather than trusting the row's parentage: the
        // predicate is cheap and the omission is a tenant leak.
        $isRecipient = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->where('school_id', $schoolId)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->exists();

        if (! $isRecipient) {
            abort(404);
        }
    }
}
