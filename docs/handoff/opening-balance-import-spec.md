# Opening balance import — specification

**Cutover mode** ~~MID-TERM, RE-BILL~~ → **TERM BOUNDARY, BALANCE FORWARD** (R5, project lead,
2026-08-07). The struck value is left visible because three revisions of this document argued from
it.
**Author** advisor · **Status** specification, agreed inputs, not yet built
**Consumes** §4.1 S9 / S11, §4.2 V11, §4.3 U12b of `docs/handoff/finance-mvp-cut-brief.md`
**Rev 2** — 2026-08-06. Rev 1 posted prior arrears as an opening *invoice*. That is unbuildable:
`finance_invoices` carries `UNIQUE (school_id, active_enrollment_key)` where
`active_enrollment_key = IF(status='issued', student_curriculum_id, NULL)`
(`2026_07_19_120000_slice2_invoice_total_immutable_and_active_enrollment_guard.php:76-81`),
and `student_curricula` is per-term (`curricula.term` is 1|2|3), so an opening invoice on the
cutover episode would occupy the slot the re-bill needs and the bulk job would fail on every
student who has arrears. §3 now posts arrears as a ledger charge. §4, §7 and §10 follow.

**Rev 3** — 2026-08-06. **The project lead has ruled on every question §6 and §7 left open.** These
are decisions, not inferences, and they are recorded here as decided rather than as things a reader
should weigh:

- **R1 — WCBS can cleanly split its extract by term.** Every WCBS financial transaction carries an
  academic term identifier. §1's identity is evaluable per term, and §6.2 is closed.
- **R2 — all four money columns will be extracted**: `prior_arrears`, `wcbs_billed_total`,
  `paid_to_date`, `wcbs_total_balance`. §1's checksum stands exactly as written. §2 carries no
  unconfirmed column.
- **R3 — there is no live reversal instrument, and there will not be one.** A wrong imported balance
  found before go-live is corrected by **restoring the database** and re-running a corrected batch.
  §7's last row and §11 say what that costs and when it expires.

Rev 3 also adds two guards to the posting commit's scope — **G1** (at most one posted batch per
school, in the database) and **G2** (posting refuses once a payment is attributed against imported
arrears). Their shapes are specified in §9 and they are what make R3 honest. Neither is built by the
staging commit.
*(Rev 4 correction: G1's claim was overstated — see §9's G1b and §11. G2 was demoted out of §9
entirely; it detected the wrong event. This paragraph is left as the record of what Rev 3 asserted.)*

One correction of fact carried in from the implementation, since two sections argued from it: **`students.admission_number` has been NOT NULL since
`2026_07_18_100000_make_identifier_columns_not_null.php:36`.** It was nullable when Rev 1 was
written. §2 and §6.1 are corrected below; the duplicate-after-trim half of that pre-flight is
unaffected and still bites.

**Rev 4** — 2026-08-07. **R5 supersedes R2, and supersedes the MID-TERM RE-BILL cutover mode ruled
on 2026-08-06.** Both are withdrawn as rulings of record; the text they produced is struck through
or replaced below rather than deleted, because Revs 1–3 argued from them and a reader arriving at a
struck sentence needs to see what it was.

- **R5 — TERM BOUNDARY, BALANCE FORWARD (ruling of record).** The cutover happens **on a term
  boundary**. The data team supplies, per student, the **final opening balance broken down per fee
  type**. Those lines post. The cutover is then complete: everything after date **D** is native to
  the portal.
  **Dropped entirely: the mid-term re-bill, and every requirement that depended on it** — §1's
  four-column identity, §2's `prior_arrears` / `wcbs_billed_total` / `paid_to_date` /
  `wcbs_total_balance` columns, §5's fee-schedule comparison, and the "import before generation or
  every student is billed the full term" ordering rule of §3.
- **R6 — the instrument is a LEDGER CHARGE, not an invoice** (§3). Three independent reasons, each
  sufficient on its own.
- **R7 — the fee-type label is a STRING, carried verbatim; it is never resolved to a
  `fee_item_id`** (§3).
- **R8 — sign decides the instrument.** Positive per fee type → one ledger charge each. Negative →
  netted into ONE migrated payment for the student, not one per fee type (§3).
- **R9 — the staging key is wrong for a per-fee-type file** and changes in commit 4 (§9).
- **R10 — §1's identity is dead** and is replaced by a two-level checksum (§1). §5 is **withdrawn**
  as a consequence (§5).
- **R4 — the export ruling** (§12), unchanged from when it was first given: the portal exports what
  it ORIGINATED, never what it INHERITED.

Rev 4 also corrects two things Rev 3 asserted and could not support: **G1 was false as written**
(§11) and **G2 detected the wrong thing** (§11). Both are fixed below with the weaker, true claim.

**Rev 5** — 2026-08-07. **R11 CONFIRMS Rev 4; it supersedes nothing.** Everything below was already
ruled in Rev 4 and derived from the schema. What is new is that **Brookstone has now stated the same
shape independently, in their own words** — and confirmation from the party whose money it is carries
a different weight from our derivation. A design we reasoned our way to and a design the client
arrived at separately are not the same artifact, even when the text matches.

- **R11 — 2026-08-07, confirmed by Brookstone via the project lead.** They export closing balances
  strictly **as at the end of the last term**, and the portal bills the new term natively. Point by
  point, against what Rev 4 already says:

  | Brookstone's words | Confirms |
  |---|---|
  | *"Pure arrears, zero new-term fees included"* | **R5** — and it adds §11's new precondition, below |
  | *"Route positives to ledger charges with per-fee-type narrations, to avoid consuming active episode slots or hitting the active-invoice unique constraint"* | **R6**, reasoning and all — they reached the episode-slot and unique-constraint arguments independently |
  | *"Net negatives per student into a single migrated payment at ACCOUNT level so `applyCreditForward` settles their new-term invoices"* | **R8** |
  | *"Keep a single batch control total to protect the checksum"* | **R10's L2** |

**R11 adds exactly one thing Rev 4 does not have, and it is not a rule — it is a precondition
nothing can enforce.** "Pure arrears" is a claim about what a number MEANS, and §11's procedural half
is where it goes. See §11.

~~R11 is also silent on `student_total_balance`, which §2 requires and L1 depends on; §12 records
that as an open item with the data team.~~ **Superseded within the same revision by R12, below.**
R11's silence stopped mattering the moment the format was frozen: we are reading it neither as
agreement nor as an open question, because it is no longer a question we are asking. The layout is
dictated and Brookstone is handed it as non-negotiable.

- **R12 — 2026-08-07: THE FILE FORMAT IS FROZEN, AND WE DICTATE IT.** We are no longer waiting on the
  data team to propose a layout. **§2's table is the authority**, and this is it:

  | Column | | |
  |---|---|---|
  | `admission_number` | required | |
  | `wcbs_student_ref` | required | |
  | `fee_type_label` | required | |
  | `balance` | required | **SIGNED** — positive owed, negative credit |
  | `student_total_balance` | required | repeated **IDENTICALLY** on every row of that student's group |
  | `wcbs_bill_reference` | **OPTIONAL** | may be blank |

  Header row, verbatim:

  ```
  admission_number,wcbs_student_ref,fee_type_label,balance,student_total_balance,wcbs_bill_reference
  ```

  This closes Rev 4's worry that `student_total_balance` was unconfirmed. It is not confirmed by the
  data team — **it is required by us**, which is a stronger position and the one R13 makes
  enforceable.

- **R13 — 2026-08-07: THE PLATFORM ISSUES THE TEMPLATE.** Brookstone **downloads it from the
  portal**; we do not email a spreadsheet and hope it survives being opened, re-saved and forwarded.

  This follows the **guardian import** precedent exactly, and that precedent's own docblock states
  the reason better than a new argument would:
  *"the COLUMNS map drives both the template generator and the row validator, so they cannot drift
  apart"* (`app/Services/Validators/GuardianImportRowValidator.php:15-19`). A hand-authored template
  is a **second source of truth** for the format — and a second source of truth for a **money**
  format is how a data team ends up holding two different files, each of which looks right.

  §2 records the shape and the commit split.

---

## 0. ~~What was decided, and one thing I got wrong~~ — SUPERSEDED BY R5

> **This whole section is superseded by R5 (2026-08-07).** It describes the mid-term re-bill and the
> import-before-generation ordering that R5 withdraws. It is kept, not deleted, because §3's Rev 2
> reasoning is the clearest record of *why* an opening invoice is unavailable, and R6 reuses that
> reasoning for a different conclusion. Read it as history. **Nothing in it is a requirement.**

