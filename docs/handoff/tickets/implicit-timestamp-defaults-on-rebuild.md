# TICKET — the implicit `ON UPDATE` materialises on REBUILD, not on deploy

**Status:** **NOT LATENT. PRODUCTION CARRIES IT ON THREE COLUMNS**, read 2026-08-13. The migration
`2026_08_13_100000_timestamp_columns_drop_implicit_on_update.php` on
`fix/notices-starts-at-server-clock` cleans all three. It has **not been run on production** — that
is the project lead's to run. The class stays open for the *rebuild* case, which no migration closes.

## ⚠️ CORRECTION — "exactly one row" was a reading of the COPY, not of production

**This file, and the report that shipped with it, previously stated that exactly one column carried
the attribute.** That number came from the advisor's `information_schema` query against
`portaa10_portal` — the LOCAL COPY — and was presented as a completeness claim. **It was not one.**
Production was read on **2026-08-13** and carries the attribute on **three** columns:

```text
finance_ledger_transactions.posted_at    default=CURRENT_TIMESTAMP  extra='on update CURRENT_TIMESTAMP'
notices.starts_at                        default=CURRENT_TIMESTAMP  extra='on update CURRENT_TIMESTAMP'
notification_actions.expires_at          default=CURRENT_TIMESTAMP  extra='on update CURRENT_TIMESTAMP'
```

**Why the copy disagreed, because the reason generalises and is worth more than the correction.**
Tables restored from a production dump carry production's MATERIALISED shape; tables created
*afterwards* by running migrations locally carry THIS host's — and this host has
`explicit_defaults_for_timestamp = ON`, so they come out clean. `notification_actions` (created
`2026_08_04_140000`) and `finance_ledger_transactions.posted_at` (added `2026_08_09_120000`) both
post-date the copy, which is exactly why they read clean on it and dirty on production. **A copy is
not a witness for any table younger than the copy** — and the divergence is invisible, because both
schemas answer the same query without complaint.

**WHICH DATABASES HAVE BEEN READ.** An earlier draft of this line said "nothing in the schema carries
the attribute today", which sounds general and was only ever true of one schema; the query below
names a single `TABLE_SCHEMA` and the first completeness reading inherited that limit silently.
Verified **2026-08-13**, after the widened migration:

| Database | Before | After |
|---|---|---|
| **production** (read by the project lead, not by an agent) | **3 columns** carrying the clause | not run — the lead's to run |
| `portaa10_portal` (production COPY, the default connection) | 1 column carrying the clause | **0** |
| `brookstone_portal_db` (the dev database CLAUDE.md names for driving flows) | 1 column carrying the clause | **0** |
| `portal_testing`, `portal_drive` | 0 — built on this host with the setting ON | 0 |

**Two companion tickets exist on the unmerged branch `fix/sql-clock-lint-v2` and are named without
links, because on this base the paths do not resolve.** `server-settings-the-code-cannot-see.md`
holds the class ("server settings the code cannot see"), of which this is member 2, and enumerates
the same three declarations; `notice-end-destroys-starts-at.md` records the one member that went
live. **This file is the rebuild-side companion to those, not a restatement.** What is recorded here
is what a *fixer* needs and what that enumeration did not establish: how the automatic assignment is
decided (positionally, and it survives `ADD COLUMN … AFTER`), why the obvious remediation `ALTER` is
a no-op on exactly the hosts that need it, and the corrected state of one of the three. When that
branch merges, the two corrections under "The three declarations" belong in its table, and these
names become links.

## The general point, and why no gate can carry it

**The attribute exists in the SCHEMA and not in the SOURCE.** A migration reads clean while the
column it created does not, so no source-reading lint can ever see it — `bin/quality` reads source
(ADR 0053). The only instrument that observes it is a query against a live database:

```sql
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_DEFAULT, EXTRA
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND EXTRA LIKE '%on update CURRENT_TIMESTAMP%';
```

On `portaa10_portal`, 2026-08-13: **1 row before** the fix branch (`notices.starts_at`), **0 rows
after**. On **production**, same date, same query: **3 rows before** — see the correction above. Note
what an empty result does *not* mean — run here it is this machine's answer, on databases built with
the setting ON, and it says nothing about a host where it is OFF, nor about a table created after the
copy was taken.

## The rule, measured rather than quoted

