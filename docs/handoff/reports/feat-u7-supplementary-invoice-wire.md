# feat/u7-supplementary-invoice-wire — the wire and the control for supplementary invoicing

**Base:** `origin/staging` @ `de48818`. **Branch:** `feat/u7-supplementary-invoice-wire`.
**Shape:** four source files + one new test file, one new ticket, this report.
**Implementation commit:** `8167282`.

---

## 0 — The premise, checked against the tree before any work

The brief's account of the tree is correct in every particular. Confirmed by reading:

- `app/Finance/Http/Controllers/InvoiceController.php:45-49` (base) carried the comment
  *"Supplementary invoicing has no route yet: this commit is domain and schema, and the wire
  shape that lets a bursar choose a kind lands with the modal."*
- Both call sites passed the literal: `:49` in `generate()` and `:125` in
  `generateForStudent()`.
- `resources/js/components/finance/new-invoice-modal.tsx:365` (base) posted `{ lines }` only.
  Every `kind` elsewhere in that file is `InvoiceLineKind` — the per-line
  charge/waiver/discount — not the invoice kind.
- The domain half is all present from #259: `App\Finance\Enums\InvoiceKind`,
  `Invoice::$casts['kind']` (`app/Finance/Models/Invoice.php:67`), the two
  `isEpisodeExclusive()` arms in `GenerateInvoice` (`:268`, `:339`), the re-keyed generated
  column and its unique index
  (`database/migrations/2026_08_18_100000_…:225`), and
  `InvoiceReadModel::activeScheduledInvoiceIdForEnrollment()` (`:216-236`).
- `tests/Feature/Finance/FinanceApiAcceptanceTest.php:231` records the same gap from the test
  side: *"generate routes hardcode InvoiceKind::Scheduled … The Action is the only way to reach
  the state under test until the modal lands."*

Nothing in the brief needed correcting. No deviations from it were taken.

---

## 1 — The wire

**One rule, on the shared parent request.**
`app/Finance/Http/Requests/GenerateInvoiceRequest.php:68`:

```php
'kind' => ['sometimes', Rule::enum(InvoiceKind::class)],
```

and one accessor at `:349-366`:

```php
public function invoiceKind(): InvoiceKind
{
    $kind = $this->validated('kind');

    return $kind === null ? InvoiceKind::Scheduled : InvoiceKind::from((string) $kind);
}
```

`validated()` rather than `input()` is load-bearing: an invalid value cannot reach the
accessor, because `rules()` has already turned it into a 422 keyed on `kind`. Over `input()`
the same method would either throw a `ValueError` (a 500) or, with `tryFrom()`, fall through
to the Scheduled default and raise a term bill for a request that asked for a supplementary
charge. Proof (e) and mutation M5 are about exactly that.

### Which routes carry it, and which do not

| Route | Controller | `kind` on the wire? | Why |
| --- | --- | --- | --- |
| `POST /v1/finance/students/{student:uuid}/invoices` | `InvoiceController::generateForStudent` | **Yes** | The modal's route (`routes/endpoints/finance.php:230`). This is what the branch exists for. |
| `POST /v1/finance/invoices` | `InvoiceController::generate` | **Yes** | Registered, permission-gated and reachable by any client holding `finance.invoice.generate`. It uses `GenerateInvoiceForStudentRequest`'s parent class, so it carries the rule *by construction* — excluding it would have required actively `unset`ting the rule in the subclass, which is the opposite of construction and would put the two routes' contracts out of step. |
| `POST /v1/finance/bulk-invoice-runs` → `ProcessBulkInvoiceRun:346` | — | **No, deliberately** | Left as the `InvoiceKind::Scheduled` literal. A bulk run bills a *cohort's term fees*; there is no such thing as "the same supplementary charge for forty students". More concretely, the run's reconciliation depends on the per-episode unique index refusing a second scheduled invoice so a re-run records `already_billed` instead of double-billing (`routes/endpoints/finance.php`, the bulk-run block). Supplementary invoices never collide, so a supplementary bulk run would bill the whole cohort again on every re-run. |
| `App\Finance\Console\DriveFinanceStates:361` | — | **No** | Drive-fixture staging, not a client. |