Brookstone goes live **partway through a term whose bills have already gone out** from WCBS. The portal **re-issues** that term's invoice with its full fee lines, because the parent screens are only worth building if there is something itemised behind them.

**I owe a correction on the mechanism.** I refused item 3 as stated — "the difference between the invoice and the imported balance becomes a payment" — and the refusal was right *for the shape as written*, because that amount is **derived from a later event**: bill first, then compute a payment from the gap. Re-price or credit-note the invoice afterwards and the ledger holds an immutable payment that is now wrong.

With two columns and the order reversed, the objection dissolves. **Paid-to-Date is a reported historical fact, not a derivation** — the parent really did pay ₦60,000, on a real date, through a real channel, and WCBS recorded it. Posting that as a payment is not fabrication; it is the truth arriving late. What made the original shape unsound was the *ordering*, not the instrument.

So: **import first, bill second.** The payment exists before the invoice does, and nothing is computed backwards.

This also means the module needs **no new settlement machinery at all**, which is the best outcome available here. `RecordAccountPayment`'s docblock names `GenerateInvoice::applyCreditForward` as *"the SOLE allocator of unnamed money"*, settling oldest-first at the next generation. An imported payment banked as unallocated account credit is exactly the input that path already consumes. `InvoiceSettlement` is untouched — `outstanding = total − Σ(allocations) − Σ(approved credit notes)` keeps working because a real allocation is what ends up there.

---

## 1. The checksum — two levels, both stated in the file

> **R10 (2026-08-07): the four-column identity is DEAD.** The Rev 1–3 rule was
> `prior_arrears + wcbs_billed_total − paid_to_date == wcbs_total_balance`. A balance-forward file
> carries none of those four inputs, so the identity has nothing to evaluate. It is removed rather
> than kept as decoration — a checksum that cannot fail is worse than no checksum, because a green
> reads as a check that ran.

Let **D** be the cutover date. There is no cutover term **T** any more: R5 puts the cutover on a term
boundary, so the file is a closing position, not a mid-term snapshot.

The replacement checks at **two levels**, which the old one did not:

```
L1   Σ(per-fee-type balance for a student)  ==  that student's STATED total balance
L2   Σ(student stated totals)               ==  the batch's STATED control total
```

**Both stated figures MUST be present in the file, and that is a file-format requirement, not a
nice-to-have** (§2). This is the load-bearing part of R10 and the reason it checks at two levels: a
file carrying only the per-fee-type lines gives nothing to check the lines against, and the checksum
degrades to *"the lines sum to themselves"* — an assertion that is true of any arithmetic whatsoever
and therefore detects nothing. The stated total is the independent witness. Without it the import
cannot tell a complete extract from one that lost a fee type on the way out of WCBS.

**The two levels check DIFFERENT FAILURES, and R12/§12 is what makes that true.** Since the control
total is typed by the operator off WCBS's own report rather than carried in the file:

- **L1 is a FILE INTEGRITY check.** Both of its figures come from the same file, so what it catches
  is a row lost *between export and upload* — an Excel edit, a truncated save, a copy-paste that took
  four of a student's five lines. It cannot see anything that was already wrong when WCBS wrote the
  file.
- **L2 is a COMPLETENESS AGAINST WCBS check.** Its witness travelled a different path — a human read
  it off WCBS and typed it — so it catches what L1 structurally cannot: a student, or a whole page of
  students, missing from the export itself.

**Neither replaces the other**, and a green on one says nothing about the other.

- **L1 fails** → the student's row-group is rejected. Both sides go in the finding, as the old
  identity did.
- **L2 fails** → a finding on the **batch**, not on any row: the lines may each be internally
  consistent and the file still be missing a student.

Neither is corrected. Reject, never coerce — unchanged from Rev 1 and the one rule that survives it.

---

## 2. File format

UTF-8 CSV, header row required. All amounts in **naira with two decimal places** (`120000.00`),
parsed to integer kobo at the boundary by integer string arithmetic and never touched as a float
thereafter.

**ONE ROW PER (STUDENT × FEE TYPE)**, not one row per student. That is the shape change R5 forces and
it is the reason §9 records a staging-key migration (R9).

| Column | Required | Notes |
|---|---|---|
| `admission_number` | yes | The join key. `students.admission_number` is unique per School and **NOT NULL** (`2026_07_18_100000_make_identifier_columns_not_null.php:36`). Duplicate-after-trim is still possible and is still §6.1's pre-flight. |
| `wcbs_student_ref` | yes | WCBS's own id, stored for traceability. Never used to join. |
| `fee_type_label` | yes | The fee type as WCBS names it. **Carried VERBATIM** into the ledger narration and stored on the staged row. Never resolved to a `fee_item_id` — R7, and §3 says why. |
| `balance` | yes | That fee type's final opening balance for that student. **Signed**: positive is owed, negative is credit. R8 decides the instrument from this sign. |
| `student_total_balance` | yes | The student's STATED total, repeated on each of that student's rows. **L1's independent witness** (§1) — without it the checksum degrades to "the lines sum to themselves". |
| `wcbs_bill_reference` | no | The reference on the last paper bill, if WCBS carries one. Lands in the narration snapshot; it no longer lands on an invoice, because no invoice is written (R6). |

And **one batch-level figure, which is NOT in the file** — it is **typed by the operator at upload**
(R12/§12, decided). That is the whole point of it; see §12 for why a total carried inside the file
would not be a witness at all:

| Figure | Required | Notes |
|---|---|---|
| `batch_control_total` | yes | Σ of every student's stated total, **read off WCBS's own report and entered at upload** — `--control-total=` on the command, a field on U12b. **L2's independent witness** (§1). |

**Blank ≠ zero. A blank in any required column rejects the row.** Unchanged and still the rule that
matters most: a zero is a claim that nothing is owed, and only the file may make it.

> **~~R2 (2026-08-06): all four money columns will be extracted.~~ WITHDRAWN by R5.** The columns R2
> committed to — `prior_arrears`, `wcbs_billed_total`, `paid_to_date`, `wcbs_total_balance` — do not
> exist in a balance-forward extract. R2 is not "still true but unused"; it named a file that will
> never be produced.

**What no longer appears anywhere, and must not be re-added by habit:** a per-term split, a
"what WCBS billed" figure, and a "what WCBS received" figure. R5 makes the file a **closing
position**. Every payment WCBS ever took is already netted into `balance`; re-importing the receipts
that produced it would double-count against WCBS's own general ledger, which is exactly what R4 (§12)
exists to prevent.

### The template is generated, not authored (R13)

Brookstone downloads the template from the portal. Nobody hand-authors a spreadsheet and emails it,
because that is a **second source of truth for the format**, and the guardian import already learned
this: *"the COLUMNS map drives both the template generator and the row validator, so they cannot
drift apart"* (`app/Services/Validators/GuardianImportRowValidator.php:15-19`).

#### Commit 4 — the map (validator scope)

`REQUIRED_COLUMNS` (`app/Finance/Console/ImportOpeningBalances.php:66-74`) is a **flat list of
strings**. No required flag, no format, no example, no notes — so it cannot render a template, and a
template built beside it would be the second source of truth R13 exists to refuse. Replace it with a
**`COLUMNS` map in the guardian shape**: keyed by column name, each entry carrying `required`,
`format`, `example`, `notes`, `group`. It has its consumer in the same commit — the validator reads
it — so it is not front-loading.

Three further diffs from what ships today, each of them a real behaviour change and not a
re-organisation:

- **`wcbs_bill_reference` moves REQUIRED → OPTIONAL.** It is required today
  (`ImportOpeningBalances.php:66-74`) and R12 makes it optional. **A blank must NOT reject the row.**
- **`NON_NEGATIVE_COLUMNS` (`:77`) RETIRES ENTIRELY.** Its three columns no longer exist, and
  `balance` is **signed by design** — a non-negative rule pointed at it would reject every credit in
  the file, which is to say every student who is owed money.
- **The map's `notes` carry the operator-facing rules**, because `notes` is the column the data team
  actually reads. A rule that lives only in this document is a rule the person filling in the
  spreadsheet never sees.

#### U12b — the export and the route (step 5 scope)

An export rendering that map, and a `GET .../import/template` route behind an ability check — the
same shape as `GuardianImportController@template` (`routes/endpoints/guardian.php:16`).

**The gate is §8's maker ability, not a new one.** §8 makes the import maker–checker "the same shape
as the other four request types", so commit 4 adds the triple
`finance.opening-balance.submit` / `.approve` / `.reject` by that convention. **The template route
uses the SUBMIT (maker) ability**: the person who downloads the template is the person who will
upload the file. Note honestly that none of the three exists in `app/Enums/Permission.php` today —
they arrive with commit 4's approval gate — so the route coins nothing; it reuses the maker half of a
triple §8 already requires. If commit 4 names that triple differently, **the route follows the
triple**, not the other way round.

