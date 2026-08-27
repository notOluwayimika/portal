# Implementation report — `feat/gateway-transaction-table`

> **Revised after review, 2026-08-27.** The first version of this branch omitted the four
> boundary §5 / §8.2 / §14 settlement requirements — the gateway fee, the settlement reference,
> the settlement date and the raw webhook payloads — and did not mention the omission. I was
> working from the advisory only and did not have the boundary document; that explains it and does
> not excuse it, since §8.2's whole reason for putting that data in scope is that it **cannot be
> recovered afterwards**. They are in this migration now, with the table still at zero rows. The
> sections below are updated throughout; the correction to the update guard that this forced is in
> **Deviations 4**, and it is the most important thing on this page.

**This is full-review tier** — it adds a migration, a money-adjacent table, DB triggers and
`school_id` isolation constraints. Recommend a cold session against this file before merge.

## Headline

Done, with four design deviations named below (the fourth is a correction of my own first version). Step 2 of the payments advisory §6 — the gateway
transaction table, its status enum and its migration — is implemented, shape-verified from
`information_schema`, and bite-proven with five watched reds.

Base: `origin/staging` @ `6f54a18a`. Branch: `feat/gateway-transaction-table`. Shape: five new
files (one enum, two models, one migration, one test file) plus this report. Two commits — the
second is the settlement data and the guard correction it forced.

## Deviations from the brief

The brief here is the advisory §6 step 2, which reads: *"Gateway transaction table, status enum,
migration — shape-verified from `information_schema` in the manner of `2026_08_25_100000`, unique
index on the gateway reference."* Three departures:

**1 · The files are NOT under `app/Finance/Gateway/`, which §3 of the advisory suggested.** The
enum is at `app/Finance/Enums/GatewayTransactionStatus.php` and the model at
`app/Finance/Models/GatewayTransaction.php` — the ordinary directories.

§3 named the cost of the nested layout itself: `App\Finance\Gateway\Models` escapes the arch rule
`arch('Finance models are School-scoped')->expect('App\Finance\Models')->toUse(BelongsToSchool)`
(`tests/Arch/ArchitectureBoundaryTest.php:57-59`), and escaping an arch rule should be a decision
someone wrote down rather than a side effect of a directory. For a model and an enum there is no
reason to escape: neither wants the DB facade, both want school-scoping. So they sit where the rule
can see them. The controllers and the Paystack client of steps 3–6 are a separate question and this
change does not pre-empt it — **nothing here forecloses `app/Finance/Gateway/` for the HTTP layer.**

**The general rule I am asserting, so it can be checked:** *put a file where the existing arch
rules apply to it unless you have a reason to want them off, and record the reason where the escape
happens.* I believe this is right; it is the kind of rule that is right most of the time.

**2 · "Unique index on the gateway reference" became TWO unique indexes, because there are two
references and they answer different questions.** `reference` is ours (generated at initiation,
echoed by the provider); `provider_reference` is theirs (learned at verification). A single index
on either one alone leaves the other free to duplicate. There is also a third,
`UNIQUE (payment_id)`, which is not a reference at all — it is the constraint that makes *one
payment per attempt* a database fact rather than handler discipline, and it is what step 4's
"idempotent by unique-index collision, never check-then-insert" is meant to collide with.

**3 · "Raw webhook payloads" became a CHILD TABLE, not a `payload` column.** §5 says payloads,
plural, and the plural is load-bearing: one transaction receives several messages — `charge.success`,
then a verify response when the payer returns, then a settlement event days later. A single JSON
column holds the last one and **silently destroys the others on each write**, which is the exact loss
§8.2 exists to prevent, arriving through the mechanism meant to prevent it. So
`finance_gateway_transaction_events` is append-only by the Constitution §15C idiom (`_no_update` /
`_no_delete`), with a `_source_guard` on `webhook` | `verify`, a JSON `payload` (asserted to be
`json` and not `text` — a TEXT column takes a truncated body silently), and a composite
`(gateway_transaction_id, school_id)` FK. It records rejected deliveries too; nothing in it asserts
the payload was trusted, because a delivery that failed signature verification is exactly the one an
investigation wants.

This is the one place I widened the ask from "four columns" to a table, so it is the one to push back
on if you disagree.

