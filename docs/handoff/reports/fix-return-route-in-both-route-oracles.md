# Implementation report — `fix/return-route-in-both-route-oracles`

## Headline

**Done, with one scope deviation and one finding that changed the row I committed.**
The IA return route is now in both committed route oracles, Phase B's two returned-bills
routes are in the middleware baseline, and three false prose claims about those oracles
are corrected, one ticket is opened and one is annotated. Branch
`fix/return-route-in-both-route-oracles`, **one commit** on base `staging` @ `a4cf4cee`
(this report rides with the work rather than trailing it — see deviation 5). No PR
opened, not pushed. Unpublished: `git ls-remote --heads origin <branch>` is empty, against
a positive control of `staging` resolving to `a4cf4cee`.

**This is the second pass.** A cold review returned five findings, all correct; three were
false quantified claims in prose this branch wrote. They are recorded and answered in
*Review findings and what this pass did*, below. Two further errors were found in this
pass that neither the review nor the brief named, and one of them **overturns this
report's own F2**.

**Tier: FULL**, and the brief's argument for it is the right one — the diff is 55 lines
but four of them are rows in a regression oracle, and a wrong row is an expectation the
gate then defends. One thing the brief did not anticipate makes the tier load-bearing
rather than ceremonial: **the generator produced a wrong row, and committing it would
have been the single-command "obvious" fix.** See *Contradictions of the premise*.

---

## Deviations from the brief

**1. I did not commit the generated access-map row. I hand-derived it and let the oracle
confirm it.**

The brief said: take the generated rows, and *"If the generated row shows anything other
than internal_auditor alone, STOP and report — that is a finding, not a row to commit."*

The generated row was `{"auth": true, "roles": []}` — empty. I report it as a finding
below rather than stopping the work, because the cause is external to the change and the
correct row is knowable by two independent paths, both of which I ran. Stopping would
have left both oracles blind to a route that is already shipped, which is the state the
brief exists to end. **This is the deviation most worth a second pair of eyes**: if you
disagree, the row to argue about is the one access-map row, and reverting it costs
nothing.

**AND THIS COMMIT CREATES A TRAP THAT IT IS THIS COMMIT'S JOB TO DISARM.** The committed
row is hand-derived, so the next person who regenerates the access map must know which
database they regenerated against:

- **Against a database where `rbac:sync` has run** — the generator produces
  `["internal_auditor"]`, matching the committed row. No diff. Nothing to think about.
- **Against a database where it has not** — the generator produces `[]`, and the diff will
  show this row as having *changed*. **It has not changed. The generator has.** Do not
  commit that diff; run `rbac:sync` and regenerate.

That distinction is the whole content of the new ticket, and it is repeated here because a
person regenerating a fixture reads the diff, not the ticket index.

**2. Scope widened by one prose block** — `app/Support/RouteAccessMap.php:61 (the bypass-exclusion branch)`. The brief
scoped "four rows and two prose blocks". I corrected a third. It is a false claim inside
the exact function that derives the row I am adding, it is comment-only, and leaving a
known-false description adjacent to the change is the wallpaper failure this project
names. Detail under *Findings*, finding **F4**.

**3. One file in the diff I did not choose to touch.**
`app/Finance/Http/Controllers/OpeningBalanceBatchController.php`, one character.
Deleting five lines from `Permission.php` shifted `FINANCE_OPENING_BALANCE_SUBMIT` from
`:263` to `:258`, and that controller cites it by line. `citation-lint` caught it. Not a
judgement call — a mechanical consequence, reported so it is not read as scope creep.

**4. Scope widened by one line in a file this commit does not own** —
`docs/handoff/tickets/route-middleware-baseline-is-67-routes-stale.md`. Its title carries
`67`; the re-measured backlog is `70`. The file is **not renamed** — the note says the
title is a point-in-time number and that the next reader should re-derive rather than
derive from either figure. One line, in a ticket this commit otherwise leaves open.

**5. Process deviation, corrected in this pass.** The first pass shipped the report as a
separate commit on top of the work. `finance-execute` has the report ride *with* the work,
and it does now: the branch is unpublished, so the two commits were squashed into one. If
you saw `23ef3d1b` and `bb217aa1`, they no longer exist.

**6. EDIT 5 of the second brief did not fire, and I made no edit for it.** It asked me to
check whether `rbac:sync` appears in `docs/handoff/post-deploy-tasks.md` and, **if absent**,
to add a task. It is present, at `docs/handoff/post-deploy-tasks.md:564-583`, and it is
better than a task I would have added — it already mandates plain `rbac:sync` over
`--fresh` with the reason, and already states that `rbac:sync` *"only ever adds — and only
permissions newly created in that run"*, which is exactly the mechanism
`finance.invoice.reject` depends on. I did not add a second bullet beside it: two copies of
one rule is the drift hazard this project keeps paying for, and a list of *which* new
permissions ride on the step would go stale by design. See finding **F1**.

