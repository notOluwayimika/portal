# Testing environment

The automated test suite runs on **MySQL** (not SQLite): several migrations use
`INFORMATION_SCHEMA`, which SQLite does not provide, so the suite cannot run on
the old in-memory SQLite config.

## One-time local setup

1. Create a dedicated test database (the name **must** contain `test` — a guard
   in `Tests\TestCase` refuses to run against anything else, so a live database
   can never be truncated by `RefreshDatabase`):

    ```sql
    CREATE DATABASE portal_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ```

    **The `COLLATE` is load-bearing, not cosmetic.** Laravel creates columns as
    `utf8mb4_unicode_ci` (the connection collation), but a trigger's `DECLARE` variable
    inherits the _database_ default. Create the DB with the MySQL-8 server default
    (`utf8mb4_0900_ai_ci`) and every trigger comparing a variable to a string column
    raises `1267 "Illegal mix of collations"` on _every_ write — a silent, total outage
    of the trigger-based enforcement floor, invisible to any test without triggers. The
    variable's collation is **frozen at trigger creation**, so the DB default must be
    canonical _before_ migrations run; recreating the DB is the fix, an `ALTER DATABASE`
    afterwards is not (it leaves already-created triggers frozen wrong).
    `SchemaConventionsTest` asserts both the canonical column collation and the DB
    default, so a mis-created DB is a red test, not a prod incident.

2. `.env.example` is the template for `.env` (`cp .env.example .env` then
   `php artisan key:generate`). It ships with **no secrets** — fill in your own
   local values. `phpunit.xml` pins `DB_DATABASE=portal_testing` and inherits the
   host/user/password from your environment, so your `.env` credentials are used
   against the test database only.

## Running the suite

```bash
php artisan migrate --force        # against portal_testing (run once / after new migrations)
./vendor/bin/pest                  # runs on MySQL via phpunit.xml
```

## Redis

Tests themselves do **not** require Redis (they use the `array` cache and `sync`
queue). Redis is provisioned as a CI service for forthcoming queue/Horizon work;
it is not needed to run the suite locally today.

## CI ratchets

CI is a real gate even though the suite and type-check are not yet fully clean.
Two ratchets fail CI only on **regressions**, not on the pre-existing backlog:

## The quality gate is LOCAL and permanent

**GitHub Actions is intentionally disabled** (billing-locked, not being pursued). It
has never executed a job here — every run is annotated _"The job was not started
because your account is locked due to a billing issue"_, `steps=0`, ~4s — so CI has
neither passed nor failed. `bin/quality` is the intended floor, not a stopgap.

```bash
bin/quality              # every push (enforced by .githooks/pre-push)
bin/quality-clean-db     # throwaway DB: migrate-from-zero, data, rollback/re-up
bin/quality-promote      # RELEASE gate for staging -> main (runs both of the above)
```

**Keep `bin/quality` and `.github/workflows/{lint,tests}.yml` in lockstep** even
though the workflows do not run: they remain the specification of what the gate
means, and a fork between them re-creates the bug that started the tsc saga, where
CI and a developer measured different trees and _both_ reported green.

### What the clean-DB run covers that nothing else did

Laravel's `RefreshDatabase` already calls `migrate:fresh`, so the ordinary suite
**does** rebuild every table from migrations each run — "tests run against your
stale DB" was never true. The real gap was elsewhere, and CI did not cover it
either: CI ran `migrate --force` against an **empty** service DB, so the
**incremental migration path against populated data** was exercised nowhere.

Slice (i) is the worked example: its backfill (`UPDATE student_curricula JOIN
students …`) touches zero rows on an empty database and its composite FKs validate
zero rows — every data-migration risk it carries is invisible to `migrate:fresh`.
`bin/quality-clean-db` provisions a throwaway DB, migrates from zero, **plants
representative rows**, then rolls back and re-migrates — which is also the
four-path `down()`/re-up() check for the found-once hazard where MySQL leaves a
FK's backing index behind and `up()` cannot reapply.

It never touches your working database or `APP_KEY`: it creates its own DB (the
name must contain `test`, or `Tests\TestCase` fails closed) and generates its own
PHPUnit config, because `phpunit.xml` pins `DB_DATABASE` via `<env>` and that pin
beats a shell variable.

