# BSS scholarship cutover — the ordered runbook

**Written 28 August 2026, for Term 1 opening 5 September.** Everything here was measured during the
work that built it; where a number came from a query, the query is beside it. Nothing in this file
is an estimate.

Companion to `phase1-deploy.md` and `post-deploy-tasks.md`. This one covers only the scholarship
chain: classifying the schemes, authoring the discount policies, importing the awards, and the first
bulk run.

---

## The population, measured on production 28 August

School#1 holds **611 students**:

| | count | what happens at the run |
| --- | --- | --- |
| on the **BSS** scheme | 91 | billed, with a reduction |
| on the **C2C** scheme | 91 | **excluded entirely** — sponsored, invoiced by hand |
| on no scheme | 429 | billed at full price |

School#2 holds 310 students and **no scholarship rows at all**. That is a normal state — a school
awards a scholarship whenever it chooses — and nothing in this chain assumes otherwise.

```sql
-- re-measure before the run; these are 28 August figures
SELECT s.school_id, s.name, COALESCE(s.kind,'(not configured)') AS kind, COUNT(st.id) AS students
FROM scholarships s
LEFT JOIN students st ON st.scholarship_id = s.id AND st.school_id = s.school_id
GROUP BY s.school_id, s.id, s.name, s.kind
ORDER BY s.school_id, students DESC;
```

---

## Order matters, and here is the one dependency that bites

**`kind` must be set before anything else touches money.**

- `AwardStudentDiscount` refuses a student whose scholarship is not `discount` — so the import
  rejects every row until the schemes are classified.
- `ProcessBulkInvoiceRun` refuses the **entire run**, before its first row, if any scholarship in
  the cohort is unconfigured. Not one student — the whole run.

Since the `2026_08_26_100000` migration deliberately backfilled every existing row to NULL rather
than guessing, **both schemes are unconfigured until somebody sets them.**

---

## 1 — Deploy

Nothing below can happen until the code is on production.

- [ ] Promote and run the migrations.
- [ ] **Three probes, immediately after.** Production is Percona **5.7.23**; everything was measured
      on 8.0.43, CHECK constraints are parsed and ignored there, and `COLLATE utf8mb4_bin` inside a
      trigger body is documented but never measured on 5.7. Each of these must be **REFUSED**:

      ```sql
      INSERT ... origin = 'Gateway'    -- capital G
      INSERT ... base   = 'Total'      -- capital T
      INSERT ... kind   = 'Discount'   -- capital D
      ```

      **If any is ACCEPTED, stop.** The guard admits case variants while every other arm still bites,
      so it looks alive and is not. Each has a passing 8.0 proof to compare against.

- [ ] Every school has at least one **active** bank account (`post-deploy-tasks.md` carries the
      query). Without one, that school can bill every family and record not one naira they pay. It
      fails at the operator, days later, with receipts on the desk.

---

## 2 — Classify the two schemes

Finance → Setup → Scholarships.

- [ ] **BSS → discount.** Brookstone's own scheme; the school reduces the fee and the family still
      gets a smaller bill.
- [ ] **C2C → sponsored.** An outside party pays; the family is not billed at all.

Both answers came from Brookstone during the scholarship arc — this is not a question to re-ask.

The screen withholds nothing else until this is done, so it is easy to skip. Do not.

---

## 3 — Author the discount policies

**One policy per distinct (percentage, base) pair in accounts' BSS list.** Four percentages across
two bases would be eight policies; the real number comes from their list, not from here.

- [ ] Each one authored through **Finance → Discount policies**: a maker submits, the ED approves.
- [ ] **Never a seeder, never a direct insert.** `ApproveDiscountPolicyChange` is the only sanctioned
      writer of the catalog. The arch arm that guards this scans `app/` for one literal and cannot
      see a seeder or a raw `DB::table` insert — so this constraint is carried by a person, not a
      gate. See `docs/handoff/tickets/the-catalog-single-writer-arch-arm-cannot-see-a-raw-insert.md`.

**The base is the money.** A 50% award read against the whole bill instead of discountable charges
was measured at **₦15,000 per child** on the drive fixture. The screen states which it is in words —
*of discountable charges* / *of the whole bill* — and the ED approves that phrase and reads it back
on the catalog. Check it there before approving.

---

## 4 — Import the awards

Finance → BSS import.

- [ ] Download the template. Fill it **in a spreadsheet application** — that is the path it was
      driven on, CRLF and all.
- [ ] Upload. Read the report.
- [ ] **"Already awarded" is not a failure.** A re-upload of the same sheet reports those rows and
      carries on; nobody is awarded twice.
- [ ] A row whose (percentage, base) pair matches no active policy is **rejected, not invented**.
      If that happens in bulk, step 3 is incomplete — go back rather than editing the sheet.

**Before uploading, compare the sheet's row count against the 91.** The system's number and accounts'
list are two different things, and only accounts can say which is right. Nine missing students would
be nine families billed in full by a system that believes it is correct.

---

## 5 — Term 1 active, 5 September

- [ ] Set Term 1 `active`.

`CurrentTerm.php:116` still resolves the active term with **no ordering**, so two active terms in one
session would resolve arbitrarily. Do not leave the previous term active alongside it. Ticket:
`current-term-resolution-is-unordered.md`.

---

## 6 — The first bulk run

- [ ] Preview first. **The three counts are your sanity check and they are now trustworthy** — the
      preview reads the same sponsored-exclusion predicate the run does, pinned by a test asserting
      the two agree.
- [ ] Expected shape in school#1, against the 28 August figures: **roughly 91 excluded as sponsored**,
      **91 carrying a reduction**, the rest at full price. The run bills placed enrollments at a
      (term, class) slot rather than raw student rows, so these are a shape to check against, not a
      total to match.
- [ ] **If the preview shows sponsored = 0, stop.** Either the cohort genuinely holds none, or step 2
      did not take. The second is the expensive one.
- [ ] After the run: `billed + already + failed + sponsored == cohort_count`. The run report states
      this itself.

---

## What is knowingly unproven, and should be said out loud

**Parents will see a reduced total with no itemised reason.** The parent portal contract carries
`total` and `outstanding` and no lines — so a family on a half scholarship sees ₦155,000 where the
full bill was ₦280,000, and nothing on their screen explains the difference. The bursar's office will
field those calls and should know the answer lives on a screen they have and the parent does not.
Deliberate: the contract is delivered and Developer 2 is building against a pinned key set. First
thing to revisit after cutover.

**A 100% award produces a zero-total invoice, and the family sees nothing.** The invoice is real,
carries the discount on its face, and is marked settled — which means it is not *outstanding*, so the
parent portal excludes it. The school holds the record; the family has no document. Correct for a
full scholarship, worth knowing before somebody asks.

**Payment against a reduced invoice has never been driven.** Neither has a run in school#2.
