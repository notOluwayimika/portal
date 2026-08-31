# Finance MVP — the cut

**Against** `docs/Finance Module — Implementation Master Plan - v10.md`
**Date** 2026-08-06 · **Author** advisor · **Status** proposal, not a ruling
**Rev 3** — 2026-08-06. Rev 1: the cut. Rev 2: the UI ruling (§2, §4.3). Rev 3: the project lead's six added items folded in — §2, S9, S11, V2, V3, V10, V11, U1/U3/U6/U12b/U20–U23, §5, §6, §7, §8, and new **§9** (the six items, item by item, including the one mechanism I am refusing) and **§10** (August, re-derived).

---

## 0. The question you asked, answered first

**Are v10's phases an MVP, or a full build wearing MVP labels?**

**A full build.** v10 is not an MVP plan and does not claim to be. Three things give it away:

1. **The phases are sliced by subject completeness, not by survivability.** Phase 6 is *all* of allocation — every rule, multi-ward, credit balances, override capture, backdating. Phase 10 is *all fourteen* reports. Phase 11 is *all eleven* exception signals. That is how you scope a finished product, not a first term.
2. **"Independently deployable" is not "runnable".** v10:1026 says every phase is independently deployable and independently valuable. True, and it is not the same claim. You cannot run a term on Phase 2, or Phase 5, or Phases 2+5. The earliest point at which Brookstone can bill and collect is the **end of Phase 6 — week 30 of 53**. First parent-visible value is Phase 8, **week 37**.
3. **The total is honest and long.** ≈53 weeks, 2 developers, ±30%, with no velocity data behind it (v10:1341 says so plainly). Nothing in the document pretends otherwise.

So the MVP question does not slice *along* the phases. It slices *across* them.

---

## 1. The cut rule

Everything below follows one rule, and I would rather be argued with about the rule than about the rows:

> **Cut surfaces. Never cut shapes.**
>
> A **shape** is anything that decides what a row in the append-only ledger looks like — a column, a posting rule, a date semantic, a lock. Deferring a shape means rewriting history later, and history in this system is immutable by trigger. You cannot backfill `effective_at` onto postings that never had one, and v10 says of allocation-mode capture in as many words: *"Phase 11 cannot derive this retroactively at any price"* (v10:1145).
>
> A **surface** is a screen, a report, an export, a notification, an automation, a bulk path. Adding one later reads the same rows that were already there. Surfaces are free to defer.

The corollary, which is the whole value of the cut: **the §15 control layer is mostly shape, so it mostly cannot be cut.** v10:1345 says the same thing from the other direction — §15 is roughly a third of the effort *and it is the part that cannot be cut, because it is why Finance and Internal Audit wrote the document.* An MVP that drops maker–checker is not a smaller product, it is a different and worse one.

---

## 2. What the MVP is

**One School. One term. Staff-operated.**

Brookstone Finance can configure the term's fees, issue that term's invoices, take money in across bank transfer/POS/cash, know what every student owes including what they owed before cutover, correct their own mistakes, and hand Internal Audit a trail that stands up.

**Every item in the cut ships with its screen — ruled by the project lead, 2026-08-06.** There is no headless slice, no "backend now, UI later". An item is in the MVP when a bursar can drive it in the running app, not when its migration lands. §4.3 names every screen. *Staff UI throughout — this ruling did not move the parent portal, which stays out.*

**Parents ARE in the MVP — read-only, three screens.** *Amended 2026-08-06 by the project lead's six added items.* A guardian signs in and sees, per School: the year's fees per ward before billing (a catalog projection), what is owed now aggregated across their wards, and a cross-ward statement of payments tagged per ward — viewable, printable, exportable, filterable. **Nothing else moves in with them:** no self-service payment, no reminders, no notifications, no bulk print/email, no document upload. The bursar still emails and still answers the phone.
**Nothing is automated except one job.** No Paystack, no SMS, no auto-reconciliation, no dunning. The single automation is the bulk-invoice run firing on the term start date (V2), and it fires to `draft`, not to `issued` — see V2.
**WCBS is not migrated.** It is *closed out* — one opening balance per student, approval-gated, provenance-marked, now **bulk-importable** (V11). WCBS survives as a read-only archive.

That last one is the largest single saving in the cut and it is how schools actually cut over.

---

## 3. Done already

Verified by reading the tree on 2026-08-06, not from the plan. `app/Finance/` is 96 files; `database/migrations/` carries 30 finance-related migrations.

| Area | What exists |
|---|---|
| **Money** | `Support\Money` VO, `MoneyCast`, integer minor units + `{name}_currency` throughout. `allocate()` correct, tested, deliberately unused. |
| **Ledger** | `finance_ledger_transactions` append-only by **DB trigger**; `LedgerImmutableException`; `SubledgerPoster`; `enforce_finance_child_school_integrity` and `enforce_finance_invoice_episode_school_integrity` composite `(id, school_id)` FKs. |
| **Core entities** | `Invoice`, `InvoiceLine`, `Payment`, `PaymentAllocation`, `LedgerTransaction`, `StudentAccount`, `FeeSchedule`, `FeeItem`, `DiscountPolicy`, `CreditNote`, `VoidRequest`, `SchoolFinanceSettings`, `Scholarship`. |
| **Actions** | `GenerateInvoice`, `RecordPayment`, `RecordAccountPayment`, `CreateFeeSchedule`, plus four full Submit/Approve/Reject triples (credit note, void, fee-schedule change, discount-policy change). |
| **Approvals** | Per-domain change tables sharing one shape (ADRs 0049/0050 — the polymorphic engine is **withdrawn, not deferred**). Maker ≠ checker at **Policy + DB constraint**. `ApprovalAbility`, `DutySeparation`, `Gate::before` excludes approve/reject so `super_admin` can never self-approve (ADR 0040). |
| **Invariants enforced in DB** | invoice total immutable · allocation not over invoice total · active-enrollment guard · credit note requires an issued invoice · `guardian_student` same-School · `students.status` + `left_at` + `leave_reason` + `index(school_id, status)`. |
| **Projections** | Balance projection atomic upsert-increment in `SubledgerPoster::post`, backstopped by `finance:reconcile-accounts` + `finance:audit-ledger-coherence`. Reporting projection via `AccountReadModel` / `InvoiceReadModel`. |
| **Sequences** | `sequences` table + `Support\Sequences\Sequences` with `lockForUpdate` — gap-free numbering primitive exists. |
| **UI** | 3 admin pages (`index`, `approvals`, `statement`), 5 modals, 9 controllers, wayfinder actions generated. |
| **Policy doc** | `docs/finance/accounting-policy.md`, co-signed by Brookstone Finance. |
| **Phase 1 substrate** | 12 of 17 §4.4 debt items ✅. `ActiveSchool`, `SchoolAccessParity`, `SchoolScope` fail-closed (built), `PermissionCatalog`, `EffectivePermissions`, `RouteAccessMap`, grants-convergence lint, duty-separation audit, six content-keyed ratchet baselines (ADR 0041), `bin/quality` 15 steps + `.githooks/pre-push`. |

