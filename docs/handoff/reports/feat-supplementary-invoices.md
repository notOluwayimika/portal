# `feat/supplementary-invoices` — invoices have a kind, and the one-per-episode guard constrains only the scheduled one

**Base:** `origin/staging` @ `af2cc2f` · **Branch:** `feat/supplementary-invoices` · **Shape:** one
migration, one enum, three domain files, one new test file, 26 existing test files touched. One commit.

**Tier: FULL REVIEW.** This change alters a live money table with a rebuilding `ALTER`, re-keys a
uniqueness invariant that prevents double-billing, and installs three triggers. Subagent review
attached; a cold session before merge is recommended.

## `bin/quality` — PASS, 15/15, on the third attempt

```
quality gate — base af2cc2f
[1/15] dependency integrity ................ ✓    [9/15]  money lint ................. ✓
[2/15] wayfinder:generate --with-form ...... ✓    [10/15] runtime-zero lint ......... ✓
[3/15] lint changed files (32 PHP files) ... ✓    [11/15] identifier-generation ..... ✓
[4/15] types (tsc ratchet) ................. ✓    [12/15] sql-clock lint ............ ✓
[5/15] frontend build (vite) ............... ✓    [13/15] architecture tests ........ ✓
[6/15] authz lint .......................... ✓    [14/15] static analysis (Larastan)  ✓
[7/15] boundary lint ....................... ✓    [15/15] tests (failure ratchet) ... ✓
[8/15] grants-convergence lint ............. ✓

✓ quality: PASS — per-push floor.
```

**The first two attempts failed, and neither is hidden here.**

**Attempt 1 — FAIL, and it was my diff.** Nine new failures: eight in `InvoiceNumberPrefixTest`
(1364, the `Invoice::create` fixture I missed) and one in `TriggerBodiesAreDumpSafeTest` (the
apostrophe in the immutability message). Both are fixed above and both are described where they
belong rather than only here. `bin/quality` caught what I did not.

**Attempt 2 — FAIL, and it was NOT my diff, and it was not a flake either.** 369 failures dominated
by `1050 Table 'jobs' already exists`, `1146 Table 'portal_testing.migrations' doesn't exist`, and
`1054 Unknown column 'two_factor_required'` — table-level DDL racing, not assertion failures. Cause
identified rather than assumed: `ps` showed PID 36051 running `php ./vendor/bin/pest
tests/Feature/Finance` against the same `portal_testing` database while the gate's own suite step
ran. Two concurrent `migrate:fresh` runs dropping and creating tables underneath each other.

That is worth stating plainly because ADR 0053 records that a red cannot be told from a flake by
looking at it, and that retrying until green is indistinguishable from fixing. This was neither
retried blind nor written off: the competing process was found, stopped, absence of any second
`pest` confirmed from the process table, and the gate re-run. Attempt 3 is the clean one.

