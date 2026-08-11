# The shared item rule's isolation half is unarmed

**Raised by:** the cold review of U1 commit 1 (`feat/fee-schedules-data-surface`).

## What is true today

`items.*.bank_account_id` is validated by
`app/Finance/Http/Requests/Concerns/HasFeeScheduleItemRules.php`:

```php
'items.*.bank_account_id' => [
    'required',
    Rule::exists(BankAccount::class, 'uuid')
        ->where('school_id', ActiveSchool::id())
        ->whereNull('deactivated_at'),
],
```

Three things it can refuse: a **missing** field, a **deactivated** account, and **another School's**
account. The first two are armed —
`tests/Feature/Finance/EditFeeScheduleDraftTest.php` ("refuses an edit whose item names no bank
account, and one naming a deactivated account") asserts both, on the edit route. **The third is armed
by nothing, on either route.**

The review confirmed the rule text is byte-identical to what `FeeScheduleRequest` carried at
`59e1da8`, so U1 commit 1 did not open this gap — it is as old as the rule.

## Why it is worth a ticket anyway

U1 commit 1 is the moment the rule became **shared by two request classes** rather than owned by one.
That is the right shape — a second copy is what drifts — but it also means a single future edit to
the trait now silently changes both the create path and the edit path. The isolation half is the one
that would fail silently: a weakened `->where('school_id', …)` does not break any existing arm,
because no arm exercises a foreign account. The failure it would permit is a fee line in School A
whose money is configured to land in School B's account.

Note what the database does and does not do here.
`finance_fee_items_bank_account_school_foreign` is a COMPOSITE `(bank_account_id, school_id) ->
finance_bank_accounts (id, school_id)`, so a genuinely cross-School reference is refused at the
database. But `CreateFeeSchedule` and `EditFeeScheduleDraft` both resolve the uuid through the
School-scoped model (`BankAccount::query()->where('uuid', …)->valueOrFail('id')`), so a foreign uuid
resolves to nothing and the request dies as a 500 rather than the 422 the rule exists to produce. The
rule is what turns it into an answer an operator can act on; the FK is the backstop.

## What would close it

One arm per route (`POST /v1/finance/fee-schedules` and `PUT …/{uuid}/draft`) posting a bank-account
uuid belonging to a second School, asserting **422** with a validation error on
`items.0.bank_account_id` — and watched red by removing `->where('school_id', ActiveSchool::id())`
from the trait, which must turn both into a 500 or a 201.

The fixture work is the reason this is not folded into U1 commit 1: it needs a second School with its
own bank account, and `testBankAccountUuid()` (tests/Pest.php:204) resolves per-school already, so the
arm is cheap but the two-School context setup is its own small piece of work. **Do not grow the
commit that raised it.**
