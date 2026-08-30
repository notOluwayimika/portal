<?php

use App\Finance\Http\Controllers\BankAccountController;
use App\Finance\Http\Controllers\BulkInvoiceRunController;
use App\Finance\Http\Controllers\CreditNoteController;
use App\Finance\Http\Controllers\DiscountAwardImportController;
use App\Finance\Http\Controllers\DiscountPolicyChangeController;
use App\Finance\Http\Controllers\DiscountPolicyController;
use App\Finance\Http\Controllers\FeeScheduleChangeController;
use App\Finance\Http\Controllers\FeeScheduleController;
use App\Finance\Http\Controllers\FinanceAccountController;
use App\Finance\Http\Controllers\InvoiceController;
use App\Finance\Http\Controllers\ManualInvoiceRunController;
use App\Finance\Http\Controllers\ManualInvoiceRunStudentController;
use App\Finance\Http\Controllers\OpeningBalanceBatchController;
use App\Finance\Http\Controllers\PaymentAllocationController;
use App\Finance\Http\Controllers\PaymentController;
use App\Finance\Http\Controllers\VoidRequestController;
use Illuminate\Support\Facades\Route;

/*
 * Finance manual entry points (no automated billing trigger; that needs the
 * enrollment-create fan-out convergence + EnrollmentCreated event, 1.4e).
 * Required into api.php inside an auth + role group.
 *
 * Path is /api/v1/finance/* — the frozen Finance API prefix (§16); every Finance
 * aggregate hangs off /api/v1/finance from here on.
 */
Route::post('/v1/finance/invoices', [InvoiceController::class, 'generate'])
    ->middleware('permission:finance.invoice.generate');
Route::post('/v1/finance/invoices/{invoice:uuid}/payments', [PaymentController::class, 'store'])
    ->middleware('permission:finance.payment.record');

/*
 * U10 — WHERE AN ALREADY-RECORDED PAYMENT'S REMAINDER SETTLES. Read half (this commit): the engine's
 * proposed split across the student's open invoices, computed and returned, with no write path.
 *
 * `finance.payment.allocate` and NOT the group's `finance.access`, even though every figure this
 * returns is already on the statement. The gate is here so the proposal and the submit that follows
 * answer to one seat; see PaymentAllocationController's docblock, and ADR 0048 D1 for what happened
 * the last time a payment surface shipped under `finance.access` alone.
 */
Route::get('/v1/finance/payments/{payment:uuid}/allocation-proposal', [PaymentAllocationController::class, 'proposal'])
    ->middleware('permission:finance.payment.allocate');

/*
 * …and the submit that turns an edited proposal into rows. SAME permission as the read above, because
 * it is one act: directing money that has already arrived.
 *
 * NOT MAKER-CHECKER, and that is a decision with its reasoning written down in
 * App\Finance\Actions\AllocatePayment — every action behind ApprovalRequirement reduces a receivable,
 * and an allocation reduces nothing: the student's balance is identical before and after, because the
 * ledger credit was posted when the payment was recorded. What is proportionate instead is on the row:
 * the operator is named (allocated_by_user_id), a departure from the proposal carries a marker and a
 * required reason, and the table is append-only. The Action's docblock also records what would reopen
 * the decision.
 *
 * THE WRITE IS APPEND-ONLY AND THEREFORE FINAL. finance_payment_allocations carries _no_update and
 * _no_delete (2026_07_19_110000), so there is no un-allocate route here and there must not be one
 * added casually — a correction is a compensating write with its own design, not an edit.
 */
Route::post('/v1/finance/payments/{payment:uuid}/allocations', [PaymentAllocationController::class, 'store'])
    ->middleware('permission:finance.payment.allocate');

