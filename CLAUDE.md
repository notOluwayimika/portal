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
- **A GATE REPORTS COVERAGE, NOT JUST FINDINGS — and its FIRST green is the least trustworthy green
  it will ever produce.** It is the only one taken before anyone has established what the instrument
  cannot see, so "no violations" is unfalsifiable until the denominator is stated.

  Measured 2026-09-01. The SIGNAL-length lint reported a clean run while reading **61 of 117**
  messages — it knew the heredoc form of a SQL string and not the escaped form inside a PHP
  single-quoted string, and nothing in its output could have revealed that. Asking the same question
  of the two OLDER gates then found a real hole in one of them: the collation tripwire's matcher had
  no `LIKE` at all, and `LIKE` is collation-sensitive (`'a' LIKE 'A'` is TRUE under
  `utf8mb4_unicode_ci`). It had been clean by luck — the repository's only two `LIKE`s happened to
  carry `COLLATE`. The same question found a SECOND gap in the same file: comparisons against a
  declared local variable, which the right-hand side had never accepted.

  **REPORT THREE NUMBERS, NOT TWO.** *Examined*, *excluded with a stated reason*, and
  **unrecognised** — constructs the instrument could not classify at all. Only the third is
  dangerous, and an aggregate hides it: `LIKE` sat inside a "61 skipped" that looked like deliberate
  exclusion. Assert the third is zero, so a new unclassifiable form reds instead of vanishing. Same
  UNKNOWN discipline as `bin/board`'s failed fetch, applied to coverage.

  **AND WIDEN THE MATCHER TO RECOGNISE MORE THAN IT JUDGES.** A numeric literal is not a collation
  concern, but the matcher must PARSE `NEW.flag = 1` in order to exclude it on column type —
  otherwise it lands in unrecognised and the honest bucket fills with noise. Recognise broadly,
  judge narrowly.

  **A PARTIAL FIX TO A GATE IS WORSE THAN THE GAP**, because it converts a known blind spot into an
  unknown one. When two gaps overlap, the bite-proof must be the case that survives either fix
  alone — here a bare `LIKE` with a `CONCAT(...)` right-hand side.

- **WHEN YOU ASSERT A NEGATIVE, ASSERT *WHICH* NEGATIVE.** An assertion satisfiable by more than one
  state does not distinguish them, and this repository's guards are STACKED — so when your assertion
  is loose, something else almost always refuses first and the test passes for the wrong reason.

  Three instances in a single round on `feat/gateway-initiate`, each caught by a different accident:

  - a ward-authorisation test used a guardian from ANOTHER school, so `SchoolScope` resolved the
    invoice to null and the refusal came from isolation — deleting `mayPay()` left it green;
  - a trigger bite-proof asserted "an exception was thrown", which **1648** (`MESSAGE_TEXT` over
    128 chars, so the `SIGNAL` itself failed) satisfies exactly as well as the intended **1644**;
  - a gross-up took the FIRST candidate that "recovers the bill" — a predicate several formulas
    satisfy — and so over-charged a large payer by ₦13,228 while every arm stayed green.

  The operational form: name the error CODE, not "it threw". Name the MECHANISM, not "it was
  refused". Name WHICH candidate, not "one that works". And build the fixture so the mechanism under
  test is the only one that can fire — a same-school guardian for a ward check, not a cross-school
  one, with isolation as its own separate arm because the two refuse for different reasons and one
  test covering both goes green if either is removed.

  This is the parent of "the fixture must make the axis the only thing that can pass it": that rule
  is about the INPUT, this one is about the ASSERTION, and either alone leaves the other hole open.

- **Re-verify a consolidated document against current state IMMEDIATELY before sending it.**
  Consolidating several asks into one message buys the reader's attention — five arrivals become
  one — and it is paid for with the FRESHNESS OF THE EARLIEST ITEMS. The longer a note is held in
  order to consolidate it, the likelier its first item has been overtaken by a reply, a merge or a
  ruling.

  Bit within hours (2026-08-31 → 09-01): a five-item note asked Developer 1 to choose between two
  fee arithmetics. His reply of 30 August had ALREADY settled it — "the parent is charged bill + fee
  and the school receives the full bill" IS solve-for-gross. Sending it would have asked him to
  re-rule a settled question, in a week he was also doing cutover Section 0, while omitting the item
  that actually was open. A second item in the same note described as *proposed* work that had since
  been *built*.

  **The document is a recollection; the repo and their replies are the instrument** — the board rule
  one level up, and the same failure this file records everywhere else. Re-read the source you are
  citing, not your summary of it, at the moment of SENDING rather than at the moment of drafting.

