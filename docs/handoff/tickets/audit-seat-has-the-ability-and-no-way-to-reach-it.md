# The audit seat holds the ability and has no page to reach it

**Raised:** 2026-09-01 · **From:** the client-side sweep following `fix/three-authorisation-holes` · **Severity:** ticket

## What

`internal_auditor` holds `activity_log.view`, `activity_log.view_all` (granted by
`2026_09_01_120000_grant_internal_auditor_activity_log_view_all.php`) and `activity_log.export`, and
holds **nothing else** — `RbacSeeder::grantsMap()` gives that seat those three permissions and no
more.

The activity log **page** is `routes/web.php:792-794`, inside the group at `routes/web.php:703`:

```php
Route::middleware(['auth', 'tenant', 'permission:admin_area.access'])->group(function () {
```

The sidebar item that is the only navigation to it is `app-sidebar.tsx:292-296`, inside
`adminNavGroups`, pushed at `app-sidebar.tsx:384` behind `can('admin_area.access')`.

`internal_auditor` does not hold `admin_area.access`. So it cannot see the item and cannot open the
page. It has no other landing either: `dashboard` is gated on `permission:dashboard.view`
(`routes/web.php:993-994`), which that seat also lacks.

## Verified from source before writing this — three readings

**(a) The migration grants a DISTINCT PERMISSION STRING.** It is not a scope widening by some other
mechanism:

```php
private const PERMISSION = 'activity_log.view_all';
private const ROLE = 'internal_auditor';
// …
$role->givePermissionTo(self::PERMISSION);
```

`App\Enums\Permission::ACTIVITY_LOG_VIEW_ALL = 'activity_log.view_all'`
(`app/Enums/Permission.php:56`). It goes to the **global** role row (`whereNull('school_id')`);
school-scoped rows are counted, reported and left alone. `down()` is a deliberate no-op.

**(b) Holders, read from the seeder and the migration — not from a database.**
`$activityAdmin` (`RbacSeeder.php:145-151`) contains `ACTIVITY_LOG_VIEW_ALL` at `:147` and is spread
into exactly two roles: `admin` (`:215`) and `head_of_school` (`:269`). `internal_auditor` holds it
explicitly at `:564`, which is what the migration converges onto the production copy.

| Role | `activity_log.view` | `activity_log.view_all` |
| --- | --- | --- |
| `admin` | yes | yes |
| `head_of_school` | yes | yes |
| `internal_auditor` | yes | yes (via this migration) |
| `teacher` | yes | **no** |

`teacher` spreads `$activityStaff` (`:303`), which is `activity_log.view` + `activity_log.view_own`
and nothing else (`:140-143`). **`teacher` does not hold `activity_log.view_all`.** `super_admin`
holds it by `Gate::before` rather than by grant.

**(c) What `baseQuery` keys on** — `app/Services/ActivityLog/ActivityLogQueryService.php:54-57`:

```php
// No view_all → users only see activity they themselves caused.
if (! $user->can('activity_log.view_all')) {
    $query->where('causer_type', User::class)
        ->where('causer_id', $user->id);
}
```

The school predicate is a separate, earlier clause keyed on `activity_log.view_cross_school`
(`:42-52`), which `internal_auditor` does **not** hold — revoked by `2026_08_04_100000` and staying
revoked under ADR 0036. So `view_all` drops only the SELF predicate; the school bound remains, twice
over (that clause and `SchoolScope`).

## The premise this ticket was raised under is struck: the migration is NOT inert

The original framing asked whether the seat has any API path to activity without
`admin_area.access`, and said that if there were none the 2026-09-01 migration should be called
inert in practice. **It is not inert.**

The audit API is a **separate route group on a different gate** (`routes/api.php:375`):

```php
Route::middleware(['auth:sanctum', 'tenant', 'permission:activity_log.view'])->group(function () {
    require __DIR__.'/endpoints/activity-log.php';
});
```

`admin_area.access` appears in `routes/api.php` only at `:281`, a different group that does not
contain these endpoints. So every audit endpoint — `GET /api/activity-logs`, `/stats`,
`/filters/options`, `/{id}`, the saved-filter trio, and `/export` (which since
`fix/three-authorisation-holes` additionally requires `activity_log.export`, held by this seat) — is
reachable by `internal_auditor` today on `auth` + `tenant` + `activity_log.view` alone.

**And by (c) the migration is load-bearing for that API.** Without `activity_log.view_all` the
auditor's feed is restricted to `causer_id = self`, and an auditor reads other people's acts by
definition — the feed would return nothing. The migration is what makes it non-empty.

**The gap is the absence of a SCREEN, not the absence of ability.** Stated the other way round, the
fix would have been aimed at a migration that is correct and load-bearing.

## What it costs

The seat can read the audit trail only by typing an API URL, and can export only by constructing a
query string by hand. There is no interface. Anyone checking "can the auditor read the log?" by
logging in as that seat and looking will conclude — correctly, from what they can see, and wrongly
about the system — that the grant did not land.

**Launch relevance.** Brookstone's answer A places Internal Audit in the bill review loop. An auditor
who cannot read the trail cannot return a bill. This is not cosmetic for that flow.

## Ruling — do NOT grant `admin_area.access` to `internal_auditor`

