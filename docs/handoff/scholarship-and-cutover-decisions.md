# Scholarships and the September cutover — decisions taken

**Date:** 25 August 2026
**Revision:** 2 — rewritten after Brookstone's requirements arrived. Section 3 supersedes revision 1;
what changed and why is marked SUPERSEDED so nobody re-proposes it.
**Status:** Decisions are settled unless marked OPEN.
**Purpose:** So nobody re-derives this from the code, and nobody asks Brookstone the same question
twice.

---

## 0. Where things stand

Landed and merged to `staging` on 25 August; the first two are also on `main` and deployed:

- **Guardian ward authorisation** (P0, live fix) — a guardian could open any student's records by
  editing a UUID. Eight routes, one middleware, `GuardianService::isWardOf()`.
- **Guardian bulk-record access** (P0, live fix) — whole-class results, a full subject score grid,
  another guardian's ward list. Two more middlewares.
- **Payment origin `gateway`** — third origin value, trigger arm, `RecordPayment` gains an origin
  and an external reference, actor nullable.
- **Parent portal read contract** — `GET /api/parent/finance/wards`, no identifier in the request.
- **`MoneyInput`** — masked naira entry, two of seven call sites.
- **Current-term fallback** — would have defaulted the first bulk run of the session to Term 3.
- **Student record access logging** — guardian views and every refusal, refusals at `warning`.

**Not yet on production:** the current-term fix and the access logging. Both should ride together.
The term fix must be there before the first bulk run; the log accrues nothing until deployed.

**Owed by a person, not code:** set Term 1 to `active` when the session starts on 5 September.

---

## 1. What a scholarship is today (verified — do not re-check)

`scholarships` holds `id, uuid, school_id, name, timestamps`, `UNIQUE (school_id, name)`.
**No type, no status, no value, no link to Finance.**
(`2026_06_15_000005_create_scholarships_table.php`.)

`students.scholarship_id` — nullable FK, `nullOnDelete`
(`2026_06_15_000006_add_profile_fields_to_students_table.php:13`).

**Zero effect on billing.** `grep -rin scholarship app/Finance` returns one hit and it is prose
(`AllocatePayment.php:84`).

Live data (dev copy): **2 scholarships, 182 students assigned.** No invoice line in any schema has
ever carried a `discount_policy_id` — the reduction path is proven by tests, not by use.

---

## 2. The two schemes, as Brookstone described them

| | **BSS** | **C2C** |
|---|---|---|
| Awarded by | The school | An external community organisation |
| Who pays | Parent, reduced amount | The sponsoring organisation |
| Fee basis | Standard schedule, discounted | A different set of fee items entirely |
| Billed by | The bulk invoice run | **Manual invoicing — excluded from the run** |
| Payment | On platform | Off platform, reconciled by hand |
| Volume | Varies | ~70 students |

Both are assigned at admission through the existing scholarship field on student upload. Neither
changes often once assigned.

> **Two corrections on record, so they are not repeated.** "External scholarship" was first read as
> *a third party pays the bill*, then as *a different price list*. Brookstone's answer is **both** —
> a different fee basis and the sponsor as payer — and the fee-basis half is handled by manual
> invoicing rather than by the billing engine.

---

## 3. Design

### 3.1 SUPERSEDED — `scholarships.discount_policy_id`

Revision 1 said a scholarship names one discount policy. **That is wrong for BSS.**

BSS is configured **per student**: a percentage from 0 to 100, and a scope. A policy per student
would be roughly a hundred policies, each through the maker-checker. Do not propose the single FK
again.

### 3.2 BSS — a per-student award, pointing at a shared policy

The reduction guard is a hard database constraint
(`2026_07_26_140002_add_discount_policy_to_finance_lines.php:62-101`): a non-charge line **must**
cite a policy that exists, is `active`, has `requires_approval = false`, and belongs to the same
school. So a per-student percentage cannot bypass policies and live on the student alone.

**The shape that satisfies both:** one discount policy per distinct **(percentage, scope)** pair,
authored once and reused; each BSS student's award points at one of them. Four percentages across
two scopes is eight policies, not a hundred. Provenance survives on every invoice line, the trigger
is satisfied, and the maker-checker stays proportionate.

The award itself — which student, which policy — is per student and needs somewhere to live. It is
**not** `students.scholarship_id`, which says only *which scheme*.

### 3.3 Scope, and the correction it forces

BSS has two per-student dimensions:

1. **Percentage** — 0 to 100, always a percentage, never a flat amount.
2. **Scope** — discountable items only, **or** the whole invoice.

