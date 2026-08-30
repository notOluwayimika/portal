# Scholarships and the September cutover — decisions taken

**Date:** 25 August 2026
**Revision:** 4 — rev 3 was Brookstone's clarifications of 25 August (rev 1 proposed a single
scholarship-to-policy FK; rev 2 removed the C2C fee schedule; rev 3 restored it as the *target*
and settled scope). Rev 4 adds §11, Brookstone's 29 August ruling on mid-term charges, and §12,
the fee-item catalog direction.
Superseded decisions are marked so nobody re-proposes them.
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
- **Payment origin `gateway`**, **parent portal read contract**, **`MoneyInput`** (2 of 7 sites),
  **current-term fallback**, **student record access logging**.

**Not yet on production:** the current-term fix and the access logging. The term fix must be there
before the first bulk run; the log records nothing until deployed. Ship them together.

**Owed by a person:** set Term 1 to `active` when the session starts on 5 September.

---

## 1. What a scholarship is today (verified — do not re-check)

`scholarships` holds `id, uuid, school_id, name, timestamps`, `UNIQUE (school_id, name)`. **No type,
no status, no value, no link to Finance.** `students.scholarship_id` is a nullable FK with
`nullOnDelete`. `grep -rin scholarship app/Finance` returns one hit and it is prose.

Live data: **2 scholarships, 182 students.** No invoice line in any schema has ever carried a
`discount_policy_id` — the reduction path is proven by tests, not by use.

---

## 2. The two schemes

| | **BSS** | **C2C** |
|---|---|---|
| Awarded by | The school | NNPC / Renaissance JV |
| Who pays | Parent, reduced amount | The sponsoring organisation |
| Fee basis | Standard schedule, discounted | A different set of fee items |
| Billed | Bulk run, per term | **Once per session, one collective figure** |
| Payment | On platform | Off platform, reconciled by hand |
| Volume | Varies | ~70 students |

> **Corrections on record.** "External scholarship" was read first as *a third party pays*, then as
> *a different price list*. It is **both**. And rev 2 recorded that Brookstone would not maintain a
> C2C fee schedule; they have since said they would, ideally — see §3.6.

---

## 3. Design

### 3.1 SUPERSEDED — `scholarships.discount_policy_id`

Rev 1 said a scholarship names one discount policy. **Wrong for BSS**, which is configured per
student. A policy per student is roughly a hundred policies through a maker-checker. Do not propose
it again.

### 3.2 BSS — a per-student award pointing at a shared policy

The reduction guard (`2026_07_26_140002_add_discount_policy_to_finance_lines.php:62-101`) requires
every non-charge line to cite a policy that exists, is `active`, has `requires_approval = false`,
and belongs to the same school. A per-student percentage cannot bypass policies.

**The shape:** one policy per distinct **(percentage, base)** pair, authored once and reused; each
BSS student's award points at one. Four percentages across two bases is eight policies, not a
hundred. Provenance survives on every line, and the maker-checker stays proportionate.

The award — which student, which policy — is per student. It is **not** `students.scholarship_id`,
which says only which scheme.

### 3.3 Scope — TWO AXES, and I collapsed them once. Do not repeat that.

**Axis one — which items *can* be discounted.** Set once, per fee schedule, when it is authored.
This is `finance_fee_items.is_discountable`. **It already exists**, defaults true, is already in the
bursar UI, and is already consumed by `GenerateInvoice::resolvePercentages()` (`:414`). Every
student billed from that schedule sees the same items marked. **Settled: no new flag, no new
screen, no code.**

**Axis two — whether a student's percentage applies to those items only, or to the whole bill.**
Set **per student**, as part of their BSS award. Brookstone's words: 100% on discountable items
leaves the child paying transport; 100% of total fees leaves them paying nothing.

**CORRECTION, and it is the final position: `GenerateInvoice` DOES need a change.**
`resolvePercentages()` computes the base as charge lines where `isDiscountable === true`. The
whole-bill base needs a second mode, chosen by the award, and
`finance_discount_policies` has no column to express which mode a policy uses.

*(I reversed this once, on the mistaken reading that settling axis one settled axis two. It does
not.)*

### 3.4 Still true

**Reuse the discount policy engine.** `finance_discount_policies` carries `basis` amount|percent
with an exclusive CHECK (`:55-63`), `requires_approval`, a `status` machine,
`supersedes_policy_id`, a no-DELETE trigger and an immutable-terms guard. Do not build a second
valuation engine on `scholarships`.

**`requires_approval` must be `false`** on any policy a scholarship uses — the guard's third arm
refuses an approval-requiring policy as an invoice line outright, so the run would fail per student
at the database. The approval Brookstone wants is on the *value*, which the discount-policy change
maker-checker already provides. **Validate this when the award is created, not at bill time.**

