# U7 — invoice list and detail

Branch `feat/u7-invoice-list-and-detail`, off `origin/staging` at `ae2d97b`. Four commits, each
pushed and gated on its own push.

| | commit | gate |
| --- | --- | --- |
| 1 | `8acc3d2` the wire, and the three irreversible surfaces | PASS 16/16 |
| 2 | `d7f9d1c` the invoice detail, and the printable invoice | PASS 16/16 |
| 3 | `90b06b8` the list, the receipt, and the ticket's remaining paths | PASS 16/16 (second run — see § 6) |
| 4 | `945aedc` this report and the drive captures | docs-only, gate SKIPPED by design |
| 5 | the cold review's three findings | see § 9 |

`bin/quality` is **16** steps on this branch, re-derived from the gate's own `[n/16]` output rather
than carried; the finance-context skill's line still says 15.

---

## 1. One premise in the brief was false, and it is named before anything else

The brief says the printable page "refuses to print migrated WCBS data on the same grounds the
receipt does". **There is no invoice-side equivalent to refuse, and there cannot be one today.**

- The receipt's predicate is `Payment::$origin` (`app/Finance/Models/Payment.php:205`,
  `isReceiptable()`), an allowlist on a column.
- `finance_invoices` has **no origin or provenance column**. The create migration
  (`database/migrations/2026_07_19_100000_create_fee_invoices_tables.php:31-62`) declares none, and
  none of the twenty later migrations naming `finance_invoices` adds one.
- The only writer of `origin = 'migrated'` anywhere is `PostOpeningBalanceBatch`, and that Action
  raises **no invoice at all** by rule — step 3 / R6
  (`app/Finance/Actions/PostOpeningBalanceBatch.php:60-63`): the import cannot choose the enrollment
  episode, the episode's slot belongs to native billing, and the portal must not originate a
  document WCBS already issued.

So a migrated branch on the printable invoice would be a condition matching zero rows now and
forever — the shape of a guard that can only ever be green. What an invoice does have is a **void**,
and the printable view marks it rather than refusing: the reader who needs an invoice on paper after
it was voided is the one reconciling why the charge is gone. Printing it silently as a live demand
for payment is the failure to avoid, and the banner is what avoids it. Both the controller and the
page carry that argument in full so nobody adds the missing branch believing it was forgotten.

The brief's other rulings were taken as given and are unchanged: the list is per student, "PDF
download" is a printable page, and the void request lifecycle is linked to rather than rebuilt.

---

## 2. What each commit changed

### Commit 1 — the wire and the irreversible surfaces

- `InvoiceResource` carries `'kind' => $this->kind->value`. It is the only invoice serialiser in the
  codebase — both generate 201s and the per-student read answer through it — which is why the
  ticket calls it the root of its other five paths.
- `resources/js/types/finance.ts` declares `InvoiceKind` and `Invoice.kind`.
- `resources/js/lib/finance/invoice-kind.ts` is new and holds the vocabulary once:
  `INVOICE_KIND_LABEL`, `INVOICE_KIND_BADGE`, and `invoiceLabel()` (kind + number). The "New
  invoice" modal's own select now reads its two labels from there instead of carrying copies, so the
  label a bursar picks at creation is the label they read back on every later surface.
- `request-void-modal`, `issue-credit-note-modal` and `record-payment-modal` title themselves
  through `invoiceLabel()`.

### Commit 2 — the invoice detail, and the printable invoice

- `GET /finance/invoices/{invoice:uuid}` and `.../print`, both on the group's `finance.access` —
  the ability the statement page and the statement's feed already carry, and these pages show
  strictly less than that feed returns for the same invoice.
- `InvoiceDetailController` resolves props server-side (the receipt's four reasons; the fourth,
  "there is no second consumer", is the one that decided it) and serialises through
  `InvoiceResource`, fully resolved to the wire shape.
- `InvoiceReadModel::settlementSums()` + `forDetail()` — see § 3, which is the load-bearing part of
  this commit.
- `InvoiceReadModel::voidRequestsForInvoice()`, matched on the foreign key. The statement pairs
  rows against pending requests by rendered `display_number` because the invoice it holds carries a
  uuid and the void request references the numeric PK; the detail holds the row and asks the
  database.
- `Invoice::student()` — a `BelongsTo` lookup for the back-link's uuid, precedented by
  `Payment::student()` and used the same way by the receipt controller.
- Both routes are exempted in `FinanceNavCoverageTest` with their reasons, and the print link's
  reason is asserted rather than trusted.

### Commit 3 — the list, the receipt, and the ticket's remaining paths

- The statement's invoices table: a kind badge under each number, the number links to the detail,
  and the client-side search matches the kind's **label** (a bursar types "supplementary"; the wire
  value `scheduled` appears nowhere on that screen).
- The receipt's allocation rows carry `invoice_kind`, rendered as muted text rather than a badge —
  a colour chip prints as a grey rectangle.
- `FinanceNavCoverageTest` gains the arm asserting the statement links the detail, unconditionally.

---

## 3. The invariant, and the two doors into it

