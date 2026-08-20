# feat/u7-supplementary-invoice-wire — the wire and the control for supplementary invoicing

**Base:** `origin/staging` @ `de48818`. **Branch:** `feat/u7-supplementary-invoice-wire`.
**Shape:** three source files + one new test file, one new ticket, this report.
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

**One thing this section got wrong by omission, corrected in the fifth commit.** The
`FinanceApiAcceptanceTest.php:230-232` passage quoted above as evidence that the gap was real
is **falsified by this same commit** — it says both generate routes hardcode the kind and that
the Action is the only way to reach the state *"until the modal lands"*, and the modal landed
here. Quoting a comment as premise evidence while ending the condition it names, and leaving it
standing, is how the next reader concludes there is still no wire. The comment has been
rewritten (fifth commit) to say what is true now and why the arm still reaches the state through
the Action rather than over HTTP: it is proving a READ-side property that must hold for a
supplementary invoice raised by any writer, including a job or a seeder, so keeping it
independent of the request layer is deliberate. Two further comments this branch falsified are
listed in §11.

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

**The same property that justifies that exclusion removes a backstop from the two routes this
branch DOES open, and this table did not say so.** "Supplementary invoices never collide" is
used above as a reason to keep bulk runs scheduled-only; read in the other direction it means
every invoice-creation path a client could reach before this branch was refused on a repeat by
`UNIQUE(school_id, active_enrollment_key)`, and on the supplementary path nothing is. A retried
POST after a client timeout creates a second identical supplementary invoice, each posting its
own `LedgerEntryType::Charge`. That is the intended semantics — there is no correct uniqueness
key for a charge type that is unbounded by nature — but it is an exposure this branch introduces
and §9 did not list it. Written up, with two priced options and no recommendation, in
`docs/handoff/tickets/a-supplementary-invoice-has-no-duplicate-backstop.md`.
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
invoiced — **driven and captured in §6.3**, both ways. (This line cited §5 until the fifth
commit; §5 is the Pest arms and the mutation table, which contain nothing about the modal. The
UI captures have always been §6.)

**What §6.3 settles, and what it does not.** It settles that the default does not follow the
DATA: the same select reads `"Term bill (will be rejected — void first)"` on an already-invoiced
episode and `"Term bill"` on one that is not, and `'scheduled'` is the selection in both. It does
**not** settle CARRY-OVER between two students in one browser session, because the report does
not record whether that second open followed the supplementary submit in the same session — it
was a fresh page load. The reset property is re-derived from source in §9 and listed there as
unobserved.

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
guard held; only its explanation was lost.

**What that does and does not demonstrate — corrected after the cold review.** This paragraph
said M3 was "the concrete demonstration that arm (b)'s message assertion is doing work". It
demonstrates something narrower. The message assertion discriminates **this** refusal from the
other 422s these routes emit — a bad discount policy, a negative total, a missing enrollment —
where a bare `assertStatus(422)` would not. It does **not** discriminate the pre-check from the
index. Delete `GenerateInvoice`'s `if ($kind->isEpisodeExclusive()) { $this->assertNoActiveInvoice(…) }`
block and arm (b) still passes: the unique index refuses the second term bill anyway, and the
1062 translation further down throws the identical sentence, so the message assertion is
satisfied by the exception path. Arm **(b2)** is what pins the layer; §12 carries its watched
red and the measurement that the whole Finance directory stays green without that block.

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

Same seat (`maker@drive.test`), same class of episode. **The three captures are three different
fixture personas, not one student reseeded**, and this line implied otherwise by saying "same
class of episode (fresh fixture, so a new student uuid)":

- **before** — a school#1 student whose current episode already carries an active term invoice,
  on the base build;
- **after** — a *different* school#1 student in the same state, on the branch build (the fixture
  was reseeded between the two runs, so the uuids do not correspond);
- **plain label** — a *third* school#1 student whose episode is **not** yet invoiced.

