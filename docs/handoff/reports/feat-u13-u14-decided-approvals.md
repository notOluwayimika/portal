# U13 + U14 — the decided-approvals read, the registry field, and the decisions surface

Branch `feat/u13-u14-decided-approvals`, four commits off `origin/staging` at `d174fc2`.
Every identifier in this report is `user#<id>` / `school#<id>`; the screenshots render names,
this file does not.

---

## 1. The premise, checked before building on it

**Claimed:** every credit-note and void-request read route is `/pending`; nothing returns a decided
one; once a checker decides, the document appears on no list anywhere.

**The first two halves hold.** `routes/endpoints/finance.php` at `d174fc2` registered five
`…/pending` reads and no `…/decided` read of any kind. Derived from the router rather than by
reading the file:

```
$ php artisan route:list --path=finance | grep -iE "pending|decided"
 GET|HEAD api/v1/finance/credit-notes/pending
 GET|HEAD api/v1/finance/discount-policy-changes/pending
 GET|HEAD api/v1/finance/fee-schedule-changes/pending
 GET|HEAD api/v1/finance/opening-balance-batches/pending
 GET|HEAD api/v1/finance/void-requests/pending
```

**The third half is narrower than the brief states, and the correction matters, because it is the
whole basis of the permission ruling in §4.** A decided credit note or void request *is* already
readable — on the per-student statement. `InvoiceController::forStudent` emits `credit_notes` and
`void_requests` (`app/Finance/Http/Controllers/InvoiceController.php:215-222`) from
`creditNotesForStudent()` / `voidRequestsForStudent()`, **neither of which filters on status**
(`app/Finance/Services/InvoiceReadModel.php:181-188`, `:232-239`), under a route carrying no
middleware beyond the API group's `finance.access`
(`routes/endpoints/finance.php`, the `students/{student}/invoices` line). `statement.tsx` renders
both: a `credit_notes` tab (`resources/js/pages/admin/finance/statement.tsx:133-135`, `:623-654`)
and void requests folded into the invoices view (`:119`).

I searched every finance surface for a credit-note or void-request read
(`grep -rn "credit_note|creditNote|void_request|voidRequest|credit-note|void-request"` over
`resources/js`, excluding the generated `actions/` and `routes/` trees). The complete set of
consumers: `approvals.tsx` (pending only, via the feed registry), `statement.tsx` (per student, all
statuses), `invoice.tsx` and the two submit modals (write paths), and `index.tsx` (the queue link's
ability check). **No school-wide list of decided documents existed anywhere.**

So the accurate statement of the gap is: **not secrecy, findability.** To read a decided credit note
you had to already know which student it belonged to.

**And one thing the brief did not claim, which is worse and is the reason the checker column exists:**
`decided_by` reached no wire anywhere. `CreditNoteResource` and `VoidRequestResource` emitted
`decided_at` and `rejection_reason` and **no checker**; the two `decidedBy()` relations existed on the
models (`app/Finance/Models/CreditNote.php:128`, `app/Finance/Models/VoidRequest.php:81`) and were
loaded by nothing. **"Who approved this" was unanswerable on every surface in the application** —
including the statement, which shows the maker alone.

---

## 2. Ruling 2 — the measurement, and why I did not wire all five

The ruling was two wired plus three declared absences, unless measurement showed all five cheaper.
**It does not.** Three findings, in decreasing order of weight.

**(a) Precedent covers exactly two of the five.** Under ruling 3 a decided feed sits on
`finance.access`. For credit notes and voids that re-serves rows `finance.access` **already reaches**
(§1). For the other three there is no such precedent: fee-schedule changes and discount-policy
changes are readable today only under `…change.approve`
(`routes/endpoints/finance.php`), and opening-balance batches only under
`finance.opening-balance.submit` / `…approve`. Putting any of them on `finance.access` is a **fresh
exposure decision**, which is a ruling to be taken, not a template to be applied. That is the cost
that does not appear in a line count.

**(b) One of the three is not the same build at all.** `OpeningBalanceBatchStatus` has **no
`Approved` case** — approval goes straight to `Posted`
(`app/Finance/Enums/OpeningBalanceBatchStatus.php`) — so "decided" for that type is
`{posted, rejected}`, a different predicate from the other four's `{approved, rejected}`. Its checker
column is `decided_by_user_id`, documented as **"the CHECKER — LOOKUP, not an FK"**
(`app/Finance/Models/OpeningBalanceBatch.php:72`), so there is no `decidedBy()` relation to
eager-load and no `whenLoaded` to hang the name on.