**4 · THE CORRECTION, and it is the most important thing in this report: my first update guard would
have made the §5 settlement columns physically unwritable.** It made `success` terminal
*absolutely* — any UPDATE of a settled row refused. But **settlement happens after success; that is
what settlement is.** The provider collects on Friday and pays out on Tuesday, and the fee, the
settlement reference and the settlement date are all reported in that later event. The suite would
have stayed green, because nothing existed yet to write them; it would have been discovered at the
first real payout, on a live table.

Terminality is now narrowed to what it was actually for — stopping a replayed webhook re-settling —
and the mechanism is **write-once** rather than freeze-the-row: `success` is terminal *for status*;
every fact reported by the provider (`provider_reference`, `paid_at`, `payment_id`,
`failure_reason`, the fee pair, `settlement_reference`, `settled_at`) may go NULL → value exactly
once and never value → value; identity and the amount are immutable from insert. That NULL → value
asymmetry is what makes a NULL in these columns mean *"not reported yet"* and never *"possibly
overwritten by a later delivery"* — a reconciliation that cannot trust that distinction cannot use
the column at all.

**The general rule, stated so it can be checked:** *a guard that freezes a row must be checked
against every fact that arrives later than the state it freezes on.* I got this wrong once here.

**5 · The table carries no `student_id`,** though every sibling `finance_` table does. It is
derivable from `invoice_id` by one join and no consumer of this table has a read that cannot afford
that join, so a denormalised copy would be a second place for one fact to live. `school_id` is
present and is not the same case — it is the isolation boundary, uniform by convention, and the two
composite FKs are what stop it diverging from the parents it was copied from.

**6 · `initialised` is documented as a deliberate absence, not left silent.** §5 names five states
and the enum has four. A row is INSERTED at initiation — that write *is* the initialise — so a
distinct `initialised` case would be a state no transaction occupies for the duration of a single
statement, and `pending` would then mean "initialised and still waiting", the same thing said twice.
That is the same reasoning that kept `approved` out of `OpeningBalanceBatchStatus`. The enum docblock
now says so, and says what would change the decision: if initiation ever becomes two steps — a row
written before the provider has accepted the checkout — the fifth case is owed, and that is a
migration plus a trigger change.

## The discrepancy-report interaction, decided now

You are right that `failed` and `abandoned` being non-terminal would make every abandoned checkout
ever "stuck beyond age" in §6 step 7's query. The decision, taken here and written into the enum
docblock so step 7 inherits it rather than re-deriving it:

**Non-terminal is not the same as unresolved, and the stuck query is over `pending` only.** Three of
the four states are non-terminal in the *database* sense — the guard will still let their row change.
Only `Pending` is unresolved in the *business* sense, the one state where this system is still
waiting to be told something. `Failed` and `Abandoned` are ANSWERED: the provider told us the
outcome, and they stay writable solely because that answer can be superseded, not because anyone is
waiting on them.

This is a decision record, not a control — nothing enforces it until step 7's query is written.
Flagging it as such rather than dressing it as enforcement.

## For Developer 1 — two deviations from written contract, visible rather than discovered

- **§6's idempotency is literally "duplicate webhook attempts an insert, hits the constraint".
  Mine is a compare-and-swap on `payment_id`.** The row already exists from initiation, so a
  duplicate delivery is an UPDATE, not an INSERT — there is no insert for a constraint to catch.
  The equivalent is `UPDATE … SET payment_id = ? WHERE id = ? AND payment_id IS NULL` inside the
  same transaction as `RecordPayment`, with the affected row count as the answer, backed by
  `UNIQUE (payment_id)`. Still a database primitive, still never a check-then-insert, but it is not
  what the contract says and should be agreed rather than assumed.
- **`invoice_id` is REQUIRED, which quietly answers section 11 decision 5** (one invoice per
  checkout, not several). It is the shape the designed flow has, and this table is not append-only
  so widening the grain later is an ordinary migration — but the decision was Developer 1's to make
  and I have made it by building.

## Contradictions of the premise

**None found.** Every §1 claim I depended on re-verified against the repo at this base:

- `Payment::ORIGIN_GATEWAY` exists (`app/Finance/Models/Payment.php:98`) and `isReceiptable()` is
  an allowlist of `[PORTAL, GATEWAY]` (`:239`).
- The origin pairing is a trigger pair, not a `CHECK`, and its `gateway` arm requires a bank
  account (`database/migrations/2026_08_25_100000_finance_payment_origin_admits_gateway.php`).
