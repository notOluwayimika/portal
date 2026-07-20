# CLAUDE.md — repository conventions for AI-assisted work

Read [CONTRIBUTING.md](CONTRIBUTING.md) first: the 16-rule Architecture
Constitution there is enforced by CI, not by review. The pointers below are the
operational facts an agent needs most often.

## Non-negotiables you will hit early

- **Isolation:** `school_id` is the only boundary. School-owned models use
  `BelongsToSchool` (global `SchoolScope`). `super_admin` bypasses
  *authorization*, never *isolation* (ADR 0036).
- **Context:** on request, `App\Support\ActiveSchool::id()` / `getOrFail()`;
  off-request (jobs/commands), **only** `ActiveSchool::runFor()` — jobs carry
  `public readonly int $schoolId` + the `SchoolAware` middleware. Never
  `auth()->setUser($causer)` for context; never default from `users.school_id`
  (Constitution 13; the remaining legacy fallbacks are baselined with expiries —
  ADR 0042).
- **Money:** `App\Support\Money` (integer minor units + ISO-4217 currency) via
  `App\Casts\MoneyCast`. Never float, never `decimal:` on a money column. Wire
  shape is `{"amount_minor": <int>, "currency": "NGN"}`; columns are
  `{name}_minor` + `{name}_currency` (ADRs 0002/0037/0038/0039). No
  rounding-bearing operation exists until the accounting policy is signed.
- **Authorization is never commented out** (rule 15) — the authz lint fails CI
  on a new commented check.

## Testing & verification

- Suite runs on **MySQL**: `DB_DATABASE=portal_testing ./vendor/bin/pest`.
  SQLite does not work (INFORMATION_SCHEMA migrations).
- CI is ratcheted: pre-existing failures are frozen in
  `tests/ratchet-baseline.txt`; check regressions with
  `./vendor/bin/pest --log-junit junit.xml && php bin/ci-test-ratchet.php junit.xml`.
- Gates to run locally before pushing: `./vendor/bin/pint --test`,
  `php bin/ci-authz-lint.php`, `php bin/ci-boundary-lint.php`,
  `./vendor/bin/pest --group=arch`, `composer analyse`.
- Tests alone are not verification — migrate the dev DB and drive the affected
  flows in the running app.

## Workflow

Slice branches off `staging` → PR → `staging` (CI + manual validation) →
milestone merge to `main`. Never stack branches. Conventional Commits with
scope. Rollout flags in `config/rbac.php` / `config/auth.php` ship dark.

### ⚠️ INTERIM (from 2026-07-20): local quality is the gate

**GitHub Actions has never executed a job on this repo — the account is
billing-locked** (every run: *"The job was not started because your account is
locked due to a billing issue"*, `steps=0`). CI has therefore neither passed nor
failed. The checks are also not required status checks (PRs #56/#57/#58 merged
over red), so nothing has ever blocked a merge.

Until that is fixed, **`bin/quality` is the gate** — it mirrors
`.github/workflows/{lint,tests}.yml` step for step, so local green means what CI
green would mean. It is enforced by a committed **pre-push hook**
(`.githooks/pre-push`, wired via `core.hooksPath`, installed automatically by
`composer install`). Push only on green.

*Honest limits:* the hook guards against **forgetting**, not intent — `--no-verify`
bypasses it by design, so a bypass is a recorded decision rather than a routine.
And it gates on your **working tree**, not on the commits being pushed.

**EXIT TRIGGER — this stopgap has a named end condition; it is not the new normal:**

1. Billing resolved → Actions can run.
2. Trigger the first-ever real CI run. **Treat its result as never-before-seen** —
   root-cause anything red *then*; local greens do not carry, because local and CI
   have already been proven able to measure different trees (the tsc/wayfinder bug).
3. Make `linter` and `tests` **required status checks** on `main` and `staging`
   (needs a repo admin — the current token has push, not admin).
4. Revert to CI-gating. `bin/quality` stays as fast local feedback; it stops being
   the floor. Delete this section then.

*Note on the current push-to-`main` decision:* it trades away the
`off staging → PR → staging` record. An alternative that keeps history without
losing speed is PR-to-staging gated on local `bin/quality`, then promote
staging → main. Flagged, not decided.

## Where things live

- Shared Kernel primitives: `app/Support/`, `app/Casts/`, `app/Concerns/`
- Module shape (future `app/Finance/` etc.): [docs/module-blueprint.md](docs/module-blueprint.md)
- Decisions: [docs/adr/](docs/adr/README.md) · Delivery status: [docs/roadmap.md](docs/roadmap.md)
