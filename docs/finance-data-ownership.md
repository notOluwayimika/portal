# Finance data ownership & foreign-key inventory (pre-first-migration boundary lock)

Investigation only — no code, no schema. Locks the aggregate boundaries before the
first Finance migration so invoices, payments, ledger entries and approvals anchor
to the correct immutable identities from day one. Grounded in the live schema
(FK delete rules queried, not inferred) and the post-soft-end enrollment model.

**Subledger vs GL — the split, stated up front:** the **student subledger**
(per-student receivable movements: charges, payments, allocations, credits,
reversals) is Finance's core and is what the walking skeleton builds. The **GL
journal / Sage export (§13)** is a later phase: periodic, aggregated,
export-shaped. *No GL/journal tables are inventoried here and the skeleton must
not create them.* "Ledger transaction" below always means the subledger.

---

## Part 1 — Ownership inventory

| Concept | Owner | Belongs to (business object) | Immutable identity | What others reference |
|---|---|---|---|---|
| **Invoice** | Finance | the student's account, for one enrollment episode | `uuid` (+ School-scoped invoice number — gap-free, own ADR + signed policy; **skeleton stubs with internal id**) | allocations, credit notes, write-offs, subledger rows reference the invoice |
| **Invoice line** | Finance | composition child of Invoice | `uuid` | Finance-internal only; carries SNAPSHOT description + amount |
| **Charge** | Finance | **not a separate aggregate** — a charge IS the debit subledger transaction created when an invoice posts. One representation, not two. | the subledger row's identity | — |
| **Payment** | Finance | the **student account** (School+student), NOT an invoice — supports unallocated/advance payments | `uuid` (+ receipt number — gap-free family, later) | allocations reference the payment |
| **Payment allocation** | Finance | child of Payment; the money-to-invoice link | `uuid` | subledger references it as the settlement source |
| **Credit note** | Finance | **an Invoice** (it reverses billed amounts — an enrollment cannot be "credited"; accounting semantics, §10) | `uuid` (+ number, gap-free family) | subledger credit sourced to it |
| **Write-off** | Finance | an Invoice balance, via approval (§10, Ph3) | `uuid` | subledger credit sourced to it |
| **Discount** | Finance | **post-bill adjustment document against an Invoice** (registrar: not a billing mode) — same shape as credit note, policy-typed (§3) | `uuid` | subledger credit sourced to it |
| **Ledger transaction (subledger)** | Finance — the core | the student account | `uuid`, append-only, ordered by PK | nothing outside Finance references rows; statements read them |
| **Journal entry (GL)** | Finance — **later phase (§13)** | GL periods / Sage export | — | **out of scope for the skeleton; no FKs inventoried** |
| **Approval record** | Ph3 engine (ADR 0040/0044 SoD) | the document it approves (polymorphic, typed) | `uuid` | **skeleton has no approvals — stubbed absent** |
| **Fee schedule** | Finance (pricing catalog per School, effective-dated) | School | `uuid` | consulted at billing time only; **never joined for display** |
| **Fee item** | Finance | child of Fee schedule | `uuid` | invoice lines may carry it as nullable provenance |

Enrollment does **not** own financial records; billing *begins from* the
enrollment but every financial document is Finance-owned, referencing the episode.

---

## Part 2 — Foreign-key inventory

Classification: **REF** = immutable business referent (live FK) · **LOOKUP** =
reference/provenance only (nullable, never load-bearing) · **SNAP** = snapshot
value copied at write time (no join).