With `explicit_defaults_for_timestamp = OFF`, MySQL assigns `DEFAULT CURRENT_TIMESTAMP ON UPDATE
CURRENT_TIMESTAMP` to the **first `TIMESTAMP` column of a table** when that column declares neither
attribute and is not declared `NULL`. Two consequences that are easy to get wrong, both measured on
MySQL 8.0.43 with the session variable forced OFF, on scratch tables dropped afterwards:

**1. "First" is POSITIONAL, and `ADD COLUMN … AFTER` can make a new column first.** A table already
carrying `created_at`/`updated_at` is not immune:

```
CREATE TABLE (…, narration VARCHAR, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)
    created_at                                  pos=3  default=NULL               extra=''
ALTER TABLE … ADD COLUMN posted_at TIMESTAMP NOT NULL AFTER narration
    posted_at                                   pos=3  default='CURRENT_TIMESTAMP' extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'
    created_at                                  pos=4  default=NULL               extra=''
```

The added column landed at ordinal 3, ahead of `created_at`, and took the attribute. This is the
fact that makes `finance_ledger_transactions.posted_at` (ordinal **11**, `created_at` at **13**, on
`portaa10_portal`) a genuine member rather than an assumed one.

**2. The obvious remediation `ALTER` IS A NO-OP on exactly the hosts that have the defect**, and the
obvious repair of that is also a no-op. Every line below was measured on MySQL 8.0.43 with the
session setting **forced OFF by the harness** — on this host it is ON, so the hostile condition does
not exist until it is imposed and every formulation looks clean:

```text
MODIFY TIMESTAMP NOT NULL                            default='CURRENT_TIMESTAMP'   extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'   <- UNCHANGED. The trap.
MODIFY TIMESTAMP NOT NULL DEFAULT <server clock>     default='CURRENT_TIMESTAMP'   extra='DEFAULT_GENERATED'                               <- works, but a server-clock default
MODIFY TIMESTAMP NOT NULL DEFAULT '1970-01-02 …'     default='1970-01-02 00:00:01' extra=''                                                <- works, ALTER only
  …then ALTER COLUMN … DROP DEFAULT                  default=NULL                  extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'   <- RE-ADDS BOTH
SET SESSION explicit_defaults_for_timestamp = ON, then bare MODIFY
                                                     default=NULL                  extra=''                                                <- cleanest, needs a PRIVILEGE
```

`MODIFY … TIMESTAMP NOT NULL` alone re-declares a first `TIMESTAMP` column with neither attribute, so
the server re-applies both. Declaring *any* explicit `DEFAULT` suppresses the `ON UPDATE` half.

**The fourth line is the one worth carrying away.** "Set an explicit default to suppress the implicit
attributes, then drop the default you did not want" is the obvious way to reach a clean column using
only `ALTER`, and it does not work: `DROP DEFAULT` leaves the column declaring neither attribute
again and an OFF server immediately re-applies both. **On an OFF host there is no reachable state
that has neither the clause nor a default.** Keeping a default is the price of removing the clause
without a privilege.

**The fifth line is cleanest and is not available on production.** `SET SESSION
explicit_defaults_for_timestamp` requires the dynamic privilege `SESSION_VARIABLES_ADMIN`. The
production deploy user's grants were **read**, 2026-08-13, rather than assumed:

```text
GRANT USAGE ON *.* TO 'portaa10'@'localhost'
GRANT ALL PRIVILEGES ON `portaa10_portal`.* TO 'portaa10'@'localhost'
(plus ALL PRIVILEGES on ~17 dated backup schemas)
```

`USAGE` is the empty global grant, and dynamic privileges can only be granted at `*.*` — so that user
holds none of them, and a migration using `SET SESSION` would be **refused**, exiting `migrate`
non-zero mid-release. Schema-level `ALL PRIVILEGES` includes `ALTER`. **So the rule for a fixer is:
the migration must need nothing but `ALTER`, which leaves the third line and its sentinel.** Using
the privilege in a *test harness* to create the hostile condition locally is fine; the two `SET
SESSION` calls look identical and only one of them is acceptable.

Where the sentinel default is undesirable, note that a server-clock default (line 2) lands in the
session zone, a different frame from every timestamp the application writes — see
[`stored-epoch-offset.md`](stored-epoch-offset.md); the sql-clock lint on `fix/sql-clock-lint-v2`
refuses that shape specifically.

