# U11 — the payment receipt

**Branch:** `feat/finance-payment-receipt`
**Base:** `origin/staging` @ `938065ded64441000749e3f79322906fd76ff46f`
(`Merge pull request #253 from notOluwayimika/feat/ui-discount-policies-redesign`)
**Commits:** two — `0d8344c` (the feature) and `d2b95f7` (three gate failures the feature's
own `bin/quality` run found; see § "Deviations & self-inflicted reds").
**Pushed:** no.

**This is full-review tier.** It touches money rendering, a wire Resource, `school_id`
isolation, a fixture oracle (the drive count table) and two gate-adjacent test files.
No subagent review is attached — the brief said "report and stop", and this session's
operating instructions forbid spawning agents unrequested. **Recommend a cold session
against this file before merge.**

---

## 1. The premise, verified before building

Every claim the brief makes was checked against the repo first. All four hold.

| Claim | Verified |
| --- | --- |
| `PaymentResource`'s docblock states the obligation and explains why `origin` is withheld | `app/Finance/Http/Resources/PaymentResource.php:13-19` before this branch — "WHOEVER BUILDS THE FIRST ONE owes the migrated-payment refusal… `origin` is deliberately NOT exposed here" |
| The spec defers the refusal to this commit by name | `docs/handoff/opening-balance-import-spec.md:603-613` — "THE RECEIPT REFUSAL IS OWED, NOT BUILT… The obligation moves to whichever commit introduces the first receipt surface" |
| There is NO GET route for a payment | `php artisan route:list --json` filtered on `payment` returned exactly two rows, both `POST` (`…/invoices/{invoice:uuid}/payments`, `…/students/{student:uuid}/payments`) |
| The `origin` predicate's values are named by a CHECK | `2026_08_10_120000_finance_bank_account_foreign_keys.php:102-104` — `origin COLLATE utf8mb4_bin = 'portal'` / `= 'migrated'`; the column's own CHECK is `2026_08_07_110000_add_provenance_to_finance_payments.php:91` |

Also established rather than assumed: `NotificationType::PAYMENT_RECEIVED` has no
dispatcher (unchanged, and untouched here); `origin = 'migrated'` has exactly **one**
writer in the codebase, `PostOpeningBalanceBatch` (`grep -rln "'migrated'" app/` returns
that file, `PaymentResource`, and the notification enum — nothing else writes it).

**Nothing in the brief was contradicted by the code.** No premise-level disagreement.

---

## 2. Decision 1 — the read surface: an Inertia page route, no JSON endpoint

`GET /finance/payments/{payment:uuid}/receipt` → `PaymentReceiptController` (invokable),
resolving the whole document server-side into props. **No `GET /v1/finance/payments/{uuid}`
is coined.**

The argument, in the order it actually weighed:

1. **A document is true whole or it is absent.** Design system §26's most-repeated
   defect — five recorded instances, three of them inside the fix for the previous one —
   is a client-fetched screen whose four states collapse into two, so the screen makes a
   confident false statement. A page whose data arrives in the same response as the page
   has **no loading, error or empty state to collapse**: either the receipt rendered from
   real props, or the navigation failed and the component never mounted. For a *document*
   that property is worth more than it is for a list.
2. **The money rule points the same way.** `bin/ci-money-lint.php`'s format ban is total
   inside `resources/js/pages/admin/finance/` (`:40-44`, `:95-100`), so every figure must
   be computed and formatted server-side. An endpoint would compute them in the same PHP
   and add a second surface to keep in step with the page.
3. **The refusal gets one place to live.** A page route lets the refusal *be* the
   response — one status, one reason, rendered by the same component. An endpoint would
   refuse in JSON and still need a page to render that refusal: two statements of one rule.
4. **There is no second consumer.** An endpoint whose only caller is a page is the
   primitive built ahead of its consumer. `routes/web.php:237-241` (the statement page) is
   the in-repo shape for a finance screen resolving its own props, and this follows it.

**The permission is `finance.access`, read off the siblings rather than chosen.** The
route declares no ability of its own and takes the group's. Measured:
`php artisan route:list` gives the receipt route
`['web', Authenticate, SetSchoolContext, PermissionMiddleware:finance.access]` — the same
floor as the statement page (`routes/web.php:237-241`, no extra middleware inside the
`permission:finance.access` group) and the same as the statement's feed
(`routes/endpoints/finance.php:73`, no extra middleware inside the api group's
`finance.access`). `finance.payment.record` is the authority to **take** money; a receipt
is a **read** of money already taken, and every seat that can read the statement a payment
appears on can read that payment's receipt.

