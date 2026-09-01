# Changing where a school's money settles takes one person and no approval

**Status:** open. **This is a governance gap deliberately left open for one week, not an
oversight** — it was identified, argued and scoped out of
`feat/audited-bank-account-and-settlement-acts` on 2026-09-01, on the reasoning below.

## The fact

`finance_school_settings.settlement_bank_account_id` decides where every naira of a school's
gateway fee income lands (`SettlementBankAccount::forSchool()`, and the `gateway` arm of
`finance_payments_origin_pairing_bi`, which requires a `bank_account_id`).

As of the branch above it can be changed by one person, in one gesture, with no second signature:

```
php artisan finance:set-settlement-account --school=2 --account=<uuid> --actor=<user>
```

The change is now **recorded** — actor and timestamp on the settings row, and a
`finance.settlement_account_changed` entry in `activity_log` carrying from → to, classified
`critical` and readable by `internal_auditor`. It is not **approved**.

## Why it was scoped out rather than built

Approval here is not a flag. It is:

- a request table (`finance_settlement_change_requests`, or the settlement case added to an
  existing request shape),
- a policy deciding who the checker is — almost certainly the Executive Director, by the same
  argument that put refunds there (`docs/handoff/brookstone-answers-31-august.md` §1),
- a **sixth approval feed**, with the count tests, the duty-separation pair
  (`ApprovalAbility::CHECKER_SEGMENTS`, `DutySeparation::enforcedPairs()`) and the maker/checker
  permission triple that every other approval in this module carries,
- and the screen to work it from, which `finance_school_settings` does not have at all
  (`app/Support/SchoolDay.php:24 (SchoolDay)` — "no screen to set it from").

Brookstone supply the production account this week. **An audited unapproved change is strictly
better than the unaudited invisible one that existed before**, and it is the half that can be
delivered before Friday. That is the trade, stated rather than left to be inferred.

## What the gap actually is

Between now and the approval slice, a single holder of shell access can re-point a school's
settlement destination and the system will comply. What stands between that and undetected
diversion is:

- the `critical` tier on `finance.settlement_account_changed`, so it surfaces in the severity
  filter rather than sitting in `info`;
- `internal_auditor` being able to read it at all, which required
  `2026_09_01_120000_grant_internal_auditor_activity_log_view_all` and moving the audit feed off
  the `academic_data.view` route gate;
- the composite foreign key `(settlement_bank_account_id, school_id)`, which makes another
  school's account non-existent rather than merely unauthorised.

Detection, in other words. Not prevention.

## What the change is

1. Decide the checker. Executive Director is the presumption; it is not decided here.
2. A request row: school, proposed account, maker, reason, status, decided_by, decided_at.
3. The maker/checker permission pair under `finance.` so
   `DutySeparation::enforcedPairs()` sees it, plus the grants-map entries and a convergence
   migration for the roles that already exist.
4. `SetSettlementBankAccount` becomes the APPLY step, callable only from an approved request. The
   command keeps working as the maker's surface, or is replaced by the screen.
5. The count tests every other approval feed carries.

## Not proposed here

Whether the same treatment is owed to `finance.bank-account.manage` — creating and retiring
accounts — is a separate question. The routes' own docblock argues a bank account "is a description
rather than a decision" and needs no second signature; the settlement selection is the decision,
and that argument does not cover it.
