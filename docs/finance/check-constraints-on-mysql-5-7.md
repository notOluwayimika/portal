# CHECK constraints on production, which runs MySQL 5.7

Production is `md-24.webhostbox.net`, **MySQL 5.7.23-23**, `explicit_defaults_for_timestamp = 0`.
Local is `Oregs-2.local`, **MySQL 8.0.43**, `explicit_defaults_for_timestamp = 1`. Both readings were
taken 2026-08-17 by the project lead through phpMyAdmin and a local client; neither is inferred.

MySQL enforces `CHECK` constraints only from **8.0.16**. Before that the clause is, in MySQL's own
words, "parsed and ignored" — accepted by the parser, absent from `SHOW CREATE TABLE`, never
evaluated. Counted as objects in the schema rather than as declarations in the source — several
migrations loop a single declaration over a list of columns — a fully migrated database holds
**27 `CHECK` constraints**. **None of them is enforced on production.** They are enforced locally,
which is why nothing ever noticed.

Count objects, not declaration sites. An earlier draft of this document counted sites, got the
number wrong, and produced a total that collided with the post-migration object count. The database
is the thing being described; count what is in it.

This is not a regression. It is a belief the repository has been carrying: several migration
docblocks and two ADR-adjacent documents describe these constraints as the independent database-level
backstop, and on production there is no such backstop. Production has run this way throughout.

## What still works on 5.7

Everything else the schema relies on. `TRIGGER` with `SIGNAL SQLSTATE '45000'` is MySQL 5.5-era, so
the reduction guard on `finance_invoice_lines`, the `no_update` trigger on
`finance_ledger_transactions`, the credit-note insert guard, the void-request no-delete trigger and
the policy-immutability trigger are all real on production. `FOREIGN KEY`, `UNIQUE`, `NOT NULL`,
column types and generated columns are all real. It is specifically and only `CHECK`.

That matters for the remedy: a trigger is not a workaround, it is the enforcement mechanism this
schema already uses in **35** places before this migration and 49 after, and it behaves identically
on both servers.

## The 19 sites, classified by what actually defends them on production

The question for each is not "is it declared" but "if the declaration does nothing, what is left".

### Group A — segregation of duties. Three live app layers; the CHECK was the raw-write backstop.

Six tables carry `CHECK (submitted_by IS NULL OR decided_by IS NULL OR submitted_by <> decided_by)`:
`subject_result_statuses`, `finance_credit_notes`, `finance_void_requests`,
`finance_discount_policy_changes`, `finance_fee_schedule_changes`, and
`finance_opening_balance_batches` (which names the same fact `submitted_by_user_id` /
`decided_by_user_id`).

What is live on production without them:

1. **Six Policies**, each refusing the maker — `SubjectResultPolicy:68`,
   `DiscountPolicyChangePolicy:36`, `FeeScheduleChangePolicy:35`, `CreditNotePolicy:55`,
   `OpeningBalanceBatchPolicy:58`, `VoidRequestPolicy:48`.
2. **Ten Actions**, each re-checking independently of the Policy. Every approve/reject Action in
   `app/Finance/Actions/` was read and all ten carry an explicit
   `(string) $x->submitted_by === (string) $checker->id` refusal: `ApproveCreditNote`,
   `ApproveDiscountPolicyChange`, `ApproveFeeScheduleChange`, `ApproveOpeningBalanceBatch`,
   `ApproveVoidRequest`, and the five matching `Reject*`.
3. **`DutySeparation`** at grant time, which refuses to leave one role holding both sides of a
   maker-checker pair (`DutySeparationViolationException`).

