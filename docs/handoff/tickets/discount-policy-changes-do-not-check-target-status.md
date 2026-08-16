# A discount-policy change may target a superseded or retired policy

**Raised by:** the cold review of `feat/ui-discount-policies-redesign`, 2026-08-16, which found the
screen telling operators _"none of them can be amended or retired again"_ and the file's docblock
claiming such a request would be _"a request the Action refuses"_. Neither was true. The screen's
copy and comments were corrected in that commit; the server gap is this ticket.

## What is and is not refused today

`SubmitDiscountPolicyChange::handle()` (`app/Finance/Actions/SubmitDiscountPolicyChange.php`) checks,
in order:

| Check                                                     | Line                                   |
| --------------------------------------------------------- | -------------------------------------- |
| School context is present                                 | `:42` (`SchoolContext::require`)       |
| The target belongs to this school                         | `:44-46` (`SchoolContext::assertOwns`) |
| A non-empty reason                                        | `:47-49`                               |
| `create` names no target; `amend`/`retire` name one       | `:52-57`                               |
| No other **submitted** change already targets this policy | `:61-67`                               |
| A checker is required (ADR 0051)                          | `:71-73`                               |

**The target's `status` is not among them**, and `DiscountPolicy` is loaded by route binding on
`uuid` with no status constraint. So `amend` and `retire` are accepted against a policy that is
already `superseded` or `retired`.

`ApproveDiscountPolicyChange::handle()` (`app/Finance/Actions/ApproveDiscountPolicyChange.php`) checks
the **change's** status (`:32-34`) and maker ≠ checker (`:35-37`), then dispatches. Neither `amend()`
(`:56-63`) nor `retire()` (`:65-69`) looks at the target's current status before writing it:

```php
private function retire(DiscountPolicyChange $change): void
{
    $target = DiscountPolicy::query()->whereKey($change->target_policy_id)->lockForUpdate()->firstOrFail();
    $target->update(['status' => DiscountPolicyStatus::Retired]);
}
```

And the database does not stop it either. `finance_discount_policies_update_guard` — live body in
`database/migrations/2026_08_01_100000_fix_discount_policy_guard_message_quoting.php:63-80` — signals
`45000` when `name`, `basis`, `value_minor`, `value_currency`, `percent`, `requires_approval`,
`school_id`, `uuid` or `supersedes_policy_id` changes. **`status` is the one column it deliberately
lets move** — its message says so: _"a policy's terms are immutable; only status may change."_ The
trigger is doing exactly its job; it was never the guard for this.

## The two outcomes

- **Retiring a retired policy** is a silent no-op success. A change row is written, the ED is
  notified, the ED approves, `status` is set from `retired` to `retired`, and the change is recorded
  as approved. Nothing is wrong afterwards except the audit trail, which now carries an approval for
  an act that did nothing.
- **Amending a superseded policy** succeeds and does something. `amend()` sets the target to
  `superseded` (already true), then inserts a **new active** policy carrying the proposed terms and
  `supersedes_policy_id` pointing at the superseded row. The only thing that can stop it is
  `finance_discount_policies_active_name_unique`, which fires only if the proposed name collides with
  a currently-active row (`:90-92` translates that 1062 into a friendly 422). So an amendment of a
  long-dead policy, under a fresh name, silently mints a live one and grafts it onto the wrong
  provenance chain.

The second is the one worth caring about. It is not reachable from this UI — the controls are
withheld on closed rows — but it is reachable from the API by anyone holding
`finance.discount-policy.change.submit`, and the ED's approval queue shows an amendment that looks
like any other.

## Why it was not fixed in the branch that found it

That branch was a screen redesign. Adding a status check to a maker Action and a checker Action is a
behaviour change to the governance path, it needs its own arms in `DiscountPolicyTest`, and it needs a
decision this ticket cannot make on its own: **whether the refusal belongs in the Action, in the
FormRequest, or in a CHECK.** Doing it inside a UI commit would have been the shape of a fix landing
without the proof.

## What the fix looks like

- `SubmitDiscountPolicyChange`: refuse `amend`/`retire` when `$target->status !== Active`, with a
  message an operator can act on ("This policy is no longer active; amend the policy that replaced
  it.").
- `ApproveDiscountPolicyChange`: re-check under the `lockForUpdate()`. This is the project's standing
  rule and it has been paid for once already — **approvals re-check subject legality under the lock**
  (the Ph3b credit-note-over-void remediation). A submit-time check alone loses to a race where two
  changes against the same policy are submitted before either is approved: the one-open-request guard
  is scoped to `submitted` status, so approving the first frees the second.
- Arms in `tests/Feature/Finance/DiscountPolicyTest.php`, watched red both ways.
- Decide whether the invalid combination also deserves a CHECK. It cannot be a simple one — the
  legality is about a _joined_ row's status, not about the change row's own columns — so a trigger is
  the only DB-level option, and it may not be worth it once both Actions refuse.

## Not to be fixed by

Removing the UI controls harder. They are already withheld, and this ticket exists because "the UI
does not offer it" was mistaken for "the server refuses it" in a docblock. That mistake is the thing
to avoid repeating, not the missing check.