/*
 * Invoice VOID is MAKER-CHECKER (Ph3b) — the second instance of the credit-note template. The
 * one-step cancel is RETIRED: reversing a whole charge takes two people, so it is a lifecycle:
 *   • SUBMIT (maker) proposes a void — the invoice stays issued, in the balance, no money moves.
 *   • APPROVE (checker ≠ maker) voids the invoice + posts the reversing ledger entry (−total).
 *   • REJECT (checker ≠ maker) closes it with a reason — the charge stands, no money.
 * Each route needs finance.access (the group) AND its own maker/checker permission; the
 * record-level maker ≠ checker rule is the VoidRequestPolicy (Gate::authorize in approve/reject),
 * with the DB CHECK as the backstop. The queue is unified with credit notes in the checker's screen.
 */
Route::post('/v1/finance/invoices/{invoice:uuid}/void-requests', [VoidRequestController::class, 'submit'])
    ->middleware('permission:finance.invoice.void-request.submit');
Route::get('/v1/finance/void-requests/pending', [VoidRequestController::class, 'pending'])
    ->middleware('permission:finance.invoice.void-request.approve');
/*
 * U14 — THE DECIDED FEED, AND IT IS GATED MORE BROADLY THAN /pending ON PURPOSE. See the block above
 * the credit-note twin for the full argument; in one line: `…approve` gates a worklist that precedes
 * an act, and this is a read of an act already taken.
 */
Route::get('/v1/finance/void-requests/decided', [VoidRequestController::class, 'decided']);
Route::post('/v1/finance/void-requests/{voidRequest:uuid}/approve', [VoidRequestController::class, 'approve'])
    ->middleware('permission:finance.invoice.void-request.approve');
Route::post('/v1/finance/void-requests/{voidRequest:uuid}/reject', [VoidRequestController::class, 'reject'])
    ->middleware('permission:finance.invoice.void-request.reject');

/*
 * Credit-note issuance is MAKER-CHECKER (Ph3). Forgiving money takes two people, so it is
 * a lifecycle, not one call:
 *   • SUBMIT (maker) proposes a pending credit note — no money moves.
 *   • APPROVE (checker ≠ maker) posts the compensating ledger credit + moves the balance.
 *   • REJECT (checker ≠ maker) closes it with a reason — never any money.
 * Each route needs finance.access (the group) AND its own maker/checker permission; the
 * record-level maker ≠ checker rule is the CreditNotePolicy (Gate::authorize in approve/
 * reject), with the DB CHECK as the backstop. The pending queue is the checker's screen.
 */
Route::post('/v1/finance/invoices/{invoice:uuid}/credit-notes', [CreditNoteController::class, 'submit'])
    ->middleware('permission:finance.credit-note.submit');
Route::get('/v1/finance/credit-notes/pending', [CreditNoteController::class, 'pending'])
    ->middleware('permission:finance.credit-note.approve');
/*
 * U13 — THE DECIDED FEED. NO MIDDLEWARE BEYOND THE GROUP'S `finance.access`, and the asymmetry with
 * the line above it is the decision, not an omission.
 *
 * `/pending` carries `finance.credit-note.approve` because it PRECEDES AN ACT: it is the checker's
 * worklist and every row on it is something they are about to do. This route precedes nothing. Both
 * statuses it serves are terminal, the money has already moved or has already been refused, and no
 * route in this file can move either row again. Reading what happened is a different capability from
 * being trusted to decide it, and the seat that reconciles the term's corrections is not the seat
 * that signs them.
 *
 * NOTHING IS COINED, AND NOTHING IS WIDENED. A new `finance.credit-note.view-decided` would gate a
 * set that `finance.access` ALREADY reaches: the statement's own feed
 * (GET /v1/finance/students/{student}/invoices, no middleware beyond the group) returns
 * creditNotesForStudent() and voidRequestsForStudent(), neither of which filters on status — so
 * every decided note and void request on this branch is readable today by any holder of
 * `finance.access` who knows which student to open. What was missing was not secrecy but
 * FINDABILITY: no surface anywhere lists them, so a decided document leaves the approvals queue and
 * appears on no list in the application.
 *
 * The precedent is U11's receipt, one door along and reasoned identically (routes/web.php):
 * `finance.payment.record` is the authority to TAKE money and the receipt takes only
 * `finance.access`, because it is a read of money already taken. ADR 0048's D1 draws the same line
 * from the other side — money-in is its own capability BECAUSE it moves receivables; a read moves
 * nothing.
 *
 * WHAT IS NEW ON THE WIRE HERE is the CHECKER's name (`decided_by_name`), which no resource emitted
 * before. It is the maker's counterpart, the maker's name has been served under `finance.access`
 * since Ph3, and an audit trail that names one of the two signatures answers nothing.
 */
