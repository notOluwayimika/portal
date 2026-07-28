# 0050 — Governing fee-schedule publication: a draft lifecycle state, not a proposed-terms payload

**Status:** Accepted — 2026-07. **Deciders:** owner + advisor. Ships as S1 commit 4, under
[0046](0046-finance-delivery-thin-vertical-slices.md). Sibling of
[0049](0049-governing-the-pricing-catalog.md) (discount-policy governance) — read them together.

## Context

[0049](0049-governing-the-pricing-catalog.md) settled that the discount catalog is governed Head→ED:
nothing changes on one signature. The lead then extended the same principle to fee schedules, and it is
the *stronger* case: setting tuition is a larger money decision than defining a 10% sibling discount, so
shipping discount governance without schedule governance would produce a system where the smaller number
needs two signatures and the larger one needs one. So **publishing a fee schedule is maker-checker**: the
Head proposes, the ED approves, and a schedule becomes billable (`status = active`) on two signatures, never one.

The mechanism is the one [0049](0049-governing-the-pricing-catalog.md) already chose — a change-request
document referencing the target, `submitted_by` / `decided_by` / maker≠checker CHECK / un-deletable /
`open_key`. One question is genuinely new, and getting it wrong is the expensive mistake: **a discount
policy's terms are four scalar columns and fit inline on the change row; a fee schedule's terms are a *set
of items*.** How does the change document carry a proposed set of line items?

## Decision

### The proposal is a DRAFT schedule, not a payload on the change row

Three ways to carry a proposed set of items were considered:

- **(a) A JSON payload on the change row.** *Rejected.* Money in a JSON blob cannot be CHECK-constrained,
  cannot be seen by `bin/ci-money-lint.php`, and violates the `{name}_minor` + `{name}_currency` contract
  that ADRs 0002/0037/0038/0039 make non-negotiable. It is the option that looks cheapest and costs the most.
- **(b) A mirror child table** `finance_fee_schedule_change_items`. *Rejected.* Typed and constrainable,
  but it duplicates the entire `finance_fee_items` schema, and every future change to an item's shape then
  has to be made twice or the two silently drift.
- **(c) A draft lifecycle state on the schedule itself.** *Chosen.* The Head builds a real schedule with
  real items in `status = 'draft'` (ordinary authorship under `finance.fee-schedule.manage`), then submits
  *that row* for approval. The `finance_fee_schedule_changes` row carries only a **target and a reason** —
  no proposed-terms payload of any kind. No schema duplication, no JSON, and the Head can assemble the
  numbers over several days, which is how the work actually happens. It reuses the supersession pattern
  already chosen for discount policies, so there is one idea in the codebase, not two.

The cost of (c), stated so it is not discovered later: a draft is a real row in a real table, so every
read path must filter on status — one `where('status', 'active')` in `FeeScheduleLookup` (the billing read
path), bite-proved. That is a smaller and more visible cost than a parallel schema.

**Two kinds, `publish` and `retire` — no `create`, no `amend`.** Creating a draft is authorship, not a
governed act, so there is no `create` kind: `target_schedule_id` is therefore **always non-null**, which
removes the `target_shape` CHECK the discount table needed *and* closes the concurrent-create gap
[0049](0049-governing-the-pricing-catalog.md) had to explain away — because the target is never null,
`open_key` genuinely constrains every open request. There is no `amend` because amending an *active*
schedule is precisely what must not be possible: re-pricing means authoring a new draft and publishing it
(which supersedes the current active), not editing a signed one.

**A schedule reaches `active` in exactly one place — `ApproveFeeScheduleChange` — asserted by an
architecture test.** The commit-2 direct-publish path (a draft→active flip inside `CreateFeeSchedule`)
existed only to give the catalog a way to reach `active` before this governance existed; commit 4 **deletes
it**. A superseded write path that still works is a back door, so its removal is proven two ways: the arch
test (no other class writes `active`) and oracle regeneration showing the route surface changed. The
statement order inside the approval is load-bearing — supersede the current active **before** activating
the draft, or `finance_fee_schedules_active_unique` rejects the activation; the reverse order passes on a
fresh database every time and fails only on the second publish, so it has its own twice-publishing proof.