**(c) The remaining two are cheap but not free.** Both tables **do** carry `decided_at`
(`database/migrations/2026_07_28_120000_create_finance_fee_schedule_changes.php:50`,
`database/migrations/2026_07_26_140001_create_finance_discount_policy_changes.php:49`) — **no
migration is needed**, which I checked because it would have been the decisive cost if absent. But
neither model casts `decided_at` nor declares `decidedBy()`
(`app/Finance/Models/FeeScheduleChange.php`, `app/Finance/Models/DiscountPolicyChange.php` — the
`@property` blocks list `decided_by` and the relation methods are `target()` and `submitter()` only),
and neither resource emits any decision field. So each is a model edit, a resource edit, a read
method, a controller method and a route — the same per-type cost as the two in scope, times three,
on top of three exposure decisions nobody has taken.

**Conclusion: two wired, three declared absent with the reason in words.** Built as ruled.

---

## 3. What was built

**Commit 1 — the read side** (`85f614a`).
`InvoiceReadModel::decidedCreditNotes()` / `decidedVoidRequests()`; `decided()` on both controllers;
`GET /api/v1/finance/credit-notes/decided` and `…/void-requests/decided`; `decided_by_name` and
`invoice_kind` on both resources.

`decided_by_name` goes through `whenLoaded('decidedBy')`, which only the decided reads load, so the
pending queues serve **exactly** what they served before — the key is absent rather than null, which
keeps "this document has no checker" and "the checker is unknown" distinct on the wire. Arm 7 in §6
pins that.

**Commit 2 — the registry** (`825b3b6`).
`decidedUrl?` and `decidedNotImplemented?` on `ApprovalFeed`, on the same entries as `pendingUrl`.
Credit note and void wire the url; the other three carry the sentence. `DECIDED_FEEDS` is derived by
filtering, never listed.

The pairing is the design. `decidedUrl?: () => string` alone is satisfied by an entry somebody meant
to wire and did not, exactly as it is satisfied by one whose feed does not exist — one is a defect,
the other a decision, and an optional member cannot tell them apart. The coverage test requires
**exactly one** of the two on every entry.

**Commit 3 — the surface** (`a5e09aa`).
`resources/js/pages/admin/finance/decisions.tsx`, `GET /finance/decisions` (no middleware beyond the
web group's `finance.access`), and the sidebar entry. A pure consumer of the same registry, with no
per-type knowledge: badge, label and subject come off the feed entry.

**Commit 4 — the drive** (this one). Two count-table columns for the fixture, the drive captures, the
defect the drive found in commit 3 (§7), the test that pins it, and this report.

---

## 4. Ruling 3 — the permission, and the reasoning

**Proposed and built: the API group's `finance.access`, with no route-level middleware of its own.
Nothing new coined.**

**Why not the approve ability.** `/pending` carries it because that route **precedes an act** — it is
the checker's worklist and every row on it is a thing they are about to do. `/decided` precedes
nothing: both statuses are terminal (`CreditNote::TRANSITIONS` lists `approved` and `rejected` with
empty successor sets, `app/Finance/Models/CreditNote.php:74-78`), the money has already moved or has
already been refused, and no route in `routes/endpoints/finance.php` can move either row again. The
seat that reconciles a term's corrections is not the seat that signs them.

**Why not a new permission.** A `finance.credit-note.view-decided` would gate a set `finance.access`
already reaches (§1). Coining one would also mean a seeder edit and a regeneration of
`rbac-grants-baseline.json` and `route-access-map.json` — the surfaces the RBAC ownership protocol
reserves — to buy a boundary that is already crossed. That is the "convention with no mechanism"
failure inverted: a mechanism protecting nothing.

**The precedent, read rather than remembered.** U11's receipt route carries the identical argument
one door along, in `routes/web.php`: *"NO EXTRA MIDDLEWARE, deliberately: it takes the group's
`finance.access` … `finance.payment.record` is the authority to TAKE money; this is a read of money
already taken."*

**ADR 0048 read before choosing, as instructed.** Its D1 argues money-in deserves its own capability
**because it moves receivables** — the 2026-08-01 correction is explicit that the exposure is
"a fabricated payment … discharges real receivables". That argument is about acts that move money.
A read moves nothing, and D1's own reasoning therefore does not reach it.

**What is genuinely new on the wire is the CHECKER's name.** It is the maker's counterpart; the
maker's name has been served under `finance.access` since Ph3 (`submitted_by_name` on both
resources); and an audit trail naming one of two signatures answers nothing.

