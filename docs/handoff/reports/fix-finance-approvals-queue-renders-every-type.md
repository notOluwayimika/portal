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

- `app/Finance/Http/Resources/DiscountPolicyChangeResource.php` (`:20-21` on `a7d24f1`, `:45-46` on this branch — an earlier draft of this report cited `:24-27`, which is neither) — `value_minor` /
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

---
---

# Round 2 — the review's findings, worked

Second commit on the same branch. `bin/quality` re-run and green; twelve tests in the two new files,
**eleven watched reds** this round on top of round 1's four.

## Headline

The reviewer's finding 1 was right and is fixed at the level it should be: not "label these two
types", but **the declared list now carries the rule** — `subject` is a required member of
`ApprovalFeed`, so the sixth type cannot be added without answering "which thing is this row about",
and the coverage test fails an entry that declares none. All three tickets closed. One of my own
docblock claims turned out to be false and the database said so; one of my watched-red *mutations*
turned out not to mutate anything, which is the same pattern the lead named and is written up below.

## The premise the database corrected

I wrote, in three files, that `name` is null on a discount-policy **amend** and a **retire**. It is
not. `finance_discount_policy_changes_terms_shape`
(`database/migrations/2026_07_26_140001_create_finance_discount_policy_changes.php:76-84`) reads:

```sql
(kind = 'retire' AND name IS NULL AND basis IS NULL AND requires_approval IS NULL)
OR (kind <> 'retire' AND name IS NOT NULL AND basis IS NOT NULL AND requires_approval IS NOT NULL …)
```

So a create and an amend **must** name themselves, and a **retire must not** — it carries no name, no
basis and no value at all, and its only content is `target_policy_id`, an internal integer nobody
renders. The retire is therefore the unidentifiable row, and it is worse than I described: an amend at
least states its own new terms. I found this because the arm I wrote against an amend was refused by
the engine with SQLSTATE **3819** on insert. Corrected in the resource, the feed module, the type and
the test; the arm is now a retire.

## What changed (round 2)

- `app/Finance/Models/FeeSchedule.php` — `term()` and `classLevel()` belongsTo. Read-side only, both
  School-scoped. The class docblock's "never joined for display" is about BILLING (prices are
  snapshotted onto invoice lines); naming a pending change's target is not that, and no price is read
  through either.
- `app/Finance/Models/DiscountPolicyChange.php` — `@return BelongsTo<DiscountPolicy, $this>` on
  `target()`. Not cosmetic: without it Larastan level 5 reads `$this->target` as a bare `Model` and
  fails any property read on it. It had no caller until now, which is why it never had to be right.
- `FeeScheduleChangeResource` — `target_label`, `target_class_level`, `target_term`.
- `DiscountPolicyChangeResource` — `target_policy_name`.
- Both `pending()` — `target` eager-loaded (see the N+1 note below).
- `resources/js/lib/finance/approval-feeds.ts` — `rowLabel` → **`subject`, required**; per-type
  renderers compose from wire fields; `decidedElsewhere` reworded.
- `resources/js/pages/admin/finance/approvals.tsx` — `rowSubject`; the column header is now
  **Subject**.
- `resources/js/types/finance.ts` — the new wire fields.
- `ApprovalsQueueFeedCoverageTest` — two new tests (wiring aliases, subject required) and
  `aqfFeedsArrayBody()`.
- `ApprovalsQueueRendersEveryTypeTest` — ARM 5 (two schedules, tellable apart) and ARM 6 (a retire
  names its policy).

### The subject renderers, verbatim

```ts
// fee_schedule_change
subject: (row) => {
    if (row.type !== 'fee_schedule_change') { return '—'; }
    const pair = [row.target_class_level, row.target_term].filter(Boolean).join(' · ');
    const named = [pair, row.target_label].filter(Boolean).join(' — ');
    const fallback = `#${row.target_schedule_id?.slice(0, 8) ?? 'unknown'}`;
    return `${row.kind} · ${named === '' ? fallback : named}`;
},

// discount_policy_change
subject: (row) => {
    if (row.type !== 'discount_policy_change') { return '—'; }
    const value = discountValue(row);                       // `10%`, or formatNaira(...)
    const policy = row.name ?? row.target_policy_name ?? 'unnamed policy';
    return `${row.kind} · ${policy}${value === null ? '' : ` · ${value}`}`;
},
```

The uuid-tail fallback is the brief's `#47` allowance and is not expected to fire: the parts are
proven present and populated over HTTP in ARM 5. A discount **value** is rendered in the subject and
still never in the money column — a rate is not money moving.

### The list entry shape, verbatim

