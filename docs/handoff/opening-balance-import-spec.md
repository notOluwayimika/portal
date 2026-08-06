# Opening balance import — specification

**Cutover mode** MID-TERM, RE-BILL — ruled by the project lead, 2026-08-06
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

One correction of fact carried in from the implementation, since two sections argued from it: **`students.admission_number` has been NOT NULL since
`2026_07_18_100000_make_identifier_columns_not_null.php:36`.** It was nullable when Rev 1 was
written. §2 and §6.1 are corrected below; the duplicate-after-trim half of that pre-flight is
unaffected and still bites.

---

## 0. What was decided, and one thing I got wrong

Brookstone goes live **partway through a term whose bills have already gone out** from WCBS. The portal **re-issues** that term's invoice with its full fee lines, because the parent screens are only worth building if there is something itemised behind them.

**I owe a correction on the mechanism.** I refused item 3 as stated — "the difference between the invoice and the imported balance becomes a payment" — and the refusal was right *for the shape as written*, because that amount is **derived from a later event**: bill first, then compute a payment from the gap. Re-price or credit-note the invoice afterwards and the ledger holds an immutable payment that is now wrong.

With two columns and the order reversed, the objection dissolves. **Paid-to-Date is a reported historical fact, not a derivation** — the parent really did pay ₦60,000, on a real date, through a real channel, and WCBS recorded it. Posting that as a payment is not fabrication; it is the truth arriving late. What made the original shape unsound was the *ordering*, not the instrument.

So: **import first, bill second.** The payment exists before the invoice does, and nothing is computed backwards.

This also means the module needs **no new settlement machinery at all**, which is the best outcome available here. `RecordAccountPayment`'s docblock names `GenerateInvoice::applyCreditForward` as *"the SOLE allocator of unnamed money"*, settling oldest-first at the next generation. An imported payment banked as unallocated account credit is exactly the input that path already consumes. `InvoiceSettlement` is untouched — `outstanding = total − Σ(allocations) − Σ(approved credit notes)` keeps working because a real allocation is what ends up there.

---

## 1. The three figures, defined so they cannot be misread

The extract's single largest risk is that WCBS reports **one running balance** that silently mixes the cutover term with everything before it. If that number is imported as arrears while the portal also re-bills the term, every parent is billed twice. The definitions below exist to make that impossible to do by accident.

Let **T** be the cutover term (the one already billed off-platform) and **D** the cutover date.

| Figure | Definition | Must exclude |
|---|---|---|
| `prior_arrears` | Amount still outstanding as at **D** for terms **strictly before T** | Anything at all relating to T |
| `wcbs_billed_total` | Amount WCBS billed the student for **T only** | Prior terms; any T amount not yet billed |
| `paid_to_date` | Amount WCBS received against **T only**, as at **D** | Payments against prior terms (those are already netted into `prior_arrears`) |
| `wcbs_total_balance` | The student's total balance in WCBS as at **D** — carried **only as a checksum** | — |

**The identity every row must satisfy:**

```
prior_arrears + wcbs_billed_total − paid_to_date == wcbs_total_balance
```

A row that fails it is rejected, not corrected. This one line is the whole defence against a mis-split extract, and it costs Brookstone one extra column they already have.

**R1 (project lead, 2026-08-06): WCBS can split by term, so the identity above is live.** Every WCBS
financial transaction carries an academic term identifier. This was the one open data question that
could have invalidated the whole design; it is answered, and the answer is yes. The consequence — one
batch is one term, and `prior_arrears` therefore already contains every earlier term's balance — is
in §6.2, and G1 (§9) is what keeps it structural.

---

## 2. File format

UTF-8 CSV, one row per student, header row required. All amounts in **naira with two decimal places** (`120000.00`), parsed to integer kobo at the boundary and never touched as a float thereafter.

| Column | Required | Notes |
|---|---|---|
| `admission_number` | yes | The join key. `students.admission_number` is unique per School and **NOT NULL** (`2026_07_18_100000_make_identifier_columns_not_null.php:36`; it was nullable when Rev 1 was written). Duplicate-after-trim is still possible and is still §6.1's pre-flight. |
| `wcbs_student_ref` | yes | WCBS's own id, stored for traceability. Never used to join. |
| `prior_arrears` | yes | ≥ 0. Zero is a normal value, not a blank. |
| `wcbs_billed_total` | yes | ≥ 0. |
| `paid_to_date` | yes | ≥ 0. |
| `wcbs_total_balance` | yes | Checksum. May be negative (student in credit). |
| `wcbs_bill_reference` | yes | The reference on the paper bill the parent is holding. Lands on the portal invoice. |
| `last_payment_date` | no | If WCBS has it, use it. If absent the payment records the cutover date **and says so** — see §4. |

