# Report — editing a draft fee schedule (U1 domain prerequisite)

**Branch:** `feat/fee-schedules-page` · **Base:** `staging` @ `f9abe6a` · **Commit:** `b772147` ·
**PR:** #234, open, not merged · **Shape:** 8 files, one commit, no migration.

Written for someone who did not do the work. Every claim below is checkable against the repo.

---

## Deviations from the brief, first

**1. The brief's premise about the unique key was stale, and it changed the finding.**
The brief said "the unique(school_id, term_id, class_level_id) key binds only ACTIVE rows via the
generated columns, so multiple drafts for one key are permitted at the database." That was true
until S1 4a. `database/migrations/2026_07_29_120000_finance_fee_schedule_pending_approval_state.php`
dropped `finance_fee_schedules_draft_unique` and created `finance_fee_schedules_pending_unique` over
generated columns defined `IF(status IN ('draft','pending_approval'), …)`. At most ONE open schedule
per slot. That is what made a draft occupy its own slot and turned "no edit path" from an
inconvenience into a brick.

**2. Three checks were built; only two are load-bearing, and the code now says so.**
The brief asked for #232's three-layer discipline. The pre-lock state check in
`EditFeeScheduleDraft::handle` is NOT independently load-bearing: removing it alone leaves all 12
arms green, because the locked re-read refuses identically a few microseconds later. This was
measured (RED F below), and the comment at `app/Finance/Actions/EditFeeScheduleDraft.php:53-57` and
the test file header state it plainly rather than claiming three controls.

**3. The `fee_item_id` rule was rewritten after `bin/ci-boundary-lint.php` refused the first shape.**
Details in "The gate caught me" below. The first shape is described in the code comment because the
reason it was wrong is the useful part.

**4. Two existing test defects were found and fixed** — `ReductionEnforcementTest`'s helper billed
from a draft, and proof 17 was passing on a 422 that was not its own. Detail below. Neither is a
weakening; both are tightenings, but both touched a test I was not asked to touch.

---

## The four decisions the brief asked me to argue

**1. Wholesale or diffed?** Ruled WHOLESALE by the project lead after I reported the evidence.
`finance_invoice_lines.fee_item_id` IS written (`app/Finance/Actions/GenerateInvoice.php:218`), which
tripped the brief's fuse, but: no FK constraint exists (`information_schema` on the copy returned 0),
the column is declared `nullable()` with `// LOOKUP provenance; no target in the skeleton`
(`2026_07_19_100000_create_fee_invoices_tables.php:76`), `docs/finance-data-ownership.md:53`
classifies it LOOKUP with description and amount SNAP beside it, `app/Finance/Models/InvoiceLine.php:16`
says "the display never joins to a mutable fee row", and a draft's items cannot be cited by any
legitimate path because `prefill` resolves through `FeeScheduleLookup::activeFor`. The copy holds
zero invoice lines today.

**2. The guard.** Pre-lock refusal, locked re-read, DB triggers — see deviation 2 for the honest
accounting of which of the three actually hold it up.

**3. What rejecting a publish does.** It returns the schedule to `draft`, under lock, in the same
transaction: `app/Finance/Actions/RejectFeeScheduleChange.php:43-48`. The state model has a LOOP, not
a dead end. Its docblock is the commit's justification and is quoted in the commit message.

**4. The route's verb and path.** `PUT /v1/finance/fee-schedules/{feeSchedule:uuid}/draft`. A
sub-resource rather than a second verb on the collection member, because the two operations differ:
this one mutates the bound row, `supersede` leaves it alone and authors a new draft beside it. PUT
because items are replaced wholesale, which is a full replacement, not a patch.

**The existing PUT is renamed `update` → `supersede`, URI unchanged.** Both route fixtures key on
`METHOD /uri` (`tests/fixtures/route-middleware-baseline.json:2050`,
`tests/fixtures/route-access-map.json:3609`), so the rename churns no fixture entry; the wayfinder
action regenerates and is gitignored (`.gitignore:10`). Nothing in `resources/` imports it by hand.

---

## The gate caught me, and the first shape was wrong

`GenerateInvoiceRequest`'s new `fee_item_id` rule was first written as `Rule::exists(FeeItem::class,
'id')->where('school_id', ActiveSchool::id())` plus a status subquery. RED D did not fire: deleting
the `school_id` term changed nothing, because the Eloquent subquery carried `SchoolScope` and was
doing the isolation invisibly. My comment claimed the opposite. I made the subquery
`withoutGlobalScopes()` so the school term became the only isolation — and `bin/quality` step 7
refused it:

```
boundary-lint: 1 NEW boundary violation(s):
  ✗ finance-escape-hatches  app/Finance/Http/Requests/GenerateInvoiceRequest.php