Route::get('/v1/finance/credit-notes/decided', [CreditNoteController::class, 'decided']);
Route::post('/v1/finance/credit-notes/{creditNote:uuid}/approve', [CreditNoteController::class, 'approve'])
    ->middleware('permission:finance.credit-note.approve');
Route::post('/v1/finance/credit-notes/{creditNote:uuid}/reject', [CreditNoteController::class, 'reject'])
    ->middleware('permission:finance.credit-note.reject');

/*
 * Read side. Voided invoices are excluded by default; ?include_void=1 is the
 * explicit audit view. The exclusion lives in the read model, not a global scope —
 * a global scope would make the {invoice:uuid} bindings above miss a voided
 * invoice and turn the double-void 422 into a 404.
 */
Route::get('/v1/finance/students/{student:uuid}/invoices', [InvoiceController::class, 'forStudent']);

/*
 * The accounts index — the bursar's front door. A School-scoped, paginated read over
 * finance_student_accounts (per-row balance + live student display via the ACL port) plus
 * the School-wide KPI totals. Read-only; ?search=, ?status=, ?sort= drive the view.
 */
Route::get('/v1/finance/accounts', [FinanceAccountController::class, 'index']);

/*
 * Fee schedules (S1 commit 2, narrowed in commit 4) — the per-School pricing catalog per (term × class
 * level). Reads carry only the group's finance.access; DRAFT authoring (store/supersede/editDraft) needs
 * finance.fee-schedule.manage. As of commit 4 `store`/`supersede` write DRAFTS ONLY — the act of making a
 * schedule `active` has moved entirely into the fee-schedule-change approval below, so there is no route
 * by which a schedule reaches `active` without an approved change (proof 31). `prefill` resolves the
 * ACTIVE schedule's items into prefilled charge lines for the bursar's generate form (a draft never prices).
 */
Route::get('/v1/finance/fee-schedules', [FeeScheduleController::class, 'index']);
Route::get('/v1/finance/fee-schedules/prefill', [FeeScheduleController::class, 'prefill']);
Route::post('/v1/finance/fee-schedules', [FeeScheduleController::class, 'store'])
    ->middleware('permission:finance.fee-schedule.manage');
Route::put('/v1/finance/fee-schedules/{feeSchedule:uuid}', [FeeScheduleController::class, 'supersede'])
    ->middleware('permission:finance.fee-schedule.manage');
// Edit a draft in place: label, and items replaced wholesale. A SUB-RESOURCE rather than a second verb on
// the collection member, because the two operations are genuinely different — this one mutates the bound
// row, `supersede` above leaves it alone and authors a new draft beside it. Same permission: both are
// draft authorship, and neither can make a schedule billable.
Route::put('/v1/finance/fee-schedules/{feeSchedule:uuid}/draft', [FeeScheduleController::class, 'editDraft'])
    ->middleware('permission:finance.fee-schedule.manage');

/*
 * Fee-schedule governance (S1 commit 4). PUBLISHING a schedule is maker-checker: the Head submits a
 * publish/retire change (finance.fee-schedule.change.submit), the ED approves/rejects (…approve / …reject).
 * A schedule reaches `active` ONLY when ApproveFeeScheduleChange approves.
 *
 * THIS COMMENT USED TO CLAIM the pending queue "joins the unified approvals screen by the
 * ApprovalAbility convention (no route edit)", and it was false for the eighteen commits it stood.
 * The convention derives who may OPEN the queue (routes/web.php's permission gate); it has never had
 * anything to say about which feeds the page FETCHES, and the page fetched two hardcoded imports. A
 * holder of finance.fee-schedule.change.approve could open a screen that never asked this endpoint
 * anything. §9 step 5a is what made the sentence true: the page now maps one declared list
 * (resources/js/lib/finance/approval-feeds.ts) over every feed, and a route that is not in that list
 * is caught by ApprovalsQueueFeedCoverageTest rather than by nobody.
 */