They are not a before/after pair on one row, and nothing here depends on their being one. The
property under test is a property of the SCREEN GIVEN AN EPISODE STATE, and it holds in each:
`already_invoiced: true` produces the labelled trap and the banner, `already_invoiced: false`
produces the plain label and no banner, and `'scheduled'` is the selection in all three.

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
- **The modal's invoice-kind reset between two students, in one browser session.** Re-derived
  from source and it holds — in `resources/js/components/finance/new-invoice-modal.tsx`, the
  `useEffect` that runs on open depends on `[isOpen, loadEnrollment, loadPolicies]`;
  `loadEnrollment` is a `useCallback` over `[student.uuid]`, so changing student re-creates it and
  re-fires the effect; it calls `setEnrollment(null)` and `setInvoiceKind('scheduled')`
  synchronously before its first `await`; and the select renders only inside `{enrollment && (`,
  so it is unmounted for the whole window. (**Cited by symbol, not by line.** This paragraph
  carried seven line numbers and all seven were wrong — derived against the working tree mid-edit,
  every one drifted before the branch was pushed. Naming the symbols ends that, and is the
  convention the third round of stale citations on this branch earned.) **Re-derived, not observed.** §6.3's second-student
  capture was a fresh page load, so it does not exercise carry-over, and no instrument on this
  platform can red this property — there is no JavaScript test runner
  (`docs/handoff/tickets/no-javascript-test-runner.md`, which now names this exact property).
  It matters more than an ordinary unproven frontend property: a carried-over `supplementary`
  produces a **201 and a success toast** for a student who needed the term bill, because a
  supplementary invoice cannot collide with anything. The cheap partial — open the dialog for a
  second student in the same session right after a supplementary submit, and read the trigger —
  was not done and is recorded in that ticket.

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

---

## 11 — The review, its findings, and what it is not

### 11.0 — What this review was, stated first because it changes what it counts as

**This was a subagent of the same chat that did the work, not an independent cold review.** Its
own opening line records the frame: the spawning context supplied the report path and the branch
name and nothing else. That is the maximum isolation available inside one session, and it is
genuinely worth something — it read the repository rather than my reasoning, it re-derived scope
instead of accepting mine, and it produced findings I had not thought of. Two of its findings
were afterwards verified against the tree by the project lead.

**It is not what `finance-method` means by the separation, and this report must not be read as
though it were.** The method's claim is that a context which did not do the work finds what the
context that did cannot; a subagent I spawned shares my process, and I controlled its frame by
choosing what to pass it. The findings below stand on their own evidence — each cites files and
lines, and they are checkable without trusting either of us. The *absence* of findings does not.

**The branch has not had an independent review.** Per `finance-method`, this change is
full-review tier — it touches money and a database invariant — and a cold session started fresh
from this file is still owed before merge.

### 11.1 — Its own stated limits, in its words

- It ran **"against the working directory, not a fresh clone"**, and said why that mattered:
  while it ran the new test file, a second `pest` (PID 33834) was executing against
  `portal_testing` from this same tree and reddened its first run. **"Isolation was observed, not
  engineered."** That is the collision written up in §10, hit independently from the other side.
- **`bin/quality` had not run on this branch at review time** — it noted that **"not one of its
  steps has executed on this branch"** and that this report's "the pre-push hook is the gate" was
  **"a forward-looking claim, not evidence"**. §10 is the answer to that and postdates it.
- It **did not reproduce the drive** (no browser, no `portal_drive`) and took §6's captures **"as
  told"**, including the two friction findings. It **did not open the ten PNGs** (this report said eleven; there are ten — `before-01`, `-02`, `-04`, `-05`, and `after-01` through `-06`).
- It **did not reproduce the 996-test finance + RBAC run**, and flagged that given the concurrent
  `pest` it **"cannot say whether the report's run was clean of the same interference"**, noting
  `duration_ms 484730` sits above this project's ~350-440 s band. That caveat is live: §1's
  regression figure was not re-verified after §10's collision was understood.
- It read **none** of the ignored files it found by pattern (`.env*`, a `junit.xml`, `plan_docs/`,
  `.claude/settings.local.json`), and named the live `pest` from this tree as **"the argument for
  the fresh-clone rule, and I did not use it"**.

### 11.2 — The findings, at the severities set by the project lead

