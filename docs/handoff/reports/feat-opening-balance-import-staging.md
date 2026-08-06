# Opening-balance import — §9 commit 1 (staging table + read-only validator)

**Branch** `feat/opening-balance-import-staging` · **Base** `origin/staging` @ `1f151b0`
**Spec** `docs/handoff/opening-balance-import-spec.md` Rev 2
**Review tier** FULL — this change touches money columns, a migration, `school_id` isolation, an
ACL boundary and the fee-schedule read path. Subagent review attached; **recommend a cold session
before merge.**

Branch hashes as asked, before a line of code was written:

```
HEAD (before)       613b1e7   (fix/lint-fragment-resolution — the tree was checked out on PR #209)
origin/staging      1f151b0
HEAD (after checkout -B feat/opening-balance-import-staging origin/staging)
                    1f151b0   == origin/staging ✓
```

---

## Deviations from the brief — read these first

Four, three of them forced by the repo contradicting the brief's premise.

### 1. The command is at `app/Finance/Console/`, not `app/Console/Commands/`

The brief says "commands live in app/Console/Commands, not in the domain folder — follow
AuditDutySeparation.php's shape". The repo says the opposite for a command that touches Finance
models, and says it in three places:

- `tests/Arch/ArchitectureBoundaryTest.php:35-45` — `App\Finance\Models` and `App\Finance\Services`
  are `toOnlyBeUsedIn('App\Finance')`.
- `bin/ci-boundary-lint.php:107-110` — a `finance_*` table literal outside `app/Finance/` is a
  boundary violation, so the fallback of raw `DB::table` is closed too.
- `bootstrap/app.php:37-40` already records the decision verbatim: *"Finance module commands live in
  App\Finance\Console (the arch boundary keeps Finance models private, so a command touching them
  cannot sit in app/Console/Commands)"* — with `ReconcileAccounts`, `AuditLedgerCoherence` and
  `DriveFinanceStates` already there.

`AuditDutySeparation.php` can sit where it does because it touches no Finance model at all. So the
command is `app/Finance/Console/ImportOpeningBalances.php`, registered in `bootstrap/app.php`
alongside the other two. Everything else about its shape follows `AuditDutySeparation` — read-only,
counts and ids only, non-zero exit on findings, `--baseline`-style operator framing.

### 2. §5's resolution chain does not exist, and neither does the id-type question

This is the "tell me what you found" item, and the answer is that the question is moot.

The spec (§5) prescribes `curricula.term` (an ordinal 1|2|3) → `terms` via the unique
`(academic_session_id, order)`, and warns the id types on `curricula.academic_session_id` versus
`terms.academic_session_id` may disagree across the hybrid uuid→integer conversion.

**`curricula` has neither column.** `2026_05_06_085734_update_terms_and_curricula_tables.php:114`
dropped `term` and `academic_session_id` together and `:129`/`:154` rebuilt the table around a
`term_id` FK. Re-derived from `information_schema` on the dev copy, 2026-08-06:

```
curricula   | term_id             | bigint unsigned | null=YES | key=MUL
curricula   | class_level_arm_id  | bigint unsigned | null=YES | key=MUL
curricula   | (no academic_session_id column)
terms       | academic_session_id | bigint unsigned | null=NO  | key=MUL
terms       | order               | tinyint unsigned| null=NO
```

So there is **one** hop to the term (`curricula.term_id` is the term id), and one to the class level
(`curricula.class_level_arm_id → class_level_arms.class_level_id`) — the only part of §5's chain that
survived. Nothing needed to be reconciled across the id-type conversion because no join spans it.

Two incidental facts worth someone's attention:

- The index `curricula_school_id_academic_session_id_status_index` survived the column drop. Its
  actual columns are `(school_id, status)`. **It is a name that lies**, and it is exactly the kind of
  artifact that would have made this look confirmed if I had grepped index names instead of columns.
- `terms_academic_session_id_order_unique` **does** exist, so the spec's claim about that key was
  right; it is simply no longer reachable from `curricula`.

### 3. `students.admission_number` is NOT NULL, so §6's first pre-flight is structurally zero

