# fix/check-constraints-as-triggers-for-mysql-5-7 — implementation report

**Full-review tier** — this is a migration, it touches money (`finance_payments`), the maker-checker
approval architecture, and it rewrites a schema invariant test. Recommend a cold session before merge.

## Headline

Done, with three deviations and two corrections to the brief's premise. Seven `CHECK` constraints on
seven tables are now fourteen `BEFORE INSERT` / `BEFORE UPDATE` triggers signalling SQLSTATE `'45000'`,
so the rules are enforced on production (MySQL 5.7.23) and not only on the dev machine (8.0.43).

Branch `fix/check-constraints-as-triggers-for-mysql-5-7`, off `origin/staging`. One commit.

## Deviations from the brief

**1. The brief said `finance_void_requests` already has BEFORE INSERT and BEFORE UPDATE triggers. It
has only BEFORE UPDATE.** Read from `information_schema.TRIGGERS` before writing anything:

```
finance_credit_notes   finance_credit_notes_insert_guard   BEFORE INSERT ord=1
finance_credit_notes   finance_credit_notes_update_guard   BEFORE UPDATE ord=1
finance_void_requests  finance_void_requests_update_guard  BEFORE UPDATE ord=1
finance_void_requests  finance_void_requests_no_delete     BEFORE DELETE ord=1
```

Consequence: the new `finance_void_requests_maker_ne_checker_bi` is the **only** BEFORE INSERT trigger
on that table (`ACTION_ORDER = 1`), not a second one. No behaviour follows from the correction — I
still added rather than edited — but the brief's premise for that table was wrong and the ordering
question it asked me to reason about is narrower there than stated.

**2. The set of tables where ordering had to be reasoned about is larger than the two the brief
named.** Five of the seven already carry a BEFORE UPDATE trigger, not two:
`finance_credit_notes_update_guard`, `finance_void_requests_update_guard`,
`finance_discount_policy_changes_update_guard`, `finance_fee_schedule_changes_update_guard`,
`finance_opening_balance_batches_no_unpost`, and `finance_payments_no_update`. Only
`subject_result_statuses` had no BEFORE trigger of any kind. My ordering conclusion is in **Ordering**
below and it covers all seven rather than the two named.

**3. I widened `SchemaConventionsTest`'s SCHEMA INVARIANT rather than merely retargeting it, in two
ways, and both are judgement calls the reviewer should press on.** Detail and reasoning in **What
changed**, item 5. In short: it now demands a **trigger pair** and no longer accepts a `CHECK` as an
alternative, and it now matches `submitted_by%` / `decided_by%` by prefix so it can see
`finance_opening_balance_batches` — the sixth approval table, which its own comment anticipated
("approval table six must be covered by the loop without anyone bumping a number") and which its
literal column-name match could not see. The general rule I am asserting, stated so it can be
checked: **a test that accepts a mechanism known to be inert on production certifies nothing, so
accepting a `CHECK` here would re-admit the exact defect this branch exists to remove.**

**4. I updated eight docblocks in `app/` and one docs table that named the dropped constraints as
live guards.** Not asked for. Ten sites across `app/Finance/{Actions,Models,Policies}` and
`docs/finance/backstop-reachability.md` asserted `finance_..._maker_ne_checker` / `..._origin_shape`
as the engine-level backstop, and five rows of that table gave the driver code as 3819. Left alone,
the branch would remove the constraints and leave the repository still claiming they enforce
something. Historical migration docblocks (`2026_08_09_100000`, `2026_08_10_120000`,
`2026_08_07_110000`) were **not** touched — each correctly describes what that migration did at the
time.

## Contradictions of the premise

**1. "31 assertions across 13 test files assert MySQL error 3819."** Measured on `origin/staging`:
the literal `3819` appears **31 times in 7 test files**, not 13. Of those 31, **6 are executable**;
the other 25 are comments, `it()` descriptions, a helper function's name (`expect3819`), and a
docblock. The per-file counts:

