# `perf/manual-run-batch-target-inserts` — one INSERT for the whole selection

**Branched from** `staging` at `881f5ceb`, clean tree.
**Scope, deliberately narrow:** replace `StartManualInvoiceRun`'s per-target `ManualInvoiceRunTarget::create()` with a chunked batch insert. No schema change, no request-contract change, no change to `BelongsToSchool` — the trait 47 models share stays exactly as it is.

---

## 1 · The numbers

`portal_testing`, planted cohort: 611 students across six curricula, 12 of them with no episode. Three reps each, warm, everything inside an outer transaction that was rolled back (students back to 0 afterwards, verified).

|                                         | queries | of which `information_schema` | inserts | selects | txn open ms             |
| --------------------------------------- | ------- | ----------------------------- | ------- | ------- | ----------------------- |
| **before** (`881f5ceb`, quiescent tree) | 1234    | 613                           | 613     | 8       | 767.9 / 798.0 / 812.5   |
| **after**                               | **13**  | **2**                         | **3**   | 8       | **131.9 / 93.6 / 88.6** |

**−98.9 % queries, −86 % transaction-open time.** Outcome identical on both sides: 611 targets, 599 placed, 12 unplaceable, 0 rows with a null or empty uuid, and the target id order matches payload order.

### The `Schema::hasColumn()` hook does NOT reach zero on this path — confirmed, not assumed

613 → **2**. Zero for the targets, which is the whole 611. The two that remain are the run row and the line row, which are still written through `Model::create()` and therefore still fire `BelongsToSchool`'s `creating` hook. They are constant in the size of the selection, so they are no longer the cost — but the ticket ([belongs-to-school-issues-a-schema-query-on-every-insert.md](../tickets/belongs-to-school-issues-a-schema-query-on-every-insert.md)) stays open and this path is not evidence that it is fixed.

The three inserts are: the run, the line, and **one** statement for all 611 targets (611 is far below the chunk size — see below).

---

## 2 · The three traps, each handled rather than discovered

### 2a · No model events fire, so `uuid` is minted explicitly

`ManualInvoiceRunTarget::query()->insert()` goes to the query builder: `creating` never fires, so **neither trait on that model does its work**.

- **`school_id` — nothing lost.** The Action takes the School as an argument and has always written it onto every row explicitly. `BelongsToSchool`'s auto-fill was never the thing supplying it here.
- **`uuid` — minted below, with the same `Str::orderedUuid()` call `AddUuid` uses**, so the values keep the ordered shape the rest of the table has rather than this one table quietly becoming random v4s. `uuid` is the model's `getRouteKeyName()`, so an empty one is a row no URL can reach.

**`bin/ci-identifier-generation-lint.php` was read first, as instructed, and it does not rule on this.** Its patterns are `DB::table('students'|'teachers')->insert|insertOrIgnore|upsert` and `Student|Teacher::insert|insertOrIgnore|upsert|createQuietly` — it is about `admission_number` and `staff_number` only, and matches nothing on `ManualInvoiceRunTarget`. Its _rationale_ is nonetheless the reason the uuid is handled explicitly rather than trusted: it exists because "you must use `save()`" conventions have failed repeatedly here, and it names "halting-event uuid setters" as one of the failures.

The precedent for the shape is `NotificationDelivery::query()->insertOrIgnore($rows)` in `FanOutNotificationJob` (`app/Notifications/Jobs/FanOutNotificationJob.php:105`), which builds rows carrying an explicit `uuid`, `created_at` and `updated_at` for exactly this reason. `bin/ci-boundary-lint.php` forbids `DB::table(` inside `app/Finance/` and `app/Academics/`; the model query builder is not that, so this shape needs no exception.

**Measured, and worth recording:** removing the uuid line is refused at the engine — `1364 Field 'uuid' doesn't have a default value` — because the column is `NOT NULL` with no default and this host runs `STRICT_TRANS_TABLES`. So on a strict server that particular trap is loud, not silent. Production's `sql_mode` has not been read and this report does not claim it; on a non-strict server the same omission writes `''`.

### 2b · Timestamps are supplied, never defaulted

`insert()` does not stamp `created_at` / `updated_at`, and an implicit column default would be the wrong repair. Production is Percona 5.7.23 with `explicit_defaults_for_timestamp` OFF, where the first `TIMESTAMP` column of a table silently acquires `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` — a server-clock value in a different frame from every timestamp the application writes, plus an `ON UPDATE` that rewrites it afterwards ([implicit-timestamp-defaults-on-rebuild.md](../tickets/implicit-timestamp-defaults-on-rebuild.md)).

One `now()` for the whole batch, the same instant on every row: Eloquent would have stamped each row at its own `freshTimestamp()`, and a run's targets are written by one act — reading them back sorted by `created_at` should not depend on how long the loop took.

