# feat/manual-invoice-run-screen — the screen

**Branch:** `feat/manual-invoice-run-screen` off `staging` @ `e861f9a`.
**Scope:** the filter-and-tick selection screen, the run report page, and the roster feed the screen
needed and did not have.
**Untouched, deliberately:** `ManualInvoiceRunController`, `StoreManualInvoiceRunRequest`,
`StartManualInvoiceRun`, `ProcessManualInvoiceRun`, `BillableEnrollmentAdapter`, the ACL port and the
four migrations. Nothing on the run path was edited.
**No migration.**

---

## 1. Where the instruction turned out to be wrong, up front

**Four corrections. The first one changed the scope of the commit and was put to you before any code
was written.**

### 1.1 — "commits 1 and 2 built everything behind it" is FALSE for the roster, and the bursar seat cannot reach the one that exists

The screen has to list students to tick. The obvious feed is `/api/students`, which the students
index uses. **The bursar cannot reach it**, and this is a permission fact rather than a preference:

| | |
|---|---|
| `/api/students` | gated `permission:student.view` (`routes/api.php`, the principal read-only group) |
| holders of `student.view` | `admin`, `head_of_school`, `principal`, `form_teacher` (`RbacSeeder.php:260`, `:299`, `:349`, `:380`) |
| holders of `finance.invoice.generate` | `admin`, `accounts_officer` (`RbacSeeder.php:248`, `:407`) |

The intersection is **`admin` alone**. `accounts_officer` — the bursar, and the drive's
`maker@drive.test` — holds `generate` and not `student.view`, so a screen fetching `/api/students`
opens onto an empty table with a 403 in the console for exactly the seat it was written for.

`/v1/finance/accounts` is not a substitute either: it reads `finance_student_accounts`, a projection
whose rows exist only after financial activity — so the students most likely to be billed here, the
ones nobody has billed yet, are absent — and it offers search and status only, with no class level
and no scholarship, which are the two axes brief §1 names.

**You chose the finance-side roster endpoint** over widening the bursar seat and over shipping the
roster as page props. So this commit is **the screen plus one read endpoint**, not the screen alone.

The route block this feature already carries had in fact anticipated it — *"the filter-and-tick
screen is a later commit and brings whatever reads it needs with it"*
(`routes/endpoints/finance.php`, the manual-run block) — so the endpoint is the block's own
expectation being met, not a scope invention.

### 1.2 — the in-flight refusal is a **422**, not a 409