**Read this column honestly.** v10:1600 warns: *"Do not read the skeleton as 'Phase 5 done.'"* What exists is a **frozen module template with real invariants** — the shapes are right and proven. What is missing is almost everything a bursar would actually touch.

---

## 4. In the cut

Ordered by whether it is a shape or a surface, because that is the argument.

### 4.1 Shapes — cannot be deferred at any price

| # | Item | v10 home | Why it cannot wait |
|---|---|---|---|
| S1 | **Exercise the `finance_student_accounts` lock anchor** — `lockForUpdate` on the account row before any balance-affecting write, proven under parallel load **on MySQL** | Ph2/Ph6 | This is Risk 2. The anchor is *declared* and **never exercised** — I grepped: `lockForUpdate` appears on `Invoice`, `FeeSchedule`, `DiscountPolicy`, `CreditNote` and `sequences`, and on **nothing** touching `StudentAccount`. Two simultaneous payments against a credit balance can double-spend it today. v10 names Risks 2+3 together as *"what actually kills this project"*. |
| S2 | **`posted_at` / `effective_at` on ledger postings + a user-supplied received date on payments**, with a Policy on who may backdate and defined behaviour in a closed period | §12.1, Ph6 +0.5 | §12.1 specifies both and neither exists in the tree. A date column added in month 6 is null for every row written in months 1–5. This is §15F signal 9. |
| S3 | **Allocation rule-in-force + explicit override marker with reason** on every allocation row | Ph6 +0.5, §12.7 | §15F signal 2. v10: *"Phase 11 cannot derive this retroactively at any price."* Acceptance is exact: an auto-allocation and a hand-corrected one are distinguishable **by a query that reads no code**, and the rule name survives a later edit to the School's setting. |
| S4 | **F6 — invoice `total` = SUM(lines), enforced** | §28 invariants, "slice 2" | The only F-invariant still ⬜. Every other one is green. Leaving it open means a discount or a re-price can silently desynchronise the header from its lines. |
| S5 | **Deferred income posting** (recognition job may lag; the *posting shape* may not) | Ph5 | If invoices post straight to revenue now and to deferred later, you have two posting shapes in one immutable ledger and no way to normalise them. Decide once. |
| S6 | **`finance_bank_accounts` + `bank_account_id` on payments** | Ph2/Ph6 | No bank-account table exists. The Schools are on separate bank accounts; a payment with no destination cannot be reconciled to a statement, ever, retroactively. *The fee-category mapping UI and the per-account collections report are surfaces — see §5.* |
| S7 | **Grant-audit listener** — writes one activity row per user-grant change with actor, target, School, before-set, after-set, reason; **writes nothing when before == after** | Ph1C +0.5 | §15F signal 7, the privilege-escalation signal. It sits in the phase blocking everything else, and v10 says *"it should be built on that ground regardless of what the exception dashboard eventually looks like."* Proving test: `syncRoles` with an identical set writes zero rows. |
| S8 | **Receipt numbering** off the existing `Sequences` primitive | Ph6 | A receipt number is a gap-free sequence and a column. Retrofitting numbers onto receipts already given to parents is not possible. The primitive already exists — this is wiring, not building. |
| S9 | **Opening balances, approval-gated, provenance-marked** — one entry per student, **posted as an opening charge or an opening credit, never as a `Payment`** | Ph6 (already in scope) + Ph14 substitute | This *is* the WCBS answer for the MVP. Without it the portal knows only this term's charges and a parent's real balance lives in neither system. **See §9 — the mechanism was specified as a derived payment and that is the one shape in the six added items I am refusing as stated.** |
| S11 | **`bank_account_id` on `finance_fee_items` and `finance_invoice_lines`** — the destination account travels with the line, snapshotted onto the invoice at issue | Ph2/Ph5, new | *Added 2026-08-06 (item 6).* S6 gives payments a **source** account; this gives each charge a **destination** account. Neither table has the column — I read both migrations. A line printed with "pay this into account X" must keep saying X after the schedule is superseded, so it is a snapshot on the invoice line, not a lookup. Retrofitting it means every invoice issued before the column existed can never say where its money should have gone. **It also creates an allocation case:** money received into account A settling a line destined for account B. Phase 6 already has a bank-account mismatch exception — that exception now has a real trigger in term one instead of a hypothetical one. |
| ~~S10~~ | ~~**Refund as an approval-gated cash-out posting**~~ — **MOVED OUT 2026-08-09.** | Ph7 | The argument was "the shape must exist before the first one happens". **SUPERSEDED 2026-08-31 — Brookstone say they did not confirm this and that refunds HAVE been issued in that period; refunds are back in the launch scope with ED approval. See `brookstone-answers-31-august.md` §1. U15 (:138), U16's six-of-six claim (:140) and the thirteen-of-twenty-four count (:150) all rest on the same voided sentence.** ~~Brookstone has confirmed **no refund in the last three terms**, so the first one is not in term one~~, and the third-state design question goes with it. Now in §5. The shape argument still holds the day a refund is issued — this is deferred, not cancelled. |

