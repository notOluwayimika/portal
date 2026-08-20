# CLAUDE.md — repository conventions for AI-assisted work

Read [CONTRIBUTING.md](CONTRIBUTING.md) first: the 16-rule Architecture
Constitution there is enforced by CI, not by review. The pointers below are the
operational facts an agent needs most often.

## Non-negotiables you will hit early

- **Isolation:** `school_id` is the only boundary. School-owned models use
  `BelongsToSchool` (global `SchoolScope`). `super_admin` bypasses
  _authorization_, never _isolation_ (ADR 0036).
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
- **Pint is invoked through `bin/lint-changed.sh`, never directly against a
  path.** `pint <directory>` reformats every file under it — correctly, and as
  noise: it has twice swept unrelated files into a commit (#223, and 71 files on
  `feat/finance-bank-accounts` where the change was 18). Until
  `bin/lint-changed.sh` can see uncommitted work
  ([ticket](docs/handoff/tickets/lint-changed-cannot-see-uncommitted-work.md)),
  pass explicit files **and guard against an empty list** — `pint` with no path
  argument lints the WHOLE PROJECT, which is how the same mistake recurred a
  third time on this very branch, from a substitution that expanded to nothing:

  ```bash
  files=$(git diff --name-only HEAD -- '*.php')
  [ -n "$files" ] && ./vendor/bin/pint $files
  ```

  And read `git diff --stat` against your own model of the change before
  pushing — no gate objects to a commit full of correct formatting, and none
  should.
- Tests alone are not verification — migrate the dev DB and drive the affected
  flows in the running app.
- **Spatie `sync*` is non-atomic and its events fire POST-write** (vendor-read
  7.4.1; paid for twice — C5 roles, C6 permissions). Wrap every role/permission
  sync in `DB::transaction`; the un-wrapped failure mode is detach-persisted,
  attach-never-ran (a user/role stripped bare by the edit meant to adjust it).
  For bite-proofs, the **detach-side event is the between-halves injection
  point** (`RoleDetachedEvent`; `PermissionDetachedEvent` on a revoke-then-give
  path) — the attach event fires after the write and produces a false green.
  And `HasPermissions::syncPermissions` detaches RAW (no event): its removals
  are invisible to the rbac audit listener — use diff-based revoke+give
  instead. Full write-up: `docs/handoff/c6-brief.md`.
- **Migration `down()` four-path audits in parallel work: re-derive the rollback
  depth per run and assert _your_ migration reverted.** `--step=N` counts from the
  branch's latest migrations, so the _other_ stream's migration can sit on top of
  yours — `--step=1` then rolls back theirs and the audit passes testing nothing of
  yours (bit once, 2026-07-21). Find your migration in `migrate:status`, roll back to
  it, and assert your column/table is gone — never trust a bare exit-0. Same class as
  the corrupt-`node_modules` tsc lie. Full write-up: `docs/testing.md` §
  "`--step=N` is relative to the branch".

## Workflow

Slice branches off `staging` → PR → `staging` (`bin/quality` via the
`.githooks/pre-push` hook, plus maintainer review — there is no CI, permanently;
ADR 0053) → milestone merge to `main`. Never stack branches. Conventional
Commits with scope. Rollout flags in `config/rbac.php` / `config/auth.php` ship
dark.

**A merged pull request is not evidence that the branch merged.** After any merge, run
`bin/landed <branch>` — target defaults to `staging`. It fetches origin (its only
mutation) and answers, from refs alone, whether `origin/<target>` contains every
commit on `origin/<branch>` and whether the merge took the head `origin/<branch>` now
points at. Nothing else answers that: PR #265 reported merged while two commits — the
only behavioural change on the branch among them — stayed outside `staging`, and the
local merge that closed the gap then sat unpushed while `git status` announced it to
nobody. Exit 0 landed · 1 a check failed · 2 could not determine, and a failed fetch
is 2, never 0. A green proves the remote contains what was reviewed; it proves nothing
about whether the merge was correct or the merged tree passes the gate. It is
deliberately **not** in `bin/quality` — that floor is offline, and the failure happens
after a merge, which the per-push hook never observes.