Blank ≠ zero. A blank in any required column rejects the row.

**R2 (project lead, 2026-08-06): all four money columns will be extracted, so nothing in this table
is provisional.** §1's identity is therefore live, not conditional — it is evaluated on every row
from the first batch, and a row that fails it is rejected rather than corrected. There is no reduced
file format to design for and no "if WCBS can only give us three of these" branch anywhere below.

---

## 3. What gets written, per student

Three writes, in this order, all inside one transaction per student:

**1 — Prior arrears → a ledger charge, brought forward.**
One `fee_ledger_transactions` row, `type = charge`, positive `amount_minor`, posted through `SubledgerPoster::post` inside the import Action's transaction. `source_type` = the import row, `source_id` = its id — the soft reference the table already takes (`source_type`/`source_id` carry no FK). `narration` snapshots *"Balance brought forward from WCBS as at &lt;D&gt; · ref &lt;wcbs_bill_reference&gt;"*. Skipped entirely when `prior_arrears` is zero. The account projection moves by the same delta, because `SubledgerPoster` maintains `finance_student_accounts.balance_minor` at the single writer.

*Why not an invoice — this is the Rev 2 correction.* Rev 1 said an opening invoice, reasoning that `fee_payment_allocations.invoice_id` is a NOT NULL FK so a bare charge can never be allocated against. That reasoning is still true. It is also beside the point, because **the invoice cannot be written at all.** `finance_invoices` carries `UNIQUE (school_id, active_enrollment_key)` with `active_enrollment_key = IF(status='issued', student_curriculum_id, NULL)` — at most one issued invoice per enrollment episode — and an episode is one term (`curricula` is unique on session × class-level-arm × **term** × exam type). The arrears invoice would take the cutover term's slot and `GenerateInvoice::assertNoActiveInvoice` would then refuse the re-bill. Attaching it to a *prior* term's episode is no better: `BillableEnrollmentProvider` exposes `findByUuid` and `currentForStudent` and nothing else, prior episodes may not exist in the portal at all, and one arrears figure spanning several terms has no single episode to belong to. Manufacturing an episode is the same class of act as manufacturing a student, which §7 already refuses.

*What that costs, stated plainly.* A later payment aimed at arrears cannot be allocated to them. It banks as unallocated credit and `applyCreditForward` settles it oldest-first against the next issued invoice. **The balance stays exactly right** — charge +25,000, invoice +100,000, payment −25,000 nets to +100,000 either way — but the *attribution* reads as paying the term, not the arrears. That is the school's stated allocation policy already, not a new compromise. The consequence to carry forward: the statement surface (V3) must render brought-forward charges as their own line, or a parent sees arrears appear and never visibly clear. Put that in V3's acceptance, not in this import.

**2 — Paid-to-date → an account payment, unallocated.**
Via the existing `RecordAccountPayment` path. Banks as account credit. Skipped when `paid_to_date` is zero. Provenance per §4.

**3 — Nothing else.** The cutover term's invoice is **not** written by the import. It is written by the bulk invoice job (V2), afterwards, off the fee schedule — and `applyCreditForward` consumes the credit from step 2 at that moment.

Worked example — billed ₦100,000 for T, paid ₦60,000, ₦25,000 owed from last term:

```
import  → ledger charge          + 25,000   (arrears brought forward)
import  → account payment        − 60,000   (unallocated credit)
                                  ---------
                       balance    − 35,000   (student in credit, correct at this instant)

bulk job → invoice for T        + 100,000
         → applyCreditForward allocates 60,000 of the credit to it
                                  ---------
                       balance    + 65,000   = 25,000 arrears + 40,000 still due on T ✓
```

The parent sees one itemised invoice for T showing ₦60,000 settled and ₦40,000 outstanding, plus a brought-forward line of ₦25,000 above it. Both carry the WCBS reference — the invoice in `external_reference`, the charge in its narration snapshot.