**One consequence I did not anticipate and am stating rather than burying: `super_admin` reaches
`/finance/decisions` and is still refused `/finance/approvals`.** ADR 0040 excludes `super_admin`
from CHECKER abilities, so the `Gate::before` bypass applies to `finance.access` and does not apply
to `finance.credit-note.approve`. Both halves are asserted on one user in
`ApprovalsPageGateTest`. Whether a `super_admin` should read decisions is a decision, not a
derivation, and it is the project lead's — I am naming it, not settling it.

---

## 5. What the surface shows

Every field the brief listed, read off the rendered table in §8:

| Required | Column | Source |
| --- | --- | --- |
| the document and its number | **Document** | `rowSubject(row)` off the feed entry — `CN-000004`, `Void · 000008` |
| the invoice it acts on **with its kind** | **Invoice** | `invoice_kind` badge + `invoice_display_number`, through `@/lib/finance/invoice-kind` |
| the amount | **Amount** | `formatNaira(row.amount)` |
| the maker | **Submitted by** | `submitted_by_name` |
| the checker | **Decided by** | `decided_by_name` |
| the decision | **Decision** | `status`, emerald `approved` / rose `rejected` |
| the decided-at | **Decided** | `decided_at` |
| the reason on a rejection | **Reason** | `rejection_reason`, falling back to the maker's `note` on an approval |

Maker and checker are **columns**, not a detail view. There are no action controls on the page and
there must not be: both statuses are terminal and a button to reverse one would offer something no
route in the application can do.

---

## 6. The proofs, and the mutation each one caught

Every arm below was bite-proved: plant the regression, watch it go red, restore, re-run green. The
restore was verified by `git diff --stat` on the mutated file being empty before continuing.

### 6a. `tests/Feature/Finance/DecidedApprovalsFeedTest.php` — 7 arms, HTTP, rows driven not planted

Green: `{"result":"passed","tests":7,"passed":7,"assertions":177,"duration_ms":19825}`

| # | Arm | Mutation | Red |
| --- | --- | --- | --- |
| 1 | reader gets 200 on decided **and** 403 on pending, one seat | added `permission:finance.credit-note.approve` to the decided route | `Expected response status code [200] but received 403.` |
| 2 | only approved + rejected, never pending | added `Submitted` to the `whereIn` | `Failed asserting that actual size 3 matches expected size 2.` |
| 3 | both signatures named, never the same person | dropped `decidedBy` from the eager load | `Undefined array key "decided_by_name"` |
| 4 | invoice named with its kind; reason only on a rejection | hardcoded `'invoice_kind' => 'scheduled'` | `-'supplementary' +'scheduled'` |
| 5 | school isolation, asserted by id | `CreditNote::query()->withoutGlobalScopes()` | `Expecting […] not to contain 'a2909c4c-…'.` |
| 6 | ordered by the decision, newest first | `orderByDesc` → `orderBy` | the two uuids came back in the opposite order |
| 7 | pending payload unchanged — no checker key | added `decidedBy` to the **pending** eager load | `Expecting […] not to have key 'decided_by_name'.` |

Arm 1 is the one worth reading twice. The 200 alone would pass just as happily if the decided route
had silently inherited the approve middleware from the line above it; the 403 on the same seat in
the same test is what makes it an assertion about the **asymmetry** rather than about access.

Arm 3 also reads `decided_by` back out of `finance_credit_notes` and `finance_void_requests` by id,
so the name on the wire is tied to the row the endpoint wrote rather than to a fixture.

### 6b. `ApprovalsQueueFeedCoverageTest` — 4 new arms

Green: `{"result":"passed","tests":12,"passed":12,"assertions":46}`

| Mutation | Red |
| --- | --- |
| removed `decidedUrl` from the credit-note entry | `A DECIDED feed is live at the API and declared on NO entry: CreditNoteController` |
| removed the decided route, left the entry | `An entry declares a 'decidedUrl' with no registered route: VoidRequestController` |
| renamed `decidedNotImplemented` on the fee-schedule entry | `[fee_schedule_change] declares NEITHER a decidedUrl nor a decidedNotImplemented.` |
| added `decidedNotImplemented` beside credit-note's `decidedUrl` | `[credit_note] declares BOTH a decidedUrl and a decidedNotImplemented — one of them is stale` |
| pointed void's `decidedUrl` at `decidedCredit` | `imports [decidedCredit, decidedVoid] but its entries fetch [decidedCredit, decidedCredit]` |