**Rounding** is banker's, `Money::percentage()` → `roundedDiv` (`Money.php:252-268`). The
constitution says no rounding-bearing operation exists until the accounting policy is signed; the
code already rounds. Sign it, or record that it was picked by default.

**The insertion point is per-enrollment.** `FeeScheduleLineMapper::linesFor()` takes no student by a
stated ruling (`:30-42`); `ProcessBulkInvoiceRun` maps once at `:246`, reuses at `:266`, `:333`,
`:346`. `InvoiceLineSpec` is `final readonly`, so appending a per-student reduction spec to a copy
of the base array inside the per-enrollment closure cannot disturb the shared base.

### 3.5 The C2C fee schedule — target end state, NOT the first increment

**The ideal, and the agreed end state:** C2C gets its own fee schedule per class, and the bulk run
selects a student's schedule by their scholarship.

**Not before 6 September**, for a reason that is not about our code: Brookstone must author **and
approve** a fee schedule for every class holding a C2C student, each through the fee-schedule
maker-checker. **Nobody has counted those classes.** Seventy students could be twelve schedules, in
the same eleven days as everything else.

**When it is built, the trap is the uniqueness index.** MySQL exempts a row from a UNIQUE index if
any indexed column is NULL, so adding a nullable `fee_schedules.scholarship_id` straight into
`finance_fee_schedules_active_unique` would silently disable uniqueness for the **default**
schedule — the one case protected today. Fold it into the generated key with a sentinel:
`IF(status='active', COALESCE(scholarship_id, 0), NULL)`. Same for `pending_*` and
`finance_fee_schedule_changes.open_key`. Write a test that inserts a second default schedule and
expects 1062, and mutation-check it by dropping the `COALESCE`. `FeeScheduleLookup::activeFor()`
gains a scholarship argument where `null` means `IS NULL`, not "no filter".

**The first increment is the same partition with a cheaper action.** Exclude the sponsored
partition instead of billing it from its own schedule. The pre-flight, the per-student lookup and
the run-row recording are identical, so nothing is thrown away when the alternate-schedule arm
lands.

### 3.6 C2C — yearly, collective, manual

- **The bulk run excludes C2C students entirely.** Without it, seventy sponsored students are billed
  standard school fees on a run that otherwise looks successful.
- Billed **once per session**, not per term. The school totals the C2C students' fees, emails the
  organisation a single figure (₦15m–₦17m), and the organisation pays off platform.
- **Each C2C student still needs their own invoice**, or there is nothing for the sponsor's payment
  to be allocated against and no student balance to show. The totalling view is what gets emailed;
  the invoices are what the money settles.
- Those invoices are **session-scoped**, unlike everything the bulk run produces. That is fine — a
  manual invoice is not a scheduled one, so the one-active-scheduled-invoice-per-episode rule does
  not bite — but it is a difference someone will trip over.
- The payer is the **sponsor**. One guardian account per organisation, linked to many students;
  parents remain guardians in parallel. Model it generically — other organisations may follow.

### 3.7 `kind` backfills to unconfigured, and stops the run

Nothing in the data says which scheme a scholarship is. `kind` backfills to NULL, never a guess. A
student on an unconfigured scholarship makes the run **fail loudly, naming it** — never fall through
to the default schedule, which is indistinguishable from correct behaviour on screen until a
sponsored parent receives a full-price invoice.

Migrate in place; `scholarships.id` must stay stable because `students.scholarship_id` points at it.
Every existing assignment stays exactly as it is.

---

## 4. Bulk manual invoicing — a platform feature, and the C2C mechanism

Today manual invoices are one student at a time ("damaged laptop, ₦10,000"). Wanted: the same flow
over many students — line items with description, amount and destination account; students chosen by
filtering on scholarship and class, then ticking individuals or taking the whole filtered set.

It is what produces the C2C session bills, and Brookstone want it independently for ad-hoc charges.
It earns its place twice, and unlike the C2C fee schedule it requires **no configuration work from
Brookstone at all**.

---

## 5. Order of work

1. **Foundation + exclusion.** `scholarships.kind`, backfilled unconfigured; the run refuses loudly
   on an unconfigured scholarship; sponsored students excluded. Needed by both C2C paths, depends on
   no outstanding answer, and stops the worst failure.
2. **Per-student BSS discount**, shown as one aggregate line item. The award model, the shared
   (percentage, base) policies, and the second base mode in `resolvePercentages()`.
3. **Bulk manual invoicing.** Delivers C2C billing with no Brookstone configuration, and is wanted
   on its own merits.
4. **C2C fee schedules** — the target end state, after cutover, with the sentinel done carefully.

**Deferred and unchanged:** the approval change-request table, moving scholarships into Finance, the
split assignment permission, the un-deletable triggers. Interim control is operational — freeze
scholarship assignment during cutover and audit the configurations by eye.

