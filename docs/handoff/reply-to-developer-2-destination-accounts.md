# Reply — the destination-account question, and the blocker

**To:** Developer 2 (payments)
**From:** Developer 1
**Date:** 29 August 2026
**Re:** your note of 27 August

All three decided. Item 1 is accepted with one correction to the FK shape. Item 2 is accepted in
principle and changed in shape, and its timing turns on a fact neither of us has. Item 3 is accepted
and deferred. Your closing paragraph turned out to be the most important thing in the note and has
been acted on.

---

## 1. The settlement account — accepted, build against this

**`finance_school_settings.settlement_bank_account_id`, nullable.** Your reasoning against a boolean
on `finance_bank_accounts` is right: two rows flagged and a uniqueness guard to write, for a datum
that is per-school configuration with exactly one value.

**One correction to the FK.** You asked for composite *"like its ten siblings"* —
`finance_school_settings` is a **top-level** Finance table and owns `school_id` directly with a plain
RESTRICT FK; the composite pattern belongs to child tables. But composite is still the right answer
here, for a different reason: the **target** is school-scoped. A plain single-column FK to
`finance_bank_accounts (id)` would let one school's settings name another school's account, and
gateway money would settle to the wrong school with nothing refusing it. So:
`(settlement_bank_account_id, school_id)` referencing `finance_bank_accounts (id, school_id)`.

**Where that convention is actually written down, because I got this wrong first:**
`2026_08_10_120000`'s docblock — *"Ten existing finance FKs to a School-owned parent are
(child_id, school_id) -> parent(id, school_id); there is not one plain single-column FK among
them."* An earlier draft of this reply cited `docs/finance-data-ownership.md`, copied from
`2026_07_21_100000`'s docblock. **That doc says nothing about composite keys** — its Part 7 gives six
day-one rules, of which the one that applies here is *every Finance FK is ON DELETE RESTRICT*. If you
went looking there on my say-so, that is why you found nothing. The migration cites the right source.

The unique key the composite reference needs, `finance_bank_accounts_id_school_unique` on
`(id, school_id)`, already exists — `2026_08_10_120000` added it for the payments and fee-item FKs.

**Your resolver contract, so nothing on your side changes when the column lands:**

```php
forSchool(int $schoolId): int
```

Returns the configured account id. Throws when unset — a `BusinessRuleException` naming the school by
id and saying the settlement account has not been configured, not a null and not a fallback. There is
no sensible default: guessing an account sends real money somewhere nobody chose.

Keep the resolver as your seam. When the column lands only its body changes.

**Your Paystack pseudo-account rejection is right and the reasoning is worth keeping on the record.**
Identity on `finance_bank_accounts` is `bank_name` + `account_number`, frozen at creation because a
bursar matches it against a statement. A Paystack pot has no account number in that sense, so the row
would fabricate identity on a deliberately immutable field. Naming the real account Paystack settles
into keeps reconciliation working, and the in-transit period is what your settlement reference and
date are for.

---

## 2. The invoice-line destination — accepted, but not NOT NULL, and the timing turns on one fact

**Your argument is correct.** Both deferral reasons in `2026_08_10_120000` have expired,
`fee_item_id` is nullable with no foreign key pointing at a mutable row, and a live lookup answers
*"where would this go today"* rather than *"where was it destined"*. On an append-only table that is
unfixable afterwards, and you are right that the window opens and does not close.

**But NOT NULL cannot ship, and you say why yourself.** A reduction line has no destination, the
relationship to the charge it offsets is not modelled, and the BSS scholarship work just made
reduction lines common rather than rare. A NOT NULL column with no defensible answer for an entire
line kind is not ready to be written, and settling that properly is a conversation rather than a
week.

**The shape that gets the dated value without the unanswered question:** a **nullable** column, with a
**trigger** requiring it on charge lines and permitting null on reductions. That is exactly the shape
of the reduction guard already in `2026_07_26_140002`. Every bulk-run line is a charge line, so the
snapshot covers 100% of what the first run issues, and the reduction question can be settled at
leisure without holding the column hostage.

Your correction to S11's premise stands and is the load-bearing part: the column does not need every
line to have a fee item, only a **chosen destination**. Schedule-derived lines inherit at issue;
manual lines require a selection.

**What decides whether this is urgent: how many bank accounts Brookstone actually operates, and
whether different fees route to different ones.** If there is one account, every line's destination
is trivially knowable and the snapshot is good hygiene rather than a deadline. If fees split, you are
right and it has to land before the first run. That question is being asked alongside the bank
account the cutover needs anyway — you will have the answer at the same time we do.

---

## 3. Apportionment — waterfall, and deferred

**Agreed, and your strongest argument is the last one:** it matches the grain of the module.
`applyCreditForward` and `AllocationProposal` both walk in order taking `min()` draws; this is the
same shape one level down. Pro-rata needs a ratio-weighted split `App\Support\Money` does not have —
`allocate(int $parts)` is an equal split — and yields a remainder on every payment plus fractions of
every line instead of clean settled and unsettled ones.

Your rejection of "only apportion once fully paid" is also right: part-payment carried into arrears is
the normal case here, and money that is never attributed at all is worse than money attributed
imperfectly.

Nothing here is needed before cutover. You are right that the priority order and the gateway
fee-bearer are the same kind of question for the same person, and they should be asked once, together.

---

## 4. Your closing paragraph was the most important part of the note

**Confirmed, measured on production on 28 August. All four zero:**

```
bank_accounts     0
active_schedules  0
fee_items         0
prefixes_set      0
```

The finance module has never been configured on production. No fee items means no fee schedule means
the bulk invoice run has nothing to price — so the entire scholarship chain, and everything I have
built for three days, sits on top of something that does not exist.

It is now **Section 0** of `docs/runbooks/bss-cutover-runbook.md`, ahead of the deploy, and it has an
owner and a date. Four things, in order: two user accounts (a maker and a checker, and they cannot be
the same person — `assignRole` throws on segregation of duties), the invoice number prefix, the bank
accounts, then the fee items and schedule entered and approved.

**You were right that the prefix is dated**, and the runbook says so in your terms: the number is
stored bare and the prefix applied at render, so setting it late re-renders the display number of
every invoice already issued.

Raising it as an aside at the end of a note about something else is how it nearly stayed unnoticed.
It was the finding.

---

## What happens next

Item 1's migration is next on my list and is small. Items 2 and 3 are recorded and waiting on the two
business questions, which are being asked this week alongside the cutover's own.

Nothing in your scope has moved: §8.2 stays as written, §8.3 stays out of scope.