The alias → controller mapping is **parsed off the import blocks** rather than derived by stripping a
verb: `pendingCredit` comes from `CreditNoteController` and no string surgery relates the two, so a
guessed suffix would have compared `Credit` against `CreditNote` and passed on nothing. It is also
why a rename cannot make the pin pass by accident.

### 6c. `ApprovalsPageGateTest` + `FinanceNavCoverageTest` — 4 new arms

| Mutation | Red |
| --- | --- |
| added the checker middleware to `/finance/decisions` | `Expected response status code [200] but received 403` on the reader-reaches-decisions arm |
| removed the sidebar entry | `A finance page is registered, permission-gated and reachable from NO menu: /finance/decisions` |
| direct controller import in `decisions.tsx` | the imports-no-controller arm went red |
| fixed-arity error rule in `decisions.tsx` | `decisions.tsx errors on a FIXED-ARITY conjunction of rejections` |
| unconditional queue button (§7) | `names 'isFinanceChecker' 1 times, not 2` |
| hardcoded checker predicate (§7) | `no longer derives the checker predicate from the ApprovalAbility convention` |

### 6d. Whole finance suite, after everything

`{"result":"passed","tests":749,"passed":749,"assertions":3929,"duration_ms":531072}`

### 6e. The gates

Commits 1 and 3 passed `bin/quality` 16/16 on the first push. Commit 2 was **blocked twice**:

1. **A real regression I introduced.** `PestNegatedExpectationMessagesTest` failed: I passed a custom
   message to `->not->toBe([], "…")` in two new arms. `->not->` discards custom messages on every
   matcher — the trap `ApprovalsQueueFeedCoverageTest`'s own comment documents at length — so the
   sentence would never have reached a failing reader. Fixed by inverting to a boolean and asserting
   positively (`expect($x !== [])->toBeTrue("…")`), the pattern that file prescribes.
2. **`SubledgerClockFrameTest`**, on the re-push, at
   `Failed asserting that 91 is identical to 90` (`:127`). Artefacts captured **before** any re-run
   (`pest-20260822-191348-13707.log`, its junit, load average 14.57, duration 807s). I then ran that
   file **in isolation 17 times: 1 failure, 16 passes.** The arm asserts
   `strtotime($secondPostedAt) - strtotime($postedAt) === 90` after `travel(90)->seconds()`, which is
   an offset from real `now()` — so a wall-clock second ticking between the first post and the travel
   makes the gap 91. Nothing in this branch's diff is in that file's path. **I did not instrument the
   race, so the mechanism is read off the test's own arithmetic rather than measured.** Retrying to
   green is exactly what this project warns is indistinguishable from fixing; what distinguishes it
   here is that it reproduces in isolation with none of this branch's code involved.

---

## 7. The defect the drive found — in my own commit 3

`decisions.tsx` offered a **"Pending approvals"** button in its toolbar to every viewer. The drive
opened the page as `user#2` (`accounts_officer`, `school#1`, holds `finance.access` and no checker
ability), and the control rendered:

```
=== SEAT maker (accounts_officer, school#1) ===
  GET /finance/decisions -> 200
  toolbar offers /finance/approvals: ["/finance/approvals|Pending approvals"]
  GET /finance/approvals -> 403
```

A control a user can see, press and fail on — the failure this codebase already names in
`approval-feeds.ts`'s `decidedElsewhere` docblock: absent is honestly broken, present-and-dead is
dishonestly broken. **No test would have caught it**: the route gate was correct, the page rendered,
and there is no JavaScript test runner here.

Fixed by deriving the same checker predicate `app-sidebar.tsx` derives, over the viewer's own
effective set — `finance.` + `.approve`/`.reject` — rather than a hardcoded ability pair, which
would hide the link from the next checker type the route already admits. Pinned by a new arm in
`FinanceNavCoverageTest` (the mirror of that file's own rule: a link must not point where its viewer
is refused), bite-proved in §6c, and re-driven:

```
=== maker (accounts_officer, NO checker ability) ===   after the fix
  GET /finance/decisions -> 200
  toolbar offers /finance/approvals: []
  GET /finance/approvals -> 403

=== checker (executive_director) ===
  GET /finance/decisions -> 200
  toolbar offers /finance/approvals: ["/finance/approvals|Approvals","/finance/approvals|Pending approvals"]
  GET /finance/approvals -> 200
```

---

## 8. The browser drive

`APP_ENV=drive`, own database, `php artisan serve --port=8001`, `pnpm build` first. Chrome driven by
`puppeteer-core` installed **outside** the repository (in the session scratchpad), never in
`node_modules`.

### 8a. Two fixture columns added first

The count tables counted nothing this screen depends on: `Payments (portal)` and `Open invoices` can
both be healthy on a fixture where nothing has ever been decided, and the surface would have opened
onto an empty table looking exactly like a broken feed. Added `Decided credit notes` and
`Decided voids` — **split by type, not summed**, because the surface merges two feeds and a fixture
with decided notes and no decided voids renders a full-looking table in which one type is absent
entirely, with the badge as the only witness. Both counted through `DriveFinanceStates` (the
boundary lint forbids a `finance_` literal outside `app/Finance`), by the same predicate the read
model uses rather than by "not submitted".

### 8b. Both count tables, verbatim from a fresh seed

```
Drive fixture seeded. Sign in at APP_URL with any user below (password: drive-password):
+--------------------------------------------+----------------------------+
| Role in the drive                          | Email                      |
+--------------------------------------------+----------------------------+
| Maker (accounts_officer)                   | maker@drive.test           |
| Full checker (executive_director)          | checker@drive.test         |
| Void-only checker (no credit-note.approve) | void-checker@drive.test    |
| Super admin                                | super@drive.test           |
| School B bursar (isolation)                | school-b@drive.test        |
| Admin (guardians screen)                   | admin@drive.test           |
| School B admin (guardian isolation)        | admin-b@drive.test         |
| Guardian editor, NO update_credentials     | guardian-editor@drive.test |
+--------------------------------------------+----------------------------+

Authoring slot per school — … the decisions surface (U13/U14) reads back what a checker has already settled:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Active schedules | Cohort at slot | Unplaceable | Decided credit notes | Decided voids |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
| A (school#1) | 2                 | 2     | 2            | 2             | 1                 | 5                 | 0                   | 2                     | 8             | 1                | 2              | 9           | 2                    | 1             |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 0                     | 1             | 1                | 2              | 1           | 0                    | 0             |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
Bulk invoice runs: /finance/bulk-invoice-runs — the cohort above sits at (term, JSS 1); JSS 2 has an empty one on purpose.

Authoring slot per school — … the guardians screen links a new guardian to students by admission number:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Students | Guardians |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
| A (school#1) | 2                 | 2     | 2            | 2             | 1                 | 5                 | 0                   | 2                     | 8             | 12       | 0         |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 0                     | 1             | 3        | 0         |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
```

`Decided credit notes 2 / Decided voids 1` for `school#1` — non-zero, so the screen has something to
render. `0 / 0` for `school#2`, which is what the isolation check in §8f measures against.
`Payments (migrated) 0` is the documented exempt column and not an abort. The two tables' shared
first ten columns agree value for value, as they must.

### 8c. `user#3` (`executive_director`, `school#1`) — the queue before, and a rejection driven through it

The fixture stages no *rejected* document of either type, so the rejection was **driven, not
planted** — submitted by the fixture as `user#2`, rejected here through the real screen.

```
  page title: Finance — pending approvals - Laravel
  QUEUE headers: ["Type","Subject","Invoice","Submitted by","Reason / note","Date","Amount","Decision"]
  QUEUE row 0: ["Credit note","CN-000003","000006","<user#2>","Awaiting sign-off","22/08/2026","₦500.00","ApproveReject"]
  QUEUE row 1: ["Void","Void · 000007","000007","<user#2>","Billed in error — awaiting approval","22/08/2026","₦2,000.00","ApproveReject"]
  reject click on credit-note row: {"found":true,"clickable":true,"subject":"CN-000003"}
  after reject — queue rows now: 1
```

**Establishes:** the credit-note row was rejected with a typed reason, and the queue dropped from 2
rows to 1 — the document *left* the pending surface, which is the state this branch exists to make
readable again. Screenshots `checker-01-pending-queue-before.png`,
`checker-02-reject-reason-dialog.png`, `checker-03-pending-queue-after-reject.png`.