### Existing callers, by construction rather than by luck

`sometimes` runs no rule when the key is absent, and `invoiceKind()` returns `Scheduled` for
an absent key. Every pre-existing caller sends no `kind`, so every one reaches the value it
reached before. Re-derived at the moment of writing:

- **`InvoiceKind::` references in `app/` and `database/`: 8**, of which the two that were
  literals in the controller are now `$request->invoiceKind()`; `ProcessBulkInvoiceRun:346`
  and `DriveFinanceStates:361` remain literals on purpose; the rest are a cast, the new rule,
  the new accessor, a read predicate (`InvoiceReadModel:231`) and one docblock reference.
- **`GenerateInvoice::handle()` call sites in `app/`: 2** (`ProcessBulkInvoiceRun:346`,
  `DriveFinanceStates:358`). Neither changed.
- **Test files referencing `InvoiceKind::`: 28**, with **52 occurrences**. None changed.
- **Regression run: `tests/Feature/Finance` + `tests/Feature/Rbac` — 996 tests, 996 passed,
  4687 assertions, 2 risky.** Raw:

```
{"tool":"pest","result":"passed","tests":996,"passed":996,"assertions":4687,"duration_ms":484730,"risky":2}
```

Proof (d) plus mutation M6 is the arm that keeps this true: it fails if `sometimes` ever
becomes `required` or the default is removed.

---

## 2 — The control

`resources/js/components/finance/new-invoice-modal.tsx`:

- **New exported type** `InvoiceKindChoice = 'scheduled' | 'supplementary'`, named separately
  from the per-line kind because both words are live on this one screen.
- **New exported pure function** `termBillLabel(alreadyInvoiced)` — returns
  `'Term bill (will be rejected — void first)'` when the episode already carries an active
  term invoice, `'Term bill'` otherwise.
- **New state** `invoiceKind`, initialised `'scheduled'` and reset to `'scheduled'` inside
  `loadEnrollment()` alongside the lines, so reopening the dialog for a different student
  cannot inherit the previous choice.
- **A Radix `Select` (`id="ni-invoice-kind"`) above the lines**, offering the two options.
  Both are always selectable — the option that will be refused is *labelled*, not disabled,
  because voiding-then-rebilling the term is a legitimate operation and the server is the
  authority on whether it is allowed right now.
- **The amber banner gains one sentence** naming Supplementary as the road out. Both
  sentences remain in step with `GenerateInvoice`'s 422, as the comment at the banner
  instructs.
- **`kind` is posted on every submit**, including the default.

**The default never follows the data.** `already_invoiced` reaches `termBillLabel` and the
banner only. The selected value on open is `'scheduled'` whether or not the episode is already
invoiced — driven and captured in §5, both ways.

### 3 — No new permission

Nothing was coined. Both generate routes keep `permission:finance.invoice.generate`
(`routes/endpoints/finance.php:26`, `:231`), unchanged by this branch. Reasoning recorded in
the rule's comment, on the bulk-run precedent.

### 4 — The guard did not move

`InvoiceReadModel::hasActiveScheduledInvoiceForEnrollment()` and the
`activeScheduledInvoiceIdForEnrollment()` it delegates to are **untouched** — `git diff`
against the base shows no change under `app/Finance/Services/`. `GenerateInvoice` is
untouched. The generated column and its index are untouched. Proof (b) is the arm that says so
under test, and mutation M3 is the watched red for it.

---

## 5 — The proofs

`tests/Feature/Finance/SupplementaryInvoiceWireTest.php`, six arms. Green, raw:

```
{"tool":"pest","result":"passed","tests":6,"passed":6,"assertions":32,"duration_ms":17327}
```