Route::post('/v1/finance/fee-schedule-changes', [FeeScheduleChangeController::class, 'submit'])
    ->middleware('permission:finance.fee-schedule.change.submit');
Route::get('/v1/finance/fee-schedule-changes/pending', [FeeScheduleChangeController::class, 'pending'])
    ->middleware('permission:finance.fee-schedule.change.approve');
Route::post('/v1/finance/fee-schedule-changes/{change:uuid}/approve', [FeeScheduleChangeController::class, 'approve'])
    ->middleware('permission:finance.fee-schedule.change.approve');
Route::post('/v1/finance/fee-schedule-changes/{change:uuid}/reject', [FeeScheduleChangeController::class, 'reject'])
    ->middleware('permission:finance.fee-schedule.change.reject');

/*
 * Discount policies (S1 commit 3, axis A). The catalog is read-only here — editing and removing are
 * `amend` and `retire` change requests, never a direct PUT/DELETE. Governance is maker-checker: the
 * Head submits create/amend/retire (finance.discount-policy.change.submit), the ED approves/rejects
 * (…approve / …reject). The catalog changes ONLY when ApproveDiscountPolicyChange approves; the pending
 * queue is on the unified approvals screen because it is an entry in that page's declared feed list —
 * see the correction on the fee-schedule block above, which this comment carried the same way.
 */
Route::get('/v1/finance/discount-policies', [DiscountPolicyController::class, 'index']);
Route::post('/v1/finance/discount-policy-changes', [DiscountPolicyChangeController::class, 'submit'])
    ->middleware('permission:finance.discount-policy.change.submit');
Route::get('/v1/finance/discount-policy-changes/pending', [DiscountPolicyChangeController::class, 'pending'])
    ->middleware('permission:finance.discount-policy.change.approve');
Route::post('/v1/finance/discount-policy-changes/{change:uuid}/approve', [DiscountPolicyChangeController::class, 'approve'])
    ->middleware('permission:finance.discount-policy.change.approve');
Route::post('/v1/finance/discount-policy-changes/{change:uuid}/reject', [DiscountPolicyChangeController::class, 'reject'])
    ->middleware('permission:finance.discount-policy.change.reject');

/*
 * THE BSS DISCOUNT-AWARD IMPORT — Brookstone's accounts team holds the scholarship list outside the
 * system, as a spreadsheet pairing each student with the percentage they were awarded, and these
 * four routes are how it comes in: template out, list in, status back, report down.
 *
 * ALL FOUR CARRY `finance.discount-award.manage`, NEWLY COINED, and the coining is the decision.
 * `finance.access` is the door onto the finance pages; `finance.invoice.reduction.apply` is one
 * reduction on one invoice a bursar is looking at. An award is a STANDING price for a named child,
 * every term, until someone changes it — neither of those is that authority, and borrowing either
 * would give it to every seat that already holds them. The reads carry the same ability as the write
 * for the reason the opening-balance maker's five routes do: the person who uploads the list is the
 * person who reads back what happened to it.
 *
 * THE ROUTE GATE IS NOT THE ONLY GATE, AND THAT IS DELIBERATE. `AwardStudentDiscount` asserts the
 * same ability against the uploader on EVERY ROW, inside the queued job, with no middleware within
 * reach. Eleven guardian routes were once unguarded in this codebase because the check lived where
 * the caller composed it rather than where the action was; a second caller reaching that Action —
 * another controller, a command, a future screen — meets the gate regardless of what its route says.
 * The two arms that prove each half separately are in DiscountAwardImportTest.
 *
 * ORDER IS LOAD-BEARING. `import/template` is declared BEFORE `{uuid}` because Laravel matches in
 * declaration order and would otherwise bind the literal string "import" as an import uuid — the
 * same trap the opening-balance block below records, and the same fix.
 *
 * IT IS NOT MAKER-CHECKER, and the reasoning is beside the gate in AwardStudentDiscount rather than
 * only here: Brookstone's approval is on the VALUE — which percentages, off which part of the bill —
 * and that is the discount-policy change flow above, with the ED as checker. This says only WHICH
 * approved policy a student sits on. A second chain would ask the ED to re-sign their own decision
 * once per child.
 */