The instruction says *"A run already in flight answers 409 naming it."* It answers **422**.
`ManualInvoiceRunController::translateActiveRunCollision()` catches the 1062 and throws a
`ValidationException` keyed `run`; the **409** is what an *untranslated* 1062 would produce
(`bootstrap/app.php`'s generic map, reading "Duplicate entry detected.", which names nothing). The
requirement is unaffected — the refusal is rendered in words either way — but the screen handles
both, and it is the 422 that the drive actually met (§6, arm 4). Commit 2's own report records the
same correction in the other direction.

### 1.3 — the roster's "no School context" refusal is a **403**, not the 409 the run report gives

Measured, and this arm was written expecting 409. `ActiveSchool::getOrFail()` is
`abort_unless(…, 403, 'No active school selected.')` (`app/Support/ActiveSchool.php:70
(getOrFail)`), so a controller that asks for the School refuses at 403. The run report never calls
it and is refused instead by `rbac.fail_closed_models` on the model, whose render is a 409. Same
sentence, different mechanism, and the roster's is the more direct of the two.

### 1.4 — "make the page-size control reachable" was already true; what was NOT true is that the control worked

The shared `Pagination` component already offers 5/10/25/50/100. But its **Prev and Next arrows were
permanently dead** on this screen, because my roster feed returned four pagination keys and that
component derives the arrows' disabled state from `prev_page_url` / `next_page_url`. **Found by the
browser drive, not by any test** — see §5.

---

## 2. Does 91 fit one page? — MEASURED. Yes, at the largest limit.

- The pagination control's options are `LIMITS = [5, 10, 25, 50, 100]`
  (`resources/js/components/pagination.tsx`), so **100 is the largest page an operator can ask for**.
- The roster endpoint **clamps** `per_page` at 100 rather than refusing it
  (`ManualInvoiceRunStudentController::MAX_PER_PAGE`), and arm 2e pins both the accepted 100 and the
  clamped 101 with **literal** payloads.

**91 ≤ 100, so a filtered cohort of 91 fits on one page and can be ticked in a single run.** Option
(a) holds and option (c) is not forced by this number.

**The ceiling is on screen, in words.** Whenever the filtered result spans more than one page the
banner states the page-scoped rule, names the ceiling, and — when the whole result fits — offers a
one-click "Show all N on one page". Above 100 it says so plainly instead of letting the operator
discover it forty ticks in:

> The largest page available is 100, so this filter cannot be put on one page. Narrow it further and
> run each part as its own run.

**The finding that follows:** a cohort **above 100** still cannot be billed as one run. That is where
option (c) — a server-side "everyone matching this filter" scope, which brief §1 sanctions and
`POST /v1/finance/manual-invoice-runs` cannot express — becomes the next commit. Nothing here works
around it.

---

## 3. What was built

| File | |
|---|---|
| `app/Finance/Http/Controllers/ManualInvoiceRunStudentController.php` | **new** — the roster feed |
| `routes/endpoints/finance.php` | +1 route, `GET /v1/finance/manual-invoice-runs/students`, same ability as the other two |
| `routes/web.php` | +2 page routes (selection screen with four prop catalogs; the per-run report shell) |
| `resources/js/services/manual-invoice-runs.ts` | **new** — wire types + wayfinder URLs |
| `resources/js/pages/admin/finance/manual-invoice-runs/index.tsx` | **new** — the selection screen |
| `resources/js/pages/admin/finance/manual-invoice-runs/show.tsx` | **new** — the run report |
| `resources/js/components/app-sidebar.tsx` | +1 nav item, keyed on `finance.invoice.generate` |
| `tests/Feature/Finance/ManualInvoiceRunPageTest.php` | **new** — 13 arms, 107 assertions |
| `tests/Feature/Finance/FinanceNavCoverageTest.php` | +1 exemption, +2 arms |

### The roster feed — what it answers and what it refuses to

`GET /v1/finance/manual-invoice-runs/students`, gated on `finance.invoice.generate` — the same
ability as the page and both run routes, so a visible control can never 403 on click.

**It answers a PAGE, never a SET.** There is no "give me every matching id" mode and it must not grow
one: an endpoint returning the whole id list hands the client exactly what brief §1 forbids it to
hold, and the control that spends it appears the following week.

**Filters come from `StudentIndexFilters::apply()`** — the same class `StudentService::paginate` and
`StudentsExport` call. One definition, three callers. That class exists *because* the index and the
export had already drifted (the export filtered on search alone, so narrowing to one class and
pressing Export downloaded the whole school); a fourth hand-written block here would be the next
drift, and its axis is which families get billed.

**Display comes through the ACL port.** `uuid`, `name` and `admission_number` are
`BillableEnrollmentProvider::displayFor()` — the same call the run report makes when it names the
unplaceable — so the picker and the report cannot spell a student differently. `class_label` is
`Student::$student_class`, the accessor the students index renders.

**The School is an argument, not an ambient opinion.** `ActiveSchool::getOrFail()->id` and an explicit
`where('students.school_id', …)`, for `StoreManualInvoiceRunRequest`'s measured reason: a
`super_admin` with no School has no ambient School, `Student`'s SchoolScope falls to its
silent-unscoped branch, and the roster would list every School's students.

**No placeability flag, deliberately** — and the drive proved this was the right call rather than the
lazy one. See §5.

**Ordered by admission number**, which is the key the report NAMES a student by; the students index's
`latest()` order would give a picker the bursar cannot check a selection against.

### The routes — which data is a prop and which is a fetch

The split is decided by which abilities the bursar holds, on the rule fee-schedules and the
opening-balance operator screen both record: **props are for data the seat cannot fetch.**

- **Class levels, arms, class-level-arms, scholarships → PROPS.** The only API listing them is
  `GET /api/students/resources`, under `permission:academic_setup.manage`, which `accounts_officer`
  does not hold. Third screen to hit the same wall, same answer.
- **Bank accounts → FETCH** of `/v1/finance/bank-accounts`, gated on `finance.bank-account.manage`,
  which both roles holding this page's ability also hold. The coupling is **asserted** (arm 3b), not
  assumed to carry over from FeeSchedulesScreenTest's own — the two screens are gated on different
  abilities.
- **The roster → FETCH**, per §1.1.
- **No term prop, and the absence is a fact about the feature.** A manual run has no (term, class
  level) slot — `finance_manual_invoice_runs` carries neither column, because an arbitrary student
  list spans class levels by definition (bulk brief §3) — and the lines are typed, not priced from a
  schedule.

### The selection screen — the three properties inherited, and the one compromise

Inherited whole from `students/index.tsx` and `student-bulk-action-bar.tsx`:

1. `selectedIds` is a `Set` of student uuids, and the footer acts on **exactly** those ids.
2. **The count lives in the button label** — `Invoice selected (N)` — so scope and label cannot
   disagree.
3. **No select-all-matching, no matching-total, no client-side "all."** The wire has no shape for one
   either: `store` takes `student_ids` and nothing else identifies who is billed, so the guardians
   defect is *unrepresentable* rather than merely avoided. `FinanceNavCoverageTest` now lints both
   identifiers out of the file and counts the one legitimate mention of
   `guardians/bulk-action-bar.tsx` (the docblock explaining why it must not be imported).

**Selection is page-scoped and the screen SAYS SO.** Ticks clear on every filter and page change —
the students index's own rule, inherited deliberately, because carrying them across a navigation
rebuilds exactly the condition the guardians defect lives in. The honest half is the warning, which
appears the moment the filtered result spans more than one page and escalates once there are ticks to
lose. Both strings are quoted verbatim in §6.

### The confirmation — a statement, not a question

Brookstone ruled on 30 August that this issues **directly**: no maker-checker, no second signature.
So the dialog is the last thing between a selection and real charges, and it names, in words, how
many students, what each is charged, how many lines, and **every destination account by name**. Its
rendered text is quoted verbatim in §6.

### The report — where submit lands

`router.visit(pageUrl(uuid))` on 201, never a toast. The report renders `target_count` (the bursar's
own number, from the targets table) against billed / failed / unplaceable, shows `claimed` separately
and never as a term of the equality, renders the server's `balances` — including its **null** while
non-terminal, as a sentence rather than a false alarm — and names the unplaceable **by admission
number**. Bucket truncation at 200 is announced in words, with the count that is not shown.

---

## 4. Bite-proofs — verbatim red text

Every plant was verified applied (grepped for its marker) before the run and reverted after; the
working tree carries no `PLANT_` marker.

### Plant A — the roster's explicit School predicate removed

```
tests 2 passed 1 failed 1
--- it_2f_—_School_B’s_roster_holds_none_of_School_A’s_students…
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
 Array &0 [
-    0 => 'a2a117cb-2f23-456e-8359-34041668f262',
+    0 => null,
+    1 => 'a2a117cb-2f23-456e-8359-34041668f262',
 ]
```

School A's student appears in School B's roster. The `null` is itself informative: the ACL port's
`displayFor()` is still School-scoped, so it cannot display the foreign student — the leak arrives as
an un-tickable row rather than a named one, which is precisely why the port is not the guard here.

### Plant B — `StudentIndexFilters::apply()` dropped (query passed through unfiltered)

```
tests 1 passed 0 failed 1
--- it_2d_—_filters_on_class_level__arm_and_scheme__and__none__is_not_a_scheme
Failed asserting that two arrays are identical.
 Array &0 [
     0 => 'ADM-301',
     1 => 'ADM-302',
+    2 => 'ADM-303',
 ]
```

### Plant C — `orderBy(admission_number)` → `orderByDesc`

```
tests 1 passed 0 failed 1
--- it_2a_—_lists_the_School’s_students_by_admission_number…
--- Expected            +++ Actual
-    0 => 'ADM-001',    +    0 => 'ADM-003',
     1 => 'ADM-002',
-    2 => 'ADM-003',    +    2 => 'ADM-001',
```

### Plant D — the `per_page` clamp removed

```
tests 1 passed 0 failed 1
--- it_2e_—_paginates__and_CLAMPS_the_page_size_at_100_rather_than_refusing
Failed asserting that 101 is identical to 100.
```

### Plant E — the page-scoped warning's testid removed from the screen

```
tests 14 passed 13 failed 1
--- it_the_manual_invoicing_screen_holds_NONE_of_the_guardians_select_all_matching_vocabulary
Failed asserting that '…' contains "data-testid=\"page-scoped-warning\"".
```

### Plant F — the sidebar href broken

```
tests 1 passed 0 failed 1
--- it_every_finance_page_is_reachable_from_the_sidebar…
A finance page is registered, permission-gated and reachable from NO menu:
/finance/manual-invoice-runs.
```

### Plant G — submit lands on a toast instead of the report

```
tests 1 passed 0 failed 1
--- it_the_manual_invoice_run_exemption_really_is_linked_from_the_selection_screen__by_BOTH_routes
Failed asserting that '…' contains "manualInvoiceRuns.pageUrl(data.uuid)".
```

### Plant N — `student.view` granted to `accounts_officer` in `grantsMap()`

The premise arm (3a) turned on its head: with the bursar able to fetch the students index, the whole
reason the roster feed exists is gone.

```
tests 1 passed 0 failed 1
--- it_3a_—_the_bursar_seat_CANNOT_reach_/api/students,_which_is_why_the_roster_feed_exists | line 559
EVERY role that may generate an invoice now also holds student.view (generators: admin,
accounts_officer), so the whole reason ManualInvoiceRunStudentController exists has gone away: the
screen could fetch /api/students directly. Re-read that controller's docblock before deleting
anything — the other half of its argument is that a page is a page and not a client-held id list —
but do not leave the docblock asserting a permission fact that is no longer true.
Failed asserting that 0 is greater than 0.
```

**This plant was run twice, against the two shapes of the same arm, and the pair is the evidence for
§4.1 below.** Under the identical mutation, the arm as originally written printed:

```
Expecting [] not to be [] 'EVERY role that may generate … true.'.
```

**No plant came back green.**

### 4.1 — one assertion was rewritten because a QUALITY GATE refused it, and the gate was right

`bin/quality` step 17 — `PestNegatedExpectationMessagesTest`, *"no test passes a custom failure
message to a negated Pest expectation, or narrows one"* — came back red on **one** new offender, mine:

```
tests/Feature/Finance/ManualInvoiceRunPageTest.php:546  ->not->toBe  MESSAGE DISCARDED
(argument #2 lands in $message; message is argument #2, 2 supplied)
```

**Classification: (a) — a negation that should be positive.** The claim is "at least one invoicing
seat cannot fetch the students index", which is a POSITIVE statement about a set being non-empty; it
was written as `expect($diff)->not->toBe([], $message)` only because `[]` was the convenient thing to
compare against. Pest's `->not->` is a proxy rather than a matcher: it runs the positive assertion
and, on success, throws its own sentence with every argument shortened-exported into it, so the
message never reached the output.

**That is not a theory about this arm — it was measured, under the same mutation** (Plant N above).
The old shape truncated a six-line instruction to
`'EVERY role that may generate … true.'`; the new one prints it in full and adds
`Failed asserting that 0 is greater than 0.` The assertion held in both shapes. Only the diagnostic
was gone, which is precisely the defect the gate names.

**The rewrite keeps the claim and adds information rather than trading it away**, per the gate's own
instruction to prefer the rewrite that keeps the most: the count lands in the failure output, and the
generator role names are interpolated into the message, so a future reader is told which seats were
examined instead of re-deriving the map.

The other four negations in the two changed test files — `->not->toContain($uuid)` twice in the page
test, `->not->toContain('selectAllMatching')` / `'totalMatching'` in the nav test — are category
**(b)**, genuine "this did not happen at all", and already carry no narrowing argument and no message.
They were checked rather than assumed: `grep -n -- '->not->'` over both files returns five hits and
only the one above supplied an argument. **No category (c) — nothing here is a true exception to the
rule, and nothing was baselined.**

### One arm was rebuilt because a plant showed it non-discriminating — in the other direction

The first version of the guardians-vocabulary lint was
`expect($screen)->not->toContain('guardians/bulk-action-bar')` and it **failed on its own subject**:
the screen's docblock names that file, in the paragraph explaining why it must never be imported.
That is the same false positive this test file already records for `receiptable` one screen over. The
path is now **counted** (exactly one mention — the docblock) so an import is a second occurrence and
reds; the two flag identifiers stay an absolute ban, and the screen's docblock was reworded to
describe them in prose rather than spell them, because an absolute ban is a stronger guard than a
count that permits one "legitimate" mention.

---

## 5. What the DRIVE found that no test could

### 5.1 — Prev and Next were permanently dead (fixed, and now pinned by an arm)

The roster feed returned `total / per_page / current_page / last_page`. The shared `Pagination`
component disables its arrows on `!meta.prev_page_url` / `!meta.next_page_url`, so **both arrows were
inert** while the numbered page buttons still worked. Every assertion about the endpoint passed —
data, counts, ordering and clamping were all correct — and the four-page roster could not be paged
with the control an operator reaches for first. Fixed by returning the same six keys
`StudentController::index` returns, and arm 2e now pins the two URLs at first, middle and last page.

This is the drive's own justification, in the form the skill describes: a 200 with the right list and
a 200 with a broken control are the same assertion.

### 5.2 — a blank Class does **not** mean a student cannot be billed

A mixed run was submitted with three students whose roster Class column read `—`. **One of them
billed.** A student can hold a current enrolment whose curriculum names no class level, so
`student_class` is empty while the ACL port places them perfectly well.

This is the measured vindication of leaving the placeability flag off the roster — the two questions
are decided by different reads at different times — and it is also a misreading an operator could
plausibly make. A line was added under the roster heading rather than a flag on the row:

> A blank **Class** does not mean a student cannot be billed. Whether anyone can be is decided when
> the run executes, and the run report names by admission number anyone it could not place.

### 5.3 — the action bar spanned the whole window and lay across the sidebar

Raised by you from a screenshot mid-run, and it was real: the bar was `fixed inset-x-0 bottom-0` —
copied from `student-bulk-action-bar.tsx`, which still has it — so it was positioned against the
**viewport**, not the content area.

**`position: sticky` inside the content column was tried and does not work here**, measured rather
than assumed. The shell's `<main data-slot="sidebar-inset">` computes `overflow: auto`, which makes
it the bar's scrollport, and it is sized by its content (`min-h-svh`, grows) so it never scrolls —
the document does. A sticky element whose scrollport never scrolls never engages; the probe found the
bar sitting at `top: 1430` in a 1400px viewport, below the fold:

```
{"position":"sticky","barTop":1430,"barBottom":1488,"viewportH":1400,"docScrollY":0,
 "blockers":["MAIN.bg-background relative flex max-w-full min-h-svh flex-1 … overflowY=auto overflowX=auto h=1512"]}
```

The bar therefore stays `fixed` and **copies its horizontal box from the content column**, re-measured
by a `ResizeObserver` on that column plus a window `resize` listener. Nothing in it knows the
sidebar's width, its collapsed state or its breakpoint — it tracks the column, and the column is
already laid out correctly at every size. Measured at four widths, bar box against content-card box:

```
{"viewport":1500,"bar":{"left":288,"right":1460},"contentCard":{"left":288,"right":1460},"sidebarRight":248}
{"viewport":1100,"bar":{"left":288,"right":1060},"contentCard":{"left":288,"right":1060},"sidebarRight":248}
{"viewport":820, "bar":{"left":280,"right":788}, "contentCard":{"left":280,"right":788}, "sidebarRight":248}
{"viewport":600, "bar":{"left":16, "right":584}, "contentCard":{"left":16, "right":584}, "sidebarRight":369}
```

Identical to the content column at every width, and clear of the sidebar at all three desktop sizes.
At 600px the sidebar is off-canvas and the bar takes the column's full width.
Screenshots: `07-footer-bar-1500px.png`, `-1100px`, `-820px`, `-600px`; the rejected sticky attempt,
with the bar sitting below the fold, is `08-rejected-sticky-bar-not-pinned-{top,bottom}.png`.

**Not fixed here, and it is the same defect:** `student-bulk-action-bar.tsx` and
`guardians/bulk-action-bar.tsx` both still use `fixed inset-x-0 bottom-0` and both still lie across
the sidebar. A drive observes; repairing two screens this commit does not touch is a separate change.

---

## 6. The drive

Environment: `APP_ENV=drive`, `portal_drive`, `localhost:8001`, `pnpm run build` before the browser.
Seat: **`maker@drive.test`** (`accounts_officer`, School A), plus `school-b@drive.test` for isolation.
Driver: `puppeteer-core` against system Chrome, installed **outside** the repository.
Screenshots: `docs/handoff/drives/2026-08-30-manual-invoicing/`.

The queue is `database` in this environment, so a dispatched run stays `pending` until a worker runs.
That is what made the in-flight arm reachable, and `queue:work --once` is what completed each run.

### The three fixture count tables, verbatim

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the bulk-run screen (U6) prices a COHORT from an ACTIVE schedule and reports the unplaceable; the decisions surface (U13/U14) reads back what a checker has already settled:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------------+---------------------+-------------+----------------------+---------------+
| School | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Active schedules | Cohort at slot | Awarded in cohort | Sponsored in cohort | Unplaceable | Decided credit notes | Decided voids |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------------+---------------------+-------------+----------------------+---------------+
| A (school#1) | 2 | 2 | 2 | 2 | 3 | 5 | 0 | 2 | 8 | 1 | 5 | 2 | 1 | 9 | 2 | 1 |
| B (school#2) | 2 | 2 | 2 | 1 | 3 | 0 | 0 | 0 | 1 | 1 | 5 | 2 | 1 | 1 | 0 | 0 |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------------+---------------------+-------------+----------------------+---------------+
Bulk invoice runs: /finance/bulk-invoice-runs — the cohort above sits at (term, JSS 1); JSS 2 has an empty one on purpose.
 School A (school#1) billable schedule lines: Tuition ₦250,000.00 (discountable) · Development levy ₦30,000.00 (NOT discountable)
 School B (school#2) billable schedule lines: Tuition ₦250,000.00 (discountable) · Development levy ₦30,000.00 (NOT discountable)

Authoring slot per school — … the guardians screen links a new guardian to students by admission number; the Scholarships tab classifies an UNCONFIGURED scholarship:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+
| School | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Students | Guardians | Scholarships | Scholarships (unconfigured) |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+
| A (school#1) | 2 | 2 | 2 | 2 | 3 | 5 | 0 | 2 | 8 | 19 | 0 | 3 | 1 |
| B (school#2) | 2 | 2 | 2 | 1 | 3 | 0 | 0 | 0 | 1 | 10 | 0 | 3 | 1 |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+

Authoring slot per school — the BSS discount-award import (/finance/discount-award-imports) resolves each row of a sheet to an ACTIVE percentage policy on a (percentage, base) PAIR, and asks the student's SCHOLARSHIP whether a discount may be awarded at all:
+--------------+-------------+-------------------+----------+---------------------------+------------------------+-----------------+
| School | Award pairs | Discount policies | Students | On a discount scholarship | On another scholarship | Discount awards |
+--------------+-------------+-------------------+----------+---------------------------+------------------------+-----------------+
| A (school#1) | 3 | 3 | 19 | 4 | 3 | 2 |
| B (school#2) | 3 | 3 | 10 | 4 | 3 | 2 |
+--------------+-------------+-------------------+----------+---------------------------+------------------------+-----------------+
 School A (school#1) admission numbers: ADM66330, ADM74936, ADM91582, ADM11452, ADM91856, ADM60383, ADM99768, ADM28764, ADM89301, ADM16001, ADM57244, ADM98435, ADM02325, ADM21408, ADM27424, ADM30946, ADM45785, ADM88725, ADM77182
 School B (school#2) admission numbers: ADM83183, ADM78767, ADM62428, ADM41180, ADM61216, ADM61129, ADM96519, ADM99274, ADM63672, ADM50151
```

**No zero in any column this screen depends on**, and **no new column was needed**: Students (19 / 10),
Class levels (2 / 2), Bank accounts (2 / 1) and Scholarships (3 / 3) are all already counted, and
School A's 19 students at 5/page give the multi-page state the page-scoped arm requires.

### Arm 0 — the screen opens for the bursar seat, catalogs populated

`url=http://localhost:8001/finance/manual-invoice-runs h1="Bulk manual invoicing"`. Console clean
apart from the known pre-existing `GET /dashboard` 403 (filed friction, unrelated).

```
PAGE dropdown #0 current="" options(3): ["|All class levels","7290612a-0c90-43cc-83d9-dd00553491d0|JSS 1","ea6b0caa-7600-4264-b9a0-032f9e0e48c9|JSS 2"]
PAGE dropdown #1 current="" options(2): ["|All arms","59932560-2da4-43a2-8ddc-cceb6cd6bc7b|A"]
PAGE dropdown #2 current="" options(5): ["|All scholarships","none|No scholarship","a2a11cc1-bf0e-4c8c-b090-624d38999759|BSS","a2a11cc1-c2be-4fa5-8fdd-a1015254eacd|C2C","a2a11cc1-c1fe-4ff8-8a67-a5b24693a8bb|Endowed"]
PAGE dropdown #3 current="" options(3): ["|Choose an account…","a2a11cc5-821d-48c2-9f44-dcc6131cd2ca|Drive account · Drive Bank","a2a11cc6-45ce-4083-929a-b3fccab4295f|Drive trips account · Drive Bank"]
ROSTER rows (19)   WARNING: null      (19 ≤ 25/page, so one page and nothing to warn about)
```

Screenshot: `01-screen-open.png`.

### Arm 1 — filter, tick, confirm, submit, land on the report

```
FILTER class_level = 7290612a-0c90-43cc-83d9-dd00553491d0 (JSS 1)
ROSTER after filter (5):
  ADM16001 | JSS 1 A | —        ADM45785 | JSS 1 A | BSS      ADM77182 | JSS 1 A | Endowed
  ADM88725 | JSS 1 A | BSS      ADM89301 | JSS 1 A | —
TICKED: ["ADM16001","ADM45785"]
DESTINATION = a2a11cc5-821d-48c2-9f44-dcc6131cd2ca
PER-STUDENT TOTAL on screen: "Each student is charged ₦1,500.00"
```

Five of nineteen — the cohort at the slot, matching `Cohort at slot = 5`. The confirmation, verbatim:

> **Start this run? It cannot be undone.**
> This raises **2** invoice(s) — one for each student you ticked — and charges every one of them
> **₦1,500.00** across **1** line(s).
> `Description | Each | Paid into` → `Excursion — Term 1 | ₦1,500.00 | Drive account`
> There is no approval step and no undo. Reversing one of these invoices takes a void request and a
> second person's approval, one at a time, for every student it billed. Starting the same list twice
> bills everyone on it twice.
> A student with no current enrolment cannot be billed and will be listed on the run report by
> admission number.
> `Cancel` · `Bill 2 student(s)`

All four required facts are on it: how many students, the per-student total, the number of lines, and
each destination account by name. Arithmetic on screen: one line at ₦1,500.00 → `Each student is
charged ₦1,500.00`; nothing in the page computed the amount from anything but `sumMinor`.

```
AFTER SUBMIT url = http://localhost:8001/finance/manual-invoice-runs/a2a11ff2-2906-4f0a-baba-a788572a35ea
```

The report, still pending:

> You selected **2** student(s). This run has accounted for **0** of them.
> SELECTED 2 · BILLED 0 · FAILED 0 · UNPLACEABLE 0 · UNACCOUNTED FOR 0
> *This run is still going, so whether every student is accounted for cannot be answered yet. A
> shortfall here is normal until it finishes.*
> Each bucket: *"Nothing here yet — this run is still going."*

Then `queue:work --once` → `completed target=2 billed=2 unplaceable=0`, and the same page:

> You selected **2** student(s). This run has accounted for **2** of them.
> SELECTED 2 · BILLED 2 · FAILED 0 · UNPLACEABLE 0 · UNACCOUNTED FOR 0
> *Every student you selected is accounted for: billed, failed or unplaceable.*
> Billed 2 — `ADM16001`, `ADM45785`

The empty-state sentences switch with terminality — *"Nothing here yet — this run is still going"*
becomes *"Every student you selected resolved to a current enrolment."* — which is the
running-vs-finished distinction rendered rather than claimed.
Screenshots: `01a`–`01d`, `05-run-report-completed.png`.

### Arm 5 — a mixed selection, so the unplaceable are NAMED

```
TICKED placeable   : ["ADM16001","ADM45785"]
TICKED unplaceable : ["ADM02325","ADM11452","ADM21408"]     (all three showed Class "—")
CONFIRMATION: "This raises 5 invoice(s) … charges every one of them ₦2,500.00 across 1 line(s)."
```

After the worker:

> You selected **5** student(s). This run has accounted for **5** of them.
> SELECTED 5 · BILLED **3** · FAILED 0 · UNPLACEABLE **2** · UNACCOUNTED FOR 0
> *Every student you selected is accounted for.*
> **Could not be placed — 2:** `ADM02325`, `ADM21408`
> **Billed — 3:** `ADM16001`, `ADM45785`, `ADM11452`

`3 + 0 + 2 = 5 = target_count`, and the server's `balances` says so. **`ADM11452` billed despite a
blank Class** — §5.2. Screenshots: `06a`, `06b`.

### Arm 2 — a line with no destination account

Client side, the confirmation is unreachable and the reason is on screen:

```
{"button":"Invoice selected (1)","buttonDisabled":true,
 "hint":"Every line needs a description, an amount and an account before a run can start."}
```

Forced past the client with a same-origin `fetch` so the **server's** refusal is what is measured:

```
{"status":422,"body":{"message":"There are validation errors",
 "errors":{"lines.0.bank_account_id":["The lines.0.bank_account_id field is required."]}}}
```

**Keyed to the row**, which is the requirement, and the screen renders it under that line's
destination select. Screenshot: `02a-no-destination-client-refuses.png`.
**The wording is a finding** — see §8.

### Arm 3 — tick, change page, come back

Before any tick, with 19 students at 5/page:

> **Ticks apply to this page only. Turning the page or changing a filter clears them.** 19 student(s)
> match these filters, across 4 pages of 5. They fit on one page — use the page-size control below to
> show them all, then tick. · `Show all 19 on one page`

After ticking three, the same banner escalates:

> **Ticks apply to this page only. Turning the page or changing a filter will clear the 3 you have
> ticked.** …

```
TICKED page 1: ["ADM02325:true","ADM11452:true","ADM16001:true","ADM21408:false","ADM27424:false"]
FOOTER: {"footerLabel":"3 selected on this page","button":"Invoice selected (3)"}

PAGE 2  : ["ADM28764:false","ADM30946:false","ADM45785:false","ADM57244:false","ADM60383:false"]
FOOTER  : {"footerLabel":null,"button":null}          ← the bar is gone; nothing is ticked

BACK ON PAGE 1: ["ADM02325:false","ADM11452:false","ADM16001:false","ADM21408:false","ADM27424:false"]
FOOTER  : {"footerLabel":null,"button":null}
```

**The selection cleared, and the operator was told it would before it happened.** Both halves
screenshotted: `03a-warning-before-ticking.png`, `03b-three-ticked-and-warned.png`,
`03c-page-two.png`, `03d-back-on-page-one-selection-cleared.png`.

### Arm 4 — a second run while one is in flight

```
http 422: POST http://localhost:8001/api/v1/finance/manual-invoice-runs
REFUSAL: "A manual invoice run is already under way for this school
          (a2a11ff2-2906-4f0a-baba-a788572a35ea). Wait for it to finish, then read its report before
          starting another — a second run over the same list bills everyone on it again."
linkButton: "Open that run’s report"
```

Rendered as words, in the server's own wording, naming the run — and the uuid is **arm 1's run**,
character for character. The link is a real recovery path: there is no index of past runs, so it is
currently the only way back to a run whose uuid the operator did not keep.
Screenshot: `04-second-run-refused-in-words.png`.

### Isolation — both seats, side by side, by id

```
Seat 1 — maker@drive.test (accounts_officer, school#1)
  class levels : ["7290612a-0c90-43cc-83d9-dd00553491d0|JSS 1","ea6b0caa-7600-4264-b9a0-032f9e0e48c9|JSS 2"]
  arms         : ["59932560-2da4-43a2-8ddc-cceb6cd6bc7b|A"]
  scholarships : ["a2a11cc1-bf0e-4c8c-b090-624d38999759|BSS","a2a11cc1-c2be-4fa5-8fdd-a1015254eacd|C2C","a2a11cc1-c1fe-4ff8-8a67-a5b24693a8bb|Endowed"]
  accounts     : ["a2a11cc5-821d-48c2-9f44-dcc6131cd2ca|Drive account · Drive Bank","a2a11cc6-45ce-4083-929a-b3fccab4295f|Drive trips account · Drive Bank"]
  roster (19)  : ADM02325 ADM11452 ADM16001 ADM21408 ADM27424 ADM28764 ADM30946 ADM45785 ADM57244
                 ADM60383 ADM66330 ADM74936 ADM77182 ADM88725 ADM89301 ADM91582 ADM91856 ADM98435 ADM99768

Seat 2 — school-b@drive.test (isolation, school#2)
  class levels : ["53faed67-4ba8-4327-81f4-5585dfffabd5|JSS 1","a42cc1a0-f670-4589-ab50-53fe097a1335|JSS 2"]
  arms         : ["289f218f-fc31-4f22-b9ae-a07f66788bc3|A"]
  scholarships : ["a2a11cc1-c376-42a1-bde8-bb5991aee103|BSS","a2a11cc1-c4df-47fa-bd19-59478190961b|C2C","a2a11cc1-c42c-4f01-9745-57151f1ec053|Endowed"]
  accounts     : ["a2a11cc5-8359-4cf3-90dd-4325ea345a60|Drive account · Drive Bank"]
  roster (10)  : ADM41180 ADM50151 ADM61129 ADM61216 ADM62428 ADM63672 ADM78767 ADM83183 ADM96519 ADM99274
```

**Every label matches character for character across the two schools** — "JSS 1", "A", "BSS", "C2C",
"Endowed", "Drive account · Drive Bank" — and every value is disjoint, as are the two admission-number
sets. School B sees one bank account to School A's two, matching the fixture table. This is why the
check is by id.

### What was NOT driven, and why

- **A cohort above 100.** The fixture's largest school has 19 students, so the "cannot be put on one
  page" branch of the banner was never rendered. It is covered by the clamp arm and by reading the
  predicate, not by eye.
- **A row the ACL port cannot display** (null uuid → un-tickable row with its own sentence). It
  cannot arise while the roster query and `displayFor()` agree about the School and the soft-delete
  scope, which they do; there is no fixture state that produces it.
- **Bucket truncation at 200.** Nineteen students cannot make a bucket of 201.
- **The `claimed` bucket.** It needs a worker that dies mid-list; nothing on this fixture stages one.
- **A `failed` row.** Every placeable student billed cleanly.
- **`super_admin` with no School on the roster** (the 403). Asserted in arm 2f; not driven, because
  the local drive cast's `super@drive.test` would have to be walked through the school picker and the
  refusal is already measured at the HTTP layer.
- **The dark-mode palette.** Every screenshot is light.

---

## 7. Gates run locally

| Gate | Result |
|---|---|
| `pest tests/Feature/Finance/ManualInvoiceRunPageTest.php` | **13 passed, 107 assertions** |
| `pest tests/Feature/Finance/FinanceNavCoverageTest.php` | 14 passed, 41 assertions |
| `pest tests/Feature/Finance/ManualInvoiceRunScreenTest.php` | 17 passed — commit 2 untouched |
| `pest tests/Feature/Finance` | **962 passed, 5231 assertions** (was 947 at commit 2) |
| `pest --group=arch` | **115 passed, 600 assertions** |
| `pest tests/Feature/Rbac/RouteAccessParityTest.php` + `RouteMiddlewareBaselineTest.php` | 19 passed — **no oracle regeneration**; the three new routes are additive |
| `composer analyse` (Larastan) | `{"tool":"phpstan","result":"passed","errors":0}` |
| `tsc` | **42 errors — exactly the `tsc-baseline`**; none in the new files |
| `pnpm run build` | built |
| `pint --test` (changed + untracked, array form) | passed |
| `prettier --check` (changed + untracked) | "All matched files use Prettier code style!" |
| `eslint` (changed + untracked) | clean |
| `php bin/ci-authz-lint.php` | OK — 0 known |
| `php bin/ci-boundary-lint.php` | OK — 8 known temporary exceptions, unchanged |
| `php bin/ci-money-lint.php` | OK — 0 known exceptions |
| `php bin/ci-citation-lint.php` | OK — 165 baselined keys, 182 citations |
| `pest tests/Feature/Quality/PestNegatedExpectationMessagesTest.php` | **passed** — was red on one new offender; see §4.1 |
| `bin/quality` | **NOT run** — reserved for your terminal |

**One gate caught a real defect rather than a formatting one.** `bin/ci-citation-lint.php` refused a
bare `app/Support/ActiveSchool.php:70` in the new test — a citation must name a symbol. It surfaced
as **ten** `--group=arch` failures in `CitationLintCoverageTest`, every one of them downstream of the
single dirty citation; naming the symbol turned all ten green. Worth recording because the ten
failures read like a broken lint rather than one bad line in my own file.

`git diff --stat` against my own model of the change: 4 tracked files modified (sidebar +19, the two
route files, the nav test +100), 5 new files. No unrelated sweep.

---

## 8. Residuals, and what is still open

1. **A cohort above 100 cannot be billed as one run.** Selection is page-scoped and the largest page
   is 100. 91 fits; 120 does not, and the screen says so rather than working around it. The answer is
   brief §1's server-side "everyone matching this filter" scope, resolved from the filter payload —
   **the next commit**, and not this one, because `POST /v1/finance/manual-invoice-runs` takes explicit
   ids only and assembling that list in the browser is the thing the rule forbids.
2. **The destination refusal reads `The lines.0.bank_account_id field is required.`** It is correctly
   keyed to the row, which is what was asked for, but it names a wire attribute at a bursar. The fix
   is an `attributes()` / `messages()` override on `StoreManualInvoiceRunRequest` — commit 2's class,
   which this commit was told not to touch — so it is reported rather than done. Worth a ticket.
3. **There is still no index of past runs.** The report is reachable only by landing on it after
   submit and by the in-flight refusal's link. A bursar who navigates away from a completed run and
   did not keep the uuid has no way back. Commit 2 recorded this as residual #4 and said a list
   "belongs with the screen"; it needs an endpoint, which this commit does not add.
4. **`student-bulk-action-bar.tsx` and `guardians/bulk-action-bar.tsx` still lie across the sidebar.**
   Same `fixed inset-x-0 bottom-0` defect this screen's bar was fixed for (§5.3). Untouched: a drive
   observes, and two screens this commit does not own are a separate change.
5. **The in-flight refusal's uuid is parsed out of prose** by the screen, to offer the recovery link.
   The parse degrades safely — a reworded message loses the link and keeps the sentence — but the
   honest fix is the server returning the uuid as its own field on the 422.
6. **The guardians select-all-matching lint is a text check with a known false positive.** Naming
   `guardians/bulk-action-bar` a second time, even in a comment, reds it. The failure message says so.
   There is no JavaScript test runner in this repository, so it is this or nothing.
7. **`ManualInvoiceRunStudentController` reads `App\Models\Student` and `App\Services\StudentIndexFilters`
   from inside `app/Finance/`.** Permitted — neither is among the four models arch rule 3 forbids, and
   `StoreManualInvoiceRunRequest` already reads `Student` directly — and chosen so the filter set has
   one definition rather than a third copy. But it is a Finance file reading an Academics-shaped
   concern, and if the ACL port ever grows a roster method this should move behind it.
8. **Brief §6's governance question stays answered-and-recorded, not enforced.** Brookstone ruled on
   30 August that this issues directly. Nothing in the code would stop a maker-checker being added
   later; nothing asserts one is absent either.