So a self-approval through the application is refused three times. What the missing `CHECK` costs is
the fourth line: a write that never passes through the application at all — a SQL console, a future
job, a bulk correction, a restored dump. That is exactly the case the constraint was written for;
`VoidRequestPolicy:20` says so in as many words ("stops it even for a raw write that never reaches
the Policy"). **Recommended for a trigger** — not because self-approval is likely today, but because
this is the audit-facing control on the whole approval architecture and it is currently a claim
rather than a fact on the server that matters.

### Group B — the cutover invariant. One thin layer.

`finance_payments` carries two: `origin IN ('portal','migrated')`, and the pairing
`(origin = 'portal' AND bank_account_id IS NOT NULL) OR (origin = 'migrated' AND bank_account_id IS
NULL)`. The pairing subsumes the domain constraint — an origin outside the pair fails both arms.

What is live: the `Payment::ORIGIN_PORTAL` / `ORIGIN_MIGRATED` constants, and
`PostOpeningBalanceBatch:254` writing the literal `'migrated'`. That is a convention, not a guard.
Nothing refuses a row that pairs `migrated` with a bank account.

One thing does degrade safely: `Payment::isReceiptable()` (`app/Finance/Models/Payment.php:173`) is
an allowlist on `origin === ORIGIN_PORTAL`, so a row with an unexpected origin refuses to print
rather than printing. The allowlist decision taken in U11 pays for itself here.

**Recommended for a trigger, and it is the most time-sensitive of the set.** The Brookstone cutover
writes migrated payments in bulk through `PostOpeningBalanceBatch`. This is the one constraint in the
19 that the import will exercise at volume, on the server where it does not exist.

### Group C — the column type already carries it. Thirteen constraints, no trigger.

**Thirteen** currency-shape constraints of the form
`CHECK (col IS NULL OR col COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}$')`, on thirteen tables:
`finance_invoice_lines`, `finance_invoices`, `finance_ledger_transactions`, `finance_payments`,
`finance_payment_allocations`, `finance_credit_notes`, `finance_fee_items`,
`finance_student_accounts`, `finance_discount_policies` and `finance_discount_policy_changes` (all
ten from `2026_08_01_120000`, which loops one declaration over a ten-entry list), plus
`ob_batches_control_total_currency_shape`, `ob_rows_balance_currency_shape` and
`ob_rows_student_total_balance_currency_shape` on the two opening-balance tables.

Every one of these columns is `CHAR(3)`, so length is enforced by the column type on 5.7 — the type
is not a `CHECK` and does not depend on the version. What is lost is only "the three characters are
uppercase A–Z". The platform is single-currency NGN, and `SubmitDiscountPolicyChangeRequest:40`
already validates `regex:/^[A-Z]{3}$/` at the edge. Twenty-six triggers — thirteen columns, insert
and update — to buy back a case-and-alphabet rule on columns that can only hold three characters is
not a trade worth making. **Documented, not triggered.**

### Group D — validated at the edge, narrow blast radius. Four constraints, no trigger.

- `terms` date order (`end_date > start_date`) — `TermController.php:88` validates
  `['required','date','after:start_date']`.
- `scores_range` (0–100) — `UpsertScoreRequest.php:37` validates `min:0` / `max:100`, and `:39`
  marks the raw `score` field `prohibited` so it cannot be posted directly at all.
- `student_curricula` promoted-requires-link — `promoted_to_id` is not mass-assignable from a
  request; `StudentRequest.php:76-82` explicitly refuses it and says why. It is written in one place,
  `StudentCurriculumController.php:226`, alongside the status it must accompany.
- `finance_discount_policies_basis_exclusive` (percent XOR amount) —
  `SubmitDiscountPolicyChangeRequest.php:35-41` covers it with `required_if` / `prohibited_if` on
  every field of both arms, and the policy row is only ever written by
  `ApproveDiscountPolicyChange` from an already-validated change row. Two layers.

`basis_exclusive` is the closest call in this group and the first candidate if the trigger set is
ever extended. It guards money semantics — a policy that is simultaneously a percentage and a fixed
amount is a double discount — but it is two layers deep at the edge and the table is not written by
the cutover.

### Group E — shape-by-kind on the change table. Two constraints, no trigger.

`finance_discount_policy_changes_target_shape` and `_terms_shape` encode "a create names no target,
an amend/retire must name one" and "a create/amend carries full terms, a retire carries none". Both
are enforced by `SubmitDiscountPolicyChangeRequest` and by the single Action that writes the row.
Same reasoning as Group D.

## The recommended set

**Seven rules, on seven tables**: the six Group A maker≠checker rules, and the Group B pairing on
`finance_payments`. That replaces **8** of the 27 constraints — Group B is two constraints on one
table, since the pairing subsumes the origin-domain rule — and leaves **19** in place: Group C's
thirteen, Group D's four, Group E's two. Fourteen triggers, seven tables.

The arithmetic, so it can be checked rather than believed: 6 + 2 + 13 + 4 + 2 = 27 before;
27 − 8 = 19 after.

Deliberately not "reimplement all 27". Every trigger is a new object that fires on every write to its
table, and fifty-four of them added days before cutover buys back guards the application already
holds while adding fifty-four new ways for a write to fail. The seven above are the ones with either
no second layer (Group B) or a second layer that a raw write bypasses entirely (Group A).

## A design point the implementer must measure, not assume

If the trigger is added *alongside* the existing `CHECK`, then on local (8.0.43) both exist and one
of them wins. The Pest suite currently asserts MySQL error **3819** (`ER_CHECK_CONSTRAINT_VIOLATED`)
in **31 places across 13 test files**. A trigger raises **1644** instead. Whichever fires first
determines whether those 31 assertions still pass, and that ordering is a measurement, not a
recollection.

The cleaner resolution is to make the two servers agree: the migration drops the `CHECK` where it
exists and installs the trigger everywhere, so there is exactly one enforcement mechanism and it
behaves identically on 5.7 and 8.0. `DROP CHECK` is 8.0.16 syntax and the constraint does not exist
on 5.7, so the drop must be guarded by an `information_schema.TABLE_CONSTRAINTS` lookup — which
returns zero on 5.7 and skips. That guard pattern is already in this repository, in the `down()` of
`2026_08_07_110000_add_provenance_to_finance_payments.php:119` and three siblings.

## What is not settled here

Whether production should move to MySQL 8.0 at all. It is shared hosting, the timeline belongs to the
host, and a major-version move days before cutover carries its own risk — so the triggers are the
answer for now, not the answer forever. If production does reach 8.0.16+ later, an enforced `CHECK`
could be added back over the top of the triggers, and at that point the existing data must already
satisfy it or the `ALTER` is refused.