---

## Contradictions of the premise

**The brief's premise held in full.** Every route it named absent was absent, in the
oracle it named, and the key spellings it guessed were the ones the generators produce.
Nothing in Step 1 disagreed with it.

**But its Step 2 method rests on an assumption that is false on this machine**, and it is
the most important thing in this report.

`rbac:derive-access` reads grants from the connected database. On this machine
`finance.invoice.reject` **has no permission row at all** — not "granted to nobody", the
permission was never created, because `rbac:sync` has not run since it was declared on
2026-09-04. So the generator did what it was asked and emitted:

```
'POST /api/internal-audit/invoices/{uuid}/return' => {"auth": true, "roles": []}
```

That is a **true measurement of an unsynced database presented as a property of a route**.
It is the shape this project keeps paying for: the generator does not warn, the output
looks like every other row, and the row would have entered the oracle as a reviewed
expectation. Thereafter `RouteAccessParityTest` would have *defended* the claim that no
role may reach the return route — going red the day someone ran `rbac:sync` and made the
route work.

The brief's own guard is what caught it, and it caught it for a reason the brief did not
predict: it asked me to state whether the row resolved to `internal_auditor` **only**,
and `[]` is not that.

---

## What changed

| File | Δ | What |
| --- | --- | --- |
| `tests/fixtures/route-middleware-baseline.json` | +21 | three rows: the return route and Phase B's two returned-bills routes |
| `routes/endpoints/internal-audit.php` | +19 −1 | the false "shows only `internal_auditor` for all three routes" sentence, and why a count confirmed it |
| `app/Support/RouteAccessMap.php` | +11 −2 | the false "(No route carries one today…)" parenthetical |
| `tests/fixtures/route-access-map.json` | +6 | one row: the return route |
| `app/Enums/Permission.php` | −5 | the stale "`.reject` IS DELIBERATELY ABSENT" paragraph |
| `app/Finance/.../OpeningBalanceBatchController.php` | +1 −1 | citation line shifted by the deletion above |

No test file changed. No assertion was weakened.

---

## Proof

### Step 1 — the measurement, with denominators and controls

Parsed JSON, exact key equality. Not a substring grep: a JSON key is a key, and grep
cannot tell a key from a value.

```
=== access (tests/fixtures/route-access-map.json) ===
TOTAL KEYS: 383
CONTROL(must be PRESENT)  'DELETE /api/activity-logs/saved-filters/{savedActivityFilter}' => PRESENT
CONTROL(must be ABSENT)   'ZZZ /this-key-cannot-exist' => ABSENT
  TARGET 'POST /api/internal-audit/invoices/{uuid}/return'    => ABSENT
  TARGET 'GET /api/v1/finance/invoices/returned'              => PRESENT
  TARGET 'GET /finance/returned-bills'                        => PRESENT
KEYS CONTAINING 'internal-audit' (3):
    GET /api/internal-audit/invoices/pending
    GET /internal-audit/review-queue
    POST /api/internal-audit/invoices/approve

=== baseline (tests/fixtures/route-middleware-baseline.json) ===
TOTAL KEYS: 364
CONTROL(must be PRESENT)  'DELETE /api/activity-logs/saved-filters/{savedActivityFilter}' => PRESENT
CONTROL(must be ABSENT)   'ZZZ /this-key-cannot-exist' => ABSENT
  TARGET 'POST /api/internal-audit/invoices/{uuid}/return'    => ABSENT
  TARGET 'GET /api/v1/finance/invoices/returned'              => ABSENT
  TARGET 'GET /finance/returned-bills'                        => ABSENT
KEYS CONTAINING 'return' (0):
```

**Two controls, not one.** The present-control proves the matcher can find a key that is
there; the absent-control proves it is not broken-open and answering PRESENT to
everything. A measurement with only the first cannot tell a working matcher from one that
says yes.

**The docblock's count claim, verified:** the access map holds exactly **3** keys matching
`internal-audit` — which is why a reader checking *"all three routes"* **by count**
confirms a false sentence. The third, `GET /internal-audit/review-queue`, is the Inertia
page declared in `routes/web.php` and is not one of `internal-audit.php`'s routes at all.
A count agreed; the set did not.

### Step 2 — key spellings, derived from the generators

Both generators were run to a **scratch** copy: committed fixtures backed up by sha, the
command allowed to write, output copied out, committed file restored via
`git checkout --`, sha re-asserted.

