# TICKET — `AwardStudentDiscount` has no HTTP caller, and therefore no permission gate

**Status:** open. **Must be closed by the commit that adds the BSS import**, not after it.

## The fact

`App\Finance\Actions\AwardStudentDiscount` contains no `authorize`, no `Gate::`, no `->can(` and no
Policy reference. It validates its inputs thoroughly — the policy must exist, be same-school, be
`active`, and carry a `percent` — but it answers no question about **who is allowed to award a
discount to a student**.

That is not currently a hole, because the action has no request-side caller at all. Every call site
today is a test:

```
tests/Feature/Finance/BssPerStudentDiscountTest.php:200   app(AwardStudentDiscount::class)->handle(...)
```

`ProcessBulkInvoiceRun` reads the awards it produces; nothing in `app/Http` or `routes/` reaches it.

## Why it is written down now rather than when it breaks

The BSS import is the first caller from a request. At that moment an action that grants a per-student
reduction — the thing whose value the executive director's approval exists to control — becomes
reachable by whoever can reach the import endpoint, with no check of its own.

The failure mode is not exotic. Every other Finance action of comparable consequence is gated in
three independent places (Policy, action guard, DB constraint); this one would arrive with one, and
the one it arrives with is the route's, which is the layer this codebase has already been bitten by
trusting — eleven guardian routes were unguarded because the check lived where the caller composed
it rather than where the action was.

## What closes it

The import commit adds the gate to the action, not only to the route, and names the ability
explicitly rather than borrowing an adjacent `finance.*` permission. A discount award is a value
decision; `finance.access` is a door.

Whether the award should also carry maker-checker — a second person approving the *award*, distinct
from the ED approving the *policy* — is a separate question that Brookstone has effectively answered
already: the approval they want is on the value, which the discount-policy change flow provides, and
`requires_approval` on the policy is false by decision. So the gate is a permission, not a second
approval chain. **That reasoning should be written beside the gate**, because the next reader will
otherwise re-derive it or, worse, add the chain.