**Branch names carry a Conventional-Commits type prefix**, so the branch says what
kind of change it is before anyone opens it — same vocabulary as the commits:
`feat/` · `fix/` · `chore/` · `docs/` · `ci/` · `refactor/` · `test/` · `perf/`

```text
feat/slice-2-multi-line-invoicing      ci/enforcement-floor
fix/promoted-to-wrong-entity           docs/branch-naming-convention
```

Use the type of the branch's _primary_ change; a slice that ships a feature plus
its docs is `feat/`. Prefer `feat/` over the older `feature/` for new branches.

This is the established pattern, not a new rule: essentially every branch in the
repo already carries a prefix. The unprefixed exceptions
(`slice-2-multi-line-invoicing`, `slice-i-enrollment-school-id`,
`ci-enforcement-floor`) are recent deviations, not precedent — don't copy them.

### The enforcement floor is LOCAL, permanently

**GitHub Actions is intentionally disabled** — the account is billing-locked and
billing is not being pursued. Actions has never executed a single job here (every
run: _"The job was not started because your account is locked due to a billing
issue"_, `steps=0`), so CI has neither passed nor failed. This is a **decision, not
a pending fix**: `bin/quality` is the intended, permanent enforcement floor. Do not
read this as something to "restore CI" to solve. If Actions is ever revisited that
is a fresh decision, not a trigger waiting to fire.

**Day-to-day (every push):** `.githooks/pre-push` runs `bin/quality` — wayfinder
generate, changed-files Pint/Prettier/ESLint, tsc ratchet, four lints, arch,
Larastan, suite + failure ratchet. Committed hook, wired via `core.hooksPath`,
installed by `composer install`.

**Releases (`staging → main`):** `bin/quality-promote`, run **on staging's HEAD
before the merge** (verify-then-promote). It is heavier on purpose: release-scoped
lint (everything staging adds over main) plus `bin/quality-clean-db` — a throwaway
database, migrate-from-zero, data planted, and rollback/re-up reversibility. It
stamps the verified SHA and the pre-push hook **refuses a push to `main`** without a
stamp matching that exact commit.

**The merge into `main` must FAST-FORWARD** — `git merge --ff-only staging`. The
stamp names one exact SHA, so a merge commit (`--no-ff`, or a real merge because
`main` has drifted) is by definition a commit the gate never verified, and the push
is refused. `--ff-only` fails loudly in that case, which is the signal that `main`
has diverged and needs a human.

**What this floor CANNOT prove — accepted, permanent residuals:**

| Gap                    | Why it stays                                                                                                                           |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| **PHP version matrix** | CI matrixed 8.3/8.4/8.5; only your local PHP is exercised. Reproducing that locally is real infrastructure, deliberately out of scope. |
| **Clean-room OS/env**  | Runs on your machine, your extensions, your MySQL. A dependency or extension you have and a teammate lacks is invisible.               |
| **Remote enforcement** | No required status checks. `--no-verify` bypasses, and a push from a clone without `composer install` has no hook at all.              |
| **Intent**             | The hook stops forgetting, not deliberate bypass.                                                                                      |
| **Determinism**        | Byte-identical code has produced both PASS 14/14 and FAIL 23 (ADR 0053, 2026-08-09). Cause investigated, not found — one failure in twelve runs. **A red cannot be told from a flake by looking at it, and retrying until green is indistinguishable from fixing.** Suite artefacts are now kept per run (last 20) so the next one is diagnosable; a red prints their paths. |

Everything else CI would have checked is covered locally, including the database
dimension CI itself never covered (CI migrated an _empty_ service DB, so incremental
migration against real data was never exercised anywhere until `bin/quality-clean-db`).

## Where things live

- Shared Kernel primitives: `app/Support/`, `app/Casts/`, `app/Concerns/`
- Module shape (future `app/Finance/` etc.): [docs/module-blueprint.md](docs/module-blueprint.md)
- Decisions: [docs/adr/](docs/adr/README.md) · Delivery status: [docs/roadmap.md](docs/roadmap.md)
