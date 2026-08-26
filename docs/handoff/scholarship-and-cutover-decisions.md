# Scholarships and the September cutover — decisions taken

**Date:** 25 August 2026
**Status:** Decisions are settled unless marked OPEN. Anything marked OPEN is waiting on
Brookstone and must not be guessed at.
**Purpose:** So nobody re-derives this from the code, and nobody asks Brookstone the same
question twice.

---

## 0. Where things stand

Landed and merged to `staging` on 25 August; the first two are also on `main` and deployed:

- **Guardian ward authorisation** (P0, live fix). A signed-in guardian could open any student's
  results, enrollment and status in their school by editing a UUID. Eight routes, one middleware,
  `GuardianService::isWardOf()`.
- **Guardian bulk-record access** (P0, live fix). Whole-class results, whole-arm results, a full
  subject score grid, and another guardian's ward list. Two more middlewares.
- **Payment origin `gateway`** — third origin value, trigger arm, `RecordPayment` gains an origin
  and an external reference, actor nullable.
- **Parent portal read contract** — `GET /api/parent/finance/wards`, no identifier in the request.
- **`MoneyInput`** — masked naira entry, adopted at two of seven call sites.
- **Current-term fallback** — resolved the last term by `order` when none was active, which would
  have defaulted the first bulk run of the session to Summer/Term 3.

**Not yet on production:** the current-term fix. It must be deployed before the first bulk
invoice run of the new session.

**Operational, owed by a person, not code:** set Term 1 to `active` when the session starts on
5 September.

---

## 1. What a scholarship is today (verified, do not re-check)

`scholarships` holds `id, uuid, school_id, name, timestamps`, with `UNIQUE (school_id, name)`.
**No type, no status, no value, no money column, no link to Finance.**
(`database/migrations/2026_06_15_000005_create_scholarships_table.php`.)

`students.scholarship_id` — nullable FK, `nullOnDelete`
(`2026_06_15_000006_add_profile_fields_to_students_table.php:13`).

**Scholarships have zero effect on billing.** `grep -rin scholarship app/Finance` returns one hit
and it is prose in a docblock (`AllocatePayment.php:84`). Nothing in `GenerateInvoice`,
`FeeScheduleLookup`, `FeeScheduleLineMapper` or `ProcessBulkInvoiceRun` knows they exist.

Live data (dev copy `portaa10_portal`): **2 scholarships, 182 students assigned.**
No invoice line in any schema has ever carried a `discount_policy_id` — the reduction path is
proven by tests, not by use.

---

## 2. The two kinds, and why they are different mechanisms

Brookstone has two arrangements. They are **not** two flavours of one idea.

**BSS — a discount.** The school charges less. Revenue falls. The bill carries a reduction line.

**C2C — a different price list.** Not a reduction on the standard bill; an entirely separate fee
schedule. Revenue is whatever that schedule says.

> **Correction on record, so it is not repeated:** "external scholarship" was first read as
> *a third party pays the bill*. It is not. C2C is a different price list. Who pays is a separate
> question — a sponsor settling a bill is an ordinary payment with the sponsor's name in
> `payer_name`, and needs no new mechanism — but it is not how the scholarship is modelled.

---

## 3. Decisions taken

### 3.1 Reuse the discount policy engine. Do not build a second valuation.

`finance_discount_policies` already encodes every value type needed:

| Requirement | How it is already represented |
|---|---|
| Fixed amount off | `basis = 'amount'` with `value_minor` + `value_currency` |
| Percentage off | `basis = 'percent'`, `percent BETWEEN 1 AND 100` |
| Full waiver | `basis = 'percent'`, `percent = 100` |

Enforced by a database CHECK (`2026_07_26_140000_create_finance_discount_policies.php:55-63`).
The policy record also carries `requires_approval`, a `status` machine, `supersedes_policy_id`,
a no-DELETE trigger and an immutable-terms update guard.

**Decision:** a discount-kind scholarship *names* a policy — `scholarships.discount_policy_id`.
Building `PERCENTAGE / FIXED_AMOUNT / FULL_WAIVER` natively on `scholarships` was considered and
rejected: it would be a second engine with no CHECK, no approval flow, no amendment history, and
it would write invoice lines with a NULL `discount_policy_id`, discarding provenance the database
already defends.

### 3.2 Use `is_discountable`. Do not add `is_scholarship_eligible`.

`finance_fee_items.is_discountable` exists, defaults true, and is **already consumed** by
`GenerateInvoice::resolvePercentages()` (`:414`, called from `:197`), which computes the
percentage base as the signed sum of charge lines where `isDiscountable === true`. Its own comment
names the case: 50% off tuition but not transport or feeding. The bursar UI already greys
non-discountable items out.

A second flag on the same rows, with nothing keeping the two consistent, is how a discount
silently misses a line.

### 3.3 `requires_approval` must be `false` on a scholarship-backed policy.