```
tests/Feature/Finance/ApprovalsQueueRendersEveryTypeTest.php:1
tests/Feature/Finance/BackstopGuardsTest.php:1
tests/Feature/Finance/BankAccountForeignKeysTest.php:2
tests/Feature/Finance/CurrencyShapeConstraintTest.php:14
tests/Feature/Finance/OpeningBalanceApprovalGateTest.php:4
tests/Feature/Finance/PaymentProvenanceTest.php:6
tests/Feature/Support/DatabaseErrorRenderingTest.php:3
TOTAL: 31
```

**2. The driver code is not the whole affected surface, and the larger half is not a numeric
assertion at all.** `tests/Feature/Rbac/MakerCheckerSeparationTest.php` carries **ten**
`->toThrow(QueryException::class, '<constraint name>')` assertions — five tables × INSERT + UPDATE —
which match the CHECK *name* in the exception message and never mention 3819. Grepping for the driver
code finds none of them. They are the largest single block of work in this change. (This is the shape
`docs/handoff/tickets/bare-query-exception-assertions-prove-nothing.md` argued for, and it is why the
change was visible at all.)

**3. Live constraint counts differ from the audit's "19 sites".** `information_schema` on
`portal_testing` before the migration: **27** `CHECK` constraints. After: **19**. The audit counts
declaration *sites* in migrations (some create several constraints in one loop); the schema counts
objects. Nothing turns on it — the seven rules and all nine constraint names in the brief are exactly
right, verified against the migrations that created them — but "twelve untouched constraints" in the
audit is twelve untouched *rules*, and nineteen untouched constraint objects.

**4. There is no 1644 gap in the error renderer.** The brief asked me to check whether the
application maps 3819 to a user-facing message with no branch for 1644. It does not.
`bootstrap/app.php:196-207` is a `match` on `(int) $e->errorInfo[1]` with explicit arms for
1062/1451/1205/1213 and a `default` that explicitly names 1452, 1644 **and** 3819 together as server
faults → 500 `'A database error occurred.'`. Both codes have taken the same path since that handler
was corrected. It is already pinned end-to-end by `DatabaseErrorRenderingTest`: X-1 drives a 3819
(`student_curricula_promoted_requires_link`, an untouched constraint) and X-2 drives a 1644
(`finance_ledger_transactions_no_update`), and both assert 500 with the same generic body. **No gap,
nothing in scope for this branch, no change made.**

## Ordering — measured, not assumed

The audit named this as "a design point the implementer must measure". Two probes on MySQL 8.0.43,
against a scratch table dropped in a `finally` (DDL commits implicitly, so `RefreshDatabase` would
not have rolled it back):

```
PROBE-A: code=1644 msg=FIRST trigger fired          <- row violating BOTH the CHECK and the trigger
PROBE-B: code=1644 msg=FIRST trigger fired          <- two BEFORE INSERT triggers, both violated
PROBE-C: zz_order_probe_bi  ACTION_ORDER=1
PROBE-C: zz_order_probe_bi2 ACTION_ORDER=2
```

Two conclusions, and both are load-bearing:

1. **A `BEFORE` trigger fires before the `CHECK` is evaluated.** So had the triggers been added
   *alongside* the constraints, every one of the 31 sites would have changed meaning anyway and the
   `CHECK` would have become unreachable dead weight. Dropping is not tidiness; it is the only option
   that leaves one mechanism.
2. **Multiple triggers of the same timing and event fire in `ACTION_ORDER`, which without
   `FOLLOWS`/`PRECEDES` is creation order.** The new triggers are created last, so they fire last.

Together: **adding is positionally equivalent to the `CHECK` it replaces.** A `CHECK` was already
evaluated after every `BEFORE` trigger, and the new trigger is now the last `BEFORE` trigger. Every
row that reached the `CHECK` reaches the new trigger, and every row refused today by an earlier guard
is still refused by that same guard with that guard's own code and message. That is why I added
rather than edited existing bodies, and why no test asserting another guard's refusal changed.

