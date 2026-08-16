# `/students` renders two placeholder-only selects after a 403 it never handles

**Raised by:** the drive on `feat/ui-bank-accounts-fee-schedules-redesign` (2026-08-15). `/students`
was opened only because it is one of the six consumers of `base-dropdown`, to prove that branch's
scroll fix on a page it does not touch. The scroll fix worked; this is what else was on the screen.

**Pre-existing. On a page that branch does not modify.** Filed rather than fixed.

## What was observed

Seat: `super@drive.test` (`super_admin`, **no school selected** — login landed on
`/super-admin/schools`, the school picker). Navigated directly to `/students`.

The page rendered: `url=http://localhost:8001/students`, `h1="Students"`. **HTTP 200.** Then:

```
http 403: GET http://localhost:8001/api/notifications/unread-count
http 403: GET http://localhost:8001/api/students/resources
pageerror: AxiosError: Request failed with status code 403
```

And the two leading filter dropdowns came back holding nothing but their own placeholder:

```
PAGE select #0 current="" options(1): ["|All class levels"]
PAGE select #1 current="" options(1): ["|All arms"]
```

One option each, value `""`. For contrast, the nine status selects on the same page — which are fed
by a hard-coded array, not a fetch — were fully populated:

```
PAGE select #2 current=""       options(4): ["active|Active","promoted|Promoted","repeated|Repeated","withdrawn|Withdrawn"]
```

Screenshot: `docs/handoff/drives/2026-08-15-untouched-consumers/students-01-open-before-scroll.png`.

## Why it happens — and it is NOT a permission bug

> **Corrected 2026-08-15 after cold review.** The first version of this ticket blamed the `tenant`
> middleware. That is wrong: `tenant` lets this seat straight through. The refusal happens **inside
> the controllers**, at `ActiveSchool::getOrFail()`. The conclusion is unchanged — it is isolation,
> not permission, and the backend is behaving correctly — but anyone acting on the old mechanism
> would have gone looking in the wrong file.

Both 403s have the same cause, and the cause is **correct behaviour**.

**It is not `tenant`.** `tenant` is `SetSchoolContext` (`bootstrap/app.php:82`), and its refusal
branch is:

```php
if (! $isSuperAdmin && ! $activeSchoolId) {   // SetSchoolContext.php:51
    …  return response()->json(['message' => 'No active school selected.'], 403);
}
```

A `super_admin` **fails the first conjunct**, so a super admin with no school selected **falls
through the middleware without being refused**. Nothing in the middleware stack produced these 403s.

**It is `ActiveSchool::getOrFail()`**, `app/Support/ActiveSchool.php:66-72`:

```php
$school = School::find(static::id());

abort_unless((bool) $school, 403, 'No active school selected.');   // :70
```

Reached from the two controllers directly:

- `app/Http/Controllers/StudentController.php:196` — `$school = ActiveSchool::getOrFail();` inside
  `resources()`;
- `app/Notifications/Http/Controllers/NotificationFeedController.php:120` —
  `ActiveSchool::getOrFail()->id` in the recipient-query builder.

The `permission:` middleware is a red herring on both routes, and on one of them it does not exist:
**the notifications group carries no `permission:` middleware at all**
(`routes/endpoints/notifications.php`, `Route::middleware(['auth:sanctum', 'tenant'])`), its own
docblock explaining that a permission there could lock a user out of their own notifications. So
nothing in that route's stack could have produced its 403 — only the controller could, and it did.

The substance is unchanged: `ActiveSchool::id()` is null for a super admin who has selected no
school, `getOrFail()` refuses, and that is **isolation** doing its job — bypass is _authorization_,
never _isolation_ (ADR 0036, Constitution 13). The layer is a controller call, not a middleware.

**So nothing in the backend needs fixing.** The defect is entirely that the frontend proceeds as
though the request had succeeded.

`resources/js/pages/admin/students/index.tsx:115-122`:

```ts
axios.get('/api/students/resources').then((res) => {
    if (!isMounted) {
        return;
    }
    setClassLevels(res.data.data.class_levels || []);
    setArms(res.data.data.arms || []);
    setClassLevelArms(res.data.data.class_level_arms || []);
});
```

A `.then()` with **no `.catch()`**. On a 403 the state simply never leaves `[]`, so the two selects
render their placeholder and nothing else, and the rejection escapes as the unhandled
`AxiosError` seen in the console above.

## The resemblance this is filed for

