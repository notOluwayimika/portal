# Implementation report — §9 step 5b-ii, the opening-balance decision surface

## Headline

**Done.** The checker can now approve or reject an opening-balance batch over
HTTP; approving posts the cutover, and the approvals queue confirms that one
approval and no other before it fires.

Branch `feat/finance-ob-decision-surface`, one commit `1168d83`, base
`a4f669d` (`origin/staging`). No PR opened — see _Not done_.

**This is full-review tier**: it touches money (an approval that posts a whole
school's opening position), a Policy on a checker ability, two new
permission-gated routes, and a fixture-adjacent test that was flipped rather
than added to. Subagent review attached; recommend a cold session before merge.

## Deviations from the brief

**One, and it is a naming correction the brief anticipated.** The brief said to
check the maker column rather than assume the sibling's name. It is
`submitted_by_user_id`
(`database/migrations/2026_08_09_100000_opening_balance_approval_gate.php:79`),
not `submitted_by`. The Policy uses it and its docblock records what a copied
`submitted_by` would have done: read `null` on every batch, take the
null-maker early return, and permit self-approval on every row — with every
test that does not specifically check the maker still green.

**Two things the brief told me to report rather than build, reported here:**

1. **The row count a checker wants is not on the wire.** The brief asked for
   the confirmation to state what will be posted in numbers the checker can tie
   back, "the batch reference and the control total at minimum", and to say so
   rather than invent a field if the numbers are missing. `OpeningBalanceBatchResource`
   emits `batch_reference` and `amount` (the control total) and **not**
   `row_count` or `file_row_count`, both of which are columns on the batch. So
   the dialog names the reference and the control total, and cannot say "…
   across N lines". I did not add the field: it is outside this commit's fuse
   and it is the operator screen's question as much as this one's. Raised
   below as a ticket.

2. **`ApprovalsQueueFeedCoverageTest` did need to learn about the decide urls,
   and about the confirmation.** The brief asked me to decide and explain. I
   added two arms plus a third on the page. Reasoning in _What changed_.

**No general rule was formed mid-implementation that this change rests on.**

## Contradictions of the premise

**None in this brief.** The premise of the _previous_ brief (that 5a shipped a
working Approve button for this type) was false, which is why this one exists;
this brief's premise — no endpoint, no policy, no console submit path, 4c
domain-only — matched the repo exactly:

- `routes/endpoints/finance.php` carried two opening-balance routes before this
  commit, `pending` and `import/template`, and no decision route.
- `app/Finance/Policies/` held four policies and no `OpeningBalanceBatchPolicy`.
- `resources/js/lib/finance/approval-feeds.ts:215-230` gave the
  `opening_balance` entry no `decide` and rendered
  `decidedElsewhere: 'No decision screen yet — §9 step 5b'`.
- `ApproveOpeningBalanceBatch` had no caller outside tests and docblocks
  (`grep` over `app routes tests`: every hit was a `{@see}` or the test file).

**One consequence of that premise worth stating plainly, because it makes this
commit look broken if you do not know it:** nothing can move a batch into
`submitted` today, so **the queue renders zero opening-balance rows** on a real
database. The feed, the routes, the Policy and the confirmation are all live and
all currently unreachable by an operator. That is the deliberate build order —
exit before entrance — and the operator screen is the next commit.

## What changed

11 files, +896 / −51.

| File                                                             | Δ       | What                                                                                                                                                                                                               |
| ---------------------------------------------------------------- | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `app/Finance/Policies/OpeningBalanceBatchPolicy.php`             | +60     | New. `approve`/`reject`: the matching permission **and** not the maker. String comparison, kept with the sibling's comment.                                                                                        |
| `app/Finance/Http/Requests/RejectOpeningBalanceBatchRequest.php` | +28     | New. `reason` required, string, max 255 — the `rejection_reason` column's own width.                                                                                                                               |
| `app/Finance/Http/Controllers/OpeningBalanceBatchController.php` | +78/−9  | `approve` and `reject`; `Gate::authorize` → Action → `BusinessRuleException` → 422 → Resource. Class docblock rewritten (it said "no HTTP path at all").                                                           |
| `routes/endpoints/finance.php`                                   | +35/−12 | Two POST routes, separately gated. Block docblock rewritten — it said "THE READ SURFACE ONLY … This route does not open one".                                                                                      |
| `app/Finance/Http/Resources/OpeningBalanceBatchResource.php`     | +16/−9  | **Docblock only, no code.** It asserted `can_approve`/`can_reject` are "FALSE TODAY FOR EVERY VIEWER"; that is now false. Rewritten to record that its own prediction came true and the file did not need editing. |
| `resources/js/lib/finance/approval-feeds.ts`                     | +82/−14 | `decide` urls replace `decidedElsewhere`; `confirm` added to the type and to this one entry; the "no approve/reject endpoint until step 5b" paragraph rewritten, keeping the rule and dropping the dead example.   |
| `resources/js/pages/admin/finance/approvals.tsx`                 | +85/−5  | `requestApprove()` gate + the confirmation Modal. Branches on the row's **feed**, never its type.                                                                                                                  |
| `resources/js/types/finance.ts`                                  | +9/−7   | Comment only — the same obituary.                                                                                                                                                                                  |
| `tests/Feature/Finance/OpeningBalanceDecisionSurfaceTest.php`    | +400    | New. 9 arms over HTTP.                                                                                                                                                                                             |
| `tests/Feature/Finance/ApprovalsQueueFeedCoverageTest.php`       | +135    | Three new arms (decide-url wiring, confirmation exclusivity, the page's gate).                                                                                                                                     |
| `tests/Feature/Finance/ApprovalsQueueRendersEveryTypeTest.php`   | +19/−7  | ARM 3b flipped `opening_balance => false` to `true`.                                                                                                                                                               |

Not in the diff and worth knowing: the wayfinder action module that exports
`approve`/`reject`, `resources/js/actions/App/Finance/Http/Controllers/OpeningBalanceBatchController.ts`,
is **gitignored** (`.gitignore:10 → /resources/js/actions`) and regenerated by
`bin/quality` step 2. The TypeScript imports it; git never sees it.

### Why the three new coverage arms, and why not more

The brief asked whether `ApprovalsQueueFeedCoverageTest` needs to know about the
decide urls. It does, and the reasoning is the file's own: it already pins
`pendingUrl` aliases 1:1 because import coverage alone stays green while one
entry is pointed at another feed's url. The decision urls have the same
failure mode one door along — `approve: (id) => approveCredit.url(id)` on the
opening-balance entry posts a batch uuid at the credit-note endpoint. That
fails loudly rather than silently, but it fails **in front of a checker trying
to approve a cutover**, which is the worst place to discover it. All five
entries already name aliases `<verb><Type>`, so the arm strips the verb and
requires an entry's three aliases to agree — no new convention invented.

The **confirmation** arm matters more, because its failure is genuinely silent:
delete `confirm` from the entry and nothing renders differently, no request
fails, no type complains — the button simply fires, and a checker posts a
cutover with one press. Both directions are pinned (missing here, extra
elsewhere), plus that the sentence contains the word "irreversible". The
expected list is written by name rather than derived, deliberately: nothing in
the TypeScript says which approvals are irreversible, so a derivation would be
inventing an oracle. A sixth irreversible type must make that arm red.

The **page** arm exists because a declared confirmation the page never consults
is worse than none — the declaration is then read as evidence the guard is
there.

**What I did not add:** a pin that the _dialog text_ matches the batch. There is
no JS test runner in this repo (`package.json` has vite/eslint/prettier/tsc and
no vitest or jest), so everything above is source assertion, and asserting on
rendered output is not available at any price here.

## Proof

### bin/quality — raw, unedited (ANSI colour codes stripped; nothing else removed)

```
quality gate — base a4f669d

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

### The new test file, green

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/OpeningBalanceDecisionSurfaceTest.php
{"tool":"pest","result":"passed","tests":9,"passed":9,"assertions":50,"duration_ms":15121}
```

Expected 9 passing arms; observed 9. The arms:

| Arm      | What it proves                                                                                                                                                                                                       |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PROOF A  | `Gate::getPolicyFor(OpeningBalanceBatch::class)` is an `OpeningBalanceBatchPolicy` — the naming convention, asserted rather than assumed.                                                                            |
| PROOF B  | Approve over HTTP → 200, `status: posted`, **3 ledger rows and 1 payment** where there were 0, two charges sourced `opening_balance_row` for the owing student, `origin = migrated` on the credit student's payment. |
| PROOF C  | The maker, **holding both checker abilities**, is 403 on approve; batch still `submitted`, `decided_by_user_id` null, subledger still `[0, 0]`.                                                                      |
| PROOF C2 | Same on reject, with a valid reason in hand.                                                                                                                                                                         |
| PROOF D  | A holder of approve-but-not-reject is 403 on reject and 200 on approve — both directions on one actor.                                                                                                               |
| PROOF E  | Reject over HTTP → 200, reason and checker recorded, `posted_at` null, staged rows retained, subledger `[0, 0]`.                                                                                                     |
| PROOF E2 | Missing reason → 422 with a `reason` validation error; 256 characters → the same.                                                                                                                                    |
| PROOF F  | A `validated` batch → 422 on **both** decisions, with the Action's own message on the wire.                                                                                                                          |
| PROOF G  | `pending` reports `can_approve`/`can_reject` **true** for the checker and **false** for the maker.                                                                                                                   |

### The feed coverage file, green

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/ApprovalsQueueFeedCoverageTest.php
{"tool":"pest","result":"passed","tests":8,"passed":8,"assertions":26,"duration_ms":204}
```

7 arms before, 8 after the page arm was added (the two feed arms landed in the
same run as the earlier 7 → the count went 5 → 7 → 8 across edits; re-derive
from the file rather than from this sentence).

### Route registration

```
php artisan route:list --path=opening-balance
 GET|HEAD api/v1/finance/opening-balance-batches/import/template
 GET|HEAD api/v1/finance/opening-balance-batches/pending
 POST     api/v1/finance/opening-balance-batches/{batch:uuid}/approve
 POST     api/v1/finance/opening-balance-batches/{batch:uuid}/reject
 Showing [4] routes
```

### Route fixture oracles — deliberately not regenerated

`RouteMiddlewareBaselineTest` documents the asymmetry at its own top: _"a NEW
route is allowed without touching the fixture as long as it carries an auth
middleware — Finance route additions never go red here."_ Both new routes carry
`auth:sanctum`. Precedent checked rather than assumed: 5b-i added a route and
its commit `b1d5e50` touched no fixture. Both oracle tests pass unchanged:

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Rbac/RouteMiddlewareBaselineTest.php tests/Feature/Rbac/RouteAccessParityTest.php
{"tool":"pest","result":"passed","tests":19,"passed":19,"assertions":18,"duration_ms":14970,"risky":1}
```

(The `risky: 1` is pre-existing and not in a file this commit touches.)

## The watched red

**Ten mutations, each restored, each with the failure named.** No arm in this
commit has only ever been seen green.

### RED 1 — `OpeningBalanceBatchPolicy.php` moved out of the tree

`mv app/Finance/Policies/OpeningBalanceBatchPolicy.php <scratch>`

```
failed  tests=9 passed=3 failed=5 errors=1
  ERROR   PROOF A: Class or interface "App\Finance\Policies\OpeningBalanceBatchPolicy" does not exist
  PROOF B: Expected response status code [200] but received 403.
  PROOF D: Expected response status code [200] but received 403.
  PROOF E: Expected response status code [200] but received 403.
  PROOF F: Expected response status code [422] but received 403.
  PROOF G: Failed asserting that false is identical to true.
```

Named the right thing. **Note what stayed green: PROOF C and C2.** With no
policy the Gate denies everybody, so the maker's 403 is right for the wrong
reason — which is exactly why RED 2 exists and why the maker in those arms
holds the checker's ability.

### RED 2 — `&& $this->isNotTheMaker(...)` deleted from **both** Policy methods

```
failed  tests=9 passed=6 failed=3
  PROOF C:  Expected response status code [403] but received 422.
  PROOF C2: Expected response status code [403] but received 422.
  PROOF G:  Failed asserting that true is identical to false.
```

Named the right thing. **The 422 rather than a 200 is informative and should be
read, not skipped:** the maker still did not post, because
`ApproveOpeningBalanceBatch` re-reads the submitter under the lock and refuses
independently. The Policy is not the last line of this defence — it is the
first of three (Policy, Action-under-lock, and the
`…_maker_ne_checker` CHECK). Deleting it degrades a 403 to a 422, not to a
self-approval. That is the design holding, and it is also why a green PROOF C
alone would have proved very little.

### RED 3 — `approve()` returns the batch without calling the Action

`$approved = $action->handle(...)` → `$approved = $batch;`

```
failed  tests=9 passed=7 failed=2
  PROOF B: Failed asserting that two strings are identical. -'posted' +'submitted'
  PROOF F: Expected response status code [422] but received 200.
```

Named the right thing — PROOF B is the arm that refuses a cheerful 200 whose
money never moved.

### RED 4 — the reject route re-gated on `finance.opening-balance.approve`

```
failed  tests=9 passed=7 failed=2
  PROOF E:  Expected response status code [200] but received 403.
  PROOF E2: Expected response status code [422] but received 403.
```

Named the right thing, **but not by the arm I expected, and that is a real
limitation of PROOF D worth recording.** PROOF D stayed green: with the reject
route gated on `…approve`, its approve-only actor is admitted by the middleware
and then refused by the Policy, which checks the same `…reject` permission. So
**PROOF D pins the refusal, not the route's middleware** — the two are
redundant with each other, and the middleware's contribution is defence in
depth rather than the sole gate. What actually caught the mutation is the pair
of arms using a reject-only checker.

### RED 5 — reject rules relaxed to `['nullable', 'string']`

```
failed  tests=9 passed=8 failed=1
  PROOF E2: Failed to find a validation error in the response for key: 'reason'
```

### RED 6 — both `catch (BusinessRuleException)` blocks removed

```
failed  tests=9 passed=8 failed=1
  PROOF F: Expected response status code [422] but received 500.
           App\Exceptions\BusinessRuleException: Only a submitted opening-balance
           batch can be approved; this one is validated.
           in app/Finance/Actions/ApproveOpeningBalanceBatch.php:52
```

(Stack trace elided — ~85 frames of framework internals. The line above is the
decisive one and is verbatim.)

### RED 7 — `confirm` deleted from the `opening_balance` feed entry

```
failed  tests=8 passed=7... (7/8)
  The approvals queue confirms [] before approving. Exactly one type may:
  opening_balance, whose approval posts a cutover that cannot be un-posted,
  deleted or moved. A missing confirmation there is a one-click irreversible
  post; an extra one anywhere else is what teaches a checker to dismiss the
  dialog without reading it.
```

### RED 8 — a second `confirm` added to the (correctable) `credit_note` entry

```
  The approvals queue confirms [credit_note, opening_balance] before approving.
  Exactly one type may: …
```

This is the type-scoping proof the brief asked for, made the way it asked:
_fire it for another type and watch the assertion catch it._

### RED 9 — the entry's approve url pointed at the credit-note endpoint

`approve: (id) => approveOpeningBalance.url(id)` → `approveCredit.url(id)`

```
  The [opening_balance] entry fetches with [pendingOpeningBalance] but approves
  with [approveCredit] — a decision posted at another type's endpoint.
```

### RED 10 — the Approve button wired straight at `approve()`, bypassing the dialog

```
failed (7/8)
  resources/js/pages/admin/finance/approvals.tsx no longer routes Approve
  through requestApprove(). An irreversible approval declared in the feed list
  would fire on the click with no dialog.
```

All ten restored; `git status` clean before the commit, and the committed tree
is the unmutated one (`bin/quality` green on it).

## Database observations

Nothing was written to the local ground-truth copy (`portaa10_portal`). All
proof ran on `portal_testing` under `RefreshDatabase`.

Schema facts re-derived rather than carried, at the moment of use:

- `finance_opening_balance_batches` decision columns come from
  `2026_08_09_100000_opening_balance_approval_gate.php`:
  `submitted_by_user_id` (`:79`), `decided_by_user_id` (`:81`),
  `rejection_reason` as `$table->string()` → varchar(255) (`:83`), and the
  `finance_opening_balance_batches_maker_ne_checker` CHECK (`:90-92`).
- **The local dev database is four migrations behind** `origin/staging`:
  `2026_08_08_110000`, `2026_08_08_120000`, `2026_08_09_100000` and
  `2026_08_09_110000` all report `Pending`. `SHOW COLUMNS` on that copy
  therefore does not show any decision column. Nothing in this commit was
  derived from that copy; the column facts above are from the migration files
  and the test database.

Subledger counts asserted in the proofs, as counts only: `[0, 0]` before every
approval arm; `[3, 1]` after the one successful post; `[0, 0]` after every
refusal and after the successful reject.

## Not done

- **No push, no PR.** The re-scoping brief (`plan_docs/explanation.md`) does not
  ask for either, and `finance-execute` ends the implementing hand at "commit on
  the branch, never push". The first brief did ask for a push and a PR, but it
  was superseded. Awaiting the lead's call; commit `1168d83` is on
  `feat/finance-ob-decision-surface`.
- **No browser drive.** This brief did not ask for one, and it could not have
  shown much: nothing can reach `submitted` yet, so the queue renders no
  opening-balance row on a real database. Driving the confirmation would mean
  planting a `submitted` batch in the dev copy by hand — and **pressing through
  it would consume that school's single posting slot permanently** (G1 admits
  one posted batch per school ever; G1b denies both exits). I judged that too
  expensive against a copy that is ground truth for other findings. The
  confirmation is therefore **proven at the source level only**: declared,
  exclusive, containing the word "irreversible", and consulted by the button.
  Whether the dialog _looks_ right on a rendered page is unverified.
- **`row_count` is not in the confirmation** — see _Deviations_.
- **PROOF D does not pin the reject route's middleware**, only that a
  non-holder is refused — see RED 4. The middleware is redundant with the
  Policy's own permission check.
- **The `risky: 1` in the Rbac oracle run** was not investigated; it is
  pre-existing and outside this diff.

## Findings raised, not fixed

- `app/Finance/Http/Resources/OpeningBalanceBatchResource.php` — emits neither
  `row_count` nor `file_row_count`, so no surface on the approvals queue can say
  how many lines a cutover posts. The checker sees a reference and a control
  total and no size. **ticket** (and probably the operator screen's to close,
  since it needs the same numbers).
- `resources/js/lib/finance/approval-feeds.ts:105` — `decidedElsewhere` now has
  **no user**. It is kept for the rule its docblock carries, which is a real
  reason, but a member no entry uses is a member that rots. **ticket.**
- Local dev database is 4 migrations behind `origin/staging` — anyone driving
  the app against it will not find the decision columns. **ticket** (environment,
  not code).