```ts
export type ApprovalFeed = {
    type: PendingApproval['type'];
    label: string;
    badgeClass: string;
    pendingUrl: () => string;
    decide?: {
        approve: (id: string) => string;
        reject: (id: string) => string;
        approvedMessage: string;
    };
    decidedElsewhere?: string;
    /**
     * WHICH thing this row is about, in human terms. REQUIRED — see the module docblock. It must
     * distinguish two pending rows of the same type from each other; a bare type name does not.
     */
    subject: (row: PendingApproval) => string;
};
```

`subject` required, `decide` optional — and the module docblock now states the asymmetry explicitly:
**`decide` may be withheld only because the endpoint does not exist. It is never the answer to a row
that is hard to label** — withholding it there reproduces this branch's own defect, and the fix for a
hard label is to put the subject on the wire.

## The pattern, named

The lead asked for this by name because it is now its fourth appearance on one branch. The pattern is
**an assertion that is green for a reason other than the one it claims**, and it has four distinct
faces here:

1. **Exiting through a guard instead of the claim.** ARM 3 claims "all five types reach a checker in
   the one shape the queue consumes". Round 1's RED 4 (bare-array envelope) made it fail on its
   *first* line — `not->toBeNull` — so the `toHaveKeys` half, which is what the test is named for,
   had never been shown to fail. Closed this round: RED 15 drops `can_reject` from one resource and
   the failure is now `Failed asserting that an array has the key 'can_reject'`. And the message
   string printed where an operand normally sits (`Expecting null not to be null '/api/v1/…ng row'`)
   is why that output reads as though a key were compared against a sentence.
2. **Counting the declaration instead of the data.** The first subject test counted `^\s+subject: `
   over the whole module and read **6 renderers for 5 entries** — the `ApprovalFeed` type's own
   member matched. Had the count gone the other way it would have been a permanent silent pass.
   Closed by `aqfFeedsArrayBody()`, which narrows to the array literal.
3. **A mutation that does not mutate.** RED 12's first form removed `->with(['submitter',
   'target.term', 'target.classLevel'])` and the test **stayed green**. Not a flake and not a bad
   assertion: the line above the new fields, `'target_schedule_id' => $this->target?->uuid`,
   lazy-loads `target` during `toArray()`, so `whenLoaded('target', …)` is *always* satisfied and the
   closures lazy-load `term` and `classLevel` in turn. The eager load is an N+1 fix and nothing more.
   **My controller docblock claimed otherwise and was wrong**; it now says what is true, and the red
   mutates the RESOURCE instead.
4. **Pinning one half of a two-half invariant.** The reviewer's finding 4: the coverage guard read
   imports and not wiring, so aliasing `void`'s `pendingUrl` at `pendingCredit.url()` was green while
   pending voids rendered nowhere. Closed by the alias multiset test — and watched red with exactly
   that aliasing (RED 7).

The lesson is not "be careful", which is unenforceable. It is that **an assertion must be shown to
fail for the reason it claims**, and the watched red is the only thing that shows it. Three of these
four were found *by* watching a red, not by reading the code: instance 3 in particular was invisible
to inspection and would have shipped as a false guarantee in a docblock.

## The watched reds — round 2

Eleven mutations, each run and each restored. Backups in the scratchpad; `git status` clean of
mutations after each; the 12/12 green below is post-restore. Output is the raw pest reporter line,
truncated to the failure message.

**RED 5 — phantom branch** (the "both directions" claim that had only been proven in one). Deleted
the opening-balance route, left the entry in the list:

```
The approvals queue declares a feed with no registered route: OpeningBalanceBatchController
— the page would fetch a URL that does not exist.
```

**RED 6 — duplicate entry.** Added a second `credit_note` entry:

```
APPROVAL_FEEDS has 6 entries for 5 registered pending routes.
```

**RED 7 — wiring, the reviewer's finding 4.** `void`'s `pendingUrl` aliased at the credit feed:

```
The declared feed list imports [pendingCredit, pendingDiscountChange, pendingOpeningBalance,
pendingScheduleChange, pendingVoid] but its entries fetch [pendingCredit, pendingCredit,
pendingDiscountChange, pendingOpeningBalance, pendingScheduleChange]. A duplicate on the right
means one type is fetched twice and another never — its rows render on no screen.
```

**RED 8 — a feed with no subject.** Renamed one entry's `subject:` key:

```
APPROVAL_FEEDS has 5 entries and 4 subject renderers. A feed with no subject renders rows that
name their TYPE and not the thing being approved, which is a second signature given to something
the checker cannot identify.
```

**RED 9 — the page reaches for a hardcoded import again.** Added one controller import to
`approvals.tsx`:

```
Failed asserting that two arrays are identical.
-Array &0 []
+Array &0 [ 0 => 'CreditNoteController' ]
```

**RED 10 — ARM 1.** Ungated the fee-schedule feed, so a credit-only checker 403s on three of four:

```
Expected response status code [403] but received 200.
```

**RED 11 — ARM 3b.** Forced `can_approve => true` on the opening-balance resource:

```
     'discount_policy_change' => true,
