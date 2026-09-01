# A school can retire the account its gateway money is still settling into

**Status:** open. Surfaced (and made visible, but not closed) by
`feat/audited-bank-account-and-settlement-acts`, 2026-09-01.

## The fact

`SettlementBankAccount::forSchool()` reads
`finance_school_settings.settlement_bank_account_id` and returns it. It does not look at
`finance_bank_accounts.deactivated_at`:

```php
$accountId = SchoolFinanceSettings::query()
    ->where('school_id', $schoolId)
    ->value('settlement_bank_account_id');
```

`BankAccountController::deactivate()` does not check whether the account it is retiring is the one
settlement points at. So:

1. school configures settlement → account A;
2. somebody deactivates account A (a legitimate gesture — it is how retirement works);
3. every subsequent gateway payment still records `bank_account_id = A`.

The gateway succeeds, the ledger balances, the origin-pairing trigger is satisfied, and the money
is arriving in an account the school has said it no longer uses. Nothing refuses and nothing warns.

`SetSettlementBankAccount` refuses to **select** a deactivated account. It cannot refuse a
deactivation that happens afterwards.

## What was done instead, and why it is not the fix

`finance.bank_account_deactivated` now carries `was_settlement_account: true|false` as a property,
so an auditor reading the trail sees it at the moment it happens. That is **detection, not
prevention**, and it is deliberately not dressed as more than that.

The deactivation was not refused because a two-step swap — retire the old account, then point
settlement at the new one — is a legitimate sequence, and a naive refusal would block it while the
school is mid-change. Getting that right needs a decision, which is why this is a ticket.

## What the change probably is

One of:

- **Refuse, with an escape.** `deactivate()` refuses when the account is the current settlement
  destination, and the operator re-points settlement first. Makes the swap a fixed order rather
  than a free one; simple, and the order it enforces is the safe one.
- **Refuse at the read.** `SettlementBankAccount::forSchool()` throws when the configured account
  is deactivated, the same way it throws when none is configured. Fails closed at the point money
  would move, which is the more valuable place — but it converts a configuration mistake into a
  payment outage, so it needs the operator sentence to be excellent.
- **Both**, which is probably right: refuse the ordinary gesture, and fail closed at the read for
  every path that gets there anyway (direct SQL, a restore, a race).

Whichever is chosen needs an arm that plants the state and watches the refusal, not just a green
on the happy path.

## Scope note

`SettlementBankAccount::forSchool()` is on the money-in write path and is deliberately not
memoised, so adding a join or a second read there has a stated cost the original docblock already
argues about. Read it before changing that method.
