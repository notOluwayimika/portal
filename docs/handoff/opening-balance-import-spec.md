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

**If WCBS cannot split by term, the import cannot proceed.** That is a data question for Brookstone, not an engineering one, and it should be answered before any code is written.

---

## 2. File format

UTF-8 CSV, one row per student, header row required. All amounts in **naira with two decimal places** (`120000.00`), parsed to integer kobo at the boundary and never touched as a float thereafter.

| Column | Required | Notes |
|---|---|---|
| `admission_number` | yes | The join key. `students.admission_number` is unique per School — but it is **nullable**, so see §6 pre-flight. |
| `wcbs_student_ref` | yes | WCBS's own id, stored for traceability. Never used to join. |
| `prior_arrears` | yes | ≥ 0. Zero is a normal value, not a blank. |
| `wcbs_billed_total` | yes | ≥ 0. |
| `paid_to_date` | yes | ≥ 0. |
| `wcbs_total_balance` | yes | Checksum. May be negative (student in credit). |
| `wcbs_bill_reference` | yes | The reference on the paper bill the parent is holding. Lands on the portal invoice. |
| `last_payment_date` | no | If WCBS has it, use it. If absent the payment records the cutover date **and says so** — see §4. |

Blank ≠ zero. A blank in any required column rejects the row.

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

Three checks, all of which can invalidate the plan:

1. **`students.admission_number` is nullable.** Count the students in the target School with a null or duplicate-after-trim admission number. Any at all and the join key needs a decision before the file format is frozen.
2. **Can WCBS split by term?** §1's three figures, separately. If not, §1's identity cannot be evaluated and the import must not proceed.
3. **Are the T fee schedules configured and active** for every class level in the School? Without them §5's comparison is blind and V2 has nothing to bill from.

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
| Import posted in error | No delete. The payment reverses through the existing correction instruments (refund or credit note), approval-gated, leaving a trail. **The arrears charge has no reversal instrument today** — `LedgerEntryType::Reversal` exists but nothing raises one for a bare charge, so a wrong arrears figure is currently uncorrectable except by a compensating charge. Decide this with the posting commit, not after it; it is the one open hole the Rev 2 change opens. Say the whole of it out loud to the operator on the approval screen. |

---

## 8. Approval gate

Per S9 the import is maker–checker, and the batch is the unit of approval, not the row. Maker uploads and reviews; a different user approves; posting happens on approval. `DutySeparation` and `ApprovalAbility` from the Kernel, the same shape as the other four request types. This makes **six** on the approvals queue (U16), which §4.3 already anticipated.

---

## 9. Build order

1. **Pre-flight** (§6) — answers, not code. Some of it can invalidate what follows.
2. **Staging table + read-only validator** — parses, validates §1's identity, runs §5's comparison, posts nothing. Brookstone can iterate the extract against it immediately. *This is the first commit.*
3. **Provenance shapes** (§4) — columns, enum values, receipt band, receipt refusal. Shipped with the thing that writes them, not ahead of it.
4. **Posting + approval gate** (§3, §8).
5. **U12b** — the operator screen.

Steps 2–5 do **not** displace S1 and V7 from the front of August. The import cannot post until the fee schedules exist (U1) and it should not post onto a balance projection whose lock anchor is still unexercised.

---

## 10. What I am least sure of

- **I got the arrears instrument wrong in Rev 1 and the schema caught me, not my reasoning.** I argued from `fee_payment_allocations.invoice_id` being NOT NULL and never checked whether a second issued invoice on the same episode was permitted. It is not. The general lesson, which applies to the rest of this document: an argument from one constraint is not a design, and I did not read the invoice table's own uniqueness before prescribing a second invoice against it.
- **The arrears reversal gap** (§7, last row) is real and unclosed. I am recording it rather than inventing an instrument for it here, because the right answer depends on whether Brookstone ever expects to correct an imported arrears figure or would simply re-run a corrected batch before go-live. Ask before building.
- **The reserved receipt band at 900,000,000** is arbitrary. Any scheme that provably cannot collide with the live sequence is equivalent.
- **§5's comparison assumes the portal's fee schedule reproduces WCBS's bill.** If Brookstone's off-platform bills carry per-student ad-hoc adjustments that no schedule can express, the mismatch report will be mostly noise and the check needs a per-student expected-total column in the file instead. I do not know which it is.