**Stated narrowly in the first version of this report, and the narrow version is what let the second
door stay open for three commits.** The rule is not about route-model binding:

> **Any `Invoice` handed to `InvoiceResource` that did not come through
> `InvoiceReadModel::withSettlement()` reports a settlement position of zero, true or not.**

`InvoiceSettlement` reads `allocated_minor` and `approved_credit_minor` off the model as plain
attributes and treats an absent one as **zero** (`for`, `app/Finance/Services/InvoiceSettlement.php:51`).
An invoice with money against it then serialises `settlement_state: 'unpaid'`, `outstanding` equal to
its full total, `can_record_payment: true` and `can_request_void: true` with no blocked reason — a
surface offering to void an invoice that carries a payment allocation, answering 200, rendering
correctly, invisible to any test that asserts a page or an endpoint responds.

**Door one — the binding.** `{invoice:uuid}` loads the row with no sums. This is the one commit 2
found and closed, and it is what proof B2 pins.

**Door two — a freshly created model that acquired allocations inside its own transaction.**
`GenerateInvoice` applies carry-forward credit by writing `PaymentAllocation` rows against the
invoice it has just created (`applyCreditForward`, `app/Finance/Actions/GenerateInvoice.php:479`)
and then returns that model. Both generate routes serialised it directly. **This one shipped**, and
it is closed in § 9 / F1 with proof E below.

**It is reachable on the ordinary cutover path**, which is what makes it a fix rather than a note:
`PostOpeningBalanceBatch` (`app/Finance/Actions/PostOpeningBalanceBatch.php:114`) turns every
negative migrated balance into a real payment row, so a student arriving from WCBS in credit has an
unallocated payment waiting, and the **first invoice a bursar raises for them** takes exactly this
branch.

**Both doors were in view when commit 2 was written, and one was fixed.** The generate routes are
pre-existing — neither `InvoiceController.php` nor `GenerateInvoice.php` appears in
`git diff ae2d97b..945aedc` — so the defect is not this branch's, but the branch closed the same
class one caller over without looking at the caller beside it.

`settlementSums()` is the one expression **this read path** uses; § 9 / F2 corrects the stronger
claim that was made here.

---

## 4. Proofs — raw output, and the mutation each one caught

Every arm was watched GREEN, then watched RED against a deliberate mutation, then restored per path
from a backup and the restore verified with `git diff --stat`. No whole-tree revert was used.

**Nine mutations plus one free red**, counted from the entries below rather than asserted: A1, A2,
B1, B2, B3, C2, D1 here, and E1, F1 in § 9. C1 is the free red. (An earlier draft of this line said
six; the entries it was counting were already eight.)

### A — `InvoiceKindOnReadPathsTest` (commit 1)

Green:

```
{"tool":"pest","result":"passed","tests":3,"passed":3,"assertions":9,"duration_ms":22095}
```

**A1 — mutation: delete `'kind' => $this->kind->value,` from `InvoiceResource`.**

```
{"tool":"pest","result":"failed","tests":3,"passed":1,"assertions":7,"failed":2,"failures":[
 {"test":"…it_a_—_the_generate_201_carries_kind…","message":"Failed asserting that null is identical to 'scheduled'."},
 {"test":"…it_b_—_the_statement_feed_distinguishes…","message":"Failed asserting that two arrays are identical.
--- Expected
+++ Actual
 Array &0 [
-    'Tuition' => 'scheduled',
-    'Damaged locker door' => 'supplementary',
+    'Tuition' => null,
+    'Damaged locker door' => null,
 ]"}]}
```

Caught: the wire dropping `kind` on both read directions at once — the 201 and the statement feed.

**A2 — mutation: `request-void-modal` titled with `invoice.display_number` alone, import removed.**

```
{"tool":"pest","result":"failed","tests":3,"passed":2,"failed":1,"failures":[{"test":"…it_c_…",
"message":"A modal that precedes an irreversible act on ONE invoice names it by number alone:
resources/js/components/finance/request-void-modal.tsx — does not name invoiceLabel();
resources/js/components/finance/request-void-modal.tsx — titles itself with display_number alone. …"}]}
```

Caught: the exact pre-U7 shape of the most expensive of the six read paths, on both halves of the
measure (the helper is absent AND the old interpolation is back).

### B — `InvoiceDetailScreenTest` (commit 2)

Green:

```
{"tool":"pest","result":"passed","tests":7,"passed":7,"assertions":102,"duration_ms":25639}
```

**B1 — mutation: `forDetail()` gains `->excludingVoid()`.**

```
{"tool":"pest","result":"failed","tests":7,"passed":6,"failed":1,"failures":[{"test":"…it_c_—_a_VOIDED_invoice_opens…",
"message":"Expected response status code [200] but received 404."}]}
```

Caught: the reporting default leaking onto the document surface — the same hole the named-scope
decision exists to prevent one route over.

**B2 — mutation: `$loaded = $invoice->load('lines')` instead of `$invoices->forDetail($invoice)`.**