**CORRECTION to revision 1: `GenerateInvoice` DOES need a change.** Revision 1 said it needed none.
That was true when scope was fixed. `resolvePercentages()` (`:414`) computes the base as charge
lines where `isDiscountable === true`. "Whole invoice" scope needs the base to be *all* charge
lines. That is a second base mode on the money path, and `finance_discount_policies` has no column
to express which mode a policy uses.

`is_discountable` cannot carry it: it lives on `finance_fee_items`, is shared by every student
billed from that schedule, and therefore cannot vary per award.

### 3.4 Still true from revision 1

**Reuse the discount policy engine.** `finance_discount_policies` already carries `basis`
amount|percent with an exclusive CHECK (`:55-63`), `requires_approval`, a `status` machine,
`supersedes_policy_id`, a no-DELETE trigger and an immutable-terms guard. Do not build a second
valuation engine on `scholarships`.

**`requires_approval` must be `false`** on any policy a scholarship uses. The guard's third arm
refuses an approval-requiring policy as an invoice line outright — the bulk run would fail per
student, at the database. The approval Brookstone wants is on the *value*, which is the existing
discount-policy change maker-checker. **Validate this when the award is created, not at bill time.**

**Rounding** is banker's, `Money::percentage()` → `roundedDiv` (`Money.php:252-268`). The
constitution says no rounding-bearing operation exists until the accounting policy is signed; the
code already rounds. Sign the policy to match, or record that it was picked by default.

**The insertion point is per-enrollment.** `FeeScheduleLineMapper::linesFor()` takes no student by a
stated ruling (`:30-42`); `ProcessBulkInvoiceRun` maps once at `:246` and reuses at `:266`, `:333`,
`:346`. `InvoiceLineSpec` is `final readonly`, so appending a per-student reduction spec to a copy
of the base array inside the per-enrollment closure cannot disturb the shared base. Leave the mapper
alone.

### 3.5 REMOVED — the fee-schedule scholarship dimension

Revision 1 designed a nullable `fee_schedules.scholarship_id` with a generated-key sentinel, and a
partitioned bulk run.

**Brookstone will not maintain a separate C2C fee schedule.** That removes the whole piece: no
`scholarship_id` on fee schedules, no sentinel, no uniqueness rework, no partitioned pre-flight.
It was the most dangerous work on the list and it is now unnecessary.

**Kept only as a warning, in case anyone revives it:** MySQL exempts a row from a UNIQUE index if
any indexed column is NULL, so adding a nullable `scholarship_id` straight into
`finance_fee_schedules_active_unique` would silently disable uniqueness for the **default**
schedule — the one case protected today. The fix is a sentinel inside the generated key,
`IF(status='active', COALESCE(scholarship_id, 0), NULL)`, plus a test that inserts a second default
schedule and expects 1062, mutation-checked by dropping the `COALESCE`.

### 3.6 C2C — excluded from the run, billed manually, sponsor pays

- **The bulk run must exclude C2C students entirely.** Without this, seventy sponsored students are
  billed standard school fees.
- They are billed through **bulk manual invoicing** (§4), per session, one bill covering the session.
- The **sponsoring organisation** is the payer. One guardian account per organisation, linked to
  many students. Parents remain guardians in parallel.
- Payment is off platform. The school records the payment, then **allocates it across the C2C
  students** — e.g. ₦50m spread over students at roughly ₦1m each.
- Model the payer generically as a **sponsor**, with C2C as the first instance. Other organisations
  may follow.

### 3.7 `kind` backfills to unconfigured, and stops the run

Nothing in the data says which scheme a scholarship is. `kind` backfills to NULL, never to a guess.
A student on an unconfigured scholarship makes the run **fail loudly, naming it** — never fall
through to the default schedule, which is indistinguishable from correct behaviour on screen until a
sponsored parent receives a full-price invoice.

Migrate the table **in place**; `scholarships.id` must stay stable because `students.scholarship_id`
points at it. Every existing assignment stays exactly as it is.

---

## 4. Bulk manual invoicing — a platform feature, not a C2C feature

Today manual invoices are one student at a time (typical use: "damaged laptop, ₦10,000"). Wanted:
the same flow applied to many students at once — line items with description, amount and destination
account; students chosen by filtering on scholarship status and class, then ticking individuals or
taking the whole filtered set.

**This is what produces the C2C bills**, and it is why no second fee schedule structure is needed.
It is also a substantial feature in its own right.

---

## 5. Scope — what must ship before 6 September

**On the term-billing critical path:**

1. **Exclude C2C students from the bulk run.** Small, and non-negotiable.
2. **Per-student BSS discount, shown as a line item.** Needs the award model, the shared
   (percentage, scope) policies, and the scope change to `resolvePercentages()`.