### One honest caveat on the Inertia 403

The refusal returns `Inertia::render(...)->toResponse($request)->setStatusCode(403)`. That
is safe on both paths, and it was checked in the vendor source rather than assumed:
`@inertiajs/core` `dist/index.js:2271-2284` fires an `httpException` event for a `>= 400`
response that carries the `x-inertia` header and then **still calls `setPage()`** — so an
Inertia XHR visit renders the refusal page, and a full page load renders the same HTML with
a 403. The drive confirmed the full-load path end to end (status `403`, refusal text on
screen). **The XHR path is proven only by reading that vendor code and by the suite's
`assertInertia` on a 403** — I did not click through from the statement in the browser to
watch the SPA transition specifically, because the drive navigated by URL.

---

## 3. Decision 2 — what replaces `origin` on the wire

`PaymentResource` gains **two** fields and does **not** gain `origin`:

```php
'receiptable' => $this->isReceiptable(),
'receipt_refusal_reason' => $this->receiptRefusalReason(),
```

**Why not `origin`.** `origin` is the provenance axis every collections report and the
general-ledger export turns on. Putting it on the wire invites the client to build
provenance logic — filters, an "imported" badge, a report — and the docblock reserves that
as a separate decision with its own consumer. The client's actual question is narrower:
*may I offer a receipt for this row, and if not, what do I tell the operator?*

**Why this exact shape.** It is the one this codebase already uses for precisely this
situation. `InvoiceSettlement:32-34`: *"request void — refused by a RULE once money has
settled → the flag is false with a `void_blocked_reason`, so the UI disables-with-reason
rather than hides."* `can_request_void` + `void_blocked_reason` is the precedent;
`receiptable` + `receipt_refusal_reason` is the same pattern one aggregate over.

**The caveat, stated rather than glossed.** `receiptable: false` today means exactly
`origin = 'migrated'`, so the same bit is inferable from the flag. What differs is the
**contract, not the current information content**: the flag promises *receiptability*, so
if the predicate ever widens (a reversed payment, say) the flag keeps its meaning where a
leaked `origin` would quietly change what a client believed about provenance. I would not
claim this hides anything today.

**The reason string lives once**, as `Payment::RECEIPT_REFUSAL_REASON`, and both consumers
read it — the controller's refusal branch and the Resource. The page renders the server's
string and holds no copy of the rule, so the statement row's tooltip and the refusal page
cannot drift apart.

**Trade-off I chose, and the alternative.** I could have added no wire fields at all: link
every row to the receipt and let the page state the refusal on arrival. That is simpler and
still satisfies "do not hide the entry point". I added the flag because it lets an operator
working down a list of payments read the rule **at the row**, before clicking, which is the
outcome §26 asks for ("one who reads why learns the rule"). The cost is two more wire
fields. A reviewer who thinks that cost is not worth paying has a real argument and the
change to reverse is small.

---

## 4. The refusal

`PaymentReceiptController::__invoke` branches on `$payment->isReceiptable()` **before** any
document is built, and answers **403** with the reason as a prop.