```
{"tool":"pest","result":"failed","tests":7,"passed":6,"failed":1,"failures":[{"test":"…it_b_—_a_SETTLED_invoice_does_not_render_as_unpaid…",
"message":"Property [invoice.settlement_state] does not match the expected value.
--- Expected
+++ Actual
-'settled'
+'unpaid'"}]}
```

Caught: § 3, exactly — a settled invoice rendering as unpaid.

**B3 — mutation: `Invoice` loses `BelongsToSchool`.**

```
{"tool":"pest","result":"failed","tests":7,"passed":6,"failed":1,"failures":[{"test":"…it_e_—_School_B_cannot_open_School_A’s_invoice…",
"message":"Expected response status code [404] but received 200."}]}
```

Caught: School B reading School A's invoice with a 200.

### C — `FinanceNavCoverageTest`

**C1 — free red, before any exemption was written.** Registering the two routes made the existing
arm fail, which is the cheapest evidence there is that the guard runs:

```
"A finance page is registered, permission-gated and reachable from NO menu:
/finance/invoices/{invoice}, /finance/invoices/{invoice}/print. …"
```

**C2 — mutation: the statement's detail link gated on `invoice.status !== 'void'`.**

```
{"tool":"pest","result":"failed","tests":11,"passed":10,"failed":1,"failures":[{"test":"…the_invoice_detail_exemption_really_is_linked_from_the_statement…",
"message":"resources/js/pages/admin/finance/statement.tsx names the literal `'void'` a different number
of times than when this arm was written. … Failed asserting that 3 is identical to 2."}]}
```

Caught: the audit view (`?include_void=1`) losing its way into the page that renders the void trail.

**What it does not catch, in the test's own words** — carried here because a caveat that lives only
in the test does not reach the person reading the report. The arm is a text check on a file and
there is no JavaScript test runner in this repository, so it cannot see *"a row hidden by something
that does not spell `'void'`"* — a filter applied server-side in the feed, or one on `cancelled_at` —
nor whether the link, once rendered, is clickable or reaches anything. The count is also a blunt
instrument: an unrelated third use of the literal reds it, which is by design and is stated in the
failure message.

### D — `PaymentReceiptTest` (commit 3, ticket § 6)

**D1 — mutation: delete `'invoice_kind' => $a->invoice?->kind->value,` from the receipt controller.**

```
{"tool":"pest","result":"failed","tests":11,"passed":10,"failed":1,"failures":[{"test":"…it_names_the_KIND_of_each_invoice_a_payment_reached…",
"message":"Failed asserting that two arrays are identical.
 Array &0 [
-    '000001' => 'scheduled',
-    '000002' => 'supplementary',
+    '000001' => null,
+    '000002' => null,
 ]"}]}
```

Caught: the receipt's rows losing the kind. The red also **proves the arm's own premise** — two
allocation rows with two different numbers really are produced by one account-level payment drawn
forward across both invoices.

### Gates

`./vendor/bin/pest --group=arch` → `103 passed, 565 assertions`. `composer analyse` → `0 errors`
(two Larastan findings were raised by the first draft of the void-trail map and fixed:
`argument.unresolvableType` on an un-annotated closure, and `property.notFound` on `->submittedBy->name`,
narrowed with `instanceof User` the way `VoidRequestResource` narrows the same relation).
`bin/ci-citation-lint.php` refused two spellings of one new citation before accepting
`` `student` (app/Finance/Models/Payment.php:173) `` — the bare basename first, then the
path-without-symbol form.

---

## 5. The drive

Fixture: `APP_ENV=drive php artisan finance:seed-drive-fixture`, throwaway drive database,
`php artisan serve --port=8001`, `pnpm run build` before the browser. Chrome driven by
`puppeteer-core` installed **outside the repository** (in the session scratchpad, never in
`node_modules`).