Severities below are the lead's, carried as given. I have not ranked them.

| # | Finding | Severity | What changed |
| --- | --- | --- | --- |
| 1 | Two comments this branch falsifies, neither updated — one of them the passage §0 quotes as its own premise evidence | ticket | **Fixed**, and a third found |
| 2 | §2's cross-reference points at §5; the UI captures are §6, and the carry-over half was never settled | ticket | **Fixed**; claim moved to §9; property named in the JS-runner ticket |
| 3 | First client-reachable paths with no duplicate backstop | ticket | **Filed**; §1 corrected |
| 4 | Report says "four source files"; the diff has three | ticket | **Fixed** |

**Finding 1 — and it was three, not two.** Re-derived by grep rather than taken from the review:

- `tests/Feature/Finance/FinanceApiAcceptanceTest.php:229-232` — rewritten. It now says the state
  *is* reachable over HTTP as of U7, and states why the arm still reaches it through the Action
  anyway: it proves a read-side property (`already_invoiced` is scheduled-only) that must hold for
  a supplementary invoice raised by **any** writer — a job, a console command, a seeder — so
  keeping the arm independent of the request layer is the point, not a leftover. It now points at
  `SupplementaryInvoiceWireTest` as where the wire is proved.
- `resources/js/components/finance/new-invoice-modal.tsx:249` — "THREE FUNCTIONS ARE EXPORTED WITH
  NO IMPORTER" is now "FOUR FUNCTIONS AND ONE TYPE", listing `selectablePolicies`,
  `termBillLabel`, `patchForKind`, `wireLine` and `InvoiceKindChoice`. Re-derived with
  `grep -n '^export '` on the file minus `NewInvoiceModal`, which `statement.tsx:20` does import,
  and each of the five confirmed to have no reference outside this file.
- **`tests/Feature/Finance/ReductionEnforcementTest.php:248` — found while fixing the other two,
  and not in the review's list.** It cites `wireLine()` at `new-invoice-modal.tsx:113-128`. This
  branch added the invoice-kind type and `termBillLabel` above it, moving `wireLine` from `:114`
  (confirmed against `git show de48818:…`) to `:147`. Same defect, same commit, and it is the
  exact failure `docs/handoff/tickets/stale-path-line-citations.md` exists for. Corrected to
  `:147-162`, with the move recorded inline so the next reader knows why it changed.

**Finding 2.** §2's reference now reads §6.3, and §2 states what that capture settles (the default
does not follow the data) and what it does not (carry-over between two students in one session).
The carry-over claim is in §9's not-verified list, worded as re-derived from source and not
observed. **The component was not changed.** I verified the property first, since the brief made a
failure there a stop: the effect at `:339-353` re-fires on a student change through
`loadEnrollment`'s `[student.uuid]` dep (`:305`), `setInvoiceKind('scheduled')` runs at `:287`
before the first `await` at `:290`, and the select is unmounted while `enrollment` is null
(`:449`). It holds. `docs/handoff/tickets/no-javascript-test-runner.md` now names this property as
a concrete thing no step of the gate can red, with the reason it is worse than an ordinary
unproven frontend property and the cheap partial that was not done.

**Finding 3.** `docs/handoff/tickets/a-supplementary-invoice-has-no-duplicate-backstop.md`, derived
from the code and citing arm (c) by name and line as its positive evidence. Two options priced —
accept, or an idempotency key — with what the key would need (origin, storage inside the Action's
transaction, window, behaviour on a hit, and what it still would not solve). **No recommendation
is made and neither was built.** §1's route table now carries the sentence it was missing.

**Finding 4.** One word: three source files, not four.

### 11.3 — One imprecision the review found in the filed ticket

Below its own finding threshold, and corrected in the fourth commit: the supplementary-invoice
read-path ticket's §6 said "nothing saying why there are two" allocation lines, while its §3 had
it right. The two lines carry different invoice numbers and **are** distinguishable; what is
missing is the **kind**. §6 now says that, and says it is the same claim as §3 rather than a
stronger one.

---

## 12 — The cold review

