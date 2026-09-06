# The void path writes no activity row

**Status:** open · **Opened:** 2026-09-06 · **Found by:** the return-history readership measurement
of 2026-09-06 · **Severity:** ticket — no behaviour is wrong and no audit trail is missing; a
SECONDARY record that every sibling Finance decision keeps is not kept for this one.

## What is true, measured

`activity(` call sites across the whole of `app/Finance` — **199 PHP files EXAMINED**, 6 code call
sites found, 0 unrecognised:

| action | `activity(` | site |
| --- | --- | --- |
| `app/Finance/Actions/ApproveInvoice.php` | **1** | `:197` — `finance.invoice.approved` |
| `app/Finance/Actions/ReturnInvoice.php` | **1** | `:214` — `finance.invoice.returned` |
| `app/Finance/Actions/SubmitVoidRequest.php` | **0** | — |
| `app/Finance/Actions/ApproveVoidRequest.php` | **0** | — |
| `app/Finance/Actions/RejectVoidRequest.php` | **0** | — |

The other four sites are `app/Finance/Http/Controllers/BankAccountController.php:188`,
`app/Finance/Actions/SettleGatewayTransaction.php:416`,
`app/Finance/Actions/AwardStudentDiscount.php:274` and
`app/Finance/Actions/SetSettlementBankAccount.php:113`. `2 + 4 = 6`, the whole denominator — so the
void trio is the only Finance governance path with none.

**Controls.** Positive: `grep -c 'class'` returned ≥1 in all five action files, so the matcher reads
them. Absent: `grep -c 'zzzNoSuchTokenXyz'` returned 0 in all five, so it is not matching everything.
The `activity(` count for `ApproveVoidRequest` / `SubmitVoidRequest` / `RejectVoidRequest` is a
`grep` exit **1**, not a silent 0.

**And it is genuinely absent, not written somewhere else.** The claim of absence needed the
exhaustive search, so here it is:

- neither `app/Finance/Models/Invoice.php` nor `app/Finance/Models/VoidRequest.php` uses
  `LogsActivity` — `grep -c LogsActivity` returns **0** on both, against **4** on
  `app/Finance/Models/StudentDiscountAward.php` as the positive control. `StudentDiscountAward` is
  the ONLY Finance model that carries the trait, out of 38 trait users across `app/`;
- no observer is registered for either model — `app/Providers/AppServiceProvider.php:106` registers
  exactly one observer in the whole application, and its subject is `StudentCurriculum`;
- the only callers are `app/Finance/Http/Controllers/VoidRequestController.php:33`, `:47` and `:63`,
  and that controller's own `activity(` count is **0**, as is
  `app/Finance/Services/SubledgerPoster.php`'s.

So a void submit, a void approval and a void rejection each write **no row to `activity_log`**.

## What a reader actually loses — and what they do not

**They do not lose WHO and WHEN.** The columns exist on the invoice row and are populated:
`cancelled_at`, `cancelled_by_user_id`, `cancel_reason` are all present in the schema (verified
against the local database's `SHOW COLUMNS FROM finance_invoices`; absent control
`zzz_no_such_col` returned NO). `finance_void_requests` additionally holds `submitted_by`,
`decided_by`, `decided_at` and `rejection_reason` (read from `SHOW COLUMNS` — 13 columns; absent
control `zzz_no_such_col` returned NO). It is append-only: it refuses every DELETE
(`database/migrations/2026_07_25_140000_create_finance_void_requests.php:105`) and every update beyond
those four columns (`:114-125`, whose `SIGNAL` text names them). The maker≠checker pairing is
enforced by triggers rather than by convention —
`database/migrations/2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php:302-303` installs the INSERT
and UPDATE arms.

**They lose the entry in the FEED.** Concretely, three things:

1. **A reader working from the activity log sees the release and the return of a bill and no sign of
   its void.** The audit page and the audit API are the surface where a seat asks *"what happened to
   this invoice"* without knowing in advance which table to open, and for a void that question
   returns nothing.
2. **The SEQUENCE is not reconstructible from one place.** `finance.invoice.returned` and
   `finance.invoice.approved` are timestamped rows in one ordered stream; the void's who/when live
   in two other tables and have to be joined in by hand, by somebody who already knows they exist.
3. **The rejection of a void request has no cheap witness at all.** It is the one decision of the
   three whose only record is `finance_void_requests.rejection_reason` on a row nobody browses.