**One consequence worth naming:** on `finance_payments`, `finance_payments_no_update`
(`ACTION_ORDER = 1`, append-only) signals on *every* UPDATE, so `finance_payments_origin_pairing_bu`
is unreachable — exactly as the `CHECK` it replaces already was. I created it anyway so the pairing
does not silently become insert-only if that table is ever given an update path. It is dead code
today and the docblock says so.

## What changed

15 files, +932 / −123.

1. **`database/migrations/2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php`** (new,
   ~330 lines incl. docblock). Drops eight `CHECK` constraints (guarded by an
   `information_schema.TABLE_CONSTRAINTS` lookup, guard shape copied from
   `2026_08_07_110000:119` — returns 0 on 5.7 and skips), creates fourteen triggers, and reads each
   one back from `information_schema.TRIGGERS` asserting `ACTION_TIMING`, `EVENT_MANIPULATION` and
   `EVENT_OBJECT_TABLE` before allowing itself to be recorded (ADR 0052). Table-exists **and**
   column-exists guards on every rule. `up()` idempotent via `DROP TRIGGER IF EXISTS`.
   `down()` drops the fourteen and does **not** restore the `CHECK`s — reasoning in its docblock and
   in **Not done** below.
2. **`tests/Feature/Finance/CheckConstraintsAsTriggersTest.php`** (new, 5 tests / 41 assertions).
   Pins the mechanism: all fourteen triggers by name/timing/event; the seven replaced constraints are
   gone; three untouched constraints are still present (a broad `DROP` sweep fails here); and the one
   bite no other file carries — the sixth table's INSERT door, which had no test of its own because
   one `CHECK` clause covered both doors and two triggers do not.
3. **`tests/Feature/Rbac/MakerCheckerSeparationTest.php`** — ten assertions moved from the CHECK name
   to the trigger message (`'<table>: the checker must not be the maker.'`). The discipline is
   unchanged and deliberately kept: the message is asserted, not just the exception class, so a
   `QueryException` thrown for any other reason fails rather than passes. The message names the table,
   which is what distinguishes this refusal from the other BEFORE triggers on the same table.
4. **`OpeningBalanceApprovalGateTest.php`** (`OBG_CHECK_VIOLATION = 3819` → `OBG_TRIGGER_VIOLATION =
   1644`), **`PaymentProvenanceTest.php`** (2 sites), **`BankAccountForeignKeysTest.php`** (2 sites) —
   driver code only; no assertion narrowed, none removed.
5. **`tests/Feature/Finance/SchemaConventionsTest.php`** — the SCHEMA INVARIANT. See deviation 3. It
   now reads `ACTION_STATEMENT` from `information_schema.TRIGGERS` and requires, for every table with
   a `submitted_by*` / `decided_by*` pair, a `BEFORE INSERT` **and** a `BEFORE UPDATE` trigger whose
   body names **both** columns. Both timings are required because a table guarded only on INSERT is
   one UPDATE away from a self-approval. `finance_opening_balance_batches` added to the containment
   floor, which is now six tables.
6. **Eight docblocks in `app/Finance/`** + **`docs/finance/backstop-reachability.md`** — see
   deviation 4.
7. **`docs/finance/check-constraints-on-mysql-5-7.md`** — the advisor's audit, committed as written.
   Not rewritten, not reformatted.

## Proof

### The fourteen triggers, read back from `information_schema.TRIGGERS`

