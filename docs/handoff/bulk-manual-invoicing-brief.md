# Bulk manual invoicing — design brief

**Status:** design, not started. **Not before the first bulk run** — see Sequencing.
**Direction:** Segun, 30 August 2026, on Brookstone's ask for "a custom list of specific students
invoiced in one batch."

**This is not a new requirement.** It is §4 of `scholarship-and-cutover-decisions.md` — *"line items
with description, amount and destination account; students chosen by filtering on scholarship and
class, then ticking individuals or taking the whole filtered set"* — and §5 already sequences it as
item 3, after the discount work and before the C2C fee schedules. Brookstone's ask and the recorded
plan agree. It earns its place twice: it is also the mechanism that produces the C2C session bills,
and it needs no configuration from Brookstone.

---

## 1. Selection — borrow the STUDENTS index, and do not borrow the guardians one

The instruction is to reuse the filter-and-tick the bursary team already knows. **Which one is
borrowed decides whether this feature bills the right people**, and the two existing implementations
differ on exactly that point.

`docs/handoff/tickets/guardians-select-all-matching-claims-a-scope-it-does-not-have.md` records the
defect, and it is live: `guardians/bulk-action-bar.tsx` renders *"Select all N matching"* and sets a
`selectAllMatching` flag, but the browser only holds the ids the server sent for the **current
page**. Every action behind that bar runs on those. The operator is told 240 and gets 25, the
control confirms 240 back to them, and nothing errors.

**The students index does not have this, by construction rather than by discipline** — the ticket
says so in its own words. Two properties make that true, and both must survive into this feature:

1. **The toolbar's "whole filter set" question is answered SERVER-SIDE.** `StudentIndexFilters` +
   `StudentsExport`'s two-scope constructor compute the current filter set on the side that can
   actually know it. The client never materialises the ids.
2. **The footer acts on EXACTLY the ticked ids, and names the count in its own label.** The two
   controls are orthogonal; neither is implied by where it sits.

**In an export, borrowing the wrong one produces a short spreadsheet. Here it bills 25 families and
tells the bursar it billed 240.** So this brief states it as a rule rather than a preference:

- [ ] "Invoice all N matching" — if offered at all — is a **server-side** scope, resolved from the
      same filter payload, never from a client id list.
- [ ] "Invoice selected (N)" acts on the ticked ids and says N in the label.
- [ ] **Do not import anything from `guardians/bulk-action-bar.tsx`.** Copying it is how the defect
      spreads, and it spreads into money here.

**The CSV admission-number paste/upload pre-selects rows; it is not a third scope.** Precedent for
the parsing and reporting is the discount-award import. An admission number that matches nothing is
**reported, not skipped silently** — the import's own rule, and the reason its report has a rejected
section.

---

## 2. `student_ids` does not address what the run bills

The payload is specified as an array of `student_ids`. **The run does not bill students; it bills
ENROLLMENTS.** `finance_bulk_invoice_run_rows` keys on `enrollment_id` with a composite
`(enrollment_id, school_id)` FK, and `GenerateInvoice::handle()` takes an **enrollment uuid**.

So the server must resolve each selected student to a current billable enrollment, and that
resolution has outcomes:

- a student with **no** current enrollment → `unplaceable`, which the row outcome enum already has;
- a student with **more than one** → an ambiguity nothing currently decides.

- [ ] Resolve server-side, from `student_id` to enrollment, and **report the unresolved rather than
      dropping them.** A bursar who selected ninety and billed eighty-four must be told which six and
      why, on the run report — not left to count.
- [ ] Decide what a multiple-enrollment student means before writing the resolver. Do not let the
      first `->first()` become the answer by accident.

---

## 3. The run table cannot hold this shape

`finance_bulk_invoice_runs` has `term_id` and `class_level_id` as **NOT NULL constrained FKs**
(`2026_08_18_110000:149-150`). The table is built around "a cohort at a (term, class level) slot",
and an arbitrary student list spans class levels by definition.

Two candidates, and this is a decision rather than a preference:

**A — make the slot nullable on the existing table.** Cheapest in migration terms. But the run's
whole accounting means "the cohort at this slot", and `cohort_count`, `unplaceable_count` and the
equality `billed + already + failed + sponsored == cohort_count` are all phrased against it. A
nullable slot makes one table mean two things, and every reader has to know which.

**B — a second run type, discriminated by a column, or a separate pair of tables.** More schema, but
each run means one thing and the existing scheduled run's invariants stay exactly as written and
tested.

Not decided here. **Whichever is chosen, the cohort-equality accounting must still hold for the new
shape** — it is the run's only self-check, and a manual run needs it more than a scheduled one
because nothing else knows what the operator intended.

---

## 4. Idempotency — MEASURED, and the cheap answer is not the one in the ticket

The instruction says "enforce an idempotency lock." Two facts change what that should be.

**Fact one: supplementary invoices have no duplicate backstop, at any layer.** The scheduled run is
protected by `UNIQUE(school_id, active_enrollment_key)` over a generated column computing
`IF(status='issued' AND kind='scheduled', student_curriculum_id, NULL)`. A supplementary invoice
computes NULL, and NULLs do not collide. Proved positively, not inferred:
`SupplementaryInvoiceWireTest:217-218` inserts two identical supplementary rows raw and both return
driver code `null`. Ticket: `a-supplementary-invoice-has-no-duplicate-backstop.md`.

