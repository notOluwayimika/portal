# §9 step 5a — the approvals queue renders every type, from a declared list

Branch `fix/finance-approvals-queue-renders-every-type`, base `origin/staging` @ `a7d24f1`,
one commit `6b14aa7`. No PR opened yet (see **Not done**).

**This is full-review tier** — it touches RBAC-gated routes, a fixture oracle and a read surface an
approver depends on. Subagent review attached; recommend a cold session before merge.

---

## Headline

Done, with two contradictions of the brief's premise resolved before writing anything and two
deviations. The queue now fetches five feeds from one declared list, renders and decides every type
off that list, and is pinned to the registered routes by a test in both directions. `bin/quality`
passes all 14 steps.

---

## Contradictions of the premise

Both were raised and resolved with the project lead before any file was touched.

**1. The brief's base commit was stale, and on it the whole of part 1 was impossible.** The brief
named `origin/staging` @ `c200d08`. On `c200d08` there is no `finance.opening-balance.approve` in
`app/Enums/Permission.php`, no opening-balance controller anywhere, and the 4c actions have no HTTP
surface at all — so part 1 ("a `pending` action on the opening-balance controller") had no controller
to add it to, and part 4's "the abilities all exist; do not add a Permission case" was false. Worse,
adding the ability on that base would have broken `approval-seam-count`: `c200d08` carries four
finance `*_SUBMIT` cases and four `app/Finance/Actions/Submit*.php`, so the Permission case without
`SubmitOpeningBalanceBatch` is exactly the lockstep drift that lint exists to catch — part 1 on that
base meant re-implementing step 4c.

The lead identified the cause: the fetch behind the brief had not run (no network on that side), so
the last-fetched ref was reported as current. A real `git fetch origin --prune` moved
`origin/staging` to `a7d24f1` — PR #217, the 4c merge — which contains `fd7965b`. Everything part 1
assumed is true there. Branched off `a7d24f1`; five types, no stacking.

**2. "Shaped exactly like the four siblings" assumed a shape the four do not share.** They are two
shapes, and the difference is load-bearing:

| feed | envelope | `type` | `can_approve`/`can_reject` | `submitted_by_name` | `amount` |
|---|---|---|---|---|---|
| credit-note, void | `{"data":[…]}` | yes | yes | yes | yes |
| fee-schedule-change, discount-policy-change | bare array | no | no | no | no |