Route::get('/v1/finance/discount-award-imports/template', [DiscountAwardImportController::class, 'template'])
    ->middleware('permission:finance.discount-award.manage');
Route::post('/v1/finance/discount-award-imports', [DiscountAwardImportController::class, 'store'])
    ->middleware('permission:finance.discount-award.manage');
Route::get('/v1/finance/discount-award-imports/{uuid}', [DiscountAwardImportController::class, 'show'])
    ->middleware('permission:finance.discount-award.manage');
Route::get('/v1/finance/discount-award-imports/{uuid}/report', [DiscountAwardImportController::class, 'report'])
    ->middleware('permission:finance.discount-award.manage');

/*
 * Opening-balance cutover — the read feed (§9 step 5a) and, as of §9 step 5b-ii, THE DECISION.
 *
 * The block previously read "THE READ SURFACE ONLY … this route does not open one … no HTTP path".
 * All three are false below this line and the rewrite is deliberate: a comment that contradicts the
 * routes under it is worse than no comment, because it is read as a statement about what the
 * feature can do.
 *
 * WHAT IS TRUE NOW. Deciding a batch is HTTP: approve and reject delegate to 4c's Actions, which own
 * the transaction, the locked re-read of `status` and the submitter, and — on approve — the post
 * itself. SUBMITTING IS STILL NOT HTTP. The maker's screen (spec §2's U12b) is the next commit, so
 * nothing can currently move a batch into `submitted` and `pending` returns an empty feed to every
 * checker. The exit is built before the entrance on purpose: the other order is what left 5a's two
 * feeds and 5b-i's template shipped and reachable from no screen.
 *
 * THE TWO DECISIONS ARE SEPARATELY GATED, on `…approve` and `…reject`, because they are two abilities
 * and the seat that holds one need not hold the other. Both end in a CHECKER segment, so
 * ApprovalAbility excludes them from the super-admin Gate::before bypass (ADR 0040); the record-level
 * maker ≠ checker rule is OpeningBalanceBatchPolicy, and the database's
 * `…_maker_ne_checker` CHECK is the backstop under both.
 *
 * APPROVE IS THE POST AND IT IS IRREVERSIBLE. One request writes the cutover into the subledger and
 * G1b denies every exit from `posted`. The queue therefore confirms this type's approval and no
 * other's — see resources/js/lib/finance/approval-feeds.ts. That dialog is a courtesy; these
 * middleware, the Policy, the Action's lock and the database are the controls.
 */
Route::get('/v1/finance/opening-balance-batches/pending', [OpeningBalanceBatchController::class, 'pending'])
    ->middleware('permission:finance.opening-balance.approve');
Route::post('/v1/finance/opening-balance-batches/{batch:uuid}/approve', [OpeningBalanceBatchController::class, 'approve'])
    ->middleware('permission:finance.opening-balance.approve');
Route::post('/v1/finance/opening-balance-batches/{batch:uuid}/reject', [OpeningBalanceBatchController::class, 'reject'])
    ->middleware('permission:finance.opening-balance.reject');