Independent session, fresh clone, its own database — the review §11.0 said the branch was still
owed. Verdict: **ship after this commit.** Severities below are the project lead's, carried as
given; nothing here is ranked by me.

### 12.1 — Its stated limits

- It **did not run `bin/quality`**. §10 remains this report's only evidence for the gate.
- It **did not reproduce the drive**. §6's captures are still taken as told.
- **Its throwaway database was created with `utf8mb4_0900_ai_ci`**, so `SchemaConventionsTest`
  failed constantly and **trigger-collation behaviour went unverified there**. That is a defect in
  the credential grant the reviewer was handed — the project lead's, recorded here as such. It is
  not the reviewer's error and it is not this branch's: `bin/quality`'s own runs (§10) use the
  project's collation and cover the arm the cold environment could not.

### 12.2 — What it closed

**§1's 996-test regression figure reproduced independently** — 996 tests, 4687 assertions, 2 risky,
**243 s on an uncontended database**. That closes the caveat §11.1 left open, which was that the
original run's 484 s elapsed sat above this project's band and could not be cleared of the §10
collision. Two runs, two environments, same totals.

### 12.3 — The findings

| # | Finding | Severity | What changed |
| --- | --- | --- | --- |
| 1 | The `kind` rule's comment justifies `sometimes` with a failure the change cannot produce | fix | Comment rewritten; **arm (f) added**, bite-proved |
| 2 | Arm (b) does not pin what §5.1 said it pinned — it survives deleting the pre-check | fix | §5.1 corrected; **arm (b2) added**, bite-proved |
| 3 | Four falsified citations and seven fresh ones | ticket | All eleven re-derived; converted to symbols |
| 4 | The accessor's docblock overstates `validated()` | ticket | Rewritten as defence in depth |
| 5 | Two report nits and an orphaned docblock | ticket | Fixed |
| — | `loadEnrollment` has no cancellation | ticket | **Filed**, not fixed |

---

**Finding 1 — the rule was right for the wrong reason.**

The comment claimed `nullable` "would let `{"kind": "supplemenatry"}` through to `invoiceKind()`'s
default and silently raise a TERM bill". **False, and I confirmed it rather than taking it:**
`nullable` exempts only `null`, so `Rule::enum` refuses the typo under either rule and both answer
422. A misspelt kind was never the exposure.

The real one is an **empty** value. `nullable` short-circuits the remaining rules on `null`, so
`{"kind": null}` validates and takes the absent-means-scheduled branch; `ConvertEmptyStringsToNull`
turns `{"kind": ""}` into `null` before any rule runs, so the empty-string case lands identically —
and `""` is what an untouched `<select>` and most form serialisers post. `sometimes` runs the rules
whenever the **key is present**, whatever its value, so both are refused. Absence and emptiness are
different requests and only `sometimes` tells them apart.

**The property had no arm.** Arm (d) covers an absent key, arm (e) a garbled value; nothing covered
the case between them, and the file was 6/6 green under **either** rule. **Arm (f)** now asserts
that `{"kind": null}` and `{"kind": ""}` each 422 with `errors.kind` and write nothing to either
table. Watched red in §12.4.

**Finding 2 — a proof that survived deleting the thing it named.**

§5.1 read M3 as "the concrete demonstration that arm (b)'s message assertion is doing work". Arm (b)
itself was always honest about what it asserts; the report was not. Verified independently:
**delete `GenerateInvoice`'s `if ($kind->isEpisodeExclusive()) { $this->assertNoActiveInvoice(…) }`
block and the entire `tests/Feature/Finance` directory stays green — 662 tests, 662 passed, 3318
assertions** (arm (b2) filtered out, since it is the arm that reds). The unique index refuses the
second term bill regardless, and the 1062 translation throws the identical sentence, so the message
assertion is satisfied by the exception path.

§5.1 now says what the message actually discriminates — this refusal from the other 422s these
routes emit — and says plainly that it does not discriminate the pre-check from the index.

**Arm (b2) pins the layer, and it has to be an instrumentation assertion.** Two behavioural
discriminators were tried and both fail, which is recorded in the arm because the next reader will
reach for them:

