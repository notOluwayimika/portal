# 0051 — "Does this need a second signature" is configuration, behind one seam

**Status:** Accepted — 2026-07. **Deciders:** owner + advisor. Ships the seam only (one class, four
one-line call-site changes, one lint rule) under the thin-vertical-slice shape of
[0046](0046-finance-delivery-thin-vertical-slices.md). Changes **no behaviour**.

## Context

The lead wants approval to be "flexible and dynamic… they can create it without the need to call a
developer again" — the same instinct that governs the pricing catalog ([0049](0049-governing-the-pricing-catalog.md))
now aimed at approval itself. Today four Finance maker actions — `SubmitCreditNote`, `SubmitVoidRequest`,
`SubmitDiscountPolicyChange`, `SubmitFeeScheduleChange` — each decide "this needs a checker" **implicitly
and unconditionally**: the requirement is hard-wired at the call site, once per action, and six more of the
ten business items are coming. The day a school says "under ₦50,000 the Officer just does it," that
decision has to change in ten places at once, each a maker action written months apart by whoever was on
that slice. That is exactly the drift that produces one action quietly out of step with the other nine.

Three different questions hide under the word "flexible," and conflating them is how this gets built wrong:

- **WHO may approve** (which role is the checker for a given transaction). This is **data** — role
  definitions, and it is the part the lead most concretely wants: per-school, self-serve.
- **WHEN a second signature is required at all** (always, or only above a threshold / for certain kinds).
  This is the decision the four Submit actions make. **This ADR is only about WHEN**, and only about
  putting it behind a seam — not about making it configurable yet.
- **WHAT the approval then does** (a one-step Head→ED, a two-step chain, a parallel panel). Each shape is
  a **build**, not a config value, and none is in scope.

## Decision

### One named place: `App\Finance\Approval\ApprovalRequirement`

The "does this submission need a checker" decision routes through a single class. Each of the four Submit
actions calls `ApprovalRequirement::for(<its maker ability>, <the amount, where one exists>)` and branches
on `->required`. Today the seam **always** answers `required: true` (fail closed). The other arm throws
`LogicException('Straight-through submission is not implemented — see ADR 0051.')` — an honest marker, not
a live path: a half-built straight-through arm that silently created an unapproved row would be far worse
than a throw, and F-4/F-5 pin that the arm is unreachable for every real caller.

When `finance_approval_rules` lands, only this class body changes — a lookup on `(school_id, maker ability
[, amount])` — and the ten call sites do not. That is the entire point of the seam: **the requirement
becomes configurable in one place instead of ten.**

### Keyed on the MAKER ABILITY, not a hand-kept transaction-type enum

`for()` takes the maker ability string (e.g. `finance.credit-note.submit`) because that is exactly what
`DutySeparation::pairs()` already derives via the `ApprovalAbility` convention. A separate enum of
"transaction types" would be a second list to keep in lockstep with the first — and the two would drift.
The boundary lint (below) asserts the invariant instead: one `Submit*.php` per finance `*_SUBMIT`
Permission case.

### The return is a value object, not a bool — because a straight-through row records a RULE, not a person

Every approval table carries `CHECK (submitted_by IS NULL OR decided_by IS NULL OR submitted_by <>
decided_by)`. "Approval not required" therefore can **never** be represented as "the maker approves their
own row" — that is unrepresentable by design, and correctly so (it would be self-approval, the one thing
maker-checker exists to forbid). A straight-through submission is a row with `decided_by IS NULL` whose
authorisation is attributed to a **rule** — and the audit trail must answer "who approved this?" with
"rule #N at school X, in force that day," not a name. So `ApprovalRequirement` carries `?int $ruleId`
alongside `bool $required`; `$amount` is on `for()` for the same reason a threshold rule will need it.
A bool return would force every call site to change **again** to carry the rule id — the exact churn the
seam exists to erase. This return type is the one deliberate shaping-ahead; everything else is inert today.

### Not a polymorphic approval engine

Consistent with [0049](0049-governing-the-pricing-catalog.md) and [0050](0050-governing-fee-schedule-publication.md):
we do **not** build a generic, table-driven approval engine that every transaction plugs into. Approval is
roughly a fifth of what any one of these transactions does; a polymorphic engine would centralise the easy
part (the yes/no) while scattering the hard parts (each transaction's legality re-check under lock, its
provenance, its ceilings). The seam is a **decision point**, not a framework. Each new maker action still
writes its own submit path and simply calls the seam — the lint guarantees it does.

### WHO is configured as DATA, and the grants are GLOBAL — so it is a build, not a threshold

When per-school approval arrives, `finance_approval_rules` rows and the role-definition data are how a
school self-serves the WHO. The permission **grants** that back the checker abilities are global
(`whereNull('school_id')`) — a role either holds `…approve`/`…reject` or it does not, uniformly. That
means "let this school's Officer be the checker" is a **role-definition build**, not a per-school threshold
toggle, and it stays out of this seam. The seam is strictly the WHEN.

### The never-configurable invariant

No configuration this seam ever grows may let a **single role hold both sides** of a pair. That is
[0040](0040-super-admin-never-overrides-maker-checker.md)'s constraint, enforced in three layers that this
ADR does not touch and does not weaken: the `submitted_by <> decided_by` DB CHECK, the `DutySeparation`
convention that pairs maker with a distinct checker, and `super_admin` never overriding maker-checker. A
straight-through rule removes the *second signature*; it never merges the two signatures into one person.

## Enforcement

- **`approval-seam-missing`** (`bin/ci-boundary-lint.php`): every `app/Finance/Actions/Submit*.php` must
  **call** `ApprovalRequirement::for(` on a live (non-comment) line. Enumerated from the filesystem glob,
  never a hardcoded list — a new `Submit*.php` is covered the moment it lands. Keyed on the call, not the
  token, so deleting only the branch (leaving a stale `use`) still trips it; comment-filtered, so a
  commented-out call does not satisfy it (the authz-rule-15 hole, closed here too).
- **`approval-seam-count`**: the count of `Submit*.php` actions must equal the count of finance `*_SUBMIT`
  Permission cases (4 = 4 today). This is the count of distinct **makers**, deliberately **not**
  `DutySeparation::pairs()` — pairs double-counts (each maker yields an approve *and* a reject checker → 8
  finance pairs for 4 makers). A new maker permission with no Submit action, or the reverse, is coverage
  drift the per-file rule cannot see.

Both rules carry **zero** baseline entries — pure enforcement, no exceptions.

## Consequences

- The four maker actions behave identically to before this commit (F-3: the maker-checker suites are green
  and unchanged). The seam is inert; only the indirection is new.
- `finance_approval_rules` is **deferred** — its only consumer is a per-school threshold the school has not
  yet confirmed, and a table with no live reader is speculative schema. When it lands it is a data model +
  this class's body, nothing at the call sites.
- The `LogicException` arm is dead code **on purpose**, and marked as such (F-5). Whoever wires the table
  replaces the throw with the straight-through write and deletes this sentence.
