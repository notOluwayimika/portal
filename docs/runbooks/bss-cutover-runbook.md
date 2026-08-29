# Finance cutover — the ordered runbook

**Revised 28 August 2026, for Term 1 opening 5 September.** Everything here was measured; where a
number came from a query, the query is beside it. Nothing in this file is an estimate.

Companion to `phase1-deploy.md` and `post-deploy-tasks.md`.

---

## Read this first — the finance module has never been configured on production

Measured on production, 28 August, all four zero:

```sql
SELECT (SELECT COUNT(*) FROM finance_bank_accounts WHERE deactivated_at IS NULL) AS bank_accounts,
       (SELECT COUNT(*) FROM finance_fee_schedules WHERE status = 'active')      AS active_schedules,
       (SELECT COUNT(*) FROM finance_fee_items)                                   AS fee_items,
       (SELECT COUNT(*) FROM finance_school_settings
         WHERE invoice_number_prefix IS NOT NULL)                                 AS prefixes_set;
```

**No fee items means no fee schedule means the bulk run has nothing to price.** Every piece of
scholarship work — the discount base, the awards, the import, the exclusion of sponsored students —
sits on top of a fee schedule that does not exist yet.

An earlier revision of this runbook started at "deploy" and assumed a configured module. It was
wrong. **Section 0 below is the critical path; everything after it is downstream.**

The long pole is not code and it is not yours: it is accounts entering Brookstone's actual term fee
structure, item by item, per class level, and getting it approved. Start that conversation before
anything else on this page.

---

# SECTION 0 — Configure the module

Nothing below Section 0 can happen until these are done, and two of them are unfixable if done late.

## 0.1 — Two people, before two rows

- [ ] **A maker account and a checker account exist on production, and they are different people.**

Fee schedules, discount policies and every other governance act go through submit-then-approve.
`User::assignRole` **throws** if you try to give one person both sides of a Finance pair — grant-time
segregation of duties, no flag, no `--force`, no super-admin shortcut. You cannot click through the
whole flow yourself.

If nobody has decided who the executive director account belongs to, that is a conversation, not a
task, and it blocks the fee schedule.

## 0.2 — The invoice number prefix

- [ ] **Set `invoice_number_prefix` before the first invoice is issued.**

The number is stored bare and the prefix applied at render. Set it afterwards and the display number
of **every invoice already issued** changes — a parent told their invoice is `000042` finds it is now
`BSS-000042`.

Cheapest item on this page, and the only one that becomes permanent by being late.

## 0.3 — Bank accounts

- [ ] At least one **active** account per school.

Without one, a school can bill every family and record **not one naira** they pay: the payment origin
guard requires a bank account for both a bursar's manual entry and a gateway payment. It fails at the
operator, days later, with receipts on the desk. Fee items also point at these, so they come first.

## 0.4 — The fee items and the fee schedule

- [ ] Entered, submitted, and **approved** — a schedule only bills from `active` status.

**Nothing about `is_discountable` is an open judgement. Brookstone decided the rule during the
scholarship arc; this step types it in.** Recorded in
`docs/handoff/scholarship-and-cutover-decisions.md` §3.3 and §7, and it is two axes, not one:

- **Which items a scholarship can reduce is per fee schedule** — that is this flag, and it is the
  only one of the two set on this screen. Brookstone's own worked example names both sides:
  *100% on discountable items leaves the child paying transport; 100% of total fees leaves them
  paying nothing.* Tuition reduces, transport does not.
- **Whether a student's percentage runs against those items or against the whole bill is per
  student** — that is the award's base, set in 2.2 and 2.3, and it is not on this screen at all.

So the only thing to get right here is a list Brookstone hands you: for each item, yes or no. Not a
decision to make at the keyboard.

**Where it goes wrong is the default, not the decision.** The column defaults `true`
(`2026_07_26_130001:40`) and a new row in the bursar UI arrives with the box already ticked
(`fee-schedules.tsx:258`), labelled *Discountable*. An item nobody thought about is discountable.
On the drive fixture that one flag was worth **₦15,000 per child**.