- **Server-side, and independent of the client.** The `receiptable` flag is a courtesy; this
  branch is the control. (Same posture as the approvals queue's own comment: *"That dialog
  is a courtesy; these middleware, the Policy, the Action's lock and the database are the
  controls."*)
- **The predicate is the `origin` column, not `MIGRATED_REFERENCE_FLOOR`.** The floor is a
  receipt-*numbering* fact — the reserved band a migrated row draws `reference` from. Using
  it as a provenance test would be a heuristic standing where a CHECK-constrained column
  answers exactly, and it would answer **wrongly** the day a school's portal counter is
  seeded into the band, which is the failure the floor's own docblock warns about one column
  away.
- **Allowlist, not denylist.** `isReceiptable()` is `origin === ORIGIN_PORTAL`, not
  `!== ORIGIN_MIGRATED`. Equivalent today (the CHECK admits two values); they differ on the
  day a third arrives, and only the allowlist fails safe.
- **The entry point is not hidden.** The statement's payments tab links **every** row,
  including the ones the route will refuse. `FinanceNavCoverageTest` now asserts that the
  link is not wrapped in `receiptable` (see § 7), so the next reasonable-looking edit
  ("don't offer a receipt we won't issue") fails rather than quietly restoring the hide.

---

## 5. The page

`resources/js/pages/admin/finance/receipt.tsx`, laid out to the design system (canvas
`bg-[#f5f7fb] dark:bg-background`, `rounded-2xl` document card with the standard soft
shadow, indigo/violet icon tile with `ring-1 ring-black/5`, `text-[10px] font-bold uppercase
tracking-wide text-slate-400` labels, `tabular-nums` money, emerald for the received amount).

**Content:** the school (name, address, phone/email), the receipt reference, the date
received *and* the date recorded, the payer, the student the money is on behalf of, the
method, the bank account it was paid into, the amount, and what it paid for.

**§26 applied as I went:**

- **The four states cannot collapse, because there are not four.** No fetch, no
  `loading`/`error` state. The single branch is a *rule* (`refusal`), not a state.
- **Every number comes from the server**, including the *decisions*: `fully_applied`,
  `held_on_account` and `nothing_applied` travel as booleans so the page never compares two
  amounts. `allocated_total` and `unallocated_amount` are computed in PHP in integer minor
  units. `money-lint` is green; it **caught me once** (see § 8).
- **Dark mode is written and was set directly**, per §26's instruction — I toggled
  `documentElement.classList` in the drive; no user can reach the theme switch.
- **Keys read out of the payload are pinned server-side** — `PaymentReceiptTest` asserts the
  prop names and their values, so a rename reds a test rather than silently rendering `—`.

**`method` renders "manual" on every portal receipt, and that is what the data says.**
`finance_payments.method` is a column DEFAULT (`2026_07_19_100002_create_fee_payments_tables.php:36`)
and **no writer ever overrides it** — `grep "'method'"` finds it only in
`PostOpeningBalanceBatch` (`'migrated'`). That is why the receipt also names the **bank
account**: the `finance_payments_bank_account_origin_shape` CHECK makes `bank_account_id`
NOT NULL for every portal payment, so a receipt always has one, and it is the part of "how
was this paid" a parent can actually check. This is an observation about existing data, not
a defect I fixed.

### What printing removes, and what survives

Stated in the file's own `PRINT_STYLES` docblock and asserted in the drive by reading
`getComputedStyle` off the live page under `emulateMediaType('print')`.

**Gone:** the sidebar and its rail/trigger (`[data-slot="sidebar"]` and friends); the
inset's margin, rounding, shadow and `overflow`; the breadcrumb header; **both** toast
layers (`[data-sonner-toaster]` for sonner and `.Toastify` for react-toastify — AppLayout
mounts both at body level); the impersonation banner; and the page's own toolbar (Back, and
the Print button itself).

**Survives:** the document and nothing else — school block, receipt number, both dates,
payer, student, method, bank account, amount, and the whole "what this paid for" section.
**The refusal survives too**, deliberately: printing a blank sheet would be worse than
printing the reason.

**Colour is forced back to light inside `.receipt-document`.** Browsers drop backgrounds
when printing but keep text colour, so a sheet printed from dark mode would otherwise be
`dark:text-white` on white paper — an invisible receipt, exactly §26's class (renders,
returns 200, cannot be used). Measured: the print PDFs generated from light mode and from
dark mode are **byte-identical** (`md5 f4cf2bd1bb555827e63a3b050a10ef31` for both).

**One shared-component edit, named because §26 says to count the blast radius:**
`impersonation-banner.tsx` gains `print:hidden` (one Tailwind class). It sits above every
page's content, so without it the banner lands on every sheet this application prints —
including the two result sheets that already print today. It is not in the ESLint/Prettier
ignore list (that is `components/ui/*`), so the lint step did see it.

**Scope held:** one payment per page. No batch, no date range, no PDF library, no email, no
storage, no parent access.

---

## 6. Tests — `tests/Feature/Finance/PaymentReceiptTest.php`, 9 arms

Every fixture goes through the **real Actions** (`RecordPayment`, `RecordAccountPayment`,
`PostOpeningBalanceBatch`) — no hand-written `origin = 'migrated'` row, because a planted
string would prove the refusal fires on a value the *test* wrote.

```
{"tool":"pest","result":"passed","tests":9,"passed":9,"assertions":102}
```

