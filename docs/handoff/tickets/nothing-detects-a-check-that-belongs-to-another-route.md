# Nothing detects an `Authz` check that belongs to another route

**Raised:** 2026-09-02 · **From:** two independent census findings with the same shape · **Severity:** ticket

## What was found twice

| Route group gate | Call site | Ability asserted | Disjointness |
| --- | --- | --- | --- |
| `permission:student_status.view` (`routes/api.php:428`) | `GuardianController@students` | `guardian.view` | admitted-not-holding: `guardian`, `teacher`, `principal` · holding-not-admitted: `registrar` |
| `permission:student.view` (`routes/api.php:443`) | `StudentSubjectController@index` | `student_subject.view` | admitted-not-holding: `principal`, `form_teacher` · holding-not-admitted: `teacher` |
| the same group | `StudentSubjectController@history` | `student_subject.view_history` | admitted-not-holding: `principal`, `form_teacher` · holding-not-admitted: `teacher` |

Both come from the S5 restore sweep, which restored ~47 dormant guards as live code (ADR 0043)
before the per-route abilities had been re-derived. **Two independent instances is a pattern, not a
coincidence**, and the sweep touched far more call sites than the census happened to observe.

**The tell, and it must be stated precisely:** an ability whose holder set is disjoint from the
route's admitted set **in both directions** is not a narrowed gate — it is a check belonging to a
different route. Nobody admitted can satisfy it, and everybody who can satisfy it is turned away at
the door. A gate that is merely *narrower* is a legitimate design; a gate that is **disjoint both
ways** cannot be anything but misplaced.

## Why the census cannot find the rest

The census surfaces a disjoint check **only if that route received traffic from a non-holder in the
observation window**. The window is eleven days of July; August produced no observations at all
(term end, a 215× traffic collapse measured against `activity_log`). A disjoint check on a quiet
route — a seasonal admin screen, an export, anything used monthly — is invisible to it, and stays
invisible until the flip turns it into a 403 in front of a real person.

**But the comparison needs no traffic.** All three inputs are in source:

1. the ability asserted at the call site — `Authz::abilityCheck(…, '<ability>', '<Controller@action>')`;
2. the permission admitting the route group — the `permission:` middleware on the group carrying
   that action;
3. both holder sets — `RbacSeeder::grantsMap()`.

## READ FIRST: `bin/ci-authz-lint.php` does NOT already do part of this

This was checked before proposing anything, and it corrects the premise the ticket was raised under.

That lint walks `app/`, and for each **line** tests two regexes — `$authz` and `$authzBareAbort` —
that match a **commented-out** guard call (`// abort_unless(`, `// ->can(`, `// Gate::…`). Findings
are keyed `file \t text \t #ordinal` and diffed against `authz-lint-baseline.txt`, with a shrink-lock
so a shrinking baseline also fails.

It therefore:

- looks only at **comments**, never at live `Authz::` call sites;
- never opens `routes/`, so it has no notion of a route, a group, or a middleware stack;
- never reads `grantsMap()`, so it has no notion of a holder set.

**So this would be a NEW lint, not an extension.** The two share a directory walk and nothing else,
and there is no existing parsing here to build on. Worth saying plainly, because "extend the authz
lint" sounds cheap and would mean writing the whole comparison anyway inside a file whose current
job is unrelated — which would also put a route-and-grants analysis behind a name that says
"commented-out checks".

## Shape — two options, and the route half is where the risk is

Every `bin/*.php` lint in this repository is a **standalone source parser**; none boots Laravel.
`bin/quality` runs them directly (`authz-lint` at `:212`, `boundary-lint` at `:215`) and also runs
`pest --group=arch` (`:283`), so an arch test is a wired gate too.

**Option A — a `bin/ci-*.php` source parser.** Parse `routes/*.php` for group middleware and
`[Controller::class, 'action']` bindings, parse controllers for the `Authz::abilityCheck` triple,
parse `grantsMap()`. Matches house style; no boot; runs in milliseconds. **Its risk is the route
half:** route files use nested groups, `require` of `routes/endpoints/*.php`, route-level
`->middleware(...)`, and `withoutScopedBindings()`. A parser that silently fails to associate an
action with its group reports *no finding* for that action — the silent-zero this repository has
already paid for.

