# DRIVE — the money: a BSS discount award reaching an invoice

**Branch** `drive/bss-money` · **Driven** 2026-08-28 on the throwaway drive fixture, `APP_ENV=drive`,
`localhost:8001`, Chrome via `puppeteer-core` installed **outside** the repository
(`~/drive-harness-money`). Never the production copy.

**Screens:** `/finance/bulk-invoice-runs` (start and preview), `/finance/bulk-invoice-runs/{uuid}`
(the run's report), `/finance/students/{uuid}/statement` and `/finance/invoices/{uuid}` (where the
money actually is).

The three drives before this proved a ROW was written. This one is the first to show a naira leaving
an invoice.

---

## 0 · THE BRIEF'S §2 PREMISE WAS FALSE, AND THE FIXTURE COULD NOT HAVE PROVEN ANYTHING

Stated first because everything below depends on it having been fixed.

The brief says: *"DriveFinanceStates seeds one discountable fee item (:277) and one non-discountable
(:288). That single false flag is what makes A and B land on DIFFERENT totals."*

The two items are real and the line numbers were right. **But the non-discountable item is also
`is_mandatory = false`, and a bulk run bills mandatory items only** —
`FeeScheduleLineMapper::linesFor()` filters `->where('is_mandatory', true)` before it maps a single
line, and its own docblock says so (*"nothing in the schema records which student takes the bus"*).

So the bus line can never appear on an invoice a run raises. **Every invoice the fixture's runs
produced held exactly one line — Tuition — and that line was discountable.** On such a bill
`discountable` and `total` denote the same money:

```
50% of discountable  = 50% × 250,000 = 125,000
50% of the whole bill = 50% × 250,000 = 125,000     ← identical
```

A and B would have differed only by their PERCENTAGE (50 vs 100), never by their BASE, and an
implementation that ignored the base axis entirely would have produced the same four invoices and
passed this drive. That is precisely the degenerate fixture the brief warns against, one level in
from where it was looking: **it is not enough for the SCHEDULE to be mixed — the BILLED SUBSET has
to be.**