**Order is not negotiable.** Import before generation. Reversed, the credit does not exist when `applyCreditForward` runs and every student is billed the full term.

---

## 4. Provenance — what keeps this honest

An imported payment is real, but it did **not** arrive through this system, and every surface that sums money must be able to tell the difference by one predicate. Without that, term-end reconciliation compares portal payments against the bank and is short by the entire import.

New, and all of it must exist **before** the first imported row:

| Where | Field | Why |
|---|---|---|
| `fee_payments` | `origin` — `portal` \| `migrated`, NOT NULL, default `portal` | The single predicate every collections report filters on. Retro-marking is impossible; a row written without it is permanently ambiguous. |
| `fee_payments` | `external_reference` (nullable string) | The WCBS receipt reference. |
| `fee_payments` | `method` value `migrated` | Distinct from `manual`/`transfer`/`pos`, which are claims about a channel this system observed. |
| `fee_payments` | received date (S2) | The WCBS payment date when the extract carries it. When it does not, the date is **D** and `origin = migrated` is what tells a reader the date is a cutover stamp, not an observation. |
| `fee_payments` | `bank_account_id` (S6) stays **null** | Legitimately unknown. `origin` explains the null; without `origin` a null bank account looks like sloppy data entry. |
| `fee_invoices` | `external_reference` (nullable string) | The WCBS bill reference on the **re-billed T invoice**, written by the bulk job from the batch, not by the import. This is what stops one term carrying two unrelatable numbers in front of the same parent. The arrears charge needs no column — its narration is already a snapshot. |

**Receipt numbers.** `fee_payments.reference` is a school-scoped gap-free sequence and `unique(school_id, reference)`. Imported payments must not interleave with portal-issued receipts, and no receipt PDF may ever be printed for one — nobody at Brookstone handed that parent this system's receipt. Migrated rows take references from a **reserved high band** (≥ 900,000,000), the live `Sequences` counter is untouched, and the receipt action is refused for `origin = migrated` with a reason rather than hidden.

---

## 5. The mismatch check

Nothing posts before every row has been compared and a human has looked at the exceptions.

For each student, the import computes the invoice total the portal **would** issue for T from the active fee schedule for that student's (School, term, class level), and compares it to `wcbs_billed_total`.

*The resolution path does not exist yet.* `BillableEnrollment` carries `enrollmentId`, `enrollmentUuid`, `studentId`, `schoolId`, `studentName`, `academicContext` — **no term and no class level**. So nothing in Finance can currently reach a fee schedule from a student. Extending the ACL port with `termId` and `classLevelId` is the right move and is not front-loading: this validator is the consumer, and V2 needs exactly the same two fields. The hops the adapter owns, all of which the implementer must verify rather than assume: `student_curricula → curricula`, then `curricula.class_level_arm_id → class_level_arms.class_level_id`, and `curricula.term` (an ordinal 1|2|3) → `terms` via `(academic_session_id, order)`, which is a unique key. The id types on `curricula.academic_session_id` versus `terms.academic_session_id` were written on either side of the hybrid uuid→integer conversion; check them before writing the join.

- **Equal** → clean.
- **Different** → exception. Amount, direction, both figures, no posting.
- **No active fee schedule for that class level** → `not comparable`, reported separately. Not an error; it means U1 has not been done for that class level yet, and it must be before the bulk job runs.

Every discount, scholarship and exemption already applied off-platform surfaces here as a difference. That is the point: a mismatch means the parent's paper bill and the portal's bill disagree, and the bursar finds out now rather than from a phone call.

**The batch carries two different controls, and they are not the same number.**
*Ingest completeness* is `file_row_count` (data lines read) against `row_count` (rows
staged); a difference means the file was not fully ingested and is a batch-level finding.
*Drift* is the three Money totals plus `row_count`, re-asserted at post time — and it
defends the STAGING TABLE, not the file, because at post time the file is gone: one
upload, one validation, a second person's approval, then posting reads staged rows.
As built, the Money totals mean **what parsed** — they include rows rejected for a failed
identity or an unresolved student, and exclude rows whose amounts did not parse. That is
neither "what the file said" nor "what will post". The drift control wants **what will
post**. Resolving that is a commit-4 decision, deliberately deferred: posting does not
exist yet, and a number defined against a consumer that has not been built is defined
against nothing. Whoever builds commit 4 must pick the meaning explicitly and say so in
the migration's docblock.