### 5.1 Both fixture count tables, verbatim

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the bulk-run screen (U6) prices a COHORT from an ACTIVE schedule and reports the unplaceable:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Active schedules | Cohort at slot | Unplaceable |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+
| A (school#1) | 2                 | 2     | 2            | 2             | 1                 | 5                 | 0                   | 2                     | 8             | 1                | 2              | 9           |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 0                     | 1             | 1                | 2              | 1           |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+
Bulk invoice runs: /finance/bulk-invoice-runs — the cohort above sits at (term, JSS 1); JSS 2 has an empty one on purpose.

Authoring slot per school — … the guardians screen links a new guardian to students by admission number:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Students | Guardians |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
| A (school#1) | 2                 | 2     | 2            | 2             | 1                 | 5                 | 0                   | 2                     | 8             | 12       | 0         |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 0                     | 1             | 3        | 0         |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
  School A (school#1) admission numbers: ADM57052, ADM19365, ADM52778, ADM89272, ADM61554, ADM33035, ADM97277, ADM08082, ADM38302, ADM96739, ADM88048, ADM67536
  School B (school#2) admission numbers: ADM13146, ADM43032, ADM68924
```

`Payments (migrated)` is zero by construction on every fresh fixture, and no fixture change was
needed for U7: `DriveFinanceStates::unallocatedRemainder()`
(`app/Finance/Console/DriveFinanceStates.php:420`) already raises a term bill AND a supplementary
charge on one episode, for U10. Two students carry that pair — `student#8` and `student#9`.

### 5.2 What the browser actually showed

**Capture 01 — `01-list-term-bill-and-supplementary.png`.** The statement of `student#8`, invoices
tab: one episode, two live invoices, each row's own href and cells read out of the DOM.

```
  LIST rows (2):
    href=/finance/invoices/a290602c-d51e-4ba8-bb39-dc0d842f6d98 :: ["000009 | Term bill","Enrollment a290602c-190c-40f6-9770-4941dfe39c38","Issued | Unpaid","₦1,500.00 | ₦1,500.00 outstanding","Record payment | Submit credit note | Request void"]
    href=/finance/invoices/a290602c-d98a-4f1e-9bd6-d3daecc7f787 :: ["000010 | Supplementary charge","Enrollment a290602c-190c-40f6-9770-4941dfe39c38","Issued | Unpaid","₦1,000.00 | ₦1,000.00 outstanding","Record payment | Submit credit note | Request void"]
```

Establishes: the two rows are distinguishable at a glance and by machine — `000009 | Term bill`
against `000010 | Supplementary charge` — and each carries its own detail href. It also shows why
the kind was needed and why `academic_context` could not stand in: **both rows print the identical
context string**, because they are the same episode.

**Capture 02 — `02-list-search-supplementary.png`.** Typing `Supplementary` into the table's search:

```
  SEARCH "Supplementary" → 1 row(s):
    ["000010 | Supplementary charge","Enrollment a290602c-190c-40f6-9770-4941dfe39c38","Issued | Unpaid","₦1,000.00 | ₦1,000.00 outstanding","Record payment | Submit credit note | Request void"]
```

Establishes: the search matches the rendered LABEL. Matching `i.kind` alone would have required the
operator to type `scheduled`, a word that appears nowhere on the screen.

**Captures 03 / 04 — `03-detail-term-bill.png`, `04-detail-supplementary.png`.**

```
[2] detail — TERM BILL (a290602c-d51e-4ba8-bb39-dc0d842f6d98)   status 200
  H1: "000009"     BADGES: ["Term bill","Unpaid"]
  LINES: [["Tuition","Charge","₦1,500.00"]]
  BUTTONS: [… "Statement","Print","Record payment","Submit credit note","Request void"]
  TITLE: "Term bill 000009 - Laravel"

[2] detail — SUPPLEMENTARY (a290602c-d98a-4f1e-9bd6-d3daecc7f787)   status 200
  H1: "000010"     BADGES: ["Supplementary charge","Unpaid"]
  LINES: [["Field trip — coach and entry","Charge","₦1,000.00"]]
  BUTTONS: [… "Statement","Print","Record payment","Submit credit note","Request void"]
  TITLE: "Supplementary charge 000010 - Laravel"
```

Establishes: lines, the kind badge, the settlement badge, the actions, and the back-link all render;
the browser TAB TITLE names the kind too, which is what a bursar with several invoices open reads.
Arithmetic on screen: the term bill's single line `₦1,500.00` is the whole total `₦1,500.00`, and
`₦1,500.00` outstanding with nothing paid — computed server-side, with the page performing no sum.

**Capture 05 — `05-void-modal-names-the-kind.png`.** "Request void" clicked on the supplementary
charge:

```
  MODAL first lines: ["Request void for approval — Supplementary charge 000010","Reason (required)","", "This is a proposal — a second person must approve it before the invoice is voided. …"]
```

Establishes ticket § 5 rendered rather than inferred: the confirmation a maker reads before
proposing an act that discards payment allocations names the DOCUMENT, not only the number.

**Capture 06 — `06-printable-supplementary.png`.**

```
  DOCUMENT text: ["Drive School A","SUPPLEMENTARY CHARGE","000010","BILLED TO","Alma Allocate","ISSUED","22 August 2026","PERIOD","Enrollment a290602c-190c-…","DOCUMENT","Supplementary charge","DESCRIPTION\tTYPE\tAMOUNT","Field trip — coach and entry\tCharge\t₦1,000.00","TOTAL\t₦1,000.00","OUTSTANDING\t₦1,000.00","Issued by Drive School A. …"]
```

Establishes: the printable page carries the School, the kind as the document's own name, the issue
date (formatted in PHP — the money lint's format ban is total in this directory), the lines, and
both money rows. `₦1,000.00` line = `₦1,000.00` total = `₦1,000.00` outstanding; nothing on the page
computed any of them.

**Captures 07 / 08 — `07-detail-voided-invoice.png`, `08-printable-voided.png`.** A voided invoice
(`inv#8`, approved void) on both surfaces, HTTP 200 on each:

```
[5]  PAGE text: [… "Term bill","Void","000008","Otto Onlyvoid · Enrollment a290602c-0dcc-…","TOTAL","₦3,000.00"]
[5b] DOCUMENT text: ["Drive School A","TERM BILL","000008","VOIDED — NOT PAYABLE","This invoice was voided on 22 August 2026, 14:42 and its charge reversed in the ledger. It is reproduced here as a record and is not a demand for payment. Reason: Duplicate enrolment", …]
```

Establishes: the void opens rather than 404-ing, on both routes; the settlement badge is ABSENT
(a void has no settlement state, so no "₦0.00 outstanding" line claims it was paid); and the paper
states the void in its own words before any figure.

**Captures 10 / 11 / 12 — the settlement axis, which is § 3 read off the rendered page.**

```
[6] detail — SETTLED (inv#3)        BADGES: ["Term bill","Settled"]
    ACTION BUTTONS: ["Submit credit note","Request void"]
    DISABLED: [{"text":"Request void","title":"This invoice has a payment allocated to it and cannot be voided — reverse or refund the payment instead."}]
    OUTSTANDING LINE: ["₦0.00 outstanding"]

[6] detail — PART-PAID (inv#2)      BADGES: ["Term bill","Part-paid"]
    ACTION BUTTONS: ["Record payment","Submit credit note","Request void"]
    DISABLED: [{"text":"Request void", "title":"This invoice has a payment allocated to it and cannot be voided …"}]
    OUTSTANDING LINE: ["₦2,000.00 outstanding"]

[6] detail — PENDING VOID (inv#7)   BADGES: ["Term bill","Unpaid","Void requested"]
    ACTION BUTTONS: ["Record payment","Submit credit note"]
    DISABLED: []
    OUTSTANDING LINE: ["₦2,000.00 outstanding"]
```

Establishes, in order: a settled invoice reads **Settled** and `₦0.00` and does not offer "Record
payment" — the bound-model defect of § 3 rendered correctly rather than asserted; the part-paid one
reads `₦2,000.00` of its `₦3,000.00`; "Request void" is DISABLED-WITH-REASON in both cases, carrying
the server's sentence verbatim rather than a client-side guess; and a pending void request replaces
the button with a **Void requested** badge, so a maker cannot stack a second request.

**Capture 09 — `09-isolation-school-b-404.png`, seat 2 (`school-b@drive.test`, school#2).**

```
  School A's invoice a290602c-d51e-… via /finance/invoices/a290602c-d51e-…       → HTTP 404
  School A's invoice a290602c-d51e-… via /finance/invoices/a290602c-d51e-…/print → HTTP 404
  School B's own /finance                                                        → HTTP 200
```

Checked by **id**, not by label: the uuid is School A's `inv#9`, the one seat 1 opened at capture 03.
The 200 on School B's own `/finance` is there so the two 404s cannot be read as "this seat sees
nothing".

### 5.3 Drive friction met (none of it caused by this change)

- **`SESSION_DOMAIN=localhost` in the drive environment.** Driving `http://127.0.0.1:8001` signs in
  (POST `/login` → 302) and then never receives the session cookie back, so every subsequent page
  redirects to `/login`. It looks exactly like a rejected password. Drive `http://localhost:8001`.
  This is not in the finance-drive skill's Friction list and cost the first two runs.
- **`/dashboard` bounces the finance seats**, so the landing URL after a successful login is
  `/login` again. Pre-existing and already documented; the session is live and the finance URLs are
  reachable directly. Do not judge login by the landing URL.
- **`button[type="submit"]` reaches the password field's "Show" toggle first** in this markup. Click
  the Sign in button by text.
- **A second tab shares the cookie jar**, so seat 2 arrives signed in as seat 1 and `/login`
  redirects away before the form renders. Use a separate browser context per seat.
- **The Vite manifest must be built before the suite, not only before the browser.** The new page
  tests failed with `Unable to locate file in Vite manifest: …/invoice.tsx` until `pnpm run build`
  ran — the page-rendering tests read the manifest exactly as the browser does.

---

## 6. Commit 3's first gate run was red, and why it was re-run

Recorded rather than summarised, because "retried until green" and "diagnosed" look identical from
the outside.

The first push of `90b06b8` failed at step 16 with 15 named regressions in `PsychomotorSkillTest`,
`PrincipalApprovalTest`, `AuthorizationOrderingTest` and `DutySeparationBaselineTest` — no finance
test among them. The stamped artefacts were copied out **before** anything else was run
(`pest-20260822-151005-27393.log`, `junit-20260822-151005-27393.xml`).

Reading them: **2063 tests, 708 passed, 1354 errors**, 625s. The error messages, counted:

```
762  SQLSTATE[42S02] 1146  Base table or view not found
478  SQLSTATE[42S01] 1050  Base table or view already exists
 77  SQLSTATE[HY000] 1824  Failed to open the referenced table
 16  SQLSTATE[42S22] 1054  Unknown column 'role…'
 10  SQLSTATE[42S02] 1051  Unknown table
  9  SQLSTATE[40001] 1213  Deadlock found when trying to get lock
  2  SQLSTATE[42S22] 1054  Unknown column 'uuid…'
  1  Expected response status code [200] but received 500
```

That is two `migrate:fresh` runs tearing down each other's schema on one database, not a regression.
**The condition was mine.** An earlier push of the same commit had been reported dead — its process
list was empty and its output file held one byte — and I started a replacement while its suite was
in fact still running against `portal_testing`. The two overlapped. The trigger for the first push's
death was a concurrent `Bash` call that ran `cd` and reset the shared shell's working directory
while the push was backgrounded.

The re-run was made with the process list verified empty and nothing else touching the database, and
passed 16/16. **What this does not prove:** that the suite is otherwise deterministic on this
machine. ADR 0053 records byte-identical code producing both PASS and FAIL; this red is explained,
and explaining one red says nothing about that.

---

## 7. The ticket, path by path

`docs/handoff/tickets/nothing-shows-which-invoices-are-supplementary.md` is updated in place, each
path marked as it was closed.

| Path | State |
| --- | --- |
| §1 `InvoiceResource` | CLOSED (commit 1) |
| §2 the TS `Invoice` type | CLOSED (commit 1) |
| §3 the statement's invoices table | CLOSED (commit 3) |
| §4 `billedTotalForStudent` | DECIDED — no breakdown, figure unchanged |
| §5 the three modals | CLOSED (commit 1) |
| §6 the receipt's allocation rows | CLOSED (commit 3) |

**§4, the decision and its reasons.** The ticket calls the figure "arguably correct as a total", and
that is the ruling: it is what the student was billed, which is the question the number is asked.
It gains no per-kind split, for three reasons. The rows now answer it directly — every invoice on
the same screen states its own kind. A second money figure on the wire is a second surface to keep
in step with the first, and `bin/ci-money-lint.php` forbids the UI deriving it, so it would have to
be a new server-side total with its own arms — infrastructure ahead of a caller that has not asked
for it. And a term-bill subtotal is not the receivable: the account balance already carries the
operative position including credit, and a second "billed" figure beside it invites the two to be
read against each other. If a per-kind total is ever wanted it belongs beside the rows and computed
server-side.

**Left open and named:** `PaymentResource`'s own `allocations` block (`invoice_id`, `amount`) gains
no kind. It is the statement's payments tab rather than a document, and nothing renders a kind from
it today.

**Checked and still not affected**, as the ticket already recorded: the bulk-run screens.
`ProcessBulkInvoiceRun:346` raises `InvoiceKind::Scheduled` as a literal, so every invoice a run
produces is a term bill by construction.

---

## 8. What I could not verify

- **Anything about the three modals' rendered titles beyond the void one.** The drive opened
  `request-void-modal` and read its title out of the DOM. `issue-credit-note-modal` and
  `record-payment-modal` were changed identically and are covered only by the text arm and by the
  fact that all three take the same `Invoice` object — not by a rendered capture.
- **Whether printing actually produces the document.** `window.print()` opens the OS print dialog
  and headless Chrome does not exercise `PRINT_STYLES`; what was captured is the printable PAGE on
  screen, not a printed sheet or a PDF. Whether the sidebar, the breadcrumb header and the three
  overlay layers really disappear under `@media print` is unproven by anything here. The selectors
  are copied from the receipt's, which has the same gap.
- **The dark-mode print path is UNREACHABLE, not merely undriven** — and the earlier wording of this
  line ("not driven, in either theme") was wrong in the direction that invites the next person to go
  and drive it. `isDarkMode()` takes an `appearance` and returns `false` unconditionally
  (`isDarkMode`, resources/js/hooks/use-appearance.tsx:40), and `applyTheme` is its only reader, so
  the `dark` class is never applied for any user under any setting. **Inherited, not this branch's:**
  `git log` on that file gives `83447b3 feat: remove dark mode`, and the file does not appear in
  `git diff ae2d97b..945aedc`. Already covered by
  [docs/handoff/tickets/dark-mode-is-unreachable-for-every-user.md](../tickets/dark-mode-is-unreachable-for-every-user.md)
  — itself corrected after a cold review — and by
  [docs/handoff/tickets/ui-chrome-components-have-no-dark-variants.md](../tickets/ui-chrome-components-have-no-dark-variants.md).
  No third ticket is filed.

  **The consequence for this branch's own work, both halves.** Every `dark:` variant on the new
  surfaces is vestigial — 46 of them across `invoice.tsx`, `invoice-print.tsx` and
  `invoice-kind.ts`, including `.invoice-document`'s `dark:bg-card` and both `INVOICE_KIND_BADGE`
  dark treatments. And the colour-force inside `PRINT_STYLES` **still works and is correct
  insurance**: the reviewer measured it by forcing the class by hand. What is not live is its stated
  motivation — the docblock says a page printed from dark mode would put `dark:text-white` on white
  paper, and no user can reach that state today. Keep the rule, and read its comment as a guard
  against the class returning rather than as a description of something happening now.

  Same paragraph, minor: two of the eight selectors in the `display: none` block —
  `[data-slot="sidebar-rail"]` and `[data-sonner-toaster]` — match nothing on this page. They are
  harmless defensive breadth copied from the receipt's set; noted as **unexercised** rather than
  removed, since the cost of carrying them is zero and the cost of being wrong about which layers
  mount is a printed sheet with an overlay on it.
- **The actions actually completing from the detail page.** No payment was recorded, no credit note
  submitted and no void request submitted THROUGH the detail screen — the modal was opened and its
  title read, then the drive moved on. `router.reload()` as the post-action refresh is therefore
  unexercised in a browser.
- **`super_admin` on these two routes.** Not driven. Bypass is authorization and never isolation
  (ADR 0036), and nothing here changes either; the seat was simply not exercised.
- **Concurrency.** Nothing was driven with two sessions acting on one invoice.
- **`voided_at`'s value.** § 9 / F3 put an arm on `issued_at`; `voided_at` is still asserted only as
  "not null", so a controller sending today's date for it would pass.
- **The suite's determinism**, as § 6 says: one red explained is not a statement about the rest.
- **The fixture's `academic_context`** prints as `Enrollment <uuid>` on every capture. That is what
  the drive fixture's episodes carry; whether a production episode's context string reads usefully
  on the printed document is not something this drive can show.
---

## 9. The cold review, and the three findings it returned

A cold review ran against this branch in a separate context, on its own database. It returned three
findings and no stop. **The severities below were set by the project lead, not by me and not by the
reviewer**, and are recorded as given.

### F1 — `fix` — the generate 201 tells the same lie the detail route was fixed for

`GenerateInvoice` writes carry-forward `PaymentAllocation` rows inside its own transaction
(`applyCreditForward`, `app/Finance/Actions/GenerateInvoice.php:479`) and returns
`$invoice->load('lines')`, which never passes through the read model. Both generate routes serialised
it. See § 3 for the invariant and its reachability on the cutover path.

**Reproduced over HTTP before anything changed**, with the ground truth taken from the DATABASE
first so the arm could not pass on the payload it is testing. Term bill of 2000 minor units overpaid
to 22000; a 12000 supplementary charge then raised through
`POST /api/v1/finance/students/{uuid}/invoices`:

```
DATABASE SAYS allocated_to_this_invoice = 12000
PRE-FIX 201 SAYS: {"settlement_state":"unpaid","outstanding":{"amount_minor":12000,"currency":"NGN"},
                   "can_record_payment":true,"can_request_void":true,"void_blocked_reason":null}
```

All five fields wrong, exactly as reported. The arm's first run failed on the ground-truth guard
instead (`Failed asserting that 0 is identical to 12000`) because the test helper hard-coded
`amount_minor` and silently ignored the amounts passed to it — the guard did its job, which is why
it is there.

**Fixed at the two `InvoiceResource` call sites**, not inside the Action's return:
`InvoiceController::generate` and `::generateForStudent` now serialise
`$invoices->withSettlement($invoice)`. `ProcessBulkInvoiceRun` calls `GenerateInvoice` once per
student and renders none of them, so putting the re-read in the Action would have charged a query
per invoice for a payload nobody builds.

`InvoiceReadModel::forDetail()` is renamed **`withSettlement()`**. The old name is part of why this
survived a commit: the two POST routes were not "the detail", so nothing about the name suggested
they needed it.

**Proof E1 — mutation: `generateForStudent` returns `new InvoiceResource($invoice)` again.** The
mutation was asserted to have landed by `diff` against the backup before the run:

```
159c159
<         return response()->json(new InvoiceResource($invoices->withSettlement($invoice)), 201);
---
>         return response()->json(new InvoiceResource($invoice), 201);
```

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":4,"failures":[{"test":"…it_d_—_the_generate_201_reports_the_credit_it_just_applied…",
"message":"Failed asserting that two strings are identical.
--- Expected
+++ Actual
-'settled'
+'unpaid'"}]}
```

Restored, `diff` clean, green: `{"result":"passed","tests":1,"passed":1,"assertions":8}`.

**The narrow statement of the invariant is corrected wherever it appeared**: § 3 of this report,
`InvoiceReadModel::settlementSums()`' docblock, `InvoiceDetailController::props()`' comment, and
`InvoiceDetailScreenTest`'s file header. Each now states the rule as "any Invoice handed to
InvoiceResource that did not come through the read model", with the binding named as one door.

### F2a — `split` — the "one expression" claim was false, three ways

`InvoiceReadModel::settlementSums()` called itself "THE TWO SETTLEMENT AGGREGATES, IN ONE PLACE" and
this report repeated it. Re-derived at HEAD:

```
$ grep -n "withSum('allocations as allocated_minor'" -r app/
app/Finance/Services/AllocationProposal.php:188
app/Finance/Services/InvoiceReadModel.php:89
app/Finance/Console/DriveFinanceStates.php:497

$ grep -n "getAttribute('allocated_minor')" -r app/
app/Finance/Services/AllocationProposal.php:209
app/Finance/Services/InvoiceSettlement.php:57
app/Finance/Console/DriveFinanceStates.php:501
```

Both claims are corrected to what is true: it is the one expression **this read path** uses, and
there are others that currently agree. `AllocationProposal` is untouched and `settlementSums()`
stays private — a primitive widened ahead of a consumer is front-loading, and `AllocationProposal`
is merged code with its own arms.

**Re-derived rather than carried, and it differs from the finding by one.** The review named five
sites — three `withSum`, two arithmetic. The arithmetic is spelled **three** times: `DriveFinanceStates`
spells both expressions, at `:497-498` and `:499-503`. It is a fixture counter rather than a surface
a bursar reads, which is a fair reason to weigh it less and not a reason to leave it off a list.

### F2b — `split` — the ticket

`docs/handoff/tickets/three-spellings-of-the-settlement-aggregates.md`. It names every site, records
that they agree today (compared character by character by the reviewer, and again when the ticket was
written), and carries the sharpest part: `AllocationProposal::openInvoices()`' own docblock at
`app/Finance/Services/AllocationProposal.php:175-178` says *"A second spelling of that sum is how two
surfaces come to disagree about what a student owes"* — while being the second spelling — and
`settlementSums()` is private, so it could not have called it even if someone tried. The ticket
states that converging them is its own change with its own arms, weighs the three obvious moves, and
says explicitly not to do it there. **Nothing enforces the agreement today**; that is the ticket.

### F3 — `fix` — a printable invoice could claim it was issued today

`issued_at` and `voided_at` appeared in the whole `tests/` tree exactly twice, both as
`whereNot(…, null)` — arms that assert a date was SENT and say nothing about which date. `issued_at`
is what the paper prints under ISSUED.

**The reviewer's mutation reproduced:** `'issued_at' => now()->format('j F Y')` passes the whole
suite and the ratchet.

**Arm h added** to `InvoiceDetailScreenTest`: the printed issue date equals the invoice's own
`created_at` and is not today's. The invoice is raised **40 days in the past** with `travelTo` — an
arm written against an invoice created today passes whether the controller sends `created_at` or
`now()`, vacuously, forever. Time is travelled rather than the row UPDATE-ed, because
`finance_invoices` is append-only and an UPDATE is the wrong tool for arranging a fixture. The arm
asserts the row really is in the past before it asserts anything about the response.

**Proof F1 — the reviewer's mutation, landed (`grep` confirmed `'issued_at' => now()->format('j F Y'),`
at line 153) and run:**

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":14,"failures":[{"test":"…it_h_—_the_printable_invoice_prints_the_date_it_was_ISSUED…",
"message":"Property [issued_at] does not match the expected value.
--- Expected
+++ Actual
-'13 July 2026'
+'22 August 2026'"}]}
```

Restored, `diff` clean, green: `{"result":"passed","tests":1,"passed":1,"assertions":23}`.

**`voided_at` is NOT covered by an equivalent arm** and remains `whereNot(…, null)` only. The same
mutation against it would pass.

### F4 — `fix` — §8's dark-mode residual was mis-stated

Raised as an addendum. §8 said the dark-mode print path was "not driven, in either theme"; it is
**unreachable**. Corrected in place, with the provenance verified rather than accepted, both tickets
cross-referenced, no third one filed, and both halves of the consequence for this branch's own
surfaces stated. See § 8.

### This commit's own first gate run was red, on a real regression I introduced

Not a flake, and recorded here rather than quietly re-run. Step 16 failed with **one** new failure:

```
ratchet: 1 NEW test failure(s) not in the baseline (regression):
  ✗ tests/Feature/Quality/PestNegatedExpectationMessagesTest.php::it no test passes a custom failure message to a negated Pest expectation
```

F1's new arm ended with `expect($supp->json('void_blocked_reason'))->not->toBeNull($message)`. Pest's
`->not->` is a proxy, not a matcher: `OppositeExpectation::__call` runs the positive assertion and,
when it succeeds, discards it and composes a generic sentence with every argument — the author's
message included — run through `Exporter::shortenedExport()`. The assertion held; the diagnostic
would have been exported and truncated. That gate ships with zero exemptions and no baseline, and it
was right.

Rewritten as a positive expectation that keeps the message and says more than the original —
`is_string($reason) && $reason !== ''`, which is what the disabled button's tooltip actually needs.

**Why the file-scoped runs could not see it.** That gate walks the whole `tests/` tree, so it only
runs in a full-suite invocation. Every run I made while building these arms was file-scoped, for the
reason the session was told to be careful about: a cold review was holding two `pest` processes at
the time. Nothing about that was wrong, and the consequence is worth stating — **a file-scoped run
is not a rehearsal of the gate**, and a repo-walking guard is invisible to it by construction.

### The reviewer's own limits, in its words

Recorded because a review's blind spots belong beside its findings:

- **no `PROCESS` on the grant** — it could not read the process list, so it could not tell a running
  suite from a finished one;
- **the wrong collation on the throwaway database** — its `u7_review_*` database was created with a
  collation the application does not use;
- both of those are **defects in the credential grant it was given, not in the review**;
- **the drive was blocked on a database name** — the drive fixture's seed command refuses unless the
  database name carries a `drive` token, which its `u7_review_*` database does not, so it drove
  nothing in a browser;
- **one full-suite run only**;
- **PHP 8.3.32 only** — the version matrix is a permanent residual of the local enforcement floor;
- and it disclosed that it **ran quarantined with an injected memory index**, and **demonstrated
  provenance for each finding** rather than asserting it.