```
NEW TRIGGERS: 14
 finance_credit_notes_maker_ne_checker_bu             finance_credit_notes             BEFORE UPDATE ord=2
 finance_credit_notes_maker_ne_checker_bi             finance_credit_notes             BEFORE INSERT ord=2
 finance_discount_policy_changes_maker_ne_checker_bu  finance_discount_policy_changes  BEFORE UPDATE ord=2
 finance_discount_policy_changes_maker_ne_checker_bi  finance_discount_policy_changes  BEFORE INSERT ord=1
 finance_fee_schedule_changes_maker_ne_checker_bu     finance_fee_schedule_changes     BEFORE UPDATE ord=2
 finance_fee_schedule_changes_maker_ne_checker_bi     finance_fee_schedule_changes     BEFORE INSERT ord=1
 finance_opening_balance_batches_maker_ne_checker_bu  finance_opening_balance_batches  BEFORE UPDATE ord=2
 finance_opening_balance_batches_maker_ne_checker_bi  finance_opening_balance_batches  BEFORE INSERT ord=1
 finance_payments_origin_pairing_bu                   finance_payments                 BEFORE UPDATE ord=2
 finance_payments_origin_pairing_bi                   finance_payments                 BEFORE INSERT ord=1
 finance_void_requests_maker_ne_checker_bu            finance_void_requests            BEFORE UPDATE ord=2
 finance_void_requests_maker_ne_checker_bi            finance_void_requests            BEFORE INSERT ord=1
 subject_result_statuses_maker_ne_checker_bu          subject_result_statuses          BEFORE UPDATE ord=1
 subject_result_statuses_maker_ne_checker_bi          subject_result_statuses          BEFORE INSERT ord=1
SURVIVING TARGET CHECKS: 0
TOTAL CHECKS REMAINING: 19
```

`ord` is `ACTION_ORDER`. The `ord=2` rows are the tables that already had a guard at that timing; the
`ord=1` rows are the ones where the new trigger is the only one. `subject_result_statuses` is `ord=1`
on both, which is the table that had no BEFORE trigger at all.

### `down()` / re-up — depth re-derived, not `--step=1` on faith

`migrate:status` tail, confirming my migration is the last applied on this branch before the rollback
was issued:

```
 2026_08_10_110000_finance_bank_account_identity_is_immutable .. [1] Ran
 2026_08_10_120000_finance_bank_account_foreign_keys .. [1] Ran
 2026_08_13_100000_timestamp_columns_drop_implicit_on_update .. [1] Ran
 2026_08_17_100000_maker_checker_and_payment_origin_as_triggers .. [1] Ran
```

```
BEFORE ROLLBACK: 14
--- rolling back ONE batch, verified above as mine ---
 INFO Rolling back migrations.
 2026_08_17_100000_maker_checker_and_payment_origin_as_triggers  100.42ms DONE
AFTER ROLLBACK  triggers=0  restored-CHECKs=0
```

The `restored-CHECKs=0` is the assertion that `down()` did **not** put the constraints back — that is
the intended behaviour, not an omission. Then re-up, and `up()` invoked a second time over an
already-installed state:

```
 2026_08_17_100000_maker_checker_and_payment_origin_as_triggers  86.95ms DONE
AFTER RE-UP: 14
AFTER UP() RUN TWICE: 14 (no 1359, shape re-verified)
```

That second line is the `bin/quality-clean-db` rollback/re-up leg: no `1359 Trigger already exists`,
and the read-back ran again on every one of the fourteen.

### Affected test files

```
tests: 53, passed: 53, assertions: 210
```

(`MakerCheckerSeparationTest`, `OpeningBalanceApprovalGateTest`, `PaymentProvenanceTest`,
`BankAccountForeignKeysTest`, `CurrencyShapeConstraintTest`, `DatabaseErrorRenderingTest`,
`BackstopGuardsTest` — the last three unchanged by this branch and asserting the untouched
constraints, included precisely to show they still bite at 3819.)

### `bin/quality`

Fifteen steps (`grep -c '^\s*step "' bin/quality` = 15, and the `[%d/15]` literal at `bin/quality:59`
agrees — re-derived, not carried). Second run, after the Pest-negated-expectation fix:

```
quality gate — base 00143c0

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form                                     ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)          ✓ lint-changed
           Pint: no changed PHP files
           Prettier: no changed frontend files
           ESLint: no changed JS/TS files
[4/15] types (tsc ratchet vs tsc-baseline)                                ✓ tsc-ratchet
[5/15] frontend build (vite)                                              ✓ build
[6/15] authorization guard (no new commented-out checks)                  ✓ authz-lint
[7/15] boundary lint (§17.2)                                              ✓ boundary-lint
[8/15] grants-convergence lint                                            ✓ grants-convergence-lint
[9/15] money lint                                                         ✓ money-lint
[10/15] runtime-zero lint (S7 legacy access sources)                      ✓ runtime-zero-lint
[11/15] identifier-generation bypass guard (1.4b)                         ✓ identifier-generation-lint
[12/15] sql-clock lint                                                    ✓ sql-clock-lint
[13/15] architecture tests (§17.1)                                        ✓ arch
[14/15] static analysis (Larastan level 5 vs baseline)                    ✓ larastan
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)             ✓ test-ratchet

✓ quality: PASS — per-push floor.
```

**Step 3 proved nothing about this change and the reader should not read it as coverage.** "Pint: no
changed PHP files" is the known limitation in
`docs/handoff/tickets/lint-changed-cannot-see-uncommitted-work.md` — the work was staged, not
committed, so `bin/lint-changed.sh` saw nothing. I ran Pint against the explicit file list instead,
using the guarded pattern from CLAUDE.md, and it passed:

```
files=$(git diff --name-only HEAD -- '*.php'; git ls-files --others --exclude-standard -- '*.php')
[ -n "$files" ] && printf '%s\n' "$files" | tr '\n' '\0' | xargs -0 ./vendor/bin/pint
{"tool":"pint","result":"passed"}
```

(The `xargs -0` is not decoration: this shell is `zsh`, which does **not** word-split an unquoted
`$files`, so the documented `./vendor/bin/pint $files` passes the whole newline-joined list as one
argument and Pint reports `"…" is not readable`. Worth knowing before someone copies the CLAUDE.md
snippet into a zsh session and concludes Pint is broken.)

`git diff --stat` was read against my own model of the change before committing, per CLAUDE.md: 15
files, +932 / −123, no unrelated file swept in.

## The 3819 / 1644 counts, before and after

Executable driver-code assertion sites (not string occurrences):

| | before | after |
|---|---|---|
| assert **3819**, belonging to the seven rules | 5 | 0 |
| assert **3819**, belonging to the untouched rules | 1 site, 6 invocations | 1 site, 6 invocations |
| assert **1644**, belonging to the seven rules | 0 | 5 |
| assert the CHECK **name** in the message (the seven rules) | 10 | 0 |
| assert the trigger **message** (the seven rules) | 0 | 10 |

The five that moved, individually:

| site | rule | before → after |
|---|---|---|
| `BankAccountForeignKeysTest.php:75` → `:83` | 7, portal-with-no-account | 3819 → 1644 |
| `BankAccountForeignKeysTest.php:97` → `:105` | 7, migrated-with-account | 3819 → 1644 |
| `PaymentProvenanceTest.php:126` → `:140` | 7, third origin value | 3819 → 1644 |
| `PaymentProvenanceTest.php:136` → `:151` | 7, case variant under `utf8mb4_bin` | 3819 → 1644 |
| `OpeningBalanceApprovalGateTest.php:58/209` → `:66/218` | 6, UPDATE door | 3819 → 1644 (via the const) |

The ten that moved from name to message are `MakerCheckerSeparationTest.php:178, 203, 267, 278, 290,
299, 314, 323, 336, 345` — rules 1–5, INSERT and UPDATE each.

**The twelve untouched rules stay 3819 and still pass.** `CurrencyShapeConstraintTest` (the six
currency-shape rules, 6 invocations of the `expect3819` helper) and `DatabaseErrorRenderingTest` X-1
(`student_curricula_promoted_requires_link`) both green, unmodified — see the 53/53 above.