That grants a whole area to solve one page, and **everything later placed in that area inherits it
silently** — a widening with no review event attached to it, which is the shape of permission drift
this repo has already paid to unwind (the audit feed itself used to sit behind `academic_data.view`
for the same "it was the group that was handy" reason, and moving it was the fix).

## STRUCK: gate the page on `activity_log.view`

That was the first replacement ruling and it is withdrawn, on the reading in (b). **`teacher` holds
`activity_log.view`** — it is in `$activityStaff`, which every teacher gets. Gating the page on it
would put the school-wide audit feed in front of every teacher.

That is **the same defect as `admin_area.access`, pointing the other way**: an unreviewed widening
inherited from a permission chosen because it was already on a group. Swapping one for the other
moves the problem rather than fixing it — and it would read as a narrowing while being a widening.

## Replacement ruling — gate on `activity_log.view_all`

(a) returned a distinct permission and (b) shows `teacher` does not hold it, so the gate is nameable
rather than an open decision.

**Gate the activity log page and the sidebar item on `permission:activity_log.view_all`.** The
reason is the seat's own definition: **the page exists for whoever reads OTHER PEOPLE'S acts**, and
`activity_log.view_all` is exactly the permission that says so — `baseQuery` uses that same
predicate at `:55` to choose between own-rows-only and all-rows. A viewer without it has nothing on
that page but their own trail. The gate and the query would then key on the same fact, which is the
property `admin_area.access` never had.

### `head_of_school` gains the page — DECIDED, not open

Today the page derives to `admin` and `super_admin` (`route-access-map.json`). Gating on
`activity_log.view_all` yields `admin`, `head_of_school`, `internal_auditor` and `super_admin`, so
`head_of_school` gains it. **That is accepted.**

**The reason it is accepted, and it is not "the seat looks senior enough".** `head_of_school` already
holds `activity_log.view_all` — `$activityAdmin`, `RbacSeeder.php:147`, spread at `:269` — and by
`ActivityLogQueryService.php:54-57` that permission **is** the predicate the query uses to drop the
self-filter. So that seat can already fetch school-wide activity from the API today, on the
`activity_log.view` group at `routes/api.php:375`, with no page involved. **The page widens the
AFFORDANCE, not the AUTHORITY** — it puts a screen over data the seat is already served.

**And this is exactly why `view_all` is the right gate rather than a new permission.** Gate and query
then key on the *same fact* and cannot drift apart. Any gate keying on something else is a second
spelling of an authority whose canonical spelling already exists — which is the defect
[ticket 1](can-export-derives-from-role-names.md) exists to fix, one layer up in the client. Solving
this page with a fourth permission would create the very thing that ticket is about.

**If Brookstone decides `head_of_school` should not see school-wide activity, the defect is the
GRANT, not the screen.** The fix is then to remove `activity_log.view_all` from `head_of_school` in
the grants map, with a converging migration — not to add a permission that hides the page while the
API keeps serving the same rows. **A gate that hides what the API serves is a gate that lies**: it
buys the appearance of a narrowing and leaves the real exposure exactly where it was, with the
reassurance that stops anyone looking again.

**What does not change:** `teacher` keeps `activity_log.view` and `view_own`, so its API access to
its own trail is untouched. Nothing narrows for any current holder — `admin` holds `view_all`, and
`super_admin` passes by `Gate::before`.

## The fix

1. Move `routes/web.php:792-798` (the `activity-logs` index and show routes) out of the
   `admin_area.access` group at `:703` into a group gated on `permission:activity_log.view_all`.
2. Split the "Activity Log" sidebar item out of `adminNavGroups` (`app-sidebar.tsx:292-296`, pushed
   at `:384`) and push it behind `can('activity_log.view_all')` — the same compose-by-permission
   pattern `app-sidebar.tsx` already uses for the Users module (`rbac.manage_users`, `:390`) and the
   Finance additions.

The route and the nav item must take the **same** permission. A visible item whose route refuses is
the silent-refusal shape this repo has already been bitten by; a reachable route with no item is the
gap this ticket is about.

## The fixtures and arms

- `route-middleware-baseline.json` and `route-access-map.json` both change for `GET /activity-logs`
  and `GET /activity-logs/{id}`. **Targeted entries, not a regeneration** — the access map is
  committed at 378 routes and regenerates at 429, so a wholesale rewrite blesses 51 routes nobody has
  reviewed.
- Arms: `internal_auditor` reaches the page — **proven refused first**, since that refusal is the
  defect; and the known negative, `admin` still reaches it. `head_of_school` gets an arm asserting it
  **reaches** the page — the widening is decided above, so the arm records the decision rather than
  waiting on it, and it goes red if someone later narrows the gate without reopening that ruling.
  `teacher` gets an arm asserting it is
  **refused**, which is what pins the struck ruling so `activity_log.view` cannot drift back in as
  the gate — build that arm on `teacher` itself, never on a role that would fail for a second
  reason.

## Related

[`can-export-derives-from-role-names.md`](can-export-derives-from-role-names.md) — the Export button
on that page is gated on a hand-maintained role list that also omits `internal_auditor`. **Both must
land for the auditor to have a working export**: this ticket gets the seat to the page, that one
renders the button once it is there.