The reduction guard trigger
(`2026_07_26_140002_add_discount_policy_to_finance_lines.php:85-88`) refuses outright:

```
IF v_requires = 1 THEN
    SIGNAL ... 'This discount policy requires per-application approval:
                apply it as a credit note, not an invoice line.'
```

A policy flagged for approval **can never be an invoice line**. If a scholarship's policy carries
it, the bulk run fails per student at the database — 182 times. The approval Brookstone wants is
on the scholarship's *value*, which is the existing discount-policy change maker-checker
(`finance.discount-policy.change.submit` / `.approve` / `.reject`, terms immutable once written).
Per-*application* approval means a credit note per student and makes bulk scholarship billing
impossible.

**This must be validated when a scholarship is linked to a policy — not discovered at bill time.**

### 3.4 `GenerateInvoice` needs no change.

It already accepts a percent-bearing `InvoiceLineSpec`, resolves it against the discountable base,
negates it (`:451`), and writes `discount_policy_id` on the line (`:296`). Both the manual and the
bulk paths call the same Action (`ProcessBulkInvoiceRun.php:346`). This feature adds no code to the
money path.

Rounding is banker's (half-to-even), `Money::percentage()` → `roundedDiv` (`Money.php:252-268`).
**Note:** the constitution says no rounding-bearing operation exists until the accounting policy is
signed. The code already rounds. Either sign the policy to match, or record that it was picked by
default.

### 3.5 The insertion point is per-enrollment, not the line mapper.

`FeeScheduleLineMapper::linesFor(FeeSchedule $schedule, int $schoolId)` takes **no student**, by a
stated ruling in its own docblock (`:30-42`). `ProcessBulkInvoiceRun` maps lines **once** at `:246`,
outside every loop, and reuses them at `:266`, `:333`, `:346`.

`InvoiceLineSpec` is `final readonly` and every transform returns a new instance, so appending a
per-student reduction spec to a copy of the base array inside the per-enrollment closure cannot
disturb the shared base. Leave the mapper alone.

### 3.6 The uniqueness sentinel — the trap that would ship silently

For C2C, fee schedules gain a **nullable** `scholarship_id`. **Do not add it to the existing unique
index.** MySQL exempts a row from a UNIQUE index if any indexed column is NULL — which would
disable uniqueness for exactly the default schedule, the one case protected today. Two active
default schedules for a class level, no error.

Fold it into the generated key with a sentinel instead:

```sql
active_scholarship_key = IF(status = 'active', COALESCE(scholarship_id, 0), NULL)
```

Same for `pending_*` and for `finance_fee_schedule_changes.open_key`. **Write a test that inserts a
second default schedule and expects error 1062, and mutation-check it by dropping the
`COALESCE(...,0)`** — the sentinel's whole purpose is invisible otherwise.

`FeeScheduleLookup::activeFor()` grows a scholarship argument where `null` must mean `IS NULL`, not
"no filter", or a default lookup starts returning a scholarship schedule.

### 3.7 The bulk run partitions. There is no second run.

Pre-flight, before the first row: read the cohort, derive the distinct scholarships present,
require an **active** schedule for every fee-schedule-kind scholarship at (term, class level,
scholarship), require the default schedule if anyone is on none or on a discount scholarship, and
**fail the whole run naming the missing coordinates** if any is absent. Not "bill the ones we can."

Map lines once per required schedule, up front — this preserves the existing one-schedule-one-
mapping invariant at partition granularity.

Per student: no scholarship → default lines. Fee-schedule kind → that schedule's lines *instead of*
the default. Discount kind → default lines **plus** one computed reduction line carrying its
`discount_policy_id`.

`finance_bulk_invoice_runs.fee_schedule_id` (pinned at `:293`) stops being true once a run uses N
price lists. Move the schedule and the scholarship onto the run **rows**.

A separate "scholarship run" after the main run must not be built: it creates a window where
students hold invoices known to be wrong, requires voiding real ledger movements for a problem that
need not exist, and "was the second pass done?" is a state nothing records.

### 3.8 `kind` backfills to unconfigured, and an unconfigured scholarship stops the run

Nothing in the existing data says whether a scholarship is a discount or a price list. `kind`
backfills to NULL, not to a guess. A student on an unconfigured scholarship makes the run **fail
loudly, naming it** — never fall through to the default schedule, which is indistinguishable from
correct behaviour on screen until a C2C parent gets a full-price invoice.

Migrate the table **in place**. `scholarships.id` must stay stable because `students.scholarship_id`
points at it. Every existing assignment stays exactly as it is — no re-derivation from names, no
re-import.

---

## 4. The cutover plan

Confirmed with Brookstone, 25 August.

**Brookstone has already issued bills for the term starting 5 September, outside the system.**

1. **Opening balances stop at the end of last term.** They carry what families owed before this
   term and **nothing else**. They do *not* include the already-issued Term 1 bill.