| FK | Class | Why (accounting semantics) |
|---|---|---|
| `invoices.student_curriculum_id` | **REF** | the billable episode — registrar: every episode bills fresh, keyed on the episode id. Soft-end made it durable. |
| `invoices.student_id` | **REF** | the account holder (statements/balances are per student, across episodes). Denormalized alongside the episode deliberately — account queries must not traverse academic rows. |
| `invoices.school_id` | **REF** | isolation (`BelongsToSchool`, Constitution §5). |
| `invoices` billed-to display (student name, admission number) | **SNAP** | §7 statement integrity: a historical statement must not re-render with a renamed student. Names are mutable; the identity FK is for linkage, the display is a snapshot. |
| `invoice_lines.invoice_id` | **REF** (composition) | line lives and dies with its invoice — except nothing dies: append-only, so RESTRICT + no delete path. |
| `invoice_lines.fee_item_id` | **LOOKUP** (nullable) | provenance of the price only. Display description + amount are SNAP (below). |
| `invoice_lines.description`, `amount_minor`+`currency`, curriculum/class label, session/term label | **SNAP** | *the decisive rule:* "JSS1 tuition, 2026/2027 Term 1, ₦150,000" must still read exactly that after the fee schedule changes, the curriculum is renamed, or the term is deleted. Any join here is a §7 statement-integrity bug. |
| `payments.student_id`, `payments.school_id` | **REF** | the account the money belongs to. Payments do **not** FK invoices — allocation does. |
| `payments` payer details (name/phone/method) | **SNAP** | the payer is a fact about the payment moment; guardian rows soft-delete and mutate. |
| `payments.received_by_user_id` | **LOOKUP** (nullable) | staff attribution; audit convenience, never load-bearing. |
| `payment_allocations.payment_id`, `payment_allocations.invoice_id` | **REF** | the many-to-many settlement link; both sides append-only. Σ(allocations of a payment) ≤ payment amount. |
| `credit_notes.invoice_id` / `write_offs.invoice_id` / discount-adjustment `invoice_id` | **REF** | adjustments reverse a *billed* amount — they belong to the invoice, never the enrollment. |
| `ledger_transactions.school_id`, `.student_id` | **REF** | the subledger is the per-student account movement log. |
| `ledger_transactions.source_type` + `source_id` | **REF** (typed, Finance-internal) | every movement is sourced to the Finance document that caused it (invoice / payment-allocation / credit note / write-off / reversal). **Ledger rows never point to enrollments or invoice lines** — the invoice carries the academic context; line-level ledger is unnecessary for the skeleton. |

Answers to the example questions: payments → student account, invoices only via
allocations. Ledger rows → source Finance documents, never enrollments. Credit
note → invoice, never enrollment.

---

## Part 3 — Cross-module identities

| Academic identity | Classification | Reason |
|---|---|---|
| `student_id` | **durable FK** | SoftDeletes (row survives); Finance reads use `withTrashed`; display name still SNAP (mutable). |
| `student_curriculum_id` | **durable FK** | no delete path remains (soft-end slice); the billable episode. |
| `school_id` | **durable FK** | no routed delete; isolation-mandatory. |
| `curriculum_id` | **should-not-store (live FK)** | routed HARD delete (`CurriculumController::destroy`) and `curricula ← student_curricula` is **CASCADE**. Context arrives via the episode; the label is SNAP on the line. |
| `academic_session_id` | **should-not-store (live FK)** | routed hard delete; label SNAP ("2026/2027"). |
| `term_id` | **should-not-store (live FK)** | routed hard delete **and** `academic_sessions ← terms` CASCADE. Label SNAP ("Term 1"). If revenue recognition later needs a period linkage, that is the GL phase — a Finance-owned billing-period concept, not a live `terms` FK. |

**Money-bearing / displayed fields — snapshot-vs-join answered per field:** line
description → SNAP; line amount+currency → SNAP (computed FROM the fee schedule
at billing time, then copied); curriculum/class label → SNAP; session/term label
→ SNAP; billed-to student display → SNAP; payer display → SNAP. **No displayed
monetary amount or line description may depend on a join to any mutable
academic/fee row.** The only live joins Finance ever makes are identity-level
(student, episode, school) for linkage — never for display or amounts.

---

## Part 4 — Lifetime analysis (queried, not inferred)

The live FK graph is **CASCADE end-to-end** on the academic side:
`schools ← {students, curricula, terms, academic_sessions, users, guardians, …}`
all CASCADE; `curricula ← student_curricula` CASCADE; `academic_sessions ← terms`
CASCADE. And Curriculum, Term, Session, CurriculumSubject each have **routed
hard-delete endpoints** today.

| Referenced record | Can it disappear? | Consequence + rule |
|---|---|---|
| Student | soft-delete only (no app hard-delete path) | FK survives; reads `withTrashed`; billed-to is SNAP anyway. |
| Enrollment (`student_curricula`) | **no delete path remains** (soft-end slice, grep-proven) — *but* a curriculum delete CASCADEs enrollments away | **the discovered boundary problem** — see below. |
| Curriculum / Term / Session | **yes — routed hard deletes, CASCADE chains** | never hold a live FK (Part 3); labels SNAP. |
| School | no routed delete | durable; and once Finance rows exist, RESTRICT armors it anyway. |
| Invoice | never — cancellation is a STATUS + credit-note/reversal, not a delete (append-only) | allocations survive against a cancelled invoice; history stays correct. |
| Ledger row | never — corrections are reversal rows | 1.4c pattern (below). |