`database/migrations/2026_08_13_100000_timestamp_columns_drop_implicit_on_update.php` is the worked
example — three columns, one formulation. It verifies `EXTRA`, `IS_NULLABLE` and `COLUMN_DEFAULT`
from `information_schema` after **each** `ALTER` and throws if any is wrong, because `ALTER` exits 0
either way (ADR 0052 — verify by shape, not by exit code); the throw leaves the migration unrecorded
rather than recording a meaningless green. It also documents why the sentinel is harmless per column
— every writer supplies its column, and all three stay `NOT NULL` — and what it costs, which is that
a future writer forgetting the column gets a row dated 1970 instead of a loud `1364`.

**It guards on the COLUMN, not only on the table, and that guard was earned.** With a table-only
guard the run died on `brookstone_portal_db` with `1054 Unknown column 'posted_at'` — that database
HAS `finance_ledger_transactions` and has not yet run the migration that adds `posted_at` — after the
`notices` ALTER had already committed. Half applied, and unrecorded. On a correctly-ordered
from-zero run neither guard fires; both exist for the environment that is mid-catch-up, which is
exactly where a release breaks.

**A shape that a hostile host can restore is a second, unadvertised benefit of the sentinel, and it
was measured on the way past.** `CREATE TABLE … LIKE` of a *clean* `timestamp NOT NULL` column is
REFUSED on a session with `explicit_defaults_for_timestamp = OFF` — `1067 Invalid default value` —
while the sentinel shape copies without complaint. The shape this host produces naturally is the one
an OFF host cannot reproduce.

## The three declarations, and their state on 2026-08-13

Derived from source, since it is a property of the migration rather than of any schema
(`grep` over `database/migrations/` for `->timestamp(`/`->timestampTz(`, excluding `nullable()`,
`default(`, `useCurrent*` and the `timestamps()` helpers), then each checked against
`information_schema` on `portaa10_portal`.

**The "live column today" column below was rewritten 2026-08-13**: it previously gave the COPY's
reading for #1 and #2 (`default=NULL, extra=''`, i.e. clean) as though it were production's. It was
not — see the correction at the top of this file.

| # | Declaration | Migration | On the COPY `portaa10_portal` | On PRODUCTION, read 2026-08-13 | On a rebuild under OFF |
|---|---|---|---|---|---|
| 1 | `$table->timestampTz('expires_at')` | `2026_08_04_140000_create_notification_actions.php:54` | `notification_actions.expires_at`, ordinal **8**, the table's **first** `TIMESTAMP` column. `default=NULL, extra=''` — clean, because the table post-dates the copy | **DIRTY** — `default=CURRENT_TIMESTAMP`, `on update CURRENT_TIMESTAMP` | acquires the clause |
| 2 | `$table->timestamp('posted_at')->after('narration')` | `2026_08_09_120000_finance_capture_columns_s2_s3.php:74` | `finance_ledger_transactions.posted_at`, ordinal **11**, ahead of `created_at` (**13**). `default=NULL, extra=''` — clean, same reason | **DIRTY** — `default=CURRENT_TIMESTAMP`, `on update CURRENT_TIMESTAMP` | acquires the clause — positionally first, per the probe above |
| 3 | `$table->timestampTz('registration_deadline')` | `2026_04_26_120713_create_curricula_table.php:21` | **the column does not exist** | not read; the column is dropped by a later migration | acquires the clause, then is dropped minutes later — see below |

**#1 and #2 are now FIXED BY THE SAME MIGRATION as `notices.starts_at`** — three statements, one
formulation, one sentinel. They are not alike and the migration records why each is there: #1 is live
and consequential, #2 is benign today and fixed because its only margin is a trigger rather than the
schema.

**#3 is weaker than "latent" and should stop being counted with the other two.**
`2026_05_06_111742_update_terms_and_curricula_dates_table.php:42` drops `registration_deadline` (and
`result_visible_at`) from `curricula`; the column is absent from `portaa10_portal`, and `curricula`'s
first `TIMESTAMP` column there is `created_at` at ordinal 11. On a from-zero rebuild under OFF the
column would be created dirty and dropped by a later migration in the same run, with no application
row updated in between. It is a vulnerable *declaration* with no reachable consequence. Recorded
rather than dropped from the list, because the declaration is still there and a future migration
that re-adds the column would inherit the shape.