- [ ] Get the discountable / non-discountable split from Brookstone **in writing, before entry
      starts** — not sorted out during it.

**Type from ONE sheet, and spell every item identically.** The fee catalog is being built straight
after the first bulk run (`docs/handoff/scholarship-and-cutover-decisions.md` §12), and it will be
backfilled from exactly what is entered here. `finance_fee_items.description` is a plain string with
**no unique index and no code column**, so that string is the only thing the backfill can group by:
`Tuition` and `Tuition Fee` become two catalog items and nobody can repair it afterwards without a
human deciding which rows meant the same thing.

- [ ] One spelling per item across **both schools and every class level** — same case, same spacing.
- [ ] **No class level or term in the description.** `JSS 1 Tuition` makes every level its own
      template; the level is already the schedule's coordinate.
- [ ] Same description ⇒ same `is_discountable`, same `is_mandatory`, same destination account.
      **Amounts may differ per level; the flags must not.** Two things that genuinely differ in
      flags are two items and need two names.
- [ ] After entry, run §12.4's two queries. Any description whose flags disagree, or any pair that
      reads as the same fee spelled twice, is fixed **before** the schedule is submitted — a fee
      item may only be written while its schedule is a draft (`2026_07_26_130001`, the parent-state
      trigger), so after approval this is no longer a quick edit.
- [ ] Read the ticked boxes back against that list before submitting for approval. Every row, not
      the ones somebody changed.
- [ ] After approval, re-run the four counts above. Any remaining zero is still a blocker.

---

# SECTION 1 — Deploy

- [ ] Promote and run the migrations. `php artisan migrate:status` afterwards — every migration
      reads **Ran**, not Pending.

- [ ] **Three probes, immediately after.** Production is Percona **5.7.23**; everything was measured
      on 8.0.43, CHECK constraints are parsed and ignored there, and `COLLATE utf8mb4_bin` inside a
      trigger body is documented but never measured on 5.7. Each must be **REFUSED**:

      ```sql
      INSERT ... origin = 'Gateway'    -- capital G
      INSERT ... base   = 'Total'      -- capital T
      INSERT ... kind   = 'Discount'   -- capital D
      ```

      **If any is ACCEPTED, stop.** Two causes, and this tells them apart:

      ```sql
      SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
      FROM information_schema.TRIGGERS
      WHERE TRIGGER_SCHEMA = DATABASE()
      ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME;
      ```

      Trigger **missing** → the migration did not run. Fixable by running it.
      Trigger **present** but the value got in → the 5.7 collation problem. Needs a code change, and
      the guard is admitting case variants while looking alive.

---

# SECTION 2 — The scholarship chain

## The population, measured on production 28 August

School#1 holds **611 students**:

| | count | at the run |
| --- | --- | --- |
| **BSS** | 91 | billed, with a reduction |
| **C2C** | 91 | **excluded entirely** — sponsored, invoiced by hand |
| no scheme | 429 | billed at full price |

School#2 holds 310 students and no scholarship rows. That is a normal state — a school awards a
scholarship whenever it chooses — and nothing here assumes otherwise.

```sql
-- re-measure before the run
SELECT s.school_id, s.name, COALESCE(s.kind,'(not configured)') AS kind, COUNT(st.id) AS students
FROM scholarships s
LEFT JOIN students st ON st.scholarship_id = s.id AND st.school_id = s.school_id
GROUP BY s.school_id, s.id, s.name, s.kind
ORDER BY s.school_id, students DESC;
```

## 2.1 — Classify the two schemes. This one stops everything.

- [ ] **BSS → discount.** Brookstone's own scheme; the school reduces the fee, the family still gets
      a smaller bill.
- [ ] **C2C → sponsored.** An outside party pays; the family is not billed at all.

Both answers came from Brookstone during the scholarship arc. Not a question to re-ask.

**Why it is first:** `AwardStudentDiscount` refuses a student whose scholarship is not `discount`, so
the import rejects every row until this is done. Worse, `ProcessBulkInvoiceRun` refuses the **entire
run**, before its first row, if any scholarship in the cohort is unconfigured. Not one student — the
whole run.

