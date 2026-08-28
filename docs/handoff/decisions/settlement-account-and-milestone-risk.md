# Decision request → Developer 1 — settlement account, and the 6 September milestone

**Raised:** 2026-08-28. **Answer needed:** today.
**Blocks:** §6 step 4 (the webhook handler) entirely. Steps 2, 3, 6 and 7 proceed without it.

Two things, and only the first is a decision. The second is a risk that needs an owner and a date.

---

## 1 · There is no settlement bank account to name, and the schema enforces that one is named

The addendum answered §11 decision 1 in the affirmative: a gateway payment names a bank account, the
settlement account. That answer is now **enforced** — the origin pairing trigger requires
`origin = 'gateway' AND bank_account_id IS NOT NULL`, and `RecordPayment::handle` takes
`int $bankAccountId`, non-nullable, no default.

**Nothing in the schema says which account.** Verified 2026-08-27, re-confirmed 2026-08-28:

- `finance_bank_accounts` carries `label`, `bank_name`, `account_number`, `account_name`,
  `deactivated_at`. **No default flag, no settlement flag.**
- `finance_school_settings` carries `invoice_number_prefix` and nothing else.
- `grep -rn settlement app/ database/ config/ routes/` returns docblock prose and
  `PaymentAllocation::settlementKind()`. **No configuration anywhere.**
- The only existing writer of a gateway payment is a test, sourcing its account from
  `testBankAccountId()` — an arbitrary account. Nothing in production code does the equivalent.

So the webhook handler has **no defensible way to choose the id the schema obliges it to pass**. Every
place that datum could live is on the §3 do-not-modify list, which is why this is your call and not
mine.

**What I need:** a decision, and the one small commit that follows it.

**Suggested shape** — yours to overrule: a nullable `settlement_bank_account_id` FK on
`finance_school_settings`. It is per-school configuration and there is exactly one such account. A
boolean flag on `finance_bank_accounts` invites two rows flagged and needs its own uniqueness guard.

**What I am doing meanwhile, so this is not a hard stop:** coding against a one-method resolver,
`SettlementAccount::forSchool(int $schoolId): int`, which throws until the column exists. When you land
it, only the resolver body changes and the webhook path is already written and tested against a stub.

---

## 2 · Milestone risk — not a decision, but it needs an owner and a date

Discovered 2026-08-27 on a clone of live. **None of this is anyone's fault and none of it is code**;
all of it sits between "the code is finished" and the §14 definition of done, which reads *"a guardian
signs in, sees what each ward owes, and settles an invoice through the gateway."*

**Live is five migrations behind `staging`, and the gap is the payment axis.** Applied to
`2026_08_18_100000`. Unapplied: the allocation-amount guard, the allocation-provenance pairing, **the
gateway origin migration**, and the two scholarship/sponsored migrations.

The blunt consequence: **`origin = 'gateway'` is refused on production today.** Live's origin-pairing
triggers carry the two-arm body — verified by comparing `ACTION_STATEMENT` against migration source,
not by name. A gateway payment written there raises 1644. The origin contract the addendum calls
delivered is delivered to `staging`, not to the environment a parent will pay into.

**The finance module is unconfigured on production.** Every `finance_*` table is empty. In order:

- **no bank account exists at all**, which turns §1 above from "no mechanism to choose one" into "no
  candidate exists";
- **no `invoice_number_prefix`**, so the first live invoice renders as a bare `000001` rather than
  `BSS-000001`. It must be set **before the first invoice is issued** — the number is stored bare and
  the prefix applied at render, so configuring it afterwards silently re-renders every historical
  invoice's display number;
- **no fee schedules or fee items**, so no invoice can be generated at all — and with nothing owed,
  there is nothing for a guardian to pay.

**There are no maker/checker accounts on live.** The whole approvals surface is unusable there today.
Two consequences for this workstream: §11 decision 4 ("what happens to a payment against an invoice
voided in between") is currently unanswerable in practice because nothing can be voided; and any
runbook step that says "void it" has no operator who can.

Healthy, for contrast: `finance:audit-duty-separation` on live returns the 10 accepted `result.*`
findings already in `duty-separation-baseline.txt` and **zero finance findings** — the invariant the
command hard-codes as never-baselineable.

**Ten days is enough for the configuration and the deploy. It is not enough to discover them on
5 September.**

---

## Still open from §11, unchanged

Decisions **2** (who bears the gateway fee), **3** (is partial payment permitted) and **4** (a payment
against an invoice voided in between). All three change the screen or the ledger. Decision 4 is the one
that interacts with §2 above.

And two deviations from written contract on `feat/gateway-transaction-table`, flagged rather than
assumed — both are mine, both are visible in the branch:

- **§6 specifies idempotency as "a duplicate webhook attempts an insert and hits the constraint".**
  What is built is a compare-and-swap on `payment_id`, because the row already exists from initiation,
  so a duplicate delivery is an UPDATE and there is no insert for a constraint to catch. Still a
  database primitive, still never a check-then-insert — but it is not what the contract says.
- **`invoice_id` is REQUIRED on the gateway transaction, which answers §11 decision 5** (one invoice
  per checkout, not several) by building. The table is not append-only so the grain can be widened by
  an ordinary migration, but the decision was yours and I have taken it by default.