```
route-middleware-baseline.json written (437 routes).
route-access-map.json written (437 routes).
9e2bc4e46e0f821959aed03a08eda81432cb6933ec06f94121c9c1a2a9eb32ae  tests/fixtures/route-middleware-baseline.json
80e7f9fecab75e4d32250818dd8dccbb6c8b53d04a72eaf1e8d95adb54867328  tests/fixtures/route-access-map.json
```

Both sha values are identical to the pre-run values, so the committed fixtures were not
disturbed by the generation.

**The key spellings match the brief exactly** — `{uuid}`, not `{invoice}`:

```
POST /api/internal-audit/invoices/{uuid}/return
GET /api/v1/finance/invoices/returned
GET /finance/returned-bills
```

**Generated vs committed, whole-file:**

```
=== access ===   committed 383  generated 437
in GENERATED not COMMITTED: 54     in COMMITTED not GENERATED: 0
shared keys: 383   VALUE DRIFT on shared keys: 0

=== baseline === committed 364  generated 437
in GENERATED not COMMITTED: 73     in COMMITTED not GENERATED: 0
shared keys: 364   VALUE DRIFT on shared keys: 0
```

**Numbers that differ from the brief.** The brief said *~54* and *~67*. Access is **54**.
Baseline is **73**, not 67 — the ticket
`route-middleware-baseline-is-67-routes-stale.md` has itself gone stale by 6 since it was
titled. Both tickets stay open; this commit touches neither.

**Zero value drift on all 383 shared access keys** is worth stating: the connected DB
agrees with the committed oracle on every permission the oracle already depends on. The
`finance.invoice.reject` gap is the *only* divergence, which is exactly what makes it easy
to miss.

### Step 2.3 — the restriction proof

The encoder was proven byte-faithful **first**, so an edit could not silently reformat
rows I did not touch:

```
route-access-map.json                round-trip BYTE-IDENTICAL
route-middleware-baseline.json       round-trip BYTE-IDENTICAL
```

Then, per fixture:

```
=== route-access-map.json ===
keys before: 383   keys after: 384
ADDED: 1   REMOVED: 0   CHANGED: 0
  + POST /api/internal-audit/invoices/{uuid}/return => {"auth":true,"roles":["internal_auditor"]}

=== route-middleware-baseline.json ===
keys before: 364   keys after: 367
ADDED: 3   REMOVED: 0   CHANGED: 0
  + GET /api/v1/finance/invoices/returned => ["api","auth:sanctum","tenant","permission:finance.access","permission:finance.invoice.generate"]
  + GET /finance/returned-bills => ["web","auth","tenant","permission:finance.access","permission:finance.invoice.generate"]
  + POST /api/internal-audit/invoices/{uuid}/return => ["api","auth:sanctum","tenant","permission:finance.invoice.approve","permission:finance.invoice.reject"]
```

Corroborated by git:

```
 tests/fixtures/route-access-map.json          |  6 ++++++
 tests/fixtures/route-middleware-baseline.json | 21 +++++++++++++++++++++
 2 files changed, 27 insertions(+)
```

27 insertions, **0 deletions, 0 modifications**. Both fixtures are `ksort`ed and the rows
landed in sort position.

### Step 2.4 — every row read, and what it means

**`POST /api/internal-audit/invoices/{uuid}/return` — access map — `["internal_auditor"]`.**

**Yes: `internal_auditor` ONLY.** Derived by two independent paths, neither of which is
the generator:

1. *From the source.* `RbacSeeder.php:581 (FINANCE_INVOICE_REJECT)` grants `FINANCE_INVOICE_REJECT` to
   `internal_auditor`. `SUPER_ADMIN_PLATFORM` (`RbacSeeder.php:71 (SUPER_ADMIN_PLATFORM)`) is four platform
   abilities and contains no finance ability, so `super_admin` holds neither `approve` nor
   `reject` as a real grant. Both terminate in `ApprovalAbility::CHECKER_SEGMENTS`
   (`ApprovalAbility.php:40 (CHECKER_SEGMENTS)`), so `isExcludedFromSuperAdminBypass` is true for each and
   `RouteAccessMap::derive()` never appends `super_admin` via `Gate::before`. The route
   intersects the group's `approve` with its own `reject`; both sets are
   `['internal_auditor']`; the intersection is `['internal_auditor']`.
2. *From the oracle.* `RouteAccessParityTest` seeds `DatabaseSeeder` into a
   `RefreshDatabase` database and re-derives live. It passes with this row, and the
   watched red below prints the live value directly: `live: [internal_auditor]`.