Both schemes are NULL until somebody sets them: the `2026_08_26_100000` migration deliberately
backfilled rather than guessing.

## 2.2 — Author the discount policies

**One policy per distinct (percentage, base) pair in accounts' BSS list.** Count those pairs the
moment the list arrives — that number is how many policies this step creates, and they must all exist
and be approved before the import accepts a single row.

- [ ] Each authored through **Finance → Discount policies**: maker submits, ED approves.
- [ ] **Never a seeder, never a direct insert.** `ApproveDiscountPolicyChange` is the only sanctioned
      writer of the catalog. The arch arm guarding this scans `app/` for one literal and cannot see a
      seeder or a raw `DB::table` insert — the constraint is carried by a person, not a gate. See
      `docs/handoff/tickets/the-catalog-single-writer-arch-arm-cannot-see-a-raw-insert.md`.

**The base is the money.** The screen states it in words — *of discountable charges* / *of the whole
bill* — the ED approves that phrase and reads it back on the catalog. Check it there before approving.

If their list gives percentages but does not say tuition-only versus whole-bill **per student**, that
gap has to be closed with accounts. It is the column the import cannot default.

## 2.3 — Import the awards

- [ ] Download the template. Fill it **in a spreadsheet application** — that is the path it was
      driven on, CRLF and all.
- [ ] Before uploading, compare the sheet's row count against the 91. Only accounts can say which is
      right; nine missing students would be nine families billed in full by a system that believes it
      is correct.
- [ ] Upload. Read the report.
- [ ] **"Already awarded" is not a failure.** A re-upload reports those rows and carries on.
- [ ] A row whose pair matches no active policy is **rejected, not invented**. In bulk, that means
      2.2 is incomplete — go back rather than editing the sheet.

## 2.4 — Term 1 active, 5 September

- [ ] Set Term 1 `active`, and make sure the previous term is not left active alongside it.

`CurrentTerm.php:116` still resolves the active term with **no ordering**, so two active terms in one
session resolve arbitrarily. Ticket: `current-term-resolution-is-unordered.md`.

## 2.5 — The first bulk run

- [ ] **Preview first.** The three counts are trustworthy: the preview now reads the same
      sponsored-exclusion predicate the run does, pinned by a test asserting the two agree.
- [ ] Expected shape in school#1: **~91 excluded as sponsored**, **91 carrying a reduction**, the rest
      at full price. The run bills placed enrollments at a (term, class) slot rather than raw student
      rows, so treat these as a shape to check against, not a total to match.
- [ ] **If the preview shows sponsored = 0, STOP.** Either the cohort holds none, or 2.1 did not take.
      The second bills 91 sponsored families who should never have received a bill, and it looks like
      a normal run until the invoices land.
- [ ] After: `billed + already + failed + sponsored == cohort_count`. The run report states this.

---

## What is knowingly unproven, and should be said out loud

**Parents will see a reduced total with no itemised reason.** The parent portal contract carries
`total` and `outstanding` and no lines, so a family on a half scholarship sees ₦155,000 where the full
bill was ₦280,000 and nothing explains the difference. The bursar's office will field those calls and
should know the answer lives on a screen they have and the parent does not. Deliberate — the contract
is delivered and Developer 2 is building against a pinned key set. First thing to revisit after
cutover.

**A 100% award produces a zero-total invoice, and the family sees nothing.** The invoice is real,
carries the discount on its face, and is settled — so it is not *outstanding*, so the parent portal
excludes it. The school holds the record; the family has no document.

**No invoice can state its own destination bank account.** Raised by Developer 2 on 27 August: the
only available answer is a live lookup through a nullable, unconstrained `fee_item_id` pointing at a
mutable row, which answers "where would this go today" rather than "where was it destined". Invoice
lines are append-only, so every invoice issued before a snapshot column exists is permanently silent
about it. Whether that matters depends on how many bank accounts Brookstone operates and whether fees
route to different ones — ask alongside 0.3.

**Payment against a reduced invoice has never been driven.** Neither has a run in school#2.