**Fixed as fixture** (the skill's named exception): a third fee item, **mandatory AND
non-discountable** — `Development levy`, ₦30,000.00. The bus line is untouched and still guards the
optional-items property another drive reads. With the levy on the bill the two bases separate:

```
half the discountable  = 125,000     →  total 155,000
half the whole bill    = 140,000     →  total 140,000     ← would have been visible as a wrong bill
```

The arithmetic in § 5 is only meaningful because those two numbers are different.

---

## 1 · The seat that may start a run — surveyed, not assumed

`grep -n FINANCE_INVOICE_GENERATE database/seeders/RbacSeeder.php` returns **two** lines, `:248` and
`:407`. Their enclosing blocks open at `:210` (`'admin'`) and `:387` (`'accounts_officer'`). So a run
may be started by **`admin`** and **`accounts_officer`**, and by nobody else.

| Seat | Role | Why it is here |
| --- | --- | --- |
| `maker@drive.test` | `accounts_officer`, school#1 | Holds `finance.invoice.generate`. Previews, starts, and reads back. |
| `school-b@drive.test` | `accounts_officer`, school#2 | Isolation — holds the same ability in a different school, which is the only way to prove the boundary rather than the gate. |
| `checker@drive.test` | `executive_director`, school#1 | **The refusal, and the interesting one.** The ED holds every finance CHECKER ability and approved the very discount policies these invoices cite — and still may not raise a bill. Refusing a seat that holds almost nothing proves less. |

`admin@drive.test` also holds the ability and was **not** driven; noted in § 9.

---

## 2 · Fixture changes, and the count columns added before the browser

**(a) The third fee item** — argued in § 0.

**(b) Three PLACED students per school.** The award-import drive's four holders are deliberately
unenrolled, so not one of them is in any cohort and none can be billed. Added
`DriveCastSeeder::seedCohortAwardHolders()`: two on the discount scheme and one on the sponsored
scheme, all three placed at the pricing coordinates. **They are different students from the import
drive's four, on purpose** — that drive needs holders with NO award so its first upload can report
`awarded`; this one needs holders WITH one, and the same student cannot be both.

**(c) Two standing awards per school, through the real Action.** `DriveFinanceStates::awardDiscount()`
calls `AwardStudentDiscount`, which is where the gate lives: it re-checks
`finance.discount-award.manage` against the actor, refuses a non-discount scholarship and refuses a
second award. A row write would skip all three. Paired **by position** — element 0 to the
discountable-base policy, element 1 to the whole-bill one — which is the only thing that makes A and
B mean different bases. Each school's own bursar is the actor, because the gate resolves in the
ACTIVE school and School A's maker holds nothing in School B.

**(d) The control comes free.** `Cody Cohort` and `Cleo Cohort` were already placed, carry no
scholarship and carry no award. Without an unreduced total beside the reduced ones there is nothing
to read them against.

**(e) Two new columns on table 1, and one printed line.** Per the skill's rule, and neither column is
derivable from one beside it:

| Column | Why nothing existing answers it |
| --- | --- |
| `Awarded in cohort` | `Discount awards` (table 3) is School-WIDE and counts the import drive's four unplaced holders. A school can show awards and bill none of them — a run whose every invoice is full price. |
| `Sponsored in cohort` | `On another scholarship` (table 3) counts sponsored **and** unconfigured holders anywhere in the school. The exclusion arm fires only for a sponsored student actually AT the coordinates. |

Both read the cohort **through the ACL port**, so they count exactly the population the run bills.
The printed line names the schedule's **mandatory** lines with their amounts and discountability —
the inputs to every total checked by hand below, and the thing whose absence caused § 0.

**One side effect handled rather than left.** `unallocatedRemainder()` picked "the mandatory item"
with `value('id')`, unambiguous while there was one. A second mandatory item made it "whichever row
the engine returns first", so it now carries `orderBy('sort_order')->orderBy('id')` and
deterministically takes Tuition, which is what the line beside it describes.

**The skill's enumeration was re-read against the command's actual output** and updated: table 1 is
now SEVENTEEN columns, and `Discount awards` is no longer zero on a fresh fixture.

---

## 3 · All three fixture count tables, verbatim

From the seed this drive was conducted against.

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the bulk-run screen (U6) prices a COHORT from an ACTIVE schedule and reports the unplaceable; the decisions surface (U13/U14) reads back what a checker has already settled:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------------+---------------------+-------------+----------------------+---------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Active schedules | Cohort at slot | Awarded in cohort | Sponsored in cohort | Unplaceable | Decided credit notes | Decided voids |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------------+---------------------+-------------+----------------------+---------------+
| A (school#1) | 2                 | 2     | 2            | 2             | 3                 | 5                 | 0                   | 2                     | 8             | 1                | 5              | 2                 | 1                   | 9           | 2                    | 1             |
| B (school#2) | 2                 | 2     | 2            | 1             | 3                 | 0                 | 0                   | 0                     | 1             | 1                | 5              | 2                 | 1                   | 1           | 0                    | 0             |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------------+---------------------+-------------+----------------------+---------------+
Bulk invoice runs: /finance/bulk-invoice-runs — the cohort above sits at (term, JSS 1); JSS 2 has an empty one on purpose.
  School A (school#1) billable schedule lines: Tuition ₦250,000.00 (discountable) · Development levy ₦30,000.00 (NOT discountable)
  School B (school#2) billable schedule lines: Tuition ₦250,000.00 (discountable) · Development levy ₦30,000.00 (NOT discountable)

Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the guardians screen links a new guardian to students by admission number; the Scholarships tab classifies an UNCONFIGURED scholarship:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Students | Guardians | Scholarships | Scholarships (unconfigured) |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+
| A (school#1) | 2                 | 2     | 2            | 2             | 3                 | 5                 | 0                   | 2                     | 8             | 19       | 0         | 3            | 1                           |
| B (school#2) | 2                 | 2     | 2            | 1             | 3                 | 0                 | 0                   | 0                     | 1             | 10       | 0         | 3            | 1                           |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+

Authoring slot per school — the BSS discount-award import (/finance/discount-award-imports) resolves each row of a sheet to an ACTIVE percentage policy on a (percentage, base) PAIR, and asks the student's SCHOLARSHIP whether a discount may be awarded at all:
+--------------+-------------+-------------------+----------+---------------------------+------------------------+-----------------+
| School       | Award pairs | Discount policies | Students | On a discount scholarship | On another scholarship | Discount awards |
+--------------+-------------+-------------------+----------+---------------------------+------------------------+-----------------+
| A (school#1) | 3           | 3                 | 19       | 4                         | 3                      | 2               |
| B (school#2) | 3           | 3                 | 10       | 4                         | 3                      | 2               |
+--------------+-------------+-------------------+----------+---------------------------+------------------------+-----------------+
  School A (school#1) award pairs: 10% of DISCOUNTABLE CHARGES · 50% of DISCOUNTABLE CHARGES · 100% of THE WHOLE BILL
  School B (school#2) award pairs: 10% of DISCOUNTABLE CHARGES · 50% of DISCOUNTABLE CHARGES · 100% of THE WHOLE BILL
  School A (school#1) admission numbers: ADM72874, ADM42670, ADM66299, ADM38706, ADM68090, ADM30296, ADM15262, ADM14648, ADM41299, ADM41143, ADM21683, ADM41521, ADM53416, ADM61548, ADM75291, ADM85873, ADM61619, ADM86693, ADM70525
  School B (school#2) admission numbers: ADM20315, ADM60999, ADM84246, ADM11905, ADM50917, ADM61745, ADM63129, ADM93931, ADM11420, ADM28989
```

**The cohort, resolved through the port rather than inferred from seeder order:**

```
school#1 term#1 classLevel#1
   student#10 ADM41299 kind=-          award=none          ← D, control
   student#11 ADM41143 kind=-          award=none          ← D, control
   student#24 ADM61619 kind=discount   award=policy#3      ← A, 50% of DISCOUNTABLE CHARGES
   student#25 ADM86693 kind=discount   award=policy#4      ← B, 100% of THE WHOLE BILL
   student#26 ADM70525 kind=sponsored  award=none          ← C, the exclusion arm
```

---

## 4 · The run — preview, and its own report

Preview as `maker@drive.test`, read out of the DOM:

```
STUDENTS IN THIS COHORT   5    Billable enrollments at these coordinates.
ALREADY BILLED            0    Of that cohort. They will be recorded, not billed again.
WOULD BE BILLED           5    Cohort minus those already carrying a term bill.
FEE SCHEDULE              Drive term bill v1 (active) · 2 mandatory item(s) on every invoice
```

The confirmation button read **`Bill 5 student(s)`**. See the finding in § 8 — four were billed.

**The run's own report** (`/finance/bulk-invoice-runs/a29ce778-…`), verbatim:

```
Cohort                  5    Billable enrollments at this run's coordinates.
Billed                  4    Term bills this run raised.
Already billed          0    Had a term bill already. Not an error.
Failed                  0    Could not be billed. Each row carries its reason.
Sponsored               1    Excluded on purpose. Billed by hand, not by this run.
Could not be placed     9    No term or no class level, so no run can bill them.
Billable in this school 14   Counted school-wide, at the moment this run executed.
Priced at other coords  0
```

**C appears as EXCLUDED — the right one of the three states.** Its own section, with its own
sentence:

> **Sponsored — billed by hand · 1 rows** — These students are on a sponsored scholarship, so an
> organisation pays for them on a different fee basis, once a session, off platform. This run left
> them alone deliberately — they are not a failure and re-running will not bill them. Their invoices
> are raised by hand, and this is the list to raise them from.

`ADM70525` is the only row in it. Not billed at zero, not billed and reduced to nothing: **no invoice
exists for that student at all**, and the statement in § 5 confirms it.

`run-01-screen.png`, `maker-02-preview.png`, `run1-04-report.png`

---

## 5 · The arithmetic — every total on screen, derived by hand beside it

Inputs, from the fixture's own printed line: **Tuition ₦250,000.00 (discountable)** and
**Development levy ₦30,000.00 (NOT discountable)**. Both mandatory, so both on every bill.

### D — the control, no award (`ADM41299`, `ADM41143`)

```
250,000.00 + 30,000.00 = 280,000.00
```

Statement row, verbatim: `["000013 Term bill", "JSS 1 · 2026/2027 · First Term", "Issued Unpaid",
"₦280,000.00 ₦280,000.00 outstanding", …]`. Invoice detail lines:

```
["Tuition","Charge","₦250,000.00"]
["Development levy","Charge","₦30,000.00"]
TOTAL ₦280,000.00
```

Rendered `₦280,000.00`; **nothing in the page computed it** — the total is derived server-side in
`GenerateInvoice` and frozen by F6's trigger. Every number below is read against this one.

### A — 50% OF DISCOUNTABLE (`ADM61619`, policy#3)

```
discountable charges          = 250,000.00        (Tuition only — the levy is not discountable)
reduction  = 50% × 250,000.00 = 125,000.00
total      = 250,000.00 + 30,000.00 − 125,000.00 = 155,000.00
```

Invoice detail lines, verbatim:

```
["Tuition","Charge","₦250,000.00"]
["Development levy","Charge","₦30,000.00"]
["BSS scholarship — half of discountable charges","Discount","-₦125,000.00"]
TOTAL ₦155,000.00 · ₦155,000.00 outstanding
```

**The non-discountable item is untouched.** The levy is on the bill at its full ₦30,000.00 and is not
in the reduction's base. **This is the arm the whole drive exists for:** had the base been read as
`total`, the reduction would have been 50% × 280,000 = **₦140,000.00** and the total **₦140,000.00**.
The screen shows ₦125,000.00 and ₦155,000.00. The two readings differ by ₦15,000.00 per child, and
before § 0's fixture change they would have been the same number.

### B — 100% OF THE WHOLE BILL (`ADM86693`, policy#4)

**Observed, not expected.** The question was open: a zero invoice, a skipped student, or a refusal.

```
whole bill = 250,000.00 + 30,000.00 = 280,000.00
reduction  = 100% × 280,000.00      = 280,000.00
total      = 280,000.00 − 280,000.00 = 0.00
```

Invoice detail lines, verbatim:

```
["Tuition","Charge","₦250,000.00"]
["Development levy","Charge","₦30,000.00"]
["BSS scholarship — the whole bill","Discount","-₦280,000.00"]
TOTAL ₦0.00 · ₦0.00 outstanding
```

**It raised a real invoice with a zero total, status `Issued` / `Settled`, and it did the sensible
thing.** Reading it precisely, because "it worked" is not a finding:

1. **The reduction covers BOTH items** — ₦280,000.00, not ₦250,000.00. `total` means the whole bill,
   including the line the other base leaves alone.
2. **Zero is allowed; negative is not.** `GenerateInvoice` refuses a zero LINE (*"An invoice line
   amount may not be zero"*) and refuses a NEGATIVE total (*"Reductions may not exceed the charges"*)
   — and says nothing about a zero total. So a 100% award lands exactly in the gap between the two
   guards, by construction rather than by luck.
3. **The status is `Settled`, not `Unpaid`,** and the invoice offers `Submit credit note` and
   `Request void` but **not** `Record payment` — which is right: there is nothing to pay. The two
   control invoices beside it do offer it. Nothing was special-cased for zero; the outstanding
   figure simply is zero and the screens follow it.
4. **The document exists.** A skipped student would have left the family with no bill and no record
   of why they owe nothing; this leaves a bill that states the scholarship on its face.

### C — sponsored (`ADM70525`)

Statement, verbatim: `["No invoices yet."]`. Excluded, as § 4 shows — not billed at zero.

`invoice-D-control-ADM41299.png`, `invoice-D-control-ADM41143.png`,
`invoice-A-50pc-discountable-ADM61619.png`, `invoice-B-100pc-whole-bill-ADM86693.png`,
`statement-C-sponsored-ADM70525.png`

---

## 6 · The reduction is visible and attributed

On both reduced invoices the reduction is **its own line**, typed `Discount`, negative, and described
by the **policy's own name** — `BSS scholarship — half of discountable charges` and
`BSS scholarship — the whole bill`. Those names were authored through the discount-policy approval
flow and approved by the ED, so a bursar reading the bill is reading the governed text, not a label
this feature invented.

In the row itself the line carries `discount_policy_id = 3` / `= 4` — the citation the
`finance_invoice_lines_reduction_guard` requires.

**What is NOT there, stated because it is the difference between "cites" and "links":** the rendered
line is not a hyperlink to the policy, and the invoice does not show the percentage or the base as
such. A bursar defending the figure to a parent reads a name and an amount, and must go to
`/finance/discount-policies` and match the name by eye to see "50% of discountable charges". That is
attribution, and it is not navigation.

---

## 7 · Re-run — nobody is billed twice

The same coordinates, the same seat, immediately after. The confirmation again read
`Bill 5 student(s)`.

```
Cohort            5
Billed            0    Term bills this run raised.
Already billed    4    Had a term bill already. Not an error.
Failed            0
Sponsored         1    Excluded on purpose.
```

> **Already billed · 4 rows** — These already carried a term bill for their episode, so this run
> raised none. That is not an error — it is what a safe re-run looks like.

Listing `ADM41299`, `ADM41143`, `ADM61619`, `ADM86693`. Measured either side rather than asserted:

```
run#1  status=completed  cohort=5  billed=4
run#2  status=completed  cohort=5  billed=0
cohort invoices: 4        (unchanged)
```

The sponsored student is `sponsored` again on the second run, not `already billed` — the exclusion is
re-evaluated rather than remembered. `run2-05-report.png`

---

## 8 · FINDING — the confirmation says `Bill 5 student(s)` and four are billed

**Observed, not fixed.**

The preview names the sponsored student in `STUDENTS IN THIS COHORT 5`, then reports
`WOULD BE BILLED 5`, and the confirm button reads `Bill 5 student(s)`. The run then bills **4** and
excludes 1 — for a rule that was fully knowable before the run: that student's scholarship kind was
`sponsored` at preview time, and `Sponsored in cohort` is now a column in the fixture's own count
table precisely because it is a pre-run fact.

**In the screen's defence, and it is a real defence:** `WOULD BE BILLED` defines itself on the same
card as *"Cohort minus those already carrying a term bill"*, and by that definition 5 is correct. The
sponsored student IS in the cohort, IS recorded, and the run's own report explains the exclusion at
length. Nothing is hidden after the fact.

**Why it is still worth reporting:** the CONFIRM BUTTON is not a labelled statistic with a definition
beside it. `Bill 5 student(s)` is a sentence about what pressing it will do, read at the moment of an
irreversible act — the screen's own words are *"Starting a run cannot be undone"* — and it was wrong
by one. On a real cohort with a dozen sponsored children the gap is a dozen, and the operator learns
it only from the report afterwards.

Severity: **ticket**, not stop. Nothing is mis-billed, no money moves wrongly, and the after-the-fact
reporting is exemplary. It is a pre-flight number that could be right and is not.

---

## 9 · Isolation and the refusal — both seats, ids visible

Every label is identical across the two schools by construction. The **ids** are in the query string
of the request each page made for itself:

```
maker@drive.test    (school#1) : /api/v1/finance/bulk-invoice-runs/preview?term_id=1&class_level_id=1
school-b@drive.test (school#2) : /api/v1/finance/bulk-invoice-runs/preview?term_id=3&class_level_id=3
```

Term `1` against term `3`; class level `1` against `3`. Both screens render the string
`2026/2027 — First Term` and the string `JSS 1`, character for character, and both report
`STUDENTS IN THIS COHORT 5` — three identical readings over disjoint data.

**School B's run list, after two runs in School A:**

```
[["No runs yet Preview a term and a class level above to see what a run would bill."]]
```

**And School A's run is unreachable from inside School B's session**, by uuid, through the real XHR
path the page itself uses:

```
school-b fetching School A's run a29ce778-501f-4afa-845c-2f4fc700ef79
  -> {"status":404,"body":"{\"message\":\"Resource not found\"}"}
```

**404, not 403** — a 403 would confirm the run exists.

**The refusal — `checker@drive.test`, the ED who approved these very policies:**

```
GET  /finance/bulk-invoice-runs                    -> 403 | Forbidden
POST /api/v1/finance/bulk-invoice-runs             -> 403 {"message":"User does not have the right permissions.", …}
GET  /api/v1/finance/bulk-invoice-runs/preview…    -> 403
sidebar finance hrefs: ["/finance","/finance/approvals","/finance/decisions"]
offers bulk runs     : false
```

The page, the preview and the write, all three; and the item is not offered. That seat can approve
the percentage and cannot spend it.

`isolation-01-school-b-runs.png`, `refusal-01-checker.png`, `schoolb-02-preview.png`

---

## 10 · Console

One entry, on every seat and every page, and it is pre-existing: the `/dashboard` 403 that finance
seats get from the sidebar's Dashboard link
(`docs/handoff/reports/feat-discount-policies-page.md:456-460`). The checker's three additional
`403`s are its own refusals, being reported correctly.

**No `pageerror`, no failed request, and no console entry originating in any run, statement or
invoice page** across the twenty-odd page loads in this drive.

---

## 11 · What was NOT driven

**THE PARENT-FACING VIEW WAS NOT CHECKED — and this is the one to read.** Nothing in this drive
opened the guardian portal. A 100%-of-total award produces an invoice whose total and outstanding are
both **₦0.00 with an issued document behind it**, and a 50%-of-discountable award produces a bill
carrying a negative line a parent has never seen before. Developer 2's portal reads the same
outstanding figures, and neither of those two shapes has been looked at from the parent's side by
anybody. A zero-total invoice in particular is a document a parent may be shown, may not understand,
and may telephone about.

**Payment against a reduced invoice was not driven.** Nothing was paid, allocated, credited or
voided. `₦155,000.00 outstanding` was read, never settled — so the interaction between a reduction
line and the allocation engine is unexercised here.

**The 10% `Sibling discount` policy was never awarded to anyone.** It is active, it is in the pair
list, and no student in the cohort holds it — so a THIRD base/percentage combination on one run is
untested by eye.

**No run was driven in School B.** Its cohort, awards and schedule are seeded identically, and its
screen was opened for the isolation read only; no invoice was raised there.

**`admin@drive.test` was not driven**, though `admin` holds `finance.invoice.generate` (`RbacSeeder`
`:248`) and can start a run. Only the `accounts_officer` path was exercised.

**No failure arm.** `Failed 0` on both runs — no student was billed into an error, so the run
report's `Failed` section rendered its empty state and never a real row with a reason on it.

**JSS 2 was not run.** The fixture leaves it unarmed on purpose, so the empty-cohort run — a real
state a screen must report without looking broken — was not put in front of a browser.

**Nothing was checked as `super@drive.test`.**

---

## 12 · Reproducing

```bash
pnpm install && pnpm run build
APP_ENV=drive php artisan finance:seed-drive-fixture
APP_ENV=drive php artisan serve --port=8001
APP_ENV=drive php artisan queue:work --tries=1     # step 5 — a run is a queued job
```

The harness is throwaway and uncommitted, in `~/drive-harness-money` (`puppeteer-core` against system
Chrome, installed outside the repository so `node_modules` is never mutated). `localhost:8001`, never
`127.0.0.1:8001`.