**THREE SHEETS.** The third is a departure from the guardian template and the code must say why:

| Sheet | Contents |
|---|---|
| **Import** | Headings + **SAMPLE ROWS, plural.** The guardian template emits a single sample row, which structurally cannot demonstrate this format's central rule: `student_total_balance` **repeats identically across a student's rows**. Emit at least **two rows for one student**, plus a **second student carrying a NEGATIVE balance**. The most likely mistake must be the one the sample shows — a one-row sample teaches the reader that one row per student is the shape, which is the exact error R9's key exists to refuse. |
| **Columns** | One row per column: Column, Group, Required, Format, Example, Notes. Exactly as guardian's (`app/Exports/GuardianImportTemplateExport.php:73-76`). |
| **Notes** | The rules that are **not per-column** and therefore have nowhere to live in the table: pure arrears / no new-term fees (§11); blank is not zero — write `0.00`; the control total is **entered at upload and is not in the file** (§12); one file per school. These are the rules behind the **expensive** failures, which is precisely why they must not be the ones with no home. |

---

## 3. What gets written, per student

Per student, inside one transaction. **The sign of each line decides its instrument (R8).**

### Step 1 — every POSITIVE fee-type balance → ONE ledger charge each

One `finance_ledger_transactions` row **per fee type**, posted through `SubledgerPoster::post`:

```
type         = charge
amount_minor = that fee type's positive opening balance (positive; a charge is a debit)
narration    = "<fee_type_label verbatim from the file> — Balance Brought Forward"
source_type  = 'opening_balance_row'
source_id    = the staged finance_opening_balance_rows.id that produced it
```

`finance_ledger_transactions` carries `narration` as a **string snapshot**
(`2026_07_19_100001_create_fee_ledger_transactions_table.php:44`) and `source_type` / `source_id` as
a **soft reference with no FK** (`:41-42`). So the statement renders the per-fee-type starting lines
the directive asks for — with **no invoice number burnt, no episode slot consumed, and no term-1
collision in any sequence**, before rollover or after. The rows are append-only at the engine
(`:51-60`; the DELETE-deny trigger is `:56-60`), so a posted opening charge cannot afterwards be
edited or deleted.

The account projection moves by the same delta, because `SubledgerPoster::post` maintains
`finance_student_accounts.balance_minor` at the single writer.

### R6 — why a ledger charge and NOT an invoice. Three reasons, each sufficient alone

The directive asks for *"the imported balance as a starting invoice line or credit line per fee
type"*. The **per-fee-type starting line is right**. The **invoice** is not available:

**1 — the import cannot choose the episode.** `BillableEnrollmentProvider` publishes exactly two ways
in: `findByUuid` (`app/Finance/Contracts/BillableEnrollmentProvider.php:20`) and `currentForStudent`
(`:30`). The file carries **admission numbers, not enrollment uuids**, so the import can only use
`currentForStudent` — and the adapter resolves that as `status = ACTIVE`, `latest('id')`
(`app/Academics/BillableEnrollmentAdapter.php:54-64`). An opening invoice would therefore land on
whichever episode happens to be active **at import time**, which the import does not choose and
cannot verify.

**2 — if the portal has already rolled over, that episode is the one native billing must use.**
`GenerateInvoice::assertNoActiveInvoice` (`app/Finance/Actions/GenerateInvoice.php:431-444`, called
at `:195`) and the database's `UNIQUE (school_id, active_enrollment_key)` would then refuse the first
native term for **every student in the file**. Correctness would depend on the import running before
rollover — an unenforced operational ordering, which is exactly the class §11 quarantines as
procedural. **A guard of the form `enrollment.termId !== batch.term_id` cannot close it**, because
`BillableEnrollment::termId` is **nullable by schema** and the contract says so and says why
(`app/Finance/Contracts/BillableEnrollment.php:39-47`): `curricula.term_id`,
`curricula.class_level_arm_id` and `class_level_arms.class_level_id` are every one of them nullable
columns. A guard that reads null cannot distinguish "different term" from "no term".

**3 — R4 forbids it independently.** An issued invoice is a document the portal **originates**. WCBS
already issued that bill; it is on WCBS's books. Re-issuing it in the portal is the same double-count
R4's export predicate exists to prevent (§12).

Reason 3 stands even if 1 and 2 were both solved, which is why the ledger charge is the instrument
rather than a workaround for a scheduling problem.

### R7 — the fee-type label is a STRING, never a `fee_item_id`

`finance_fee_items` are **children of a fee schedule** keyed to (term, class level)
(`2026_07_26_130001_create_finance_fee_items.php:35`) and frozen by three parent-state triggers
(created at `:54`, `:65` and `:76`). The opening balance comes from the **closed** term, whose
schedule the portal may not hold at all. Resolving a WCBS label onto a `fee_item_id` would bind an
inherited balance to a priced catalog row it was never priced from, and would fail outright wherever
the old term's schedule is absent.

**Nothing is lost by not doing it.** `finance_invoice_lines.fee_item_id` is documented in that same
migration as *"nullable LOOKUP provenance since the create migration, **null in every row ever
written**"* (`:9-10`). The label goes verbatim into the narration, and onto the staged row, so the
mapping stays auditable.

### Step 2 — every NEGATIVE balance → ONE netted migrated payment for the student

**Not per fee type.** Every negative line for that student nets into a **single** payment row and its
ledger `payment` credit.

**Why netted.** A payment belongs to the **student account**, not to a fee type or an invoice — the
payments migration's own header says exactly that: *"A payment belongs to the STUDENT ACCOUNT (school
+ student), not to an invoice"* (`2026_07_19_100002_create_fee_payments_tables.php:11`). Writing one
payment per fee type would assert a fee-type attribution the table cannot hold. The per-fee-type
breakdown of the credit lives on the **staged rows**, which is where it is auditable.

**Why a payment ROW and not a bare ledger credit — this is the load-bearing proof.** Verified against
these four lines, not assumed:

| Line | What it establishes |
|---|---|
| `GenerateInvoice.php:193` | carry-forward credit is read from the **account balance** |
| `GenerateInvoice.php:386-396` | but the **allocation** is sourced from `Payment` rows, `orderBy('id')` |
| `GenerateInvoice.php:398-421` | each payment's **unallocated remainder** is drawn in turn |
| `GenerateInvoice.php:425-428` | the code's own admission that a balance-only credit leaves the leftover **unallocated** |

A bare ledger credit nets the balance correctly and leaves the next invoice reading **fully
outstanding** — right total, wrong statement. A migrated payment row is inserted before any portal
payment exists, so it sorts first under `orderBy('id')` and is drawn first. **The directive is
correct on the code; that was checked against these lines rather than taken on trust.**

Constraints the migrated payment row must respect, all of them schema facts:

- **`reference` is the receipt sequence** under `UNIQUE (school_id, reference)`
  (`2026_07_19_100002_create_fee_payments_tables.php:30`, `:41`). It **must** draw from §4's reserved
  migrated band and **must never** call the live receipt sequence.
- **`origin = 'migrated'`, `method = 'migrated'`** — both ship in commit 4.
- **There is no received/effective date column on `finance_payments`**; the table has only
  `timestamps()`. The cutover date **D** lives on the batch
  (`2026_08_06_100000_create_finance_opening_balance_tables.php:92` `cutover_date`) and the payment
  inherits it **by provenance**. **Do not add a date column in this commit.** Whether §3 needs D on
  the payment surface at all is an open decision, recorded in §12.

### Step 3 — nothing else

No invoice. No re-bill. **The cutover is complete once these post**: everything after **D** is native
to the portal, which is R5's whole point. The `import before generation` ordering rule of Rev 2 is
withdrawn with the re-bill it protected.

---

## 4. Provenance — what keeps this honest

An imported payment is real, but it did **not** arrive through this system, and every surface that sums money must be able to tell the difference by one predicate. Without that, term-end reconciliation compares portal payments against the bank and is short by the entire import.

New, and all of it must exist **before** the first imported row:

**The table is `finance_payments`, not `fee_payments`** — and `finance_invoices`, not `fee_invoices`.
All five were renamed by `2026_07_19_110000_rename_fee_tables_to_finance.php`; the `fee_*` names below
were dead when this section was written and are corrected here. The create migrations still carry the
old names in their **filenames**, so a citation like `2026_07_19_100002_create_fee_payments_tables.php`
is still right — the *table* name in it is not.