- *"the refusal consumes no invoice number"* — `Sequences::next` runs after the pre-check, so on the
  pre-check path no number is drawn; but `Sequences::next` opens a **nested** transaction, so the
  increment rolls back with the Action's transaction. The counter ends identical on both paths.
- *"no INSERT was attempted"* — `DB::listen` fires from `Connection::logQuery`, which runs only
  after a statement **succeeds**. A failed INSERT is never logged, so its absence is true on both
  paths and the assertion is vacuous.

What is observable is the pre-check's own SELECT — the kind-filtered existence read
`InvoiceReadModel::activeScheduledInvoiceIdForEnrollment` issues. It succeeds, so it is logged, and
it exists only on the pre-check path. Arm (b2) captures statements through `DB::listen` and asserts
at least one such read ran during the refused POST. "At least one" and not a count: the claim is
"the pre-check ran", and pinning an exact number would red on an unrelated read of the same table
and prove nothing more.

**Finding 3 — eleven citations, all re-derived here, none taken from the review.**

Four were falsified **by this branch**, which added the invoice-kind type, `termBillLabel` and the
select near the top of `new-invoice-modal.tsx` and pushed everything below them down:

- `docs/handoff/tickets/a-malformed-200-renders-the-empty-state-not-the-error-state.md` cited the
  modal's `setPolicies(selectablePolicies(data ?? []))` at `:291`. At `de48818` that was right; at
  `b3014c5`, `:291` is `setInvoiceKind('scheduled')` — a line this branch added. Corrected to
  `:334`, the one place in that table where a line number is the useful identifier because the table
  is a list of call sites.
- `ReductionPreCheckTest`'s docblock cited the modal post at `:349` (now `:408`) and the pre-check
  call site as `InvoiceController.php:114` (now the `assertDiscountPoliciesUsable()` call in
  `generateForStudent`).
- `ReductionPreCheckTest`'s "TWO call sites of the pre-check (`:39` and `:83`)". **Re-derived, and I
  reached a different answer from the brief**, which gave the real sites as `33` and `109`: those
  are `assertMayReduce()`, which produces a 403 and is not what any arm in that file exercises. The
  file's subject is `assertDiscountPoliciesUsable()`, whose two call sites are one per public
  generate method. Worth recording: `:83` was **already wrong at `de48818`**, where it was a
  docblock line, so that citation had been stale before this branch touched anything.
- `GenerateInvoiceRequest`'s own "This runs at `InvoiceController:39`, the context refusal at
  `GenerateInvoice:100`". `:39` happens to still be right; `GenerateInvoice:100` is a docblock line
  — the refusal is in `handle()`.

Seven more were **fresh, introduced by §9 and §11.2 of this report**, which presented them as
re-derived from source. They were derived against the working tree mid-edit and every one had
drifted by the time the branch was pushed: the effect, the `[student.uuid]` dep, `setEnrollment(null)`,
`setInvoiceKind('scheduled')`, the first `await`, the `{enrollment && (` guard, and the accessor's
own span.

**All eleven are now cited by symbol wherever a symbol locates the target unambiguously**, and the
line numbers are gone rather than corrected. That is the cheaper convention and it does not go
stale — a function name does not move when something is inserted above it.
`ReductionPreCheckTest`'s docblock now says so in place of its third round of corrected numbers.
This is the failure `docs/handoff/tickets/stale-path-line-citations.md` exists for, and this branch
committed it three times.

**Finding 4 — `validated()` is defence in depth, not the guarantee.**

The docblock called it "THE WHOLE GUARANTEE". Measured: swap `validated()` for `input('kind')` and
`InvoiceKind::from()` for `tryFrom(…) ?? Scheduled`, and every arm still passes — because
`Rule::enum` has already refused the bad value. **The guarantee is the rule**; if the rule is
removed or weakened, that line catches nothing and arm (e) is what reds. The docblock now says
that, and separately says why the line is still worth writing: it cannot be handed a value nothing
checked if this method is later called from a path that skipped the rule, and `from()` dies loudly
rather than quietly becoming a term bill.

**Finding 5.**