3. `scholarships.kind` backfilled unconfigured, with the loud refusal.

**Not on the critical path, and each is a real feature:** the BSS management page, the sponsor
management page with per-group totals, the sponsor guardian account, manual allocation of a sponsor
payment across students, and bulk manual invoicing.

**Decide deliberately, before the 5th:** if bulk manual invoicing does not land, someone raises
roughly seventy C2C invoices one at a time. That is about a day of bursar work — ugly, not blocking.
Discovering it on the day is the failure mode.

**Deferred, from revision 1 and unchanged:** the approval change-request table, moving scholarships
into Finance, the split assignment permission, the un-deletable triggers. Interim control is
operational — freeze scholarship assignment during cutover and audit the configurations by eye.

---

## 6. The cutover plan

Confirmed with Brookstone. **They have already issued bills for the term starting 5 September,
outside the system.**

1. **Opening balances stop at the end of last term.** They carry prior arrears and **nothing else** —
   not the already-issued Term 1 bill.
2. **This term is re-billed** by the bulk run, with BSS discounts applied and C2C excluded.
3. **Payments already received** are entered by hand so they settle the new invoices.

**The order cannot vary.** A payment needs an invoice to attach to; recorded first, `RecordPayment`
banks it as account credit and the invoice still shows as owing.

`finance_opening_balance_batches` records `cutover_date` (:101) and `term_id` (:102), so the boundary
can be enforced by the system — **but read that migration's docblock first** to establish whether
`term_id` names the term being cut over *into* or the last term *included*. Backwards, the guard
blocks the correct run and permits the wrong one.

### What breaks it

**The new invoice must equal the bill already sent.** If the fee schedule differs, or a BSS
student's manual percentage differs from the configured one, the payment already received no longer
settles the invoice and that family shows a balance they do not owe. **Run the cohort and compare
against the issued bills before committing.** This affects everyone, not only scholarship students.

**The window between the run and the hand reconciliation**, during which every parent who has
already paid sees an unpaid invoice. Either finish reconciliation before parents can log in, or tell
Brookstone the window exists and how long it lasts.

---

## 7. Questions — answered and still open

**Answered by Brookstone, 25 August:**

- BSS is **always a percentage**, 0–100%, **per student**, never a flat amount. Percentage-with-a-cap
  is not required — the hidden work revision 1 feared does not arise.
- BSS scope is per student: discountable items only, or whole invoice.
- C2C students pay a **different set of fee items**; the school will **not** maintain a schedule for
  them.
- The sponsor pays the school directly, off platform, per session.
- ~70 C2C students.

**Answered from the code, so nobody asks again:**

- *Does the discountable flag sit on the fee item globally, or per schedule?* On
  `finance_fee_items`, which belong to a fee schedule — so per item within a schedule. Their
  preferred answer is already the situation.
- *Should the discount show as one aggregate line or one per item?* **One aggregate line.**
  `resolvePercentages()` emits one reduction line per percentage spec supplied, so an aggregate is
  both the natural output and the cheaper one.

**OPEN — needs Brookstone:**

- **When a sponsor payment is allocated across students, is it even (total ÷ students) or manual per
  student?** Partial payments make manual the safer default. This blocks the allocation feature, not
  the run.
- **Were the already-issued bills for BSS students already reduced?** If they were issued at full
  price with the adjustment still to come, the engine applying the discount **changes** what those
  families owe rather than reproducing it, and reconciliation is a different exercise. *This is the
  one that protects those families from wrong balances.*

---

## 8. Watch this

A sponsor guardian account linked to seventy students is a guardian with seventy wards. It works
with the ownership guard shipped on 25 August, and it will see seventy families' invoices in the
parent portal — presumably intended, but it is an unusually broad account, the read contract will
return seventy wards in one response, and every access it makes lands in the audit log.

---

## 9. Sequencing risk

Creating the discount policies goes through the maker-checker: one person submits, a **different**
person approves. Policy terms are **immutable once written** — a wrong value means retire and
supersede, not an edit. That human dependency sits *before* the dry-run comparison, which sits
*before* the real run.

---

## 10. Rules that applied throughout, and still do

- Money is `App\Support\Money` — integer minor units plus ISO-4217. Never a float. The frontend does
  no monetary arithmetic; a lint enforces it.
- `school_id` is the only isolation boundary. `super_admin` bypasses authorization, never isolation.
- A reduction is a line with a **negative** `amount_minor`. No sign column, deliberately.
- Authorization is never commented out.
- A rule without a lint, a gate or a database constraint is decoration.
- Prove a guard by breaking it. A bite-proof that comes back green is a non-discriminating test, not
  a passing guard — say so rather than recording it as a pass.