/*
 * §9 step 5b-iii — THE MAKER's half, spec §2's U12b. The block above is the checker's.
 *
 * ALL FIVE ARE THE SUBMIT (MAKER) ABILITY, the same one 5b-i put on the template, and for the same
 * reason: the person who downloads the format is the person who uploads the file, reads its findings
 * and offers it for approval. None of these can post anything — `submit` moves a `validated` batch to
 * `submitted` and stops, and the post is the checker's approval (§8).
 *
 * ORDER IS LOAD-BEARING. `pending` and `import/template` are declared BEFORE `{batch:uuid}` because
 * Laravel matches in declaration order and both would otherwise be swallowed as a uuid — a checker
 * fetching the queue would get a 404 for a batch called "pending".
 *
 * `store` VALIDATES NOTHING SYNCHRONOUSLY. It inserts the batch (so §7's idempotency key is enforced
 * by the engine and the operator has something to poll), stores the file at a path derived from the
 * new row's uuid, and dispatches. There is no `file_path` column and no `report_path` column: the
 * path is derived and the report is rendered on demand from `findings` and the staged rows.
 */
Route::post('/v1/finance/opening-balance-batches', [OpeningBalanceBatchController::class, 'store'])
    ->middleware('permission:finance.opening-balance.submit');
Route::get('/v1/finance/opening-balance-batches', [OpeningBalanceBatchController::class, 'index'])
    ->middleware('permission:finance.opening-balance.submit');
Route::get('/v1/finance/opening-balance-batches/{batch:uuid}', [OpeningBalanceBatchController::class, 'show'])
    ->middleware('permission:finance.opening-balance.submit');
Route::get('/v1/finance/opening-balance-batches/{batch:uuid}/report', [OpeningBalanceBatchController::class, 'report'])
    ->middleware('permission:finance.opening-balance.submit');
Route::post('/v1/finance/opening-balance-batches/{batch:uuid}/submit', [OpeningBalanceBatchController::class, 'submit'])
    ->middleware('permission:finance.opening-balance.submit');

/*
 * §9 step 5b-i (R13) — the import template the platform ISSUES. Brookstone downloads it here; nobody
 * emails a hand-made spreadsheet, because that is a second source of truth for a money format. The
 * workbook renders ImportOpeningBalances::COLUMNS, so the file the operator fills in and the file the
 * validator reads back cannot drift apart. This is the download ONLY — the upload screen is 5b-ii.
 *
 * Gated on the MAKER half of §9 step 4c's triple, not the checker's: the person who downloads the
 * template is the person who will upload the file. Nothing new is coined here.
 *
 * It sits under `opening-balance-batches` rather than a second `opening-balances` noun — the brief
 * wrote the latter, but one feature answering at two nouns is how a route list stops being readable,
 * and `pending` above already owns this prefix. Path shape otherwise matches
 * GET /api/guardians/import/template exactly.
 */
Route::get('/v1/finance/opening-balance-batches/import/template', [OpeningBalanceBatchController::class, 'template'])
    ->middleware('permission:finance.opening-balance.submit');

/*
 * Bill a STUDENT (the bursar UI's path). Enrollment resolution is server-side via the
 * ACL port, so the frontend never handles an enrollment id. The read powers the "New
 * invoice" modal's episode-confirm + F7 preview; the write generates and delegates to the
 * same GenerateInvoice (unchanged). The old enrollment-id POST above stays for the harness.
 */
Route::get('/v1/finance/students/{student:uuid}/billable-enrollment', [InvoiceController::class, 'billableEnrollment']);
Route::post('/v1/finance/students/{student:uuid}/invoices', [InvoiceController::class, 'generateForStudent'])
    ->middleware('permission:finance.invoice.generate');

/*
 * Record a payment ON THE ACCOUNT — no invoice named (the "money at the window" door). Banks as
 * account credit and settles oldest-first at the next generation (ADR 0048). Gated on
 * finance.payment.record (ADR 0048 D1), held by accounts_officer only, so recording money IN is a
 * distinct capability from viewing finance — a fabricated payment discharges real receivables (D2),
 * so finance.access alone must not reach here. super_admin stays on both payment routes: record is
 * not a checker ability, so the Gate::before bypass applies. Mirrors the student-addressed invoice POST above.
 */