### 2c · Order survives the chunking — measured, not assumed

The job walks targets by id and the report prints them in the order the bursar submitted, so **id order must follow payload order**.

`array_chunk` preserves order, a multi-row `INSERT` assigns `AUTO_INCREMENT` in `VALUES` order, and the chunks run sequentially inside one transaction. That reasoning was not trusted. **The chunk size was forced down to 10 rows and the real Action was run over the same 611-student cohort**, producing 62 target statements (64 inserts including the run and the line):

```
rep1 queries=74  insert=64  targets=611  order_matches_payload=YES
rep2 queries=74  insert=64  targets=611  order_matches_payload=YES
rep3 queries=74  insert=64  targets=611  order_matches_payload=YES
```

`innodb_autoinc_lock_mode = 2` on this host — the interleaved default, which is where this would break if it broke anywhere. A concurrent session can take ids between two chunks; that leaves **gaps**, never a reordering.

The comparison is against the payload, not against a sort: the arms' fixture helper `mirsSelection()` returns the ids **reversed**, so payload order is deliberately not the students' own id order. With the payload in creation order, a target table that ignored the payload entirely and happened to sort by `student_id` would have satisfied the assertion.

---

## 3 · The chunk size, and what was measured to choose it

**4,095 rows**, and it is derived at runtime rather than written down:

```php
intdiv(self::PLACEHOLDER_BUDGET, count($rows[0]) * 2)   // 65535 / (8 * 2)
```

**Two ceilings, both measured.**

**The placeholder ceiling, bite-proved.** MySQL encodes a prepared statement's parameter count in two bytes, so one statement carries at most 65,535 placeholders. Probed on 8.0.43 against a `TEMPORARY` table of this row's exact eight-column shape:

```
n=8191   placeholders=65528  ACCEPTED  (rows now 8191)
n=8192   placeholders=65536  REFUSED   SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders
n=8300   placeholders=66400  REFUSED   SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders
```

The boundary is exact, and it is a hard error rather than a truncation — which is why a number is taken _below_ it rather than at it.

**The packet ceiling, measured on the real statement.** Compiling the actual insert through the model's own grammar:

| rows  | placeholders | SQL bytes | binding bytes | wire bytes | bytes/row |
| ----- | ------------ | --------- | ------------- | ---------- | --------- |
| 1     | 8            | 190       | 134           | 324        | —         |
| 100   | 800          | 2 764     | 13 400        | 16 164     | 161.6     |
| 1 000 | 8 000        | 26 164    | 134 000       | 160 164    | **160.2** |

At 160.2 bytes per row, 4,095 rows is **~656 KB**. This developer machine reports `max_allowed_packet = 67108864` (64 MiB, the 8.0 default) — but **production is Percona 5.7.23, whose default is 4 MiB**, and that is the number this has to be safe against. 656 KB is ~16 % of it. Even at the placeholder ceiling of 8,191 rows the statement would be ~1.3 MiB, still inside 4 MiB — so **the placeholder cap binds first, by 3.2×**, and the packet has headroom either way.

**Why the divisor is the row's own width and not a literal.** A constant would have been the same number today and silently wrong the day a column is added to `finance_manual_invoice_run_targets` — a wider row lowers the placeholder ceiling, and the failure mode is 1390 at whatever size someone happens to run. Taking `count($rows[0])` from the row cannot drift.

**Why the halving.** It is the margin against the packet ceiling and against a future wider row: at sixteen columns the chunk halves to 2,047 rather than the statement doubling. It costs one extra round trip per 4,095 students, which is nothing against a cohort a school actually has — the largest class level in the production copy is 116, and the whole school is 611.

---

## 4 · The two arms

### 7a — cost, rewritten rather than kept

The previous shape asserted **reads flat, writes grow one per target**. The second half is now false by design, so the arm was **reworded to pin the new contract**: _neither_ reads nor writes grow with the selection. A `currentForStudent()` restored in the loop breaks the first half; a `ManualInvoiceRunTarget::create()` restored in the loop breaks the second.

**The non-vacuity guard had to move with it.** While writes grew one per target, "writes grew by 27" was itself the proof that the two measured windows really differed by 27 students. Now nothing in the query counts moves with N, so that proof comes from the **rows**: the two runs are asserted to have produced 3 and 30 targets. Without it, two runs that both did nothing would pass.

**The FROM-clause exclusion is gone**, and its removal is recorded in the section preamble rather than silently dropped. It existed because `BelongsToSchool`'s hook made schema-catalogue reads grow one per target; the batched insert fires no model events, so on this path they no longer do (613 → 2, both constant). Nothing is filtered now and the arm is simpler for it.