- `finance_invoices_id_school_unique` and `finance_payments_id_school_unique` both exist
  (`2026_07_19_110001_enforce_finance_child_school_integrity.php:33-34`), which is what makes the
  two composite FKs below possible. I checked this rather than assumed it — without those parent
  unique keys the composite FKs would have failed at `ALTER`.

**The §2 blocker is untouched and still open.** Nothing in this change chooses a settlement bank
account, and nothing here needs one: this table records the checkout, not the payment. The blocker
bites at step 4.

## What changed

| File | Lines | What it does |
|---|---|---|
| `database/migrations/2026_08_27_100000_create_finance_gateway_transactions.php` | — | Creates `finance_gateway_transactions` **and `finance_gateway_transaction_events`**, three composite FKs, five indexes, two currency-shape `CHECK`s and **six** triggers — then reads every one of them back out of `information_schema` (columns, index column SETS, constraint names, the `payload` data type) and throws rather than record itself if the shape is wrong. |
| `app/Finance/Enums/GatewayTransactionStatus.php` | 72 | `pending` · `success` · `failed` · `abandoned`. A reader of the trigger's domain, not a second copy of it. |
| `app/Finance/Models/GatewayTransaction.php` | — | `AddUuid` + `BelongsToSchool`, `MoneyCast` on `amount` **and `fee`**, enum cast on `status`, `invoice()` / `payment()` / `events()`. |
| `app/Finance/Models/GatewayTransactionEvent.php` | — | The raw delivery. Append-only at the database; `payload` cast to array. |
| `tests/Feature/Finance/GatewayTransactionSchemaTest.php` | — | 19 tests, 91 assertions. Every write is raw `DB::table`, never the model — the guards under test are the database's. |

Re-derive the line counts from the tree rather than from this table; they moved with the second
commit and a carried number is how a stale fact becomes a confident assertion.

**Three design points a reviewer should attack first:**

1. **`success` is terminal at the database; `failed` and `abandoned` are not.** The update guard
   refuses every UPDATE of a row already in `success`, and refuses any return to `pending`. The
   asymmetry is deliberate and is the arm most likely to be wrong: a payer whose card declines can
   complete the *same* reference by transfer minutes later, and the provider then reports success on
   a reference it previously reported failed. Freezing `failed` would make that money invisible to
   the portal. If that is not how the chosen provider behaves, this is the decision to revisit.
2. **The `(provider, reference)` unique index is NOT school-scoped.** The reference crosses the wire
   to a third party, so its namespace is the provider's estate, not one school's. A
   `(school_id, provider, reference)` index would pass every other arm in the test file and fail
   exactly one — which is why that arm exists.
3. **The currency-shape rule is written twice**, once as a `CHECK` (uniformity with the ten columns
   `2026_08_01_120000` constrained, and its naming convention) and once in the insert trigger. The
   trigger is the authority: production is MySQL 5.7.23, which parses and ignores `CHECK` entirely.
   The test asserts driver code **1644** (trigger), not 3819 (`CHECK`), precisely so a refusal that
   is local-only cannot masquerade as one that is live.

## Proof

Raw output, in the order run. Test DB is `portal_testing` throughout; the production copy
(`brookstone_portal_db`) was never written to.

**The new test file:**

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/GatewayTransactionSchemaTest.php
{"tool":"pest","result":"passed","tests":19,"passed":19,"assertions":91,"duration_ms":13164}
```

**The new file plus every sibling that reasons about this schema** — schema conventions (which
loops every `finance_` table demanding `school_id` and canonical collation), the currency shape
constraints, the gateway origin pairing, and the CHECK-as-triggers pin:

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest \
    tests/Feature/Finance/GatewayTransactionSchemaTest.php \
    tests/Feature/Finance/SchemaConventionsTest.php \
    tests/Feature/Finance/CurrencyShapeConstraintTest.php \
    tests/Feature/Finance/PaymentOriginGatewayTest.php \
    tests/Feature/Finance/CheckConstraintsAsTriggersTest.php
{"tool":"pest","result":"passed","tests":48,"passed":48,"assertions":278,"duration_ms":16286}
```

**Arch group** (the boundary rules that decide where these files may live):

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest --group=arch
{"tool":"pest","result":"passed","tests":115,"passed":115,"assertions":599,"duration_ms":41988,"risky":1}
```

**Larastan:**

```
$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}
```

**The four lints and Pint** (Pint invoked on an explicit file array, never a directory):

```
$ ./vendor/bin/pint --test "${files[@]}"
{"tool":"pint","result":"passed"}