| Arm | What it pins |
| --- | --- |
| refusal, migrated payment | 403 **and** `receipt === null` **and** the reason is `Payment::RECEIPT_REFUSAL_REASON` |
| refusal, migrated payment **with allocations** | the predicate is `origin`, not "has no allocations" — the migrated row is born account-shaped (spec R6: no invoice, no allocation) and later gains one via credit-forward, done here with the real `GenerateInvoice` |
| invoice-allocated happy path | the allocation renders with the invoice's **display number**, `applied_on_receipt: true`, and all five money/boolean props |
| account-level happy path | `nothing_applied: true`, `held_on_account: true`, `allocations: []`, `unallocated == amount` |
| over-payment through the invoice door | both sentences true at once — `allocated_total` 300_00, `unallocated` 200_00 |
| isolation | another School's payment uuid and a nonexistent one produce **byte-identical** bodies |
| permission floor (positive) | a seat holding `finance.access` and nothing else — no `payment.record` — gets 200 |
| permission floor (negative) | a signed-in seat with no finance ability gets 403 |
| wire fields | `receiptable` + `receipt_refusal_reason` are on the statement feed and `origin` is **not** |

### Watched reds — pasted, one per arm group

**1. `isReceiptable()` → `return true`**

```
"failed","tests":9,"passed":6,"failed":2,"errors":1
 ✗ REFUSES a receipt for a migrated payment…       Expected [403] but received 200.
 ✗ refuses a migrated payment that HAS acquired…   Expected [403] but received 200.
 ✗ carries receiptable + the reason…               Trying to access array offset on null
```

*(the third was an error rather than a clean failure; the arm was tightened afterwards to a
positive assertion on the sorted flag set — see § 8 — and it now fails cleanly.)*

**2. `invoice_number` → `(string) $a->invoice_id`** (the raw PK, which is what a receipt
shows if nobody notices the difference between the key and the printed number)

```
"failed","tests":9,"passed":8,"failed":1
 ✗ renders the allocated invoices for an INVOICE-ALLOCATED payment
   Property [receipt.allocations.0.invoice_number] does not match.
   --- Expected  -'000001'
   +++ Actual    +'2'
```

**A mutation that did NOT red, recorded because it is the one a reader would assume covers
this:** dropping `->with('invoice')` from the allocations query leaves **all nine arms
green** — Eloquent lazy-loads `$a->invoice` per row, so the eager load is a query-count
optimisation and nothing in this file is sensitive to it. That is written into the test
file so it is not mistaken for coverage.

**3. `nothing_applied` → hard-coded `false`**

```
"failed","tests":9,"passed":8,"failed":1
 ✗ says an ACCOUNT-LEVEL payment sits on the account, applied to nothing
   Property [receipt.nothing_applied] — false is not identical to true.
```

Only the account arm moved; the invoice arm stayed green, which is what tells the two doors
apart.

**4. `Payment::resolveRouteBinding` doing `withoutGlobalScopes()->where('uuid', …)`** — the
shape of a well-meant "fix" for a binding that seems not to find things

```
"failed","tests":9,"passed":8,"failed":1
 ✗ gives another School’s payment uuid BYTE-IDENTICAL bytes to a nonexistent one
   Failed asserting that 200 is identical to 404.
```

**5. `->withoutMiddleware('permission:finance.access')` on the route**

```
"failed","tests":9,"passed":8,"failed":1
 ✗ refuses the receipt to a signed-in user with no finance.access at all
   Expected [403] but received 200.
```

**6. `FinanceNavCoverageTest` — the exemption removed**

```
 ✗ every finance page is reachable from the sidebar, or is named here as not a nav destination
   A finance page is registered, permission-gated and reachable from NO menu:
   /finance/payments/{payment}/receipt.
```

**7. `FinanceNavCoverageTest` — the receipt link wrapped in `{payment.receiptable && …}`**

```
 ✗ the receipt exemption really is linked from the statement, on EVERY payment row
   Failed asserting that 2 is identical to 1.
```

All seven mutations were restored and the tree re-verified green (`git status --short` clean
against the commits) before the gate run.

### One assertion I removed rather than kept

The isolation arm originally forced `config(['app.debug' => false])` on the theory that a
debug-mode 404 would render the `ModelNotFoundException` message, which carries the uuid.
**Measured: it does not arise.** `bootstrap/app.php:157` renders every
`NotFoundHttpException` as a fixed `{"message":"Resource not found"}` before the debug
renderer is reached. Removing the line left the arm green, so I deleted it rather than
leave a line sitting there looking load-bearing. The drive reads the same bytes off the
running app (§ 7).

---

## 7. The drive