### 7b — correctness, which the last commit did not need and this one does

A batched insert can be perfectly flat and quietly wrong, because it skips the model events. 7a stays green on rows with no uuid and no timestamps — proved, below.

Every expectation is derived by the **other** code path or from the payload, never from the batch's own rule: the episode each row should name comes from calling `currentForStudent()` per student, one at a time. The arm pins, over a 12-student selection (8 enrolled, 4 not, submitted in reverse creation order):

- the count is the selection's;
- **id order is payload order**, plus an explicit assertion that the payload is _not_ in sorted order, so the ordering claim cannot pass degenerately;
- per row, `enrollment_id` and `enrollment_uuid` equal what the single-student resolver returns, and are NULL exactly where it returns null;
- the fixture really contained both halves — 8 placed, 4 NULL — so neither branch of the loop was skipped;
- every row carries a valid `uuid`, the right `school_id`, and non-null `created_at` / `updated_at`;
- the uuids are **distinct**, which a per-row check cannot see: one shared uuid satisfies every other assertion and breaks the route key for eleven rows.

---

## 5 · Mutations — three, all verified applied before running

### A · the per-row `create()` restored — 7a reds

```php
-  foreach (array_chunk($rows, $this->targetChunkSize($rows)) as $chunk) {
-      ManualInvoiceRunTarget::query()->insert($chunk);
+  foreach ($rows as $row) {
+      ManualInvoiceRunTarget::create($row);
   }
```

```
Failed asserting that 27 is identical to 0.
tests/Feature/Finance/ManualInvoiceRunScreenTest.php:1059     (the WRITES delta)
```

`tests 36  passed 35  failed 1` across `ManualInvoiceRunScreenTest` + `ManualInvoiceRunTest` — **7b stayed green**, which is correct and is the point of having two arms: the rows the per-row write produces are identical, so only the shape arm can see this.

### B · the `uuid` line removed — refused at the engine

The faithful mutation of "somebody trusted `AddUuid` to still fire". Both arms error:

```
SQLSTATE[HY000]: General error: 1364 Field 'uuid' doesn't have a default value
```

Loud rather than silent, because the column is `NOT NULL` with no default and this host runs `STRICT_TRANS_TABLES`. Recorded as a measured fact about this host, not as a claim about production.

### C · the `created_at` line removed — 7b reds, 7a green

The genuinely silent one: `created_at` is nullable, so the row is written with a NULL and nothing complains.

```
Expecting null not to be null .
tests/Feature/Finance/ManualInvoiceRunScreenTest.php:1124
tests 2  passed 1  failed 1     (7a green, 7b red)
```

That is the split the two arms exist for: a column quietly lost is invisible to every cost measurement.

**A note on the mutation mechanics, because it nearly cost something.** The restore substitution for B matched `'school_id' => $schoolId,` followed by `'run_id' => $run->id,` — a pair that also appears in the `ManualInvoiceRunLine::create()` block above — and injected a spurious `uuid` key there. Caught by reading the file back before running, not by a test. The repository's own rule applies: keep a mutation a one-line edit, verify it was applied, and read the file rather than trusting the substitution.

---

## 6 · Gates run locally

`bin/quality` was **not** run — Segun runs it in his own terminal.

| gate                                                                                                                          | result                      |
| ----------------------------------------------------------------------------------------------------------------------------- | --------------------------- |
| `pest tests/Feature/Finance`                                                                                                  | 999 passed, 5414 assertions |
| `pest --group=arch`                                                                                                           | 115 passed                  |
| `pint --test` (changed files, array form)                                                                                     | passed                      |
| `composer analyse` (Larastan)                                                                                                 | 0 errors                    |
| authz · boundary · citation · money · sql-clock · dev-namespace · identifier-generation · runtime-zero · dependency-integrity | all OK                      |

The citation lint refused the new `BelongsToSchool.php:21` pointer once: it accepts `path:LINE (symbolName)` or `symbolName (path:LINE)` and nothing else — `(symbolName, path:LINE)` reads as a bare citation.

---

## 7 · What this does NOT do

- **It does not touch `BelongsToSchool`.** Its `Schema::hasColumn()` is still an uncached `information_schema` query on every model insert everywhere else in the codebase; two of them remain on this path, for the run row and the line row. The ticket stays open.
- **It does not batch the run row or the lines.** One row and (today) a handful; batching them would buy nothing and would cost both of them their model events for no reason.
- **It does not change the request contract, the roster ceiling, or the schema.**
- **It does not claim production's `sql_mode` or `max_allowed_packet`.** The chunk size is chosen to be safe under Percona 5.7.23's _documented default_ packet of 4 MiB; neither value has been read from the production server by this branch.