**That ticket accepted the exposure partly because "the blast radius is one student's balance." This
feature ends that reasoning.** Over a list of ninety, a duplicate is ninety duplicates, each posting
its own `LedgerEntryType::Charge` and each recoverable only by a maker-checker void request **per
invoice**. The ticket must be re-read as an input to this design, not as settled background.

**Fact two: the run-row unique index exists but is on the WRONG SIDE of the invoice write.**
`finance_bulk_invoice_run_rows` carries
`UNIQUE(school_id, run_id, enrollment_id)` (`2026_08_18_110000:292`), which looks like idempotency
and is not — because the invoice is created at `ProcessBulkInvoiceRun:446` and the row is written at
`:593`, **after**. On a re-execution the invoice commits first; the row insert then collides with
1062, and `attempt()` (`:386`) only **logs** it. The result is worse than a plain duplicate: a
duplicate invoice that **no row records**, which also breaks the run's own cohort equality.

Nothing has hit this because `tries = 1` (`:147`) stops Laravel retrying the job. That is one flag
standing between the scheduled run and an unrecorded double-bill, and it protects nothing at all
against an operator starting a **second run** with the same list.

**So there are two different duplicates, and they need different answers:**

- **Within one run (re-execution, requeue):** invert the order — **claim, then bill.** Write the row
  first as a claim, let `UNIQUE(school_id, run_id, enrollment_id)` refuse the second attempt
  *before* an invoice exists, then update the row with the outcome. Costs a new pending state on the
  outcome column and a re-reading of the failure paths. It is far cheaper than the ticket's Option B
  (`finance_request_idempotency` table, header contract, pruning job) and it is strictly stronger,
  because it binds to the unit of work rather than to a client's good behaviour.
- **Across runs (the operator presses Run twice, or two operators do):** nothing above helps. This
  needs a deliberate answer — a confirmation naming the exact count and total, a short window in
  which a second run over an overlapping list is refused or warned, or an explicit "yes, bill them
  again". **Not decided here, and it should not be decided by whoever writes the code.**

**UNVERIFIED:** the claim-then-bill inversion is a proposal derived from reading `:446` and `:593`.
It has not been prototyped, and its interaction with the existing failure paths — particularly the
`already_billed` outcome and the equality check — has not been measured.

---

## 5. Three inherited rules that will bite whoever implements this

**Sponsored students must NOT be excluded.** The scheduled run excludes them, the predicate is
shared between the preview and the run, and it is pinned by a test — which makes it exactly the
thing someone copies. **This feature exists partly to bill sponsored students**: §4 says it produces
the C2C session bills. Reusing the cohort logic silently drops the very students the feature was
built for.

**Every charge line must name a destination account.** S11 (`d3227c0`) made it required:
`GenerateInvoiceRequest::assertDestinationsChosen()` refuses with a 422 keyed to
`lines.N.bank_account_id`, and `finance_invoice_lines_destination_guard` is the authority behind it.
A bulk manual run supplies one set of lines for the whole list, so this is one choice per line, not
per student — but it is not optional and there is no default.

**The charge is at full price for everyone, including scholarship holders.** Brookstone, 29 August:
a scholarship covers the termly fees and does not apply to a mid-term charge
(`scholarship-and-cutover-decisions.md` §11). `GenerateInvoice` contains zero references to
`StudentDiscountAward`, and that is now pinned by a test rather than left as an absence. Nothing to
build; do not "fix" it.

---

## 6. Open, and to be answered before code

- [ ] Run-table shape: A or B (§3).
- [ ] The across-runs duplicate answer (§4).
- [ ] What a student with more than one current enrollment means (§2).
- [ ] Is a bulk manual run a governance act? Single manual invoices are not maker-checker today and
      neither is the scheduled run. Consistency says no. Billing ninety families in one click on a
      path with no duplicate backstop is the argument for yes. **Ask; do not infer.**

## 7. Sequencing

After the first bulk run, alongside the fee catalog, and **ahead of it** — this has a live consumer
in the C2C session bills, where the catalog's value only begins accruing once people are typing
mid-term charges. Not before 5 September: it needs a migration, and Finding 0 warns that migrations
landing in cutover week is the shape that goes wrong.

---

## 8. Cross-workstream collisions this design already survived, and what they cost

Added 30 August after both landed. Recorded here because this feature was built alongside the
payments workstream, and everything below happened between two branches that were each correct.

**A fixture can predate a guard.** S11 made a destination mandatory on every charge line while
#330's branch was open. That branch's fixture built charge lines without one, its gate was green on
its own tree, and the merge took staging red across twenty-two arms. Nothing either side ran could
have seen it.

**An exhaustive-set assertion can meet a new member.** This feature's migration adds a currency-shape
CHECK; #330 shipped a closed list of every CHECK on a `finance_` table. Same shape, same window,
same invisibility. It then happened a second time inside a single commit, against
`config/rbac.php`'s `fail_closed_models` — see
`docs/handoff/tickets/hand-maintained-exhaustive-sets-have-no-discovery-path.md`.

**Neither is a migration-order problem**, which is what the `--step` warning had led us to watch
for. Ordering matters and is not sufficient.

**The practical consequence for whoever builds the remaining commits:** before merging anything from
this feature, re-run the gate against a tree that has staging merged INTO the branch, not just the
branch. That is the only local approximation of what the server would check, and it is the thing
that would have caught both.