Route::post('/v1/finance/students/{student:uuid}/payments', [PaymentController::class, 'storeForStudent'])
    ->middleware('permission:finance.payment.record');

/*
 * BANK ACCOUNTS (S6/U3 commit 1) — the accounts money lands in, per School.
 *
 * All four gated on finance.bank-account.manage: this is finance CONFIGURATION, and it follows
 * finance.fee-schedule.manage's shape (a `manage` verb, no maker-checker triple) because a bank
 * account is a description rather than a decision — there is nothing for a second signature to
 * approve.
 *
 * THERE IS NO DELETE ROUTE, and there must never be one. A bank account that has received money has
 * to stay nameable forever; retirement is `deactivate`, which withdraws it from choice while leaving
 * every historical reference resolvable. BankAccountRouteTest fails if a destroy route appears.
 */
Route::get('/v1/finance/bank-accounts', [BankAccountController::class, 'index'])
    ->middleware('permission:finance.bank-account.manage');
Route::post('/v1/finance/bank-accounts', [BankAccountController::class, 'store'])
    ->middleware('permission:finance.bank-account.manage');
Route::patch('/v1/finance/bank-accounts/{bankAccount:uuid}', [BankAccountController::class, 'update'])
    ->middleware('permission:finance.bank-account.manage');
Route::post('/v1/finance/bank-accounts/{bankAccount:uuid}/deactivate', [BankAccountController::class, 'deactivate'])
    ->middleware('permission:finance.bank-account.manage');
Route::post('/v1/finance/bank-accounts/{bankAccount:uuid}/reactivate', [BankAccountController::class, 'reactivate'])
    ->middleware('permission:finance.bank-account.manage');

/*
 * BULK INVOICE RUNS (U6 commit 4) — bill a whole cohort, and read back exactly who was and was not
 * billed. The domain is commit 3's: the run row, the queued job, the four outcomes and the
 * reconciliation. These four routes are its operator surface.
 *
 * ALL FOUR CARRY `finance.invoice.generate`, AND NOTHING NEW IS COINED. A bulk run raises the same
 * document, through the same GenerateInvoice, under the same rule as the single-student POST above —
 * so the authority to raise one invoice is the authority to raise forty. A `…generate-bulk` minted
 * here would be granted to precisely the roles that already hold `generate`, deciding nothing, while
 * adding a second case that can drift out of step with the first. The reads carry the same ability as
 * the write for the reason the opening-balance maker's five routes do: the person who starts a run is
 * the person who reads it back.
 *
 * ORDER IS LOAD-BEARING. `preview` is declared BEFORE `{run:uuid}` because Laravel matches in
 * declaration order and would otherwise bind the literal string "preview" as a run uuid — the same
 * trap the opening-balance block above records, and the same fix.
 *
 * `preview` IS A GET AND WRITES NOTHING. No run row, no dispatch. It exists because starting a run is
 * irreversible in practice: undoing one is a maker-checker void request per child, so the operator
 * gets the cohort size, the schedule that would be pinned and the refusal — if there is one — before
 * they commit rather than after.
 *
 * THERE IS NO DELETE AND NO CANCEL, and there must not be one. A run is a record of what happened,
 * and `finance_bulk_invoice_runs` is the only thing that accounts for the students a run did NOT
 * bill. Re-running is the recovery path and it is safe by construction: the per-episode unique index
 * on `finance_invoices` refuses a second scheduled invoice, so a re-run records those students as
 * `already_billed` rather than billing them twice.
 */
Route::get('/v1/finance/bulk-invoice-runs/preview', [BulkInvoiceRunController::class, 'preview'])
    ->middleware('permission:finance.invoice.generate');
Route::get('/v1/finance/bulk-invoice-runs', [BulkInvoiceRunController::class, 'index'])
    ->middleware('permission:finance.invoice.generate');
Route::post('/v1/finance/bulk-invoice-runs', [BulkInvoiceRunController::class, 'store'])
    ->middleware('permission:finance.invoice.generate');