| Where | Field | Why |
|---|---|---|
| `finance_payments` | `origin` — `portal` \| `migrated`, NOT NULL, default `portal`, **DB CHECK** | The single predicate every collections report and every GL export filters on. Retro-marking is impossible; a row written without it is permanently ambiguous. **Shipped** — `2026_08_07_110000_add_provenance_to_finance_payments.php`. |
| `finance_payments` | `external_reference` (nullable string) | The WCBS receipt reference. **Shipped** in the same migration — with **no index and no uniqueness**, see the open decision below. |
| `finance_payments` | `method` value `migrated` | A snapshot of the channel. See the limit below: this is not a constraint and cannot be made into one in this commit. |
| `finance_payments` | ~~received date (S2)~~ | **Corrected in Rev 4: there is no received/effective date column on `finance_payments` — the table has only `timestamps()`.** D lives on the batch (`2026_08_06_100000:92`) and the payment inherits it by provenance. Whether one is needed at all is an open decision in §12; it is not added silently. |
| `finance_payments` | ~~`bank_account_id` (S6) stays **null**~~ | **Corrected: the column DOES NOT EXIST.** No table carries it and no code reads one; S6 has not happened. There is no null to explain. It is not added here — a column ahead of its writer is front-loading, and S6 will add it with the code that populates it. |
| ~~`fee_invoices`~~ | ~~`external_reference`~~ | **WITHDRAWN by R5/R6.** It existed for the re-billed T invoice, and no invoice is written any more. The opening charge needs no column — its narration is already a snapshot carrying the label and the WCBS bill reference. |

**`origin` CAN NO LONGER BE DESCOPED — R4 (§12) made it structural.** In Rev 3 it was the predicate
behind G2; that guard is demoted in §11, but `origin` is now the predicate that keeps the **general
ledger** from double-counting the cutover. Dropping or deferring it does not weaken a guard, it
breaks the export boundary. §9 records this as a hard dependency of commit 3.

**And the ORDERING is load-bearing on its own — not merely a consequence of §9's build order.** The
objection to expect, because it has already been raised once: `NOT NULL DEFAULT 'portal'` back-fills
every pre-existing row whenever the ALTER runs, so surely the column could be added after the import.
It could not. The DEFAULT retro-marks **uniformly, not correctly**, and the two coincide only while no
migrated row exists — which is exactly the precondition this ordering enforces, not an independent
property of the default. Import first, add the column second, and every migrated payment is silently
stamped `'portal'`, joins the WCBS collections and GL export, and double-counts the cutover: the precise
failure R4 exists to prevent. No later ALTER repairs it, because the rows no longer carry the
distinction it would have to mark — **you cannot correctly retro-mark a distinction the data has already
lost.** `origin` is not a label applied to rows; it is a fact captured at write time. §9's build order
is a *second* reason, not a substitute.

**The CHECK is what makes `origin` a rule rather than a convention.** `CHECK (origin COLLATE
utf8mb4_bin IN ('portal','migrated'))`. §11's G1 finding was that a status column with no CHECK is
releasable by one UPDATE, and `origin` carries more weight than a status because an export decides on
it. `COLLATE utf8mb4_bin` is load-bearing for the same reason it is in
`2026_08_01_120000_add_currency_shape_checks.php:24` — under the default case-insensitive collation
`'Migrated'` would insert *and* would match every `origin = 'migrated'` filter. **On this table the
CHECK's live door is INSERT**: `finance_payments` is append-only, so `finance_payments_no_update`
(SIGNAL 45000, driver 1644) refuses an UPDATE ahead of any CHECK evaluation. Both doors are shut at
the database; only the codes differ. Proved in `tests/Feature/Finance/PaymentProvenanceTest.php`.

**LIMIT — `method` is an unconstrained string, and "adding a value" to it enforces nothing.** There is
no payment-method enum in this project (`ls app/Finance/Enums/` — fifteen enums, none of them a
method), and `finance_payments.method` is a plain `string` with default `'manual'`
(`2026_07_19_100002_create_fee_payments_tables.php:36`). So `method = 'migrated'` is a **snapshot
label**, not a predicate: nothing refuses a fourth value, and nothing will until an enum plus a CHECK
ships. **No enum is invented here** — introducing one would touch every existing payment write path,
which is a different change from this one. The division is therefore explicit: **`origin` is the
predicate and it has a constraint; `method` is a description and it has none; every code path that
needs to know where the money came from filters on `origin`.**

**Receipt numbers.** `finance_payments.reference` is a school-scoped sequence under
`unique(school_id, reference)`. Imported payments must not interleave with portal-issued receipts, so
migrated rows take references from a **reserved high band** (≥ 900,000,000,
`App\Finance\Models\Payment::MIGRATED_REFERENCE_FLOOR`) and the live `Sequences` counter is untouched.

**THE SEED TRAP — the band's safety rests on an absent argument, and the codebase's dominant pattern is
the one that breaks it.** `Sequences::next()` takes an optional third argument, a seed closure used on
first use to adopt the existing domain maximum. Both payment call sites — `RecordPayment::handle` and
`RecordAccountPayment::handle` — **pass no seed**, so a school's counter starts at 0 and portal receipts
begin at 1 no matter what is sitting in the reserved band. `HasAdmissionNumber` and `HasStaffNumber`
**do** pass one, correctly for them. Anyone "hardening" the payment sequence to match those two makes
the counter adopt `900,000,001` on the first payment after an import, and every subsequent portal
receipt for that school is issued inside the reserved band — permanently, because the table is
append-only and nothing can be renumbered.

**The two Actions share ONE counter** — same scope `finance_payment`, same key (the school id), stated
in `RecordAccountPayment`'s own comment as "one receipt series per school across both doors". A seed is
evaluated on **first use only**, so whichever door a school happens to use first after its import is the
one that creates the counter row, and seeding **either Action alone** corrupts the band through both.
Both doors are therefore pinned separately, one seed case each, and a red was watched for each. A
**third** call site on this scope — the commit-4 posting Action, if it allocates through `Sequences`
rather than computing references in the band directly — would be pinned by neither and must arrive with
its own case.

*Can it be enforced?* **No — only asserted.** The invariant is the absence of an optional argument: a
DB constraint sees only the resulting value (legal); a lint would have to pin a scope string literal at
a call site and any extracted variable walks past it; static analysis has no opinion, since both
arities type-check. The one mechanism available is a test —
`PaymentProvenanceTest`'s seed case plants a migrated row at 900,000,001, records a portal payment and
asserts the reference is `1` — and it is stated plainly in that test's header as the only one. It runs
in `bin/quality` step 14, so it blocks a push; it does not stop anyone writing the seed in the first
place. The one constraint that *would* be structural — `CHECK (origin = 'migrated' OR reference <
900000000)` — is expressible and is deliberately **rejected**: it converts a silent corruption into a
hard 3819 on every payment the school takes after the import, i.e. it closes the bursar's front door
instead of preventing the mistake. Recorded as considered, not overlooked.

**OPEN, and owed by commit 4: is `external_reference` UNIQUE?** It shipped nullable with **no index and
no uniqueness**, which is the honest state of a column whose writer does not exist yet — but it is a
decision, not an oversight, and it is named here so it is not discovered later. Idempotency today is at
the **batch** level only (`ob_batches_school_reference_unique` on `(school_id, batch_reference)`), so two
batches filed under different batch references and carrying the same WCBS receipt would post that
receipt twice and the database would have no opinion — at which point `external_reference` stops being
"the only handle back to the source system". Commit 4 must rule one way or the other: a unique index on
`(school_id, external_reference)` where non-null, or an explicit statement that duplicates are legal and
why. Do not leave it unstated a third time.

**THE RECEIPT REFUSAL IS OWED, NOT BUILT — there is no receipt surface to refuse from.** No receipt is
produced for a payment anywhere in this repository today: payments are JSON-only
(`PaymentController::store` / `storeForStudent`, `routes/endpoints/finance.php:24`, `:145`), the only
UI that renders them is a read-only table (`resources/js/pages/admin/finance/statement.tsx:688`), and
there is no PDF, no mail, no export and no download endpoint over `finance_payments`.
`NotificationType::PAYMENT_RECEIVED` exists as an enum case
(`app/Notifications/Enums/NotificationType.php:39`) and has no dispatcher. Building a refusal now would
be a guard over nothing. **The obligation moves to whichever commit introduces the first receipt
surface** — PDF, printable page, emailed confirmation or export: it must refuse for `origin = 'migrated'`
**with a stated reason**, never silently hide the row, because nobody at Brookstone handed that parent
this system's receipt. That is a condition on that commit, and this paragraph is where it is recorded.

