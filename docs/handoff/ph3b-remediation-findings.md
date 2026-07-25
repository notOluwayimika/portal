# Ph3b remediation — findings & promotion note

Closeout for the pre-merge remediation of the Finance Ph3b (invoice-void maker-checker)
slice. The one money hole is **fixed**; the rest are report-only findings, a proposed
policy amendment, and record-keeping. **This doc doubles as the Ph3b promotion note** —
read the behaviour-change line before promoting to `main`.

## ⭐ Promotion note — one behaviour change

**Void-over-payment is removed.** The retired one-step cancel could void a *paid* invoice,
stranding the payment as credit. Ph3b's `VoidEligibility` refuses to void an invoice that
has an allocated payment or an approved credit note (advisory at submit, authoritative at
approval under the invoice-row lock). The correct instrument once money has moved is a
credit note (or a refund, when built) — not void. Written up as a proposed §3a amendment to
`docs/finance/accounting-policy.md` (unsigned; the project lead's to accept).

## 1. The money hole (FIXED) — a credit note surviving its invoice's void

**Path:** a credit note submitted while the invoice was live sits `submitted` — invisible to
`VoidEligibility` (which only counts *approved* credits). The invoice is voided and its whole
charge reversed. Approving the stale credit note then passes the ceiling (the void didn't
touch Σ approved) and posts a compensating credit — money conjured from a dead invoice, fully
signed and audited.

**Fix (in `ApproveCreditNote`, not the void side):** under the invoice-row lock it already
takes, refuse the approval when the invoice is no longer `issued`. The credit note stays
`submitted` (a human rejects it with "invoice voided") — never auto-decided. DB backstop:
migration `2026_07_25_150000` extends the credit-note UPDATE + INSERT guards to `SIGNAL` when
the referenced invoice is not `issued` (same two paths the ceiling already covers; `#95`
BINARY collation discipline on the added check).

**Proofs** (`FinanceApiAcceptanceTest`, all green): app-layer 422 + DB-layer raw-UPDATE refusal
+ zero additional ledger + balance exactly pre-invoice + note still `submitted`; raw
approved-INSERT against a voided invoice refused; safe direction (approved credit note blocks
the void) unchanged. Existing Ph3 credit-note proofs (22) still green.

**Generalization** (in `maker-checker-two-instance-diff.md`): *every approval must re-check, at
approval time and under the lock, that its subject is still in the state that made the action
legal.* Ceiling, void-eligibility, and now invoice-status are three instances of one rule.

## 2. R1 — did the deleted `CancelInvoice` post a reversal? (data audit)

**Yes.** `git show HEAD~1:app/Finance/Actions/CancelInvoice.php` — it posted a
`LedgerEntryType::Reversal` (`charge->times(-1)`) in the **same transaction** as the status
flip. So a voided invoice's charge and its reversal both sit in the ledger, netting to zero;
the balance (= SUM(ledger)) is unaffected. No orphaned charge can arise from that path.

**Dev DB audit (`brookstone_portal_db`, read-only):** **0** void invoices exist (1 invoice, 2
ledger rows total). `finance:reconcile-accounts` → *no drift*. So nothing to correct on dev.

**Staging:** not reachable from this local session — the same audit query
(`docs/handoff/ph3b-remediation-findings.md` §2 query below) should be run there, but the
divergence is **structurally impossible** from the cancel path because the reversal + status
flip were atomic. Query to run on staging:
```sql
SELECT i.id, i.number,
       SUM(CASE WHEN l.type='charge'   THEN l.amount_minor ELSE 0 END) AS charge,
       SUM(CASE WHEN l.type='reversal' THEN l.amount_minor ELSE 0 END) AS reversal
  FROM finance_invoices i
  LEFT JOIN finance_ledger_transactions l
    ON l.source_type='invoice' AND l.source_id=i.id
 WHERE i.status='void'
 GROUP BY i.id, i.number
HAVING charge + reversal <> 0;   -- any row here is an orphaned charge
```

**Reconcile's visibility — a real finding:** `ReconcileAccounts` checks
`balance_minor == SUM(signed ledger)` — it **trusts the ledger as truth**. A charge that was
*never reversed on void* would sit in BOTH the balance and the ledger sum, which would still
*agree* — so that class of divergence is **invisible** to reconcile by construction. Catching
it would need a different check (ledger-vs-invoice-status). It does not arise today only
because `CancelInvoice` was atomic; worth knowing if a future void path is ever non-atomic.

## 3. R5 — §5 (waivers/discounts): is there a named approval authority for create-time reductions?

**Reported, nothing built.** §5 says "The approver is configurable in-system (Ph3
maker-checker)" and lists **"approver rules (Ph3 maker-checker, §7)" as still PENDING**. So the
policy names the *intent* (an approver) but the **implementation does not exist** for
billing-time discount/waiver lines: `GenerateInvoice` folds a negative-amount `discount`/`waiver`
line into the total at creation with **no maker-checker gate**. The only enforced guard is the
non-negative-total 422 (a reduction can bring the total to zero, never below).

