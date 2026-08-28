# DRIVE — the BSS discount-award import screen (`/finance/discount-award-imports`)

**Branch** `drive/bss-import` · **Screen shipped by** `08c7998`, merged to `staging` as `431d500`
(PR #317) · **Driven** 2026-08-28 against the throwaway drive fixture, `APP_ENV=drive`,
`localhost:8001`, Chrome via `puppeteer-core` installed **outside** the repository
(`~/drive-harness-bss`). Never the production copy.

**Route reached by:** `GET /finance/discount-award-imports` — the web page route added by `08c7998`
at `routes/web.php`, gated on `finance.discount-award.manage`. Reached from the sidebar item
**Finance → Discount awards**, which is the href the broad-selector read below confirms.

**A drive observes and does not fix.** Everything under § 10 is reported unfixed. The fixture work in
§ 1 is the skill's named exception and is in this commit.

---

## 1 · The seat that holds the ability — surveyed, not assumed

`grep -n FINANCE_DISCOUNT_AWARD_MANAGE database/seeders/RbacSeeder.php` returns **one** line, `:439`.
The block it sits in opens at `:387` with `'accounts_officer' => [`. So the ability is granted to
**`accounts_officer` alone**, exactly as `08c7998`'s own commit message claims.

**No fixture gap.** `maker@drive.test` (school#1) and `school-b@drive.test` (school#2) both hold
`accounts_officer`, so both hold it. No grant was added.

**The refusal seat is `checker@drive.test`** (`executive_director`, school#1) and it is the
interesting choice rather than a convenient one: that seat holds `finance.access` **and** both
discount-policy checker abilities. It is the person who APPROVES the percentages this sheet cites,
and it still cannot open the screen that places a child on one — which is the separation
`finance.discount-award.manage` was coined for. `void-checker@drive.test` would have proven only
that a seat holding almost nothing is refused.

---

## 2 · The fixture could not reach this screen's state — what was added

Three things were missing, and none of them is visible to any test (`SeedDriveFixture` refuses
outside `APP_ENV=drive`; `phpunit.xml:29` pins the suite to `APP_ENV=testing`).

**(a) One (percentage, base) pair.** The fixture seeded exactly one discount policy per school —
`Sibling discount`, 10%, on the default base. A screen whose third column IS the base axis cannot be
driven against a catalog with one base in it: a resolver that ignored the column entirely would have
awarded every row and read as correct. Added `DriveFinanceStates::ensureAwardPolicies()`, seeding
**50% of DISCOUNTABLE CHARGES** and **100% of THE WHOLE BILL** per school — the template's own sample
percentages, so a bursar who downloads the template and changes only the admission numbers gets rows
that resolve. `75% of THE WHOLE BILL` is deliberately **absent**: it is the no-active-policy arm, and
it has to be a gap rather than a row.

Authored through the real `SubmitDiscountPolicyChange` → `ApproveDiscountPolicyChange` path, with
School B's own bursar as maker. The reason is sharper here than anywhere else in that class: this
import's whole design rests on `ApproveDiscountPolicyChange` being the catalog's single writer, so a
fixture that wrote the rows directly would be driving a screen whose central claim it had falsified.

**(b) Scholarship kinds and holders.** Both fixture scholarships were `kind = NULL` and had no
holders. This screen asks a student's scholarship whether a discount may be awarded at all, and
answers a **different** refusal for each kind — so with no holders it refuses every row identically
and can demonstrate none of them. Now three schemes per school (`BSS` = `discount`, `Endowed` =
`sponsored`, `C2C` = NULL) and four holders per school, **not enrolled** so that `Cohort at slot` and
`Unplaceable` — which two other drives read — do not move. A sponsored student in particular must not
be placed: the bulk run excludes them, so placing one would silently change what U6's drive bills.

`C2C` stays NULL and stays the only unconfigured row, so the Scholarships tab keeps its subject. The
`kind = NULL` direct write keeps its existing exemption (a state that exists in production and that
no writer can create). The two **classified** rows are a weaker case and are marked as one in the
seeder: `ScholarshipController::store()` can mint them, so "no writer exists" does not cover them —
what covers them is that the writer is a controller whose entire body is
`Scholarship::create(['school_id', 'name', 'kind'])`, so the seeder's write is byte-identical rather
than a shortcut past it. The moment an Action exists, those two move to it.

**(c) Four count columns the tables could not produce.** Per the skill's rule, added before opening a
browser — as a **third table**, following the precedent the guardians fixture set, and a narrow one:
it does **not** repeat the ten columns tables 1 and 2 share, because this screen reads none of them.

| New column | Why no existing column answers it |
| --- | --- |
| `Award pairs` | `Discount policies` counts rows. Three could be three drafts, three fixed amounts, or three rows on ONE pair — all of which render the empty state or the ambiguity refusal while that column reads healthy. |
| `On a discount scholarship` | `Scholarships` counts SCHEMES. Zero holders means no row of any sheet can ever be awarded. |
| `On another scholarship` | Zero means the two scholarship refusals cannot be shown at all. |
| `Discount awards` | Zero on a fresh fixture and printed anyway, exactly as `Guardians` is — it is the denominator § 8's re-upload check is measured against. **Not** an abort. |

**The skill's enumeration was checked against the command's actual output and was still right** for
tables 1 (15 columns) and 2 (14, sharing the first ten). It is updated in this commit for the third.

---

## 3 · Both fixture count tables, verbatim — plus the new third

Pasted from the seed run that this drive was then conducted against (the admission numbers below are
the ones that appear in § 6 and § 8).

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the bulk-run screen (U6) prices a COHORT from an ACTIVE schedule and reports the unplaceable; the decisions surface (U13/U14) reads back what a checker has already settled:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Active schedules | Cohort at slot | Unplaceable | Decided credit notes | Decided voids |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
| A (school#1) | 2                 | 2     | 2            | 2             | 3                 | 5                 | 0                   | 2                     | 8             | 1                | 2              | 9           | 2                    | 1             |
| B (school#2) | 2                 | 2     | 2            | 1             | 3                 | 0                 | 0                   | 0                     | 1             | 1                | 2              | 1           | 0                    | 0             |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
Bulk invoice runs: /finance/bulk-invoice-runs — the cohort above sits at (term, JSS 1); JSS 2 has an empty one on purpose.

Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the guardians screen links a new guardian to students by admission number; the Scholarships tab classifies an UNCONFIGURED scholarship:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Students | Guardians | Scholarships | Scholarships (unconfigured) |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+
| A (school#1) | 2                 | 2     | 2            | 2             | 3                 | 5                 | 0                   | 2                     | 8             | 16       | 0         | 3            | 1                           |
| B (school#2) | 2                 | 2     | 2            | 1             | 3                 | 0                 | 0                   | 0                     | 1             | 7        | 0         | 3            | 1                           |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+

Authoring slot per school — the BSS discount-award import (/finance/discount-award-imports) resolves each row of a sheet to an ACTIVE percentage policy on a (percentage, base) PAIR, and asks the student's SCHOLARSHIP whether a discount may be awarded at all:
+--------------+-------------+-------------------+----------+---------------------------+------------------------+-----------------+
| School       | Award pairs | Discount policies | Students | On a discount scholarship | On another scholarship | Discount awards |
+--------------+-------------+-------------------+----------+---------------------------+------------------------+-----------------+
| A (school#1) | 3           | 3                 | 16       | 2                         | 2                      | 0               |
| B (school#2) | 3           | 3                 | 7        | 2                         | 2                      | 0               |
+--------------+-------------+-------------------+----------+---------------------------+------------------------+-----------------+
  School A (school#1) award pairs: 10% of DISCOUNTABLE CHARGES · 50% of DISCOUNTABLE CHARGES · 100% of THE WHOLE BILL
  School B (school#2) award pairs: 10% of DISCOUNTABLE CHARGES · 50% of DISCOUNTABLE CHARGES · 100% of THE WHOLE BILL
  School A (school#1) admission numbers: ADM40892, ADM61217, ADM12622, ADM22998, ADM83984, ADM97612, ADM31454, ADM75910, ADM81892, ADM58941, ADM90314, ADM49722, ADM66470, ADM24049, ADM65977, ADM71342
  School B (school#2) admission numbers: ADM23816, ADM79365, ADM90193, ADM91934, ADM46545, ADM82123, ADM73476
```

The first ten columns of table 2 repeat table 1's, value for value. Table 3 repeats none of them.

**Holders, re-derived from the database rather than inferred from seeder order:**

```
school#1 student#16 ADM66470 kind=discount      school#2 student#20 ADM91934 kind=discount
school#1 student#17 ADM24049 kind=discount      school#2 student#21 ADM46545 kind=discount
school#1 student#18 ADM65977 kind=sponsored     school#2 student#22 ADM82123 kind=sponsored
school#1 student#19 ADM71342 kind=NULL          school#2 student#23 ADM73476 kind=NULL
```

---

## 4 · The empty state — driven FIRST, with the catalog emptied through the real retire path

School A's three active percentage policies were retired through `SubmitDiscountPolicyChange(Retire)`
→ `ApproveDiscountPolicyChange`, maker `maker@drive.test`, checker `checker@drive.test`. Not a status
write: the screen has to be looked at in a state the application can actually reach.

```
retired policy#3
retired policy#4
retired policy#1
active percent policies now: 0
```

Then, as `maker@drive.test`, `GET /finance/discount-award-imports`:

```
--- file inputs ---
0
--- upload buttons ---
0
--- buttons ---
["MD Maker Drive","Toggle sidebar","Download template","Read this before you fill in the file Open the format guide"]
```

The upload is **withheld**, not annotated: zero file inputs and zero upload controls on the page. The
body, verbatim:

> This school has no approved percentage discount policies yet, so there is nothing a list could put a student on.
>
> Every row of this file names a percentage and what it comes off, and is matched against a discount policy that is already approved and active. This import never creates one. If you upload now, every row will be refused for the same reason.
>
> Each percentage you intend to use — on each of DISCOUNTABLE CHARGES and THE WHOLE BILL — has to be submitted and approved through the discount-policy approval flow first. That approval is what makes the figure legitimate; this sheet only says which student sits on which approved figure.
>
> Go to Discount policies to submit them

The remedy anchor is present and points at `/finance/discount-policies` — offered because
`maker@drive.test` holds `finance.discount-policy.change.submit`, which its route requires.

`empty-01-no-active-policies.png`

**Then the policies were restored (fixture re-seeded) and the same screen re-read:**

```
file inputs   : 1
upload buttons: ["Upload list"]
pair chips    : ["10% of DISCOUNTABLE CHARGES","50% of DISCOUNTABLE CHARGES","100% of THE WHOLE BILL"]
```

`seeded-02-pairs-and-upload.png`

**What this establishes.** The empty state is not cosmetic — it removes the control. A bursar cannot
upload 91 rows into 91 rejections, because with nothing to match there is nothing to press. And the
pair chips answer the question that follows: the three percentages a sheet may name, in the phrasing
the third column accepts.

---

## 5 · The template downloads and opens — base column, pasted

Downloaded by clicking the real **Download template** anchor in the page (not `page.request`, which
carries no `Referer` and 401s under Sanctum). `discount-award-import-template.csv`, 140 bytes:

```
"admission_number","discount_percentage","discount_applies_to"
"STU2025001","50","DISCOUNTABLE CHARGES"
"STU2025002","100","THE WHOLE BILL"
```

First bytes, so "no BOM" is a measurement rather than a claim:

```
00000000: 2261 646d 6973 7369 6f6e 5f6e 756d 6265  "admission_numbe
```

**The base column values are `DISCOUNTABLE CHARGES` and `THE WHOLE BILL`. `TUITION ONLY` does not
appear in the file the platform issues.** The two rows differ on the base axis, which is what stops
the third column reading as a constant to be copied down.

The consequence that can go stale is on the screen, in the column note, and not in the template
heading — read out of the rendered format guide:

> REQUIRED, on every row, and there is no default. Write DISCOUNTABLE CHARGES or THE WHOLE BILL.
> DISCOUNTABLE CHARGES means every charge your fee schedule marks as discountable — **which in your
> fee schedule today means tuition**, and will mean whatever else you mark later. It is per student
> and it changes the money: 100% of DISCOUNTABLE CHARGES still leaves the child paying for transport
> and anything else not marked discountable, while 100% of THE WHOLE BILL leaves them paying nothing
> at all. Those are different amounts, so we will not guess which one you meant. If your list is
> written in Brookstone's own words, TUITION ONLY is accepted and means DISCOUNTABLE CHARGES.

Six NOTES render below the column table: *Delete the two example rows before you upload* · *The
policies must exist BEFORE you upload* · *THE THIRD COLUMN IS MONEY — it has no default* ·
*Re-uploading the same sheet is safe* · *The student must already be on a discount scholarship* ·
*Extra columns are ignored*. `format-01-guide-open.png`

---

## 6 · The spreadsheet round trip — Microsoft Excel 16.59, not a text editor

The downloaded CSV was opened in **Microsoft Excel 16.59** via AppleScript, the four data rows were
typed into cells `A2:C5`, and the workbook was **saved from Excel** as CSV. That file — not one this
drive wrote — is what was uploaded.

**What Excel produced is materially different from what the platform issued:**

```
00000000: 6164 6d69 7373 696f 6e5f 6e75 6d62 6572  admission_number
00000010: 2c64 6973 636f 756e 745f 7065 7263 656e  ,discount_percen
00000020: 7461 6765 2c64 6973 636f 756e 745f 6170  tage,discount_ap
00000030: 706c 6965 735f 746f 0d0a 4144 4d36 3634  plies_to..ADM664
```

- **CRLF** line endings (`0d 0a`) where the issued template had bare LF;
- **all quoting stripped** — every field unquoted, where the issued template quoted all of them;
- no BOM (this Excel build did not add one).

```
admission_number,discount_percentage,discount_applies_to
ADM66470,50,DISCOUNTABLE CHARGES
ADM24049,75,THE WHOLE BILL
ADM65977,50,DISCOUNTABLE CHARGES
ADM99999,100,THE WHOLE BILL
```

**The reader accepted it whole.** Four rows read, four rows answered, no parse failure and no
mangled cell. That is the observation this step exists for: two of the three properties above — CRLF
and the de-quoting — are introduced by the round trip and by nothing in the test suite, which builds
its fixtures with `fputcsv` and bare LF.

---

## 7 · Four rows, one per outcome, in one upload

Upload as `maker@drive.test`. Counters as rendered:

```
--- STATS ---
["ROWS READ = 4","AWARDED = 1","ALREADY AWARDED = 0","NOT APPLIED = 3"]
--- FILTERS ---
["All (4)","Not applied (3)","Awarded (1)","Already awarded (0)"]
```

Every row rendered, keyed by the line number and the admission number the uploader typed — nothing
read back out of the database. Reasons verbatim:

| Line | Admission | Discount | Outcome | Reason (verbatim) |
| --- | --- | --- | --- | --- |
| 2 | ADM66470 | 50% of DISCOUNTABLE CHARGES | **Awarded** | `Awarded 50% of DISCOUNTABLE CHARGES.` |
| 3 | ADM24049 | 75% of THE WHOLE BILL | **Not applied** | `This school has no active discount policy for 75% of THE WHOLE BILL. The policy has to be approved before the award can be made — this import never creates one. Submit it through the discount-policy approval flow, wait for approval, then upload this sheet again.` |
| 4 | ADM65977 | 50% of DISCOUNTABLE CHARGES | **Not applied** | `Scholarship [Endowed] is sponsored; a discount award may only be made against a discount scholarship. A sponsored student is billed by hand and is excluded from the bulk run, so an award on one could never be applied.` |
| 5 | ADM99999 | 100% of THE WHOLE BILL | **Not applied** | `No student in this school has the admission number [ADM99999]. Admission numbers are unique within a school, so a number from another school matches nobody here. Check it against the portal — and check that Excel has not dropped a leading zero.` |

`upload1-01-in-flight.png`, `upload1-02-report.png`

**The poll terminates.** In-flight state seen: **true**. Poll responses: **2**. Reached terminal in
**4.3 s**. The screen entered its spinner, polled `GET …/discount-award-imports/{uuid}` twice at 2 s,
and transitioned to the report on the second. This is the check no test can make: a terminal set
that did not match this import's statuses (`completed` / `failed`) would have spun forever, and
`imports.status` would be a state the client never recognised.

**What each row establishes**, one line each:

1. Line 2 — the base axis is READ. Two active policies sit at different bases; the awarded row landed
   on `policy#3` (50/discountable), confirmed by query, not on the 10% or the 100%/total row.
2. Line 3 — a pair nobody approved is refused **by name**, and the sentence says where to get one
   approved. The import created no policy: `Discount policies` stayed at 3.
3. Line 4 — the scholarship gate fires, names the scheme, and explains the consequence
   (`excluded from the bulk run`) rather than only the rule.
4. Line 5 — a miss is a miss, and the sentence volunteers the leading-zero cause, which is the one
   failure the reader cannot detect for the operator.
5. **The report is a bursar's document, not a developer's.** Three refusals, three different
   sentences, each naming what to do. No id, no stack, no SQL.

**The downloadable report carries the same rows** (fetched in-session, `200 text/csv`), so the screen
and the file cannot disagree about a run:

```
line_number,admission_number,discount_percentage,discount_applies_to,outcome,reason
2,ADM66470,50,"DISCOUNTABLE CHARGES",already_awarded,"Already on 50% of DISCOUNTABLE CHARGES. Nothing changed — this row needs no action."
3,ADM24049,75,"THE WHOLE BILL",rejected,"This school has no active discount policy for 75% of THE WHOLE BILL. …"
4,ADM65977,50,"DISCOUNTABLE CHARGES",rejected,"Scholarship [Endowed] is sponsored; …"
5,ADM99999,100,"THE WHOLE BILL",rejected,"No student in this school has the admission number [ADM99999]. …"
```

(That paste is import #2's report — the second upload — which is why line 2 reads `already_awarded`.)

---

## 8 · The re-upload is not a screen of red

The **same file** uploaded again, unchanged.

```
--- STATS ---
["ROWS READ = 4","AWARDED = 0","ALREADY AWARDED = 1","NOT APPLIED = 3"]
--- FILTERS ---
["All (4)","Not applied (3)","Awarded (0)","Already awarded (1)"]
```

Line 2, verbatim: `Already on 50% of DISCOUNTABLE CHARGES. Nothing changed — this row needs no
action.` — rendered **Already awarded**, in the neutral chip, with no red tone on the counter.

**No second award row was written.** Measured either side, not asserted:

```
awards before re-upload: 1
awards after  re-upload: 1
  award#1 school#1 student#16 policy#3
  import#1 school#1 status=completed ok=1 skip=0 fail=3
  import#2 school#1 status=completed ok=0 skip=1 fail=3
```

The three refusals repeat identically, which is correct: none of them was ever a state the first run
could have changed. `upload2-01-in-flight.png`, `upload2-02-report.png`

---

## 9 · Isolation — both seats, ids visible

Every label on this screen is identical across the two schools by construction: same three pair
chips, same three scheme names, same page. The ids are what separate them.

**The pairs, as the screen renders them (identical strings):**

```
maker@drive.test    (school#1) : ["10% of DISCOUNTABLE CHARGES","50% of DISCOUNTABLE CHARGES","100% of THE WHOLE BILL"]
school-b@drive.test (school#2) : ["10% of DISCOUNTABLE CHARGES","50% of DISCOUNTABLE CHARGES","100% of THE WHOLE BILL"]
```

**The rows behind them (disjoint ids):**

```
school#1: policy#1 10/discountable | policy#3 50/discountable | policy#4 100/total
school#2: policy#2 10/discountable | policy#5 50/discountable | policy#6 100/total
```

**School B's screen shows no trace of School A's work.** Read from `school-b@drive.test`:

```
{ "h1": "Discount awards — import",
  "school": "Drive School B",
  "pairChips": ["10% of DISCOUNTABLE CHARGES","50% of DISCOUNTABLE CHARGES","100% of THE WHOLE BILL"],
  "reportRows": 0,
  "fileInputs": 1,
  "mentionsA": false }
```

**And School A's imports are unreachable from inside School B's session** — by uuid, through the real
XHR path the screen itself uses:

```
school-b fetching School A's import a29ccf44-0cf6-4dfa-a3c2-4d447ab11874 -> {"status":404,"body":"{\"message\":\"Resource not found\"}"}
school-b fetching School A's REPORT                                     -> {"status":404,"body":"{\"message\":\"Resource not found\"}"}
```

**404 and not 403** — the right answer. A 403 would confirm that an import with that uuid exists.
`isolation-01-school-b-screen.png`

**The refusal — `checker@drive.test`, holder of both discount-policy checker abilities:**

```
GET  /finance/discount-award-imports        -> 403 | title: Forbidden
     page text: "403 User does not have the right permissions."
POST /api/v1/finance/discount-award-imports -> 403 {"message":"User does not have the right permissions.", …}
GET  /api/v1/finance/discount-award-imports/template -> 403
```

Both halves: the page AND the write. And the sidebar does not offer what the route refuses — read
with the **same broad `document.querySelectorAll('a')` selector** the maker's list was read with, so
"absent" is a fact about the page and not about the selector:

```
maker@drive.test       [… ,"/finance/discount-policies","/finance/discount-award-imports", …]  award item present: true
checker@drive.test     ["/finance","/finance/approvals","/finance/decisions", …]               award item present: false
school-b@drive.test    [… ,"/finance/discount-policies","/finance/discount-award-imports", …]  award item present: true
```

`refusal-01-checker-403.png`, `refusal-02-checker-sidebar.png`

---

## 10 · Console throughout, and the one finding

**Console across every seat and every page: one entry, repeated, and it is pre-existing.**

```
[http 403] GET http://localhost:8001/dashboard
[console.error] Failed to load resource: the server responded with a status of 403 (Forbidden)
```

That is the `/dashboard` 403 the discount-policies drive filed
(`docs/handoff/reports/feat-discount-policies-page.md:456-460`) — the sidebar's Dashboard link,
refused for finance seats. Nothing to do with this screen. **No `pageerror`, no failed request, no
console entry originating in the award-import page, on any of the eleven page loads in this drive.**

### FINDING (environment, not the change) — the drive instance runs no queue worker, and its absence is indistinguishable from a broken poll

`.env.drive.example` sets `QUEUE_CONNECTION=database`. The first upload of this drive therefore sat
at `imports.status = 'queued'` with one row in `jobs`, while the screen entered its in-flight state
and polled **21 times without transitioning**. That reads exactly like a wrong terminal set — the one
defect on this screen that only a browser can find — and cost this drive a cycle chasing the feature
instead of the environment.

**Reported as environment, and the fix is documentation only**, which the skill admits as the second
narrow exception (a drive-environment config change is config, not the feature):
`docs/finance/drive-environment.md` gains step 5 (`queue:work`) and a paragraph naming the confusion,
with the one-line query that tells the two apart (`DB::table('jobs')->count()` — non-zero means no
worker; zero with the record still `queued` is a real finding). **No application code was touched.**

This affects every queued screen, not this one: guardian import, opening balances and bulk invoice
runs all poll the same way.

---

## 11 · What was NOT driven

**The awards were NOT confirmed to APPLY on a bill.** This is the important one. Everything above
proves a row of `finance_student_discount_awards` was written against the right policy for the right
student, and nothing above proves a naira left anybody's invoice. That needs a bulk invoice run at
the school's coordinates, and it is the next thing after this — not a gap in the screen, but the
half of the feature that makes it worth anything. `award#1` sits on `student#16`, who is
deliberately **unenrolled**, so a run would not currently pick them up at all: driving the money
would first mean placing a discount holder at (term, JSS 1).

**The unconfigured-scholarship refusal is seeded but was not driven.** `ADM71342` (school#1,
`kind = NULL`) is in the fixture and produces a fifth, distinct sentence. The brief asked for four
rows, one per outcome, so it was left out of the file; the arm is available to the next drive at no
setup cost.

**The ambiguity refusal was not driven** — two ACTIVE policies on one pair, which the importer
refuses rather than choosing. The screen's own amber chip for it was likewise never rendered, because
the fixture deliberately holds one policy per pair. Staging it means approving a duplicate through
the policy flow; it is reachable and was simply out of this brief's four outcomes.

**A malformed file was not uploaded** — no missing column, no `.xlsx` in place of a CSV. The `failed`
status and its `error` sentence therefore rendered on no screen; only `completed` was seen.

**Nothing was driven as `super@drive.test`.** `finance.discount-award.manage` ends in `manage`, so
ADR 0040 does **not** exclude it from the `Gate::before` bypass and a platform admin is expected to
reach this screen. That expectation is stated in `AwardStudentDiscount`'s docblock and was not put in
front of a browser.

**The `void-checker@drive.test` seat was not driven**, having been passed over for
`checker@drive.test` as the more informative refusal (§ 1).

---

## 12 · Reproducing

```bash
pnpm install && pnpm run build
APP_ENV=drive php artisan finance:seed-drive-fixture
APP_ENV=drive php artisan serve --port=8001
APP_ENV=drive php artisan queue:work --tries=1      # step 5 — see § 10
```

The harness is throwaway and uncommitted, in `~/drive-harness-bss` (`puppeteer-core` against system
Chrome, installed outside the repository so `node_modules` is never mutated). `localhost:8001`,
never `127.0.0.1:8001`.