**#2 remains benign for reasons that are not luck, and they are set out in full under "The finance
declaration" in `server-settings-the-code-cannot-see.md` (unmerged, as above)** — the table is
append-only (named `no_update`/`no_delete` triggers, SQLSTATE 45000), so `ON UPDATE`
is structurally unreachable, and both writers supply `posted_at` explicitly, so the `DEFAULT` never
fires. What this ticket adds is that its membership is now *established* rather than assumed.

**#1 is the one with no second line of defence, and on production it has been firing.**
`notification_actions` has no immutability trigger, and the row is updated by design — it is a
claim/relay record whose `status`, `outcome` and `resolved_by` move after insert. All three writes
live in `NotificationActionResolver` and **none of them sets `expires_at`**: the claim
(`app/Notifications/Services/NotificationActionResolver.php:60-68`), the expiry settlement
(`:102-108`), and the two relay outcomes (`:127-143`). So on production every state change rewrote
the expiry to the server clock.

**What that costs, established rather than assumed.** The claim's own guard is intact: MySQL
evaluates `WHERE … expires_at > ?` against the pre-update row, so the exactly-once decision the
docblock calls "the entire concurrency design" is correct. The value it decided on is then destroyed
by the same statement. **Nothing reads `expires_at` after the first claim today** — searched, and the
only other reader is `NotificationAction::isClaimable()`
(`app/Notifications/Models/NotificationAction.php:68-72`), which has **no production caller** (tests
only); `NotificationFeedResource` does not expose it and neither does the tap endpoint's JSON. So the
loss is currently unobserved. It is not bounded going forward: the **reconciliation pass the
resolver's docblock anticipates** for `RESOLVING`/`UNCONFIRMED` rows would be the first post-claim
reader, and on production it would find, on every already-resolved row, an `expires_at` equal to the
moment of the last write rather than the window that was offered. A settled-as-EXPIRED row is
additionally left claiming it expired at the tap that discovered the expiry, not at its real
deadline.

## When this stops being latent

Any of: a **new environment**, a **restore into a fresh database**, a **second deployment**, or a
host whose `explicit_defaults_for_timestamp` is OFF. It is not triggered by deploying code — the
existing columns keep whatever shape they were created with — which is precisely why it can sit
here unnoticed.

~~**No fix is proposed for #1–#3 in this ticket**, deliberately: on every database this project can
observe, all three are clean, and a migration that "fixes" a clean column would leave it carrying a
`DEFAULT CURRENT_TIMESTAMP` it does not have today for a hazard that has not materialised.~~

**SUPERSEDED 2026-08-13.** The premise of that paragraph — "on every database this project can
observe, all three are clean" — was the copy's answer, not production's. Production carries the
clause on all three, so #1 and #2 are fixed now, by the same migration and in the same commit.
`registration_deadline` (#3) is not fixed because the column does not exist to fix. **The reasoning
that survives** is why the shape it leaves behind is acceptable: the sentinel default is a shape the
column did not previously have, and the migration argues that cost per column rather than assuming
it.

**One consequence of fixing a column that is clean HERE**, stated because it is the price of the
decision: on this host `notification_actions.expires_at` and `finance_ledger_transactions.posted_at`
were clean, and after the migration they carry `DEFAULT '1970-01-02 00:00:01'`. That is convergence
rather than damage — a migrated-from-zero database and an ALTERed one now reach the *same* shape,
which is what `bin/quality-clean-db` compares across — but it does mean a writer that omits one of
those columns now gets a 1970 row instead of a loud `1364`.

## Related

- `server-settings-the-code-cannot-see.md` — the class (member 2), the exhaustive schema reading,
  and why the three explicit `->useCurrent()` columns are a *different* three. **On
  `fix/sql-clock-lint-v2`, not on this base.**
- `notice-end-destroys-starts-at.md` — the one member that went live, fixed by the migration this
  ticket ships alongside. **Its derived half is now observed**: an arm that imposes the clause and
  calls the route watched `starts_at` move by 19,323,776 s. **On `fix/sql-clock-lint-v2`, not on this
  base** — the status and the two claims corrected there are listed in
  `docs/handoff/reports/fix-notices-starts-at-server-clock.md` under "Not done".
- [`stored-epoch-offset.md`](stored-epoch-offset.md) — member 1 of the same class, the session
  time zone.
- `docs/adr/0052-a-migration-is-a-dated-act.md` — verify by shape, not by exit code.