*(A process-watch I armed during attempt 3 fired repeatedly with "CONTAMINATION: 2 concurrent pest
processes". It was matching its own `grep` pattern in its own shell's command line. Direct inspection
showed exactly one `pest` — the gate's. Recorded so the noise is not mistaken for evidence.)*

---

## Deviations from the brief, and one thing it asked for that MySQL does not permit

**1 — "one transaction where MySQL allows it" — MySQL does not allow it, and I did something
stronger instead.** DDL commits implicitly on MySQL: every `ALTER TABLE` and `CREATE TRIGGER` ends
any open transaction, so `DB::transaction()` around this migration would have produced the
appearance of atomicity across statements and none of the substance. What the brief was protecting —
"the one moment where the double-billing guard does not exist" — is closed a different way: the drop
of the index, the change of the generated expression and the re-add of the index are **one
`ALTER TABLE` statement** with three clauses, applied in a single table rebuild that MySQL 8.0's
atomic DDL commits or rolls back as a unit. There is no committed state in which the table carries
the new expression and no unique index over it. The window is not narrowed; it does not exist.

Holding both indexes at once was considered and is impossible: the old index keys on
`status = 'issued'` alone, so while it stands a supplementary invoice collides with the episode's
scheduled one — the exact thing being fixed.

**2 — the 422's wording is now imprecise, and I did not change it.** `GenerateInvoice` still says
*"This enrollment already has an active invoice. Void it before billing again."* With supplementary
invoices in existence that should read *"active **scheduled** invoice"*. I left it because
`resources/js/components/finance/new-invoice-modal.tsx:415` carries its own hardcoded copy of the
same sentence, and the modal is commit 2 and explicitly out of scope here. Changing one and not the
other creates a mismatch a reader would have to reconcile. **Flagging rather than picking a side:
the two strings should be corrected together in commit 2.**

**3 — I strengthened three existing assertions rather than only repairing them.** See "Existing
tests changed" below; each was about to pass for the wrong reason.

---

## What was built

### 1. `finance_invoices.kind` — `VARCHAR(16) NOT NULL`, no default

`database/migrations/2026_08_18_100000_finance_invoices_kind_and_scheduled_only_episode_guard.php`.

Read back from `information_schema` after migrating:

```
KIND COLUMN: {"COLUMN_TYPE":"varchar(16)","IS_NULLABLE":"NO","COLUMN_DEFAULT":null,"EXTRA":"","GENERATION_EXPRESSION":""}
```

`COLUMN_DEFAULT` is `null` and `IS_NULLABLE` is `NO`, which is what makes an omitting writer meet
**1364** rather than a silently-chosen side. Proven in `SupplementaryInvoiceTest`.

**A transient default is used and then dropped, and this is not a contradiction of "no default".**
`ADD COLUMN … NOT NULL` with no default does not fail on a populated MySQL table — it fills every
existing row with the type's implicit default (`''`), which is outside the domain. DDL commits
implicitly, so a backfill `UPDATE` afterwards is a *second* transaction, and there is a window —
permanent if the migration dies between the two — in which live invoice rows hold a kind that is not
a kind. Adding with `DEFAULT 'scheduled'` and dropping the default immediately removes that window:
every existing row is correct at the instant the column exists. The backfill `UPDATE` is kept anyway
(expected to affect 0 rows) and is followed by a `COUNT` that throws if anything is not `scheduled`.

Backfilling to `scheduled` is correct **by construction**, not by assumption: before this migration
the only invoice-creating path is `GenerateInvoice`, raising the episode's fees, which is what
`scheduled` means.

### 2. The re-key

```
active_enrollment_key = IF(status = 'issued' AND kind = 'scheduled', student_curriculum_id, NULL)
UNIQUE (school_id, active_enrollment_key)
```

Both properties slice 2's docblock records are preserved and both are exercised:

- it stays **GENERATED**, so no code path can forget to set or clear it;
- voiding still recomputes it to **NULL**, and NULLs do not collide in a MySQL unique index, so the
  policy's "repeat = billed fresh" re-bill after a void still works. A supplementary invoice now
  reaches NULL by the *second* arm, by exactly the same mechanism.

`information_schema` read-back after `migrate`:

```
KEY COLUMN: {"EXTRA":"STORED GENERATED","GENERATION_EXPRESSION":"if(((`status` = _utf8mb4\\'issued\\') and (`kind` = _utf8mb4\\'scheduled\\')),`student_curriculum_id`,NULL)"}

INDEX: [{"INDEX_NAME":"finance_invoices_active_enrollment_unique","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"COLUMN_NAME":"school_id"},
        {"INDEX_NAME":"finance_invoices_active_enrollment_unique","NON_UNIQUE":0,"SEQ_IN_INDEX":2,"COLUMN_NAME":"active_enrollment_key"}]
```

Present, `NON_UNIQUE = 0`, covering `(school_id, active_enrollment_key)` in that order. The migration
asserts all three of those facts itself and refuses to record itself otherwise (ADR 0052);
`SupplementaryInvoiceTest` asserts them again, because the migration's own read-back cannot see a
*later* migration changing them.

**Collation, stated rather than left to be rediscovered.** `kind = 'scheduled'` in the generated
expression is evaluated under the table's `utf8mb4_unicode_ci`, so it would also match `'Scheduled'`.
That is the safe direction — a case variant would still be *constrained* rather than escaping the
index — and it is unreachable anyway because the domain trigger pins membership with
`COLLATE utf8mb4_bin`. It matches slice 2's bare `status = 'issued'` for the same reason.

### 3. `kind` is immutable — a separate trigger, not an arm of the existing one

**The choice, and why.** Adding `kind` to `finance_invoices_total_immutable`'s denied set was the
alternative. A separate `finance_invoices_kind_immutable` was chosen for two reasons. First, that
trigger's *name* states what it guards — the money snapshot, F6, `total = SUM(lines)` — and folding
an unrelated column in would make the name a lie and the message misleading: an operator refused for
editing `kind` would be told the invoice total is snapshotted at creation. Second, it is the
precedent this schema already set — `2026_08_17_100000` added `BEFORE UPDATE` triggers to five
tables that already carried one rather than folding them in, on the stated ground that "a table's
existing guard keeps its own name, its own message and its own tests".

**What it closes.** `finance_invoices` legitimately allows UPDATE (issued → void). Without this,
the invariant is bypassable in two statements: flip the episode's live scheduled invoice to
`supplementary` — its key recomputes to NULL, freeing the slot while it is still issued and still
collecting payments — then issue a second scheduled invoice.

### 4. The domain of `kind` is a trigger, not a `CHECK`

Production is MySQL 5.7 and parses-and-discards `CHECK` silently
(`docs/finance/check-constraints-on-mysql-5-7.md`). `BEFORE INSERT` + `BEFORE UPDATE`,
`SIGNAL SQLSTATE '45000'`, `COLLATE utf8mb4_bin`, each read back from `information_schema.TRIGGERS`
after `CREATE`.

`information_schema.TRIGGERS` read-back on `finance_invoices` after `migrate` (ordered by timing,
event, `ACTION_ORDER`):

```
finance_invoices_kind_domain_bi   BEFORE INSERT   ACTION_ORDER 1
finance_invoices_total_immutable  BEFORE UPDATE   ACTION_ORDER 1
finance_invoices_kind_domain_bu   BEFORE UPDATE   ACTION_ORDER 2
finance_invoices_kind_immutable   BEFORE UPDATE   ACTION_ORDER 3
finance_invoices_no_delete        BEFORE DELETE   ACTION_ORDER 1
```

The order is the one the docblock claims, so the message an operator sees is determined:
`kind = 'garbage'` is refused by the domain arm, `kind = 'supplementary'` passes it and is refused as
immutable, and a bare `status = 'void'` passes both.

**A second measurement, and a hole in an existing oracle.** Neither message contains a single quote,
and that is not style. MySQL accepts an escaped apostrophe in a `SIGNAL` message at `CREATE` time and
then **stores the body with the escape stripped** — the trigger works in place and `mysqldump` emits
SQL that will not re-import. `TriggerBodiesAreDumpSafeTest` exists because that already cost this
project a failed database copy. It caught my first draft's immutability message:

```
trigger body has an unterminated string literal (usually an apostrophe in a SIGNAL message,
which MySQL stores unescaped): finance_invoices_kind_immutable
```

**It did not catch the domain triggers, which were equally broken.** That test's invariant is an ODD
count of single quotes. The possessive `episode''s` left five quotes and was flagged. The domain
message's quoted enum values, `''scheduled'' or ''supplementary''`, left **twelve** — balanced,
therefore green. Read back verbatim from `information_schema.TRIGGERS`:

```
finance_invoices_kind_domain_bi (quote count: 12)
BEGIN
 IF NEW.kind COLLATE utf8mb4_bin NOT IN ('scheduled', 'supplementary') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
 'finance_invoices.kind must be exactly 'scheduled' or 'supplementary'.';
 END IF;
 END
```

That `MESSAGE_TEXT` line is not valid SQL and would fail a restore exactly as the apostrophe case
does. **Balance is necessary and not sufficient**, and the test passes it. Both messages now carry no
quote at all, and the domain message names the two values as bare words.

**This is a finding about the oracle, not only about my triggers — TICKET severity.**
`TriggerBodiesAreDumpSafeTest` reports green on a class of body it was written to catch. I did **not**
change it: it is a shared oracle over every trigger in the schema, a stricter check may well flag
pre-existing triggers, and that is a separate change with its own blast radius and its own proof
obligations. It should not ride in on a supplementary-invoicing commit. Recommend a ticket.

**A measurement that cost a red, and is recorded in the migration docblock.** `MESSAGE_TEXT` is
capped at **128 characters**. The immutability message was first written as a full explanation of the
attack; MySQL 8.0.43 answered **1648** (`ER_COND_ITEM_TOO_LONG`) at *SIGNAL* time — not at
`CREATE TRIGGER` time, which succeeded and read back with the correct shape. So a trigger whose
message is too long installs cleanly, passes an `information_schema` read-back, and then raises the
**wrong error code** on every write it was meant to refuse. It still refuses the write, which is why
this is subtle rather than an open hole: an arm asserting only `toThrow(QueryException::class)` would
have stayed green. This is the concrete reason every refusal arm in the new file asserts the driver
code.

### 5. `GenerateInvoice` takes the kind from its caller — required, not defaulted

```php
public function handle(string $enrollmentUuid, array $lines, InvoiceKind $kind, ?int $actorId = null): Invoice
```

**Required and third, deliberately.** A default would reintroduce exactly the silent-wrong-value
failure the NOT NULL / no-default column exists to prevent, and it is inconsistent to make the
column loud and the API quiet. Third rather than last because PHP forbids a required parameter after
an optional one. Every existing caller therefore fails loudly — 2-argument callers with
`ArgumentCountError`, 3-argument ones with `TypeError` — and all now pass `InvoiceKind::Scheduled`
explicitly. Behaviour is unchanged by this commit.

**Both duplicate arms are scoped to `scheduled`, and both had to be:**

- `assertNoActiveInvoice` (`GenerateInvoice.php:485`) gained `->where('kind', InvoiceKind::Scheduled->value)`
  so it mirrors the index exactly. Without it a supplementary charge raised in week 2 makes the term
  bill unraisable and the operator is told to void an invoice that is not the term bill.
- the duplicate-key translation is now `if ($kind->isEpisodeExclusive() && $this->isActiveEnrollmentCollision($e))`.
  When raising a supplementary invoice a 1062 on that index is impossible (its key is NULL), so if
  one arrives the generated expression is not what the Action believes — and the raw `QueryException`
  is rethrown deliberately. A 500 naming the index is diagnosable; a friendly 422 telling a bursar to
  void the term bill before adding a trip fee is a wrong answer stated confidently.

**Call sites updated to `InvoiceKind::Scheduled`:** `InvoiceController::generate`,
`InvoiceController::generateForStudent`, `DriveFinanceStates::invoice`.

---

## Planted reds — six, one per guard, each restored and re-run green

Every plant was a real mutation of the shipped file, the suite was run against it, and the file was
restored from a copy held outside the repository.

**A — the immutability trigger is not installed.**

```
{"result":"failed","tests":1,"passed":0,"failed":1,"failures":[{"test":"it an UPDATE changing kind is REFUSED by finance_invoices_kind_immutable (1644)",
  "line":250,"message":"Failed asserting that null is identical to 1644."}]}
```

`null` is the driver code when nothing threw: the UPDATE was **accepted**. Restored → passed, 5 assertions.

*(A first attempt at this plant renamed the trigger instead of removing its installation, and the
test stayed green — correctly, because a renamed trigger still fires. Recorded because a plant that
does not actually remove the guard is a green that proves nothing, which is the failure this
discipline exists to catch.)*

**B — the domain `BEFORE INSERT` trigger is not installed.**

```
{"result":"failed","failures":[{"test":"it an INSERT with a kind outside the domain is REFUSED by the domain trigger (1644)",
  "line":290,"message":"Failed asserting that null is identical to 1644."}]}
```

Line 290 is the `kind => 'sundry'` arm — nonsense accepted into the column.

**C — `COLLATE utf8mb4_bin` removed from the domain trigger body.**

```
{"result":"failed","assertions":3,"failures":[{"test":"it an INSERT with a kind outside the domain is REFUSED by the domain trigger (1644)",
  "line":300,"message":"Failed asserting that null is identical to 1644."}]}
```

Note it got **three** assertions in before failing: `'sundry'` and `''` were still refused, and the
arm that fell over is line 300, the case variant `'Supplementary'`. That is the precise behaviour the
`COLLATE` clause buys, isolated.

**D — the generated key reverted to slice 2's expression (`status = 'issued'` only).**

```
{"result":"failed","errors":1,"error_details":[{"test":"it a SCHEDULED plus ANY NUMBER of SUPPLEMENTARY invoices coexist on one episode",
  "message":"SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-1' for key
   'finance_invoices.finance_invoices_active_enrollment_unique' ... values (1, 1, 1, 2, issued, supplementary, ...)"}]}
```

Two things at once: the feature is dead without the re-key, and the raw 1062 surfacing rather than a
422 confirms the translation really is scoped to `scheduled`.

**E — `assertNoActiveInvoice` loses its `kind` filter.**

```
{"result":"failed","errors":1,"error_details":[{"test":"it a SUPPLEMENTARY invoice raised FIRST does not block the term bill",
  "file":"app/Finance/Actions/GenerateInvoice.php","line":494,
  "message":"This enrollment already has an active invoice. Void it before billing again."}]}
```

**F — the pre-check runs for every kind (the `isEpisodeExclusive()` guard removed).**

```
{"result":"failed","errors":1,"error_details":[{"test":"it a SCHEDULED plus ANY NUMBER of SUPPLEMENTARY invoices coexist on one episode",
  "file":"app/Finance/Actions/GenerateInvoice.php","line":492,
  "message":"This enrollment already has an active invoice. Void it before billing again."}]}
```

E and F are distinct failures at two different lines — the pre-check's `kind` filter and the
Action's `kind` branch are independently load-bearing, and neither substitutes for the other.

**After restoring everything:** `{"result":"passed","tests":10,"passed":10,"assertions":50}`.

---

## Migration reversibility — audited by re-deriving the depth, not by `--step=1` faith

`migrate:status` showed `2026_08_18_100000_…` as the last entry, batch `[2]`, so `--step=1` is
*this* migration and not another stream's. After `migrate:rollback --step=1`:

```
kind col exists: false
key expr: if((`status` = _utf8mb4\'issued\'),`student_curriculum_id`,NULL)
index:    [{"NON_UNIQUE":0,"COLUMN_NAME":"school_id"},{"NON_UNIQUE":0,"COLUMN_NAME":"active_enrollment_key"}]
triggers: ["finance_invoices_total_immutable","finance_invoices_no_delete"]
```

The column is gone, the expression is slice 2's verbatim, the index survives and is still UNIQUE over
the same two columns, and the trigger set is exactly the pre-migration pair. `down()` uses the same
one-statement shape, so the rollback leg is never left with an unguarded table either. Re-`migrate`
succeeded (the trigger installs are `DROP … IF EXISTS` first, so the rollback/re-up leg of
`bin/quality-clean-db` re-asserts rather than 1359-ing).

---

## Tests

**New:** `tests/Feature/Finance/SupplementaryInvoiceTest.php` — 10 tests, 50 assertions.

Every refusal arm asserts the **driver code**, not the exception class. On this table
`QueryException` is what 1062, 1364, 1452 and 1644 all arrive as, so `toThrow(QueryException::class)`
here is close to `toThrow(Throwable::class)`. Every refusal arm also writes **raw**, because the
authority is a unique index over a generated column and an arm that only drives the Action proves
the pre-check.

Covering the brief's list: two scheduled refused **by the index** (raw insert, 1062, index named,
alongside the Action's 422 shown separately as the pre-check); a scheduled plus four supplementary
coexisting, with the generated keys asserted directly (`[episode, null, null, null, null]`), five
ledger charges posted, and the scheduled invoice's total and status untouched; a supplementary
raised **first** not blocking the term bill; void-then-rebill still freeing the slot **with a
supplementary standing at the same time**; kind UPDATE refused; kind outside the domain refused
(nonsense, empty string, and case variant); 1364 on an omitted kind; the shape read from
`information_schema`; and per-School isolation of the index.

One arm exists purely to distinguish a correct guard from a broken one: *"the issued → void status
flip still works"*. A trigger refusing every UPDATE would look identical in the immutability arm
while breaking voiding entirely.

### Existing tests changed

**Three changed because they were about to pass for the wrong reason** — each does a raw
`DB::table('finance_invoices')->insert()` omitting `kind`, which now throws 1364. All three asserted
only `toThrow(QueryException::class)`, so all three would have stayed green while testing nothing
they claim to test:

| File | Was proving | Now |
| --- | --- | --- |
| `MultiLineInvoiceTest.php` — DUPLICATE GUARD BITE-PROOF | some QueryException | `kind => 'scheduled'` supplied; asserts **1062** and that the message names `finance_invoices_active_enrollment_unique` |
| `InvoiceConcurrencyTest.php` — two racing generations | some QueryException | `kind => 'scheduled'` supplied on both rows and asserted on the committed one; asserts **1062** + index name; **throws explicitly if the insert is accepted**, which the old `expect(fn () => …)` shape could not distinguish from a refusal |
| `EnrollmentSchoolIntegrityTest.php` — composite-FK school integrity | some QueryException | `kind => 'scheduled'` supplied; asserts **1452** |

**A fourth changed because it broke outright**, and I missed it on the first pass — it was caught by
`bin/quality`, not by me. `InvoiceNumberPrefixTest.php` builds its fixture with
`Invoice::create([...])` rather than through the Action, so it took 1364 on all eight of its tests:

```
SQLSTATE[HY000]: General error: 1364 Field 'kind' doesn't have a default value
```

It now passes `'kind' => InvoiceKind::Scheduled`. No assertion changed; these are term bills, which
is the right fixture for numbering. Recording the miss because it is the shape of the omission worth
knowing about: I enumerated `Invoice::create` call sites early, saw this one, and then patched only
the `DB::table(...)->insert()` sites — the enumeration was right and the follow-through was not.

**Twenty-three changed mechanically**, and only mechanically: the required third argument to
`GenerateInvoice::handle` propagated as `InvoiceKind::Scheduled`, plus the `use` line. **No assertion
in any of them was weakened, changed or removed.** They are `AccountPaymentTest`, `BackstopGuardsTest`,
`CaptureColumnsTest`, `CreditNoteConcurrencyTest`, `CreditNoteTest`, `InvoiceConcurrencyTest`,
`InvoiceSettlementTest`, `LedgerCoherenceTest`, `MoneyCurrencyValidationTest`, `MultiLineInvoiceTest`,
`OpeningBalancePostingTest`, `OverAllocationGuardTest`, `PaymentCurrencyGuardTest`,
`PaymentProvenanceTest`, `PaymentReceiptTest`, `PaymentRecordGateTest`, `SchemaConventionsTest`,
`SchoolContextGuardTest`, `WalletApplyForwardTest`, `WalletConcurrencyTest`, `WalletCreditTest`,
`WalletW3ConcurrencyTest`, `MakerCheckerSeparationTest`.

### One red that was NOT the diff, classified before it was re-run

Mid-verification a run of the new file failed with `1213 Deadlock ... update roles set name =
accounts_supervisor where name = finance_director` inside `RbacSeeder`, and a later one with
`1146 Table 'portal_testing.finance_opening_balance_batches' doesn't exist` and
`1050 Table 'users' already exists` in the same run. Those are two concurrent `migrate:fresh` runs
dropping and creating tables underneath each other — the review subagent exercising the same
`portal_testing` database. Not classified as a flake and not retried until green: the cause was
identified (`information_schema.PROCESSLIST`), the database was allowed to go quiet, and the affected
files then passed together — 55 tests, 254 assertions. Recorded because "re-ran it and it went green"
is indistinguishable from "fixed it" unless the cause is named.

### The ambiguity the brief asked about, answered

**`MultiLineInvoiceTest`'s duplicate arms did not name a kind, and I did not quietly pick a side —
they were already unambiguous once read.** Both go through
`POST /api/v1/finance/invoices`, which is the term-bill route and now passes `InvoiceKind::Scheduled`
explicitly; the raw-insert bite-proof copies its columns from the row that route created. So
"a second invoice on this episode is refused" meant, and still means, *a second **scheduled**
invoice* — the kind is now stated in the row rather than implied by the route. The same holds for
`InvoiceConcurrencyTest`, where I additionally assert `$row->kind === 'scheduled'` so the arm's scope
is visible in the test rather than inferable from the route it happened to use.

I found **no** existing test asserting a second-invoice refusal in a way that is genuinely ambiguous
between the two kinds.

---

## What I did NOT do

- **No UI.** `new-invoice-modal.tsx` is untouched; commit 2.
- **`kind` is not exposed on `InvoiceResource`.** No wire shape changed in this commit, so no client
  can read or set it yet. Commit 2 needs it; adding it here would be API surface with no consumer.
- **No route or permission for raising a supplementary invoice.** `GenerateInvoice` accepts the kind;
  nothing on the wire can ask for it. Both HTTP routes hardcode `Scheduled`. **The feature is
  reachable only from PHP until commit 2** — worth being explicit about, because "supplementary
  invoicing works" is true of the domain and not yet of the product.
- **No 5.7 measurement.** No MySQL 5.7 was available. That `SIGNAL SQLSTATE '45000'`,
  `COLLATE utf8mb4_bin` in a trigger body, multiple same-timing triggers per table, and the
  128-character `MESSAGE_TEXT` cap behave on 5.7 as they do on 8.0.43 is **documented, not observed** —
  the same standing as every other trigger in this schema, per
  `docs/finance/check-constraints-on-mysql-5-7.md`.
- **The single-statement `ALTER` was measured on 8.0.43 only.** It is a table-rebuild
  (`ALGORITHM=COPY`) on `finance_invoices`; on a production-sized table that is a locking rebuild.
  I did not measure it against production row counts.
- **No screen was driven.** Nothing renders differently; there is no screen for this yet.
- **`docs/roadmap.md`'s residual-gap entry** (the slice-2 "no add-line-to-an-existing-invoice path"
  note) is not updated. This change does not close that gap — it routes around it with a second
  invoice rather than adding a line to a sealed one. The seal remains unbuilt.
