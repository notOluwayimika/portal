# Brookstone's answers, 31 August 2026 — three reversals and one confirmation

**Status:** received 31 August. Two questions still outstanding (§6). Nothing here has been built.

Every claim about the code below was read out of the tree at `b6588196` before it was written down.

---

## 1. Refunds are back in the launch scope, and the basis for cutting them was never theirs

Brookstone, verbatim in substance: *they did not confirm that there had been no refunds in the last
three terms; there have been refunds in that period; and they cannot guarantee none in the coming
term.* Refund functionality is to stay in the launch scope, with **every refund approved by the
Executive Director before it is processed by the Finance Lead**.

**What this voids.** `finance-mvp-cut-brief.md` rests the S10 cut on one sentence — *"Brookstone has
confirmed no refund in the last three terms"* — in five places: the S10 row (`:93`), U15 (`:138`),
U16's six-of-six claim (`:140`), the thirteen-of-twenty-four count (`:150`), and the §5 deferral row
(`:176`). The client says that sentence is not theirs. However it came to be written, it can carry
no weight now, and those five lines are superseded by this document.

**There is no refund code at all.** Measured, not assumed: `LedgerEntryType` has four cases
(`Charge`, `Payment`, `Reversal`, `CreditNote`) and none is a refund; the only traces in the tree
are
prose warnings and a single `finance.refund_issued` key in `config/activity_log_severity.php` that
nothing emits. This is a build, not a re-enable.

**It reopens the void path, and the code already says so.** `SubmitVoidRequest.php:31-35` warns that
"has an allocated payment" is monotonic ONLY because nothing can currently undo a payment — and a
refund can. Whoever builds refunds must revisit that branch, not only the ledger. That warning was
written when refunds were still in scope and it survived the cut; it is now live again.

**The seats already exist, but the order needs pinning.** `executive_director` is already the sole
checker on every finance approval and `finance_lead` is already a proposer
(`RbacSeeder.php:95-103`).
Brookstone's wording — approved by the ED **before** processed by the FL — inverts the house
pattern,
where a maker proposes, a checker approves, and approval is what executes. Who actually executes
matters to the duty-separation oracle, so it must be settled rather than assumed.

---

## 2. Internal Audit review — the 30 August "direct issue" ruling is superseded

Brookstone: the Finance team may prepare and generate group billing **without a second Finance
approval**, but **all billing must undergo Internal Audit review before it is released to parents**.
The Auditor reviews the selected students, the fee amounts, the applicable scholarships and
discounts, and other billing details; the review must appear in the audit trail with who, what, and
when.

The first half preserves the 30 August answer. The second half reverses it in substance: there IS a
checker, and it is Internal Audit rather than a second bursar.

**Four shipped places now assert something false** — the sentence "this feature issues DIRECTLY with
no maker-checker (Brookstone, 30 August 2026)", or a paraphrase of it, appears in
`ManualInvoiceRun`'s model docblock, `app-sidebar.tsx` (the nav comment), the manual run screen's
own
header comment, and this repository's ticket on the report's 200-row truncation, whose severity
rested on the report being the ONLY oversight the act ever gets. All four need correcting when the
review step is built, and the truncation ticket's severity drops when it is.

**Three things make this cheaper than it sounds.**

`internal_auditor` **already exists** as a seeded role. It holds `ACTIVITY_LOG_VIEW` and
`ACTIVITY_LOG_EXPORT` and nothing else (`RbacSeeder.php:549-552`), and the seeder's own note at
`:104-108` records that the safety reason for withholding finance reads disappeared with `001fd1f`,
calling the finance grant "DECIDED and UNIMPLEMENTED". The reading half of their requirement is one
already-decided grant away.

The audit trail they describe — who, what, date and time — is what spatie activitylog already
records for every other approval in this system. Nothing new is needed to satisfy that sentence.

And **parents cannot see invoices today.** There are no guardian-facing finance routes, and
`InvoiceStatus` has exactly two cases, `Issued` and `Void`. "Release to parents" therefore gates a
channel that does not yet exist. This is the cheapest moment this requirement could ever arrive:
retrofitting a review gate after a parent portal ships would be materially worse.

**One thing to watch in their list.** They want the Auditor to see "applicable scholarships and
discounts". On a mid-term manual charge there are none, by Brookstone's own 29 August ruling that a
scholarship covers termly fees and does not touch a mid-term charge — and `GenerateInvoice` contains
zero references to `StudentDiscountAward`, pinned by a test. The review screen must say that plainly
rather than render an empty discount section that reads as "none applied today".

---

## 3. Bank accounts — the structure is confirmed, and most of it is already built

Brookstone use **more than one designated collection account**, by purpose: new students, boarding
fees, sale of forms, examinations, and others. A part-payment reduces the student's total
outstanding
balance, and the system must record which account the money was received into.

**The recording half is already a database guarantee.** `finance_payments.bank_account_id` exists,
and the trigger installed by
`2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php:334`
refuses a payment whose `origin` is `portal` and whose `bank_account_id` is NULL (and the converse
for `migrated`). A portal payment cannot fail to name its account.

**What this newly settles** is the apportionment question that has been open since 30 August. S11
made a destination account mandatory on every charge line, so money received into account A can
settle a line destined for account B — a case `finance-mvp-cut-brief.md:92` already names as having
"a real trigger in term one instead of a hypothetical one". Brookstone's answer confirms it is real:
purpose-designated accounts mean a single part-payment against a total balance can settle lines
across several destinations. That is its own design piece and it is now unblocked.

---

## 4. Payment record retention — settled, and it is NOT the other clock

Seven years or longer, subject to School Management's approved retention policy. Proceed on that
basis.

**This does not close `gateway-payload-retention.md`.** That ticket is about how long the raw
gateway payload is kept, which is a different clock and a different risk — Brookstone answered about
the payment RECORD. Do not mark the payload question answered by this.

---

## 5. Group billing — whole school, richer selection, and a preview

**Maximum size:** the whole school in one action, in addition to a class or several classes.

**Selection:** by class, year group, level, or other defined groups; and where billing applies only
to some students within a group, Finance must be able to select them without ticking each one. The
selected students and their billing details must be **displayed for review before submission**.

That preview is not a nicety — it is the step the implementing agent identified as missing from the
"post a filter and bill" design, and Brookstone have now made it a requirement rather than an
engineering opinion.

**"Year group" and "level" need mapping to what this system actually has** before anyone builds a
selector for them. `StudentIndexFilters` offers search, class level, arm and scholarship. Whether
"year group" is a distinct axis or another name for class level is not established, and inventing a
fourth filter that shadows an existing one is how the guardians and students indexes drifted apart.

---

## 6. Still open

- **Does Internal Audit review apply to ALL bills — including the scheduled termly run for the whole
  school — or only to group billing the Finance team prepares by hand?** The cost difference is
  large. Asked 31 August.
- **Does "reviewed before released to parents" mean the bill does not exist and does not count
  against the balance until approval, or that it exists and counts but is not shown or sent until
  approval?** Both are buildable and they are very different pieces of work. Asked 31 August.

Segun's own direction of 31 August — plan for every possibility without limitation, and yes to
selecting a group without ticking names — stands as the working assumption. It is recorded here as
HIS direction and not as Brookstone's answer, because the two were given on the same day and only
the second is the client's.
