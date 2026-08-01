# 0048 — The account-scoped payment endpoint (a door, not a room)

**Status:** Accepted — 2026-07. **Deciders:** owner + advisor. Ships as a thin vertical slice under
[0046](0046-finance-delivery-thin-vertical-slices.md). Zero migrations, zero shared surfaces.

## Context

A bursar could record a payment only against a named invoice
(`POST /v1/finance/invoices/{invoice}/payments`). But money arrives at the window without an
invoice — a parent pays ahead, or before this term's billing has run. The workaround shipped in the
statement UI routed such a payment through "the most recent issued invoice" (`advancePaymentTarget`),
which was both a lie (the payment does not belong to that invoice) and a bug source: it settled
against invoices **newest-first**, the opposite of the carry-forward rule.

The capability itself already existed in the domain. `finance_payments` carries **no invoice FK** — a
payment belongs to the *account*, and the allocation is a separate optional link. `RecordPayment`'s
own overpayment path already writes a payment with **no allocation row** when the invoice is fully
settled (W2). So an account-scoped payment is not a new behaviour; it is a **door** onto one the
schema and the primitive already expressed.

## Decision

Add `POST /v1/finance/students/{student:uuid}/payments` → `PaymentController::storeForStudent` →
**`RecordAccountPayment`** (a sibling Action). It writes a payment, no allocation ever, and posts a
`LedgerEntryType::Payment` ledger row **byte-identical in shape** to `RecordPayment`'s — so
`finance:audit-ledger-coherence` (ADR 0047) needs no vocabulary change. The cash banks as account
credit and settles **oldest-first** at the next generation via `GenerateInvoice::applyCreditForward`,
which is now the *sole* allocator of unnamed money.

**D2 — settlement order, settled by deletion.** The statement's `advancePaymentTarget` workaround and
its header button are removed outright. With the general-payment path no longer routed through an
invoice, `applyCreditForward`'s oldest-first becomes the **only** settlement order in the system —
stronger than "both orders, one of them fixed." The per-invoice-row payment button stays: paying
against a named invoice is a real act and allocates to the invoice you named. Recorded in
`accounting-policy.md` as **ENFORCED**, with the honest mechanism — enforced by `applyCreditForward`
being the only allocator, *not* by a constraint.

**D3 — Action shape: Option B (sibling), not Option C (invert onto the primitive).** A separate
`RecordAccountPayment` rather than making `RecordPayment` take an optional invoice. Option C is the
destination and is named here as such: **taken when refunds land** (the next maker-checker instance),
which is when a second caller shape justifies the inversion. Until then two small Actions read more
honestly than one branching one.

**No lock (§4 of the design pass).** `RecordPayment` locks the invoice row for the #94 ceiling
Σ(allocations) ≤ total. This path writes no allocation, so that invariant is **vacuous here, not
unprotected** — there is no per-invoice sum to bound. The only shared state is the account balance,
moved by `SubledgerPoster`'s atomic `balance = balance + delta` (skew-free without an application
lock, `WalletConcurrencyTest` PROOF 4); nothing in the path is a read-modify-write. Stated in the
Action's docblock and in `concurrency.md` so the fourth money Action's deliberate absence of a lock
does not read as a missing guard.

**D1 — dedicated `finance.payment.record` permission: decided-and-scheduled, NOT built here.** D1 is
approved on the merits (money-in should be its own capability, granted to `accounts_officer` only, so
"takes the money in" separates from "approves the write-off"). But it edits the **seeder** and
regenerates `rbac-grants-baseline.json` + `route-access-map.json` — surfaces the RBAC ownership
protocol (`docs/rbac-implementation-plan.md` §4.1) reserves to the RBAC stream, and the RBAC seat is
mid-review against those exact fixtures. Building it here would move a review's evidence under it.
So D1 is its own small seeder-owned slice (mini-brief in the build brief §9), **sequenced after the
RBAC seat reports and before any pilot takes a payment**, with `super_admin` staying on both payment
routes (it is not a checker ability, so the `Gate::before` bypass applies). There is **no live hole**
in the interim: a fabricated payment only creates account credit, and credit is not withdrawable
until refunds exist. The posture gap becomes exploitable the day refunds land — which is the trigger.
Meanwhile the new route ships under the existing `finance.access` group, exactly the gate the
sibling invoice-scoped route already carries.

**Correction (2026-08-01, when D1 was built — `feat/finance-payment-record`).** The "no live hole"
claim above understated the interim exposure, and D2 (below) is why. Per D2 and
`docs/finance/accounting-policy.md`, an account-scoped payment **settles the oldest invoice first at
the next generation** and the invoice-scoped route allocates against the named invoice **immediately**
— so a fabricated payment does not merely park credit, it **discharges real receivables**: a
student's debt is extinguished and the receivables position is misstated. No cash leaves the school
until refunds exist (that half of the original reasoning stands, and is retained above deliberately —
not deleted), but *debt discharge is live today*, which is a bursar-shaped fraud vector, not merely a
posture gap. This is exactly why D1 is now built: `finance.payment.record` gates both payment routes,
held by `accounts_officer` only, so `finance.access` alone can no longer record a payment.

**L4 (statement discovery) — out of scope, size stated.** A bursar still cannot navigate to the
statement of a student who has never been invoiced (`finance_student_accounts` rows exist only after a
first ledger movement; the accounts index resolves names to ids and filters accounts to them, so a
never-billed student returns an empty page). So this slice closes the **API** gap; the **counter
experience** for a brand-new student stays open until L4. Closing it is real work — either surface
directory matches with no account row on the index (the port's `matchingStudentIds` already returns
them; the read model needs a zero-balance placeholder) or a "Find student" entry point. Gated on the
pilot date (D6): if the pilot is near, L4 must ship with this or the feature does not exist for its
user; if far, ship this and file L4 next.

## Consequences

**Positive:** the honest capability is now reachable; `advancePaymentTarget`'s lie and its
newest-first bug are deleted rather than patched; oldest-first is the single settlement order; the
ledger shape is unchanged so the coherence detector stays green with no edit.

**Negative / accepted:** two payment Actions until refunds justify the Option-C merge; the D1 posture
gap remains until its own slice lands (bounded — no extraction path exists pre-refunds); the L4
discovery gap leaves never-billed students unreachable from the UI until that slice.

**Neutral:** payment methods (cash / transfer / POS) are the next payment slice; the request classes
are kept separate (`RecordAccountPaymentRequest` vs `RecordPaymentRequest`) precisely so one door can
gain them before the other.
