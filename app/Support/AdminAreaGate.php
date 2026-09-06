<?php

namespace App\Support;

/**
 * The two middleware strings that gate the admin area, named once so they cannot drift apart.
 *
 * WHY TWO GATES AND NOT ONE. `admin_area.access` is a group gate over a FLAT, MIXED area: measured
 * from `route:list` on 2026-09-06, it is the sole guard on 22 GET routes AND 18 write routes —
 * POST/PUT `/students`, POST `/api/guardians/{guardian}/password`, POST|DELETE `/setup/principals`
 * and `/api/heads-of-schools`, the notice CRUD, POST|DELETE `/api/teacher-assignments`, PUT
 * `/api/teachers/{teacher}/schools`, and the two curriculum migrations. Granting it to a read-only
 * seat therefore grants student creation and guardian password resets, whatever the name suggests.
 *
 * That is the hazard routes/web.php already ruled on for the audit seat — "granting a whole AREA to
 * solve one page means everything later placed in that area inherits it silently"
 * (docs/handoff/tickets/audit-seat-has-the-ability-and-no-way-to-reach-it.md) — and the resolution
 * here is the same shape: a second, read-only door.
 *
 * HOW IT IS APPLIED. The GROUP carries {@see READ}; each WRITE inside re-narrows to {@see ACCESS}
 * on its own declaration. Middleware stacks intersect, so a write ends up requiring
 * `(access OR view) AND access` — which is `access`, exactly what it required before.
 *
 * ─── WHY NOT `withoutMiddleware`, WHICH IS THE OBVIOUS SHAPE ─────────────────────────────────────
 *
 * The tidier-looking version keeps the group on ACCESS and lets each read opt IN via
 * `->withoutMiddleware(self::ACCESS)->middleware(self::READ)`. It was built that way first, and it
 * is WRONG HERE — not because it misbehaves at runtime (it does not; the router honours the
 * exclusion) but because it is INVISIBLE TO BOTH ROUTE ORACLES. `RouteAccessMap::derive()` and
 * `RbacDeriveRouteBaseline::snapshot()` each read `$route->gatherMiddleware()`, which does NOT
 * subtract excluded middleware, and `derive()` then INTERSECTS the stack. So an excluded gate is
 * still counted, the map records the read routes as admitting `['admin','super_admin']`, and
 * `admin_viewer`'s real access to them appears in no fixture at all. Measured 2026-09-06: the
 * regenerated `route-access-map.json` showed exactly that, listing both gates in the middleware
 * baseline while crediting `admin_viewer` with none of the 22 reads.
 *
 * That is disqualifying rather than cosmetic. Route access here is reviewed as a FIXTURE DIFF —
 * `RouteAccessParityTest` reds until somebody regenerates and reads the change. A mechanism the
 * oracle cannot see silently switches that review off for every future widening, which is the
 * "instrument blind to its own axis" failure this repository keeps paying for. The intersecting
 * form below is more verbose at the call site and fully legible to both instruments, so a widening
 * cannot land unreviewed.
 *
 * WHAT GUARDS THE REMAINING GAP. This shape is fail-OPEN for a route ADDED to a group later: it
 * inherits READ. Two mechanisms close that, and neither is the group's shape.
 *   1. `AdminViewerHoldsNoWriteGateTest` walks the LIVE route table and fails if any ability
 *      `admin_viewer` holds unlocks a non-GET route. A write added without re-narrowing reds there.
 *   2. Any new `admin_viewer` access at all — read or write — changes `route-access-map.json` and
 *      reds `RouteAccessParityTest` until regenerated as a reviewed diff. This is precisely the
 *      mechanism `withoutMiddleware` would have defeated.
 */
final class AdminAreaGate
{
    /** The area gate as it has always been: entry AND every write behind it. */
    public const ACCESS = 'permission:admin_area.access';

    /**
     * The read widening. An OR, so every current `admin_area.access` holder keeps exactly what it
     * had — this moves nobody's authority, it only adds a second key that opens fewer doors.
     */
    public const READ = 'permission:admin_area.access|admin_area.view';
}