Raw string counts, for completeness: `3819` in `tests/` went 31 → 24; `1644` went 30 → 43.

## The watched red

**Mutation:** removed the `finance_void_requests` entry from the migration's `MAKER_CHECKER` array,
so two of the fourteen triggers are never created and that table's `CHECK` is never dropped. Nothing
else touched.

**Red:**

```
tests=18 passed=15 failed=3

it_all_FOURTEEN_triggers_exist__with_the_right_timing__event_and_table
  trigger [finance_void_requests_maker_ne_checker_bi] is missing. On MySQL 5.7 there is no CHECK
  behind it, so the rule it carries is simply not enforced on production.

it_the_seven_replaced_CHECK_constraints_are_GONE__so_there_is_exactly_one_mechanism
  CHECK constraint(s) [finance_void_requests_maker_ne_checker] were re-added over the triggers that
  replaced them. MySQL 5.7 parses and ignores CHECK, and on 8.0 the trigger fires first — so this is
  a guard that enforces nothing on either server.

it_SCHEMA_INVARIANT_—_every_approval_table_carries_a_maker≠checker_TRIGGER_pair__not_a_CHECK_
  table [finance_void_requests] has a submitted_by*/decided_by* pair but no BEFORE INSERT trigger
  naming BOTH columns — the act-level guarantee is silently absent there on MySQL 5.7, where a CHECK
  would be parsed and ignored. BEFORE INSERT triggers found on it: (none)
```

All three failures name `finance_void_requests` — the table I removed — and no other. The third is
the rewritten schema invariant biting on exactly the case it was rewritten to catch, which is the
arm most worth having watched: it is the guard that would catch a *future* approval table shipping
without one.

**This red was watched twice, and the first run is the more useful fact — see "The gate caught a real
defect" below.** On the first plant, the first and third messages printed as
`Expecting null not to be null 'trigger [finance_void_request…ction.'` — the sentence exported and
truncated, not the failure description. That is the defect
`tests/Feature/Quality/PestNegatedExpectationMessagesTest.php` exists to catch, and it caught mine.
The output above is from the re-plant after the fix.

**Restored** (`grep -c finance_void_requests` back to 4) **and green, including the quality test that
caught it:**

```
tests: 19, passed: 19, assertions: 142
```

## The gate caught a real defect in my new test code

`bin/quality` step 15 failed the first time I ran it, with a genuine regression that was mine:

```
ratchet: 1 NEW test failure(s) not in the baseline (regression):
  ✗ tests/Feature/Quality/PestNegatedExpectationMessagesTest.php::it no test passes a custom
    failure message to a negated Pest expectation
```

I had written `expect($found)->not->toBeNull("<sentence>")` in the new
`CheckConstraintsAsTriggersTest` and in the rewritten `SchemaConventionsTest` invariant. Pest's
`->not->` is a proxy, not a matcher: it runs the positive assertion and, when that succeeds, throws
its own generic sentence with every argument shortened-exported into it. The message is discarded and
a truncated export printed in its place — which is exactly what the first watched red showed.

Both were rewritten to the positive form the gate's own message prescribes,
`expect($x !== null)->toBeTrue($message)`, and both now print the whole sentence. **This is worth
recording rather than quietly fixing**: the two arms it hit are the ones whose entire value is the
diagnostic — an invariant that fires on a *future* approval table is useless if it cannot say which
table. The gate caught a defect that a green run of my own tests could not have shown me, because
both tests passed either way.

## Database observations

`portal_testing`, before → after the migration. No row contents, no identities.

| | before | after |
|---|---|---|
| `CHECK` constraints in schema | 27 | 19 |
| the seven rules' constraints | 8 objects | 0 |
| triggers in schema | 35 | 49 |
| the seven rules' triggers | 0 | 14 |