---

## 5. ~~The mismatch check~~ — WITHDRAWN under R5/R10

**Ruling: §5 is WITHDRAWN, not repointed.** It is not left standing with no input, and it is not
quietly re-aimed at something that would make it pass.

**Why withdrawn rather than repointed.** §5's only input was `wcbs_billed_total` — what WCBS billed
for the cutover term — and R5 deletes that column because a balance-forward file carries a closing
position, not a term's billing. Repointing it at what the new file *does* carry would mean comparing
an **opening balance** against a **fee schedule price for the next term**. Those are different
quantities: one is what a student owes at the boundary, the other is what they are about to be
charged. They have no reason to be equal, so the comparison would raise an exception on essentially
every row and a report that flags everything flags nothing. §5's stated purpose — *"a mismatch means
the parent's paper bill and the portal's bill disagree"* — also has no referent once the portal
issues no competing bill for that term. There is nothing left to compare.

**What retires with it, and it is commit-4 removal scope, not this commit's:**

| Retired | Where it lives today |
|---|---|
| `expected_billed_minor` / `expected_billed_currency` | `2026_08_06_100000_create_finance_opening_balance_tables.php` (rows table) |
| `comparison_mismatch` finding | `app/Finance/Console/ImportOpeningBalances.php:366` |
| `no_active_enrollment` | `:333` / `:335` |
| `enrollment_has_no_class_level` | `:338` / `:340` |
| `no_active_fee_schedule` | `:359` / `:361` |
| `OpeningBalanceRowStatus::NotComparable` | `app/Finance/Enums/OpeningBalanceRowStatus.php` |

**Which of the three `not_comparable` reasons survive: NONE.** All three exist only to explain why
§5's comparison could not run, and two of them (`no_active_enrollment`,
`enrollment_has_no_class_level`) exist only because the comparison needed an *episode* at all. Under
R6 the import touches no episode: it posts ledger charges keyed to `student_id`. So all three lose
their subject, not merely their input.

**One consequence that must not be discovered later.** The ACL port fields `termId` / `classLevelId`
(`app/Finance/Contracts/BillableEnrollment.php:46-47`) were added in commit 1 with this validator as
their consumer. When commit 4 removes §5, **they have no caller left in the repository** until
normal-course bulk billing is built — which does still need (term, class level) → schedule, so they
are not wrong, merely early. Stated here rather than left for a reviewer to find: this is the
front-loading rule biting in reverse, where a consumer was withdrawn after the primitive shipped.

---

## 6. Pre-flight, before any of this is built

1. **Duplicate-after-trim admission numbers.** `students.admission_number` is NOT NULL
   (`2026_07_18_100000_make_identifier_columns_not_null.php:36`), so the null half of this check can
   only ever answer zero — the validator still computes it, cheaply, in case that is ever relaxed.
   The half that bites is duplicate-after-trim: `'ADM1'` and `' ADM1'` are distinct rows at
   `students_school_id_admission_number_unique` and identical as a join key. Any at all and the key
   is unsafe; the validator raises it as a finding on the batch, not on a row.
2. ~~**Can WCBS split by term?**~~ **MOOT under R5.** R1 answered it yes, and R5 then removed the
   question: a boundary cutover needs a closing position, not a per-term split.
   ~~What replaces it is a different data question, and it must be answered before the file format is
   frozen: does the extract carry the per-student stated total and the batch control total?~~
   **ALSO CLOSED, by R12: the format is frozen and we dictate it.** There is nothing left to ask the
   data team about the layout. `student_total_balance` is a **required column** by construction (§2),
   and `batch_control_total` is **not a column at all** — it is typed by the operator at upload
   (§12), which is what makes it a witness rather than a second copy of the file's own arithmetic. So
   L1 and L2 both have their witness by design, not by hoping the extract carries one.
   R1's *consequence* survives its question: **one batch is one term boundary.** A second posted
   batch would bring the same history forward twice, and §11's G1 is what makes that hard — though
   see §11 for how hard it actually is, which is less than Rev 3 claimed.
3. ~~**Are the T fee schedules configured and active**~~ — **no longer a cutover precondition.** It
   was one only because §5's comparison and the re-bill both read the schedule; §5 is withdrawn and
   the re-bill is dropped. The **next** term's schedules must exist before native billing runs, but
   that is normal-course U1 work and it does not gate the import. The Rev 3 sentence recording a
   dated production-copy figure is removed with it: it was a claim about a moment, in a document that
   outlives the moment.

---

## 7. Edge cases, decided

Rewritten for the balance-forward file. Every Rev 1–3 row that named `prior_arrears`,
`wcbs_billed_total`, `paid_to_date` or `wcbs_total_balance` is gone with the columns, not merely
re-worded — a rule about a column that no longer exists is the decoration R10 refuses.

| Case | Decision |
|---|---|
| A student's lines are all positive | Ordinary. One ledger charge per fee type (R6). |
| A student's lines are all negative | The student is in credit. One netted migrated payment (R8); nothing is charged. |
| A student has both positive and negative lines | Both instruments, both in the one transaction: a charge per positive fee type, and ONE netted payment for the sum of the negatives. They are not offset against each other first — the per-fee-type breakdown is the thing the directive asked for and netting it away would destroy it. |
| A student's lines sum to zero | The lines still post. A zero *net* is not an absence of history, and a statement showing "Tuition +50,000 / Scholarship −50,000" is the correct rendering of a student who owes nothing for a reason. |
| A single line's balance is zero | Skip the line; there is no movement to post. It still stages, and it still counts toward L1. |
| L1 fails for a student | Reject that student's whole row-group with both sides in the finding. Not a partial post — posting three of four lines is worse than posting none. |
| L2 fails for the batch | A finding on the BATCH. Every line may be internally consistent and the file still be missing a student. |
| Student in WCBS, absent from the portal | Reject the row and name it. **Never create a student from a finance import** — unchanged, and the one rule that has survived every revision. |
| Student exists but is SOFT-DELETED | Rejected, but under **its own finding code**. `admissionNumberIndex()` excludes soft-deleted students by the Student model's default scope (`app/Finance/Contracts/BillableEnrollmentProvider.php:72-75`). Today that path emits `student_not_found` with the text *"No student in this School has admission number [X]"* (`app/Finance/Console/ImportOpeningBalances.php:311-312`), which is **false** for a trashed student and hides the one case an operator must decide by hand. Commit 4 splits it: a distinct `student_soft_deleted` code with its own message (§9). Both still reject; the operator is told which. |
| A student has no active enrollment | **Their lines post anyway.** Under R6 the import resolves a **student**, not an episode — `admissionNumberIndex()` is a Student roster (`app/Academics/BillableEnrollmentAdapter.php:128-138`), and the contract states the boundary: *"Withdrawn and graduated students ARE included: §7 imports their arrears and payments — their balance stays chaseable"* (`app/Finance/Contracts/BillableEnrollmentProvider.php:72-75`). This is **deliberate, not an oversight of R5**. Rev 3's `no_active_enrollment` rejection was §5's precondition — the comparison needed an episode to reach a fee schedule — not a rule about who may hold a balance, and it retired with §5. **DO NOT RE-ADD AN ENROLLMENT CHECK TO THE IMPORT**: it would reject exactly the debtors the cutover exists to carry. |
| Student in the portal, absent from the file | Report as unimported. Their opening position is zero, which is a claim someone must make deliberately. |
| Duplicate `(admission_number, fee_type_label)` in one file | Refused at the DB by the R9 key. Two lines for the same fee type are an extract defect, not a rule for the import to decide. |
| Import posted in error | **R3 (2026-08-06): no live reversal instrument, and there will not be one.** A wrong imported balance found before go-live is corrected by **restoring the database** and re-running a corrected batch. See below. |

**R3, and exactly what it costs.** A post cannot be undone by deleting rows, and that is a property
of the design rather than a missing feature. `SubledgerPoster::post` is the single writer that
maintains `finance_student_accounts.balance_minor`, and it does so by an **atomic delta** at write
time, not by re-summing the ledger. Deleting the ledger rows therefore leaves the projection holding
the movement they caused: the balance stays wrong and now disagrees with its own ledger, which is the
one condition `finance:reconcile-accounts` exists to detect. `finance_ledger_transactions` is
additionally append-only at the engine — DELETE is denied by trigger
(`2026_07_19_100001_create_fee_ledger_transactions_table.php:56-60`), so "just remove the rows" is
not merely unwise, it is refused. **"Undo the import" is not a smaller version of "restore the
database"; it is a different and worse outcome.**

