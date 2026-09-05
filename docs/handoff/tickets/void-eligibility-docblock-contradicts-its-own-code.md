# `VoidEligibility`'s docblock contradicts its own code

**Status:** open · **Opened:** 2026-09-05 · **Found by:** the void-approval investigation of
2026-09-05 · **Severity:** ticket — no behaviour is wrong; the description of the behaviour is

## What is true

`app/Finance/Services/VoidEligibility.php:18` says the check is used

> Used twice: advisory at submit (a friendly message), and AUTHORITATIVE at approval under
> the invoice-row lock — a payment can land between the two, so approval re-checks.

**It is not advisory at submit.** `app/Finance/Actions/SubmitVoidRequest.php:59-61` throws:

```php
$blocker = VoidEligibility::blocker($invoice);
if ($blocker !== null) {
    throw new BusinessRuleException($blocker);
}
```

## The correct account is on the caller

`app/Finance/Actions/SubmitVoidRequest.php:21-27` describes it accurately, and gives the reason:

> The eligibility check here (no allocated payment, no approved credit note) is a HARD REFUSAL,
> not advisory — and that is correct because BOTH conditions are MONOTONIC: an allocation and an
> approved credit note are append-only/terminal, so once either lands the invoice can never
> become voidable again.

**That paragraph's CONCLUSION survives, but an earlier draft of this ticket got the enforcement
layer wrong on one of the two limbs, and the correction matters more than the conclusion.** The two
conditions are monotonic for DIFFERENT reasons, at different layers:

| condition | why it never reverses | enforced where |
| --- | --- | --- |
| a `PaymentAllocation` row exists (`app/Finance/Services/VoidEligibility.php:26`) | `finance_payment_allocations` carries triggers denying UPDATE **and** DELETE — `database/migrations/2026_07_19_110000_rename_fee_tables_to_finance.php:42-43`. No delete path for that model exists in `app/`. | **DATABASE** |
| an **approved** credit note exists (`app/Finance/Services/VoidEligibility.php:30`) | `approved` is a terminal state in `CreditNote::TRANSITIONS` (`app/Finance/Models/CreditNote.php:76-80`, `'approved' => []`), and `transitionTo()` throws `\DomainException` on an illegal move (`app/Finance/Models/CreditNote.php:162`, `app/Finance/Models/CreditNote.php:165`). DELETE is still DB-denied. | **PHP for the status limb; DATABASE for DELETE only** |

### The credit-note limb is NOT database-enforced, and the trigger this ticket used to cite is gone

An earlier draft said `finance_credit_notes` carries `_no_update` and `_no_delete` triggers, citing
`database/migrations/2026_07_23_120000_create_finance_credit_notes.php:72-73`. **Both triggers are
created there. One of them is dropped six weeks later.**

`database/migrations/2026_07_25_120000_finance_credit_note_maker_checker.php:68`:

```php
//      (no_delete stays: DELETE remains denied throughout.)
DB::unprepared('DROP TRIGGER IF EXISTS '.self::OLD_NO_UPDATE);   // finance_credit_notes_no_update
```

Confirmed against a database that has replayed every migration:

```
finance_credit_notes_insert_guard         BEFORE INSERT
finance_credit_notes_maker_ne_checker_bi  BEFORE INSERT
finance_credit_notes_update_guard         BEFORE UPDATE
finance_credit_notes_maker_ne_checker_bu  BEFORE UPDATE
finance_credit_notes_no_delete            BEFORE DELETE

finance_credit_notes_no_update: ABSENT
finance_credit_notes_no_delete: PRESENT
```

**Does anything at the database level prevent
`UPDATE finance_credit_notes SET status = 'submitted' WHERE status = 'approved'`? NO.** Answered
from the replacement guard's body, not from its name —
`database/migrations/2026_07_25_120000_finance_credit_note_maker_checker.php:123-153`. It has two
arms and neither one fires:

- The immutability arm (`database/migrations/2026_07_25_120000_finance_credit_note_maker_checker.php:129-141`) lists `amount_minor`, `amount_currency`, `invoice_id`,
  `school_id`, `student_id`, `number`, `kind`, `note`, `submitted_by`, `uuid`. **`status` is not in
  it** — the SIGNAL text at `database/migrations/2026_07_25_120000_finance_credit_note_maker_checker.php:140` says so out loud: *"only status/decided_by/decided_at/
  rejection_reason may change."*