The envelope difference is a Laravel subtlety rather than an oversight:
`response()->json(Resource::collection($x))` serialises through `jsonSerialize()`, which does **not**
apply the `data` wrap that the two working feeds get by returning through `toResponse()`. So a
`{url,label}` list mapping `res.data.data` would have read `undefined` from two of four — and even
after fixing that, those rows would have rendered with no type badge (falling through to the "Credit
note" branch), no label, `can_approve: undefined` (buttons permanently disabled), and an approve
click POSTing a fee-schedule-change uuid at the credit-note approve endpoint. Part 2 as scoped was
necessary and not sufficient. The lead ruled scope: normalise both change feeds, read surface only,
mirror the working shapes exactly rather than improve on them.

Minor drift, corrected in place: the `APPROVAL_REQUESTED` deep link is at
`app/Notifications/Services/PayloadHydrator.php:123`, not `:121`.

---

## Deviations from the brief

**1. Opening-balance rows render WITHOUT decision buttons, and say where the decision is taken.** The
brief's part 1 asks only for a `pending` action; there is no opening-balance policy and no
approve/reject endpoint anywhere (that is step 5b / U12b), and the lead's scope answer forbade
opening one. A row with Approve/Reject that cannot work is the lead's own "present-and-dead is
dishonestly broken", so `ApprovalFeed.decide` is **optional**: a feed with no decision urls renders
`decidedElsewhere` in place of the buttons. Step 5b turns opening balances into a decidable row by
adding two urls to one entry.

The general rule this rests on, stated so it can be checked: **a queue row must not offer an action
whose endpoint does not exist.** I believe that is right here. It is worth a second opinion on
whether the row should appear at all before 5b — I judged yes, because before 5a a holder of
`finance.opening-balance.approve` had no screen telling them a batch was waiting, which is the defect
this step exists to close.

**2. `can_approve` / `can_reject` are still emitted on the opening-balance resource, and are false
for every viewer today.** They are computed through the Gate exactly as the four siblings compute
theirs (`$user->can('approve', $this->resource)`). With no `App\Finance\Policies\
OpeningBalanceBatchPolicy`, Laravel's discovery finds nothing, the super-admin `Gate::before` returns
`null` for a terminally-`approve` ability (ADR 0040 exclusion), and the call resolves **false** —
fail-closed, and asserted as such. The value is not read by the page for this type. The upside is
that 5b flips them on by registering a policy, with no edit to the resource. Flagged because
"a Policy-computed flag with no Policy" is the kind of thing that reads as a bug at a glance.

---

## What changed

16 files, +1,150 / −136 (`git diff --numstat a7d24f1 6b14aa7`).

**Part 1 — the opening-balance feed (read surface only)**

- `app/Finance/Http/Controllers/OpeningBalanceBatchController.php` (new, 48) — one action, `pending`.
  Filters `OpeningBalanceBatchStatus::Submitted` (4c's only approvable state; `validated` has not
  been offered for a decision), eager-loads the maker, returns the `data` envelope.
- `app/Finance/Http/Resources/OpeningBalanceBatchResource.php` (new, 62) — mirrors
  `CreditNoteResource` / `VoidRequestResource` field for field. `amount` is the batch's
  `control_total` (§1's L2 witness — the position approval posts in one transaction).
- `app/Finance/Models/OpeningBalanceBatch.php` (+15 −0) — a `submittedBy()` belongsTo on
  `submitted_by_user_id`, for the queue's submitter column. No FK added; that column's design (no DB
  FK, "LOOKUP") is unchanged, and an Eloquent relation does not need one.
- `routes/endpoints/finance.php` (+25 −3) — `GET /v1/finance/opening-balance-batches/pending`, gated
  on `finance.opening-balance.approve`; plus the two docblock corrections below.

**Part 2 — the queue renders every type**

- `resources/js/lib/finance/approval-feeds.ts` (new, 163) — `APPROVAL_FEEDS`, the declared list:
  `{type, label, badgeClass, pendingUrl, decide?, decidedElsewhere?, rowLabel}` × 5, plus `feedFor()`
  and `rowLabel()`.
- `resources/js/pages/admin/finance/approvals.tsx` (+150 −124) — imports **zero** controller actions;
  fetches `APPROVAL_FEEDS.map(...)`; errors on `settled.every(rejected)`; badge, label, decision urls
  and toast copy all come off the feed entry. `Promise.allSettled` kept, and the comment now says at
  N feeds what it said at two.
- `resources/js/types/finance.ts` (+69 −4) — three new members of the `PendingApproval` union.
- Both change controllers' `pending()` (+8 −1, +18 −1) — `data` envelope, `submitter` eager-loaded.
- Both change resources (+26 −1, +40 −1) — `type`, `note`, `amount` (null), `submitted_by_name`, and
  Policy-computed `can_approve` / `can_reject`.

**Part 4 / guard**

- `tests/Feature/Finance/ApprovalsQueueFeedCoverageTest.php` (new, 147 lines, 3 tests) — the declared list vs the
  registered routes, both directions; the page imports no controller action; the error rule is
  array-wide.
- `tests/Feature/Finance/ApprovalsQueueRendersEveryTypeTest.php` (new, 365 lines, 5 tests) — the HTTP arms.
- `tests/fixtures/route-access-map.json`, `route-middleware-baseline.json` (+6, +7) — regenerated, not
  hand-edited, in the strict order (`rbac:sync` → `rbac:derive-access` → `rbac:derive-map`).
- `docs/handoff/finance-mvp-cut-brief.md` — U16 closed against reality (see **The ticket**).

**No Permission case. No Submit action. No migration. No change to any approve/reject path.**
`phpstan-baseline.neon` and `tests/ratchet-baseline.txt` untouched.

### The feed list, verbatim

```ts
export const APPROVAL_FEEDS: ApprovalFeed[] = [
    { type: 'credit_note',           label: 'Credit note',     pendingUrl: () => pendingCredit.url(),           decide: {…}                                                          },
    { type: 'void',                  label: 'Void',            pendingUrl: () => pendingVoid.url(),             decide: {…}                                                          },
    { type: 'fee_schedule_change',   label: 'Fee schedule',    pendingUrl: () => pendingScheduleChange.url(),   decide: {…}                                                          },
    { type: 'discount_policy_change',label: 'Discount policy', pendingUrl: () => pendingDiscountChange.url(),   decide: {…}                                                          },
    { type: 'opening_balance',       label: 'Opening balance', pendingUrl: () => pendingOpeningBalance.url(),   decidedElsewhere: 'Decided on the opening-balance batch screen'      },
];
```

(Abridged on `decide` / `badgeClass` / `rowLabel` for width only — the full entries are at
`resources/js/lib/finance/approval-feeds.ts:78-152`.)

---

## The two false docblocks

`routes/endpoints/finance.php` claimed, on **both** governance blocks, that the pending queue "joins
the unified approvals screen by the ApprovalAbility convention (no route edit)". That was false for as
long as it stood. The convention derives who may **open** the queue (`routes/web.php:167-172`); it has
never had anything to say about which feeds the page **fetches**, and the page fetched two hardcoded
imports. A holder of `finance.fee-schedule.change.approve` could open a screen that never asked that
endpoint anything. Both comments now say what happened and why 5a is what makes the sentence true.

This is the wallpaper principle in the concrete: the convention was real for the route gate and was a
**wish** for the page, and nothing failed a build over the gap for eighteen commits.

---

## The notification link

`app/Notifications/Services/PayloadHydrator.php:123` points `APPROVAL_REQUESTED` at
`/finance/approvals`. It needed no edit and got none. It was **correct by accident for two types and
wrong for two**: an approver notified about a fee-schedule change followed a correct-looking link to
a screen that did not fetch their feed, and saw "Nothing awaiting approval". As of this change the
link is correct for all five — the queue renders every type, so every `APPROVAL_REQUESTED`
notification lands somewhere that shows the thing it is about. `NotificationDeepLinkRouteTest` (which
pins the PHP and TS deep-link maps to each other) is unaffected; the URL did not change.

## The ticket

The ticket carrying this was **U16** in `docs/handoff/finance-mvp-cut-brief.md:140`, whose status read
*"`approvals.tsx` exists, covers four."* That was wrong twice: it covered **two**, and four feeds were
live. Updated to five of six, with the reason the sixth is outstanding (refund has no domain — U15 /
S10) and the note that opening balances render but are not decided here. The row can no longer be
wrong silently, because `ApprovalsQueueFeedCoverageTest` fails if the list and the routes disagree.

I did **not** rewrite §4 of `docs/handoff/reports/feat-finance-ob-approval-gate.md`, which says "U16
remains open and is now two types further from done". That report is a record of what was true when
it was written, not a live tracker.

---

## Proof

Raw. The pest output in this environment is the JSON reporter line; it is pasted exactly as printed,
including via `rtk proxy` (unfiltered) to rule out the wrapper.

**The two new files, together:**

```
$ DB_DATABASE=portal_testing rtk proxy ./vendor/bin/pest tests/Feature/Finance/ApprovalsQueueFeedCoverageTest.php tests/Feature/Finance/ApprovalsQueueRendersEveryTypeTest.php
{"tool":"pest","result":"passed","tests":8,"passed":8,"assertions":135,"duration_ms":16356}
```

**The fixture regeneration, in the strict order:**

```
$ php artisan rbac:sync
rbac:sync — roles/permissions synced; existing grants preserved (non-destructive).

$ php artisan rbac:derive-access
route-access-map.json written (361 routes).

$ php artisan rbac:derive-map
route-middleware-baseline.json written (361 routes).
```

The whole fixture diff — one route, no other movement:

```
tests/fixtures/route-access-map.json
+    "GET /api/v1/finance/opening-balance-batches/pending": {
+        "auth": true,
+        "roles": [
+            "executive_director"
+        ]
+    },

tests/fixtures/route-middleware-baseline.json
+    "GET /api/v1/finance/opening-balance-batches/pending": [
+        "api",
+        "auth:sanctum",
+        "tenant",
+        "permission:finance.access",
+        "permission:finance.opening-balance.approve"
+    ],
```

`rbac-grants-baseline.json` did not change, and should not have: no grant moved.

**`bin/quality` — all 14 steps:**

```
$ DB_DATABASE=portal_testing bin/quality
quality gate — base a7d24f1

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

Note for the reader: `bin/quality` is **14** steps on this base, not the 13 that circulates.

### `approval-seam-count` / `approval-seam-missing`

Both stayed green and untouched — step 7 above, zero baseline entries. What each would have caught
had I ignored part 4:

- **`approval-seam-count`** — it counts finance `*_SUBMIT` Permission cases against
  `app/Finance/Actions/Submit*.php` files (`bin/ci-boundary-lint.php:186-202`). Adding a Permission
  case for a new maker without its Submit action makes those counts 6 and 5, and the lint names
  `app/Enums/Permission.php` with the mismatch. On the brief's original base this would have fired
  immediately — it is what proved part 1 was impossible there.
- **`approval-seam-missing`** — every `Submit*.php` must call `ApprovalRequirement::for(` on a live,
  uncommented line (`:162-184`). Adding a Submit action for the opening-balance HTTP path with the
  requirement hard-wired at the call site, or commenting the call out, names that file. Neither could
  fire here: I added no Submit action.

---

## The watched reds

Four mutations, each run and each restored. Backups taken to the scratchpad first; `git diff --stat`
confirmed clean after every restore, and the final 8/8 green above is post-restore.

**RED 1 — remove one entry from the declared list.** Deleted the `FeeScheduleChangeController` import
block and the `fee_schedule_change` entry from `approval-feeds.ts` (4 entries left).

```
{"tool":"pest","result":"failed","tests":3,"passed":2,"assertions":4,
 "message":"A pending feed is live at the API and rendered on NO screen: FeeScheduleChangeController
 (GET /api/v1/finance/fee-schedule-changes/pending) — add it to
 resources/js/lib/finance/approval-feeds.ts. This is the exact defect §9 step 5a fixed."}
```

Names the missing feed **and its route**. This is the brief's fourth bite-proof: the guard against the
defect recurring rather than against this instance of it. Restored; green.

**RED 2 — put the "both rejected" rule back.** Replaced the page's condition with the pre-5a
fixed-arity form `settled[0].status === 'rejected' && settled[1].status === 'rejected'`.

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,
 "message":"resources/js/pages/admin/finance/approvals.tsx no longer errors on
 `settled.every(... rejected)`. The error rule must be expressed over the whole feed array,
 not a fixed number of feeds."}
```

(The first run of this red dumped the whole 400-line page source into the failure message — a
correct red with a useless message. I changed the assertion to compare booleans with an explicit
message and re-ran it; the output above is the second run.) Restored; green.

**RED 3 — ungate one feed.** Removed `->middleware('permission:finance.credit-note.approve')` from
the credit-note pending route, then ran the arm that asserts a checker holding no approve ability is
403 on every feed:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":6,
 "message":"Expected response status code [403] but received 200."}
```

Restored; green.

**RED 4 — revert one envelope.** Put `FeeScheduleChangeController::pending()` back to
`response()->json(FeeScheduleChangeResource::collection($changes))` — the bare array — then ran the
five-types arm:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":28,
 "message":"Expecting null not to be null '/api/v1/finance/fee-schedule-…ng row'."}
```

This is the one that proves the envelope claim rather than asserting it: with the bare array there is
no `data.0` at all, so the page's fetch-and-map genuinely could not have read this feed. Restored;
green.

**All eight tests passed on their first run before any red was watched.** That is exactly the state
the bite-proving discipline says proves nothing, which is why all four are above.

---

## Database observations

No schema change, no migration, no grant change. `rbac:sync` reported non-destructive and moved
nothing (the catalog diff was empty — no Permission case was added).

Route surface: 360 → **361** registered routes. Exactly one new route, reachable by one seeded role
(`executive_director`, which holds `finance.opening-balance.approve`). Test fixtures only; the
`portaa10_portal` copy was not touched.

---

## Not done

- **No PR, no push.** The brief's closing line asks for both. The `finance-execute` hand-off rule is
  explicit that pushing, merging and opening the PR are the project lead's, not the implementing
  hand's, and the two instructions conflict. I followed the standing rule and stopped at the commit.
  Say the word and I will push and open it.
- **No browser drive.** The queue's rendering is proven at the HTTP layer and by source assertions
  over the TypeScript. Nobody has watched five types render in a browser, and CLAUDE.md is explicit
  that tests alone are not verification. This is the biggest unproven arm in the change and the one I
  would look at first.
- **No JavaScript test.** There is no JS test runner in this repository — `package.json` has vite,
  eslint, prettier and tsc, and no vitest or jest. So the page's own rules (the ALL-rejected error
  condition, the feed coverage) are asserted by **parsing the TypeScript source from PHP**. The
  precedent is `tests/Feature/Notifications/NotificationDeepLinkRouteTest.php`, which reads
  `use-notifications.ts` the same way for the same reason. The limit is real and worth stating
  plainly: those tests pin the *shape of the code*, not what React renders. A refactor that keeps the
  literal `settled.every(...)` string while changing the behaviour around it would pass.
- **The pending rows are planted, not driven**, for the two change types and the opening-balance
  batch — model `create()` with `status = submitted` and `submitted_by` set. Credit notes and voids go
  through their real submit endpoints. Each planted type's write path has its own dedicated proof file
  (`FeeScheduleChangeTest`, `DiscountPolicyTest`, `OpeningBalanceApprovalGateTest`); what is under
  test here is the read. A reviewer who thinks the read should be proven against
  Action-produced rows rather than planted ones has a fair point and it is a cheap change.
- **Opening-balance rows are not decidable anywhere yet.** That is step 5b, and it is the single
  largest thing a user of this screen will notice.

---

## Findings raised, not fixed

- `app/Finance/Http/Resources/DiscountPolicyChangeResource.php:24-27` — `value_minor` /
  `value_currency` are emitted as **raw columns**, not through the `Money` VO wire shape, and this
  table is one of the two whose `*_currency` does not cast through `MoneyCast` (a known, recorded
  exception). Pre-existing; I did not touch it, and set the queue's `amount` to null rather than
  route a discount *rate* through `formatNaira`. **ticket.**
- `app/Finance/Http/Controllers/FeeScheduleChangeController.php:60` and its discount twin — `pending()`
  orders by `id` **ascending** while the two working feeds order `id` **descending**. Harmless today
  because the page re-sorts the merged set by `created_at` descending, but it means the API's own
  ordering is inconsistent across five feeds that claim to be one shape. Left alone deliberately: the
  brief scoped this to a read normalisation and changing an existing feed's order is a contract change
  nobody asked for. **ticket.**
- `app/Finance/Policies/*Policy.php` — none of the four approve/reject policies checks `status`, so
  `can_approve` is `true` on an already-decided record for any non-maker holding the ability. Invisible
  on the queue (it only lists `submitted` rows) and the Actions refuse the transition, so this is a
  flag-accuracy issue on the other surfaces that use these resources, not a hole. Pre-existing across
  all four, uniformly. **ticket.**