---

## 6. The cutover plan

**Brookstone have already issued bills for the term starting 5 September, outside the system, and
those bills ALREADY SHOW the BSS discount removed.** Those families were not billed full price.

1. **Opening balances stop at the end of last term** — prior arrears and nothing else.
2. **This term is re-billed** by the bulk run, BSS applied, C2C excluded.
3. **Payments already received** are entered by hand so they settle the new invoices.

**The order cannot vary.** A payment needs an invoice to attach to; recorded first, `RecordPayment`
banks it as account credit and the invoice still shows as owing.

`finance_opening_balance_batches` records `cutover_date` (:101) and `term_id` (:102), so the boundary
can be enforced — **but read that migration's docblock first** to establish whether `term_id` names
the term cut over *into* or the last term *included*. Backwards, the guard blocks the correct run
and permits the wrong one.

### What breaks it

**The engine's figure must equal the bill already sent.** Now that we know those bills carry the
discount, the comparison is exact: run the cohort, compare against the issued bills, and only then
commit. A difference in the fee schedule or in a student's configured percentage means the payment
already received no longer settles the invoice, and that family shows a balance they do not owe.

**The window between the run and the hand reconciliation**, during which every parent who has
already paid sees an unpaid invoice. Finish reconciliation before parents can log in, or tell
Brookstone the window exists and how long it lasts.

---

## 7. Questions — answered, and still open

**Answered by Brookstone:**

- BSS is **always a percentage**, 0–100%, **per student**, never a flat amount. No cap required.
- Which items are discountable is set **per fee schedule**, not per student.
- Whether a student's percentage applies to discountable items only or to the whole bill is **per
  student**. A 100% award on discountable items still leaves transport payable; a 100% award on
  total fees leaves nothing payable.
- C2C students are billed on a **different set of fee items**, **once per session**, as a single
  collective figure to the sponsoring organisation, paid off platform.
- The already-issued bills for BSS students **already show the discount**.
- ~70 C2C students.
- **(29 August)** A scholarship covers the **termly school fees**. It does **not** apply to a
  mid-term extra charge. See §11 — this is already the behaviour, and §11 says why that is a
  liability rather than a comfort.

**Answered from the code, so nobody asks again:**

- The discountable flag lives on `finance_fee_items`, which belong to a fee schedule — so per item
  within a schedule. Brookstone's preferred answer is already the situation.
- The discount shows as **one aggregate line**. `resolvePercentages()` emits one reduction line per
  percentage spec supplied.

**OPEN:**

- **How many classes hold C2C students?** Decides whether the C2C fee schedule path is configurable
  at all before 6 September. Blocks item 4, not items 1–3.
- **When a sponsor payment is allocated across students, is it even (total ÷ students) or manual per
  student?** Partial payments make manual the safer default. Blocks the allocation feature, not the
  run.

---

## 8. Watch this

A sponsor guardian account linked to seventy students is a guardian with seventy wards. It works
with the ownership guard shipped on 25 August, will see seventy families' invoices in the parent
portal, will return seventy wards in one read-contract response, and every access it makes lands in
the audit log.

---

## 9. Sequencing risk

Creating the discount policies goes through the maker-checker: one person submits, a **different**
person approves. Policy terms are **immutable once written** — a wrong value means retire and
supersede, not an edit. That sits *before* the dry-run comparison, which sits *before* the real run.

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

---

## 11. A scholarship covers the termly fees, not a mid-term extra charge

**Brookstone, 29 August 2026.** A scholarship reduces the **term's school fees**. A charge raised
mid-term — a trip, a replacement item, an optional service taken up after the term bill went out —
is **not** reduced by it. The family pays that in full whatever their award.

**This is already what the code does, and it is not done by a rule.** It falls out of which writer
handles which invoice:

- The **bulk run** applies awards. `ProcessBulkInvoiceRun::reductionSpecFor()` appends the reduction
  per student to a copy of the mapper's lines.
- **`GenerateInvoice` contains no reference to `StudentDiscountAward` at all** — measured, zero
  occurrences. Every ad-hoc invoice, and every supplementary one, therefore prices at full rate.

And the two paths carry exactly the split Brookstone described. `FeeScheduleLineMapper::linesFor()`
bills **mandatory items only**, because `is_mandatory` is a property of the price list and nothing in
the schema records which child takes the bus. Its docblock states the consequence plainly: optional
items are *"added per child afterwards, singly through the generate modal or as a supplementary
invoice."* Those are the mid-term charges, and they go through the writer that cannot see an award.