$ php bin/ci-boundary-lint.php
boundary-lint: OK — no new boundary violations (8 known temporary exceptions).
$ php bin/ci-authz-lint.php
authz-lint: OK — no new commented-out authorization checks (0 known).
$ php bin/ci-money-lint.php
money-lint: OK — no money-rule violations (0 known exception(s)).
$ php bin/ci-sql-clock-lint.php
sql-clock-lint: OK — no SQL-side clock reads (4 named exception(s)).
```

`git diff --stat` against my own model of the change: four new files, nothing else touched. No
unrelated file was swept in by formatting.

**Full suite + failure ratchet:**

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest --log-junit junit.xml
{"result":"failed","tests":2390,"passed":2373,"failed":6,"errors":1,"skipped":10,"risky":4}
PEST_EXIT=2

$ php bin/ci-test-ratchet.php junit.xml
ratchet: OK — no new failures beyond the baseline (7 known-failing).
RATCHET_EXIT=0
```

The suite is red and the ratchet is green, which is the expected shape here rather than a
contradiction: the 7 reds are **exactly** the 7 frozen in `tests/ratchet-baseline.txt`, name for
name — four `ActivityLogApiTest`, two `GuardianProfileTest`, one `AuthenticationTest::users are
rate limited`. I compared the sets rather than the counts, because a count cannot tell "these seven"
from "some other seven" and the swap is the case that slips through. None is in Finance and none is
in a file this branch touches.

### The `down()` audit — rollback depth re-derived, not assumed

Per the standing rule that `--step=N` counts from the branch's latest migrations and can roll back
someone else's work while the audit passes testing nothing of mine, I located my migration in
`migrate:status` first and confirmed **nothing is listed after it**:

```
$ php artisan migrate:status | grep -v Pending | grep -n "2026_08_27_100000_create_finance_gateway_transactions"
166: 2026_08_27_100000_create_finance_gateway_transactions .. [1] Ran

$ php artisan migrate:status | tail -4
 2026_08_25_100000_finance_payment_origin_admits_gateway .. [1] Ran
 2026_08_26_100000_add_kind_to_scholarships_table .. [1] Ran
 2026_08_26_100001_bulk_invoice_run_rows_admit_sponsored .. [1] Ran
 2026_08_27_100000_create_finance_gateway_transactions .. [1] Ran
```

Only then `--step=1`, and the assertion is on **my** objects being gone, not on a bare exit 0:

```
$ php artisan migrate:rollback --step=1 --force
 2026_08_27_100000_create_finance_gateway_transactions .. 142.85ms DONE

parent=gone events=gone triggers=0 payments_origin_intact=1

$ php artisan migrate --force
 2026_08_27_100000_create_finance_gateway_transactions .. 410.09ms DONE
```