- **Before starting any task: run `bin/board`, READ THE DIVERGENCE SECTION, then compile against the
  base you intend to branch from.** One step, three questions — **is it already built**, **does it
  still branch cleanly**, **does it compile**. The board fetches, so the divergence answer describes
  the remote rather than how stale your clone is.

  **The scar, because the abstract version of this rule does not get followed:** a
  `SettlementBankAccount` stub was built against a resolver Developer 1 had ALREADY LANDED on
  `staging`. One fetch at task start would have caught it. Four dependency surprises in a single day
  were every one of them found by CHECKING rather than by planning — branch topology and other
  people's merges are invisible from inside a task, and they keep changing while you work.

  **Read the SUBJECTS, not the count.** "12 commits behind" tells you to rebase;
  `feat(finance): withhold un-reviewed invoices from the parent feed` tells you somebody has built
  the thing you were about to start. Recognition is the whole value, and only the subject line
  produces it.

  It is folded into `bin/board` rather than written here as a habit, so it runs because the
  instrument runs and not because somebody remembered — the same reason `bin/db-exclusive` is a
  script and not a sentence. A failed fetch prints **UNKNOWN** and exits 2, never an empty list:
  "nothing landed" and "I could not look" must not render identically, which is the no-signal class
  the board exists to close.
- **A command whose exit code matters is NEVER the left side of a pipe — and an ad-hoc shell
  inherits none of this repo's safety.** `bin/quality`, `.githooks/pre-push`, `bin/board` and
  `bin/db-exclusive` all `set -uo pipefail`, so the scripts are fine. A one-off command typed at a
  prompt is not: `git push -u origin <branch> | tail -6` exits with **tail's** status, which is 0
  whatever the push did. Bit on 2026-08-31 — the pre-push gate REFUSED the push (one ratchet
  regression), and the command reported success. Worse, the harness's own completion notification
  reported `exit code 0` too, because it reports the pipeline's status: **the false signal was
  echoed back by the tooling, not just produced by it**, so there was no second opinion to catch it.
  What caught it was `git rev-parse origin/<branch>` — the ref, not the code — which is the same
  discipline `bin/landed` exists to enforce one level up. Three earlier pushes the same day reported
  success without moving the remote for three DIFFERENT reasons (hook aborted by a branch switch, a
  gate poisoned by a concurrent suite, DNS), so this is the fourth instance of one class: **a
  wrapper's exit status is a claim about the wrapper.** Either drop the pipe when you need the
  status, or verify the effect instead of the code — and prefer the latter, because it is the only
  form that survives someone else adding a pipe later.

- **`git commit -m "…"` runs backticks as commands. Write the message to a FILE and use `-F`.**
  Double quotes stop word-splitting and globbing; they do NOT stop command substitution. A commit
  message that names identifiers the way this repo's messages do — `payment_id IS NULL`,
  `withoutGlobalScope`, `redacted_at` — hands every one of them to the shell to EXECUTE. Bit once
  (2026-08-31) on `feat/paystack-webhook`: nine identifiers were substituted away and the commit
  landed with sentences like "a compare-and-swap on  whose affected-row count is asserted". The
  only visible sign was a few `command not found` lines scrolling past ABOVE the successful commit
  hash, which reads as noise from an earlier step. **The commit still succeeds**, which is what
  makes it dangerous: nothing fails, and the message is wrong in exactly the places that carried
  the technical content. Same class as the pint scalar-vs-array trap one entry down — a
  substitution that does not expand to what you think it does — and the same fix shape: take the
  shell out of the path (`-F file`) rather than escaping harder, because escaping is a rule you
  have to remember every time and a file is a rule you cannot forget. Heredocs with a QUOTED
  delimiter (`<<'EOF'`) are safe and are why the file-writing steps in the same session were fine.

- Tests alone are not verification — migrate the dev DB and drive the affected
  flows in the running app.