| Arm | What it proves |
| --- | --- |
| **a** | An episode with an active SCHEDULED invoice accepts a SUPPLEMENTARY invoice over HTTP. 201; row `kind` is `supplementary`; same episode; still `issued`; the episode now carries 2 invoices. |
| **a2** | The harness route (`POST /v1/finance/invoices`) carries the same choice. |
| **b** | The same episode still refuses a second SCHEDULED invoice — asserting the **message**, not the status. |
| **c** | The DATABASE, not the app: two raw inserts bypassing `GenerateInvoice` entirely. A second issued scheduled invoice is refused with driver code **1062**; two further supplementary inserts are permitted. |
| **d** | `kind` absent produces a `scheduled` invoice, and the guard it implies is live. |
| **e** | An invalid `kind` is a 422 field error on `kind` with **nothing written** to either table — checked for a garbled enum value *and* for `'charge'`, a valid `InvoiceLineKind` that must not be accepted as an invoice kind. |

### 5.1 — Every proof shown red against a stated mutation

Each mutation was applied alone, the file restored by `git checkout` immediately after, and
the suite re-run. Raw tool output for each, in order:

```
### BASELINE (green)
{"tool":"pest","result":"passed","tests":6,"passed":6,"assertions":32,"duration_ms":17327}

### M1 — generateForStudent reverted to the InvoiceKind::Scheduled literal
{"tool":"pest","result":"failed","tests":6,"passed":4,"assertions":25,"duration_ms":14970,"failed":2}
   FAIL a  — Expected response status code [201] but received 422.
   FAIL b  — Expected response status code [201] but received 422.

### M2 — generate() reverted to the InvoiceKind::Scheduled literal
{"tool":"pest","result":"failed","tests":6,"passed":5,"assertions":31,"duration_ms":13949,"failed":1}
   FAIL a2 — Expected response status code [201] but received 422.

### M3 — InvoiceKind::isEpisodeExclusive() returns false
{"tool":"pest","result":"failed","tests":6,"passed":4,"assertions":29,"duration_ms":13118,"failed":2}
   FAIL b  — Expected response status code [422] but received 409.
   FAIL d  — Expected response status code [422] but received 409.

### M4 — generated column re-keyed to IF(status='issued' AND kind <> 'zzz', …)
{"tool":"pest","result":"failed","tests":6,"passed":2,"assertions":22,"duration_ms":12933,"failed":4}
   FAIL a  — Expected response status code [201] but received 409.
   FAIL a2 — Expected response status code [201] but received 409.
   FAIL b  — Expected response status code [201] but received 409.
   FAIL c  — Failed asserting that 1062 is null.

### M5 — Rule::enum(InvoiceKind::class) weakened to 'string'
{"tool":"pest","result":"failed","tests":6,"passed":5,"assertions":24,"duration_ms":12516,"failed":1}
   FAIL e  — Expected response status code [422] but received 500.

### M6 — 'sometimes' changed to 'required'
{"tool":"pest","result":"failed","tests":6,"passed":5,"assertions":29,"duration_ms":12846,"failed":1}
   FAIL d  — Expected response status code [201] but received 422.

### RESTORED (green)
{"tool":"pest","result":"passed","tests":6,"passed":6,"assertions":32,"duration_ms":11903}
```

The FAIL lines above are the `message` fields of the same JSON objects, with the Pest test
names abbreviated to the arm letter; nothing else is elided.

**Three things in that table are worth reading rather than counting.**

**M1 is the reported defect, reproduced as a test failure.** Reverting one call site to the
literal turns arm (a) — the bursar raising a supplementary charge — into the exact 422 the
report described.

**M3 answers 409, not 500 or 201.** Making `isEpisodeExclusive()` return `false` removes the
Action's friendly pre-check *and* its 1062 translation, so the DB index still refuses the
second term bill and its 1062 falls through to `bootstrap/app.php`'s 1062 → 409 mapping. The
guard held; only its explanation was lost. This is also the concrete demonstration that arm
(b)'s message assertion is doing work: an assertion of "some 4xx" would have been satisfied.