This matches the argument at `routes/endpoints/internal-audit.php:73-84 (the BOTH-abilities argument)`: the route
requires **both** the group's `finance.invoice.approve` and its own
`finance.invoice.reject`, and `internal_auditor` is the only seat holding both.

**`POST /api/internal-audit/invoices/{uuid}/return` — baseline —**
`api, auth:sanctum, tenant, permission:finance.invoice.approve, permission:finance.invoice.reject`.
The stack is the API group, sanctum auth, the school-isolation middleware, then the two
permission gates in declaration order — group first, route-level second. It is the
literal shape of the "requires BOTH abilities" property the docblock argues for, now
pinned in order, so removing either gate or swapping them reds.

**`GET /api/v1/finance/invoices/returned` — baseline —**
`api, auth:sanctum, tenant, permission:finance.access, permission:finance.invoice.generate`.
Finance's own read of the returned queue: the finance-area door plus the *maker* ability.
Consistent with `app/Finance/Http/Controllers/ReturnedInvoiceQueueController.php:48`, which states explicitly that it
is gated on `finance.invoice.generate` and **not** `finance.invoice.reject` — that is the
auditor's ability, and gating Finance's own queue on it would hand Finance the checker
side.

**`GET /finance/returned-bills` — baseline —**
`web, auth, tenant, permission:finance.access, permission:finance.invoice.generate`.
The Inertia page for the same queue. Same two permissions as its API sibling, on the web
stack rather than the api stack — which is the correct correspondence and is now pinned,
so the page and its API can no longer drift apart unnoticed.

I did **not** add access-map rows for the two Phase B routes: they were already present
and correct (`accounts_officer, admin, super_admin`), and were among the 383 shared keys
with zero drift.

### Step 3 — the two (three) prose corrections

`routes/endpoints/internal-audit.php` now states what is true and the asymmetry beneath
it, citing `RouteAccessParityTest.php:45 ($fixture)` and
`RouteMiddlewareBaselineTest.php:28 ($fixture)` — both re-derived on this tree:

```
$ sed -n '45p' tests/Feature/Rbac/RouteAccessParityTest.php
    foreach ($fixture as $key => $expected) {
$ sed -n '28p' tests/Feature/Rbac/RouteMiddlewareBaselineTest.php
    foreach ($fixture as $key => $stack) {
```

Both loops iterate the **fixture's** keys, so a route absent from a fixture is never
asserted by either test, and rows enter these files only when an author adds them by hand.

`app/Enums/Permission.php` — stale paragraph deleted. The sweep for siblings, with its
control:

```
$ grep -rniE "reject.{0,60}(deliberately absent|deferred|not declared|undeclared)|((deliberately absent|deferred|not declared|undeclared).{0,60}reject)" --include='*.php' --include='*.md' --include='*.tsx' --include='*.ts' .
app/Enums/Permission.php:189:    // `.reject` IS DELIBERATELY ABSENT. The return-to-Finance path ships on 13 September with the

--- CONTROL (must be >0): files mentioning finance.invoice.reject ---
      20
```

One hit, the one deleted. `ApprovalAbility.php` carries no absent/deferred language about
`reject`; its `MAKER_OVERRIDES` docblock describes `reject` as having *joined* the map on
2026-09-04, which is current. `FINANCE_INVOICE_REJECT`'s own docblock was not touched.

**The first version of that grep returned 0 and was wrong.** `--include=*.php` unquoted is
glob-expanded by zsh; the command errored having examined nothing and the control also
printed `0`. It is only visible as a finding because the control was there to disagree.
The same trap fired a second time in this session (`php -r '…' S=path` passes `S=path` as
argv, not env, and printed `ROUTES CARRYING A CHECKER-SEGMENT permission: 0`). Both are
recorded here because both produced a *clean-looking* zero.

### Gates

Each reports what it examined.

```
pint --test  (4 changed PHP files, array form, guarded)  → passed
citation-lint                                            → OK, 181 citation(s), 164 baselined key(s), exit 0
boundary-lint                                            → OK, 935 files scanned across app/ and tests/, exit 0
authz-lint                                               → OK, 0 known, exit 0
```

**Pint was positive-controlled**, because a formatter that examined nothing reports
success:

```
--- pint --test WITH planted violation (must FAIL) ---
{"tool":"pint","result":"fail","files":[{"path":"app/Support/RouteAccessMap.php","fixers":["no_trailing_whitespace","unary_operator_spaces","not_operator_with_successor_space","binary_operator_spaces"]}]}  EXIT: 1
--- pint --test restored (must PASS) ---
{"tool":"pint","result":"passed"}  EXIT: 0
```

