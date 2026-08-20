# U6 commit 4 — a bursar can bill a cohort, and read back exactly who was and was not billed

Branch `feat/u6-bulk-run-screen`, off `origin/staging` at `8b02807` (the #261 merge).

The route, the controller, the resources and the operator screen for a bulk invoice run. No new
domain: the run row, the queued job, the four outcomes, the two reconciliation equalities, the cohort
and unplaceable reads and the mapper's five refusals all landed in commits 1–3 and are unchanged.

---

## 1. Two premises in the brief that the code contradicts

Both were followed as far as they are true and departed from where they are not. Naming them first,
because a brief executed faithfully on a false premise produces a confidently wrong change.

### 1a. "A FAILED run shows its failure_reason and NO figures. It has none."

True of four of the **five** routes into `failed`; false of the fifth.

`ProcessBulkInvoiceRun::reconcile()` writes all eight counts **and then** sets `failed` in the same
statement when the NOBODY-BILLED rule fires — a non-empty cohort in which every member failed
(`ProcessBulkInvoiceRun.php:445-473`). So a run that walked forty students and billed none is
`failed` **and fully counted**, and those counts are the entire diagnosis of it.
`BulkInvoiceRunStatus` says so in its own words: *"a `failed` run must be READ, not assumed: check
`cohort_count` and the row counts"* (`BulkInvoiceRunStatus.php:54-56`).

**What was built instead:** the payload carries `has_figures`, keyed on `cohort_count !== null` — the
first column `reconcile()` writes and one `writeFailure()` cannot, because `writeFailure()` names
`status`, `finished_at` and `failure_reason` and no count (`ProcessBulkInvoiceRun.php:551-558`). That
answers the question actually being asked — *has this run reported?* — and covers pending, running
and the four count-less failures identically while admitting the fifth. Keying the screen on the
STATUS would have blanked the figures in the one failure where they matter most.

The brief's rule as written is preserved for the case it describes and is pinned by a test
(`it exposes NO figures for a run that FAILED before it could count anything`); the fifth case has
its own arm beside it.

### 1b. "TWO DIFFERENT THINGS ARE CALLED TERM: `curricula.term` … `terms.id`"

`curricula.term` **does not exist.** `2026_05_06_085734_update_terms_and_curricula_tables.php:114`
dropped `curricula.term` and `curricula.academic_session_id` together and replaced them with a
`term_id` FK. Re-derived from the live database rather than from that migration:

```
SHOW COLUMNS FROM curricula   → term_id            (no `term`, no `academic_session_id`)
SHOW INDEX  FROM curricula    → curricula_unique_key = (school_id, class_level_arm_id, term_id,
                                                        exam_type_id, is_ccm)
```

So the addendum's stated `curricula_unique_key` is the **create** migration's five columns, two of
which were dropped. There is exactly one meaning of "term" reachable today.

**The conclusion the addendum drew from it is still correct and is now stronger:** the live unique key
puts `class_level_arm_id` and `term_id` in the same index, so one arm holds one curriculum row **per
term** — the mapping is one-to-many and the term is not derivable from a class level. Nothing tries to.

**Which "term" each surface I touched uses — all of them `terms.id`, the row:**

| Surface | Column / field | Which |
| --- | --- | --- |
| `finance_bulk_invoice_runs.term_id` | FK → `terms.id` | the row |
| `BulkInvoiceRunCoordinatesRequest` | `Rule::exists('terms','id')` | the row |
| `BulkInvoiceRunResource.term_id` | `$this->term_id` | the row |
| page prop `default_term_id` | `CurrentTerm::forSchool()?->id` | the row |
| screen state `termId`, wire `term_id` | the prop / the select's value | the row |
| `FeeScheduleLookup::activeFor($termId, …)` | `finance_fee_schedules.term_id` | the row |
| `BillableEnrollment::$termId` | `curricula.term_id` (`BillableEnrollmentAdapter:465-471`) | the row |

---

## 2. What was built

**Backend**

| File | What |
| --- | --- |
| `app/Finance/Http/Controllers/BulkInvoiceRunController.php` | `preview` · `store` · `index` · `show` |
| `app/Finance/Http/Requests/BulkInvoiceRunCoordinatesRequest.php` | one request for the preview AND the start |
| `app/Finance/Http/Resources/BulkInvoiceRunResource.php` | the run on the wire; the null-is-not-zero rule lives here |
| `app/Finance/Models/BulkInvoiceRun.php` | +`term()` / `classLevel()` — display relations, mirroring `FeeSchedule`'s |
| `app/Support/CurrentTerm.php` | **extracted**, see §3 |
| `app/Http/Controllers/SetupController.php` | now reads `CurrentTerm` instead of carrying the expression |
| `routes/endpoints/finance.php` | four API routes |
| `routes/web.php` | two page routes + props |

**Frontend**

| File | What |
| --- | --- |
| `resources/js/services/bulk-invoice-runs.ts` | wayfinder URLs + the wire types (every count `number \| null`) |
| `resources/js/pages/admin/finance/bulk-invoice-runs/index.tsx` | preview → confirm → start, and the runs list |
| `resources/js/pages/admin/finance/bulk-invoice-runs/show.tsx` | one run's report: figures, alarm, four buckets |
| `resources/js/components/app-sidebar.tsx` | the nav item, keyed on `finance.invoice.generate` |

**Tests / fixture / docs**

| File | What |
| --- | --- |
| `tests/Feature/Finance/BulkInvoiceRunScreenTest.php` | 18 arms |
| `tests/Feature/Finance/FinanceNavCoverageTest.php` | the per-run route's exemption + its link arm |
| `database/seeders/DriveCastSeeder.php` | placed enrollments, an arm on JSS 1, a past term |
| `app/Finance/Console/DriveFinanceStates.php` | `ensureActiveFeeSchedule()` + its count |
| `app/Console/Commands/SeedDriveFixture.php` | three new count-table columns |

**No new permission.** All four API routes and both page routes carry `finance.invoice.generate` —
the ability the single-student generate POST already carries. Bulk raises the same document, from the
same `GenerateInvoice`, under the same rule; a `…generate-bulk` minted now would be granted to
precisely the roles that already hold `generate`, deciding nothing, while adding a second case that
can drift out of step with the first.

**No money on this screen or in its payload.** `finance_bulk_invoice_runs` carries no money column at
all, by the migration's own decision, so `bin/ci-money-lint` is irrelevant to this path by
construction rather than by care. No `Intl`, no `toLocaleString`, no arithmetic on an amount.

**Toasts are `sonner`**, matching the three redesigned Finance screens. `react-toastify` is not
imported anywhere in the new files.

---

## 3. The term is defaulted, not asked for — and where the resolution now lives

The operator picks a **class level**. The term arrives pre-filled and is shown as a fact, with a
"Change" control beside it; picking a different term is a deliberate act and the screen then says
*"Not the current term — this run will bill the term selected above"*, restated inside the
confirmation dialog.

**The logic was EXTRACTED, not copied.** It stood inside `SetupController::index()` and was the only
expression of it in the application. It now lives at `app/Support/CurrentTerm.php` — the shared
kernel, which Finance may reach — and **`SetupController` reads it from there**, so there is one
definition rather than one-plus-a-comment. The resolution and its fallback are preserved exactly:

1. the school's `is_current` session (`School::currentSession()`, `School.php:122-126`);
2. inside it, the term whose `status` is `active`;
3. failing that, **the last term by `order`** — the between-terms case, where a resolver returning
   null would leave the screen with no default at the moment an operator is most likely to be billing
   the term that just ended.

`default_term_id` is **null** when the school has no current session, and the term control is then
open from the start with nothing pre-selected. A screen that invented a default there would be
choosing a term on behalf of a school that has not said which one it is in.

**It is a default and never a constraint.** The term is named explicitly on the wire by both the
preview and the start, so an override is representable; the server does not re-resolve it. Billing a
past term is a real act — a child who enrols late is billed for the term they enrolled in.

---

## 4. Decisions worth arguing with

**The preview is the control this screen is built around.** Nothing undoes a bulk run: each invoice
it raises is undone by its own maker-checker void request, one submission and one approval **per
child**. Start is unreachable until a preview *for the current coordinates* has been read and did not
refuse; changing either select discards the preview, because a confirmation naming last query's
cohort size is worse than no number.

**The refusal text is the server's own string, verbatim** — `FeeScheduleLineMapper`'s
`BusinessRuleException` message, or `ProcessBulkInvoiceRun`'s no-schedule sentence. A second wording
is a second thing that can disagree with the job about why a run cannot happen. Pinned by an arm that
asserts the whole sentence including the schedule uuid.

**`already_billed` in the preview is N queries over the cohort, deliberately.**
`InvoiceReadModel::activeScheduledInvoiceIdForEnrollment()` is THE one PHP expression of "does this
episode already carry an active scheduled invoice", shared by the modal preview, `GenerateInvoice`'s
pre-check and the job. A single `whereIn` here would be a fourth copy, and the last time two copies
existed they disagreed the day one gained the `kind` filter. Making it one query means giving the read
model a batch form of the same expression — worth doing, **not** worth doing inside a screen commit.
Recorded here rather than left as a surprise.

**`reconciliation` is on the wire and is slightly more than the brief asked for.** The model names two
equalities, each with a persisted-rows side and a walked-list side, and their failure is the run's
*only* alarm — there is deliberately no flag column. A screen rendering eight numbers without saying
whether they add up renders the alarm as decoration. It is derived server-side from figures already on
the wire, and both sides travel with it so a reader can disagree.

**Bucket totals are counted from the ROWS, not read off the run.** Two different facts, stated
separately: the run's `counts` are what it reported when it finished; the bucket totals are what is in
`finance_bulk_invoice_run_rows` now. While a run is in flight the header reads "rows recorded so far".

**`show` answers 404, not 403, for a foreign run** — established, not assumed. `BulkInvoiceRun` carries
`BelongsToSchool`, so `SchoolScope` filters the implicit binding and another School's uuid resolves to
no model. That is also the right answer: 403 would confirm a run with that uuid exists somewhere.

**Two display relations were added to the model** (`term()`, `classLevel()`), mirroring
`FeeSchedule`'s exactly. Not domain — the job reads the two ids as integers and never touches them —
but it is an edit to a file the brief did not name, so it is called out. The alternative was a label
map assembled in the controller, i.e. a second way of turning a (term, level) pair into words.

---

## 5. Tests, and the red for every guard

`tests/Feature/Finance/BulkInvoiceRunScreenTest.php` — **18 passed, 165 assertions.**

Sixteen guards were planted and watched red, then restored. Each line is the plant, then the failure
message the suite actually printed.

| # | Plant | Red |
| --- | --- | --- |
| 1 | `has_figures` keyed on `status === Completed` | `the nobody-billed rule` — *Failed asserting that false is identical to true* |
| 2 | `'cohort' => (int) $this->cohort_count` | **3 arms** — *Failed asserting that 0 is identical to null* |
| 3 | `Rule::exists('terms','id')` unscoped (drop `->where('school_id', …)`) | `refuses coordinates belonging to another School` — *Expected 422 but received **201*** (it really did create a cross-School-coordinate run) |
| 4 | drop `permission:finance.invoice.generate` from the `show` route | `refuses every route to a seat that…` — *Expected 403 but received 200* |
| 5 | `preview` inserts a run row and dispatches | `previews a run without creating a row…` — *Failed asserting that 1 is identical to 0* |
| 6 | `index` with `->withoutGlobalScope(SchoolScope::class)` | `shows School A no run of School B` — the uuid array gained School B's run |
| 7 | `'cohort_balances' => true` | `reports the run's own alarm` — *Failed asserting that true is identical to false* |
| 8 | re-word the mapper refusal to a sentence of our own | `reports the schedule-level refusal in the JOB's own words` — string diff, ours vs the mapper's |
| 9 | add a uniqueness guard to `store` (409 on a second run at the same coordinates) | `permits a SECOND run at the same coordinates` — *Expected 201 but received 409* |
| 10 | `'student' => null` in `serializeRows()` | `names the student for the U7 link` — *Failed asserting that null is identical to 'a28ac75d-…'* |
| 11 | `ActiveSchool::getOrFail()` (the **model**) bound into `where('school_id', …)` | `hands the page the school's OWN terms…` — *Property [terms] was marked as invalid* (the empty-select defect, caught) |
| 12 | wrap the report link in `run.status === 'failed' ? '#' : …` | `the bulk-invoice-run exemption really is linked…` — *Failed asserting that 5 is identical to 4* |
| 13 | rename the sidebar href to `/finance/bulk-runs` | `every finance page is reachable from the sidebar` — *reachable from NO menu: /finance/bulk-invoice-runs* |
| 14 | `default_term_id` = newest term by id | `defaults the term to the school's CURRENT one` — *Failed asserting that 2 is identical to 1* |
| 15 | `default_term_id` = any `active` term, highest `order` | same arm, same red |
| 16 | `store` re-resolves the term server-side, ignoring the caller's | `lets an explicit term OVERRIDE the default` — *Failed asserting that 2 is identical to 1* |

Plants 14 and 15 needed a second attempt to be honest: the first version of the default arm seeded the
current term as the **newest**, so a "newest by id" plant passed. The fixture now seeds the decoy as an
**older** session whose term is also `active` and carries a **higher** `order`, so the arm reds on
newest-by-id, highest-order, first-in-the-props-list and any-active-term alike.

Plant 6's first attempt also passed vacuously — `withoutGlobalScope('App\Scopes\SchoolScope')` names a
class that does not exist (it is `App\Models\Scopes\SchoolScope`), so nothing was removed. Recorded
because a plant that silently does nothing is indistinguishable from a guard that works.

---

## 6. The browser drive

Throwaway instance, `APP_ENV=drive`, `portal_drive`, port 8001, `pnpm run build` first. Puppeteer-core
against system Chrome, installed **outside** the repository. Screenshots in
`docs/handoff/drives/2026-08-19-bulk-invoice-runs/`.

Two pieces of harness friction, both already on record and neither a defect in this change: `/dashboard`
403s for the finance seats, and `SESSION_DOMAIN=localhost` means the drive must use `localhost:8001`
— on `127.0.0.1:8001` the session cookie is never stored and every page silently bounces to `/login`,
which looks exactly like a broken login. The second is not in the friction list yet.

### 6a. The fixture count table, verbatim

```
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+------------------+----------------+-------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Active schedules | Cohort at slot | Unplaceable |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+------------------+----------------+-------------+
| A (school#1) | 2                 | 2     | 2            | 1             | 1                 | 3                 | 0                   | 1                | 2              | 7           |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 1                | 2              | 1           |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+------------------+----------------+-------------+
```

**The three right-hand columns are new, and the fixture needed real work before a drive was worth
running.** Read the state it was in first:

- `DriveCastSeeder::enrollFor()` built every episode on a bare `Curriculum::factory()`, whose
  `term_id` and `class_level_arm_id` are both nullable and unset. **Every drive episode was
  unplaceable.** No cohort query could return one, so a bulk run would have billed nobody on a fixture
  that looks fully populated.
- There was **no fee schedule at all** — `DriveFinanceStates` had no method that made one — so every
  preview would have answered *"No active fee schedule exists at these coordinates"* and the whole
  screen would have rendered one sentence.

Neither is visible to any test: `SeedDriveFixture` refuses outside `APP_ENV=drive` and the suite is
pinned to `APP_ENV=testing`. Fixed as a precondition of the drive (U1 commit 1's precedent):

- an `Arm` + `ClassLevelArm` on **JSS 1 only** — JSS 2 stays unarmed so the screen has a genuinely
  empty cohort to report;
- two placed, unbilled students per school (`Cody`, `Cleo`) — the `billed` bucket;
- every pre-existing episode left unplaced — the `unplaceable` bucket, which is the actionable list;
- `DriveFinanceStates::ensureActiveFeeSchedule()`, through the **real** draft → submit → approve path,
  because an approved publish is the only thing that makes a schedule `active`; a status write would
  put a state in the fixture the application cannot reach. One mandatory item and one optional one, so
  the run can be seen leaving the optional one out;
- a second, **non-current** session with an `active`, higher-`order` term per school, so the term
  default is discriminating in the fixture as well as in the suite and the override control renders a
  list of more than one.

`Payments (migrated) = 0` is the exempt column and is zero by construction. This screen has no
migrated-payment case.

### 6b. Isolation — both seats, by id, side by side

Labels are identical strings by construction; only the values differ.

```
Seat 1 — maker@drive.test (accounts_officer, school#1)
  DEFAULT TERM SHOWN  : 2026/2027 — First Term
  MODAL term options  : ["2|2025/2026 — Third Term","1|2026/2027 — First Term"]
  MODAL level options : ["1|JSS 1","2|JSS 2"]

Seat 2 — school-b@drive.test (isolation, school#2)
  DEFAULT TERM SHOWN  : 2026/2027 — First Term
  MODAL term options  : ["4|2025/2026 — Third Term","3|2026/2027 — First Term"]
  MODAL level options : ["3|JSS 1","4|JSS 2"]
```

Terms `1,2` against `3,4`; levels `1,2` against `3,4`; and the default is each school's **own**
current-session term (`1` and `3`) — not the newest term (`2` and `4`), not the highest `order`, not
the first in the list. Four label strings match character for character.

Run lists, by uuid:

```
maker@drive.test   run uuids: ["a28ad343-1d89-…","a28ad251-8ecb-…","a28ad155-9654-…"]   (3, all School A's)
school-b@drive.test run uuids: ["a28ad3f9-aeaf-…"]                                        (1, School B's own)

maker@drive.test    opening School B's run by uuid : refused — "Could not load this run"
school-b@drive.test opening School A's run by uuid : refused
school-b@drive.test opening its OWN run            : "JSS 1 · 2026/2027 — First Term" | Completed
```

### 6c. The permission gate, across three seats

```
void-checker@drive.test    nav item: false  page status: 403
checker@drive.test         nav item: false  page status: 403
maker@drive.test           nav item: true   page status: 200
```

The nav item and the route ask exactly the same question, so a visible entry cannot 403 on click and
a hidden one is never reachable by typing the URL.

### 6d. All four statuses

**`pending` (Queued)** — started through the screen with `QUEUE_CONNECTION=database`, so the row really
sat unclaimed:

```
STATUS   : Queued
NO-FIG   : ["This run has not reported any figures",
            "It is queued and no worker has picked it up yet. Nothing has been billed."]
BUCKETS  : "Nothing recorded here yet — the run has not finished." ×4
```

**`running`** — the real interval is milliseconds on a two-student cohort and cannot be caught by hand,
so the status was **written directly on the throwaway drive database** to see the rendering, then put
back. Stated plainly because it is the one state below that was not reached by clicking:

```
RUN STATUS PILL      : Running
FIGURES              : (none rendered)
ABSENCE BLOCK PRESENT: true
BUCKET HEADERS       : "Could not be placed 0 rows recorded so far" · "Failed 0 rows recorded so far"
                       "Billed 0 rows recorded so far" · "Already billed 0 rows recorded so far"
```

Note the wording difference from `completed`: **"rows recorded so far"**, and the empty-bucket sentence
*"Nothing recorded here yet — the run has not finished"* rather than *"This run billed nobody."*

**`completed`** — one `queue:work --once`:

```
H1       : JSS 1 · 2026/2027 — First Term
SUBTITLE : Started by Maker Drive · priced from Drive term bill v1
STATUS   : Completed
Cohort = 2 · Billed = 2 · Already billed = 0 · Failed = 0
Could not be placed = 7 · Billable in this school = 9 · Priced at other coordinates = 0
BUCKETS  : "Could not be placed 7 rows" · "Failed 0 rows" · "Billed 2 rows" · "Already billed 0 rows"
```

The arithmetic is checkable and nothing in the page computed it: `9 − 2 − 7 = 0`, the run's own
`billable − cohort − unplaceable_listed`. The unplaceable list is **7** while the fixture has **8**
unplaced episodes, because `Pat` holds two and the port's tie-break takes at most one episode per
student — the documented `MAX(id) GROUP BY student_id` behaviour, visible on screen.

The "Priced at other coordinates" card carries its caveat on the page, not only in a docblock:

> Billable students this run did not name, because they belong to other terms or class levels. NOT a
> count of students missed — on a single-level run this is most of the school, every time. It is an
> indicator and not a headcount: student-less episodes collapse into one, so it can under-report.

**`completed` again, as a RE-RUN** — the recovery path, started from the screen at the same coordinates:

```
PREVIEW  : STUDENTS IN THIS COHORT 2 · ALREADY BILLED 2 · WOULD BE BILLED 0
CONFIRM  : "This raises the term bill for 2 student(s) in JSS 1, for 2026/2027 — First Term.
            2 of them already carry a term bill and will be recorded as already billed, not billed twice.
            There is no undo…"
RESULT   : Cohort = 2 · Billed = 0 · Already billed = 2 · Failed = 0
```

That `Billed = 0` is the other half of the null-is-not-zero rule **rendered**: a genuine zero still
prints `0` on a screen that prints `—` for a run that has not reported. A payload that dashed
unconditionally would have passed every test written for the missing case.

**`failed`** — reached honestly, and the way production will reach it: a run was queued from the screen,
then the fee schedule was **retired through the real maker-checker path** (`SubmitFeeScheduleChange`
Retire → `ApproveFeeScheduleChange`) before the worker picked the job up. That is exactly the race the
preview cannot close, which is why the preview is a courtesy and not a control.

```
RUN STATUS PILL: Failed
FAILURE BLOCK  : "This run failed"
                 "No active fee schedule exists at these coordinates, so there is no price list to bill from."
                 "A failed run does not promise that nothing was billed — read the buckets below.
                  Re-running is safe: anything already billed comes back as already billed."
FIGURES SHOWN  : 0        ← no figure cards rendered at all
ABSENCE BLOCK  : "This run has not reported any figures"
                 "It stopped before it could count anything. Its counts were never written,
                  so there are none to show — not zeroes."
```

**The list, with all three School A runs on it:**

```
JSS 1  2026/2027 — First Term  Failed     —                  —  —  Maker Drive  Open
JSS 1  2026/2027 — First Term  Completed  Drive term bill v1  0  2  Maker Drive  Open
JSS 1  2026/2027 — First Term  Completed  Drive term bill v1  2  2  Maker Drive  Open
```

Em dashes for the failed run's Billed and Cohort, a real `0` for the re-run's Billed, real numbers
elsewhere. The failed run's Schedule column is `—` because no schedule resolved, so
`fee_schedule_id` was never pinned.

### 6e. The preview, and the refusal that blocks Start

```
JSS 1 (armed, priced):
  STUDENTS IN THIS COHORT 2 · ALREADY BILLED 0 · WOULD BE BILLED 2
  SCHEDULE : "Drive term bill v1 (active) · 1 mandatory item(s) on every invoice"
  START disabled: false

JSS 2 (no schedule, empty cohort):
  STUDENTS IN THIS COHORT 0 · ALREADY BILLED 0 · WOULD BE BILLED 0
  SCHEDULE : "No active fee schedule at these coordinates. There is nothing to price from."
  REFUSAL  : "This run would fail before billing anybody"
             "No active fee schedule exists at these coordinates, so there is no price list to bill from."
  START disabled: true
```

The schedule states **1** mandatory item, not 2 — the fixture's optional "Bus" line is correctly
excluded from what a bulk run would bill.

### 6f. The term default, driven

```
DEFAULT TERM SHOWN  : 2026/2027 — First Term
TERM SELECT PRESENT : false      (collapsed while the default stands)
CHANGE CONTROL      : true
TERM HELP           : "The school's current term. Change it to bill a past term."
LEVEL SELECT VALUE  : 1
ONE-DECISION PREVIEW: STUDENTS IN THIS COHORT 2 · ALREADY BILLED 2 · WOULD BE BILLED 0
```

The preview was taken after touching **one** control. Clicking "Change" opens a select carrying both
terms (`["2|2025/2026 — Third Term","1|2026/2027 — First Term"]`) and does not discard a preview by
itself — only an actual change of coordinates does.

### 6g. What was NOT driven

- **`running` was not reached by clicking** — see 6d. The status was set directly on the throwaway
  drive database purely to render it, then reverted.
- **An override actually being *started*.** The control was opened and both options read, but no run
  was started against the past term from the browser; the past term has no fee schedule, so it would
  have hit the refusal path already covered. The override reaching the domain is pinned by test
  (plant 16), not by eye.
- **A completed run over an EMPTY cohort.** JSS 2 has no schedule, so the refusal blocks Start before
  the empty-cohort completion can be produced. The empty-bucket *wording* on a completed run was seen
  (the `Failed 0 rows` bucket on every completed run).
- **A `failed` bucket with rows in it, and the nobody-billed rule.** Both need per-student billing
  failures, which the fixture cannot produce without injecting a fault. Covered by
  `BulkInvoiceRunTest` and, for the payload, by the `nobody-billed` arm here.
- **A truncated bucket.** Needs >200 rows of one outcome.
- **`super@drive.test`.** No finance grant, so it holds no `finance.invoice.generate` — the same
  answer the two checker seats gave, established there.

---

## 7. The U7 slice, and what a reader of a run cannot reach

A `billed` row links to **that student's statement** (`/finance/students/{uuid}/statement`), which
already lists their invoices. The student uuid comes through the ACL port
(`BillableEnrollmentProvider::displayFor()`) — Finance holds `student_id` and owns no uuid, no name and
no admission number.

**What a reader of a run therefore cannot reach, because there is no invoice index:**

1. **The specific invoice this run raised for a student.** The row records `invoice_id`, and the link
   goes to the statement, so a reader lands on *all* of that student's invoices and has to pick the
   term bill out by eye. Nothing on the run says which one it was.
2. **The invoices of a run as a SET.** There is nowhere to ask "show me the 40 invoices run X raised" —
   no filter by run, no export, no total. The run reports counts; the money lives on the invoices and
   is not reachable from here in aggregate.
3. **Anything about a billed invoice's state.** Whether it has since been paid, part-paid, voided or
   credited is invisible on the run report; the statement is the only route to it, one student at a
   time.
4. **A `failed` row's student in the invoice context.** Their statement opens, but there is no invoice
   to look at — the failure is the reason there isn't one, and that reason is on the run row.

This is stated on the screen too, under the buckets, rather than only here.

---

## 8. Residual risk

- **The preview's N queries.** One class level's cohort, on an explicit click. Fine now, and the fix
  (a batch form of the read model's one predicate) is named in the controller docblock rather than
  left to be rediscovered.
- **`running` is unrendered in production until a run is long enough to catch.** The state renders
  correctly (6d) but nobody has seen it arrive by itself.
- **No JavaScript test runner**, so every frontend rule in this commit — the em dashes, the discarded
  preview, the in-flight bucket wording, the override notice — is held by the drive and by two
  text-reading arms in `FinanceNavCoverageTest`, not by a unit test. Unchanged from every other screen
  here, and stated rather than implied.
- **The drive fixture now seeds more state** (an arm, two placed students, an active schedule, a past
  term per school). Other screens' drives will see those rows. Nothing existing was modified — the
  pre-existing episodes are untouched and still unplaced — but the count table's first two columns
  moved from 1 to 2.
- **`SetupController.php` shows 28 changed lines for a 5-line edit.** Pint reformatted the response
  array's `=>` alignment and dropped an unused `Illuminate\Http\Request` import when it ran over the
  files this commit touched. It is a file this commit genuinely changes and `lint-changed` would
  demand the same formatting on push, so it is kept rather than reverted — but the diff is larger than
  the change, and that is worth seeing before reading it.
