# Finance authority matrix — Brookstone's decisions, 3 August 2026

Supersedes the version placed earlier today, which was written from the first answered sheet.
Brookstone withdrew that sheet ("forget about the previous answered questions I sent earlier").
**Executive Director, delegation of the Head's authority, and restoring the Principal's finance
approvals are all GONE. None of them is in scope. Do not build them.**

Source: the second returned `financeauthoritymatrixforcorrection.docx`, plus four decisions taken
3 August in answer to our checks. Letters below are read from the returned grid, not from prose.

Reading key, unchanged: **D** does it alone · **P** prepares, someone else approves ·
**A** approves · **V** views · **—** no access.

---

## 1. The four decisions

| # | Row | Decision |
|---|---|---|
| 1 | 14 — reverse a receipt | Accounts Supervisor approves. Internal Auditor drops to `V`. |
| 1 | 19 — correct a posted transaction | Accounts Supervisor approves. Internal Auditor drops to `V`. |
| 2 | 18 — transfer a payment to another student | Head of School approves. Internal Auditor drops to `V`. |
| 3 | 17 — change an opening balance | `D` for AO, AS and FL accepted as returned. Second signature built as a per-school switch, off. |
| 4 | 11 — apply a discount | Policy-backed: AO applies directly (`D`). Non-policy: AO prepares, Head of School approves. |

Two of our three checks are closed by these:

- **The Internal Auditor approves nothing anywhere.** Rows 14, 18 and 19 were the only three, and
  all three moved. The auditor is a viewer across the whole matrix, which is what the seat is for.
- **The Accounts Supervisor is a checking seat again**, on rows 14 and 19.

## 2. The corrected block, rows 11 and 14–20

| # | Transaction | AO | AS | FL | IA | HoS |
|---|---|---|---|---|---|---|
| 11 | Apply a discount — policy-backed | D | V | V | V | V |
| 11 | Apply a discount — non-policy | P | V | V | V | **A** |
| 12 | Write off a balance | P | V | P | V | A |
| 13 | Process a refund | P | V | P | V | A |
| 14 | Reverse a receipt | V | **A** | P | V | V |
| 15 | Cancel an invoice | V | V | P | V | A |
| 16 | Issue a credit note | V | V | P | V | A |
| 17 | Change an opening balance | D | D | D | V | V |
| 18 | Transfer a payment to another student | P | V | P | V | **A** |
| 19 | Correct a posted transaction | P | **A** | P | V | V |
| 20 | Create/amend/retire a discount policy | P | V | P | V | A |

Bold = changed by the four decisions. Rows 1–10 stand as returned.

## 3. What this costs — and decision 4 costs nothing

**Decision 4 is already built.** The database trigger `finance_invoice_lines_reduction_guard`
(`2026_07_26_140002:51`) refuses any reduction line that does not cite an active discount policy of
the same school, with the message *"discretionary reductions go through a credit note"* — and
refuses a policy carrying `requires_approval = 1` with *"apply it as a credit note, not an invoice
line."* The credit-note workflow is maker-checker. So "policy-backed applies directly, non-policy
needs a second signature" is the behaviour already enforced, at the database rather than in a
screen. What decision 4 changes is only WHO signs — see §4.

**Decision 3 is a design input, not a change.** No opening-balance table, column, permission or
screen exists. The switch is therefore free if it is designed in now: one per-school boolean, same
shape as `finance_discount_policies.requires_approval`, read at the point the change is raised.
Building the row first and adding the switch afterwards would cost a migration plus a screen rework.

**Decisions 1 and 2 are design inputs too.** Receipt reversal, payment transfer and posted-
transaction correction do not exist — no migration, no permission enum case, nothing. These letters
say what to build when we get there; they change nothing that is running.

## 4. Decision 5 — the credit-note approver moves

The returned sheet gave rows 15 and 16 to the **Head of School**; the running system gave both to
the **Accounts Supervisor** (`RbacSeeder.php` grants `accounts_supervisor`
`finance.credit-note.approve/reject` and `finance.invoice.void-request.approve/reject`;
`head_of_school` holds neither). Decision 4 walked straight into it, since a non-policy discount is
a credit note.

**Answered 3 August: the Head of School approves a credit note and an invoice cancellation.** The
sheet was right and the code is what moves.

Four grants change seats. `rbac:sync` will not do it — it revokes nothing and grants only
permissions created in the same run — so it takes a convergence migration on the pattern of
`2026_08_02_100000_realign_finance_governance_grants.php`, plus corrections to the maker-checker
rationale in `app/Enums/Permission.php`, `docs/finance/segregation-of-duties.md` and
`docs/rbac/finance-seat-realignment.md`. Briefed at
`docs/handoff/credit-note-approver-move-brief.md`.

Two consequences to watch, both checkable locally and both in the brief:

- **`finance:check-staffing-readiness` may go red.** It requires a maker and a distinct checker per
  school per pair. The checker for both pairs becomes whoever holds `head_of_school`; a school
  without one cannot approve a credit note or cancel an invoice at all.
- **`head_of_school` becomes a checker on two more pairs**, so any user holding it alongside
  `accounts_officer` or `finance_lead` in the same school is now a duty-separation violation. Count
  them before migrating; who loses which hat is a business decision.

Note for the record, not to re-litigate: `accounts_supervisor` now approves nothing that is built.
Its checker side returns only when rows 14 and 19 are built.

## 5. Still open, unchanged

**Discount-policy eligibility.** *"the software must block the action unless the student explicitly
matches the policy's pre-approved criteria or guest list."* `finance_discount_policies` has no
eligibility model — name, description, basis, value, `requires_approval`, status. There is nothing
to match a student against. Three surfaces: defining criteria, attaching students, enforcing at
apply time. Real scope, in no estimate, being sized separately. Brookstone has been told this.

**The Principal.** Answered as a user-assignment matter — *"the principal is also the HoS in
secondary school. It can just have the two roles"* — not a grant change. Before promising it, check
that holding `principal` and `head_of_school` together does not trip the duty-separation guard at
assignment time.

## 6. Risk declined, and recorded

On rows 3 and 4 the Accounts Officer both confirms the money arrived and writes the record saying it
arrived. We proposed moving bank confirmation to the Accounts Supervisor; Brookstone refused
explicitly: *"no approval for verifying a payment against the bank statement. It is only done by the
account officer (verify and record)."*

Their call, and it is made. What changes is the character of the control: nothing prevents this any
more, so what remains is detective — the activity log and the Internal Auditor, who now holds no
approval anywhere and is purely a reviewing seat. Detective controls only work if someone looks. Say
once, without re-litigating, that a periodic review of bank reconciliation is a process the school
runs and not a gate the system enforces. Then build what they asked for.
