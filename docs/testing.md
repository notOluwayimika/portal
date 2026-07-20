# Testing environment

The automated test suite runs on **MySQL** (not SQLite): several migrations use
`INFORMATION_SCHEMA`, which SQLite does not provide, so the suite cannot run on
the old in-memory SQLite config.

## One-time local setup

1. Create a dedicated test database (the name **must** contain `test` — a guard
   in `Tests\TestCase` refuses to run against anything else, so a live database
   can never be truncated by `RefreshDatabase`):

    ```sql
    CREATE DATABASE portal_testing;
    ```

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

### Accepted permanent residuals

The PHP version matrix (CI matrixed 8.3/8.4/8.5; only your local PHP runs), a true
clean-room OS/environment, and any remote enforcement (no required status checks;
`--no-verify` bypasses; a clone without `composer install` has no hook). These are
known and accepted, not hidden.

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

#### The three ways the tsc count has lied here

All three are real incidents, not hypotheticals. **Regenerate, then compare the SET,
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
