# Finance visual drive — 2026-07-25

First drive off the committed fixture (`php artisan finance:seed-drive-fixture`, drive env, port 8001,
puppeteer-core + system Chrome). The point of a drive is the thing the harness cannot see: a 200 with
the right list, a 200 with an empty list, and a 200 rendering an error where a list should be are the
same assertion to an acceptance test. This drive found **one real defect the harness is blind to** —
exactly the payoff the drive-enablement work was for.

Screenshots in this folder (`NN-name.png`), captured as the noted user.

## Confirmed rendering correctly ✓

- **Settlement, four states** (`02`/`03`/`04`/`05`/`06`): unpaid shows all three actions; **settled**
  (`03`) suppresses Record Payment at the invoice level, keeps **Submit credit note offered**, and
  shows **Request void disabled** (greyed) — hide-what's-meaningless / disable-what-a-rule-forbids, as
  designed. The account-level **Record payment** button is present in the header (advance path).
- **Two orthogonal axes** (`03`/`05`): the invoice row shows the **document** badge (Issued/Void) and,
  beneath it, the **settlement** badge (Unpaid/Part-paid/Settled) as *separate* badges — most
  importantly on Pat's invoice 000007 (`05`), which carries **Issued + Unpaid** badges *and* a distinct
  **"Void requested"** action state: the pending void does not collapse the badges.
- **Pending moves no money** (`05`): Pat's pending credit note renders **struck-through** and the
  account balance excludes it (₦5,000 = the two unpaid invoices, not −₦500).
- **Unified approvals queue, full checker** (`09`): a **TYPE** column with a **CREDIT NOTE** row and a
  **VOID** row merged into one table, both Approve/Reject — the two feeds in one queue.
- **`finance:reconcile-accounts` runs clean** on the finished fixture (8 accounts, no drift) — every
  state was produced by the real Actions, not hand-written rows.

## Defects observed — NOT fixed (per brief: a drive that repairs what it finds destroys the evidence)

### D1 — a void-only checker is locked out of the entire approvals page (real defect)

`08-queue-void-only-checker.png`: signed in as the checker holding `finance.invoice.void-request.approve`
but **not** `finance.credit-note.approve`, `/finance/approvals` returns a **full-page 403 "User does not
have the right permissions."**

**Cause:** the page route is gated on a single permission —
`routes/web.php:140` → `->middleware('permission:finance.credit-note.approve')`. So the unified queue's
**per-feed 403-tolerance** (`Promise.allSettled` over the two pending feeds, designed so a one-sided
checker sees only their feed) **never executes**, because the page itself never loads. The API
acceptance test (`FinanceApiAcceptanceTest` "UNIFIED QUEUE …") proved the *feeds* (void 200, credit 403)
but is structurally blind to the *page-route middleware* — which is precisely the class of gap a visual
drive exists to catch.

**Suggested fix (for its own slice):** gate the page on *either* checker ability —
`permission:finance.credit-note.approve|finance.invoice.void-request.approve` — so any approver reaches
the queue and the client-side per-feed tolerance does its job. **Not fixed here.**

## Open-question answers (advance-payment edges from Decision 3 — sets the account-endpoint priority)

### O1 — a student with NO invoices has no advance-payment affordance

`07-no-invoices.png` (Emma Empty): the statement header shows only **Refresh** and **New invoice** — the
account-level **Record payment** button is **absent**. The button targets the latest non-void invoice
(`invoices.filter(status!=='void').at(-1)`); with none, it disappears. So a parent cannot bank an advance
payment for a student who has never been billed.

### O2 — a student whose ONLY invoice is void looks identical to one never billed

`06-only-void.png` (Otto Onlyvoid): his single invoice is void, so the default statement reads **"No
invoices yet"** (void excluded by §4) and, like Emma, shows **no account-level Record payment button**.
A voided-only account is visually indistinguishable from an unbilled one on the default view.

**Both confirm** the settlement slice's flagged follow-up: the account-level Record Payment riding on an
invoice is a stopgap. A **dedicated account-scoped payment endpoint** is the fix, and these two states
are why it is more than cosmetic — there is currently *no* payment path for these students. Priority:
after D1.

## Not captured this session (with why + where they are covered)

- **Super-admin failing to approve** — the super-admin has no finance permission, and D1 means even
  reaching `/finance/approvals` needs `finance.credit-note.approve`; the bypass-exclusion is proven by
  `FinanceApiAcceptanceTest` "VOID INHERITED — a super_admin cannot approve".
- **The interactive void loop** (request→approve→re-invoice→reject click-through) — the *states* are
  seeded and captured (pending void on Pat `05`, approved void on Otto `06`), and the transitions are
  proven by the VOID PROOFs in the acceptance harness. The click-through itself was not scripted.

## Drive-env fix made (config, not Finance code — in scope)

The drive origin (`:8001`) had to be added to `SANCTUM_STATEFUL_DOMAINS` (both `.env.drive` and the
committed `.env.drive.example`), or the SPA's `/api/v1/finance/*` calls 401 and every statement renders
"Could not load the statement" — the first thing this drive hit. Also note: under `php artisan serve`
(single-threaded) the SPA can lose the CSRF race on the very first paint; the drive script reloads once
on the error state.