Eight constraint objects for seven rules, because rule 7 drops two: `finance_payments_origin_shape`
and `finance_payments_bank_account_origin_shape`. The pairing subsumes the domain rule — an origin
outside `{portal, migrated}` fails both arms of the pairing — so one trigger predicate replaces both,
verified by `PaymentProvenanceTest`'s two arms, which insert a third value and a case variant and are
now refused by the pairing trigger.

**No production reads were taken by me.** The twelve violation counts (all 0, 2026-08-17) are the
project lead's, carried from the brief; I did not re-derive them and could not. That matters for one
reason stated in the migration docblock: a trigger inspects only rows being written, so a legacy
violator would not have blocked this migration — it would have blocked the next UPDATE of that row
instead, which is a worse failure mode and is why the counts were taken.

## Not done

- **`down()` does not restore the `CHECK`s, deliberately.** A restore is real on 8.0.43 and a silent
  no-op on 5.7.23, so a `down()` that restores means two different things on the two servers: local
  rolls back to an enforced constraint, production rolls back to nothing while reporting success. The
  asymmetry this branch removes would be reintroduced by its own rollback, in the direction nobody
  checks. Precedent for a non-inverse `down()`: `2026_08_02_100000`, `2026_08_03_100000`,
  `2026_08_13_100000`. Stated in the `down()` docblock.
- **`finance_payments_origin_pairing_bu` is unreachable and untested as a *distinct* mechanism.**
  `finance_payments_no_update` (`ACTION_ORDER = 1`) refuses every UPDATE first.
  `PaymentProvenanceTest`'s UPDATE arm asserts 1644 and now cannot distinguish which of the two
  triggers spoke, since both signal `45000`. I recorded that in the test comment rather than
  contriving an assertion that appears to separate them. It is the same limitation the `CHECK` had.
- **The `COALESCE(…, 0)` guard on a NULL origin is unreachable today and I say so in the test.**
  `finance_payments.origin` is `NOT NULL`, so a NULL insert is refused 1048 by the column before the
  trigger body runs. The `COALESCE` is the belt for a future relaxation of the column, not a live
  guard, and `CheckConstraintsAsTriggersTest` asserts only that the row does not land — not which
  guard spoke. Stated rather than dressed up.
- **Nothing was driven in a browser.** No screen changed; this is schema and tests only.
- **Production was not touched and no migration was run there.** The migration is written to be a
  no-op on the `DROP` half there (guard returns 0) and to create the fourteen triggers, but that has
  been exercised only against 8.0.43. **I have no MySQL 5.7 to run it on**, so the 5.7 behaviour of
  `DROP TRIGGER IF EXISTS`, `SIGNAL SQLSTATE '45000'`, `COLLATE utf8mb4_bin` inside a trigger body,
  and multiple same-timing triggers per table is asserted from documented version support (5.5, 5.5,
  5.7, 5.7.2 respectively) and **not measured**. That is the single largest unproven claim in this
  change and the reviewer should treat it as such.

## Findings raised, not fixed

- `tests/Feature/Finance/SchemaConventionsTest.php:364` (before this change) — the containment floor
  listed five approval tables and the derived set matched literal column names, so
  `finance_opening_balance_batches` was invisible to the invariant that exists to cover "approval
  table six". Fixed here as deviation 3, but the class of bug — an invariant whose derived set
  silently excludes the case it was written for — is worth a look elsewhere. **ticket**
- `docs/finance/backstop-reachability.md` is a point-in-time audit with no mechanism keeping it in
  step with the schema; five of its rows were stale the moment this migration ran and I corrected
  them by hand. By the wallpaper principle that table is a wish, not a rule. A test deriving it from
  `information_schema` would be small. **ticket**
- `MESSAGE_TEXT` is capped at 128 characters and MySQL truncates past it silently. The longest
  message here is 97 (rule 7) and I counted rather than eyeballed, but nothing in the repository
  enforces the cap on the twenty-odd other `SIGNAL` sites. **ticket**