Both tables go, in the right order — the child holds the FK, so dropping the parent first is 1217
and a half-done rollback. Two further things that line proves beyond "it rolled back": the `finance_payments` origin pairing survives
my rollback untouched (my `down()` does not reach into another migration's rule), and the re-up
succeeds against a database that has already had these triggers — the `DROP TRIGGER IF EXISTS`
before each `CREATE` is what stops the rollback/re-up leg of `bin/quality-clean-db` hitting 1359.

## The watched red

Five mutations, each a one-line edit, each verified as **applied** before the run (the grep output
is included for that reason — a substitution that silently does not match reads as a survivor).
All five killed, and each killed **the specific test that names the property**, which is the part
that matters: a mutation that reds the whole file proves only that the file runs.

**1 · `COLLATE utf8mb4_bin` dropped from ONE arm of the status domain** (the `success` arm — the
quiet failure, because the other three keep biting and the guard still looks alive):

```
257:                OR NEW.status = 'success'
--- MUTATION 1 APPLIED ---
{"result":"failed","tests":13,"passed":12,"errors":1,"error_details":[{"test":"…the_status_domain_admits_exactly_the_four_enum_values__case_sensitively__on_INSERT","message":"expected a QueryException carrying 1644, none thrown"}]}
```

`'Success'` inserted. This is the arm that measures the collation clause, and it measures it.

**2 · The terminal-`success` check disabled** (`IF OLD.status … = 'success'` → `IF FALSE`):

```
{"result":"failed","tests":13,"passed":12,"errors":1,"error_details":[{"test":"…a_settled_attempt_is_final_—_the_replayed_webhook_has_nothing_it_can_move","message":"expected a QueryException carrying 1644, none thrown"}]}
```

**3 · `UNIQUE (payment_id)` widened to `UNIQUE (payment_id, school_id)`** — the mutation that lets
one attempt settle twice under an index name that reads as though it could not. This one is killed
by the migration's own shape assertion, before any test runs:

```
Index [finance_gateway_transactions_payment_unique] on finance_gateway_transactions has columns
[payment_id, school_id], expected [payment_id]. An index with the right name and the wrong columns
enforces a different rule from the one this migration claims to add.
```

Every test in the file errored on that message. Note what this proves that the others do not: the
`information_schema` read-back is not decoration, and it fires on a column-set change that
`CREATE TABLE` accepts happily.

**4 · The no-return-to-`pending` check disabled:**

```
{"result":"failed","tests":13,"passed":12,"errors":1,"error_details":[{"test":"…status_may_not_return_to_pending__but_a_failed_attempt_may_still_succeed_later","message":"expected a QueryException carrying 1644, none thrown"}]}
```

**5 · The payment composite FK narrowed to a single column** (`(payment_id, school_id)` →
`(payment_id)`, i.e. the isolation half removed):

```
187:            $table->foreign(['payment_id'], 'finance_gateway_transactions_payment_school_foreign')
--- MUTATION 5 APPLIED ---
{"result":"failed","tests":13,"passed":12,"errors":1,"error_details":[{"test":"…an_attempt_cannot_name_another_school_s_invoice_or_another_school_s_payment","message":"expected a QueryException carrying 1452, none thrown"}]}
```

A school-A attempt successfully claimed a school-B payment. The composite FK is the only thing
refusing that, and the arm sees it.

**6 · The null-safe `<=>` replaced by a plain `<>` on one write-once column** (`settlement_reference`).
The interesting one, because it fails in only one direction:

```
457:               OR (OLD.settlement_reference IS NOT NULL AND NEW.settlement_reference <> OLD.settlement_reference)
--- MUTATION 6 APPLIED ---
{"result":"failed","tests":19,"passed":18,"errors":1,"error_details":[{"test":"…a_fact_reported_by_the_provider_is_WRITE_ONCE_—_NULL_to_a_value__never_a_value_to_another","message":"expected a QueryException carrying 1644, none thrown"}]}
```

Rewriting one value to another still bit; **erasure to NULL slipped straight through**, because
`NULL <> NULL` is NULL rather than FALSE. Without the erasure arm in that test this mutation would
have survived and the rule would have been wallpaper in exactly the case it exists for.

**7 · The events table's `_no_update` trigger not created:**

```
failed 17 / 19
 - it_a_stored_delivery_can_never_be_edited_or_deleted_—_it_is_evidence… | expected a QueryException carrying 1644, none thrown
 - it_the_table_carries_the_columns__indexes__foreign_keys_and_CHECK… | Failed asserting that an array contains 'finance_gateway_transaction_e…
```

Two kills, which is the right number: the behavioural arm and the by-name arm are separate claims.

**8 · `payload` declared `text` instead of `json`** — killed by the shape read-back before any test
ran, all 19 errored on:

```
finance_gateway_transaction_events.payload is [text], expected [json].
```

**9 · The fee/amount currency match removed** (`IF NEW.fee_currency … <> NEW.amount_currency` →
`IF FALSE`):

```
failed 18 / 19
 - it_the_fee_is_both_halves_or_neither__never_negative__and_always_in_th
```

**Restored after each**, and the file verified byte-identical to the pre-mutation copy before the
final green run (`diff -q` → clean). The greens in the Proof section above were produced by the
restored file, not by a file I had stopped mutating and hoped was right.

**What I did NOT bite-prove:** the `COALESCE(…, 0)` NULL wrapper on the status domain. `status` is
`NOT NULL`, so I could not construct a row that reaches it — it is the belt behind a brace that is
holding, carried from `2026_08_25_100000` for the case where someone relaxes the column. Named here
rather than left to look tested.

## The drive

No screen changed. This change adds schema, an enum and a model; nothing renders. The portal screens
are §6 step 5 and are not in this change.

## Database observations

Under the privacy rule — structure and counts only.

- Written only to `portal_testing`. The production copy was not touched, and `rbac:sync` was not
  run in any form.
- Before: `finance_gateway_transactions` did not exist. After: it exists with 16 columns, 5 indexes
  (`PRIMARY`, three UNIQUE, one plain), 5 foreign keys (`school_id` → `schools`,
  `initiated_by_user_id` → `users`, the two composite (child, `school_id`) pairs, plus the index
  Laravel creates alongside), 1 `CHECK`, and 3 triggers.
- Row counts are whatever `RefreshDatabase` left; no environment carries persistent rows in this
  table, and production is still five migrations behind this branch's base (advisory §5, unchanged
  by this work).
