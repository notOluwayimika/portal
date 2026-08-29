# Decision request → Developer 1 — settlement account, and the 6 September milestone

**Raised:** 2026-08-28. **Answer needed by: 31 August 2026.**
**Blocks:** §6 step 4 (the webhook handler) entirely. Steps 2, 3, 6 and 7 proceed without it.

**Why the 31st and not "when you get to it".** The definition of done is 6 September. The 31st leaves
3 September for configuration and the deploy, and two days of slack. **After the 31st this stops
being a technical question and becomes a milestone one** — at that point it escalates to whoever owns
the milestone rather than being chased again, because an unanswered dependency past its date is a
schedule fact, not a dependency.

Three things. Only the first is a decision; the second needs an owner and a date; the third is
information about your code that should not wait for my branch to merge.

---

## The covering note — DRAFTED, NOT SENT

**I cannot send this.** It is written to be pasted into whatever channel you actually use, and the
distinction matters: until a human sends it, the state of this dependency is *"raised in a file in
the repository"*, which is not the same as *"asked"*. A document nobody has been pointed at is the
paper form of a control with no enforcement behind it.

**AND THE FILE HAD TO MOVE BEFORE EVEN THAT WAS TRUE.** This document was first written on
`feat/gateway-transaction-table` — an unpushed branch pending review of work Developer 1 is not
blocked by. So it was not "in the repository" in any sense he could reach: it was on one laptop,
behind a review of something else. It now lives on this branch, off `staging`, pushed, **precisely so
that the decision is not gated on the branch that happened to discover it.** The gateway branch can
stay local as long as review needs; this cannot.

The general form, since it is the same error one level out: *a dependency is only raised when the
person it depends on can see it.* Writing it down, committing it, and even pushing it are all
upstream of that — the message is the delivery, and the document is only the detail behind it.

> Two things that need you, both today.
>
> **1. The settlement bank account — blocking.** A gateway payment is required by the trigger to name
> a bank account, and nothing in the schema says which one; both candidate homes are your tables.
> It's a decision plus one small commit — same shape as the `origin` contract. I've suggested
> `settlement_bank_account_id` on `finance_school_settings` and I'm coding against a resolver stub
> meanwhile, so I'm not idle — but nothing that writes a gateway payment can ship until it lands.
>
> **2. Production readiness — same document, needs an owner.** Live is five migrations behind
> staging, so `origin = 'gateway'` is refused there today. The finance module is unconfigured: no
> bank accounts, no fee items, no fee schedules, no `invoice_number_prefix`, no maker/checker
> accounts. None of it is mine to fix, all of it is between "code complete" and line one of the
> definition of done. The prefix specifically must be set *before* the first invoice is issued — it's
> applied at render, so setting it later re-renders the display number of every invoice already out.
>
> **What I need:** a decision on (1) and a named owner plus date on (2) **by 31 August**. That leaves
> 3 September for configuration and deploy, and two days of slack before resumption. After the 31st
> it stops being a technical question and becomes a milestone one.
>
> **Separately, worth your eyes this week:** while fixing a collation defect on my branch I found the
> same class live in 29 comparisons across 10 existing finance triggers, two of which gate the credit-note ceiling and the
> opening-balance terminal state — string comparisons under `utf8mb4_unicode_ci` where `'X' = 'x'`.
> Reachability not established, so I've ticketed rather than claimed it. But it's your code and it's
> on production. `2026_08_17_100000`'s own docblock already names this failure mode.

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


---

## 3 · Your code, on production — 24 collation-degenerate string comparisons in finance triggers

**Not blocking me, not mine to fix, and not something to sit on until my branch merges.** Raised now
because it is live on production today and it is in your files.

Every `finance_` table is `utf8mb4_unicode_ci`, which is case- **and accent**-insensitive. Inside a
trigger that makes `NEW.status = 'approved'` match `'Approved'`, and `NEW.x <=> OLD.x` treat a
case-variant rewrite as no change at all. Measured across all 58 finance triggers, restricted to
string-typed columns (collation is meaningless on an integer): **29 genuinely bare comparisons
across 10 triggers.** (An earlier draft said 24 — my scanner missed the `<=>` operator and mis-flagged
two `BINARY`-protected comparisons. The ticket records both corrections; take the list over the
number.)

Two are worth your eyes before the rest, because they are domain comparisons on a `status` column —
the shape that admits a value the rest of the system believes impossible:

- `finance_credit_notes_insert_guard` / `_update_guard` — `NEW.status = 'approved'`, which gates the
  **credit-note ceiling check**, the arm stopping a credit note exceeding the invoice it credits.
- `finance_opening_balance_batches_no_delete_posted` / `_no_unpost` — `NEW.status = 'posted'`, which
  gates the **terminal-state guard** the enum's own docblock calls terminal *at the database*.

**Reachability was NOT established** and should not be assumed either way — it depends on whether any
writer can put a case variant into `status` at all, which is an app-layer question these triggers do
not answer. That is the first thing to measure, and note that a `status` column with no DB-level
domain guard would itself be the finding.

**And the obvious sweep does not work, which is worth knowing before you plan one.** The natural
hypothesis is that these are the triggers written before `2026_07_26_140002` recorded the #95
correction — a dated cohort, sweepable. Measured: **six of the ten are after that date, and one of
them is `2026_07_26_140001`, the sibling committed the same day.** The correction was written into one
migration's docblock and never propagated, not even next door. So the fix is not only the collation —
it is a tripwire that makes the next omission fail a build, which is what makes this different from
adding the clause and hoping.

This class is already named in your own `2026_08_17_100000` docblock, for domain arms: *omitting
`COLLATE utf8mb4_bin` from ONE arm is the quiet failure, because the other arms keep biting and the
guard still looks alive.* What my branch adds is that it applies to **freeze and write-once** arms
too — a column frozen under a case-insensitive collation is not frozen.

Full write-up, the scan, and why the scan under-reported its first time:
`docs/handoff/tickets/finance-trigger-string-comparisons-are-case-insensitive.md`.

---

## If the 31st passes with no answer

This escalates to the milestone owner rather than being chased a third time. Recorded here so that
step is a pre-agreed consequence rather than a judgement call made in the moment, and so the date
this was raised — 2026-08-28 — is on the record alongside it.