### Why two change tables, not one polymorphic `finance_approval_requests`

`finance_fee_schedule_changes` and `finance_discount_policy_changes` are near-identical, and the next
person to see them will want to merge them into one table with `target_type` + `target_id`. **They must
not.** A polymorphic target cannot carry a foreign key, so the composite `(target_id, school_id)` FK that
guarantees a change and its target belong to the same School — the fact that makes cross-school tampering a
database impossibility rather than a code convention — becomes impossible to express. This module's whole
method is that isolation and integrity are database facts, not application discipline. Two tables, each
with a real composite FK, is the price of that method, and it is worth paying. This is recorded here
precisely because the duplication *looks* like an obvious refactor and is not one.

(The same reasoning appears in [0049](0049-governing-the-pricing-catalog.md); it is restated here from the
fee-schedule side because this is the commit that makes the second table real, and whoever opens this file
to ask "why two tables" should find the answer without a cross-reference chase.)

### The permissions wire in by convention, nothing is registered

`finance.fee-schedule.change.submit` / `.approve` / `.reject` — the same four-segment shape as the discount
trio and `finance.invoice.void-request.*`. The **terminal** verb is load-bearing: `ApprovalAbility` derives
the maker (`…change.submit`) from the checker (`…change.approve`) by convention, `DutySeparation::pairs()`
picks the pair up, the grant-time SoD guard refuses any role holding both sides, and the approvals-page gate
admits the checker — with **nothing added to any list**. Each of those is a claim about code this commit did
not write, so each is asserted by a convention test, not trusted. `finance.fee-schedule.manage` survives but
its meaning narrows to **draft authorship**, deliberately kept separate from `…change.submit` so a school
may let a bursar assemble the numbers and only the Head submit them.

### An approved schedule with zero items is refused

A business rule with no natural database expression (the DB happily activates an itemless schedule): an
empty approved schedule bills nothing and looks like a working configuration. `ApproveFeeScheduleChange`
refuses to publish a draft with no items. It lives at the point of approval, not only at authoring time,
because a draft stripped of its items after it was authored would otherwise slip past.

## Consequences

**Positive:** the largest money decision a school makes — setting tuition — now needs two signatures;
`active` is single-writer and proven so; the proposed terms are real, typed, CHECK-constrained item rows,
never a blob; and the machinery is the discount-governance shape already proven one commit earlier, not a
new mechanism.

**Negative / accepted:** a second change table the next reader will want to merge with the first (recorded
above, twice, so they don't); and every read path over `finance_fee_schedules` must remember the status
filter — the accepted cost of choice (c), localized to `FeeScheduleLookup` and bite-proved there.

**Negative / accepted — the draft is mutable between submit and approve, and commit 4 does not detect it.**
This follows directly from choosing (c) over (b): because the proposal is a *live draft row* rather than a
frozen payload, its **items are deliberately mutable** while it is a draft — proof 30 asserts exactly that,
and it must, because a draft is assembled over days. The change row itself is frozen
(`finance_fee_schedule_changes_update_guard`) and the target is frozen (the composite FK), but the *numbers*
are not. So a third seat — anyone holding `finance.fee-schedule.manage`, distinct from the Head who submits
and the ED who approves — can change the amounts *after* submission, and the ED approves a different schedule
from the one they were shown. Nothing in commit 4 catches it. The remedy is a **submit-time fingerprint**:
stamp the item count plus the sum of `amount_minor` on the change row at submit, re-derive it under the lock
at approve, and refuse on mismatch. It is scheduled as **commit 4a**, after 3b and before any pilot school
enters real prices — the window in which the gap could bite money does not open until then. Recorded here,
now, rather than in 4a, so that if 4a slips the gap is still on the record with an owner and a slot; an
unrecorded gap with no owner is how it becomes permanent. (3b does not build the fingerprint — the pilot has
not started, and 3b is line-level reduction enforcement, not schedule-approval hardening.)