Both gates are **bite-proven in both directions** (2026-07-20): a migration whose
`down()` left a state `up()` could not reapply was watched pass the ordinary suite
(`migrate:fresh` never rolls back, so it is structurally blind) and fail
`bin/quality-clean-db`; removing it returned the gate to green. The release gate was
watched block a push to `main` with no stamp _and_ with a stamp for a different
commit, and allow one only on an exact SHA match.

That exercise also fixed the reach of the reversibility check: it is exactly
`--step` migrations deep, and a defect can be **masked by a deeper migration's
`down()`**. The first planted defect — an index on `student_curricula.school_id` —
did _not_ fail the gate, because `130000`'s `down()` drops that column and took the
orphaned index with it. Green here means "the last N migrations are reversible
against data", not "all of them are"; raise `STEPS` when a release adds more than
three migrations.

#### ⚠️ `--step=N` is relative to the branch, not to YOUR migration (parallel-work trap)

A step-based rollback counts back from the **latest** migrations on the branch. In
parallel work the _other_ stream's migrations can sit on top of yours, so `--step=1`
rolls back **their** migration and your four-path audit **passes while testing nothing
of yours.**

This is not hypothetical — it happened on 2026-07-21. A Finance four-path run at
`--step=1` rolled back the RBAC stream's later
`2026_07_21_210000_subject_result_maker_checker_separation`, not the Finance migration
under test. It was caught **only because the probe asserted the specific column was
actually gone** (it wasn't) — not because the rollback exited 0. Re-run at the correct
depth (`--step=2`), it verified properly.

Two rules, both mandatory for a `down()` audit:

1. **Re-derive the depth per run.** Find _your_ migration in `php artisan
migrate:status` and roll back exactly to it. **Never assume "the last migration"
   is yours** — the parallel stream may have moved staging under you since you branched.
2. **Assert the RIGHT migration reverted.** After rollback, assert _your_ column/table
   is actually gone — not merely that `migrate:rollback` exited 0. **"A rollback
   happened" ≠ "my rollback happened."** That assertion is the only thing that caught
   the false pass; make it standard, never optional.

This is the migration-audit sibling of the **corrupt-`node_modules` tsc lie** (§ "the
four ways the tsc count has lied"): both are a check silently testing the **wrong
thing** because the parallel stream changed the ground under it. This one is the more
dangerous of the two — a false-passing four-path audit gives false confidence on the
**least-reversible** class of change there is.

### Gate audit — every gate bite-proven (2026-07-20)

Three gates had already been caught reporting protection they were not providing
(Actions that never ran; the tsc baseline calibrated against a mis-generated tree; the
authz-lint's warn-only shrink plus a dedup bypass). Three of one failure mode is a
class, so every gate in the floor was audited **empirically** — planting the regression
it exists to catch and watching the exit code. Reading a script and concluding "it
looks like it exits 1" is not the audit; two of the three defects looked fine and
`exit 0`'d in practice.

| gate                                | clean | new violation     | stale baseline entry         |
| ----------------------------------- | ----- | ----------------- | ---------------------------- |
| lint-changed (Pint/Prettier/ESLint) | 0     | **1**             | n/a (no baseline)            |
| tsc ratchet                         | 0     | **1** (146 > 145) | **1** (decrease detected)    |
| authz-lint                          | 0     | **1**             | **1**                        |
| boundary-lint                       | 0     | **1**             | **1** _(was 0 — fixed)_      |
| runtime-zero-lint                   | 0     | **1**             | **1** _(was 0 — fixed)_      |
| identifier-generation-lint          | 0     | **1**             | **1** _(was absent — added)_ |
| architecture tests                  | 0     | **1**             | n/a                          |
| Larastan                            | 0     | **1**             | n/a (baseline is per-error)  |
| test ratchet                        | 0     | **1**             | **1**                        |
| wayfinder generate                  | 0     | **1** on failure  | n/a                          |

**Three more defective gates were found — the fourth, fifth and sixth of the class.**
`boundary-lint` and `runtime-zero-lint` printed _"baselined exception(s) removed
(good)"_ and **exited 0**; `identifier-generation-lint` had no stale-entry handling at
all and reported _"OK"_ while ignoring one. All three now `exit 1`, bite-proven in both
directions, with **detection scope unchanged** — the fix is enforcement, never coverage.

Baselines verified at their true counts by regenerating and diffing: boundary 20,
runtime-zero 12, identifier-generation 0, authz 2, tsc 145, tests 13.

**Two process lessons worth more than the fixes:**

1. A gate can be **wrong in its own docblock**. `ci-boundary-lint` said _"the baseline
   may only shrink"_ while not enforcing it. Comments are not evidence.
2. **`git reset --hard` mid-audit destroyed uncommitted fixes**, and every proof taken
   before it was silently invalidated — the scripts on disk no longer contained what
   had been proven. It was caught only because the baseline-truth check regenerated a
   file and saw the _old_ behaviour return. Commit fixes before probing anything else.

### Accepted permanent residuals

The PHP version matrix (CI matrixed 8.3/8.4/8.5; only your local PHP runs), a true
clean-room OS/environment, and any remote enforcement (no required status checks;
`--no-verify` bypasses; a clone without `composer install` has no hook). These are
known and accepted, not hidden.

**Nothing in the gate renders a page.** The suite exercises HTTP/JSON and the database;
tsc and lint read source. No step mounts a React tree, so a page that type-checks and
lints can still throw at render and come up blank. The browser click-through before
promotion is the only gate that covers this, and it is not ceremony — on 2026-07-20 it
caught a blank login page that all ten checks had passed.

Two cheap substitutes were evaluated and **rejected on evidence**, so nobody re-proposes
them from intuition:

- **`pnpm run build`** (~3 s, so cost was not the objection) — _bite-proven not to
  work._ The broken `import { send } from '@/routes/verification'` was restored and the
  build **still exited 0**; the unresolved import did not fail it. It would not have
  caught this.
- **An SSR smoke render** of the top few routes _would_ catch it — that is exactly the
  layer the errors surfaced at — but it needs the SSR bundle built, a node process
  running, and a booted Laravel to render against. That is real infrastructure, not a
  step; worth doing only if this class recurs.

What actually closed this hole was correcting the generation flags above, which returns
the three failure modes to tsc's reach — `.form` errors become `TS2339`, a missing
generated module becomes `TS2307`. **Prefer fixing the measurement over adding a gate.**

---

**Baselines only shrink, and every ratchet ENFORCES it** — each exits non-zero both
when the count/set gets _worse_ (a regression) and when it gets _better_ without the
baseline being lowered (an improvement left unlocked leaves slack the next regression
can hide in). Bite-proven 2026-07-19: a planted regression was watched go red at
every gate, and the shrink-lock was watched fire on a simulated improvement.

- **Tests** — `tests/ratchet-baseline.txt` lists the known-failing tests. CI fails
  if a test outside the baseline fails, **and** if a baselined test starts passing
  (remove it). Regenerate: `./vendor/bin/pest --log-junit junit.xml || true` then
  `php bin/ci-test-ratchet.php junit.xml generate`.
- **Types** — `tsc-baseline` holds the current `tsc --noEmit` error count. CI fails
  if the count increases **or decreases** (lower the baseline to lock the win):
  `pnpm run types:check > tsc-output.txt 2>&1 || true` then
  `php bin/ci-tsc-ratchet.php tsc-output.txt generate`.

    ⚠️ **The count is only meaningful with wayfinder output present.**
    `resources/js/{routes,actions}` are generated from the PHP routes and gitignored,
    so a fresh checkout has neither and `tsc` then measures a _different, smaller_
    codebase (missing-module errors replace the real ones). CI runs
    `php artisan wayfinder:generate` before `types:check` for exactly this reason —
    and locally you must regenerate before trusting a count, or you will compare a
    stale tree against the baseline and see a phantom regression. That is precisely
    what happened once: a "+2 regression" turned out to be stale generated files, and
    the true count was _below_ the floor.

#### ⚠️ Generation flags are part of the measurement — `--with-form` is mandatory

`vite.config.ts` builds with `wayfinder({ formVariants: true })`. `bin/quality` and
`lint.yml` must therefore generate with **`--with-form`**, or they overwrite the dev
server's correct output with a build **the application cannot run**: every `.form`
helper vanishes, 11 files call `.form()`, and the login page renders blank.

This is not a subtle difference — it is a **different codebase**, and it is how a blank
login page sat inside a green ratchet for a whole release cycle:

1. `bin/quality` generated _without_ `--with-form`, then measured tsc against that.
2. tsc **did** report all 11 `.form` errors — it was never silent.
3. But the baseline (148) had been calibrated on that same wrong tree, so those 11
   errors **were the baseline**. The ratchet compared broken against broken and said OK.
4. Meanwhile the running dev server had correct files from the vite plugin — until the
   next `bin/quality` run overwrote them. Whoever generated last won.

Corrected 2026-07-20: both call sites now pass `--with-form`, and the baseline was
re-derived on the correct tree at **145** — a _shrink_, because removing 11 artifact
errors and fixing 4 real ones outweighed 12 generator errors the wrong tree had been
hiding. **If you ever change the wayfinder flags, re-derive the baseline in the same
commit**; a baseline is only meaningful relative to the generation that produced it.

The general rule: **the ratchet does not measure your code, it measures your code _as
generated_.** Any change to how generation is invoked silently redefines what "green"
means.

#### The four ways the tsc count has lied here

All four are real incidents, not hypotheticals. **Regenerate, then compare the SET,
not only the number.**

1. **Stale generated tree** — a count taken without `wayfinder:generate` measures a
   smaller codebase. Produced a phantom "+2 regression" whose true count was _below_
   the floor.
2. **Wrong carried baseline** — "143" was carried in conversation while the real
   baseline was 149. A number nobody re-derived.
3. **Generation-order artifact (net-zero count, churning set)** — during the
   `staging → main` reconciliation, six `routes/setup/studentCurricula` errors
   appeared on _both_ sides of the merge with their two type arguments merely
   **swapped** (`student` ↔ `studentCurriculum` in expected-vs-actual). Six left, six
   arrived, count unchanged. Wayfinder emits these from route ordering, so they churn
   without anything having changed.

The lesson generalises past wayfinder: **a matching count does not mean a matching
set.** Six fabricated errors could have replaced six fixed ones and the ratchet —
which counts — would have said OK. The tsc-150 diagnosis only resolved because the
_sets_ were diffed (normalised for line shifts and this swap), which is how the two
genuine `TS18046` regressions were isolated out of a 13-line raw delta. Diff the set
whenever the number moves, and whenever it suspiciously doesn't.

4. **Corrupt `node_modules` (count inflated, source untouched)** — a stale or
   partially-corrupt install reported **145** where the lockfile's true count is
   **122**, a phantom +23 that looks exactly like someone else's regression. The trap
   is the obvious remedy: **`pnpm install --frozen-lockfile` did NOT fix it** — pnpm
   considered the existing tree satisfied and left the corruption in place. Only
   `rm -rf node_modules && pnpm install --frozen-lockfile` restored the true count.
   Before believing a tsc gap you did not cause, **reinstall from scratch, not
   incrementally**.

Lint (Pint/Prettier/ESLint) runs in **check mode on changed files only**
(`bin/lint-changed.sh`): new and modified code must be clean; the legacy drift is
grandfathered and burns down as files are touched.

### Merges need BOTH lint scopes — neither alone covers one

The changed-files lint is relative to a base, so **the base decides what is invisible**.
On a reconciliation merge the two bases are complementary, and this was proven the hard
way on the first `staging → main`:

| base                                                       | lints                 | missed by the other |
| ---------------------------------------------------------- | --------------------- | ------------------- |
| `origin/main` (release scope, `bin/quality-promote`)       | what **staging** adds | 6 drifted PHP files |
| merge-base with `staging` (per-push, `.githooks/pre-push`) | what **main** adds    | 17 ESLint + 1 Pint  |

Release scope is **structurally blind to what `main` carries in**: those files are
unchanged relative to `origin/main`, so they are not in its diff at all. The per-push
gate is what caught them — it blocked the reconciliation branch, correctly.

This matters most on the **first** promotion after a long divergence, and it compounds
with a second fact: **`main` has never been gated.** It has no `tsc-baseline`, no
`bin/quality`, no pre-push hook, and Actions never executed a job. Every file it carries
is arriving unlinted and untyped for the first time — which is exactly where the two
`TS18046` errors came from. Expect accumulated drift on that first pass and budget for
it; it is not a regression the merge introduced.

The full gate list (including the commented-authz lint, boundary lint,
architecture tests and Larastan) and every baseline's mechanics live in
[CONTRIBUTING.md](../CONTRIBUTING.md).