---

## 6. Pre-flight, before any of this is built

Two checks remain open; the third is closed by R1.

1. **Duplicate-after-trim admission numbers.** `students.admission_number` is NOT NULL
   (`2026_07_18_100000_make_identifier_columns_not_null.php:36`), so the null half of this check can
   only ever answer zero — the validator still computes it, cheaply, in case that is ever relaxed.
   The half that bites is duplicate-after-trim: `'ADM1'` and `' ADM1'` are distinct rows at
   `students_school_id_admission_number_unique` and identical as a join key. Any at all and the key
   is unsafe; the validator raises it as a finding on the batch, not on a row.
2. **CLOSED by R1 (project lead, 2026-08-06). WCBS can cleanly split by term** — every WCBS
   financial transaction carries an academic term identifier, so §1's three figures are separable
   and its identity is evaluable. **The consequence is a scope rule, and it is load-bearing: one
   batch is one term.** `prior_arrears` means everything owed **before that batch's term**, so it
   already contains every earlier term's unpaid balance. Importing two terms' extracts as two
   batches would therefore bring the same history forward twice — the earlier term's arrears once
   as its own `prior_arrears`, and again inside the later term's. That is not a procedure to be
   careful about; **G1 (§9) makes a second posted batch impossible at the database.**
3. **Are the T fee schedules configured and active** for every class level in the School? Without them §5's comparison is blind and V2 has nothing to bill from. Still open: as at 2026-08-06 the production copy carries zero active fee schedules, so §5's comparison reports `not_comparable` for every row until U1 prices the class levels.

---

## 7. Edge cases, decided

| Case | Decision |
|---|---|
| `paid_to_date` > `wcbs_billed_total` | Legitimate — the student is in credit. Excess remains unallocated after `applyCreditForward` and carries to the next term. This is the existing overpayment path, unchanged. |
| Student withdrawn or left | Import arrears and payments. **Do not** invoice for T — `students.status` already excludes them from V2. Their balance stays visible and chaseable. |
| Student in WCBS, absent from the portal | Reject the row and name it. Never create a student from a finance import. |
| Student in the portal, absent from the file | Report as unimported. Their opening position is zero, which is a claim someone must make deliberately. |
| All three figures zero | Skip. Nothing to post. |
| Negative figure in `prior_arrears`, `wcbs_billed_total` or `paid_to_date` | Reject. Credit belongs in `wcbs_total_balance` as a negative, derived from the other three. |
| Re-running the import | Idempotent on `(school_id, batch_reference, admission_number)`. A second run of the same batch posts nothing. A *different* batch against a student who already has imported rows is refused. |
| Import posted in error | **R3 (project lead, 2026-08-06): there is no live reversal instrument, and there will not be one.** A wrong imported balance found before go-live is corrected by **restoring the database** and re-running a corrected batch. See below for what that costs and when it stops being available. |

**R3, and exactly what it costs.** A post cannot be undone by deleting rows, and that is a property
of the design rather than a missing feature. `SubledgerPoster::post` is the single writer that
maintains `finance_student_accounts.balance_minor`, and it does so by an **atomic delta** at write
time, not by re-summing the ledger. Deleting the ledger rows therefore leaves the projection holding
the movement they caused: the balance stays wrong and now disagrees with its own ledger, which is the
one condition `finance:reconcile-accounts` exists to detect. The `finance_*` tables are additionally
append-only by trigger. So "undo the import" is not a smaller version of "restore the database" — it
is a different and worse outcome, and the restore is the correction path precisely because it is the
only one that returns every table to a coherent state together.

**R3 expires, and the expiry has a name.** It is available only while the imported figures are the
newest money in the school — the moment a payment is attributed against imported arrears, a restore
would destroy a real receipt that a parent holds, which is not a correction but a second error.
**G2 (§9) is what enforces that expiry**: posting refuses once such an attribution exists, so R3
cannot be relied on past the point where it stops being true. Without G2, R3 is merely convenient;
with it, R3 is honest.

---

## 8. Approval gate