**M4 needed two attempts, and the first one is a finding about the migration rather than about
this branch.** The obvious mutation — dropping `AND kind = 'scheduled'` from the generated
expression — does not produce a red test at all. The migration's own `assertReKeyShape()`
(`2026_08_18_100000_…:374-381`) refuses to complete, so all six arms error during
`migrate:fresh` with:

```
finance_invoices.active_enrollment_key generation expression does NOT mention [kind] and must:
got [if((`status` = _utf8mb4'issued'),`student_curriculum_id`,NULL)], expected the sense of
[IF(status = 'issued', student_curriculum_id, NULL)]. The MODIFY reported success without
changing the expression, so the unique index constrains the wrong set of rows.
```

That self-check is doing its job and is a second, independent guard on the same invariant.
The mutation reported above is the one that gets *past* it — an expression that mentions
`kind` and discriminates nothing — and that is what reds arm (c) specifically, with
`Failed asserting that 1062 is null`: the second supplementary insert collided.

---

## 6 — The drive

`APP_ENV=drive`, throwaway `portal_drive`, port 8001, assets built with `pnpm run build`
before seeding. Browser: system Chrome via `puppeteer-core` installed in a temporary
directory outside the repository. Screenshots in
`docs/handoff/drives/2026-08-20-supplementary-invoice/`.