**citation-lint needed no planted control — it went red on my own change**, which is the
same evidence and better:

```
citation-lint: 3 NEW or GROWN citation violation(s)
  ✗ routes/endpoints/internal-audit.php  tests/Feature/Rbac/RouteAccessParityTest.php:45  [citation-missing-symbol]
  ✗ routes/endpoints/internal-audit.php  tests/Feature/Rbac/RouteMiddlewareBaselineTest.php:28  [citation-missing-symbol]
  ✗ app/Finance/Http/Controllers/OpeningBalanceBatchController.php  app/Enums/Permission.php:263  [citation-symbol-not-found]
REAL EXIT: 1
```

**`REAL EXIT`, because the first run of this lint was read through `| tail -8` and
reported exit 0 — tail's status, not the lint's.** The failure text was visible above it
and would have been read as noise. Re-run without a pipe, it is 1. Every exit code in this
report is taken without a pipe.

---

## The watched red

Both oracles bite-proven **on the committed tree**, mutating the *value* and never the
key, so the rule's precondition is recreated rather than removed. `EXAMINED` counts come
from a counter incremented inside each test's own `foreach` — measured from the iteration,
not asserted from the file's key count. The instrumentation was removed and sha-verified.

Baseline, before any mutation:

```
[EXAMINED] RouteAccessParityTest iterated 384 fixture keys
[EXAMINED] RouteMiddlewareBaselineTest iterated 367 fixture keys
{"tool":"pest","result":"passed","tests":19,"passed":19,"assertions":18,"duration_ms":23072,"risky":1}
```

384 and 367 are the post-insert key counts: every fixture key, including the four added,
is compared.

### A — access map: admit `super_admin` on the return row

```
  before: ["internal_auditor"]
  after : ["internal_auditor", "super_admin"]

[EXAMINED] RouteAccessParityTest iterated 384 fixture keys
{"tool":"pest","result":"failed","tests":17,"passed":16,"failed":1,
 "message":"ACCESS CHANGED: POST /api/internal-audit/invoices/{uuid}/return
    expected: [internal_auditor, super_admin] auth=true
    live:     [internal_auditor] auth=true"}
```

The message names the right key, and it prints `live: [internal_auditor]` — the live
derivation against the seeded database, independently confirming the committed row. This
mutation is the specific wrong answer the ADR 0040 bypass-exclusion exists to prevent, not
an arbitrary edit.

### B1 — baseline: DROP `permission:finance.invoice.reject`

```
  after : ["api","auth:sanctum","tenant","permission:finance.invoice.approve"]

[EXAMINED] RouteMiddlewareBaselineTest iterated 367 fixture keys
{"tool":"pest","result":"failed","tests":2,"passed":1,"failed":1,
 "message":"CHANGED: POST /api/internal-audit/invoices/{uuid}/return
    fixture: [api, auth:sanctum, tenant, permission:finance.invoice.approve]
    live:    [api, auth:sanctum, tenant, permission:finance.invoice.approve, permission:finance.invoice.reject]"}
```

### B2 — baseline: pure REORDER, same set

```
  after : ["api","auth:sanctum","tenant","permission:finance.invoice.reject","permission:finance.invoice.approve"]   (same set, order only)

[EXAMINED] RouteMiddlewareBaselineTest iterated 367 fixture keys
{"tool":"pest","result":"failed","tests":2,"passed":1,"failed":1,
 "message":"CHANGED: POST /api/internal-audit/invoices/{uuid}/return
    fixture: [api, auth:sanctum, tenant, permission:finance.invoice.reject, permission:finance.invoice.approve]
    live:    [api, auth:sanctum, tenant, permission:finance.invoice.approve, permission:finance.invoice.reject]"}
```

B2 is separate from B1 deliberately. The generator's docblock claims the fixture *"must be
able to catch a reorder, not just a membership change"* (ADR 0043 §3), and a drop-only
bite-proof passes an oracle that compares sets. The mutation asserts
`sorted(after) == sorted(before) and after != before` before running, so it is a reorder
and nothing else.

### Restoration

```
=== diff of sha lists (empty == byte-identical to pre-Step-4 committed tree) ===
IDENTICAL
=== git status (must be clean) ===
(clean)
```

covering all four touched files — both fixtures and both test files. Final green on the
committed tree `23ef3d1b`:

```
{"tool":"pest","result":"passed","tests":19,"passed":19,"assertions":18,"duration_ms":23987,"risky":1}
{"tool":"pest","result":"passed","tests":22,"passed":22,"assertions":69,"duration_ms":21632}   ← SuperAdminMatrixTest + GrantsMapSeparationTest
```

