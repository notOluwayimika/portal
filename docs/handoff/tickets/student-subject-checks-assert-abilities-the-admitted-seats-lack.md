# `student_subject` checks assert abilities the admitted seats lack

**Raised:** 2026-09-02 · **From:** the `authz_observations` census · **Severity:** ticket (blocks the `AUTHZ_ENFORCE` flip)

## What

Two actions under the same route group assert abilities that the group's admitted seats do not hold.

`routes/api.php:443` — the group:

```php
Route::middleware(['auth:sanctum', 'tenant', 'permission:student.view'])->group(function () {
```

The two routes (`:447` and `:449`), both `->withoutScopedBindings()`:

- `students/{student:uuid}/enrollments/{studentCurriculum:uuid}/subjects` → `StudentSubjectController@index`
- `students/{student:uuid}/enrollments/{studentCurriculum:uuid}/subjects/history` → `StudentSubjectController@history`

What each asserts:

- `StudentSubjectController.php:31` — `Authz::abilityCheck(…, 'student_subject.view', 'StudentSubjectController@index')`
- `StudentSubjectController.php:183` — `Authz::abilityCheck(…, 'student_subject.view_history', 'StudentSubjectController@history')`

Both are observe mode, so `Authz::gate` records and continues today (`app/Support/Authz.php:40-51`).
On the flip both become `abort(403)`.

## The seats, re-derived from the seeder for this ticket

Derived by resolving `RbacSeeder::grantsMap()` with its shared fragments expanded — `$studentSubjectFull`
and the rest — across all **15** roles in the map (`accounts_officer`, `accounts_supervisor`, `admin`,
`boarding_parent`, `executive_director`, `finance_lead`, `form_teacher`, `guardian`, `head_of_school`,
`internal_auditor`, `key_stage_coordinator`, `principal`, `registrar`, `super_admin`, `teacher`).
Enumerating the whole map matters: a holder set read by grepping one permission at a time misses a
role that acquires it through a spread.

| Ability | Holders |
| --- | --- |
| `student.view` — the group gate | `admin`, `form_teacher`, `head_of_school`, `principal` |
| `student_subject.view` — `@index` asserts | `admin`, `head_of_school`, `teacher` |
| `student_subject.view_history` — `@history` asserts | `admin`, `head_of_school` |

`super_admin` holds none of the three by grant — `self::SUPER_ADMIN_PLATFORM` carries `rbac.*` and two
`activity_log.*` entries only — and reaches all three by `Gate::before`.

## Both-directions disjointness — the same tell as `guardian.view`

**On the flip:**

- `principal` and `form_teacher` pass the group and hold **neither** ability. Both 403 on `@index`;
  both 403 on `@history`.
- `form_teacher` is the sharper case of the two: it is a **teaching** seat that cannot read a pupil's
  subject list, while `teacher` — which holds `student_subject.view` — cannot reach the route at all,
  because it holds no `student.view`.

**And in the other direction:** `teacher` holds the asserted ability and is not admitted by the
group. An ability whose holder set is disjoint from the admitted set in *both* directions is not a
narrowed gate — **it is a check belonging to a different route.** That is the same tell recorded in
[`guardian-view-asserted-on-a-route-its-users-cannot-satisfy.md`](guardian-view-asserted-on-a-route-its-users-cannot-satisfy.md),
and it is now the second instance, which makes it a pattern in the S5 restore sweep rather than a
one-off: the sweep restored the dormant guards as live code before the per-route abilities were
re-derived, so a guard can be live, correct-looking, and about a different route.

The census recorded both classes as real traffic — `student_subject.view` on `@index` and
`student_subject.view_history` on `@history` — so these are not theoretical seats. Totals are in the
census, not repeated here; see the retention note below for why they may not be re-derivable later.

## Disposition — name it, and keep the grant decision out

