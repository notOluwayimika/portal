# `guardian.view` is asserted on a route its own users cannot satisfy

**Raised:** 2026-09-01 · **From:** the Phase-0 read of `GET /api/guardians/{guardian:uuid}/students` · **Severity:** ticket (blocks the `AUTHZ_ENFORCE` flip)

## What

`App\Http\Controllers\GuardianController@students`:

```php
Authz::abilityCheck(request()->user(), 'guardian.view', 'GuardianController@students');
```

The route's legitimate population is **guardians fetching their own ward list**. The `guardian` role
does not hold `guardian.view`.

In observe mode `Authz::gate` records the would-be denial and lets the request continue
(`app/Support/Authz.php:40-51`), so nothing is visibly wrong today. On the `AUTHZ_ENFORCE` flip the
same line becomes `abort(403)` for every seat that reaches it and does not hold `guardian.view`.
**How many of those are parents is UNVERIFIED — see the section below**; the July census is a window
that closes before the replacement route existed.

## The seats, read from the seeder — and it is worse than one population

| | Holders |
| --- | --- |
| `student_status.view` — the group gate at `routes/api.php:428` | `admin` (`:259`), `head_of_school` (`:298`), `teacher` (`:313`), `guardian` (`:331`), `principal` (`:348`) |
| `guardian.view` — what the controller asserts | `admin` (`:211`, via `$guardianFull`), `head_of_school` (`:265`, via `$guardianFull`), `registrar` (`:316`, explicit) |

**One correction to the brief that raised this:** `RbacSeeder.php:316` is inside the **`registrar`**
block, not `head_of_school`. `head_of_school` gets `guardian.view` at `:265` through
`$guardianFull` (`:153-163`). The conclusion is unchanged; the citation is not.

Two things fall out of the table that the original framing did not have:

- **The flip breaks THREE of the five admitted seats, not one.** `guardian`, `teacher` and
  `principal` are all admitted by the group and all fail the controller's ability. Parents are the
  most visible population, not the only one — and see the SUPERSEDED note below before assuming they
  are still on this route at all.
- **`registrar` holds `guardian.view` and cannot reach this route at all** — it holds no
  `student_status.view`, so it never passes the group. An ability check whose holder set is disjoint
  from the route's admitted set in *both* directions is not a narrowed gate; it is a check that
  belongs to a different route. That is the tell.

## UNVERIFIED — the parent portal may no longer touch this route at all

`routes/api.php:459-469` carries a second, newer endpoint:

```php
Route::middleware(['auth:sanctum', 'tenant', 'permission:parent_portal.access'])->group(function () {
    // The parent portal's ward list. Gated on the SAME ability as the page that
    // consumes it (`parent/wards`, routes/web.php) — the page previously fed off
    // /api/guardians/{uuid}/students, which sits under `student_status.view`, so a
    // guardian role holding one ability but not the other rendered the page and
    // then silently failed to fill it. Takes no guardian id: see
    // GuardianController::wards.
    Route::get('/parent/wards', [GuardianController::class, 'wards']);
});
```

`GuardianController@wards` takes **no guardian id**, resolves the row server-side
(`forUserInActiveSchool($request->user())`), and carries **no `Authz` check at all**. So the parent
page was moved off the route this ticket fixes, and moved for an ability mismatch of the same family.

**Two source facts that narrow it further, and neither settles it:**

- **No first-party client calls the old route.** `resources/js/pages/parent/wards.tsx:130` calls
  `/api/parent/wards`. A sweep of `resources/js/pages/`, `components/` and `layouts/` finds no caller
  of `/api/guardians/{uuid}/students` outside generated wayfinder stubs under `resources/js/actions/`,
  which are URL builders rather than call sites.
- **The July observation window is SUPERSEDED evidence, and is deliberately not quoted here as a
  count.** `/api/parent/wards` was introduced in `224cddf0`, **2026-08-03**. The census window closes
  **before that route existed**, so its rows describe a system in which the old route was the only
  way for a parent to load their ward list. They are not weak evidence about today; they are **no
  evidence about today**, and the distinction is the reason the totals are omitted rather than
  hedged. A number carried into a ticket acquires authority from being written down, and this one
  would be quoted back in a month as the size of a population nobody has measured since the move.
  Whatever those rows count, they count it about a system that no longer exists.