### 8d. `user#3` — the decisions surface

```
  /finance/decisions title: Finance — decided approvals - Laravel
  DECISIONS headers: ["Type","Document","Invoice","Submitted by","Decided by","Decision","Decided","Reason","Amount"]
  DECISIONS row 0: ["Credit note","CN-000004","Supplementary charge000013","<user#2>","<user#3>","approved","22/08/2026","Bus route cancelled for two weeks","₦1,500.00"]
  DECISIONS row 1: ["Credit note","CN-000003","Term bill000006","<user#2>","<user#3>","rejected","22/08/2026","No supporting evidence for the hardship claim — resubmit with the letter.","₦500.00"]
  DECISIONS row 2: ["Credit note","CN-000002","Term bill000005","<user#2>","<user#3>","approved","22/08/2026","Post-payment adjustment","₦500.00"]
  DECISIONS row 3: ["Void","Void · 000008","Term bill000008","<user#2>","<user#3>","approved","22/08/2026","Duplicate enrolment","₦3,000.00"]
  DECISIONS row 4: ["Credit note","CN-000001","Term bill000004","<user#2>","<user#3>","approved","22/08/2026","Full bursary","₦3,000.00"]

  200 /api/v1/finance/credit-notes/decided -> ["credit_note:a290c3eb-d469-…","credit_note:a290c3eb-ca7e-…","credit_note:a290c3eb-bc8d-…"]
  200 /api/v1/finance/void-requests/decided -> ["void:a290c3eb-e391-4784-a30b-89995abd397f"]
```

**Establishes, one line each:**

- **An approved credit note** (rows 0, 2, 4), **a rejected one carrying the checker's reason**
  (row 1), and **an approved void request** (row 3) — the three captures the brief asked for, on one
  screen.
- **Maker and checker on every row, and they are different people** — `<user#2>` submitted all five,
  `<user#3>` decided all five. The DB CHECK and the Policy make the identity case unrepresentable;
  what the screen adds is that both are *readable*.
- **The invoice kind is on every row and it discriminates**: row 0 renders a violet
  `SUPPLEMENTARY CHARGE` badge against four indigo `TERM BILL`s. See §8e — this was staged
  deliberately, because a column where every value is identical demonstrates rendering and not
  distinction, and distinction is U7's entire point.
- **The reason appears only on the rejection as a rejection reason**; approvals show the maker's own
  note (`Full bursary`, `Post-payment adjustment`), which is the declared fallback rather than an
  empty column.
- **Amounts** through `formatNaira`: `150000` minor → `₦1,500.00`, `300000` → `₦3,000.00`. Nothing on
  the page computes money; the values are the resources' `amount_minor`.
- **The pending void `void:a290c3eb-dd27-…` is on the queue and NOT in the decided feed** — the two
  surfaces partition the documents rather than overlapping.

Screenshot: `checker-04-decisions-school-a.png`.

### 8e. Staging the supplementary charge, through the real screens

The fixture's decided set is all term bills, so the kind column would have rendered one value five
times. Driven as `user#2`: **New invoice** modal → kind combobox → `Supplementary charge` →
`Late bus fee — term 1`, `7500` → **Create invoice**; then **Submit credit note** on that row; then
approved by `user#3` on the queue.

```
   POST 201 /api/v1/finance/students/…/invoices :: {"…","display_number":"000013","status":"issued","kind":"supplementary",…}
  statement row 2: ["000013Supplementary charge","Enrollment a290c3eb-768a-…","issuedUnpaid","₦7,500.00₦7,500.00 outstanding","Record paymentSubmit credit noteRequest void"]
  dialog heading: Submit credit note for approval — Supplementary charge 000013 …
   POST 201 /api/v1/finance/invoices/…/credit-notes :: {"…","display_number":"CN-000004","amount":{"amount_minor":150000,"currency":"NGN"},…}
   POST 200 /api/v1/finance/credit-notes/…/approve
```

**Establishes:** the kind on the decisions row is the *invoice's* kind, carried end to end from the
modal that created it — and the column distinguishes two kinds side by side, which is the state U7
exists for (an episode can carry a term bill and supplementary charges at once, so a number alone
stopped naming one document). Note the modal's own kind label read
`Term bill (will be rejected — void first)` on that episode, F7 stating itself before the act.
Screenshots `maker-03-new-supplementary-invoice.png`, `maker-04-statement-after-supplementary.png`,
`maker-05-credit-note-on-supplementary.png`.