-    'opening_balance' => false,
+    'opening_balance' => true,
```

**RED 12 — ARM 5, first form: GREEN. See the pattern above.** Removing the eager load changed no
output. Second form — removed the three `target_*` fields from `FeeScheduleChangeResource`:

```
Failed asserting that null is of type string.
```

**RED 12b — ARM 5's second half, which the mutation above never reached.** Replaced the three
subject parts with per-type constants: present, populated, and identical across both rows.

```
two pending fee-schedule changes render identically: publish|a class|a term|Fee schedule
Failed asserting that actual size 1 matches expected size 2.
```

This is the reviewer's finding 1 reproduced exactly, and the assertion that refuses it.

**RED 13 — ARM 6.** Dropped `target` from the discount eager load — here it genuinely is
load-bearing, because nothing else on that resource touches the relation:

```
Failed asserting that null is of type string.
```

**RED 14 — ARM 7, School isolation on the new endpoint.** `->withoutGlobalScopes()`:

```
Failed asserting that actual size 2 matches expected size 1.
```

**RED 15 — ARM 3's shape half** (pattern instance 1). Dropped `can_reject` from one resource:

```
Failed asserting that an array has the key 'can_reject'
```

Every assertion in both new files has now been watched red. The docblock claim that the guard works
"in BOTH directions" is demonstrated rather than asserted.

## Tickets closed

- **Reviewer 2** — `decidedElsewhere` no longer names a screen that does not exist. It reads
  **"No decision screen yet — §9 step 5b"**. Naming a screen sends the approver somewhere, which is
  the same dishonesty as a dead button.
- **Reviewer 3** — closed by the eleven reds above rather than by striking the claim.
- **The citation** — `DiscountPolicyChangeResource`'s raw `value_minor` / `value_currency` are at
  `:20-21` on `a7d24f1` and `:45-46` on this branch. Corrected in the findings section above.

## Proof — round 2

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/ApprovalsQueueFeedCoverageTest.php tests/Feature/Finance/ApprovalsQueueRendersEveryTypeTest.php
{"tool":"pest","result":"passed","tests":12,"passed":12,"assertions":157,"duration_ms":16875}
```

`bin/quality` **failed first**, and the failure is worth pasting rather than hiding — it is the
Larastan error the new `target` relation caused, and the reason the generic annotation above is not
cosmetic:

```
[13/14] static analysis (Larastan level 5 vs baseline)
   ✗ larastan
       {"tool":"phpstan","result":"failed","errors":1,"error_details":{
         ".../app/Finance/Http/Resources/DiscountPolicyChangeResource.php":[{"line":42,
         "message":"Access to an undefined property Illuminate\\Database\\Eloquent\\Model::$name.",
         "identifier":"property.notFound"}]}}
✗ quality: FAIL (1): larastan
```

After annotating `DiscountPolicyChange::target()`:

```
[1/14] … ✓ dependency-integrity-lint
[2/14] … ✓ wayfinder:generate
[3/14] … ✓ lint-changed
[4/14] … ✓ tsc-ratchet
[5/14] … ✓ build
[6/14] … ✓ authz-lint
[7/14] … ✓ boundary-lint
[8/14] … ✓ grants-convergence-lint
[9/14] … ✓ money-lint
[10/14] … ✓ runtime-zero-lint
[11/14] … ✓ identifier-generation-lint
[12/14] … ✓ arch
[13/14] … ✓ larastan
[14/14] … ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

No fixture regeneration this round: no route was added, moved or re-gated, and `git diff` on
`tests/fixtures/` is empty since round 1.

## Not done — round 2

- **Still no browser drive.** Unchanged from round 1, and now carrying more: the subject strings are
  composed in TypeScript and nobody has read one on a rendered page. What is proven is that every
  part they compose from is on the wire, populated, and different per target (ARM 5, ARM 6). The
  composition itself is unproven — there is no JS test runner to prove it with, and that is exactly
  the seam pattern instance 3 lives in.
- **The reviewer's ordering ticket is still open** — `pending()` orders `id` ascending on the two
  change feeds and descending on the two working ones. Out of scope, unchanged, still worth a ticket.
- **I did not re-spawn the reviewer.** Round 2 is a response to its findings; a second pass by the
  same subagent on the same branch is worth less than the cold session the headline already
  recommends.
