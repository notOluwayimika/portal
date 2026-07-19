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

**Baselines only shrink, and every ratchet ENFORCES it** — each exits non-zero both
when the count/set gets *worse* (a regression) and when it gets *better* without the
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
  so a fresh checkout has neither and `tsc` then measures a *different, smaller*
  codebase (missing-module errors replace the real ones). CI runs
  `php artisan wayfinder:generate` before `types:check` for exactly this reason —
  and locally you must regenerate before trusting a count, or you will compare a
  stale tree against the baseline and see a phantom regression. That is precisely
  what happened once: a "+2 regression" turned out to be stale generated files, and
  the true count was *below* the floor.

Lint (Pint/Prettier/ESLint) runs in **check mode on changed files only**
(`bin/lint-changed.sh`): new and modified code must be clean; the legacy drift is
grandfathered and burns down as files are touched.

The full gate list (including the commented-authz lint, boundary lint,
architecture tests and Larastan) and every baseline's mechanics live in
[CONTRIBUTING.md](../CONTRIBUTING.md).