- `SchemaConventionsTest` re-run with the new table present: its `school_id`-on-every-`finance_`-
  table loop and its canonical-collation sweep both pass, so the new table joins the convention
  rather than being exempted from it.

## Not done

- **I did not verify the 7 baseline reds against this base branch with none of my work present.**
  The standing rule is that a red is not a regression until you have seen the same code green
  somewhere, and its converse applies here too: I am trusting the baseline file to still describe
  reality. The set matches the baseline exactly, so nothing about this change is implicated either
  way — but I did not independently re-establish that those 7 are the same 7 for the same reasons.
- **`bin/quality` was not run end to end.** It is the pre-push gate and will run on push. The
  frontend legs of it (wayfinder, tsc ratchet, ESLint/Prettier) cannot be affected by this change —
  it touches no TypeScript — but that is an argument, not a measurement.
- **No factory** for `GatewayTransaction` or `GatewayTransactionEvent`. Nothing needs one yet; the
  test file builds rows raw on purpose. Step 4 may want one.
- **The unmatched-delivery gap is open and named, not closed.** A webhook whose reference matches no
  transaction has nowhere to go in `finance_gateway_transaction_events` — every row hangs off a
  parent and a school. That log belongs with the webhook handler (step 4), and it is the case where a
  forged or misrouted delivery would leave no trace at all. Recorded in the migration and model
  docblocks; **severity: ticket**, and it should not be forgotten at step 4.
- **`settled_at` and `paid_at` are both `timestamp`, in the application's clock frame.** Nothing here
  reads MySQL's clock (the sql-clock lint is green), but neither column is yet written by anything,
  so the frame is a claim about the writer that does not exist. Step 4 must bind a PHP-captured
  instant, not `NOW()`.
- **Nothing verifies the fee against the payment.** `fee_minor` is constrained to be non-negative and
  same-currency; nothing checks it is less than `amount_minor`. That is deliberate — I do not know
  that a provider never reports a fee exceeding a small payment — but it is an unenforced assumption
  a reviewer should decide on rather than inherit.
- **Nothing consumes any of this yet.** That is deliberate and is the shape of step 2. The model
  has no writer, the enum has no reader in production code, and no route reaches the table. A
  reviewer should expect that and should NOT read it as incompleteness — but should also confirm I
  have not smuggled in unconsumed helpers. I removed one (`isSettleable()` on the enum) for exactly
  that reason before committing.
- **Not decided, and not decidable here:** whether the chosen provider ever reports success on a
  reference it previously reported failed. The update guard permits that transition on the strength
  of how these providers generally behave. If Paystack's contract says otherwise, tightening the
  guard is a one-line trigger change and a new migration.

## Findings raised, not fixed

- **The settlement-account gap is still open** (advisory §2). Nothing in `finance_bank_accounts`
  (`2026_08_10_100000`) or `finance_school_settings` says which account a gateway payment settles
  into, and `RecordPayment::handle` takes `int $bankAccountId` non-nullable with no default. Step 4
  cannot be completed until Developer 1 lands it. Severity: **stop**, for step 4; nothing for this
  change.
- **`origin = 'gateway'` is refused on production today** (advisory §5). Live is behind
  `2026_08_25_100000`. Severity: **stop**, as a milestone risk with an owner and a date — named, not
  mine to fix.
- **A piped `pest` invocation masks its exit code.** `pest … 2>&1 | tail -20` exits 0 even when the
  run fails; only the JSON body says otherwise. I hit this while running the mutations and read the
  body rather than the code. Anywhere a script branches on `$?` after a pipe, that branch is
  reading `tail`'s success. Severity: **ticket** — worth a grep across `bin/` before it matters.
