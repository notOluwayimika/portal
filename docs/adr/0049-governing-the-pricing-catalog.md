# 0049 — Governing the pricing catalog: approve the change, not the record

**Status:** Accepted — 2026-07. **Deciders:** owner + advisor. Ships across S1 commits 3a (this
governance domain) and 3b (the line-level enforcement). Shipped as thin vertical slices under
[0046](0046-finance-delivery-thin-vertical-slices.md).

## Context

A school prices tuition and grants discounts, and the lead's ruling is that both are governed:
"only Head should be able to create policy and ED will be the approval… editing, removing must always
go through approval," and policies must be "flexible and dynamic… they can create it without the need
to call a developer again." Two different questions hide under "approval," and conflating them is the
single most likely way this gets built wrong.

- **Axis A — governance of the CATALOG.** Creating, amending, and retiring a discount policy each need
  Head→ED approval. Nothing in the catalog changes on one signature.
- **Axis B — governance of each USE of a policy.** Per policy, `requires_approval`: false = a bursar
  may apply it on a bill themselves; true = each individual application needs a second person.

A policy can be approved once (axis A — it exists, it is legitimate) and still require approval every
time it is used (axis B). These are orthogonal and each needs its own enforcement.

## Decision

### Axis A is a change-request document, not lifecycle columns on the policy

`finance_discount_policies` holds only *approved* state and never carries approval columns. The
approved artifact is a separate `finance_discount_policy_changes` row — the `finance_void_requests`
shape: `submitted_by` / `decided_by` / `decided_at` / `rejection_reason`, a maker≠checker DB CHECK, an
un-deletable audit trail, and an `open_key` generated column giving "at most one open request per
target." Putting `submitted_by`/`decided_by` on the policy row itself would cover only creation;
amendment and retirement would need two more mechanisms bolted on, and a pending change must not alter
the target's usability before anyone approves it. Three kinds — create / amend / retire — one table,
one queue, one permission trio.

**Amendment inserts a superseding row; it never mutates.** `finance_invoice_lines.discount_policy_id`
and `finance_credit_notes.discount_policy_id` are provenance — they must point at the *exact terms that
were applied*, forever. If an approved policy could be edited in place, a 2026 invoice would trace back
to 2027's terms. The UI can present "Edit"; the system records supersede-with-approval (a new active
row with `supersedes_policy_id`, the old flipped to `superseded`). Money/identity columns never change;
only `status` moves — enforced by the `finance_discount_policies_update_guard` trigger. The catalog is
written in exactly one place — `ApproveDiscountPolicyChange` — asserted by an architecture test.

### Axis B reuses the credit-note maker-checker; it does NOT introduce a draft-invoice state

`InvoiceStatus` has only `issued | void` by deliberate design — there is nowhere to hold a
proposed-but-unapproved reduction on an invoice, and `finance_invoices_total_immutable` +
the append-only line triggers make a pending line impossible. So axis B is enforced not by a third
approval document but by a rule about **which door a reduction may enter through**: an active policy
with `requires_approval = false` enters as a line at generation; `requires_approval = true` or a
policy-less "exceptional case" enters as a credit note after issuance, through the machinery already
shipped and bite-proven.

**⚠ Axis B's line-level enforcement is NOT yet live as of 3a.** The DB rule that a reduction line must
cite an active, non-approval-requiring policy — the `finance_invoice_lines_reduction_guard` trigger and
the `discount_policy_id` columns — ships in **commit 3b**, together as one migration (the columns must
not exist ahead of the guard that gives them meaning). Until 3b merges, a reduction line at generation
time is still reachable free-text, exactly as before Part 0's actor column made it attributable. This
ADR describes the full control; 3b makes the axis-B half of it real, and this paragraph is amended when
it does.

### The other decisions, recorded so they are not re-litigated

- **`requires_approval` is a policy ATTRIBUTE, not a hardcoded category list.** A school creates a
  policy tomorrow without a developer — the explicit requirement. The database never enumerates
  discount categories.
- **The approver is a PERMISSION (`finance.discount-policy.change.approve`), not a column or a role
  name.** Role-agnostic approval logic is a standing rule; the four-segment names wire into the
  `ApprovalAbility` convention (maker-derivation, super-admin bypass exclusion, the approvals-page gate)
  with nothing registered in a list — proven by three convention tests, not trusted.
- **`basis` is CHECK-constrained to "amount XOR percent, never both, never neither"** — "make both
  available" as a database fact, not a FormRequest convention.
- **Two change tables, not one polymorphic `finance_approval_requests`** (fee schedules get their own,
  `finance_fee_schedule_changes`, in commit 4 — see [0050](0050-governing-fee-schedule-publication.md)).
  A polymorphic `target_type` + `target_id` cannot carry a foreign key, so the composite
  `(target_id, school_id)` FK that guarantees a change and its target share a School becomes impossible.
  This module's method is that isolation and integrity are database facts; two tables with real FKs is
  the price, and it is worth paying. The next person to see two near-identical tables will want to merge
  them — this is why they must not.
- **Fee schedules hold live RESTRICT FKs to `terms`/`class_levels`; invoices hold none.** The snapshot
  rule governs historical *documents* (an invoice line copies description + amount and joins nothing),
  not a live *catalog* consulted at billing time. The consequence — `TermController::destroy` translates
  the resulting 1451 to a friendly refusal, and the `academic_sessions←terms` CASCADE into that RESTRICT
  refuses a session delete too — is handled and bite-proven (S1 commit 2).
- **`is_discountable` narrows the percentage base** (a "50% staff-child discount" no longer discounts
  transport/feeding), defaulting **true** so a school that configures nothing sees behaviour identical to
  before. Additive, not a semantic break. Ships in 3b with the generate-flow threading.
- **Percentage rounding is half-to-even** (banker's rounding, `Money::percentage`, signed accounting
  policy §1) — NOT half-up. Any plan doc saying half-up is wrong.
- **The one deliberate departure from append-only:** `finance_fee_items` permits DELETE while its parent
  schedule is a draft — a proposal that has never priced an invoice has no accounting meaning, so §15C
  does not protect it (S1 commit 2).

### Known product question, recorded not resolved

`scholarships` (Academics-owned, name-only, no money) overlaps conceptually with a full-waiver discount
policy. Whether a scholarship should *be* a discount policy, or stay a separate academic fact, is a
product question for the lead — flagged here rather than silently resolved one way.

### Deliberately out of S1 (Part 5), each with its trigger to build

Student discount *entitlements* (a durable "this student has the staff-child discount" record — lands
in S2 with bulk generation, its actual consumer); the `auto_apply` toggle (a boolean no code reads is
the trap this project polices — arrives with entitlements); proration / any read of
`terms.start_date`/`end_date` (dead by the lead's case-by-case mid-term ruling); refunds (maker-checker
instance #3); any change to `scholarships`.

## Consequences

**Positive:** the largest money decisions (repricing a class group, defining a discount) now need two
people; the catalog is single-writer and every reduction's provenance is a real column; the machinery is
the void-request shape already proven, not a new mechanism.

**Negative / accepted:** two change tables the next reader will want to merge (recorded above so they
don't); axis B's line-level enforcement is live only from 3b, so the free-text-reduction hole at
generation time stays open across 3a; and `discount_policy_id` on every reduction line written before
3b is permanently NULL (the reduction guard is BEFORE INSERT only, and the line table is
append-only) — 3b records its own merge date as the provenance boundary before which "every reduction
cites a policy" is false by construction.