### 6.1 — Both fixture count tables, verbatim from the seed

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the bulk-run screen (U6) prices a COHORT from an ACTIVE schedule and reports the unplaceable:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+------------------+----------------+-------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Active schedules | Cohort at slot | Unplaceable |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+------------------+----------------+-------------+
| A (school#1) | 2                 | 2     | 2            | 1             | 1                 | 3                 | 0                   | 1                | 2              | 7           |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 1                | 2              | 1           |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+------------------+----------------+-------------+
Bulk invoice runs: /finance/bulk-invoice-runs — the cohort above sits at (term, JSS 1); JSS 2 has an empty one on purpose.

Authoring slot per school — … the guardians screen links a new guardian to students by admission number:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+----------+-----------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Students | Guardians |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+----------+-----------+
| A (school#1) | 2                 | 2     | 2            | 1             | 1                 | 3                 | 0                   | 10       | 0         |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 3        | 0         |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+----------+-----------+
```

No zero in any column that is not exempt. `Payments (migrated)` and `Guardians` are the two
documented always-zero columns and are not a stop. The first eight columns of table 2 repeat
table 1's value for value, as they should.

**The fixture reached the required state without modification.** `DriveFinanceStates` stages
scheduled invoices for School A students, so an episode carrying an active term invoice exists
on a fresh seed — confirmed by query before opening a browser, and by the screen's own
`already_invoiced: true`.

### 6.2 — BEFORE the change (base `de48818` restored into the working tree, rebuilt)

Seat `maker@drive.test` (accounts_officer, school#1), statement of a school#1 student whose
current episode already carries an active scheduled invoice.

```
statement url = http://localhost:8001/finance/students/a28c65e0-71a1-.../statement | title = Statement — … - Laravel
GET billable-enrollment = {"status":200,"body":{"academic_context":"Enrollment a28c65e0-74c2-429f-b49e-4a4c244dd950","already_invoiced":true}}
New invoice button found = true
MODAL selects = [
 {
  "type": "radix",
  "id": "(none)",
  "value": "Charge"
 }
]
AMBER banner = "This episode already has an active term invoice. Void it first — creating another term invoice will be rejected."
POSTED BODY = {"lines":[{"description":"Damaged locker door","amount_minor":1234500,"kind":"charge"}]}
ERRORS ON SCREEN = ["This episode already has an active term invoice. Void it first — creating another term invoice will be rejected.","This enrollment already has an active TERM invoice. Void it before billing the term again."]
TOAST "Invoice created." = false
```

Captures: `before-01-statement.png`, `before-02-modal-open.png`, `before-04-filled.png`,
`before-05-submitted.png`.

What this establishes, in order:

1. **The episode is in the reported state** — the API's own `already_invoiced` is `true`.
2. **The modal has exactly one select, and it is the LINE kind** (`"value": "Charge"`, no
   `id`). There is no invoice-kind control; the bursar has no way to ask for anything but a
   term bill.
3. **The posted body carries no `kind`** — `{"lines":[…]}`, exactly what the brief said
   `:365` produced.
4. **The refusal is the reported one, rendered on screen**, and it is the Action's sentence
   verbatim: *"This enrollment already has an active TERM invoice. Void it before billing the
   term again."* Nothing was created.

### 6.3 — AFTER the change (branch HEAD, rebuilt, fixture reseeded)

Same seat, same class of episode (fresh fixture, so a new student uuid — re-derived, not
carried).

```
statement url = http://localhost:8001/finance/students/a28c68a6-317b-.../statement | title = Statement — … - Laravel
GET billable-enrollment = {"status":200,"body":{"academic_context":"Enrollment a28c68a6-3b6a-41a2-b8ce-bad11392096a","already_invoiced":true}}
New invoice button found = true
MODAL selects = [
 {
  "type": "radix",
  "id": "ni-invoice-kind",
  "value": "Term bill (will be rejected — void first)"
 },
 {
  "type": "radix",
  "id": "(none)",
  "value": "Charge"
 }
]
AMBER banner = "This episode already has an active term invoice. Void it first — creating another term invoice will be rejected. To bill something outside the term’s fees — damages, a trip, a lost book — choose Supplementary charge above instead, which needs nothing voided."
INVOICE KIND options = ["|Term bill (will be rejected — void first)","|Supplementary charge"]
AFTER CHOOSING, selects = [
 {
  "type": "radix",
  "id": "ni-invoice-kind",
  "value": "Supplementary charge"
 },
 {
  "type": "radix",
  "id": "(none)",
  "value": "Charge"
 }
]
POSTED BODY = {"kind":"supplementary","lines":[{"description":"Damaged locker door","amount_minor":1234500,"kind":"charge"}]}
ERRORS ON SCREEN = []
TOAST "Invoice created." = true
```

Captures: `after-01-statement.png`, `after-02-modal-open.png`, `after-03-kind-options.png`,
`after-04-filled.png`, `after-05-submitted.png`.

And the same modal opened on a school#1 student whose episode is **not** already invoiced:

```
student a28c68a6 | already_invoiced=false | KIND SELECT VALUE = "Term bill" | AMBER = null
```

Capture: `after-06-not-yet-invoiced-plain-label.png`.

What this establishes, in order:

1. **The control exists and is the invoice kind**, distinct from the line kind — two Radix
   selects now, one with `id="ni-invoice-kind"`.
2. **The default is Term bill in BOTH episode states.** On the already-invoiced episode the
   select's value on open is the term-bill option; on the not-yet-invoiced episode it is also
   the term-bill option. `already_invoiced` changed the label only, from
   `"Term bill (will be rejected — void first)"` to `"Term bill"`.
3. **The trap is visible before submit**, both in the option label and in the banner's new
   sentence.
4. **The wire carries the choice** — `{"kind":"supplementary","lines":[…]}`.
5. **The invoice was created**: no errors on screen, and the toast fired.
6. **The database agrees, and shows the mechanism**, read back from `portal_drive`:

```
episode 1 invoices: [{"id":1,"kind":"scheduled","status":"issued","total_minor":300000,"active_enrollment_key":1},
                     {"id":10,"kind":"supplementary","status":"issued","total_minor":1234500,"active_enrollment_key":null}]
supplementary lines: [{"description":"Damaged locker door","amount_minor":1234500,"kind":"charge"}]
```

The term bill keeps `active_enrollment_key = 1`; the supplementary charge computes `NULL` and
therefore cannot collide. That is #259's generated column, observed in the running app rather
than inferred. `1234500` minor units is the `12345.00` typed into the form — the modal's
naira→minor conversion, unchanged by this branch and visible end to end.

### 6.4 — Drive friction paid for on this run

**`SESSION_DOMAIN=localhost`, so driving `127.0.0.1:8001` silently fails to authenticate.**
This cost most of an hour and looked exactly like a wrong password. `POST /login` answers
`302 → /dashboard`, `/dashboard` answers `302 → /login`, and the browser ends on the login
page with **no session cookie at all** — `document.cookie` holds only `appearance`. The
credentials are fine (`Hash::check` confirmed against the seeded user). The cookie is scoped
to the domain `localhost` (`.env.drive.example:39`) and is dropped on `127.0.0.1`. **Drive
`http://localhost:8001`, never the loopback IP.** `docs/finance/drive-environment.md` and the
`finance-drive` skill both document the `/dashboard` bounce, which is what this looks like
from the outside, so the two are easy to conflate — the discriminator is the cookie jar.

**Port 8001 was held by an unrelated, seven-hour-old `php artisan serve` from a different
checkout** (`/private/tmp/review-u6screen`), left behind by an earlier session. `artisan serve`
reports `Failed to listen on 127.0.0.1:8001 (reason: Address already in use)` **to its log and
then exits**, so the port answers, the login page renders, and the failure presents as a
mysterious auth problem against someone else's database. I killed that process (PID 81320) to
free the port; it is restartable from its own directory. Worth checking `lsof -ti tcp:8001`
before concluding anything about a drive.

---

## 7 — Out of scope, filed

`docs/handoff/tickets/nothing-shows-which-invoices-are-supplementary.md`. Every read path in
it was read before it was written; the brief's list was a starting point, not the answer, and
it turned out to be incomplete in one direction that matters. In summary:

- `InvoiceResource` does not serialise `kind` **at all** — it is the only invoice serialiser,
  so no client can distinguish the kinds today. That is the root, and it makes everything
  below it a consequence rather than an independent omission.
- `resources/js/types/finance.ts`'s `Invoice` type mirrors it and has no `kind`.
- `statement.tsx` renders the invoice rows from `display_number` / `settlement_state` / money
  and cannot show a kind the wire does not carry.
- `InvoiceReadModel::billedTotalForStudent()` (`:60-68`) applies no `kind` filter, so the
  statement's billed total now sums term bill *and* supplementary charges with no breakdown.
- `receipt.tsx` + `PaymentReceiptController:156-157` name allocations by
  `invoice_number` + `academic_context`; two invoices on one episode share the
  `academic_context`, so a split payment shows two lines with no way to tell them apart.
- **Not in the brief's list and the most expensive of them:** `request-void-modal.tsx:99`,
  `issue-credit-note-modal.tsx:117` and `record-payment-modal.tsx:158` each title themselves
  with `invoice.display_number` alone. Voiding the wrong invoice discards its payment
  allocations, and this is the confirmation a bursar reads before doing it.
- **Checked and NOT affected:** the bulk-run screens render no invoice kind and need none,
  because `ProcessBulkInvoiceRun:346` raises only scheduled invoices.

---

## 8 — Gates

Run locally on the changed files only:

- `./vendor/bin/pint <the three PHP files>` — one fix applied
  (`fully_qualified_strict_types` on the new test file), then clean.
- `npx prettier --write` on the modal — *All files formatted correctly*.
- `npx eslint` on the modal — *No issues found*.
- `npx tsc --noEmit` — **42 errors before the change and 42 after**, none in any touched file.
  Measured by stashing the branch, re-running, and unstashing. No regression, and the ratchet
  is unmoved.

`bin/quality` has **not** been run manually, per the brief. The pre-push hook is the gate.

---

## 9 — What I did not verify

- **`bin/quality` in full, including the arch group, the four lints, Larastan and the whole
  suite.** Only the finance and RBAC directories were run here (996 tests). The pre-push hook
  is where the rest happens, and its result is not in this report.
- **`bin/quality-clean-db`** — migrate-from-zero and rollback reversibility. This branch adds
  no migration, so there is nothing new for it to exercise, but it was not run.
- **School isolation was not driven.** This branch adds no route, no read path and no query;
  the one new server-side field is a validation rule on an existing request. There was nothing
  isolation-shaped to observe, so the two-seat side-by-side check was not performed. If a
  reviewer disagrees that the change is isolation-neutral, that check is the thing to ask for.
- **The `super@drive.test`, `checker@drive.test`, `void-checker@drive.test` and
  `school-b@drive.test` seats were not driven.** Only `maker@drive.test` was. The modal is
  gated on `finance.invoice.generate`, which the maker holds and which this branch did not
  change; the other seats' relationship to this screen is unchanged from before the branch.
- **The Radix option *values* could not be read out of the DOM.** `[role="option"]` elements
  carry no `data-value`, which is why the `INVOICE KIND options` line above shows an empty
  value column (`"|Term bill…"`). The selected value was read from the trigger's rendered text
  instead. For this change that is adequate — the two options are distinguished by their
  labels and the posted body proves which value was sent — but it is a label-based reading, not
  a value-based one, and the drive skill's rule prefers values.
- **A supplementary invoice's downstream behaviour** — paying it, credit-noting it, voiding it,
  how it lands on the statement's totals — was not driven. #259's suite covers void and the
  guard; the rendering consequences are the filed ticket.
- **`already_invoiced` remaining `false` after a supplementary invoice** is covered by
  `FinanceApiAcceptanceTest` from #259 and was not re-proved here.

---

## 10 — The gate (appended after the cold review, which ran before this had happened)

Recorded here because §9 listed `bin/quality` as unverified and the cold review named the
same gap: at review time not one of its steps had executed on this branch. It has now. This
section adds that outcome and answers none of the review's findings — those are the project
lead's.

**`bin/quality`: PASS, 15/15,** on the pre-push hook. Every step green, including `arch`,
Larastan, the six lints and the full suite against `tests/ratchet-baseline.txt`. Pushed;
`git ls-remote --heads origin feat/u7-supplementary-invoice-wire` and `git rev-parse HEAD`
both read `7237ad3f6f76bcdcb18ee245f42f8b7d9f675c45`, so the remote carries exactly what was
reviewed and nothing sits unpushed.

### 10.1 — The first attempt was blocked, and the cause was sequencing, not the diff

The first push was refused at step 15 with 23 regressions printed against the ratchet. The
run underneath that print was far worse than the print: **15 failed and 321 errors out of
1833 tests**, dominated by

```
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'portal_testing.schools' doesn't exist
SQLSTATE[HY000]: General error: 1412 Table definition has changed, please retry transaction
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'roles.id' in 'on clause'
```

That is a second process running `migrate:fresh` on `portal_testing` underneath a live suite.
**I caused it:** the cold-review subagent and `git push` were launched in the same message,
and both use that database. The only Finance entry in the entire run was
`TriggerBodiesAreDumpSafeTest` reporting *"no triggers found — this test would pass vacuously
and prove nothing"* — which is that same dropped schema, and is a test doing exactly its job.
No arm of this branch appeared.

Artifacts and conditions were captured before anything was re-run, per the project's rule on
this: the stamped suite log and junit copied out of `/var/folders/…/quality-runs/`, load
average 7.39, elapsed 469 s — above the ~350-440 s band, consistent with contention.

**This is not ADR 0053's non-determinism.** That signature is `FAIL 23` with no missing
tables; this one is 321 errors with tables absent mid-run. The re-run is therefore not
"retrying until green": the tree was byte-identical, the cause was identified from the
artifacts and is independent of the diff, and the second run was made only after confirming
no other `pest` process was alive.

**The cold reviewer hit the same thing independently**, without being told about it — its
first run of the new test file came back red with
`1062 Duplicate entry … role_has_permissions.PRIMARY` and three deadlocks on `roles`, from a
concurrent `pest` (PID 33834) on this same tree. It captured, waited, and re-ran clean at 6
passed / 32 assertions. Two contexts, the same interference, the same discrimination.

**The general lesson, since it will recur:** the cold review and the gated push must not run
concurrently on this project — they share `portal_testing`, and the resulting corruption
presents as a broad, alarming and entirely fictitious regression in whatever unrelated
subsystem happens to be running when the tables vanish. `lsof`/`ps` for a live `pest` before
believing a suite-wide red.