- The ceiling arm (`database/migrations/2026_07_25_120000_finance_credit_note_maker_checker.php:143-152`) is conditioned on `NEW.status = 'approved' AND OLD.status <>
  'approved'` — it guards the move **INTO** approved. A move **OUT OF** approved makes
  `NEW.status = 'approved'` false, so it does not fire at all.

**The conclusion still holds, and it now rests on a PHP state machine.** No application path moves a
credit note out of `approved`: `TRANSITIONS` maps `'approved' => []` and `transitionTo()` throws.
So the limb is monotonic in practice — but it is monotonic because of `app/Finance/Models/CreditNote.php`,
not because of a trigger, and a raw `UPDATE` would go straight through. Anyone reasoning about this
invariant from the database outwards would reach the wrong answer.

So the fix to the docblock is to **correct `app/Finance/Services/VoidEligibility.php:18`**, not to
make the submit path advisory. The behaviour is right; the sentence on the class that owns it is
wrong.

### How this error survived the commit's own citation check — the reusable part

**Every `path:LINE` in the old table resolved.** `database/migrations/2026_07_23_120000_create_finance_credit_notes.php:72-73` exists and
names exactly those two triggers, because that is where they are created.
`bin/ci-citation-lint.php`'s `WINDOW` test (`bin/ci-citation-lint.php:209`) asks whether the named
symbol appears near the cited line — and it does.

**What no instrument asked is whether the thing is still there at the time the sentence claims.**
That is RESOLVABILITY, not accuracy, and the distinction is the finding: **a citation gate proves a
line exists; it never proves the claim about it is true.** It is the "read the rename migration, not
the create filenames" trap in a new place — the create migration is the honest-looking citation, and
the later migration that undoes it carries no pointer back.

**No gate is proposed for this, and that is deliberate.** A checker that verified a cited trigger is
still live at HEAD would have to model DDL across the whole migration history — every CREATE, every
DROP, every rename, in order — which is a schema simulator, and the repository already has a cheaper
authority for that question: the database itself, after `migrate:fresh`. The correction here is a
habit, not a mechanism: **when a citation names a database object, read the object, not the
migration that created it.**

## Why it matters

Two docblocks describe one check and disagree, and **the wrong one is on the class that owns the
check** — the file a reader opens first when they want to know what "eligible to void" means.

The concrete failure it invites: somebody reads `app/Finance/Services/VoidEligibility.php:18`, believes submit only
warns, and writes a caller — a batch path, a UI pre-flight, an off-request job — that treats a
non-null `blocker()` as advice and carries on. They then meet a `BusinessRuleException` from a
method they were told was friendly, in an environment where it is expensive to find out.

It is also the sentence that would have to change if the monotonicity assumption ever breaks, and
`app/Finance/Actions/SubmitVoidRequest.php:29-33` already names the case that would break it (refunds). A stale
description is how that warning gets acted on in the wrong file.

**Environment:** every environment. It is a source-level defect and it is invisible to every gate —
no lint reads prose for agreement with the code beneath it.

## What would close it — SEVEN SITES, NOT ONE

An earlier draft said "rewrite `app/Finance/Services/VoidEligibility.php:18`, one sentence". **That
is a half-fix that reads as complete**, because the same wrong description is repeated across the
tree. This is CLAUDE.md's *"re-arming a tripwire means grepping for every SIBLING carrying the same
assertion"*, applied to prose.

**Start from the grep, not from the file:**

```bash
grep -rn "advisory" --include='*.php' --include='*.md' --include='*.tsx' --include='*.ts' . \
  | grep -v '^\./vendor/' | grep -v '^\./node_modules/' | grep -v '^\./build/'
```

Note the QUOTES around each `--include` pattern. Unquoted, zsh tries to glob `--include=*.php`
against the working directory, finds nothing, and aborts the whole command — which prints an error
and **zero hits**, indistinguishable from a clean tree. That happened while deriving this list.