Per `.claude/skills/finance-drive/SKILL.md`. Throwaway `APP_ENV=drive` instance on `:8001`,
`pnpm install && pnpm run build` first, `finance:seed-drive-fixture`, `queue:work` for the
import job. Browser: puppeteer-core against system Chrome, **installed outside the
repository** (its own `npm install` in a private `mktemp -d`; `node_modules` in the repo was
not touched).

### The fixture count table — and the column this commit added to it

The table could not answer the question this drive asks, so the fixture changed first, in
this commit, argued. `DriveFinanceStates::paymentCount($schoolId, $origin)` and two new
columns. The split by `origin` is the point: a single payments count would have read as
coverage while the **migrated** half — the half that exists because of the WCBS cutover —
still had nothing to render.

Pasted verbatim from the command:

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+
| School | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+
| A (school#1) | 1 | 1 | 2 | 1 | 1 | 3 | 0 |
| B (school#2) | 1 | 1 | 2 | 1 | 1 | 0 | 0 |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+
```

**`Payments (migrated) = 0` is the answer the brief asked me to establish.** Nothing in the
fixture posts an opening-balance batch — `DriveFinanceStates` exposes no opening-balance
state method, and `PostOpeningBalanceBatch` is the only writer of `origin = 'migrated'`. So
the refusal could not be rendered from the seeded fixture.

### So I walked the real import path, in the browser

Rather than claim a refusal I had not seen. Both halves of the cutover, through the real
screens:

```
Seat 1 — maker@drive.test (accounts_officer, school#1)
  TERM options ( 1 ): ["1|2026/2027 — First Term"]
  Validate button: {"text":"Validate extract","disabled":false}
  HTTP 201 POST /api/v1/finance/opening-balance-batches
  HTTP 200 POST /api/v1/finance/opening-balance-batches/a284b2fc-…/submit

Seat 2 — checker@drive.test (executive_director, school#1)
  OPENING-BALANCE row: "OPENING BALANCE Batch · WCBS-DRIVE-1 — Maker Drive — 16/08/2026 -₦750.00 Approve Reject"
  CONFIRMATION MODAL: "Post this cutover? It cannot be undone. … This is irreversible.
   Posted balances cannot be un-posted, deleted or moved to another school, and this school
   may never post a second batch. Cancel | Post opening balances"
  clicked in modal: "Post opening balances"
  HTTP 200 POST /api/v1/finance/opening-balance-batches/a284b2fc-…/approve
```

The row it produced, read back out of the database:

```
payment#4 uuid=a284b445-… ref=900000001 origin=migrated student#4 bank=NULL
```

`reference` in the reserved band (`>= MIGRATED_REFERENCE_FLOOR`), `bank_account_id` NULL as
the origin-keyed CHECK requires. **A real migrated payment, from the real writer.** The
drive spent the school's single posting slot — which is what a throwaway database is for.

### What the screens actually contained

**Portal payment, statement payments tab** (`maker-01/02`, light + dark):

```
PAYMENTS TABLE heads: ["Payer","Reference","Method","Date","Amount","Receipt"]
PAYMENTS ROW: {"cells":["Guardian","#1","manual","16/08/2026"],
               "receiptHref":"/finance/payments/a284b093-046e-45ad-b1f7-4897a1a42e04/receipt",
               "chip":null,"chipTitle":null}
```

**The receipt itself** (`maker-03` light, `maker-04` dark) — the document's full text as
read out of the DOM:

```
Drive School A | PAYMENT RECEIPT | #1 | RECEIVED FROM | Guardian | ON BEHALF OF | Paula Part
| DATE RECEIVED | 16 August 2026 | RECORDED | 16 August 2026, 19:16 | METHOD | manual
| PAID INTO | Drive account · Drive Bank | AMOUNT RECEIVED | ₦1,000.00
| WHAT THIS PAID FOR | INVOICE PERIOD APPLIED
| 000002  Enrollment a284b092-c48e-…  ₦1,000.00
| APPLIED TO INVOICES  ₦1,000.00
| The full amount has been applied to the invoices above. Nothing is held on the account from this payment.
| Issued by Drive School A. This receipt records one payment and is valid without a signature.
```

The arithmetic on screen: the one allocation is `₦1,000.00`, the applied total is
`₦1,000.00`, the amount received is `₦1,000.00`, and `fully_applied` produced the closing
sentence. **Nothing in the page computed any of it** — all three figures and the sentence
selector came from `PaymentReceiptController::document()`.

**The migrated row and its refusal** (`maker-11`, `maker-12` light, `maker-13` dark):

```
PAYMENTS ROW: {"cells":["Balance brought forward (WCBS batch WCBS-DRIVE-1)","#900000001","migrated","16/08/2026"],
   "receiptHref":"/finance/payments/a284b445-…/receipt", "receiptText":"Receipt",
   "chip":"Not issued here",
   "chipTitle":"This payment was collected in the previous system before the cutover and brought
    across as an opening balance. Brookstone never issued a receipt for it from this system, so
    this system will not print one now. The receipt the parent holds is the one the previous
    system issued."}

REFUSAL http status = 403
REFUSAL page text  = No receipt for payment #900000001 | This payment was collected in the previous
   system before the cutover and brought across as an opening balance. Brookstone never issued a
   receipt for it from this system, so this system will not print one now. The receipt the parent
   holds is the one the previous system issued. | Drive School A
receipt document present = true    Print button present = false
```

The link is live on the migrated row (not hidden, not disabled), the chip states the rule in
place, the page states it in full, and the Print button is absent because there is nothing
to print.

**Print rendering** (`maker-05` emulated screenshot, `maker-06`/`07` PDFs, `maker-14` refusal
PDF), computed styles read off the live page under `emulateMediaType('print')`:

```
PRINT computed: {"sidebar":"none","header":"none","toolbar":"none","document":"block",
                 "documentColor":"rgb(15, 23, 42)","bodyBg":"rgb(255, 255, 255)"}
PRINT-from-dark document color: {"color":"rgb(15, 23, 42)"}
PRINT computed on the refusal: {"sidebar":"none","toolbar":"none","document":"block"}
```

and the decisive one:

```
md5 maker-06-receipt-print.pdf            f4cf2bd1bb555827e63a3b050a10ef31
md5 maker-07-receipt-print-from-dark.pdf  f4cf2bd1bb555827e63a3b050a10ef31
```

Byte-identical. One A4 page each.

**Isolation — by uuid, from `school-b@drive.test` (school#2), in its own browser context:**

```
school#1 PORTAL   payment uuid -> 404 "{\"message\":\"Resource not found\"}"
school#1 MIGRATED payment uuid -> 404 "{\"message\":\"Resource not found\"}"
nonexistent       uuid         -> 404 "{\"message\":\"Resource not found\"}"
portal-vs-nonexistent bodies identical   = true
migrated-vs-nonexistent bodies identical = true
```

Checked **by id, not by label** — School B has zero payments of its own (count table above),
so the disjointness here is the uuid set itself. The seat was authenticated: an
unauthenticated request to a `web` route redirects to `/login`, and these returned 404 with
a body.

### One observation the drive produced — pre-existing, not introduced

The receipt's **Period** column renders `Enrollment a284b092-c48e-4425-af46-aab23e799c21`.
That is `invoices.academic_context`, whose fallback is
`'Enrollment '.$enrollment->uuid` when the curriculum has no class-level arm, session or term
wired (`app/Academics/BillableEnrollmentAdapter.php:234-246`). **It is a fixture gap, and it
is pre-existing** — measured, not assumed: the statement's own invoices tab renders the same
string today.

```
STATEMENT invoices tab, first row:
"000002 Enrollment a284b092-c48e-4425-af46-aab23e799c21 Issued Part-paid ₦3,000.00 ₦2,000.00 outstanding …"
```

On a real school it renders `JSS 1 · A · 2026/2027 · First Term`. **A drive observes; it does
not fix** — reported, not touched. If the project lead wants the drive fixture's curricula
wired to their class-level arm and term, that is its own change.

### What was NOT driven

- **`super@drive.test` and `void-checker@drive.test`** — neither seat's distinguishing
  property touches this screen. The receipt has no checker side and no super-admin bypass
  exclusion; it is a plain `finance.access` read.
- **The Inertia XHR path to the 403** (see § 2's caveat) — the drive navigated by URL, so the
  403 was exercised as a full page load. The SPA path is covered by the vendor source and by
  `assertInertia` on a 403 in the suite, not by eye.
- **A real printer.** "Print" was exercised through Chrome's print media emulation and its
  PDF renderer, not through a physical print dialog.
- **A payment with more than one allocation** — the fixture produces one allocation per
  payment, so the multi-row allocation table and its `tfoot` total were rendered with a
  single row. The multi-allocation shape *is* covered in the suite (the over-payment arm
  exercises `allocated ≠ amount`), but no drive screenshot shows two allocation rows.
- **The `receipt_refusal_reason` tooltip on touch.** The chip carries the reason as a `title`
  attribute, which is hover-only. The full reason is on the refusal page one click away, so
  nothing is unreachable — but a touch user does not read it at the row. Noted rather than
  fixed; making it a persistent inline note is a design call.

---

## 8. Deviations & self-inflicted reds

Everything in this section is a departure or a mistake of mine, at the top of the section
rather than in a footnote.

**1. I added two wire fields where zero would have satisfied the brief's letter.** Argued in
§ 3, including the case against.

**2. I changed a shared component** — one Tailwind class (`print:hidden`) on
`impersonation-banner.tsx`. Justified in § 5; it is outside the `components/ui/*` lint-ignore
list, so the gate did see it.

**3. I changed a fixture oracle** — two columns on the drive count table, plus
`DriveFinanceStates::paymentCount()`. In scope per the drive skill ("if the **fixture** cannot
reach the state your brief told you to drive, fixing the fixture is in scope"), and argued in
§ 7.

**4. I edited `docs/handoff/opening-balance-import-spec.md`.** A `> DISCHARGED by U11` block
above §4's paragraph. The paragraph itself is left standing verbatim as the record of what was
owed; the note says what is now built and what remains true in it (no PDF, no email, no export,
no archival). A doc that says an obligation is outstanding when it has been met is the same
class of defect as a comment contradicting the routes under it.

**5. `bin/quality` failed on my first run, on three things, all mine.** Fixed in `d2b95f7`,
not hidden:

- **Larastan, 5 errors.** `Payment::allocations()` carried no generic, so `->get()` read as
  `Collection<int, Model>`; every typed closure over it was `argument.type`, plus
  `method.notFound` on `Invoice::displayNumber()`. Fixed by annotating the relations
  (`allocations()`, `student()`, `bankAccount()`, and `PaymentAllocation::invoice()/payment()`,
  which the same chain resolves through) rather than casting at the call site.
- **`FinanceNavCoverageTest`.** Every finance GET route must be a menu item or a named
  exemption. The receipt is per-payment, so it is the second exemption — with an arm asserting
  what the exemption *claims*, including that the link is unconditional.
- **`PestNegatedExpectationMessagesTest`.** I wrote `->not->toBeNull('…')`; a custom message on
  a negated Pest expectation is silently dropped. Replaced with a positive assertion on the
  sorted flag set, which says more anyway.

**6. `money-lint` caught me during development**, before any commit:

```
money-lint: 1 NEW money-rule violation(s)
  ✗ money-format-outside-formatnaira  resources/js/pages/admin/finance/receipt.tsx  ).toLocaleString()}
```

I had formatted the "Recorded" timestamp with `new Date(...).toLocaleString()`. The ban is
**total** inside the Finance UI, and it is right to be: the fix is that both dates are now
formatted in PHP and travel as display strings, which is the same posture as the money. Worth
noting for the record that the sibling call already in `statement.tsx`
(`.toLocaleDateString(`) does **not** trip the rule — the regex is `/\.toLocaleString\s*\(/`
only. I did not widen the lint; that is a separate decision.

---

## 9. `git diff --stat`, raw

```
 app/Console/Commands/SeedDriveFixture.php          |  15 +-
 app/Finance/Console/DriveFinanceStates.php         |  25 ++
 .../Http/Controllers/PaymentReceiptController.php  | 173 +++++++++
 app/Finance/Http/Resources/PaymentResource.php     |  33 +-
 app/Finance/Models/Payment.php                     |  86 +++++
 app/Finance/Models/PaymentAllocation.php           |   2 +
 .../checker-01-approvals-queue.png                 | Bin 0 -> 101012 bytes
 .../checker-01-approve-dialog.png                  | Bin 0 -> 130519 bytes
 .../checker-02-after-approve.png                   | Bin 0 -> 99550 bytes
 .../isolation-01-school-b-404.png                  | Bin 0 -> 11234 bytes
 .../maker-01-statement-payments-light.png          | Bin 0 -> 99095 bytes
 .../maker-02-statement-payments-dark.png           | Bin 0 -> 91912 bytes
 .../maker-03-receipt-light.png                     | Bin 0 -> 89396 bytes
 .../maker-04-receipt-dark.png                      | Bin 0 -> 87359 bytes
 .../maker-05-receipt-print-emulated.png            | Bin 0 -> 56621 bytes
 .../maker-06-receipt-print.pdf                     | Bin 0 -> 36424 bytes
 .../maker-07-receipt-print-from-dark.pdf           | Bin 0 -> 36424 bytes
 .../maker-08-ob-import-form.png                    | Bin 0 -> 147145 bytes
 .../maker-09-ob-validated.png                      | Bin 0 -> 185078 bytes
 .../maker-10-ob-submitted.png                      | Bin 0 -> 221614 bytes
 .../maker-11-statement-migrated-row.png            | Bin 0 -> 107793 bytes
 .../maker-12-refusal-light.png                     | Bin 0 -> 67166 bytes
 .../maker-13-refusal-dark.png                      | Bin 0 -> 65017 bytes
 .../maker-14-refusal-print.pdf                     | Bin 0 -> 13111 bytes
 docs/handoff/opening-balance-import-spec.md        |  11 +
 resources/js/components/impersonation-banner.tsx   |   5 +-
 resources/js/pages/admin/finance/receipt.tsx       | 411 ++++++++++++++++++++
 resources/js/pages/admin/finance/statement.tsx     |  52 ++-
 resources/js/types/finance.ts                      |   6 +
 routes/web.php                                     |  17 +
 tests/Feature/Finance/FinanceNavCoverageTest.php   |  46 ++-
 tests/Feature/Finance/PaymentReceiptTest.php       | 417 +++++++++++++++++++++
 32 files changed, 1282 insertions(+), 17 deletions(-)
```

(Drive screenshots are 18 of those 32 files. The code change is 14 files.)

---

## 10. `bin/quality`, raw

**15 steps** — re-derived from this run's own `[n/15]` output, not carried.

```
quality gate — base 938065d

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 9 changed PHP file(s)
       Prettier (check) on 4 changed file(s)
       ESLint on 4 changed file(s)
[4/15] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/15] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/15] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/15] boundary lint (§17.2)
   ✓ boundary-lint
[8/15] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/15] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/15] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/15] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/15] sql-clock lint (no MySQL clock functions in raw SQL — two frames, one table)
   ✓ sql-clock-lint
[13/15] architecture tests (§17.1)
   ✓ arch
[14/15] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

### Which steps read this change's files

- **3 (lint-changed)** — Pint on 9 PHP files, Prettier + ESLint on 4 TS files. It diffs
  `BASE...HEAD`, so it saw these files **because they are committed**; it is blind to a dirty
  tree.
- **4 (tsc ratchet)** — compiled `receipt.tsx`, `statement.tsx`, `finance.ts`,
  `impersonation-banner.tsx`. Held at baseline **42**, unchanged; my files contribute zero
  errors (`grep` on the run's tsc output for `receipt|statement|impersonation|types/finance`
  returned nothing).
- **5 (vite build)** — bundled the new page. This is the step the ratchet structurally cannot
  stand in for.
- **7 (boundary lint)** — the new controller and `DriveFinanceStates::paymentCount()` both live
  inside `app/Finance/`, which is why the count-table column had to be added to the Finance side
  rather than to the command.
- **9 (money lint)** — scanned `receipt.tsx` under the **total** Finance-UI ban. Green now;
  it failed on this file earlier (§ 8).
- **14 (Larastan)** — the step that failed first and now passes at 0 errors.
- **15 (test ratchet)** — ran the whole suite including the 9 new arms and the 2 new/changed
  nav-coverage arms.

### What this gate cannot see here

Beyond `CLAUDE.md`'s four standing residuals (PHP version matrix, clean-room OS/env, remote
enforcement, intent) and the determinism residual:

- **Whether the receipt renders, what it says, or whether the print output is usable.**
  Nothing in `bin/quality` opens a browser. That is what § 7 is for, and it is the
  verification rather than a courtesy.
- **The markdown.** It reads none — not this report, not the spec edit.
- **The drive fixture.** `SeedDriveFixture` refuses outside `APP_ENV=drive`
  (`:49-54`) and `phpunit.xml:29` pins the suite to `APP_ENV=testing`, so **no test in the
  suite can execute the count table I changed.** Its only proof is the pasted output in § 7.
- **The Inertia 403 on the XHR path**, per § 2's caveat.

---

## 11. Explicitly not done

No PDF library. No email — `NotificationType::PAYMENT_RECEIVED` still has no dispatcher and
this commit does not write one. No storage or archival of generated receipts. No
parent-facing access. No batch or date-range printing. No new endpoint over
`finance_payments`. No change to how a payment is recorded, allocated or numbered.