**Option B — a Pest arch test.** Take the route half from `Route::getRoutes()` and
`$route->gatherMiddleware()`, which is **exact** — the same source `RbacDeriveRouteBaseline::snapshot()`
and `RouteAccessMap::derive()` already use, and it resolves nesting, requires and route-level
middleware for free. Take the grants half from `grantsMap()` **as source**, not from the database.

**Recommend Option B for the route half.** The parsing risk is entirely in associating actions with
middleware, and the framework already answers that question exactly. Note the deliberate hybrid:
`RouteAccessMap::holders()` resolves holders from the **database** (`Role::with('permissions')`), and
this check must NOT use it — see the bound below.

**Resolve `grantsMap()` with its fragments expanded.** A role acquires a permission through
`...$fragment` far more often than on its own line: `admin` opens with six consecutive spreads before
its first literal. A holder set built by grepping one permission at a time is wrong, quietly, in the
direction of under-reporting holders — which manufactures false disjointness.

## What must NOT fail the lint

**Partial overlap is a grant question, not a defect.** A gate admitting five seats where the ability
is held by three is an ordinary narrowing and is often the intent. Flagging it would drown the signal
and turn the lint into something people learn to baseline past.

**Fail only on disjointness in both directions**, and report both sides in the message — the seats
admitted but not holding, and the seats holding but not admitted. The second list is what identifies
the route the check actually belongs to.

Cases to decide explicitly rather than discover:

- `super_admin` holds almost nothing by grant and passes by `Gate::before`. It must be excluded from
  both sets, or every check looks disjoint.
- Approval abilities are excluded from that bypass (`ApprovalAbility::isExcludedFromSuperAdminBypass`),
  so the exclusion is not a blanket one.
- A route with **no** `permission:` group (plain `auth:sanctum`) has an admitted set of every role;
  disjointness is then impossible and the case should be **excluded with a stated reason**, not
  silently skipped.
- `Authz::ensure` sites are ownership and business-rule guards, not abilities; they have no holder set
  and are **out of scope**. Say so, rather than letting them fall into an unrecognised bucket.

## Coverage — report three numbers, not two

Call sites **examined**, call sites **excluded with a stated reason** (no `permission:` group,
`Authz::ensure`, super-admin-only), and call sites **unrecognised** — a `Authz::abilityCheck` whose
ability is not a literal, or whose action string cannot be matched to a route. **Assert the third is
zero.** An unresolvable call site must red, not vanish into a skipped count: the SIGNAL-length lint
read 61 of 117 messages and reported clean, and the aggregate is what hid it.

**Bite-proof it by planting a disjoint check** — a real one, at a real call site, whose ability no
admitted seat holds — and watch it red; then a partial-overlap check, and watch it stay green. The
second arm is the one that proves the lint is not simply flagging every narrowing.

## THE HONEST BOUND

**This reads the map and the source, not the live database. It catches design-time disjointness and
not grant drift on the production copy.** Those are different defects with different instruments:

- **Design-time disjointness** — the map and the routes disagree. Source is the whole witness, and a
  lint over the diff or the tree is the right instrument.
- **Grant drift** — the map and the production `role_has_permissions` rows disagree, because
  `RbacSeeder::sync()` is non-destructive and a pre-existing permission added to an existing role
  grants nothing on an environment that has already been seeded. Source **cannot** see that; its
  instruments are `php artisan rbac:diff-grants` for the past and
  `bin/ci-grants-convergence-lint.php` for the future.

**A green from this lint says the design is coherent. It says nothing about what the production copy
actually grants** — and a check that is fine in the map can still be disjoint in production if the
grant never landed. State that in the lint's own header, the way the convergence lint states its
non-retroactivity, so a future reader cannot draw the wider conclusion from the narrower green.

## Related

- [`guardian-view-asserted-on-a-route-its-users-cannot-satisfy.md`](guardian-view-asserted-on-a-route-its-users-cannot-satisfy.md) — instance 1.
- [`student-subject-checks-assert-abilities-the-admitted-seats-lack.md`](student-subject-checks-assert-abilities-the-admitted-seats-lack.md) — instances 2 and 3.

Both are dispositioned individually. **This ticket is the mechanism that finds the ones nobody has
walked into yet**, and it should land before the flip rather than after: after the flip, the
detection channel is a 403 in front of a user.