**So:** after void became two-person, a **discount line at invoice generation is the last
single-signature path to reduce what a student owes** — down to zero on one signature. Whether
that is acceptable is a policy question §5 half-answers (it *wants* an approver, Ph3/§7, not yet
built). This is an **in-policy gap**, not a violation — flagged for the project lead. Not fixed
here (out of the Ph3b scope; it is the next reduction instrument to gate).

## 4. Manual drive — the one-permission checker

**Embodied as a permanent acceptance test** (`FinanceApiAcceptanceTest`, "UNIFIED QUEUE — a
void-only checker…"): a checker holding `void-request.approve` but **not** `credit-note.approve`
gets their void feed (200, `can_approve` true on the maker's request), is **403** on the credit
feed (the exact rejection the UI's `Promise.allSettled` degrades to an empty contribution), and
sees `can_approve` **false** on their own submission. This is the scenario no prior test covered.

**Visual screenshots: not captured.** A real browser drive needs either seeding a test user +
pending void into the developer's live dev DB (invasive — against the standing "never touch the
real DB carelessly" rule) or a served throwaway instance. The rendered behaviour is a thin
function of the now-proven feed contract (render feed → omit the 403 side → disable the button
where `can_approve` is false). Offered, not performed; ask if the visual pass is wanted and I
will drive it against a throwaway instance.

## 5. §3 amendment — proposed

See `docs/finance/accounting-policy.md` §3a (PROPOSED — UNSIGNED). Names the void precondition
(nothing settled), the two-person control, and the correct instrument once money has moved.
The project lead accepts or edits; the code already enforces it.

## 6. Serialization point — recorded

`docs/finance/concurrency.md` now names the invariant: **the invoice row is the serialization
point for every money action touching that invoice** (`RecordPayment` #94, `ApproveCreditNote`,
`ApproveVoidRequest`), lock taken before any read the decision depends on.

## 7. `SchemaConventionsTest` exist-only audit

The append-only trigger assertions are **name-based** (exist-only). Of the **11** trigger names
in the core "1.4c immutability triggers exist" assertion, **4 were bite-proven elsewhere**
(`finance_ledger_transactions` ×2 in WalkingSkeletonTest; `finance_credit_notes_no_delete` in
CreditNoteTest; `finance_void_requests_no_delete` in the acceptance harness) and **7 were
exist-only with no behavioural bite anywhere**:

- `finance_invoices_no_delete` (DELETE)
- `finance_invoice_lines_no_update`, `finance_invoice_lines_no_delete`
- `finance_payments_no_update`, `finance_payments_no_delete`
- `finance_payment_allocations_no_update`, `finance_payment_allocations_no_delete`

**All 7 converted** — a new `SchemaConventionsTest` bite-proof generates a real invoice + line +
payment + allocation and asserts each raw UPDATE/DELETE is denied at the DB (a FOR EACH ROW
trigger never fires on an empty table, so the rows are real). `finance_invoices` is DELETE-only
there (status is intentionally mutable; total-immutability on UPDATE is bite-proven in
MultiLineInvoiceTest).

## 8. Attack the fix — subject-state-change pairs between submit and approve

Enumerated every way an approval's subject can change state between submit and decide, and
which are covered by the invoice-row lock:

| Pair (state change between submit & approve) | Action | Covered? |
|---|---|---|
| Invoice **voided** → approve credit note | `ApproveCreditNote` | ✅ **fixed here** (lock + status re-check + trigger) |
| Another credit note approved (raises Σ) → approve credit note | `ApproveCreditNote` | ✅ ceiling re-checked under lock + trigger |
| Payment allocated → approve credit note | `ApproveCreditNote` | ✅ no re-check needed — ceiling is Σ(approved) ≤ *total*, independent of payments |
| Invoice already void → approve void | `ApproveVoidRequest` | ✅ `isVoid` under lock (InvoiceConcurrencyTest bite-proof) |
| Payment allocated → approve void | `ApproveVoidRequest` | ✅ `VoidEligibility` re-check under lock (VOID PROOF 5) |
| Credit note **approved** → approve void | `ApproveVoidRequest` | ✅ `VoidEligibility` re-check under lock (VOID PROOF 6) |
| Credit note **pending** during void → void approves, then credit approve | both | ✅ closed from the credit side (this fix): the note becomes un-approvable once the invoice voids |
| Second void request for the same invoice | `ApproveVoidRequest` | ✅ `open_key` UNIQUE + one-way void |

**Residuals (not between-submit-and-approve holes):**
- **Create-time discount/waiver line** — a single-signature reduction path (R5, §3 above). Not
  an approval subject-change; a separate ungated instrument.
- **Payment refund/reversal** — not built. When it exists it is another instrument that changes
  invoice settlement state and must join the invoice-row-lock convention (concurrency.md).

No un-covered between-submit-and-approve pair was found after the fix.