2. **This term is re-billed** by the bulk invoice run in the new system, with scholarships applied.
3. **Payments already received** against the old bill are entered by hand so they settle the new
   invoice.

**The order cannot vary.** A payment needs an invoice to attach to; recorded first, `RecordPayment`
banks it as account credit and the invoice still shows as owing.

`finance_opening_balance_batches` records `cutover_date` (:101) and a `term_id` FK (:102), so this
boundary can be enforced by the system rather than by memory — **but read that migration's docblock
first** to establish whether `term_id` names the term being cut over *into* or the last term
*included*. Getting it backwards builds a guard that blocks the correct run and permits the wrong
one.

### What breaks it

**The new invoice must equal the bill Brookstone already sent.** If the fee schedule differs, or a
scholarship student's manual discount was a naira amount where the policy says a percentage, the
payment already received no longer settles the invoice and that family shows a balance they do not
owe. **Run the cohort and compare against the issued bills before committing the real run.** This
affects everyone, not only the 182 — any fee schedule difference does it.

**The window between the run and the hand reconciliation.** Every parent who has already paid sees
an unpaid invoice. If the portal is open during that window, on the day the school resumes, expect
calls. Either finish reconciliation before parents can log in, or tell Brookstone the window exists
and how long it lasts.

---

## 5. Scope — what must ship before 6 September, and what follows

The full target model (scholarships moved into Finance, a four-state status machine, a
`finance_scholarship_changes` maker-checker table, a separate assignment permission, no-DELETE and
immutable-terms triggers, three ADRs) is **weeks of work**. The first bulk run is in days.

**Required before the run**, because without them children are billed wrong amounts silently:

- `scholarships.kind`, backfilled unconfigured
- `scholarships.discount_policy_id` for the discount kind, with link-time validation that the
  policy is `active` and `requires_approval = false`
- `finance_fee_schedules.scholarship_id` **with the sentinel** in the generated keys
- partitioned pre-flight that fails the whole run naming missing coordinates
- per-row schedule and scholarship recording
- the loud refusal on an unconfigured scholarship

**Follows the run:** the approval change-request table, the move into Finance, the split assignment
permission, the un-deletable triggers. The interim control is operational — freeze scholarship
assignment during cutover and audit the two configurations by eye. That is weaker than the target
and is a trade made deliberately, not by running out of time.

---

## 6. OPEN — sent to Brookstone 25 August, do not re-ask

Sent in plain language. Answers pending.

1. **What is BSS worth?** Fixed amount, percentage, percentage-up-to-a-maximum, or full waiver —
   with the number.
   *Why it blocks:* three of the four are already supported. **"Percentage up to a maximum" is
   not** — the CHECK is strictly amount XOR percent with no cap column. It would need a third
   basis, a widened constraint, and a change to `resolvePercentages()`, and it lands on the
   critical path.
2. **Does BSS come off the whole bill or only some items?** Which items exactly.
   *Why it blocks:* sets `is_discountable` per fee item.
3. **Do C2C students pay a different set of fees, or the same fees at different amounts?** With
   the actual figures.
4. **Which classes have C2C students, and how many in each?**
   *Why it blocks:* every (term, class level) with even one C2C student needs its own fee schedule
   authored **and approved through the fee-schedule maker-checker**. If C2C spans ten class levels
   that is ten schedules in eleven days. **This is the answer most likely to break the date, and
   the setup work is probably larger than the code.**
5. **Who pays a C2C student's fees** — family, or an outside body paying the school directly?
   *Blocks nothing.* Affects collections, not billing.
6. **Are BSS and C2C the only two scholarships, and how many students on each?**
   *Blocks nothing.* Two rows and 182 students are already visible in the data.
7. **Were the already-issued bills for BSS and C2C students already reduced / already on C2C
   prices?**
   *Why it blocks:* if they were issued at full price with the adjustment still to come, then the
   engine applying the discount **changes** what those families owe rather than reproducing it, and
   the reconciliation is a different exercise. This is the one that protects 182 families from
   wrong balances.

---

## 7. Sequencing risk

Creating the discount policies goes through the maker-checker: one person submits, a **different**
person approves. Policy terms are **immutable once written** — a wrong value means retire and
supersede, not an edit. That is a human dependency sitting *before* the dry-run comparison, which
sits *before* the real run, inside eleven days.

---

## 8. Rules that applied throughout, and still do

- Money is `App\Support\Money` — integer minor units plus ISO-4217, `{name}_minor` +
  `{name}_currency`. Never a float. The frontend does no monetary arithmetic; a lint enforces it.
- `school_id` is the only isolation boundary. `super_admin` bypasses authorization, never isolation.
- A reduction is a line with a **negative** `amount_minor`. There is no sign column, deliberately.
- Authorization is never commented out.
- A rule without a lint, a gate or a database constraint is decoration.
- Prove a guard by breaking it. A bite-proof that comes back green is a non-discriminating test,
  not a passing guard — say so rather than recording it as a pass.
