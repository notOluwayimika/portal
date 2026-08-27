# Implementation report — `feat/gateway-transaction-table`

**This is full-review tier** — it adds a migration, a money-adjacent table, DB triggers and
`school_id` isolation constraints. Recommend a cold session against this file before merge.

## Headline

Done, with three design deviations named below. Step 2 of the payments advisory §6 — the gateway
transaction table, its status enum and its migration — is implemented, shape-verified from
`information_schema`, and bite-proven with five watched reds.

Base: `origin/staging` @ `6f54a18a`. Branch: `feat/gateway-transaction-table`. Shape: four new
files (one enum, one model, one migration, one test file) plus this report. One commit.

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

**3 · The table carries no `student_id`,** though every sibling `finance_` table does. It is
derivable from `invoice_id` by one join and no consumer of this table has a read that cannot afford
that join, so a denormalised copy would be a second place for one fact to live. `school_id` is
present and is not the same case — it is the isolation boundary, uniform by convention, and the two
composite FKs are what stop it diverging from the parents it was copied from.

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
| `database/migrations/2026_08_27_100000_create_finance_gateway_transactions.php` | 454 | Creates `finance_gateway_transactions`, its two composite FKs, four indexes, the currency-shape `CHECK` and three triggers — then reads every one of them back out of `information_schema` and throws rather than record itself if the shape is wrong. |
| `app/Finance/Enums/GatewayTransactionStatus.php` | 72 | `pending` · `success` · `failed` · `abandoned`. A reader of the trigger's domain, not a second copy of it. |
| `app/Finance/Models/GatewayTransaction.php` | 86 | `AddUuid` + `BelongsToSchool`, `MoneyCast` on `amount`, enum cast on `status`, `invoice()` / `payment()`. |
| `tests/Feature/Finance/GatewayTransactionSchemaTest.php` | 387 | 13 tests, 53 assertions. Every write is raw `DB::table`, never the model — the guards under test are the database's. |

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
{"tool":"pest","result":"passed","tests":13,"passed":13,"assertions":53,"duration_ms":15501}
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
{"tool":"pest","result":"passed","tests":42,"passed":42,"assertions":238,"duration_ms":19661}
```

**Arch group** (the boundary rules that decide where these files may live):

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest --group=arch
{"tool":"pest","result":"passed","tests":115,"passed":115,"assertions":599,"duration_ms":43256,"risky":1}
```

**Larastan:**

```
$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}
```

**The four lints and Pint** (Pint invoked on an explicit file array, never a directory):

```
$ ./vendor/bin/pint "${files[@]}"
{"tool":"pint","result":"fixed","files":[{"path":"app/Finance/Enums/GatewayTransactionStatus.php","fixers":["fully_qualified_strict_types","blank_line_after_namespace"]}]}

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
{"tool":"pest","result":"failed","tests":2384,"passed":2367,"failed":6,"errors":1,"skipped":10,"risky":4,"duration_ms":1074809}
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
 2026_08_27_100000_create_finance_gateway_transactions .. 217.00ms DONE

table=gone triggers=0 constraints=0
payments origin trigger still present: 1

$ php artisan migrate --force
 2026_08_27_100000_create_finance_gateway_transactions .. 199.38ms DONE
```

Two things that line proves beyond "it rolled back": the `finance_payments` origin pairing survives
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
- **No factory** for `GatewayTransaction`. Nothing needs one yet; the test file builds rows raw on
  purpose. Step 4 may want one.
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
