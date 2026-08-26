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
  files=($(git diff --name-only HEAD -- '*.php'))
  [ ${#files[@]} -gt 0 ] && ./vendor/bin/pint "${files[@]}"
  ```

  **An ARRAY, not a string, and that is not style.** The scalar form
  `pint $files` depends on the shell word-splitting an unquoted parameter —
  which bash does and **zsh does not**. This project's shell is zsh, so the
  scalar form passes all N paths as ONE argument: pint reports
  `The path "a.php b.php c.php" is not readable` and lints nothing. It failed
  loudly here (2026-08-25), but it is the same class as the empty-list bug one
  line up — a substitution that does not expand to the arguments you think it
  does — and the next pint version that tolerates a joined path would fail
  SILENTLY, reporting success having formatted nothing. The array form is
  correct in both shells, so the guard covers the empty case and the shell
  difference at once. Fix the class, not the instance.

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
- **On a framework subclass, a generic private helper name collides with the base
  class's method surface — silently, and fatally.** `FormRequest` extends
  `Illuminate\Http\Request`, where `session()` is real and called internally, so a
  private `session(string, int)` did not override-error: it corrupted the framework's
  own call and PHP exited **2 with no output, no exception and no stack trace**. The
  identical helper is safe on a `Command`, which has no `session()` — which is exactly
  the trap, because it looks correct in the file you copied it from. Bisecting
  test-by-test was the only thing that found it. Prefix helpers on framework
  subclasses (`resolveSession`, not `session`) — the method-level form of the `sr_`/
  `rc_` fixture-namespacing discipline. Its cousin, same session: `ActiveSchool::
  getOrFail()` returns the **School model**, so `where('school_id', $model)` matches
  nothing while reading as correct — use `->id`, and `ActiveSchool::id()` when null is
  acceptable. Both are the reading-cannot-catch family: only running it finds them.
- **Re-arming a tripwire means grepping for every SIBLING carrying the same
  assertion — not just the one that fired.** A version tripwire fires on one test
  first; fix that one alone and the rest surface later, one server bump at a time,
  each looking like a fresh environment problem rather than the same incomplete
  sweep. Bit once (2026-08-24): `PaymentAxisConcurrencyTest` was re-armed for MySQL
  9.7.1 while `AllocatePaymentConcurrencyTest` kept `toStartWith('8.0.')`, fired
  weeks later on an unrelated branch, read as "the environment is wrong", and was one
  hurried decision from being **baselined** — which would have frozen a working
  tripwire as permanently-failing, the exact anti-pattern the ratchet's own message
  invites. `grep -rn toStartWith tests/` before closing the fix.
  When you do re-arm one: **re-measure and add the specific prefix, never widen to a
  bare major.** `9.` blesses 9.8 and everything after it sight unseen — switching the
  alarm off while appearing to update it.
- **A red is not a regression until you have seen the same code green somewhere.**
  The ratchet compares against a baseline, not against a clean run, so any drift
  between when the baseline was written and now reads as a regression it cannot tell
  from a real one. Bit once: 11 Finance screen tests failed as "NEW regressions" on a
  branch that touches no Finance code — a stale Vite manifest after a `staging` pull
  that added new pages, so every Inertia test rendering them 500'd. The check is
  cheap: run the failing files on the base branch with none of your work present.
  **Rebuild the frontend after any `staging` pull that touches it**, and note the
  worse cousin — a manifest that is stale but still *resolvable* passes against the
  wrong bundle instead of erroring.
- **Never build a test's input from the value under test.** A cap test written as
  `while (count($ids) <= MAX_BATCH) { … }` submits "cap + 1" whatever the cap is, so
  it proves a limit exists and is _structurally incapable_ of noticing that limit
  loosening. Bit once on `feat/reassignment-ui`: `MAX_BATCH` raised 60 → 100000, arm
  stayed green. That is worse than an untested cap, because it reads as covered.
  Pin the **value** (`expect(MAX_BATCH)->toBe(60)`) and use a **literal** payload
  (61), plus the accepting side (60) so an off-by-one reds in both directions. The
  general form: an assertion that derives from the thing it guards can only ever
  restate it. Same family as "assert the transition, not the endpoints" and "isolate
  the guard where it acts alone".
- **A green suite after you change a rule's KEY means the old behaviour survived — not
  that the new rule is right.** Two different claims; only the first is being tested.
  Bit on `feat/reassignment-ui`: exam type was removed from the reassignment
  eligibility key and all 26 M3 tests stayed green, because M3 shipped with exam type
  IN the key and so never had an arm that crossed it. Nothing was wrong with those
  tests — they were blind to the axis that moved. **When a key changes, the new arms
  are the ones that cross the axis you removed or added**, and a removal needs a
  POSITIVE arm (the newly-allowed move succeeds) as its mutation guard, or the old
  predicate drifts back in as a "restored" match with nothing going red.
  Corollary for the axes you KEPT: "drop X and its arm reds" proves each is
  **necessary**, never that the key contains nothing else. Completeness is established
  by reading the predicate list, not by mutation — a seventh filter hiding in a query
  makes a derived set silently narrower while every existing arm stays green.
- **A test proves the property it NAMES only if the fixture makes that property the
  SOLE explanation for the pass.** The recurring failure is not a wrong assertion —
  it is a fixture whose degrees of freedom have collapsed until a wrong
  implementation passes by coincidence, while the test's name stays true throughout.
  That is what makes it invisible to reading. Four instances, one mechanism:
  - **one arm on the target level** collapses arm-choice — a preview picking arms by
    *any* rule lands everyone in the same place, so a parity test cannot see drift;
  - **every fixture arm labelled `B`** collapses distribution into label-match, so a
    "distribution" test never evaluates the modulo at all;
  - **two ids of the same parity** collapse a `% armCount` to one residue, leaving
    the arm ORDER unpinned while the test reads as though it covered it;
  - **a single-element acknowledgment set** cannot express a swap, so a count-based
    check passes every arm and keeps the hole it was written to close;
  - **and it happens in DRIVES, not only in suites** (2026-08-25). Re-planning a
    rollover immediately after its batch drained returned `unconfigured=0`, which
    read as proof the readiness flag was satisfied. It was not: every pupil was
    already `promoted` with a link set, so there were no advancers and the flag was
    never evaluated. The demonstration only became real once the unresolved-pupil
    state was reconstructed. **A drive is an artifact too** — "I clicked it and it
    looked right" degenerates exactly as a fixture does, and the browser gives you
    no assertion to inspect afterwards, so ask what else could produce this screen.

  Before trusting a green, ask **what else could produce this pass?** and give the
  fixture enough distinguishing structure that the answer is "nothing but the rule
  under test": two arms, a non-matching label, ids of different residue, a set with a
  swap in it. Mutation testing is what surfaces this — it makes the wrong
  implementation explicit, and a degenerate fixture cannot kill it. This is the
  general parent of the self-referential cap: not "the test must be able to fail on
  its axis" but **"the fixture must make the axis the only thing that can pass it."**

  **And check the DOUBLE, not only the instrument.** Laravel's fakes record intent
  without enforcing the preconditions the real service enforces — `BusFake::batch()`
  returns a `PendingBatchFake` that skips `ensureJobIsBatchable()` entirely, so a
  fully-faked suite is green about the FAKE, not the system. Paid for twice:
  `MoveToNextYearJob` and `MoveFromTermJob` shipped without `Batchable` and `--commit`
  had never once worked; `MoveFromCcmJob` was caught with the same gap before it
  shipped. `Queue`, `Mail`, `Notification` and `Http` are all more permissive than
  what they stand in for. **If correctness rests on a precondition the real service
  validates, one arm must run against the real service.** Same principle as the
  instrument, one layer further in: anything standing in for production can diverge
  from it in exactly the dimension under test.

  **And check the instrument, not only the fixture.** A mutation-testing summariser
  that prints only Pest's `failures` bucket under-reports every exception-based kill
  as a SURVIVOR — and throwing is how most guards kill, so it disagrees with reality
  for exactly the controls most likely to be guards. Bit once (2026-08-26): a
  silent-drop guard's mutant was reported green; the mutation was working and the
  guard was raising a `RuntimeException`, which Pest files as an **error**. Count
  errors as kills. Same shape one layer out: **a measurement that mis-measures itself
  manufactures a wrong conclusion with nobody in the room to catch it.** Two
  corollaries paid for in the same hour: verify a mutation was APPLIED before
  trusting its result (a substitution that silently does not match reads as a
  survivor), and keep the mutation a one-line edit — a clever loop that escaped its
  replacement corrupted the file and reported six reds that measured nothing.

  Its other half: **derive the expected value by an INDEPENDENT path, never by
  restating the rule under test.** An expectation computed the way the code computes
  it asserts that the implementation equals itself. The arm expectation is built from
  an explicitly-ordered query, not from the resolver's own ordering — which is why
  flipping `orderBy('id')` to `orderByDesc('id')` reds it.
- **A control the server never receives is theatre, and theatre is worse than
  absence.** The rollover commit took two session ids and nothing else, so it could
  not distinguish a plan the operator had read from one a client had merely fetched,
  and every divergence signal it emitted came from `queued()` — *after* dispatch. The
  check existed entirely in the client. Same family as a stated rule with no lint
  behind it: an unenforced control does not merely fail to protect, it **manufactures
  the confidence that stops anyone looking**. Before adding one, ask what crosses the
  wire and what compares it. And when you enforce one over a set of things, **compare
  the SET, not the count** — a count cannot tell "these two" from "some other two",
  and the swap is the case that slips through. Server-enforce divergence precisely
  where post-write reporting is too late because the divergence is unrecoverable;
  leave benign divergences to the client.

  **Name it for what it is.** What was built here is a **staleness gate**: the server
  re-plans and refuses if the unsafe set grew since the client's last preview. It is
  NOT an acknowledgment — no operator gesture is bound to it, and the client echoes
  the last preview automatically, so it cannot tell "the operator read the warning and
  accepted it" from "a client fetched a preview". Calling it an acknowledgment was an
  overclaim, and it was caught by cold review *in the very entry describing this
  lesson* — which is the lesson twice over: **a claim wider than its artifact is the
  same defect as a control with no enforcement, one level up.** Resolve it by asking
  whether the claim is the GOAL — if it is, fix the artifact; if the artifact is right
  and the words overreached, fix the words. Leaving the gap open is the theatre this
  rule warns about.

## Workflow

Slice branches off `staging` → PR → `staging` (`bin/quality` via the
`.githooks/pre-push` hook, plus maintainer review — there is no CI, permanently;
ADR 0053) → milestone merge to `main`. Never stack branches. Conventional
Commits with scope. Rollout flags in `config/rbac.php` / `config/auth.php` ship
dark.

**A merged pull request is not evidence that the branch merged.** After any merge, run
`bin/landed <branch>` — target defaults to `staging`. It fetches origin (its only
mutation) and answers, from refs alone, whether `origin/<target>` contains every
commit on `origin/<branch>`, and — where the branch is contained — which merge took
it. **Containment is the detector**; the merge-head line explains, and makes no claim
about a branch that is not contained (git records no branch identity in the DAG, so
"merged then advanced" and "never merged" are the same graph shape — measured, see
`docs/handoff/reports/feat-landed-check.md` §§ 7-8). PR #265 reported merged while two commits — the
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