**Why this section exists, and it is the whole point of writing it down: the ruling is enforced by an
absence.** Nothing asserts that `GenerateInvoice` ignores awards. It ignores them because nobody
wired them in. A later change that makes the generate modal "helpfully" apply a student's award would
contradict a decision Brookstone has taken, would look like a bug fix in review, and **not one test
would go red**. An absence is not a guard.

- [ ] **Pin it.** A test that a supplementary invoice for a student holding an active 50% award
      carries no reduction line, naming this section as the reason. Block is with the implementer;
      it is not landed at the time of writing.

Do not treat "no code change needed" as the end of this item. The code change needed is the one that
makes the ruling breakable-with-a-red.

---

## 12. Fee items become a catalog with templates, not free text

**THE DECISION IS SEGUN'S AND IT IS RECORDED FIRST, SEPARATELY FROM MY DISAGREEMENT, BECAUSE AN
EARLIER REVISION OF THIS SECTION BLENDED THEM AND THAT IS HOW A DIRECTION QUIETLY BECOMES ITS
ADVISOR'S OPINION.** Rev 4 of this document recorded "not before cutover" as though it were the
agreed order. It was not. The order given was *"1. Mandatory Fee Catalog (Templates) First. 2.
Immediate Survey Check. 3. Step 0 Execution"* — catalog first. The substitution was mine and was not
flagged when it was made.

### 12.1 The decision, 29 August

Fee items become a **school-level catalog of templates** that fee schedules draw from, replacing the
free-text invoice line as the normal way a charge is described.

**Sequenced: Section 0 lands first on the existing schema to secure the 5 September cutover; the
catalog is built immediately after the first bulk run.** Section 0's fee-item entries must be
structured so they backfill into the catalog without re-entry — see 12.3, which is a condition of
this decision rather than advice.

### 12.2 The disagreement, and how it was settled

I argued for deferring, on three grounds: Section 0 would otherwise wait on new code in the seven
days before term; Finding 0 had just been rewritten to warn that four migrations landing in cutover
week is the shape that goes wrong, and a catalog is a fifth; and the survey has not returned, so the
catalog's scope is unknown and one that cannot express a real charge sends people back to free text.

The counter-argument is real and was accepted: every mid-term charge raised between September and
December is a free-text line with no fee item, so `is_discountable` falls to its default of **true**
(`app/Finance/DTOs/InvoiceLineSpec.php:74`) and the line sits in the discount base unclassified.
Waiting accrues that cost rather than avoiding it.

Settled on the middle path: bill Term 1 on the current path, build the catalog while mid-term
charges are still few. **The cost of waiting is a few weeks of free-text lines, not a term.**

### 12.3 What "backfills without re-entry" requires, and why it is a data rule

`finance_fee_items` (`2026_07_26_130001:31-44`) holds `description` as a plain `string`. **There is
no unique index, no code, no slug.** A catalog backfill will have nothing to group by except that
string, so two spellings of the same fee become two templates and there is no later repair that does
not involve a human deciding which rows meant the same thing.

Four rules for Section 0 entry. They cost nothing at typing time and cannot be recovered afterwards:

1. **One spelling per item, everywhere.** Identical string across every class level and both
   schools — same case, same spacing, no trailing space. `Tuition` and `Tuition Fee` are two
   catalog items.
2. **Never encode the class level or term in the description.** `JSS 1 Tuition` makes every level a
   separate template. The level is already the schedule's own coordinate.
3. **Same description ⇒ same `is_discountable`, same `is_mandatory`, same destination account.**
   **Amounts may differ per class level; the flags must not.** A description whose flags disagree
   across schedules cannot become one template. If two things genuinely differ in flags, they are
   two items and need two names.
4. **Type both schools from one agreed sheet**, not from each school's own list.

### 12.4 The check, because a rule without one is wallpaper

Run after entry and before approving anything. Any row returned is a description that will not
backfill cleanly:

```sql
SELECT description,
       COUNT(DISTINCT is_discountable) AS discountable_variants,
       COUNT(DISTINCT is_mandatory)    AS mandatory_variants,
       COUNT(*)                        AS rows_with_this_description
FROM finance_fee_items
GROUP BY description
HAVING discountable_variants > 1 OR mandatory_variants > 1;
```

And, for spelling drift, which no query can judge for you:

```sql
SELECT DISTINCT description FROM finance_fee_items ORDER BY description;
```

Read that list for near-duplicates. `Transport` beside `Transport Fee` is the defect.

**Both queries are UNVERIFIED — written from the schema, not run.** There are no fee items on
production to run them against yet, which is the whole reason this section exists.

### 12.5 Still open until the survey returns

Whether free text is removed outright or kept behind an explicit "uncatalogued charge" affordance
that forces the two fields the catalog would have supplied. Removing it outright is cleaner and is
the reason to ask rather than assume — a school that cannot bill an unforeseen charge on the day
bills it off the platform, and that is worse than a messy line.