**34 hits, classified. The denominator is the whole non-vendor tree.**

**(i) WRONG — says or implies the submit-time check is advisory: 7**

| site | text |
| --- | --- |
| `app/Finance/Services/VoidEligibility.php:18` | *"advisory at submit (a friendly message)"* — the one the ticket originally named |
| `app/Finance/Actions/ApproveVoidRequest.php:29` | *"the friendly check at submit is advisory, this one decides."* |
| `docs/finance/accounting-policy.md:151` | *"`VoidEligibility` refuses a settled invoice — advisory at submit…"* |
| `docs/handoff/ph3b-remediation-findings.md:12` | *"(advisory at submit, authoritative at…"* |
| `docs/handoff/maker-checker-two-instance-diff.md:108` | *"advisory at submit, authoritative at approval"* |
| `tests/Feature/Finance/FinanceApiAcceptanceTest.php:932` | *"(the submit-time check is only advisory)"* — **test comment** |
| `tests/Feature/Finance/FinanceApiAcceptanceTest.php:975` | *"(advisory guard at submit)"* — **test comment** |

**THE TWO TEST COMMENTS ARE THE WORST OF THE SEVEN, and they deserve a separate line.** A comment on
an acceptance proof that describes the behaviour backwards is worse than one in a docblock, because
the proof beneath it is the evidence. `tests/Feature/Finance/FinanceApiAcceptanceTest.php:975` is the sharpest: it calls the submit guard advisory on
the line directly above an assertion that a fresh submit returns **422**. The code asserts the
opposite of its own comment.

`docs/finance/accounting-policy.md:151` is the highest-authority one — the accounting policy
document, read by people who will never open the class.

**(ii) CORRECT — says it is a hard refusal: 3**

`app/Finance/Actions/SubmitVoidRequest.php:23` (*"not advisory — and that is correct because BOTH
conditions are MONOTONIC"*); `app/Finance/Actions/SubmitVoidRequest.php:34` (the refund paragraph —
it says the check *must revert to* advisory once refunds exist, which is correct as a conditional
about the future); `tests/Feature/Finance/InvoiceSettlementTest.php:190` (*"Not merely advisory: the
request is never created…"*).

**(iii) UNRELATED uses of the word: 17** — `app/Finance/Actions/ReturnInvoice.php:74` and `app/Finance/Actions/ReturnInvoice.php:185`
(the released-bill guard, a different rule), `tests/Feature/Finance/OpeningBalanceSingleColumnTest.php:303`,
`docs/handoff/staging-integration-decision.md:67`, `docs/handoff/opening-balance-import-spec.md`
(3 hits), `docs/handoff/tickets/classification-gate-accepts-any-string-but-one.md:62`,
`docs/handoff/drive-runbooks/m4-rollover-surface.md:149`,
`docs/handoff/reports/feat-gateway-transaction-table.md` (7 hits — "the payments advisory" is a
document's name), `docs/handoff/reports/feat-u8-invoice-modal-discount-policy.md:704`.

**(iv) This ticket and its report, describing the error: 7** — 5 in this file, 2 in
`docs/handoff/reports/docs-four-findings-from-the-void-investigation.md`. One of the five is a
verbatim quote of the wrong text and must stay wrong.

`7 + 3 + 17 + 7 = 34`, the full denominator.

**Out of scope, accounted for rather than ignored:** `./build/` holds **10** further hits and
`./node_modules/` **6** — compiled and vendored artefacts, not sources. `./vendor/` holds 16. None is
edited; they are named here so the grep's excluded arms are not a silent gap. There is no phpstan
cache directory on this tree.

**The correction itself** is the same in all seven: the submit-time check is a **hard refusal**, and
approval re-checks authoritatively under the invoice-row lock. Fix the corpus, not the line.

There is no mechanism to propose for the prose itself, and that is worth saying plainly rather than
inventing one: a gate that checks prose against code does not exist in this repository and building
one for a two-word error would cost more than the class of defect it prevents. What makes it
findable is that the CALLER's docblock is the detailed one —
`app/Finance/Actions/SubmitVoidRequest.php:21-33` argues the monotonicity and names the refund case
that would end it — so a reader who reaches either file has the argument in front of them.