- **ONE CONSUMER OF `portal_testing` AT A TIME — and a `git push` IS a suite run.** `bin/quality`
  runs the full suite; `.githooks/pre-push` runs `bin/quality`. So every push holds the test database
  for ~18 minutes, and anything started beside it collides. The collision does not look like a
  collision: it produces `1213 Deadlock ... update roles set name = ...` and `1452` foreign-key
  violations on `role_has_permissions`, spread across nearly every file — **798 reds in one run**,
  indistinguishable at a glance from a catastrophic regression. It also silently breaks the push,
  because the poisoned `bin/quality` fails and a failing pre-push hook aborts the push while the
  surrounding shell still exits 0. Three false signals from this on 2026-08-30 alone, each of which
  read as a real finding. Before starting a suite, check nothing else is running:
  `ps -eo command | grep -c '[p]hp.*vendor/bin/pest'`.

  **AND MAKE IT GATE, NOT REPORT.** Written as a bare `echo` at the top of a compound command it is
  decoration: on 2026-08-30 exactly such a pre-flight printed `2` and the suite started anyway,
  because nothing branched on the number. A check whose result is not acted on is the same defect as
  a rule with no lint behind it, one layer smaller. Either guard it —

  ```bash
  [ "$(ps -eo command | grep -c '[p]hp.*vendor/bin/pest')" -eq 0 ] || { echo "busy"; exit 1; }
  ```

  — or use `bin/db-exclusive`, which is that guard as a script: `bin/db-exclusive ./vendor/bin/pest`
  refuses rather than reports. **Do not write the inline form**, because an unheeded check
  manufactures the confidence that stops anyone looking.

  **AND ITS FIRST VERSION WAS BROKEN CLOSED**, which is worth more than the script. The matcher
  `[p]hp.*vendor/bin/pest` also matched the INVOKING SHELL, whose command line contains the script's
  own text — so it refused every time, including when the database was free. Same self-matching trap
  as the `pgrep -f` wait-loops that blocked for hours the day before, now in the tool written to stop
  a *different* self-inflicted false signal. It was caught only by asserting the **known negative**
  (free → exit 0) alongside the known positive; a busy-only bite-proof passes a gate that always
  refuses.
- **`Http::fake()` ACCUMULATES stubs and the FIRST match wins — re-faking the same URL inside a
  loop does nothing.** Every iteration after the first receives the FIRST iteration's response, so a
  `foreach` over six provider statuses tests one status six times and reports six passes. Bit once
  (2026-08-29, the Paystack client) and it surfaced only by luck: the arm asserted the status was
  echoed back UNCHANGED, so iteration two compared `abandoned` against `failed` and reded. Had it
  asserted only the thing it was nominally about — `isSuccessful()` is false — all six would have
  passed for the same wrong reason and the file would have read as covering six states while covering
  one. **Use a dataset (`->with([...])`), not a loop**: each case gets its own fake, its own name and
  its own failure. Same family as the entries around it — the framework does something reasonable and
  your test lies about what it exercised.
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
  **AND THE RULE ABOVE UNDER-DESCRIBES ITS OWN FAILURE — a `staging` pull is not the only thing that
  stales it.** `public/build/manifest.json` is ONE SHARED, GITIGNORED artifact, and **any
  `bin/quality` run on any branch rebuilds it from that branch's tree**. So pushing branch A silently
  invalidates the manifest for branch B, with no pull, no merge and no edit involved — ordinary
  branch-switching is enough. Measured 2026-08-30: the manifest was rebuilt on a branch that adds an
  Inertia page, its tests passed, a push on an unrelated docs branch then rebuilt it WITHOUT that
  page, and the same tests 500'd with `Unable to locate file in Vite manifest`.

  Being gitignored is what makes it recur: it appears in no diff, survives no code review, and is
  never fixed *for* anybody — it breaks independently on every machine and is re-diagnosed from
  scratch each time. **Rebuild before running any suite that renders a page your branch adds, if
  anything else has run since** — not merely after a pull.
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
- **A monotone counter is an ACCUMULATOR, never a current-state signal — derive live state from
  live rows.** `job_batches.failed_jobs` only ever goes up: `decrementPendingJobs` prunes the uuid
  out of `failed_job_ids` on a retry-success but writes `'failed_jobs' => $batch->failed_jobs`
  unchanged. So a rule reading it as "failures outstanding right now" inherits history that has
  stopped being true, and `pending === failed` compared two accumulators as though they described
  the batch at this instant. Bit once (2026-08-26) on the CCM fold panel, and note WHICH DIRECTION it
  failed in: it withdrew "do not change the current session yet" while a retried worker was still
  running — the FALSELY-SAFE direction. It did not remove the retry-window lie, it swapped which half
  of the window told it, toward the worse half. The prior `finished_at === null` reading had been
  CORRECT in exactly that window.
  The fix keys on the `failed_jobs` ROW, which is live — `queue:retry` deletes it before re-dispatch
  — rather than the counter, which is a tombstone. That a listed id with no row means "retry in
  flight" is the tell that the new signal is ground truth and not a proxy: it catches a case a
  count of the ids alone still gets wrong. Same family as the fixture whose degrees of freedom
  collapsed and the wrapper exit code — an instrument that agrees with itself but not with reality,
  this time one layer below the surface, in the data model. **Before comparing two numbers from a
  row, ask of each: does anything ever DECREMENT this?**

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