**This is recorded as UNVERIFIED, and the query that settles it is:**

> filter `authz_observations` on `controller_action = 'GuardianController@students'`, **restricted to
> rows dated on or after 2026-08-03** — the date `224cddf0` introduced `/api/parent/wards`. Anything
> before that date is superseded and must be excluded from the count, not merely noted alongside it.
> Rows in that window are parents still reaching the old route — a stale SPA bundle, a bookmark, a
> non-browser client. An empty result means the parent population has already left.

**This does not weaken the ticket, and the disposition does not change.** `teacher` and `principal`
break on the flip regardless of which route parents use, and the `registrar` disjointness holds
either way. What it changes is the **headline population**: until that filter runs, the number of
affected parents is **unmeasured**, and this ticket states no figure for it. The defect is that an
ability check asserts something its route's admitted seats cannot satisfy; how many parents sit
behind it is a separate measurement that has not been taken.

## The route's real authorization is already complete without it

- **Authority** — `permission:student_status.view` on the group (`routes/api.php:428`), real
  middleware, enforced now and unaffected by `authz.enforce`.
- **Identity** — `->middleware('guardian_self')` →
  `App\Http\Middleware\EnsureGuardianOwnsGuardianRecord`, which compares the bound `Guardian`'s
  primary key against `GuardianService::forUserInActiveSchool($user)`. Unconditional, messaged, and
  audited as `student_record_access_refused`.

The `Authz::abilityCheck` line is a leftover from the S5 restore sweep, which restored the dormant
guards as live code before the per-route abilities were re-derived (ADR 0043/0044). Here it restored
the wrong ability.

## Fix — REMOVE it. Stated as a choice, with the alternative and why it loses

**Remove the line.** Nothing is left unguarded: the group gate is enforced middleware and the
identity binding is enforced middleware. No ability check is missing; the wrong one is present.

The alternative considered and rejected: **keep `guardian.view` but apply it only to the staff arm**
— guardians pass on identity, non-guardians must hold `guardian.view`. That is coherent, and it is a
real narrowing rather than a fix: it would cut the staff arm from
`{admin, head_of_school, teacher, principal}` to `{admin, head_of_school}`. It may even be the right
answer, but it is a **grant decision about who may read an arbitrary parent's ward list**, and it must
not ride along inside a bug fix. Do the removal; raise the narrowing on its own if it is wanted.

**Do not narrow the group gate.** `student_status.view` is wide on purpose — the route serves two
populations, a guardian fetching themselves and staff fetching anyone in their school. Regating on
`guardian.view` locks out every parent, which is this ticket restated as its own cause.

**Open question worth recording, not resolving here:** today `teacher` and `principal` can read any
guardian's ward list within their school — group gate passed, `isActingAsGuardian` false, so the
identity binding stands aside. Whether that is intended is a live question. It is not answered by
leaving a broken check in front of it, because that check protects nothing while `authz.enforce` is
off and breaks parents the moment it is on.

## Arms for the fix

- A guardian fetching their **own** record still succeeds — the known negative, and the arm standing
  between this change and any parent still on this route. `GuardianBulkRecordAccessTest:238` already
  covers it and must stay green. It is the required arm whether or not the observation filter finds
  parents still there: the route admits the `guardian` role today, so the seat must keep working.
- A guardian fetching **another** guardian's record is still refused — `:244` — so removing the
  ability check is proven not to have removed the protection. That distinction is the whole point:
  the check being deleted is not what refuses.
- Both arms run with `authz.enforce` **on**, which is the only setting under which this defect is
  observable at all. An arm that runs only in observe mode cannot see the thing being fixed.

## Precondition for the `AUTHZ_ENFORCE` flip

**Every row in `authz_observations` is a 403 the flip will make real.** The flip is blocked until
that table is empty, or until every remaining row is a denial we have read and intend to enforce.
The production census is not a supporting check — **it is the flip's blast radius**.

This route is one row-source in that table. Clearing it is necessary for the flip and nowhere near
sufficient.

## Related

[`guardian-binding-applicability-is-ungated.md`](guardian-binding-applicability-is-ungated.md)
— the identity half of this route's authorization, and the reason removing the ability check is safe
rests on that binding actually applying to the caller.