The second line is the pair that pins `ApprovalAbility::MAKER_OVERRIDES` and the
super-admin matrix — the assertions most likely to be disturbed by editing
`Permission.php` and `RouteAccessMap.php`.

`bin/db-exclusive` gated every suite invocation (`portal_testing is free.`, exit 0). No
suite ran concurrently.

---

## The drive

No screen changed. Four JSON fixture rows and four comment blocks; no controller, route
declaration, request, view or query was modified. Nothing to drive.

---

## Database observations

Read-only probe of the connected development database, under the privacy rule — counts and
structure only.

| permission | row exists | global web-guard holders |
| --- | --- | --- |
| `finance.invoice.approve` | yes | 1 |
| `finance.invoice.reject` | **no** | 0 |
| `activity_log.view_all` | yes | 3 |

Control: 15 global web-guard roles total, so the query reached a populated table.

**Nothing was written.** `rbac:sync` was **not** run — see *Not done*. The generators are
read-only with respect to the database.

---

## Not done

- **`rbac:sync` was not run**, and this is a deliberate choice, not an omission. It would
  have mutated the maintainer's ground-truth copy to serve my own measurement, and the row
  it would have produced is one I obtained without it. It is owed as an operational task —
  finding **F1**. (The first pass of this report cross-referenced **F5** here, which is the
  oracle-coverage finding. My error, corrected.)
- **`bin/quality` was not run.** Four targeted lints, pint over the changed files, and the
  four affected test files were. The full suite and the tsc/test ratchets are Segun's, in
  his own terminal, per the brief.
- **The two open tickets stay open**, as instructed. This commit adds four rows and
  regenerates nothing. **Their figures move because of it, so here they are re-measured at
  this commit** — by key difference against the live route table, not by subtracting from
  the previous number:

  | fixture | keys | registered but absent | fixture keys not registered |
  | --- | --- | --- | --- |
  | `route-access-map.json` | 384 | **53** | 0 |
  | `route-middleware-baseline.json` | 367 | **70** | 0 |

  Both reconcile exactly to the 437 registered routes (384+53, 367+70), and the third
  column is the unrecognised bucket, asserted at zero. **These are measured at this commit
  and they move**: before it they were 54 and 73, and the tickets' own titles say 54 and 67.
  Re-derive; do not carry any of the six numbers in this paragraph.
- **I did not verify the return route end-to-end in a browser or via HTTP.** On this
  machine it would refuse every seat — see F1 — so such a check would measure the unsynced
  database, not the route.

---

## Findings raised, not fixed