Per S9 the import is maker–checker, and the batch is the unit of approval, not the row. Maker uploads and reviews; a different user approves; posting happens on approval. `DutySeparation` and `ApprovalAbility` from the Kernel, the same shape as the other four request types. This makes **six** on the approvals queue (U16), which §4.3 already anticipated.

---

## 9. Build order

1. **Pre-flight** (§6) — answers, not code. Some of it can invalidate what follows.
2. **Staging table + read-only validator** — parses, validates §1's identity, runs §5's comparison, posts nothing. Brookstone can iterate the extract against it immediately. *This is the first commit.*
3. **Provenance shapes** (§4) — columns, enum values, receipt band, receipt refusal. Shipped with the thing that writes them, not ahead of it.
4. **Posting + approval gate** (§3, §8), **and G1 and G2 below**. Both are approved and both ship in
   this commit — G1 because it is what makes "one batch is one term" (§6.2) structural rather than
   procedural, and G2 because it is what makes R3 (§7) honest rather than merely convenient. Neither
   is built by the staging commit; both are shapes here, not code.
5. **U12b** — the operator screen.

Steps 2–5 do **not** displace S1 and V7 from the front of August. The import cannot post until the fee schedules exist (U1) and it should not post onto a balance projection whose lock anchor is still unexercised.

### G1 — at most one posted batch per school, ever

**In the DATABASE, not the job.** A job-level "has this school already posted?" check reads, decides,
and then writes, and two approvals landing together both read `false`. A unique index does not lose
that race. This project already carries the shape, at
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
  here the key already **is** the school, so adding `school_id` would be redundant — and, worse,
  reads as though the constraint were per-something-else.
- **No new base column is needed.** `status` (varchar(255) NOT NULL) and `school_id` (bigint
  unsigned NOT NULL) both exist on the table as built, so the generated column has everything it
  references. See §9's answer in the implementation report for the derivation.
- **The `'posted'` VALUE does not exist yet.** `OpeningBalanceBatchStatus` is `draft | validated |
  rejected`; commit 4 adds `posted` (and whatever approval state precedes it). The index is
  therefore inert until the enum grows, which is correct — it must ship in the same commit as the
  transition it guards, or it guards nothing.
- **`status` collates `utf8mb4_unicode_ci`**, so `status = 'posted'` also matches `'Posted'` and
  `'POSTED'`. That is the safe direction — a case-variant status cannot slip past the guard — but it
  should be stated rather than found.

Bite-proof it the way the index deserves: post one batch, attempt a second, and assert **driver code
1062** rather than an exit code or a message. A PHP guard cannot produce 1062, so the assertion is
what proves the refusal is the index.

### G2 — posting refuses once a payment is attributed against imported arrears

This is the expiry of R3 (§7). It exists because a restore after a real receipt has landed is not a
correction; it destroys money a parent actually paid.