This is the **U1 fee-schedules defect**, one page over: _a select that renders empty because the data
behind it never arrived, on a page that returns 200._

The `finance-drive` skill opens with the same class — the opening-balance operator screen where
`routes/web.php` bound a **School model** into `where('school_id', …)`, matched nothing, and left the
term select empty on a page that returned 200 with every assertion passing
(`docs/handoff/reports/feat-finance-ob-operator-screen.md:200-215`). The skill's own summary of why
drives exist names it directly: _"a select rendering empty because the fixture seeds nothing behind
it"_. Here the cause is a 403 rather than a bad binding or a thin fixture, but the **symptom, and its
invisibility to every gate, are identical**: a 200, a rendered page, a control the operator cannot
use, and no test that can see the difference.

`resources/js/components/finance/new-invoice-modal.tsx:540-560` is the in-repo example of the
handled version, and it is worth copying rather than re-deriving — it branches on
`policiesLoading` / `policiesFailed` / `policies.length === 0` and, for the empty case, says what is
actually true and where to go instead of rendering a control with nothing in it. Its comment: _"NEVER
AN EMPTY SELECT. A select with only the placeholder in it looks like a control the operator has not
used yet, so they hunt for the option that is not there."_

## Cross-reference: the `/dashboard` 403 for the finance drive seats

Same shape — a seat-versus-route mismatch that only a drive can see — and it is the reason both are
worth grouping.

`maker@drive.test` and `school-b@drive.test` sign in successfully and are then refused on
`GET /dashboard` and bounced back to `/login`. Confirmed again on this branch's drive: the raw log
records `http 403: GET http://localhost:8001/dashboard` after every finance-seat login
(`docs/handoff/drives/2026-08-15-drive-log.txt`).

**A correction worth recording, because the cross-reference does not resolve where it appears to.**
Both the `finance-drive` skill and this project's write-ups refer to that as _"filed as a ticket by
the drive that observed it"_. **It was not.** It exists as a `ticket`-tagged bullet inside a report —
`docs/handoff/reports/feat-discount-policies-page.md:456-460` — and no file for it exists under
`docs/handoff/tickets/`. That was verified while writing this ticket, precisely because this one was
meant to link to it. A finding tagged `ticket` in a report is not a ticket; the skill's citation has
been pointing at a report bullet for weeks.

Both are seat-versus-route mismatches, both were found by a drive rather than by the suite, and both
leave the user on a screen that looks broken while the backend behaves exactly as designed.

## Scope of the observation — read this before acting

**Only one seat state was driven: a `super_admin` with no school selected.** That is arguably the
least representative seat on the platform, and it is the only one this was seen on.

Not checked, and each would change what the fix is:

- a **school-scoped** admin holding `academic_setup.manage` (the intended user of this screen) — this
  may well be entirely fine for them, in which case the bug is only ever visible to a contextless
  super admin;
- the same `super_admin` **after** selecting a school through `/super-admin/schools`;
- a seat that holds a school but **lacks** `academic_setup.manage`, where the `permission:`
  middleware on `routes/api.php:47` would 403 `students/resources` before the controller is reached —
  the same silent failure, a genuinely different layer. (Notifications would still succeed for that
  seat, since its group has no `permission:` at all.)

Establish which of those actually reach this state before deciding how much to build. If it is only
the contextless super admin, the honest fix may be to refuse the page and send them to the school
picker rather than to render an unusable filter bar.

## What the fix looks like

- Give the fetch a `.catch()`. An unhandled rejection reaching `pageerror` is a defect on its own,
  independent of anything above.
- Distinguish **loading**, **failed** and **genuinely empty** for those two selects, as
  `new-invoice-modal.tsx` already does. "No class levels have been set up" and "we could not load the
  class levels" are different sentences and must not share a rendering — the same rule
  [`docs/ui-ux-design-system.md`](../../ui-ux-design-system.md) § 13 states for tables, applied to a
  select.
- For the contextless-super-admin case specifically, consider refusing earlier: a page that needs a
  school should say so rather than render a filter bar that cannot filter.

## Cross-references

- [`dark-mode-is-unreachable-for-every-user.md`](dark-mode-is-unreachable-for-every-user.md) — the
  other pre-existing finding from the same drive.
- [`no-javascript-test-runner.md`](no-javascript-test-runner.md) — why neither was catchable by the
  suite.
- `docs/handoff/reports/feat-finance-ob-operator-screen.md:200-215` — the original empty-select
  defect this one resembles.