```

The lint was right. The escape hatch existed to make a redundant check testable, which is a reason to
delete the redundant check, not to open the hatch. (`bin/ci-boundary-lint.php:136` also bans the
`DB::table(` form of the same evasion, so both ways out were closed.) The rule is now a closure
reading through `FeeItem::query()` — `SchoolScope` IS the isolation, as Constitution §5 has it
everywhere else — with an explicit status check beside it. Both terms are independently red-able.

**Why not restricted to ACTIVE.** Two legitimate paths bill from a superseded schedule: void-and-rebill
(the Action's own message tells the operator to do this) where a publish was approved in between, and
the plain race of a form prefilled before `ApproveFeeScheduleChange:87` moved the previous active to
`superseded`. `GenerateInvoice::resolveDiscountability` already tolerates a superseded id — it applies
no status filter — so closing that door here would contradict it. What is closed is `draft` and
`pending_approval`.

**One pre-existing gap left open, deliberately:** `lines.*.discount_policy_id` is still
`['sometimes','nullable','integer']`. The DB `reduction_guard` is the stated authority there. Not
touched; not in scope.

---

## Watched reds — six mutations, each verified landed

| # | Mutation | Result |
|---|---|---|
| A | Remove the locked `assertDraft($locked)` only | **RED** — 1 arm. `QueryException` instead of `BusinessRuleException`: without it the write reaches the trigger and surfaces as a 500. |
| B | Remove BOTH Action state checks | **RED** — 5 arms. All four state arms 500-instead-of-422; the DB-backstop arm stayed green, proving those arms test the Action. |
| C | Delete the `del` trigger from the 4a migration's `up()` | **RED** — 1 arm, the backstop. Running DB confirmed only `_ins` and `_upd` present. |
| D | Remove the isolation term from the `fee_item_id` rule | **RED** — 1 arm. 201 instead of 422: a foreign School's item billed. |
| E | Remove the status check from the `fee_item_id` rule | **RED** — 1 arm. 201 instead of 422: a draft's item billed. |
| F | Remove the pre-lock check only | **GREEN 12/12** — the measurement behind deviation 2. |

**Two of these did not work the first time, and that is the useful part of the table.**

- **RED C, first attempt: the mutation LANDED and was INERT.** I renamed the trigger to
  `…_del_DISABLED` in the migration and confirmed the rename in the running database
  (`information_schema.TRIGGERS` showed the new name). The suite still passed — because a renamed
  MySQL trigger still fires. Verifying that a mutation landed is not the same as verifying it
  changed behaviour. Redone as a deletion.
- **RED D, first attempt: did not fire, and the code was wrong, not the test.** See the section above.

---

## Test changes to files I was not asked to touch

`tests/Feature/Finance/ReductionEnforcementTest.php`:

- **`reFeeItems` left the schedule a DRAFT**, and proofs 16 and 17 billed from it — which no real
  path can do. The new rule caught it. The helper now publishes by raw status write, the way the rest
  of the suite moves a lifecycle it is not testing. This makes the fixture more realistic, not the
  assertion weaker.
- **Proof 17 was passing for the wrong reason.** It asserted a bare 422; once `fee_item_id` started
  rejecting the request for an entirely different reason, it kept passing. It now pins the message
  (`'at least one charge line'`). Written with `str_contains(...)` + `toBeTrue($message)` rather than
  `toContain($needle, $message)` — Pest treats `toContain`'s second argument as another NEEDLE, which
  is a trap this repo has hit before and which I hit again while writing it.

`app/Finance/Models/FeeItem.php` gained a `@return BelongsTo<FeeSchedule, $this>` docblock. Larastan
resolved the bare `BelongsTo` to `Model`, so `$item->schedule->status` was an undefined-property
error. The generic is the fix; the alternative would have been a cast at the call site.

---

## Verification, raw

`bin/quality` — **PASS 14/14**, 19:30:07 → 19:37:53 (466 s, inside the ~350–440 s band's shoulder;
load average 2.45 at start). Two prior runs went red and both were real, not flakes: the boundary-lint
violation above (3 failures, one root) and the Larastan property error (1 failure). Suite artefacts
for the reds are at `/var/folders/…/quality-runs/` per the run banners.

`tests/Feature/Finance/EditFeeScheduleDraftTest.php` — 12 arms, 50 assertions, green.
`tests/Feature/Finance` — 457 tests before the fixture fix, with exactly one break, which was the
informative one.

**Drive on `portal_drive`**, through the real kernel with the real middleware stack:

```
1. CREATE draft => 201 label=Term 1 v1 items=2
   item#3 Tuition 15000000 -> Boarding account
   item#4 Boarding 4000000 -> Drive account

2. EDIT draft => 200 label=Term 1 v1 (corrected) items=3
   item#5 Tuition 16500000 -> Boarding account
   item#6 Boarding 4000000 -> Drive account
   item#7 Uniform 750000 -> Boarding account
   schedules for this slot: 1          <- the row was edited, not superseded

3. SUBMIT for approval => 201   schedule status now: pending_approval

4. APPROVALS QUEUE (as the ED) => 200  entries=1
   {"kind":"publish","target_label":"Term 1 v1 (corrected)","status":"submitted", …}
```

And the corrected message, in the running app, against the now-occupied slot:

```
occupied slot => 422
A draft or pending schedule already exists for this term and class level. Edit that draft
instead, or submit it for approval; if it is already awaiting approval, await the decision.
```

---

## What I did NOT do

- **No review of my own work.** No `finance-reviewer` subagent was spawned: this session is under a
  standing instruction not to invoke agents unless asked. This report is therefore unreviewed.
  **Full-review tier** — it touches money validation, `school_id` isolation, a lint, and a fixture
  oracle. Recommend a cold session before merge.
- **U1's page is not here.** One Action, one route, one request reuse, one message correction, their
  tests, one drive — the fuse, held.
- **The drive fixture seeds no academic slot** (`terms: 0  levels: 0  sessions: 0` in `portal_drive`),
  so a fee schedule cannot be authored on it out of the box. The drive script created a term and
  class level itself rather than expanding the committed fixture. **U1's page commit will need this
  in `DriveFinanceStates`**, or its drive will hit the same wall.
- **The ED's queue read had to run in its own process.** In a single process the spatie permission
  cache answered for whichever user called last and returned 403 for a user who holds the permission.
  That is a property of the drive harness, not of the app under a real request.
- **The pre-lock check has no arm of its own** — by construction, since RED F shows there is nothing
  to pin. If a reviewer thinks it should be deleted rather than documented, that is a defensible call
  I did not make.