### 8f. Isolation — both seats side by side, by id

| | `user#3` (`school#1`) | `user#6` (`school#2`) |
| --- | --- | --- |
| `credit-notes/decided` | `["a290c3eb-d469-462d-924a-85db631c6ad8","a290c3eb-ca7e-48f5-bbe3-dc5df78a7fbc","a290c3eb-bc8d-49f0-b8de-8dc922ac0b26"]` | `[]` |
| `void-requests/decided` | `["a290c3eb-e391-4784-a30b-89995abd397f"]` | `[]` |
| rendered rows | 5 | 0 |
| empty state | — | `"Nothing decided yet — Credit notes and void requests appear here once a checker approves or rejects them."` |
| **overlap A ∩ B** | | **`[]`** |

Read as **ids off the API payloads**, not labels: both schools mint `CN-000001` and both render
`First Term`, so a label comparison would prove nothing. `school#2`'s empty result is the fixture's
`0 / 0` column rendering honestly rather than a blank screen.
Screenshot `isolation-01-school-b-decisions.png`.

### 8g. Friction encountered

**`SESSION_DOMAIN=localhost` means `127.0.0.1:8001` cannot hold a session.** Driving the host
`127.0.0.1` logged in successfully (`POST /login → 302 /dashboard`) and then lost the session on
every subsequent request — `GET /dashboard → 302 /login`, `GET /finance/decisions → 302 /login` —
which reads exactly like a rejected password. The cookie is scoped to `localhost`
(`.env.drive.example:39`). Driving `http://localhost:8001` fixed it immediately. `drive-environment.md`
documents the `SANCTUM_STATEFUL_DOMAINS` entry, which lists both hosts, and that is what misleads:
Sanctum accepting `127.0.0.1` does not make the session cookie visible there. **Worth adding to the
skill's friction list; I have not edited the skill.**

The documented `/dashboard` 403-bounce for finance seats occurred as described and is not a defect.

---

## 9. What was NOT driven, and what I could not verify

- **The three declared-absent feeds have no decided surface to drive.** By construction.
- **`void-checker@drive.test` (`user#4`) and `super@drive.test` were not driven.** The super_admin
  asymmetry in §4 is proven at the HTTP layer in `ApprovalsPageGateTest`, not in a browser.
- **A rejected VOID request was never rendered.** I drove a rejected *credit note*; the fixture's one
  pending void was left pending on purpose, to evidence that a pending document is absent from the
  decisions feed (§8d). So the rejection path is rendered for one of the two types and proven by test
  for both (arm 4 asserts the void's `rejection_reason` over HTTP).
- **Pagination and search were not exercised against a large set.** Five rows fit one page. The
  decided set grows for the life of a school while the pending queue does not, and this cut pages it
  **client-side**, mirroring the queue. At a few hundred documents that is fine; at ten thousand it is
  not, and there is no server-side page. Stated as a bounded limit, in the read-model docblocks and
  here.
- **No concurrency proof.** Both routes are reads over append-only-plus-status tables with no write
  path, so there is nothing to race — but I did not test it.
- **`decided_at` ordering across a DST or timezone boundary** was not examined.
- **The 42 pre-existing `tsc` errors are unchanged** (42 before, 42 after; none in any file this
  branch touches). The `app-sidebar.tsx` `Teacher.uuid` error moved from line 542 to 555 because my
  addition shifted it, and exists at `HEAD`.
- **What §6e's clock-frame flake actually is.** Characterised (1 failure in 17 isolated runs, the
  arithmetic that produces 91), not diagnosed.

---

## 10. Out of scope, observed, not built

- **`index.tsx`'s "Pending approvals" link is gated on a hardcoded pair** of abilities
  (`finance.credit-note.approve`, `finance.invoice.void-request.approve`) while the route's gate is
  derived over every finance checker ability. A holder of only `finance.fee-schedule.change.approve`
  reaches the queue by URL and is offered no link from the finance hub. Pre-existing, unrelated to
  this branch, and the same class as the defect in §7.
- **`OpeningBalanceBatchResource` still emits no row count**, recorded in `approval-feeds.ts` before
  this branch and unchanged by it.
- The three feeds' decided routes, per ruling 2's out-of-scope list.