### 4.2 Surfaces — in the cut because a term stops without them

| # | Item | v10 home | Minimum viable form |
|---|---|---|---|
| V1 | **Fee configuration usable by an admin** | Ph2 | Brookstone's real template for one School configured with zero code, all twelve §3 components **as rows, none enumerated in code**. This is Ph2's own acceptance test and it is the right one. |
| V2 | **Bulk invoice generation, idempotent, gap-free under concurrency — all non-withdrawn students in the active session + term, off the fee schedules — and scheduled to fire on the term start date** | Ph5 | *Extended 2026-08-06 (item 2).* `ShouldBeUnique` + a natural key on (student, term, schedule version); `students.status` already exists and is the withdrawal filter. **Not** the full `Idempotency` table + middleware — that is for hostile webhook input and goes out with Paystack. **Two conditions on the auto-fire, both non-negotiable:** (a) it generates to `draft` and a human releases the batch to `issued` — a job that bills a whole School with nobody in the loop bills the whole School wrong when a schedule is wrong, and cancelling issued invoices costs void requests; (b) **Risk 6 must be reconciled first** — `retry_after=90` against `timeout=3600` means the queue can start a second copy of a run still in progress, which is exactly how you get a duplicate invoice batch. Idempotency makes that survivable, not harmless. |
| V3 | **Shared PDF engine + invoice PDF + receipt PDF + cross-ward statement PDF** | Ph1E, Ph5, Ph6, Ph8 | Absent from the tree — no dompdf/snappy/browsershot/mpdf. The bursar has to hand a parent a bill and a receipt. *Statement PDF moved back IN 2026-08-06 (item 5): "printable" is a PDF.* Bulk print/email of statements stays out. |
| V4 | **Discount award to a student, approval-gated, one audited reversible row** | Ph4 | `DiscountPolicy` + `DiscountPolicyChange` + approval already exist. What is missing is the *award*. Bulk award is out. |
| V5 | **Outstanding-balances list per class / year group, Excel export** | Ph10 (one report of fourteen) | This is the one report a bursar cannot chase money without. `maatwebsite-excel` is already installed. |
| V6 | **Per-School feature flag** | Ph1E, Ph2 | Absent from the tree. It is how the MVP ships to one School and stays dark in the other three. Cheap. |
| V7 | **Observability baseline** — error tracking + `failed_jobs` alerting | Ph1E | Risk 3, the other half of *"what actually kills this project"*. Nothing today: no Sentry, Telescope, Bugsnag or Flare. A silently failed allocation means a parent chased for money they paid. This is a DSN and a config, not a project. |
| V8 | **tsc ratchet actually enforced** | Ph1B | Committed baseline 149, working tree **151 in 30 files**. "Baselines only shrink" is ADR 0041 law and this baseline is unenforced by its own tool. |
| V10 | **Guardian year preview — per ward, the whole academic year's fees, totalled and split per term** | **Nowhere in v10. New.** | *Added 2026-08-06 (item 1).* Genuinely new, and the reason it is new is worth saying: it projects over the **fee catalog before any invoice exists**, so it is not a statement. A statement reconciles to the ledger; this reconciles to nothing, because there is nothing yet. Two consequences. First, it must be visibly labelled an estimate, or a parent will treat it as a bill and the school will be arguing about a number it never issued. ~~Second, it imposes an operating rule Brookstone has not been told about: **all three terms' schedules must be `active` before term-1 billing**, or the preview shows one term and a blank.~~ **WITHDRAWN 2026-08-09 — the operating rule is refused, and the screen absorbs it instead.** School boards adjust prices mid-year, so requiring all three terms up front would force Brookstone to publish numbers they have not agreed. **The ruling: a term's card renders ONLY once that term's fee schedule is published; unconfigured terms are not shown at all.** And one consequence that is not a layout question — **the year total renders only when every term in that ward's structure is published.** Otherwise: the cards that exist, no year total, and one line saying which terms are not yet published. A hidden card looks deliberate; a year total that silently sums one term of three looks correct and is not, which is the worse of the two failures and the reason "just hide the empties" is half a rule. A ward on a 3-term class level with all three published shows three term totals and a year total. |
| V11 | **Bulk import of opening balances — CSV, per student, staged → approved → posted** | Ph6 + Ph14 substitute | *Added 2026-08-06 (item 3), and it moved from OUT to IN.* My cut had bulk import out and single entry in (U12). At Brookstone's roll size that is wrong — this is a one-time cutover of every student in the School, and hand entry of that is both slower and less auditable than a file with a checksum. **The import is a surface. What it writes is S9's shape, and only S9's shape.** It stages rows, shows the operator the totals and the rejects, and on approval posts opening entries with provenance. It does not create payments. See §9. |
| V9 | **A named release gate** | Ph1B | CI has never executed a single job — the GitHub account is billing-locked, every run is `steps=0` at ~4s, and PRs #56/#57/#58 merged over red. Either unblock the billing or **formally adopt `bin/quality` + the pre-push hook as the gate and write it down.** This is your decision, not code. |

### 4.3 The screens — every one of them, and who owns it

Ruled 2026-08-06: the MVP is what a bursar can drive, so this table is part of the cut, not a follow-up to it.

Today's tree carries **3 pages** (`admin/finance/index`, `approvals`, `statement`) and **5 modals** (`new-invoice`, `record-payment`, `issue-credit-note`, `request-void`, plus `table-toolbar`/`finance-stat-card`/`account-status-badge` as supporting parts). The MVP needs **24 surfaces** — twenty staff, four parent-facing. *Rows U20–U23 and the amendments marked ✱ were added 2026-08-06 by the project lead's six items.*