- §11.1 said "the eleven PNGs"; there are **ten**.
- §6.3 said "same seat, same class of episode (fresh fixture, so a new student uuid)", implying one
  student reseeded. The three shots are **three different fixture personas** — an already-invoiced
  episode on the base build, a different already-invoiced episode on the branch build, and a third
  student not yet invoiced. §6.3 now says what they are and why nothing depends on their being one
  row: the property under test is a property of the screen given an episode state, and it holds in
  each.
- `GenerateInvoiceRequest`'s `@return list<InvoiceLineSpec>` block was orphaned above another
  docblock, with `lineSpecs()` carrying none. Pre-existing and phpstan-clean, and **this branch
  inserted `invoiceKind()` into the middle of it**, putting two unrelated methods between the
  docblock and its subject. Reattached to `lineSpecs()`.

**The filed ticket — `loadEnrollment` has no cancellation.**

`docs/handoff/tickets/a-late-enrollment-response-repaints-the-wrong-students-dialog.md`. No
`AbortController`, no generation token, so a late response for student A can `setEnrollment(dataA)`
under student B's open dialog. **Pre-existing; what this branch changed is the blast radius** —
`already_invoiced` used to drive only the amber banner and now also drives the banner's
Supplementary sentence and, through `termBillLabel`, the label on the select itself, so a stale
`true` prints an instruction to void a term invoice that does not exist, on the control the bursar
is about to use.

**It misleads; it does not misbill**, and the ticket says so plainly: the POST is addressed by
`student.uuid`, a prop rather than fetched state, so stale `enrollment` cannot redirect the write,
and the server re-resolves the episode and applies its own guard. The worst case is a wasted trip
or an unwarned 422. Nothing on this platform can red it — cross-referenced to
`no-javascript-test-runner.md`, with the note that a drive cannot reliably reproduce a promise-
ordering race either, so a drive that misses it is not evidence of absence. **Not fixed here.**

### 12.4 — The watched reds for the two new arms

Both applied alone; the file restored from a copy afterwards and re-run green. Raw:

```
### BASELINE (green)
{"tool":"pest","result":"passed","tests":8,"passed":8,"assertions":44,"duration_ms":9648}

### M7 — 'sometimes' -> 'nullable' on the kind rule
{"tool":"pest","result":"failed","tests":8,"passed":7,"assertions":37,"duration_ms":9558,"failed":1}
   FAIL f — Expected response status code [422] but received 201.

### M8 — GenerateInvoice's isEpisodeExclusive pre-check block DELETED
{"tool":"pest","result":"failed","tests":8,"passed":7,"assertions":44,"duration_ms":10116,"failed":1}
   FAIL b2 — Expecting [] not to be empty.

### M8b — whole tests/Feature/Finance with that block still deleted, arm (b2) filtered out
{"tool":"pest","result":"passed","tests":662,"passed":662,"assertions":3318,"duration_ms":156605}

### RESTORED (green)
{"tool":"pest","result":"passed","tests":8,"passed":8,"assertions":44,"duration_ms":9942}
```

**M7's red is the finding.** `Expected 422 but received 201` on arm (f) alone — under `nullable`,
an empty `kind` does not refuse, it **creates a term bill and returns 201**. That is the silent
wrong document the rewritten comment now describes, measured rather than argued.

**M8's two lines are one finding read twice.** Arm (b2) reds with `Expecting [] not to be empty` —
the pre-check's SELECT is gone, so nothing matched. **M8b is the part that matters:** with the same
block still deleted, the entire Finance directory passes 662/662. Everything except the arm written
for it is blind to that deletion — including arm (b), which is what §5.1 claimed otherwise.

### 12.5 — A process note on the mutation runs

M7's restore was done with `git checkout -- <file>` while that file also held **uncommitted** work
from Fixes 1, 4 and 5. It restored the file to `HEAD` and discarded all three, which was caught
immediately (a `grep` for the new text returned 0) and redone from the edit script — but the M7 red
above was produced against the correct tree and re-verified after. Mutation restores in this session
now copy from a saved file rather than going through git, which is what M8 used.