Route::get('/v1/finance/bulk-invoice-runs/{run:uuid}', [BulkInvoiceRunController::class, 'show'])
    ->middleware('permission:finance.invoice.generate');

/*
 * THE MANUAL INVOICE RUN — a bursar's own list of students, one supplementary invoice each, from
 * lines they typed. Two routes and no more; the filter-and-tick screen is a later commit and brings
 * whatever reads it needs with it.
 *
 * SAME ABILITY AS EVERY OTHER INVOICE ROUTE, and nothing new is coined. `finance.invoice.generate`
 * already governs the single-student POST and all four bulk-run routes on exactly this reasoning:
 * the authority to raise one invoice is the authority to raise ninety. A `…generate-manual` minted
 * here would be granted to precisely the roles that already hold `generate` — deciding nothing while
 * adding a second case to keep in step. The read carries the same ability as the write for the
 * reason the opening-balance maker's five routes do: the person who starts a run is the person who
 * must read it back.
 *
 * NO PREVIEW, AND THE ASYMMETRY WITH THE BULK RUN IS DELIBERATE. A scheduled run's cohort is
 * COMPUTED — the operator names two coordinates and the server decides who that is, so a preview is
 * the only way to see the list before committing to it. A manual run's list is GIVEN: the operator
 * IS the preview, and they are looking at the students they ticked. What they cannot see in advance
 * is which of them the server can place, and that is answered by the run report rather than by a
 * second endpoint — there is no maker-checker on this path, so the report is where a wrong selection
 * has to surface anyway, and one place is better than two that can disagree.
 *
 * THERE IS NO DELETE AND NO CANCEL, for the reason the block above states and one worse. A run is
 * the record of a billing act and the only thing that accounts for the students it did NOT bill, so
 * deleting one destroys the evidence. And re-running is NOT the recovery path it is on the scheduled
 * side: a supplementary invoice has no duplicate backstop at any layer
 * (docs/handoff/tickets/a-supplementary-invoice-has-no-duplicate-backstop.md), so a second run over
 * the same list bills everyone on it a second time.
 *
 * ORDER IS NOW LOAD-BEARING, WHICH IT WAS NOT WHEN THIS BLOCK WAS WRITTEN. The paragraph here used
 * to read "there is no literal-segment route to shadow `{run:uuid}`", and the screen commit made
 * that false by adding `/students` below. `{run:uuid}` is a BINDING, not a pattern — nothing
 * constrains the segment to look like a uuid — so declared in the other order it would swallow
 * `/v1/finance/manual-invoice-runs/students` and answer 404 from a failed model binding, which
 * reads as "the roster is empty" on the screen rather than as a routing mistake. The literal
 * segment is declared FIRST, and the trap the two blocks above record is the reason.
 *
 * THE ROSTER IS THE READ THE SCREEN BRINGS WITH IT, exactly as this block already anticipated. It
 * is a THIRD route on this feature and it is not a third scope: it answers a PAGE of students to
 * tick, never a set of ids for the client to act on in bulk. See
 * ManualInvoiceRunStudentController for why the screen cannot simply fetch `/api/students` — in
 * one line, `student.view` and `finance.invoice.generate` intersect on `admin` alone, so the
 * bursar seat this feature exists for would meet a 403 where the roster should be.
 *
 * SAME ABILITY AGAIN. A read of who might be billed is not a smaller authority than the write that
 * bills them, and a feed gated differently from the page that consumes it is how a visible control
 * comes to 403 on click.
 */
Route::post('/v1/finance/manual-invoice-runs', [ManualInvoiceRunController::class, 'store'])
    ->middleware('permission:finance.invoice.generate');
Route::get('/v1/finance/manual-invoice-runs/students', [ManualInvoiceRunStudentController::class, 'index'])
    ->middleware('permission:finance.invoice.generate');
Route::get('/v1/finance/manual-invoice-runs/{run:uuid}', [ManualInvoiceRunController::class, 'show'])
    ->middleware('permission:finance.invoice.generate');