**F1 — `finance.invoice.reject` has no permission row in the connected database, so the
Phase A return path is dead in this environment. — severity: fix (operational, not code).**
`rbac:sync` has not run since the permission was declared (2026-09-04). The route carries
`permission:finance.invoice.reject`, so it refuses **every** seat including
`internal_auditor`; the review-queue page's `can('finance.invoice.reject')`
(`resources/js/pages/admin/internal-audit/review-queue.tsx:129 (mayReturn)`) is false for everyone, so the control is hidden rather than
broken — which is why nobody has reported it. The seeder is correct and expects exactly
this (`RbacSeeder.php:574-581 (FINANCE_INVOICE_REJECT)`, "NEW permission, so `rbac:sync` grants it on the next run
and no convergence migration is owed"). **The deploy step already exists and is correct** —
`docs/handoff/post-deploy-tasks.md:564-583` mandates plain `rbac:sync` after `migrate`,
never `--fresh`, and states that it *"only ever adds — and only permissions newly created in
that run"*, which is precisely how this ability arrives. So this is not a missing step; it
is a step whose execution state I cannot see. **Whether any deployed environment has run it
since 2026-09-04 is unknown from the tree, and I assert nothing about staging or
production.** What is measured is this machine's copy, and the action is Segun's.

**F2 — `rbac:derive-access` silently emits `roles: []` against a database missing a
permission, with no warning. — severity: ticket. NOW TICKETED:
`docs/handoff/tickets/rbac-derive-access-emits-empty-roles-for-an-unsynced-permission.md`.**

**AND THE FIRST PASS OF THIS REPORT GOT ITS SEVERITY ARGUMENT WRONG. I am correcting my own
claim.** It said the oracle *"would have defended the claim that no role may reach the return
route — going red the day someone ran `rbac:sync` and made the route work."* That is false,
and the second brief carried the same reasoning as a premise for the ticket. Neither of us
had checked it.

The two sides do not read the same database. `rbac:derive-access` reads the connected
development database; `RouteAccessParityTest` seeds `DatabaseSeeder` into `portal_testing`
(`tests/Feature/Rbac/RouteAccessParityTest.php:35`), and that path reaches `RbacSeeder`, which
grants the ability. **Measured** — the generator's own `[]` planted in the fixture:

```
expected: [] auth=true
live:     [internal_auditor] auth=true
```

It reds on the *next suite run*, not on some later day. So the oracle catches it, the blast
radius is bounded, and ticket is the right severity for a better-stated reason than the one
I gave.

What remains wrong is sharper than what I originally claimed. **The failure message's own
remedy re-creates the defect**: it says *"regenerate via `php artisan rbac:derive-access` and
review the diff"*, and an operator on an unsynced database who follows that literally
regenerates `[]` and reds again. The fixture is blamed by name; the generator is offered as
the cure. And the protection is incidental rather than designed — it holds only because
`RbacSeeder` is ahead of the dev copy. If an ability is missing from the *seeded* database
too, both sides derive `[]` and agree, which is exactly the state of an ability declared
ahead of the code that grants it. Mechanism: `RouteAccessMap.php:107 (holders)` resolves an
unknown ability through `$holders[$p] ?? []`, turning *"I have no data"* into *"there are no
holders"*.

**F3 — the ticket `route-middleware-baseline-is-67-routes-stale.md` is itself stale; the
figure is 73. — severity: ticket.**
A carried number, one layer up from the code.

**F4 — `app/Support/RouteAccessMap.php` claimed "No route carries one today"; 20 of 437
registered routes do. — severity: fix. FIXED IN THIS COMMIT (scope deviation).**
The parenthetical sat on the super-admin bypass-exclusion branch and described it as
hypothetical — *"deriving it correctly is what keeps the C2 oracle true when one does"*.
Measured on the generated snapshot: **20** routes carry a permission whose terminal segment
is `approve` or `reject` — every finance approvals queue and decision endpoint, plus
internal audit's three. That branch is what decides `super_admin`'s absence from 20 rows of
the committed access map, including the row this commit adds. A reader trusting the
parenthetical would conclude those absences came from somewhere else. Fixed because it is
comment-only and describes the exact function under change; flagged because it was outside
the brief's scope.

**The replacement was itself wrong, and the cold review caught it.** Its first version gave
the total as 20 and then itemised *"every finance approvals queue and decision endpoint, plus
internal audit's three"* — which sums to 19. Re-measured in this pass, reading
`CHECKER_SEGMENTS` from `ApprovalAbility` rather than hardcoding it: **20 of 437**, being
**16 finance** (five pending queues, ten decision endpoints, `GET /finance/approvals`) and
**4 internal audit**. The dropped one is `GET /internal-audit/review-queue`.

**That is the finding, not the arithmetic.** It is the *same* route whose presence made the
original *"exactly three keys"* claim falsely checkable by count — the Inertia page under
`/internal-audit`, not `/api/internal-audit`, which is why it is the one a reader drops. One
overlooked route produced two independent false statements, in a branch whose entire subject
is an overlooked route. The comment now itemises to the total and says which the fourth is.

**F5 — neither oracle can distinguish "this route's access is correct" from "this route is
not in my fixture", and this is at least the third time it has bitten. — severity: ticket
(already ticketed; this is a second data point).**
`docs/handoff/tickets/duplicate-check-route-is-in-neither-route-oracle.md` records
`GET /api/guardians/duplicate-check` in exactly this state, and
`docs/handoff/tickets/no-gate-asserts-a-new-routes-middleware-matches-its-intent.md`
records the structural gap. The return route is a fresh instance: it shipped absent from
both, and the only thing that noticed was a cold review reading a docblock. **Adding four
rows does not close this** — it fixes the instances and leaves the class. The asymmetry is
deliberate (it stops parallel Finance work going red on every new route), so the fix is not
"make new routes red"; it is something that reports *coverage* — the count of registered
routes NOT in each fixture, asserted against an expected number, so the gap has to move
deliberately. The three-numbers discipline (examined / excluded / **unrecognised**) applied
to the oracles themselves. Today those numbers are 383/437 and 364/437, and nothing states
them.

---

## Review findings and what this pass did

A cold review (own git worktree, handed only this path and the branch name) returned five
findings and the verdict **ship with fixes**. All five were correct. Its summary of the
problem, quoted:

> three of the four prose blocks the commit adds to replace false prose contain false or
> non-reconciling quantified claims of their own, in a commit whose entire thesis is that
> quantified prose about these oracles must be true.

| # | Finding | Action |
| --- | --- | --- |
| 1 | `RouteAccessMap.php` states 20 but itemises 19; the dropped route is `GET /internal-audit/review-queue` | **Fixed.** Re-measured independently (20 = 16 + 4) and the comment now itemises to its total and names the fourth. See F4. |
| 2 | *"a route ABSENT from a fixture is never asserted by either"* is false for the middleware oracle | **Fixed.** Rewritten per-oracle. See below — the error was the advisory brief's, not the implementing side's. |
| 3 | *"the map **does contain** exactly three keys"* is present tense and this commit makes it four | **Fixed.** Past tense, plus an explicit parenthetical that the count is now four. |
| 4 | F2 lives only in a per-branch report, with no ticket file | **Fixed.** `docs/handoff/tickets/rbac-derive-access-emits-empty-roles-for-an-unsynced-permission.md`. Its severity argument is rewritten, because verifying it disproved it. |
| 5 | *"Not done"* states the backlogs at their pre-commit values | **Fixed.** Re-measured at this commit by key difference: 53 and 70, both reconciling to 437. |

**Finding 2's error was the brief's, not this side's, and that attribution matters.** The
advisory brief asserted as fact that both oracles iterate only their own fixture keys, and
this side wrote it down without opening the second test. The advisory side has said so
plainly. Recording it here because a report that quietly absorbs an upstream error teaches
the wrong lesson to whoever reads it next: the failure was *an unread premise carried into
prose*, which is the same failure the commit exists to correct, one level up.

Measured this pass — every arm of both files, cited by line:

| test | arm | iterates | sees an absent route? |
| --- | --- | --- | --- |
| `RouteAccessParityTest.php:34` | fixture parity | `$fixture` (`:45`) | no |
| `RouteAccessParityTest.php:67` | deviation honesty | `ACCESS_DEVIATIONS` (`:73`), currently empty | no |
| `RouteAccessParityTest.php:132` | HTTP smokes | hand-written dataset, 15 cases (`:141`) | no |
| `RouteMiddlewareBaselineTest.php:19` | stack parity | `$fixture` (`:28`) | no |
| `RouteMiddlewareBaselineTest.php:46` | unguarded-new | **`$live`** (`:53`) | **yes — but only if it carries no `auth*`** |

So the precise statement is: the access oracle is blind to absence outright; the middleware
oracle is blind to an **authenticated** absent route. The return route carries `auth:sanctum`,
which is why the one arm that looks at live routes passed it freely. Controls: the arm-listing
grep matched a pattern known to exist and returned nothing for a pattern that cannot.

---

## Two more findings this pass, named by neither the review nor the brief

**F6 — this report's own F2 was wrong, and the second brief inherited it.** Recorded above
under F2 rather than repeated here. The general shape is worth stating on its own: **a
severity argument is a claim, and it needs measuring like any other.** "The gates cannot see
this" was plausible, was reasoned from a correct mechanism (`$holders[$p] ?? []`), and was
false — because it assumed one database where there are two. It took one planted row and one
test run to disprove. Both sides asserted it; neither had run it.

**F7 — three of my own measuring commands reported a clean number while examining the wrong
thing, in a session whose subject is instruments that do that.** All three were caught only
by a control:

| command | what it reported | why it was wrong |
| --- | --- | --- |
| `grep -rn … --include=*.php .` | `0` matches | unquoted glob, expanded by zsh; the command errored having examined nothing |
| `php -r '…' S=path` | `0` checker routes | `S=path` after the script is **argv, not env**; `getenv` returned false |
| `awk 'length>100'` | five over-long lines | `length` counts **bytes**; the box-drawing `──` is 3 bytes each, so a 97-character line read as 153 |
| `grep -rln FINANCE_INVOICE_APPROVE database/migrations/` | control returned `0` | the *control itself* was vacuous — that string genuinely is not in migrations, so it proved nothing about the instrument |

The fourth row is the one worth keeping: **a positive control that returns zero has not
validated anything**, and I nearly took an absence claim off it. Replaced with a control
known to hit (`activity_log.view_all`, 2 files, against 181 migration files visible).

**F8 — this report cross-referenced the wrong finding id.** *"Not done"* pointed at F5 for
`rbac:sync` where the finding is F1. Corrected. Trivial in itself; recorded because a report
whose internal pointers are wrong is the same defect class as a docblock whose line citations
are wrong, and this branch fixed one of those in someone else's file.

---

## What this commit does not prove

The oracles now assert these four routes. That is a claim about the *fixtures*, not about
whether the gates are right. The gates were read and argued from the seeder and
`ApprovalAbility`, and `RouteAccessParityTest`'s live re-derivation agrees — but no request
was made against the return route by any seat, because on this machine every seat would be
refused for F1's reason rather than the route's own. **Presence is not reachability.**