**Stated at its right size: this is a gap in a secondary record.** The primary record —
append-only rows with the actor, the timestamp and the reason, protected at the database — is
intact. Nothing about a void is unrecoverable. What is true is narrower and still worth a ticket:
**the log is not a complete account of Finance's governance decisions, and it reads as though it
is**, because every neighbouring decision is in it.

## Why it matters NOW rather than generally

`docs/handoff/what-correct-returned-invoice-must-satisfy.md:12` carries Brookstone's settled
requirement 3 verbatim:

> a full record: the reason for return, what the bill said before and says now, who changed it, when;

and requirement 4 at `:13`:

> that history visible to Finance and Internal Audit only, never to the parent.

**A correction to a returned bill is a void underneath.** The same document measures it: there is no
in-place edit path for an issued invoice, so a correction is a void plus a re-raise, and the void
half routes through `finance.invoice.void-request.approve` — held by `executive_director` alone
(`docs/handoff/what-correct-returned-invoice-must-satisfy.md:599`).

So the moment requirement 3 is implemented against the activity log — which is where the return half
already lives, at `app/Finance/Actions/ReturnInvoice.php:214-227` — **the correction's own history
will have a hole in exactly the middle of it.** The return is logged, the re-release is logged, and
the reversal that is the point of the correction is not. That is a worse shape than logging none of
it, because the feed reads as complete.

**Environment:** every environment. It is a source-level absence, invisible to every gate — no lint
asserts that a governance action emits an activity row.

## What would close it — PROPOSED, NOT CHOSEN. Do not build this from this ticket.

Three options, with what each costs. Naming a default rather than asking an open question, per the
house rule that the framing decides the answer.

**(1) Emit from the three actions, inside the existing transaction — the default.** Three
`activity('finance')` blocks mirroring `app/Finance/Actions/ApproveInvoice.php:197-208`, with events
`invoice.void_requested`, `invoice.voided`, `invoice.void_rejected`. Cost: three small diffs, three
new event keys, and a decision on each key's entry in `config/activity_log_severity.php` and
`config/activity_log_sensitive.php`. **Measured: neither file declares any key containing `void`** —
`grep -n void` over both returns exit 1, against 18 `finance` hits in the severity map as the
positive control. So all three keys would be new. **Note the trap that makes that worth checking:**
`finance.fee_adjusted` and `finance.refund_issued` ARE declared sensitive with no emitter, listed in
that file's own `pending_emitters` — declaring a key ahead of its emitter is established practice
here, so the answer was not obvious without looking.

*And there is a gate on the way in.* `bin/ci-activity-catalogue-lint.php` runs as
`bin/quality` step **20** (`bin/quality:498`), and it requires a non-constant event to carry an
`// @activity-emits log_name.event` declaration (`bin/ci-activity-catalogue-lint.php:139`). Note
what it does and does not do: it checks that a DECLARED emitter is catalogued. **Nothing asserts
that a governance action HAS an emitter** — which is why this absence survived to be found by hand,
and is the reason it is worth writing down rather than assuming a gate would have caught it.

*The event-name shape is not free either.* `app/Finance/Actions/ApproveInvoice.php:103-111` records that
`invoice.approved` carries a dot against the house's one-segment shape, deliberately, to match the
permission it attests to. A void emitter should make the same choice consciously rather than copy it.

**(2) A `LogsActivity` trait on `app/Finance/Models/VoidRequest.php`.** Cheaper to write, and worse:
it logs the WRITE rather than the ACT, so a submit and an approval are two `updated` rows the reader
must interpret, and it fires on any future writer including a backfill. `StudentDiscountAward` is the
one Finance model that does this and it carries an explicit comment (`:86`) saying the trait exists
to complement an action-level `activity('finance')` call, not to replace it.

**(3) Do nothing, and say so in the docblocks.** Legitimate — the primary record is intact. Its cost
is that the next reader re-derives this absence, as this one did, and that requirement 3's answer has
to explain why one third of the correction is not in the feed.

**Whichever is chosen, the bite-proof is the same and it is not "an activity row exists".** Assert
the EVENT KEY and the CAUSER, on a path where no sibling emitter could produce the row — otherwise
the arm passes on `finance.invoice.returned` written earlier in the same fixture.

## Cross-references

- `docs/handoff/what-correct-returned-invoice-must-satisfy.md` §E and §"Requirement 4, both halves" —
  the merged measurement this ticket falls out of, and see its 2026-09-06 correction block.
- `docs/handoff/tickets/void-eligibility-docblock-contradicts-its-own-code.md` — the other open
  ticket on this path; different class (a description that is wrong, rather than a record that is
  absent).