Spec §2 and §6.1 both rest on the column being nullable. It was, at
`2026_04_26_121302_create_students_table.php:15`; it stopped being so at
`2026_07_18_100000_make_identifier_columns_not_null.php:36`
(`ALTER TABLE students MODIFY admission_number VARCHAR(255) NOT NULL`), and
`information_schema` confirms `null=NO` today. There is also a live
`students_school_id_admission_number_unique`.

I implemented and report the count anyway rather than deleting the check — it costs one loop
iteration, it defends against the constraint ever being relaxed, and a pre-flight that silently
stopped existing is worse than one that always answers zero. But **it can only ever be zero today,
and §6.1 should not be read as an open question.** Duplicate-*after-trim* remains genuinely possible
(`'ADM1'` and `' ADM1'` are distinct at the unique index) and is the check that still bites.

### 4. Three additions the brief's column list did not name

- **`--batch-reference=`** on the signature (defaults to the CSV's basename). §7's idempotency key
  and the DB-refusal test both need a way to name a batch deliberately; the brief's signature had
  none, and deriving it silently from the filename alone would make "re-run this exact batch" and
  "re-upload a corrected file with the same name" indistinguishable.
- **`findings` (json) on the batch row.** The brief put `findings` only on rows, but §3d/§6 require
  batch-level findings ("a finding on the BATCH, not on a row"). Without the column those findings
  would exist only in console output, which is not durable and which U12b cannot render.
- **The `^[A-Z]{3}$` CHECK on all eight new `*_currency` columns**, matching
  `2026_08_01_120000_add_currency_shape_checks.php`. That migration's own docblock argues the rule is
  wallpaper without a DB constraint; adding eight unconstrained currency columns would have
  reintroduced exactly what it closed. Constraint names are abbreviated (`ob_batches_*` / `ob_rows_*`)
  because `finance_opening_balance_batches_total_prior_arrears_currency_shape` is 66 characters and
  MySQL caps identifiers at 64 — the same reason both new unique indexes are named explicitly (the
  auto-derived rows index failed with **1059** on the first run).

I did **not** build: `finance_payments.origin` / `external_reference` / the `migrated` method value /
the reserved receipt band (§4), the posting Action (§3), the approval gate (§8), or U12b. No
`SubledgerPoster`, `RecordAccountPayment` or `GenerateInvoice` is imported anywhere in this diff.

---

## What shipped

| File | |
|---|---|
| `database/migrations/2026_08_06_100000_create_finance_opening_balance_tables.php` | new — two tables, 8 CHECKs, composite school-integrity FK |
| `app/Finance/Models/OpeningBalanceBatch.php`, `OpeningBalanceRow.php` | new |
| `app/Finance/Enums/OpeningBalanceBatchStatus.php`, `OpeningBalanceRowStatus.php` | new |
| `app/Finance/Console/ImportOpeningBalances.php` | new — the validator |
| `app/Finance/Contracts/BillableEnrollment.php` | +`termId`, +`classLevelId` |
| `app/Finance/Contracts/BillableEnrollmentProvider.php` | +`admissionNumberIndex()` |
| `app/Academics/BillableEnrollmentAdapter.php` | populates both; implements the new method |
| `bootstrap/app.php` | registers the command |
| `tests/Feature/Finance/OpeningBalanceImportTest.php` | new — 12 cases |

### The migration filename and the exact final column list

`database/migrations/2026_08_06_100000_create_finance_opening_balance_tables.php` — dated after
`2026_08_05_100000`, which `ls database/migrations | tail -1` confirmed was the latest.

Re-derived from `information_schema` **after** the migration ran on the dev copy, not from the
migration source:

```
== finance_opening_balance_batches
  id                            bigint unsigned  NOT NULL
  uuid                          char(36)         NOT NULL
  school_id                     bigint unsigned  NOT NULL
  batch_reference               varchar(255)     NOT NULL
  filename                      varchar(255)     NOT NULL
  status                        varchar(255)     NOT NULL DEFAULT draft
  row_count                     int unsigned     NOT NULL DEFAULT 0
  total_prior_arrears_minor     bigint           NULL
  total_prior_arrears_currency  char(3)          NULL
  total_paid_to_date_minor      bigint           NULL
  total_paid_to_date_currency   char(3)          NULL
  total_wcbs_billed_minor       bigint           NULL
  total_wcbs_billed_currency    char(3)          NULL
  cutover_date                  date             NOT NULL
  term_id                       bigint unsigned  NOT NULL
  uploaded_by_user_id           bigint unsigned  NULL
  findings                      json             NULL
  created_at                    timestamp        NULL
  updated_at                    timestamp        NULL

== finance_opening_balance_rows
  id                            bigint unsigned  NOT NULL
  uuid                          char(36)         NOT NULL
  school_id                     bigint unsigned  NOT NULL
  batch_id                      bigint unsigned  NOT NULL
  line_number                   int unsigned     NOT NULL
  admission_number              varchar(255)     NULL
  wcbs_student_ref              varchar(255)     NULL
  prior_arrears_minor           bigint           NULL
  prior_arrears_currency        char(3)          NULL
  wcbs_billed_total_minor       bigint           NULL
  wcbs_billed_total_currency    char(3)          NULL
  paid_to_date_minor            bigint           NULL
  paid_to_date_currency         char(3)          NULL
  wcbs_total_balance_minor      bigint           NULL
  wcbs_total_balance_currency   char(3)          NULL
  wcbs_bill_reference           varchar(255)     NULL
  last_payment_date             date             NULL
  student_id                    bigint unsigned  NULL
  status                        varchar(255)     NOT NULL
  findings                      json             NULL
  expected_billed_minor         bigint           NULL
  expected_billed_currency      char(3)          NULL
  created_at                    timestamp        NULL
  updated_at                    timestamp        NULL
```

Keys and constraints: `ob_batches_school_reference_unique (school_id, batch_reference)`,
`finance_opening_balance_batches_id_school_unique (id, school_id)`,
`ob_rows_school_batch_admission_unique (school_id, batch_id, admission_number)`,
`finance_opening_balance_rows_batch_school_foreign (batch_id, school_id) → batches(id, school_id) ON DELETE CASCADE`,
FKs to `schools` and `terms` both `RESTRICT`, and 8 `ob_*_shape` CHECKs.

**Every row amount is nullable, on purpose.** §2's "blank ≠ zero" is structural: a blank or
unparseable cell is staged as absent with a named finding, never coerced. `MoneyCast` already
distinguishes both-null (legitimate absence) from exactly-one-null (corrupt row, throws), so the
pair cannot half-populate.

The batch's three totals are also nullable, written when the run completes: a batch that aborted
mid-parse must present no total rather than a total nobody summed.

**One note the reviewer should weigh.** The `*_currency` population was recorded as "closed at 10"
(finance-context). It is now **18**. Nothing enforces that count — `CurrencyShapeConstraintTest`
names three specific columns rather than enumerating — so nothing went red, which is itself the more
interesting fact.

### Port vs. direct resolution, and why

**Through the port**, and there was no lawful alternative: from inside `App\Finance` arch rule 3 and
the boundary lint both close the direct path. But the honest part is that **neither existing port
method could serve as a join**, so I extended it:

- `displayFor(int[] $ids)` runs ids → display. The file has admission numbers, not ids.
- `matchingStudentIds(string $term)` is the accounts-index **search box**: `%term%` with escaped
  wildcards (`BillableEnrollmentAdapter.php:106-113`). It would resolve `A1` onto `A100`. A fuzzy
  join key on a money import is the worst failure available here.

Neither can answer §6's counts or §7's "in the portal, absent from the file" either. So the port
gains `admissionNumberIndex(): list<array{student_id:int, admission_number:?string}>` — an exact,
School-scoped roster the command matches against itself. It is consumer-driven: the consumer is in
this commit. The reason is written into both the interface's and the adapter's docblocks, and
`ImportOpeningBalances`' class docblock records the choice as the brief asked.

Soft-deleted students are excluded (the model's default scope — the same boundary `displayFor` has).
Withdrawn and graduated students are **included**, per §7: their arrears import, only their
invoicing is excluded, and that is V2's concern.

`termId` also has a real consumer in this commit and is not carried for V2's benefit: when the
student's active enrollment is for a term other than the batch's `T`, the row gets an informational
`enrollment_term_differs_from_cutover_term` finding. That fired on real data — see below.

---

## Proof

### The Pest run — raw

**A constraint on this paste, stated rather than papered over.** This environment has a shell hook
that rewrites every `pest` invocation and emits a JSON summary in place of Pest's textual output. I
tried four ways round it (`rtk proxy`, `php ./vendor/bin/pest`, the package binary directly,
redirecting to a file — the file contained the same one line) and could not get the human-readable
form. **This JSON is the raw, unedited output, not a summary I wrote**, and the test names below come
from `--log-junit`, not from me retyping them.

```
{"tool":"pest","result":"passed","tests":12,"passed":12,"assertions":69,"duration_ms":11125}
```

Test names, extracted from the JUnit XML:

```
it accepts a row satisfying the identity and rejects one that is off by a single kobo, naming both sides
it rejects a blank required column but accepts a literal 0.00 as a real zero
it rejects a negative prior_arrears but accepts a negative wcbs_total_balance
it parses naira to exact kobo, including a value that loses a kobo through float round-tripping
it counts a file row matching no student, still stages the batch, and exits non-zero
it raises a BATCH-level finding when the School has admission numbers that collide after trimming
it reports equal, exception with a signed difference, and not_comparable — and never counts not_comparable as an exception
it refuses a re-run of the same batch_reference at the unique index, not in PHP
it refuses to run without --dry-run and writes nothing
it never resolves a row against a student belonging to another School
it persists the control totals and validates a clean file with exit 0
it resolves the enrollment term and class level through the port, one hop each
```

### The watched reds — six rules, planted, red, restored

Each mutation was applied to the real file, the affected test run, the file restored from a byte copy,
and the test re-run. Raw output, cut only where a line was pure whitespace.

```
=== 1 identity (§1) :: MUTATED ===        if (! $derived->equals(…))  →  if (false && ! $derived->equals(…))
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":3,…,"message":"Failed asserting that two variables reference the same object.\n-…(Rejected, 'rejected')\n+…(NotComparable, 'not_comparable')"}
=== 1 identity (§1) :: RESTORED ===
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":8,"duration_ms":13117}

=== 2 blank required column (§2) :: MUTATED ===   if (trim($values[$column] ?? '') === '')  →  if (false)
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":3,…,"message":"…-…(Rejected, 'rejected')\n+…(NotComparable, 'not_comparable')"}
=== 2 blank required column (§2) :: RESTORED ===
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":6,"duration_ms":16571}

=== 3 negative figures (§7) :: MUTATED ===   if ($amounts[$column] !== null && $amounts[$column]->isNegative())  →  if (false)
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":3,…,"message":"…-…(Rejected, 'rejected')\n+…(NotComparable, 'not_comparable')"}
=== 3 negative figures (§7) :: RESTORED ===
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":5,"duration_ms":14360}

=== 4 exact naira→kobo parse (§2) :: MUTATED ===   Money::fromNaira($raw)  →  Money::fromKobo((int) ((float) $raw * 100))
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":3,…,"message":"Failed asserting that 8000014 is identical to 8000015."}
=== 4 exact naira→kobo parse (§2) :: RESTORED ===
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":4,"duration_ms":11491}

=== 5 batch_reference uniqueness at the DB (§7) :: MUTATED ===   $table->unique([...])  →  $table->index([...])
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,…,"message":"expected the unique index to refuse the second run"}
=== 5 batch_reference uniqueness at the DB (§7) :: RESTORED ===
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":3,"duration_ms":15620}

=== 6 school isolation of the roster :: MUTATED ===   Student::query()  →  Student::query()->withoutGlobalScopes()
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,…,"message":"…-…(Rejected, 'rejected')\n+…(NotComparable, 'not_comparable')"}
=== 6 school isolation of the roster :: RESTORED ===
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":6,"duration_ms":12329}
```

Proof 5 is the one that matters most for §7: with the index downgraded, the second run **succeeded**
and the test's own `throw new RuntimeException('expected the unique index to refuse the second run')`
fired. The refusal is the index, not a guard clause. The green asserts driver code **1062**, not a
message and not an exit code — a PHP guard could not produce it.

Proof 6 shows the isolation test is not inherited: with the scope stripped, the cross-School student
resolved and the row stopped being rejected.

**The float counter-example in the brief is wrong, and I checked rather than assumed.** The brief
suggested `8.07`. On this PHP, `(int) (8.07 * 100)` is **807** — the product rounds up to exactly
807.0, so a test built on 8.07 would have passed no matter how the parser worked. I scanned for
values that actually break and used `80000.15`: `(int) ((float) '80000.15' * 100)` is **8000014**,
and the test asserts the stored value is **8000015**. That assertion is in the test itself, so the
counter-example cannot silently stop being one.

### `bin/quality` — all 13 steps, no baseline touched

```
[1/13]  ✓ wayfinder:generate
[2/13]  ✓ lint-changed
[3/13]  ✓ tsc-ratchet
[4/13]  ✓ build
[5/13]  ✓ authz-lint
[6/13]  ✓ boundary-lint
[7/13]  ✓ grants-convergence-lint
[8/13]  ✓ money-lint
[9/13]  ✓ runtime-zero-lint
[10/13] ✓ identifier-generation-lint
[11/13] ✓ arch
[12/13] ✓ larastan
[13/13] ✓ test-ratchet

✓ quality: PASS — per-push floor.
```

The first run failed at step 12 with one Larastan error
(`Strict comparison using !== between Carbon\CarbonImmutable|null and false will always evaluate to
true`). I fixed the code rather than the baseline: `parseDate` now catches `Throwable` (Carbon 3
throws instead of returning false) and null-checks, keeping the round-trip comparison that catches
overflow dates like `2026-02-30 → 2026-03-02`. **No baseline was widened or regenerated** —
`git diff --stat` over `tests/ratchet-baseline.txt`, `boundary-lint-baseline.txt`, `tsc-baseline*`
and `tests/fixtures/` is empty.

### Driven against the dev database, not just the suite

`php artisan migrate --force` on `brookstone_portal_db`, then the command against school#1 with a
4-row file (2 real admission numbers, 1 nonexistent, 1 both-nonexistent-and-identity-broken).
Admission numbers are redacted here; they are production-copy data.

```
Posting is not implemented in this commit. Re-run with --dry-run.
exit=1

Batch [DEVDRIVE-1] staged from [wcbs.csv] — READ-ONLY, nothing was posted.
| rows staged                                    | 4   |
| rejected rows                                  | 2   |
| comparison exceptions (§5 different)           | 0   |
| not comparable (§5 — NOT an exception)         | 2   |
| file rows matching no student                  | 2   |
| students in School absent from the file        | 609 |
| file rows dropped as duplicate keys            | 0   |
| School students with no admission number       | 0   |
| School admission numbers duplicated after trim | 0   |
Control totals (kobo): Σ prior_arrears=2501000 Σ paid_to_date=21000500 Σ wcbs_billed_total=30002000 row_count=4
```

Staged rows (re-read from the table):

```
line 2 status=not_comparable resolved=1 expected=NULL codes=enrollment_term_differs_from_cutover_term,no_active_fee_schedule
line 3 status=not_comparable resolved=1 expected=NULL codes=enrollment_term_differs_from_cutover_term,no_active_fee_schedule
line 4 status=rejected       resolved=0 expected=NULL codes=student_not_found
line 5 status=rejected       resolved=0 expected=NULL codes=identity_mismatch,student_not_found
batch status=rejected rows=4 currency=NGN
```

Two things this proves that the suite could not. **`resolved=1` on real rows** means the extended ACL
hop populated `classLevelId` from production-shaped data — a fixture could have been built to pass
either way. And **`enrollment_term_differs_from_cutover_term`** fired because school#1's students'
active enrollments are on a term other than the `--term=1` I passed, so `termId` is populated too.

Also visible: school#1 has **611 students and 0 active fee schedules**, so every resolved row is
`not_comparable`. That is §5 behaving correctly against the real pre-U1 state, and it is the fact
that matters operationally — **U1 has priced nothing, so §5's comparison is blind today** (spec §6.3
lists this as a pre-flight that can invalidate the plan).

Cleanup: the batch was deleted (`batches=0 rows=0`), which also demonstrated the composite FK's
`ON DELETE CASCADE`. Both new tables are empty; the ground-truth copy is as found.

### Migration rollback, re-derived rather than assumed

Per `docs/testing.md`'s `--step=N` warning, I checked which batch was mine before rolling back rather
than trusting `--step=1`:

```
latest batch=21
  2026_08_06_100000_create_finance_opening_balance_tables      ← alone in the batch
--- rollback ---
2026_08_06_100000_create_finance_opening_balance_tables .. 70.28ms DONE
finance_opening_balance_batches exists=false
finance_opening_balance_rows exists=false
--- re-up ---
2026_08_06_100000_create_finance_opening_balance_tables .. 444.43ms DONE
finance_opening_balance_batches exists=true
finance_opening_balance_rows exists=true
checks=8
```

Asserted *my* tables are gone and came back, not a bare exit-0.

---

## Semantics a reviewer should challenge

Judgement calls where the brief left room, all visible in the enums' docblocks:

1. **A §5 mismatch leaves the row `ok`.** The brief's row statuses are `ok | rejected |
   not_comparable`, which has no slot for "exception". A mismatch is a figure for a human to
   reconcile, not a structural defect, so the row stays `ok`, carries a `comparison_mismatch` finding
   with both sides and the signed difference, and is counted separately from both rejections and
   `not_comparable`. The test asserts `not_comparable` is **not** counted as an exception, as asked.
2. **"batch still validates" (brief §4, unresolved-student case) I read as "the run completes and
   stages everything, and exits non-zero"** — not as `batch.status = validated`. The alternative
   reading contradicts §3h. `status = validated` iff zero rejected rows **and** zero batch-level
   findings. The test asserts the batch persisted with `row_count = 2` and exit 1. **If the intended
   reading was the other one, this is a one-line change and I would rather be told than guess again.**
3. **A duplicate admission number *within one file*** would collide on
   `ob_rows_school_batch_admission_unique` mid-loop and abort the run. The first occurrence is staged;
   later ones are not staged, are counted, and raise a batch-level finding naming their line numbers.
   Nothing is dropped silently — but they are dropped, and that is a decision, not a derivation.
4. **Control totals cover rows whose three summed figures all parsed**, including rows rejected for
   other reasons (line 5 above contributed ₦10 to Σ prior_arrears). A row that could not produce a
   figure contributes nothing rather than a zero.
5. **Console prints no per-student amounts**, per the brief's "never a student's amounts in aggregate
   reports". Figures live in each row's `findings` JSON for U12b. This makes the console less useful
   than it could be; if that reading is too strict, the exception table is where to relax it.
6. **The absent-from-file list printed 50 of 609 entries** with an announced truncation. Announced,
   but a 609-line console report is close to unusable either way — U12b is the real surface.

## What I did not do

- **No `finance-reviewer`-independent second opinion from me.** Per method, I do not review my own
  work; the subagent's findings are returned raw and unanswered.
- **No PHP version matrix, no clean-room** — the documented permanent residuals of the local floor.
- **`uploaded_by_user_id` is always NULL** from a console run (no authenticated causer). It exists for
  U12b's upload path in a later commit. That is a column slightly ahead of its writer; I judged it
  in-scope because the brief named it explicitly, but it is the one item in the schema that a strict
  reading of "no front-loading" could object to.
- **§6.2 (can WCBS split by term?) is unanswered** and is not an engineering question. §1's identity
  is unevaluable without it, and this validator is the thing that will tell Brookstone whether their
  extract satisfies it.
- **I committed `docs/handoff/opening-balance-import-spec.md`** (the premise for this branch) and left
  `finance-mvp-cut-brief.md` and `fragment-resolution-brief.md` untracked, since they belong to other
  work.