Two coherent options. **Neither is "narrow the group gate":** `student.view` is what admits the
oversight seats to student records generally, and regating it on `student_subject.*` would cut
`principal` and `form_teacher` out of the student area to fix two endpoints.

**Option A — REMOVE both ability checks.** The group gate is enforced middleware and already
expresses the authority ("may read student records in this school"). Removing the two checks changes
the effective set to exactly the group's: `admin`, `form_teacher`, `head_of_school`, `principal`,
plus `super_admin`.

- **Adds** (relative to today's *intended* behaviour on flip): `principal`, `form_teacher` keep
  working rather than 403.
- **Cuts:** nobody. `teacher` still cannot reach the route, because that is the group's doing and
  this option does not touch the group.

**Option B — REPLACE the group gate for these two routes with `student_subject.view` /
`student_subject.view_history` at route level**, intersecting with the group as
`fix/three-authorisation-holes` did for the activity-log export.

- **Adds:** nobody — an intersection can only narrow.
- **Cuts:** `principal` and `form_teacher` from `@index`; `principal`, `form_teacher` **and** the
  whole teaching side from `@history`, leaving `admin` and `head_of_school`.

**Recommendation: Option A**, on the same reasoning as the `guardian.view` ticket. Option B is
coherent, but *"should an oversight seat read a pupil's subject list and its history?"* is a **grant
decision about scope of access**, and it must not ride along inside a fix for a check that asserts
the wrong ability. Do Option A; raise the narrowing separately if it is wanted.

**Open question, recorded not resolved:** if `teacher` is *supposed* to read a pupil's subject list —
and it holds `student_subject.view`, which suggests somebody thought so — then the defect is that the
group excludes it, and the fix is a grant or a regating decision. This ticket does not answer that,
and leaving a broken check in front of it answers nothing either: the check protects nothing while
the flag is off and breaks two oversight seats the moment it is on.

## Arms for the fix

- `principal` and `form_teacher` reach `@index` and `@history` — the known negatives, and the arms
  standing between this change and two broken oversight seats.
- `teacher` is still refused, **at the group**, so the refusal's mechanism is named rather than
  merely observed. Building this arm on a seat that would also fail the deleted check would make it
  pass for the wrong reason.
- Both arms run with `authz.enforce` **on**. Under observe mode the defect is invisible, so an arm
  that runs only in observe mode cannot see what is being fixed.

## Classification follows disposition, not the other way round

`docs/runbooks/authz-observation-classifications.json` is the §24 condition-3 artifact and its
`classes` array is **empty** — none of the four observed classes is classified.

**These two classes cannot be classified until this ticket is dispositioned.** The artifact's
`_readme` defines exactly two values: `expected` ("the denial is correct — the caller genuinely lacks
the ability") and `regression` ("legitimate access enforcement would break"). Under Option A the
check is **removed**, so on the flip the class produces no denial at all: it is neither correct nor
broken. It is **obsolete**, and the artifact has no state for that — see the schema note in
[`observe-mode-has-no-liveness-signal.md`](observe-mode-has-no-liveness-signal.md), which carries the
full reading of what the gate does and does not enforce.

**Retention — classify before anything prunes.** The census rows span 2026-07-21 to 2026-07-31; the
newest is **33 days old**. `authz:prune --older-than=30` (the documented default, and the retention
`post-deploy-tasks.md` states) cuts at 2026-08-03 and would delete **every one of them**. August
produced **no observations at all** to replace them. So the classification must be written before any
prune runs, or the evidence for this ticket is gone and cannot be re-derived from the table.

## Precondition for the `AUTHZ_ENFORCE` flip

**Every row in `authz_observations` is a 403 the flip will make real.** The flip is blocked until
that table is empty, or until every remaining row is a denial we have read and intend to enforce.
The production census is not a supporting check — **it is the flip's blast radius**.

These two classes are row-sources in that table, and `post-deploy-tasks.md:300-326` puts them in
Track A: classify (condition 3), enforce in staging (A4), then in production with a live 403 probe
(A5).