| # | Screen | Serves | State today |
|---|---|---|---|
| **Configuration — admin** ||||
| U1 | Fee schedules — list · draft · add/edit fee items · **✱ assign a bank account per fee item** · submit for approval · version history across `draft · pending_approval · active · superseded · retired` | V1, S4, ✱S11 | Controllers + change table exist. **No page.** The table is already keyed `(school_id, term_id, class_level_id)` — see §9 item 1. |
| U2 | Discount policies — list · create · submit change for approval | V4 | Controllers exist. **No page.** |
| U3 | Bank accounts — per-School CRUD, **✱ and the account is selectable on fee items and invoice lines** | S6, ✱S11 | **Nothing. No table, no controller, no page.** |
| U4 | Finance settings — allocation rule in force, the two §12.10 `exception_threshold` rows | S3 | `SchoolFinanceSettings` model exists. **No page.** |
| U5 | Per-School feature-flag toggle | V6 | **Nothing.** |
| **Billing — bursar** ||||
| U6 | Invoice generation — cohort + term + schedule version · preview counts · run · result with per-student failures · safe to re-run · **✱ the draft batch, with a release-to-issued action and a schedule showing when the job will next fire** | V2 | `new-invoice-modal` is single-student. **No bulk surface.** |
| U7 | Invoice list + detail — lines, status, PDF download, cancel → void request | V3, S8 | Partial in `index`. |
| U8 | Discount award — award against a policy, approval-gated, reversible per student | V4 | **Nothing.** |
| **Money in — bursar / cashier** ||||
| U9 | Record payment — amount · method (transfer/POS/cash) · reference · **bank account** · **received date, with a reason field when it is not today** | S2, S6 | `record-payment-modal` exists; carries none of the three bold fields. |
| U10 | **Allocation screen** — the engine's proposal per invoice, editable; an override writes rule-in-force + marker + reason | S3 | **Nothing. This is the highest-value screen in the MVP.** |
| U11 | Receipt — number, printable, PDF | S8, V3 | **Nothing.** |
| U12 | Opening-balance entry — one per student, approval-gated, provenance-marked | S9 | **Nothing.** |
| U12b | **✱ Bulk opening-balance import** — upload · staged preview with row rejects and a control total · approve · post. Posts opening entries, **never payments** | V11, S9 | **BUILT — §9 step 5b-iii, 2026-08-09.** *Moved in from OUT 2026-08-06.* Template (CSV, single sheet, generated from the validator's own `COLUMNS`), upload with a control total, queued validation, findings under the import's privacy discipline, submit for approval; the decision is U16's, with the queue's only irreversibility confirmation. Driven in a browser end to end including the template round trip. **Approve remains undriven** — pressing it consumes the school's one posting slot under G1/G1b, which is permanent. |
| **Corrections** ||||
| U13 | Credit note / write-off — list + detail | Ph7 done | `issue-credit-note-modal` exists; no list. |
| U14 | Void request — list + detail | Ph7 done | `request-void-modal` exists; no list. |
| U15 | Refund — request + approval path | S10 | **Nothing, and now OUT** — follows S10 to §5 on 2026-08-09 (no refund in the last three terms). |
| **Oversight** ||||
| U16 | Approvals queue — must cover **six** request types: fee-schedule change · discount-policy change · credit note · void · opening balance · refund | all | **Five of six, and the sixth is blocked on its domain, not on this screen.** The old "covers four" was wrong twice over: it covered **two**, while four feeds were live and ability-gated at the API — fee-schedule changes and discount-policy changes were rendered nowhere, so a holder of `finance.fee-schedule.change.approve` had no screen at all. §9 step 5a added the opening-balance feed and made the page render every type from one declared list (`resources/js/lib/finance/approval-feeds.ts`), pinned to the registered routes by `ApprovalsQueueFeedCoverageTest` — so this row can no longer be wrong silently. Refund is the only type outstanding and has no domain yet (U15 / S10); when it lands it is one entry. Opening balances are now DECIDED here too: §9 step 5b-ii added `OpeningBalanceBatchPolicy` and the approve/reject routes, so the row carries working buttons — plus the queue's only irreversibility confirmation, because approving one posts the cutover under G1b. The other half closed on 2026-08-09: §9 step 5b-iii shipped the operator screen (U12b), so a batch can now reach `submitted` over HTTP and the feed renders real rows. **Refund is the only type outstanding, and as of 2026-08-09 it is OUT of the cut with S10 — so this row is six-of-six for everything the cut contains, and five-of-six only against the original list.** |
| U17 | Student account statement — staff-facing | — | `statement.tsx` exists. |
| U18 | Outstanding balances by class/year group + Excel export | V5 | **Nothing.** |
| U19 | Activity-log filter for grant changes | S7 | Reuses the existing log; filter only. |
| **✱ Parent — read-only, added 2026-08-06** ||||
| U20 | **Year preview per ward** — the whole academic year's fees, per term and totalled, labelled an estimate | V10 | **Nothing.** Not in v10 at all. **Design ruling 2026-08-09:** render a term's card only when that term's schedule is published; render the year total only when every term in the ward's structure is published; otherwise state which terms are unpublished. See V10. |
| U21 | **Amount owing at this School** — aggregated across every ward the guardian has there, drilling to per-ward and per-invoice | Ph8 partial | **Nothing.** This is the screen that puts a projected balance in front of a parent — see §9 item 3. |
| U22 | **Cross-ward statement** — every payment across the guardian's wards at that School, each row tagged with its ward · filterable per ward · printable (PDF) · exportable (Excel) | Ph8 partial, V3, V5 | **Nothing.** `statement.tsx` is staff-facing and single-student. |
| U23 | **Guardian sign-in surface for finance** — the shell the three screens hang off: School switcher for a guardian with wards at more than one School, ward list, and the boundary that a guardian sees **only** their own wards and **only** within one School at a time | Ph8 partial | **Nothing.** v10's accepted divergence stands: a Bill Payer is linked to wards **within a School**, not across Schools. A guardian with children at two Brookstone Schools sees two separate views, and there is no cross-School total. Say this on the screen or the first such parent will file a bug. |

**Thirteen of twenty-four are nothing at all today** (U2, U3, U5, U8, U10, U11, U12, U15, U18, U20, U21, U22, U23). *Was fourteen; U12b was built 2026-08-09. U15 is on the list but is now OUT of the cut with S10, so twelve of the thirteen are still owed.* **Two of the surfaces that exist are single-student modals where the MVP needs a bulk or an allocation surface.**

**This adds nothing to v10's totals.** Every screen above sits inside a phase scope line v10 already wrote — Ph2 *"admin config UI"*, Ph3 *"pending-approvals queue UI"*, Ph5 *"generation (single + bulk)"* and *"invoice PDF"*, Ph6 *"the allocation engine (configurable + manual)"* and *"opening balances (approval-gated)"*, Ph7 refunds, Ph10 exports. **What changed is my cut, not the plan:** §4.1 and §4.2 listed shapes and named their screens only by implication, and that was under-specified. This table fixes it.

**The six added items DO add, and that is a different claim.** The UI ruling re-described work v10 had already scoped. The six items of 2026-08-06 move real rows across the line: three parent screens plus their shell come from Ph8, the bulk import comes from Ph14, and two things — the guardian year preview (V10) and line-level bank accounts (S11) — are in **neither** v10 nor my earlier cut. §6 re-derives.

**And it removes the cheapest compression lever there was.** "Ship the shapes now, the screens next month" was the one way to make a short deadline by moving work rather than cutting it. That option is now closed by ruling, which is the correct call for a system a bursar has to operate — and it should be known *before* the month-end list is drawn, not after.

---

## 5. Out of the cut

Everything here is deferred, named, and reversible. Nothing here is cancelled.

| Item | v10 home | Cost saved | When it stops being optional |
|---|---|---|---|
| ~~**Parent portal & statements**~~ — **MOSTLY MOVED IN 2026-08-06.** In: the guardian shell (U23), amount owing (U21), cross-ward statement with per-ward tagging, filter, print and export (U22), year preview (U20). Still out: **bulk statement print/email**, any self-service payment, and parent document upload | Ph8 · 4wk ∥ | ~0.5wk of the 4 | This row was the second-largest saving in the cut. It is now largely spent. What remains out is the *bulk* and *transactional* half of Ph8, not the *visibility* half. |
| **Both attachment surfaces** — approval-request attachments **and** `student_documents`, plus the 1E storage primitive | Ph3 · +2wk, 1E +0.5 | **2.5wk** | The single biggest honest saving. Scholarship letters and payment evidence sit in a shared drive for one term. Deferring also **removes Risk 16 entirely** and parks the unanswered parents-upload-their-own-evidence question, which is a security decision nobody has made. |
| **Notifications & dunning** — six triggers, SMS driver, templates, reminder schedules, bulk reminders | Ph9 · 3wk ∥ | 3wk | When chasing by phone stops scaling. Also removes a new external vendor, a per-message cost and a spend cap from term one. |
| **Thirteen of the fourteen reports + the live Financial Dashboard** | Ph10 · 4wk | ~3.5wk | Progressively, from term two. Reports read the projection, which already exists — this is genuinely additive. |
| **Period controls & Audit Dashboard** — period close, reopen workflow, all 11 exception signals, exception reports, severity/module/approval_status columns | Ph11 · 4wk | 4wk | **At the end of term one, not the start.** There is no prior period to close on day one — WCBS holds the past. Note the trap: the three §15F *capture* gaps (S2, S3, S7 above) stay **in** the cut precisely because the dashboard can never derive them later. |
| **Paystack & auto-reconciliation** | Ph12 · 4wk ∥ | 4wk | When manual bank-transfer entry becomes the bottleneck. v10 already documents the manual path as the fallback runbook (Risk 20). |
| **Sage 50 export** | Ph13 · 2wk ∥ | 2wk | Blocked on Brookstone confirming the import format anyway. Zero cost to defer. |
| **Full WCBS migration** — parent + student records, historical references, ≥3 dry runs, zero-orphan assertion, kobo reconciliation | Ph14 · 4wk | ~3.5wk | Replaced by S9 (opening balance per student). Becomes non-optional only if Brookstone needs historical *detail* in the portal rather than a balance forward. Ask them — most schools do not. |
| **Bulk everything *except* invoice generation and opening-balance import** — bulk discount award (Ph4), bulk re-pricing / credit-note-and-reissue (Ph7), bulk statement print/email (Ph8), bulk reminders (Ph9) | Ph4/7/8/9 | ~2wk | Bulk paths belong to the phase owning the single-record path (v10:1131), so every one of these is purely additive later. A bursar can do thirty students by hand for one term. |
| **`Idempotency` table + middleware** | Ph1E | — | Ships with Paystack. Webhooks are the hostile input it exists for; V2's natural key covers bulk invoicing. |
| **Refunds (S10)** — approval-gated cash-out posting, one path, one modal | Ph7 | ~1wk incl. the third-state design | **The first time Brookstone issues a refund.** ~~Moved here 2026-08-09 on their answer that none has been issued in the last three terms.~~ **SUPERSEDED 2026-08-31: that answer was not theirs — see `brookstone-answers-31-august.md` §1. This row is void; refunds are in the launch scope.** It is a *shape*, not a surface, so it does not become additive with time — money leaving the building must be in the ledger. Nothing may pay a refund out by hand in the meantime and call it something else. U15 goes with it. |
| **Sibling credit transfer** — moving a leaver's credit balance onto a sibling's account | **Nowhere in v10. New.** | — | *Added 2026-08-11 by the project lead.* **After U2/U8, post-September. Not cutover-blocking**, because the cutover has an answer that needs no code: the credit imports onto the leaver's own account, which is where it truthfully was on the last day of term (`opening-balance-import-spec.md` R16). What makes it a real item rather than a wish is that **doing it in the spreadsheet instead is refused** — a credit moved between two children in a file has no actor, no date and no reason, and the sibling's opening balance then disagrees with the extract Brookstone sent. **Four design constraints, recorded so whoever scopes it does not rediscover them.** ①  **Its OWN `LedgerEntryType` case, posted on both legs — not `Payment`.** The enum's docblock gives the reason for the credit-note case and it transfers verbatim: a self-describing type *"keeps 'payments received' reporting from ever double-counting it"*, and the `type` column is *"a free varchar (no DB enum/CHECK), so adding a case is a PHP-only change"* (`app/Finance/Enums/LedgerEntryType.php:16-19`). A transfer posted as a Payment is money the school never received appearing in receipts. ②  **A PESSIMISTIC LOCK on the source account.** `RecordAccountPayment` deliberately takes none because *"the amount comes from the request, never from a prior read"* (`app/Finance/Actions/RecordAccountPayment.php:30-31`); a transfer must first check the leaver holds that much credit, which is a genuine read-modify-write. `SubledgerPoster`'s docblock already names where that arrives: *"the pessimistic lock arrives in W3, where applying credit is a genuine read-modify-write of the balance"* (`app/Finance/Services/SubledgerPoster.php:34-35`). ③  **Maker–checker with ED approval**, the `SubmitVoidRequest` / `ApproveVoidRequest` shape — it moves money between two families. ④  **Its own append-only table** carrying from, to, amount, reason, submitter, approver: the ledger rows on both legs need a real `source_type` / `source_id` to point at. |
| **Instalment schedules · late fees · penalties · cross-School anything** | §2.3 | — | **Never.** Already ruled out by Brookstone 2026-07-29 and by §2.1. Listed here only so nobody "restores" them. |

---

## 6. What the cut is worth

I am not going to hand you a week count I have not derived, and v10 is right that no velocity data exists for this team on this codebase (v10:1341) — CI has never run a job, so there is no measurement anywhere in this project.

What I will state:

- **Deferred, before the six items:** 4 + 2.5 + 3 + 3.5 + 4 + 4 + 2 + 3.5 + 2.5 ≈ **29 weeks of scoped work**, of which four phases were parallel and so contribute less than their duration to the critical path.
- **The six items of 2026-08-06 give back roughly 4.5 of those 29.** Ph8's visibility half ≈ 3.5wk (U20–U23 + statement PDF + statement export), Ph14's bulk import ≈ 0.5wk against the S9 baseline already counted, and the two genuinely new items — V10 the year preview and S11 line-level bank accounts — ≈ 0.5wk that appears in **no** v10 number because v10 never scoped them. **Net deferred ≈ 24.5 weeks.** ±30%, and these are judgements.
- **The critical path lengthens back.** Before: `1(partial) → 2 → 3 → 4 → 5 → 6 → 7(partial)`. Now: **`1(partial) → 2 → 3 → 4 → 5 → 6 → 7(partial) → 8(partial)`** — the parent screens read the projection, so they cannot start before Phase 6 settles it. Ph8 was parallel in v10 because it was optional; a parent screen that must be correct on day one is not parallel, it is terminal.
- **The remaining work is not evenly distributed.** S1 (the lock anchor) and V7 (observability) are small in weeks and are the two items v10 names as project-killing. If only two things get done, those are the two — **and the six items made both of them more urgent, not less.** U21 puts the balance projection in front of a parent. That projection is exactly what the unexercised `finance_student_accounts` lock protects, and a silently failed allocation is exactly what V7 exists to catch. Neither S1 nor V7 appears anywhere in the six items, which is why I am putting them back at the top of this list.
- **The UI ruling raises the floor; the six items raise the total.** Twenty-four screens, fourteen of them from zero. No MVP item can be part-shipped: an item is done when a bursar or a parent drives it, or it is not in the month.
- **The six items do not fit in August, and I will not pretend otherwise.** As a set they are Ph2 + Ph5 + Ph8(partial) + part of Ph14, with UI. v10 costs those at 5 + 4 + 4 + 4 weeks. There are about 3.5 weeks left in the month and no velocity measurement anywhere in this project. See §10 for what I would actually put in August and what I would move.

**±30% still applies, and it applies to this cut at least as much as to v10.** These are judgements, not measurements. The value is that the number now has arithmetic behind it you can dispute.

---

## 7. What I need from you

0. **The cutover question — new, and now the most urgent of the four.** Does Brookstone go live **at a term boundary** or **mid-term**? At a boundary, an opening balance is a plain arrears figure and there is no retroactive invoicing. Mid-term, some students have already paid part of a term the portal is about to invoice in full, and the imported figure must be an opening **credit** carrying a paid-to-date, not an arrears. Same import file, two different postings, and the ledger is immutable. This decides §9 item 3 and it decides it before any code is written.
1. **The WCBS question.** Does Brookstone need historical *detail* in the portal, or is a balance-forward per student enough for term one? This single answer is worth ~3.5 weeks and it is a client question, not an engineering one.
2. **The release gate.** Unblock the GitHub billing, or adopt `bin/quality` + pre-push formally as the gate. Right now the project has no gate it has written down, and three PRs merged over red.
3. ~~Tell me what fits before the month ends.~~ **Answered by the six items of 2026-08-06, and re-derived in §10.** What I still need is one line back on §10: which of the six you would move out of August, given that all six do not fit.
4. ~~**Are the fee schedules for all three terms going to exist before term-1 billing?**~~ **WITHDRAWN 2026-08-09 — the question is refused rather than answered.** Boards adjust prices mid-year, so the portal must not require a commitment the school cannot make. V10/U20 absorb it: unpublished terms are hidden and the year total is withheld until the whole structure is published. No Brookstone commitment is needed.
5. ~~**Has a refund been issued in the last three terms?**~~ **ANSWERED 2026-08-09 — NO.** S10
   leaves the cut and takes the third-state design question with it. Moved to §5; see §8.
6. ~~**For a leaver in arrears, does Brookstone want the balance chased, or written off before the
   file?**~~ **ANSWERED 2026-08-12 — CHASED, which is the default that was already ruled.** *Added
   2026-08-11.* The answer **confirms** the ruling rather than changing it: no code, no change to
   the file format, and no change to the substance of the rulings. The substance stays where it is
   written — `opening-balance-import-spec.md` **R18**, with R17's mechanism for the write-off
   exception. What moved is the standing of the ruling, not its content: it was held on the project
   lead's authority and is now also confirmed by the party whose money it is. **This was the last
   open POLICY question on the cutover** — what remains outstanding with Brookstone is the extract
   file itself.
7. ~~**Will the MySQL server timezone be aligned in a maintenance window?**~~ **ANSWERED
   2026-08-11 — NO, AND NOT BY ANY WINDOW** (project lead). Recorded on this list because a reader
   needs to stop expecting a window that is not coming; the open item itself was carried in
   `docs/handoff/tickets/stored-epoch-offset.md`, never here. **Production stays as it is.**

   **It is not a scheduling problem, and reading it as one is the mistake this item exists to
   prevent.** The deployment is **shared hosting**: the global database server clock is physically
   restricted and is not ours to set. There is no maintenance window that would help, because a
   window is not what is missing. The reason that was already recorded still holds and is now the
   second reason rather than the first — changing the server zone would reinterpret **data already
   written**, across `activity_log` and the finance tables, both append-only by trigger and so
   unable to accept the UPDATE that would correct them.

   **Connection pinning was proposed and is REFUSED.** Adding `'timezone' => '+01:00'` to the
   `mysql` block in `config/database.php` is the standard Laravel answer for shared hosting, and it
   is the wrong answer here: **the session zone governs rendering as well as storage, so pinning
   re-renders every `TIMESTAMP` row already written.** The measurement in the ticket is the proof —
   the PHP-written path stores −3600s and reads back 0s, and that cancellation is exactly what
   pinning breaks. On production every PHP-written historical timestamp would then render **4.5
   hours earlier**, across payments, invoices, ledger transactions and the activity log. **And it
   does not even close the gap it targets**: it shrinks the `NOW()`-written error from the scaled
   +19,800s to +3,600s rather than removing it.

   **`App\Support\SchoolDay` absorbs the business-date half permanently**, not as an interim
   measure: dates the application derives come from the school's timezone rather than the server's.
   **What is left is one two-clock residual, and its remedy is a CODE FIX that has not been made** —
   binding a PHP-supplied timestamp in place of the two `NOW()` calls in `SubledgerPoster`. **This
   item is answered; the ticket stays OPEN until that lands in its own commit.**

---

## 8. Where I disagree with myself

Recorded so it is arguable rather than hidden.

- **Deferred income (S5) is the weakest shape claim.** You could argue recognition timing is a reporting concern and post everything to revenue now. I put it in shapes because two posting conventions in one trigger-protected append-only ledger cannot be normalised afterwards. If you disagree, this is the row to attack.
- ~~**Refunds (S10) may not fire in term one at all.**~~ **RESOLVED 2026-08-09.** The question was asked and Brookstone answered NO — no refund in the last three terms. S10 has moved to the OUT column and the third-state design question is parked with it. Recording the resolution rather than deleting the row: this is the one item in this section that the cut was changed by, and how it moved is worth more than the fact that it did.
- **Period controls (Ph11) sit in OUT on a timing argument, not a value argument.** If any student carries a *closed* WCBS period the portal must post against, that argument breaks and period close moves in. This depends on S9's shape and I have not tested the interaction.
- **I had bulk opening-balance import in OUT and that was wrong** (now V11). I applied "bulk paths are additive" mechanically. It is true of bulk discount award, because you can award thirty by hand across a term. It is false of a **one-time cutover of every student in the School on one day** — there the bulk path *is* the path, and hand entry is both slower and less auditable than a file with a control total. Corrected 2026-08-06 on the project lead's item 3.
- **My ≈4.5-week give-back in §6 is the softest number in this document.** It is a judgement about how much of Ph8 the visibility half represents, and Ph8's 4 weeks was itself a ±30% estimate with no velocity behind it. Treat it as an order of magnitude — "the parent items cost most of a phase" — not as arithmetic.

---

## 9. The six added items — 2026-08-06

Verbatim from the project lead, mapped, with one refusal.

### Item 1 — fee templates per class level per term, plus the guardian year preview

**The first half already exists as schema.** I read `database/migrations/2026_07_26_130000_create_finance_fee_schedules.php` in full. `finance_fee_schedules` is keyed `school_id` × `term_id` × `class_level_id`, carries `status` across `draft · active · superseded · retired`, a `supersedes_schedule_id` lookup, a composite `(id, school_id)` unique for child FKs, and **four STORED generated columns** backing two unique indexes so at most one `active` and one `draft` schedule exist per (School, term, class level). `finance_fee_items` hangs off it with `amount_minor` / `amount_currency`, `is_mandatory`, `is_discountable`, `sort_order`, and three parent-state triggers permitting DELETE only while the parent is `draft`. Its docblock says it plainly: *"A per-School catalog of prices keyed to (term × class level)."* **What is missing is U1 — the page.**

**The second half is new** — V10 / U20 above. Not in v10 anywhere. It is a catalog projection, not a statement, and that distinction is the whole design.

### Item 2 — idempotent bulk-invoice job, auto-firing on term start

In as V2 / U6, with the two conditions stated there: **generate to `draft`, release by hand**, and **reconcile Risk 6 first** (`retry_after=90` vs `timeout=3600`). `students.status` already exists and gives the non-withdrawn filter. `ShouldBeUnique` plus a natural key on (student, term, schedule version) gives the re-run safety. The scheduler entry itself is trivial; the control question is not.

### Item 3 — bulk import of opening balances → settlement records

> Verbatim: *"Import each student's current balance; when they're invoiced, the difference between the invoice and the imported balance becomes a settlement/payment transaction. E.g. billed 100, balance imported as 0 → a payment of 100 that settles the invoice."*

**The outcome is right. The mechanism is wrong, and this is the one thing in the six items I am refusing as stated.**

Deriving a `Payment` row from (invoice − imported balance) writes a payment that never happened, into a trigger-protected append-only ledger, under a policy Brookstone Finance co-signed. Concretely, that row:

- has **no bank account** — S6/S11 exist so every payment has a source; this one cannot have one, because no money moved;
- has **no received date** — S2 exists so `effective_at` is real; this one would carry the import date, which is a lie about when the parent paid;
- has **no reference**, and if it takes one it **burns a receipt number** off the gap-free sequence for a receipt nobody issued;
- **is derived from a later event.** Bill 100 against an imported balance of 0 gives a payment of 100. Then issue a credit note for 20, or re-price the term, and the ledger holds an immutable payment of 100 that is now wrong and cannot be edited.

Reconciliation is where this bites hardest: at term end, the sum of payments in the portal will not match the sum of money in the bank, by exactly the value of every fabricated row. That is not a reporting inconvenience — it is the number Internal Audit checks first.

**The correct shape, which gives the same outcome:** import the figure as an **opening entry** — arrears as an opening charge, paid-ahead as an opening credit — approval-gated and provenance-marked, with `effective_at` = the cutover date. v10 Phase 14 already specifies exactly this: *"opening Ledger entries with **provenance markers** (migrated rows have no genuine audit trail)."* Then the existing Phase 6 overpayment → credit → auto-apply path does the settling. Bill 100 against an opening credit of 100 and the invoice settles to zero, automatically, with the ledger telling the truth about where the money came from: a documented off-platform cutover, not a payment the portal received.

**What decides the import format is §7 question 0** — boundary cutover or mid-term. Answer that and the import file's columns follow in an afternoon.

### Item 4 — parent amount-owing per School

In as U21, aggregated across the guardian's wards **at one School**. v10's accepted divergence holds and must be visible on the screen: a Bill Payer is linked to wards within a School, not across Schools, and there is no cross-School total and no cross-School credit transfer.

**This is the item that changes the priority order.** It puts the balance projection in front of a parent. That projection is maintained by the atomic upsert-increment in `SubledgerPoster::post` and protected by a `finance_student_accounts` lock anchor that is **declared and never exercised** — I grepped `lockForUpdate` across the tree and it appears on `sequences`, `Invoice`, `FeeSchedule`, `DiscountPolicy` and `CreditNote`, and on nothing touching `StudentAccount`. Two concurrent payments against a credit balance can double-spend it today. That was a staff-facing bug this morning. With U21 it is a parent-facing one.

### Item 5 — cross-ward statements

In as U22. Every payment across the guardian's wards at that School, each row tagged with its ward, filterable per ward, printable and exportable. This pulls **statement PDF back into V3** and leans on `maatwebsite-excel`, already installed. Bulk print/email stays out.

### Item 6 — school bank accounts, with line-level assignment

Two shapes, not one. **S6** — `finance_bank_accounts` + `bank_account_id` on payments — was already in the cut: where the money came in. **S11 is new** — `bank_account_id` on `finance_fee_items` and `finance_invoice_lines`: where the money should go. I read all four migrations; **neither table has the column**, and neither does `fee_payments`.

Three consequences worth stating before it is built. The account must be **snapshotted onto the invoice line**, not looked up through the fee item, because the line is immutable and the schedule can be superseded. Assigning an account to a fee item is a **fee-schedule change**, so it goes through the existing approval path — it is a price-adjacent field on an approval-gated catalog. And it makes Phase 6's **bank-account mismatch exception real in term one**: money received into account A settling lines destined for account B is now an ordinary occurrence, not a hypothetical, so the allocation screen (U10) has to show it rather than silently allocate across.

---

## 10. August, re-derived

3.5 weeks left. Two developers. No velocity measurement anywhere in this project — CI has never executed a job.

**The six items as a set are Ph2 + Ph5 + Ph8(partial) + part of Ph14, with UI. v10 costs those at 5 + 4 + 4 + 4 weeks.** They do not fit. Nothing I can do to the ordering makes them fit, and the honest thing is to say so before the month rather than after it.

What I would put in August, in this order, and why:

| Slot | Item | Why here |
|---|---|---|
| Week 1, both devs | **S1** (exercise the lock anchor, proven under parallel MySQL load) + **V7** (error tracking + `failed_jobs` alerting) + **V8** (tsc ratchet enforced) | These are hours-to-days, not weeks, and item 4 just made S1 parent-facing. Doing anything else first is building a parent-visible balance on an unprotected projection. |
| Week 1–2, dev A | **S6 + S11 + U3** — bank accounts, both directions, with the page | Item 6 whole. It is shape, it is cheap, and every invoice issued before it exists is permanently silent about where its money should have gone. |
| Week 1–2, dev B | **S2 + U9** — `posted_at` / `effective_at`, user-supplied received date, backdating policy, on the record-payment surface | Shape. Every payment written before this column exists is null forever. |
| Week 2–3.5, both | **S3 + U10** — allocation rule-in-force, override marker, and the allocation screen | The highest-value screen in the MVP and the one v10 says *"Phase 11 cannot derive retroactively at any price."* |

**The rule behind that table:** only shapes and the two project-killing risks go in a month this short. Every row above is something that cannot be added later without rewriting immutable history — or is S1/V7, which v10 names as what actually kills the project.

**What that leaves out of August, explicitly:** items 1, 2, 3, 4 and 5 in full. The fee-schedule page (U1), the bulk invoice job (V2/U6), the opening-balance import (V11/U12b), and all four parent screens (U20–U23) start in September.

**Two honest warnings.** U10 is the row most likely to overrun; if it does, it should eat the U3 or U9 slot rather than part-ship, because a half-built allocation screen writes allocation rows that are wrong in a ledger that cannot be edited. And **all of the above is estimated, none of it is measured** — the first real velocity number this project will ever have is what these four rows actually take.

**What I need back:** one line on which of the six items you would move out of August if you disagree with this ordering, and the answer to §7 question 0.