**The check as first phrased cannot be written, and the schema is why.** "Any
`finance_payment_allocation` pointing at a ledger charge whose `source_type` is an opening-balance
row" describes a join that does not exist: `finance_payment_allocations` carries
`invoice_id BIGINT UNSIGNED NOT NULL` and **no ledger reference of any kind** — no
`ledger_transaction_id`, no `source_type`/`source_id`. Allocations settle *invoices*. §3 already
states the consequence from the other side ("a later payment aimed at arrears cannot be allocated to
them; it banks as unallocated credit"), so an allocation against the arrears charge is not merely
absent — it is unrepresentable.

So G2 must read the thing that IS representable. The imported money that can be *attributed* is the
imported **payment**, which `applyCreditForward` allocates to the first issued invoice at V2. The
check, therefore:

```sql
SELECT EXISTS (
    SELECT 1
    FROM finance_payment_allocations a
    JOIN finance_payments p ON p.id = a.payment_id AND p.school_id = a.school_id
    WHERE a.school_id = ?
      AND p.origin = 'migrated'
);
```

It reads exactly two things: the allocation rows for the school, and `finance_payments.origin` — the
§4 provenance column, which ships in commit 3, one commit *before* the guard needs it. That ordering
is not a coincidence to be preserved by luck; **if §4's `origin` is ever descoped or deferred, G2
becomes unwritable and R3 becomes unenforceable with it.** Say so in commit 3's brief.

Two costs, stated so nobody re-derives them under time pressure:

- The join is on `finance_payment_allocations_payment_school_foreign (payment_id, school_id)`, which
  exists, so the read is indexed. `a.school_id` alone is also indexed.
- The check is *narrower* than "has anything happened since the post". It cannot see, for example, a
  brand-new unallocated payment banked after the post. Widening it to that would need a `posted_at`
  on `finance_opening_balance_batches`, which **does not exist today**. Decide in commit 4 whether
  the narrow reading is enough; do not assume it is.

---

## 10. What I am least sure of

- **I got the arrears instrument wrong in Rev 1 and the schema caught me, not my reasoning.** I argued from `fee_payment_allocations.invoice_id` being NOT NULL and never checked whether a second issued invoice on the same episode was permitted. It is not. The general lesson, which applies to the rest of this document: an argument from one constraint is not a design, and I did not read the invoice table's own uniqueness before prescribing a second invoice against it.
- ~~**The arrears reversal gap** (§7, last row) is real and unclosed.~~ **Closed by R3** (project
  lead, 2026-08-06): no live reversal instrument; a database restore and a corrected batch. The
  question I said depended on Brookstone has been answered — they would re-run before go-live. What
  remains is not uncertainty but a dependency: R3 holds only until money is attributed against the
  import, and **G2 (§9) is what stops it being relied on after that**. §11 says which half of the
  cutover a machine can hold and which half it cannot.
- **The reserved receipt band at 900,000,000** is arbitrary. Any scheme that provably cannot collide with the live sequence is equivalent.
- **§5's comparison assumes the portal's fee schedule reproduces WCBS's bill.** If Brookstone's off-platform bills carry per-student ad-hoc adjustments that no schedule can express, the mismatch report will be mostly noise and the check needs a per-student expected-total column in the file instead. I do not know which it is.

---

## 11. Cutover preconditions — split by who enforces them

These are grouped by **enforcer, not by importance**, because they fail differently and a single
checklist hides that. A reader who cannot tell which of these a machine will hold and which needs a
named person will assume all of them are held, and the ones that are not are exactly the ones whose
failure is unrecoverable.

### Enforced by the DATABASE

**G1 — at most one posted batch per school, ever** (§9). A unique index on a generated key.
Nothing an operator, a job, a race or a second approval can do produces two posted batches; the
second write fails with 1062 and the transaction rolls back. This is the guarantee behind §6.2's
"one batch is one term" — without it, the rule is a sentence in a document and double-counted
arrears is one mis-click away.

### Enforced by the JOB

**G2 — posting refuses once a payment is attributed against imported arrears** (§9). A read the
posting Action performs before it writes. Strong, but not as strong as G1: it is a check-then-act, so
its guarantee is bounded by the transaction it runs in, and it is only as good as the predicate
(§9 records that the predicate is narrower than "anything has happened since the post"). It is
enforcement, and it belongs to the job — not to the schema, and not to a human.

### PROCEDURAL — not enforceable, and must not be written up as if it were

Two things, and neither has a mechanism:

1. **The pre-post snapshot.** R3's entire correction path is a restore, and a restore is only
   available if somebody took a snapshot first.
2. **No other write to that school's finance tables between the post and the go/no-go call.**
   Every such write is destroyed by the restore that R3 depends on.

**Why these cannot be automated, stated plainly so nobody proposes a checkbox for them.** A restore
happens **below the application**. The portal is not running when it occurs, it is not consulted, and
afterwards it cannot tell that it happened — there is no row to read, no event to observe, no
invariant to violate. The application cannot see a restore, cannot refuse one, and cannot verify that
a snapshot preceded one. Any control claiming otherwise would be a control that reports green in the
one scenario it was built for.

So these two need **a named person at cutover time, holding them for the duration of the window** —
not a line in a runbook that someone ticks. Name that person in the cutover plan, and give them the
window's start and end explicitly. A procedural control with no owner is not weaker than an enforced
one; it is absent.

### The thing that connects them

R3 (§7) — restore-and-re-run — is the correction path for a wrong imported balance, and it is
**available only inside this window**. G2 is what closes the window when it should close: the moment
imported money is attributed, a restore stops being a correction and becomes destruction of a real
receipt. So the enforced half (G1, G2) and the procedural half are not two lists; the procedural half
is what makes R3 usable, and G2 is what stops it being used once it is no longer true.