**The discovered problem and its resolution:** the soft-end guarantee has a hole
one level up — `CurriculumController::destroy` → `curricula` CASCADE →
enrollments deleted (today only *accidentally* blocked when an enrollment has
`student_subjects`, whose FK is RESTRICT). **Finance's own FKs are the armor:**
every Finance FK is declared **`ON DELETE RESTRICT`**. In MySQL a cascaded
delete that reaches a RESTRICT child fails the *entire* statement — so the
moment one invoice references an episode, the curriculum-delete (and
school-delete) chains are blocked at the database, with no academic-side code
change. Follow-up (Ph2, not skeleton): `CurriculumController::destroy` will then
surface a QueryException and needs a graceful "curriculum has financial records"
guard — noted, not built.

---

## Part 5 — Walking-skeleton verification (post-soft-end)

Enrollment → Invoice → Ledger charge → Payment → Withdrawal → Cancellation:

- enrollment survives withdrawal — **proven** (WithdrawSoftEndTest; no delete path).
- invoice identity never changes — uuid at creation; number stubbed internally;
  cancellation is status+reversal, never delete/renumber.
- payment allocation survives cancellation — allocations are append-only rows
  referencing invoice+payment; cancellation adds a credit, removes nothing.
- ledger entries remain append-only — **decision recorded: the ledger (and all
  Finance money tables) reuse the 1.4c immutability pattern** — BEFORE
  UPDATE/DELETE database triggers + model guard + a `verify` command (extending
  `audit:verify-immutability`'s approach) — **not a new mechanism**. Applied in
  the migration that creates each table.
- future audit links durable — Finance documents are referenced by uuid from the
  (immutable) activity log; both sides append-only.

Every reference in the trace is valid at every lifecycle step under the Part 2/3
rules.

---

## Part 6 — Aggregate ownership map

```
Academic (owns)                          Finance (owns)
└── School ─┬─ Student                   ├── Fee Schedule ── Fee Items
            │    └── StudentCurriculum   ├── Invoice ─┬─ Invoice Lines   (composition)
            │        (episode,           │            ├─ Credit Notes / Write-offs / Discount adjustments
            │         durable, soft-end) │            └─ ▲ Payment Allocations ▲ ── Payment
            └── Curriculum/Term/Session  ├── Subledger (ledger_transactions, append-only)
                (deletable — never       └── [later: GL Journal / Sage export (§13); Approvals (Ph3)]
                 FK'd by Finance)
Ownership: └──   References (live FK, RESTRICT): Invoice ⟶ StudentCurriculum, Student, School;
                                                 Payment ⟶ Student, School;
                                                 Subledger row ⟶ its Finance source document.
Snapshots (no join): every displayed label & amount (fee, curriculum/term/session
labels, billed-to, payer). Lookups (nullable): fee_item provenance, received_by.
```

---

## Part 7 — Sequencing recommendation

**The Finance walking skeleton can begin.** No boundary problem blocks it — the
one discovered (academic CASCADE chains under routed hard-deletes) is *resolved
by* the skeleton's own design rules rather than by prerequisite academic work.

**Day-one rules for the first Finance migration (the point of this document):**

1. Every Finance FK is **`ON DELETE RESTRICT`** — this simultaneously armors the
   entire upstream academic CASCADE graph.
2. **Snapshot every displayed label and amount** at write time (Part 3 list); live
   FKs are identity-only (student, episode, school).
3. **No FK to `curricula`, `terms`, or `academic_sessions`** — labels are snapshots.
4. **1.4c immutability pattern** (triggers + model guard + verify command) on
   every money table, in the same migration that creates it.
5. **Subledger only** — no GL/journal tables; no approval engine (Ph3); invoice
   number stubbed internally (gap-free numbering = own ADR + signed policy before
   production invoicing).
6. Money exclusively via the `Money` VO / `{name}_minor`+`{name}_currency`
   columns / Resource-only serialization (ADRs 0002/0037/0038/0039).