**R3 expires**, and §11 says how that expiry is watched — which, after Rev 4's correction to G2, is
by a person and not by a gate.

---

## 8. Approval gate

Per S9 the import is maker–checker, and the batch is the unit of approval, not the row. Maker uploads and reviews; a different user approves; posting happens on approval. `DutySeparation` and `ApprovalAbility` from the Kernel, the same shape as the other four request types. This makes **six** on the approvals queue (U16), which §4.3 already anticipated.

---

## 9. Build order

1. **Pre-flight** (§6) — answers, not code.
2. **Staging table + read-only validator** — *shipped* (PR #210 at `bdb0a99`, PR #211 on top). Built
   against Rev 2/3, so **Rev 4 leaves it out of date**: it parses four columns that no longer exist
   and runs §5's comparison, which is withdrawn. Commit 4 carries that correction; see R9 below.
3. **Provenance shapes** (§4) — `origin`, `external_reference`, the `migrated` method value, the
   reserved receipt band, the receipt refusal. **`origin` is now a hard dependency, not a
   convenience**: R4 (§12) makes it the export-exclusion predicate, so descoping it breaks the
   general-ledger boundary rather than weakening a guard.
4. **Posting + approval gate** (§3, §8), **plus the R9 migration, G1 and G1b**.
5. **U12b** — the operator screen.

### R9 — the staging key is wrong for a per-fee-type file

`finance_opening_balance_rows` carries

```sql
unique(school_id, batch_id, admission_number)   -- 2026_08_06_100000_create_finance_opening_balance_tables.php:144
```

which permits exactly **one row per student per batch**. A per-fee-type file needs one row per
(student × fee type). Commit 4's migration must:

- change the key to `unique(school_id, batch_id, admission_number, fee_type_label)`;
- add `fee_type_label` (string, **NOT NULL** for an ingested row) and its per-fee-type balance
  column pair (`{name}_minor` + `{name}_currency`, Constitution rule 10, with the same
  `^[A-Z]{3}$` CHECK the other eight carry);
- retire the four Rev 2/3 money column pairs and the `expected_billed` pair (§5).

**This is safe to change, and a reviewer should not read it as a rewrite of live data.** Staging is
scratch: `finance_opening_balance_rows` is CASCADE-deleted from its batch by
`finance_opening_balance_rows_batch_school_foreign`, the tables carry no immutability trigger, and
**nothing has ever posted from them** — the posting Action does not exist yet. There is no migration
of existing rows to design, because there are no rows worth keeping.

### Split `student_not_found` — commit-4 code scope

A soft-deleted student is excluded from `admissionNumberIndex()` by the Student model's default
scope, so the import's join misses them and reports `student_not_found`:
*"No student in this School has admission number [X]"*
(`app/Finance/Console/ImportOpeningBalances.php:311-312`). **That message is false for a trashed
student**, and it collapses two cases an operator must handle differently — "this person was never
here, check the extract" versus "this person was deleted, decide whether to restore them before the
cutover carries their balance". The second is the one that needs a human, and it is currently
invisible.

Commit 4 splits it into a distinct **`student_soft_deleted`** finding with its own message. Both
still reject — nothing posts against a trashed student — but the operator is told which.

**Reaching the distinction means asking the PORT for the trashed roster.** Do not reach for
`withTrashed()` from inside Finance: soft-deletion is an Academics-owned lifecycle fact, and the
whole reason `admissionNumberIndex()` exists is that Finance must not re-join the students table
(arch rule 3). The trashed roster is a second question for the same port, not a scope to peel off
inside the module.

> **A correction, because this note previously claimed an enforcer that did not exist.** It said the
> boundary lint forbade `withTrashed()` inside Finance. **It did not.**
> `bin/ci-boundary-lint.php`'s `finance-escape-hatches` rule matched five tokens —
> `withoutGlobalScope(`, `withoutSchoolScope(`, `->hasRole(`, `auth()->setUser(`, `DB::table(` — and
> `withTrashed(` was not among them; `App\Models\Student` is also absent from
> `tests/Arch/ArchitectureBoundaryTest.php`'s forbidden-model list, and deliberately so, since three
> Finance files already import it. `Student::withTrashed()` inside `app/Finance` therefore passed all
> thirteen quality steps. **It is closed now** — `withTrashed(`, `withoutTrashed(` and
> `SoftDeletingScope` were added to that same rule, with the lint's own coverage test to prove it
> fails.
>
> **The general lesson, which is worth more than the fix:** `withTrashed()` *is*
> `withoutGlobalScope(SoftDeletingScope::class)` — Laravel's `SoftDeletes` trait implements it that
> way — so the behaviour had been forbidden since the first Finance commit and only the *token*
> escaped. **A token-grep lint cannot see a method that reaches the same forbidden behaviour under a
> different name.** Every alias of a banned call has to be enumerated by hand, or the rule has a hole
> shaped exactly like the alias — and a doc that asserts the rule covers it makes the hole harder to
> find, not easier.

### OPEN — `term_id` on the batch contradicts §1, and this is not a docs decision

**Rev 4 and the shipped migration disagree, and the disagreement is live.** Both read, both confirmed:

- §1 says *"There is no cutover term **T** any more: R5 puts the cutover on a term boundary, so the
  file is a closing position, not a mid-term snapshot."*
- The batch table says otherwise:
  `$table->foreignId('term_id')->constrained('terms')->restrictOnDelete();`
  (`2026_08_06_100000_create_finance_opening_balance_tables.php:93`) — **NOT NULL by default**, so
  every batch must name a term.
- And the command still requires one: `{--term= : the cutover term T (terms.id)}`
  (`app/Finance/Console/ImportOpeningBalances.php:58`), validated to a term of the target School at
  `:721`.

So today the validator cannot stage a batch without naming a term that R5 says no longer exists. That
is not a documentation slip on either side; it is a real column with a real FK, written when T was a
real thing.

**Two options, and RECORDING THEM IS ALL THIS REVISION DOES:**

1. **`term_id` becomes nullable** — the batch names no term, because a boundary cutover has none.
   The `--term` option and its validation go with it.
2. **`term_id` is repurposed to name the term being CLOSED OUT** — the last term, whose closing
   position the file carries. The column keeps its FK and its NOT NULL; only its MEANING changes, and
   the docblock and the option's description have to change with it or the column becomes a lie.

**No choice is made here.** Either answer changes a migration — option 1 an `ALTER`, option 2 at
minimum a comment and an option rename — and a migration is not decided in a docs commit. Commit 4
picks one and says which, in the migration's docblock.

> **DEFERRED AGAIN BY STEP 4a (2026-08-08), on the merits — and recorded here so it is not read as
> overlooked.** 4a shipped the R9 migration, which is the file this paragraph names, and did **not**
> close this. The reason is that both options turn on what a batch's term is *for*, and nothing in the
> repository answers that until posting exists: option 2's "the term being CLOSED OUT" only has a
> meaning a reader can check once something reads the column, and option 1 deletes an FK on the
> strength of a use nobody has written yet. Choosing now would be picking a label, not making a
> decision. **It moves to 4b — the posting step — which is the first commit with a caller.** Until
> then `term_id` stays NOT NULL and `--term` stays required and validated; `ImportOpeningBalances`'s
> `resolveTerm()` docblock says so at the site.

### G1 — at most one posted batch per school, at INSERT

**In the DATABASE, not the job.** A job-level "has this school already posted?" check reads, decides,
and then writes, and two approvals landing together both read `false`. A unique index does not lose
that race. The project already carries the shape, at
`2026_07_19_120000_slice2_invoice_total_immutable_and_active_enrollment_guard.php:76-81`:

```sql
ALTER TABLE finance_invoices
    ADD COLUMN active_enrollment_key BIGINT UNSIGNED
        GENERATED ALWAYS AS (IF(status = 'issued', student_curriculum_id, NULL)) STORED;
ALTER TABLE finance_invoices
    ADD UNIQUE finance_invoices_active_enrollment_unique (school_id, active_enrollment_key);
```

The batches equivalent — a key that **is** the school when posted and NULL otherwise, so MySQL
exempts every non-posted row from the index and unlimited draft, validated and rejected batches
coexist:

```sql
ALTER TABLE finance_opening_balance_batches
    ADD COLUMN posted_school_key BIGINT UNSIGNED
        GENERATED ALWAYS AS (IF(status = 'posted', school_id, NULL)) STORED;
ALTER TABLE finance_opening_balance_batches
    ADD UNIQUE ob_batches_posted_school_unique (posted_school_key);
```

Four things the implementer must not discover the hard way:

- **The unique index is on the generated column ALONE**, not on `(school_id, posted_school_key)` as
  the invoice precedent has it. There the key was the *enrollment* and the school was the partition;
  here the key already **is** the school, so adding `school_id` would be redundant and would read as
  though the constraint were per-something-else.
- **No new base column is needed.** Verified inline against the migration in this repository, not
  against any other artifact: `school_id` is
  `2026_08_06_100000_create_finance_opening_balance_tables.php:76` and `status` is `:80`. Both are
  NOT NULL and both exist on the table as built, so the generated column has everything it
  references.
- **The `'posted'` VALUE does not exist yet.** `OpeningBalanceBatchStatus` is `draft | validated |
  rejected`; commit 4 adds `posted` (and whatever approval state precedes it). The index is inert
  until the enum grows, which is correct — it must ship in the same commit as the transition it
  guards, or it guards nothing.
- **`status` collates `utf8mb4_unicode_ci`**, so `status = 'posted'` also matches `'Posted'` and
  `'POSTED'`. That is the safe direction — a case-variant status cannot slip past — but it should be
  stated rather than found.

Bite-proof it the way the index deserves: post one batch, attempt a second, and assert **driver code
1062** rather than an exit code or a message. A PHP guard cannot produce 1062, so the assertion is
what proves the refusal is the index.

### G1b — deny the transition OUT of `posted`. Without it, G1 is releasable

**G1 alone is not "ever", and Rev 3 was wrong to say so.** Verified:
`grep -n "TRIGGER\|unprepared" database/migrations/2026_08_06_100000_create_finance_opening_balance_tables.php`
returns **zero hits** — `finance_opening_balance_batches` carries no trigger at all, and `status`
(`:80`) has no CHECK constraint. So one statement,

```sql
UPDATE finance_opening_balance_batches SET status = 'rejected' WHERE id = <the posted batch>;
```

frees the generated unique key and a **second batch posts**. And the first post's ledger charges
**cannot be removed** — `finance_ledger_transactions` denies DELETE by trigger
(`2026_07_19_100001_create_fee_ledger_transactions_table.php:56-60`) — so the arrears are
double-counted **permanently**. That is the worst available outcome from a guard that reads as
absolute.

**The invoice precedent is not a counter-example.** A voided invoice *should* free its slot, and
`app/Finance/Enums/InvoiceStatus.php:14-16` calls exactly that behaviour correct: *"any future
non-issued state … automatically frees the enrollment's active slot without touching that
invariant."* A posted batch must **never** free its slot. Same mechanism, opposite requirement — and
copying the precedent without noticing that is how the false claim got written.

**G1b, commit-4 scope:** a `BEFORE UPDATE` trigger on `finance_opening_balance_batches` denying any
transition **out of** the posted state, signalling SQLSTATE `'45000'` in the house style. Until it
exists, §11 must state the weaker, true claim.

---

## 10. What I am least sure of

- **I got the arrears instrument wrong in Rev 1 and the schema caught me, not my reasoning.** I argued from `fee_payment_allocations.invoice_id` being NOT NULL and never checked whether a second issued invoice on the same episode was permitted. It is not. The general lesson, which applies to the rest of this document: an argument from one constraint is not a design, and I did not read the invoice table's own uniqueness before prescribing a second invoice against it.
- **I asserted G1 was absolute and it was not.** Rev 3 said *"nothing an operator, a job, a race or a
  second approval can do produces two posted batches."* One `UPDATE` does. I copied the invoice
  precedent's shape without noticing that the precedent's slot-freeing behaviour is *correct there
  and catastrophic here*. That is the same failure mode as the Rev 1 invoice error, one revision
  later: reasoning from a mechanism that resembled the problem rather than from the requirement.
  §9's G1b and §11's weakened claim are the correction.
- **I proposed G2 as a post-time gate and it detects the wrong event.** §11 records the analysis;
  the short version is that its predicate goes true when the *system* consumed imported credit, not
  when a parent's real receipt arrived, and after the restore R3 mandates the predicate is
  necessarily false. It has been demoted to an advisory read.
- ~~**The arrears reversal gap**~~ — **closed by R3** (no live instrument; restore and re-run).
- **The reserved receipt band at 900,000,000** is arbitrary. Any scheme that provably cannot collide with the live sequence is equivalent.
- ~~**§5's comparison assumes the portal's fee schedule reproduces WCBS's bill.**~~ — **moot**, §5 is
  withdrawn.
- **Per-fee-type is a CUTOVER-MOMENT rendering, and it decays immediately.**
  `finance_student_accounts.balance_minor` is a single scalar, and allocations settle **invoices**,
  not fee types — `fee_payment_allocations` carries `payment_id`, `invoice_id` and an amount, and no
  fee dimension at all (`2026_07_19_100002_create_fee_payments_tables.php:44-57`). So the
  per-fee-type breakdown exists as **N narrated ledger rows dated D, and nowhere else**. After D the
  portal **cannot** answer *"how much of this student's outstanding is tuition"* — the first payment
  that lands settles an invoice, not a fee type, and the split stops being derivable. That is a limit
  of the design, not of the import, and it is recorded here so nobody promises a per-fee-type ageing
  report on the strength of R5's file.
- **The batch control total's delivery mechanism is unspecified.** §2 requires the figure; where it
  arrives from — an operator option, a control row in the file, a sidecar — is left to commit 4 and
  recorded in §12. I would rather name it as open than invent a file convention the data team has
  not agreed to.

## 11. Cutover preconditions — split by who enforces them

Grouped by **enforcer, not by importance**, because they fail differently and a single checklist
hides that. A reader who cannot tell which of these a machine holds and which needs a named person
will assume all of them are held, and the ones that are not are exactly the ones whose failure is
unrecoverable.

### Enforced by the DATABASE

**G1 — one posted batch per school, ENFORCED BY THE UNIQUE KEY AGAINST INSERT; releasable by an
UPDATE until G1b lands** (§9).

That sentence is deliberately weaker than Rev 3's, which claimed *"nothing an operator, a job, a race
or a second approval can do produces two posted batches."* **That was false.** The generated key is
computed from `status`, `finance_opening_balance_batches` carries no trigger, and `status` has no
CHECK — so `UPDATE … SET status='rejected'` on the posted batch frees the key and a second batch
posts, while the first post's ledger charges can never be removed. §9 carries the verification and
G1b's shape. **Until G1b ships, the true claim is: concurrent inserts cannot both post; a deliberate
status edit can re-open the slot.**

### Enforced by the JOB

Nothing, currently. **G2 has been removed from this half** — see below. If commit 4 wants a job-level
precondition it must be argued for on its own merits, not inherited from Rev 3.

### PROCEDURAL — not enforceable, and must not be written up as if it were

Four things now, and none has a mechanism:

0. **PURE ARREARS — the file contains no new-term fees (R11).** Listed first because it is the most
   expensive assumption in the cutover, and the only one whose failure is invisible until after the
   money has moved.

   **There is nothing to check it against.** A closing balance that carries a term's fees is
   **byte-identical** to one that does not. There is no column to compare — R5's file has one signed
   `balance` per fee type — no schedule to compare against, because §5 is withdrawn, and no
   arithmetic anywhere that separates *"arrears of 120,000"* from *"arrears of 20,000 plus this
   term's 100,000"*. **L1 and L2 both pass either way**, and that is not a defect in either: L1
   checks the file against **itself** and L2 checks it against **a total** — and neither of them
   knows what a number MEANS. A control total read off WCBS carries the contamination too, because
   WCBS produced both. A checksum can only tell you that numbers agree with each other; it can never
   tell you that a number is the wrong number.

   **The damage surfaces one step later and looks like a portal bug.** The native re-bill posts the
   new term's fees on top, and the student is billed the term twice — and the first place anyone sees
   it is a parent's statement. By then the opening charges sit in an **append-only** ledger
   (`2026_07_19_100001_create_fee_ledger_transactions_table.php:56-60` denies DELETE), so there is no
   tidying it away: R3's answer is a **database restore**, with everything §11's other three items
   cost.

   So it is verified **by a person, before the post, against WCBS** — not by the import. This is
   procedural for exactly the reason the pre-post snapshot and the no-other-writes window are: **a
   claim about what a number MEANS cannot be checked by code that only sees the number.** Writing it
   up as a validation would be the same class of false comfort §11 exists to refuse.

   **Operator step, before approving the batch:** take a sample of students and confirm against WCBS
   that each student's stated total is the **last term's closing position** and carries none of the
   new term's fees. Sample, name who did it, and record it with the go/no-go — the point is that a
   human looked at the meaning, which no sample size makes automatic.

1. **The pre-post snapshot.** R3's entire correction path is a restore, and a restore is only
   available if somebody took a snapshot first.
2. **No other write to that school's finance tables between the post and the go/no-go call.**
   Every such write is destroyed by the restore R3 depends on.
3. **The pre-restore advisory read (formerly G2).** Run **before** the restore, not at post time:

   ```sql
   -- Has anything the portal ORIGINATED landed since the cutover date D?
   SELECT COUNT(*) FROM finance_payments
    WHERE school_id = ? AND origin = 'portal' AND created_at > ?;   -- D
   SELECT COUNT(*) FROM finance_ledger_transactions
    WHERE school_id = ? AND source_type <> 'opening_balance_row' AND created_at > ?;
   ```

   Non-zero on either means the restore will destroy real portal money, and the go/no-go is a human
   decision at that point. **This is advisory and unenforceable. It is not a gate and must not be
   dressed as one.**

**Why G2 was demoted, stated so nobody re-proposes it.** G2 refused a post when a payment with
`origin = 'migrated'` had an allocation. Under §3 that predicate goes true when **the system**
consumed the imported credit at the next invoice — not when a parent's real receipt arrived; a real
receipt is `origin = 'portal'`. And after the full restore R3 mandates, the predicate is
**necessarily false**, because the restore removes the allocations along with everything else. So it
could not fire in any sequence R3 produces. A gate that cannot fire in the scenario it was written
for is wallpaper, and leaving it in the ENFORCED half would have been worse than having nothing
there: it would have made the procedural half look shorter than it is.

**Why the procedural four cannot be automated, stated plainly so nobody proposes a checkbox.**
Item 0 fails for its own reason — the file cannot testify to its own meaning — and the other three
fail for one shared reason. A
restore happens **below the application**. The portal is not running when it occurs, it is not
consulted, and afterwards it cannot tell that it happened — there is no row to read, no event to
observe, no invariant to violate. The application cannot see a restore, cannot refuse one, and cannot
verify that a snapshot preceded one. Any control claiming otherwise would report green in the one
scenario it was built for.

So these need **a named person at cutover time, holding them for the duration of the window** — not a
line in a runbook that someone ticks. Name that person in the cutover plan and give them the window's
start and end explicitly. A procedural control with no owner is not weaker than an enforced one; it
is absent.

### The thing that connects them

R3 (§7) — restore-and-re-run — is the correction path for a wrong imported balance, and it is
**available only inside this window**. Rev 3 claimed a gate closed that window automatically; Rev 4
withdraws the claim. The window is now closed by a person reading item 3 above and deciding. That is
a weaker guarantee, honestly stated, and it is the reason §12's export boundary and G1b matter more
than they otherwise would.

---

## 12. The export boundary (R4), and what Rev 4 leaves open

### R4 — the export ruling

**WCBS remains the general ledger and the book of record.** The portal is an accounts-receivable
**subledger** that exports the transactions **it** handles.

**The export carries what the portal ORIGINATED, never what it INHERITED.** Imported rows are already
on WCBS's books; exporting them double-counts the cutover in the GL — the same double-count that R6's
reason 3 refuses at the invoice.

Two exclusion predicates, and they are named so the export cannot be written without them:

| Excluded from export | Predicate |
|---|---|
| Imported payments | `finance_payments.origin = 'migrated'` |
| Imported opening charges | ledger rows with `source_type = 'opening_balance_row'` |

**Consequence, recorded in §9 as well as here: `finance_payments.origin` can no longer be
descoped.** In Rev 3 it was a guard's input and a guard can be re-scoped; now it is the predicate
that keeps the general ledger from double-counting the cutover. There is no second way to identify an
imported payment after the fact — `origin` cannot be retro-marked, which is the reason §4 required it
before the first imported row in the first place.

The ledger-side predicate needs nothing new: `source_type` is already a plain string column
(`2026_07_19_100001_create_fee_ledger_transactions_table.php:41`) and §3 fixes its value to
`'opening_balance_row'`.

### Open decisions Rev 4 does not make

Named rather than silently resolved. Each is commit 4's to close, and each should be closed in a diff
somebody reads.

1. **Does the migrated payment need date D on its own surface?** There is no received/effective date
   column on `finance_payments`; the table has only `timestamps()`. D lives on the batch
   (`2026_08_06_100000_create_finance_opening_balance_tables.php:92`) and the payment inherits it by
   provenance. **Rev 4 does not add a column.** If a statement or a report needs D on the payment
   row itself, that is a schema decision to argue explicitly — not a field to slip into the
   provenance migration because it seemed useful.
2. **How does `batch_control_total` arrive — CLOSED by R12.** It arrives as an
   **OPERATOR-ENTERED OPTION**: `--control-total=` on the command, and a field on U12b. **Not a
   column, not a control row in the CSV, not a sidecar.**

   **Why, and the reason is the only reason the figure is worth having.** A total carried inside the
   file was produced by **the same export run as the rows**. Drop a student on the way out of WCBS
   and they vanish from the rows *and* from the total — the two still agree, and **L2 goes green on
   an incomplete file**. A witness that shares a failure mode with the thing it witnesses is not a
   witness; it is a second copy. The total earns its place only by travelling a **different path**:
   read off WCBS's own report and typed by the person doing the upload, who thereby **attests** to
   it.

   This is what sharpens the two levels into different checks rather than two spellings of one, and
   §1 now says so: **L1 is FILE INTEGRITY** (a row lost between export and upload — an Excel edit, a
   truncated save), **L2 is COMPLETENESS AGAINST WCBS**. Different failures. Neither replaces the
   other, and a green on one says nothing about the other.

   ~~AND — `student_total_balance` is NOT confirmed, and this one blocks the file format.~~
   **Withdrawn by R12.** It is not an open item and never becomes one: §2 requires the column, the
   template the platform issues (R13) emits it, and the validator's `COLUMNS` map is the single
   source of truth for both. Nothing here is waiting on the data team.

3. ~~**Does `fee_type_label` need normalising for the R9 key?**~~ **CLOSED by step 4a
   (2026-08-08): the collision is KEPT — `'Tuition'` and `'tuition'` are THE SAME FEE TYPE — and the
   in-PHP detection was changed to agree with the index.**

   The column is `utf8mb4_unicode_ci` (verified against `information_schema.COLUMNS`, not assumed
   from `config/database.php`), so `unique(school_id, batch_id, admission_number, fee_type_label)`
   collides the two at the engine whatever PHP believes. The ruling is that this is the wanted
   behaviour: a WCBS export spelling one fee type two ways has **two lines for one fee type**, which
   §7 already calls an extract defect, and the alternative — normalising the stored label — would
   break R7's "carried VERBATIM into the ledger narration".

   **What changed in code, and why it had to.** Duplicate detection previously compared bytes, so a
   case-variant pair passed the in-PHP pass and collided at the INSERT, aborting the run mid-batch
   with 1062 instead of reporting a named finding. `ImportOpeningBalances::normaliseLabel()` now
   folds case (and trims) before comparing, so the reported duplicate and the refused duplicate are
   the same set.

   **The residual is WIDER THAN ACCENTS, and the first version of this paragraph understated it.**
   `utf8mb4_unicode_ci` is the full UCA folding: it equates accents (`'Tuición'` = `'Tuicion'`),
   expansions (`'Straße'` = `'Strasse'`) and everything else its tertiary weights ignore, and it is
   PAD SPACE. `mb_strtolower` + `trim` reproduces case and padding only, so **every** equivalence the
   collation has and the fold does not reaches the INSERT.

   **And that is why the write is now wrapped in a catch, which is the load-bearing half of this
   decision.** The paragraph originally said the residual was "a worse operator experience, not a
   correctness hole: nothing is staged wrongly either way". **That was false, and it was asserted
   without executing the case.** Running it showed the abort leaving a committed batch in `draft`
   whose own `row_count` said `0` while an arbitrary prefix of the file sat in the rows table — and
   §7's idempotency reference spent on a run nobody could read. The row insert now catches the unique
   violation and converts it into the same `duplicate_row_key_in_file` finding the in-PHP pass would
   have produced, counted in the same not-ingested accounting, and the run continues. The fold keeps
   the common case out of the engine; the catch is what makes the claim true. Recorded in
   `normaliseLabel()`'s docblock and in the 4a migration's.

   **The same catch covers 1406** (a value longer than its column), with a `max` in the `COLUMNS` map
   as the defence in front of it. One defect, two triggers, one fix: a guard that closed only the
   1062 door would have left the identical orphaned-batch failure reachable under another error
   number.
