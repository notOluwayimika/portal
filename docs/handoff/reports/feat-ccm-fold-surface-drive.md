# CCM fold surface — the drive (backend legs proven; browser leg handed back)

Branch `feat/ccm-fold-surface`. This session was asked to drive the four legs in a browser. It
could not: **this session has no Chrome-extension tooling**, and the brief's own fallback applies —
*"If this session can't drive the browser, build and unit-prove the fixtures and hand the live
browser leg back."* So the fixtures were built, proven red, and then driven **end-to-end through the
real jobs, the real queue and the real controller actions against `portal_drive`**, leaving only the
rendering itself unobserved.

That distinction is the whole report: everything below is real execution, not a faked bus, and the
two findings in § 4 were **invisible to the existing suite** and only appeared because the real
thing ran.

## 1. The critical first step — the fixture was proven red before anything else

Leg 4 asserts a negative (the gate stays up). In a browser a fold that **succeeded** and a fold that
**could never have failed** are the same observation: a green batch over a cleared gate. So the
fixture had to be shown capable of producing a refusal first.

**One builder, two callers.** `database/seeders/CcmFoldDriveSeeder.php` is the single definition of
both worlds; `tests/Feature/CcmFoldDriveFixtureTest.php` asserts against it, and
`academics:seed-ccm-fold-drive` hands the identical shape to the browser. A Pest helper proving a
*lookalike* of the drive fixture would prove nothing about the fixture the browser gets.

Six arms, all green (`27 assertions`). Three watched reds, each **verified applied in the file
before measuring**, each reddening exactly one arm:

| Mutation | Result |
| --- | --- |
| `if (false && $dropped !== [])` — guard disabled | refusal arm reds (`Exception "RuntimeException" not thrown`) |
| `$scored >= 0` — guard keys on SHAPE, not loss | unscored arm reds — **as a Pest ERROR, not a failure**, which is how a throwing guard kills, and is exactly the mis-measurement CLAUDE.md records |
| `isCcm: true` in `NextTermSlot` — landing always CCM | the "flag decides" arm reds, proving the negative arm crosses its axis |

Tree restored to byte-identical after each; `git status` clean of tracked changes.

**The unit proof paid for itself on its first run.** Legs 1-3 *refused*. `MoveFromCcmJob` does not
copy the CCM subject's components onto the non-CCM side — with no scheme it seeds them from
`MarkingComponent::global()`, the school's template rows. The fixture had none, so the non-CCM
subject was built with **zero** components and every scored component was unmatched. Had this gone
to the browser first, legs 1-3 would have failed for a fixture reason indistinguishable from a
product defect.

## 2. Two worlds, genuinely different, in two schools — and why two schools

Marking schemes are keyed `(school, is_ccm, version)` **school-wide**. An active CCM scheme in the
subject-local school would attach itself to legs 1-3's arrival and make that fold refuse too. The
separation is therefore two schools, not two class levels.

```
+---------------------------------------------+----------+-----------+-------+-----------+----------+----------------+------------------+
| World                                       | School   | Curricula | Slots | CCM slots | Episodes | Active schemes | Global templates |
+---------------------------------------------+----------+-----------+-------+-----------+----------+----------------+------------------+
| A — legs 1-3 (subject-local, fold SUCCEEDS) | school#1 | 2         | 6     | 1         | 3        | 0              | 2                |
| B — leg 4 (scheme-asymmetric, fold REFUSES) | school#2 | 1         | 2     | 1         | 2        | 2              | 0                |
+---------------------------------------------+----------+-----------+-------+-----------+----------+----------------+------------------+

non-CCM scheme#1: ["Continuous Assessment","Examination"]
CCM     scheme#2: ["Continuous Assessment","Half Term Project"]
```

**Two zeros there are structural and are printed with their reason** — School A's `Active schemes`
and School B's `Global templates` are the axis the worlds differ on, so a fixture without them would
be the broken one. Every other zero is a stop. The command says this in its own output rather than
leaving it to be rediscovered.

`Slots` is 6 for school A because both levels run `[1, 3, 4]`. **Slot 4 was added deliberately**:
leg 3 ends *"the gate clears and the rollover proceeds"*, and a level whose participation ends at
slot 3 has nowhere to proceed to — that clause would have been unreachable by construction and the
leg would have quietly degraded into "the gate clears" with nothing to notice. Slot 2 stays skipped
so `1 -> 3` still proves *next participating slot*, not *next term*.

## 3. What actually ran, against `portal_drive`

Real `MoveFromTermJob` / `MoveFromCcmJob`, real `Bus::batch` **dispatched through
`RolloverController::foldCcm` itself** (a hand-written batch here would be a second definition of
what the button does), real `queue:work --stop-when-empty`, real `failed_jobs`, and the panel read
back through `RolloverController::batches`.

**Legs 1-2 — arrival, with the negative arm crossing the axis:**

```
LEG1 Year 7  source#1 -> ccm=curriculum#4  non_ccm=NONE
LEG1 Year 8  source#2 -> ccm=NONE          non_ccm=curriculum#5
LEG2 components=["Continuous Assessment@0.400","Examination@0.600"]
```

Same session, same movement `1 -> 3`, same exam type, same school; one participation flag apart.
Neither level landed on the other side of the flag — so the flag decides, not the rollover.

**Leg 3 — the fold succeeds:**

```
PANEL total=1 pending=0 failed=0 draining=false reasons=[]
FOLDED ccm curriculum#4 status=closed promoted_out=2
  carried score=30.00 onto 'Continuous Assessment' (weight 0.400)   [rescale parity: 0.4 -> 0.4]
GATE(after fold) is_runnable=true blocked_by=[] curricula=2 pupils=3
ROLLOVER COMMIT status=200  batches_before=1 batches_after=2
```

**Leg 4 — the fold refuses, and the surface tells the truth:**

```
WORKER: MoveFromCcmJob FAIL / FAIL / FAIL   (three attempts, ONE worker run)
PANEL  kind=ccm-fold total=1 failed=1
PANEL  reason[0]: Refusing to fold curriculum#4: 1 scored marking component(s) on subject#2 have no
       counterpart on the non-CCM side and their marks would be lost — "Half Term Project"
       (2 score(s)). Add matching component(s) to the non-CCM marking scheme, then fold again.
GATE   is_runnable=false blocked_by=["ccm-active"] ccm_blockers=["e212398e-…"]
CCM    curriculum#4 status=active is_ccm=true promoted_out=0
COMMIT status=422   batches_before=1 batches_after=1     ← nothing batched
```

Every negative holds: gate up, `is_runnable` false, blocker still named, commit still 422 with
**nothing batched**, curriculum untouched, nobody promoted out of a fold that did not happen.

**The no-backoff invariant is now proven, not assumed.** All three attempts ran inside a single
`--stop-when-empty` worker with no gap, so the worker never observed an empty queue mid-retry. It is
also pinned as an asserted arm (`$tries === 3`, no `backoff` property or method), because a backoff
added later would silently reduce the drive to one attempt while still appearing to run.

## 4. Two findings — both invisible to the suite, both the "check the double" lesson

### A. The panel prints an absolute filesystem path and a line number

`failureReasons()` takes `strtok($exception, "\n")` and strips the FQCN. But the first line of a
**real** PHP exception is `RuntimeException: <message> in /abs/path/File.php:265` — the frames
(`#0 …`) are on later lines, so first-line-only removes the trace but **not** the ` in /path:line`
suffix. The operator sees:

> …then fold again. **in /Users/oluwayimika/Documents/portal/app/Jobs/MoveFromCcmJob.php:265**

`CcmFoldSurfaceTest` passes because its hand-inserted `exception` string was written **without** that
suffix — a double diverging from the real thing in exactly the dimension under test. Its assertions
(`not->toContain('#0 /app/Jobs')`, `not->toStartWith('RuntimeException')`) both still hold.

### B. A fold batch containing any failure never finishes — the panel says "draining" forever

`Illuminate\Bus\Batch::markAsFinished` is called **only** from `recordSuccessfulJob` when
`pendingJobs === 0`, and `DatabaseBatchRepository::incrementFailedJobs` deliberately writes
`'pending_jobs' => $batch->pending_jobs` — unchanged. So a permanently-failed batched job never
decrements pending, `finished_at` stays null, and:

- `is_draining` (`finished_at === null && cancelled_at === null`) is **true forever**;
- `done_jobs` (`total - pending`) is **0 forever**;
- the *"Finished with N failure(s)"* copy added in `d576d345` is **unreachable** for any fold batch
  with a failure in it — including mixed batches, since successes cannot drive pending to zero while
  a failure holds it up.

Observed: leg 4's batch `total=1 pending=1 failed=1 finished_at=null draining=true` after all three
attempts were exhausted. Leg 3's clean batch read `draining=false`, which is the contrast confirming
this is specific to failures and not an artifact of the dispatch.

`failure_reasons` **does** surface, so the operator is not blind — but the batch is labelled
*draining* while it is dead, and the retry-window copy ("neither done nor failed for three
attempts") never stops being true. That is close to the failure this surface exists to prevent.

`CcmFoldSurfaceTest` cannot see it: it hand-inserts `job_batches` rows with `pending_jobs => 0,
finished_at => time()`, a shape the real bus does not produce for a failed batch.

**Both are reported, not fixed** — a drive observes; the decision is the project lead's.

## 5. One gate failure fixed, because the branch caused it and it blocks `bin/quality`

`composer analyse` was **already red on this branch's HEAD**, verified by running it on a clean tree
with none of this session's work present. Commit `ef608a0e` added the `$newComponent !== null`
narrowing, which fixed the inference and left the `phpstan-baseline.neon` entry for
`mapOverlappingMarkingComponents` **unmatched** (`ignore.unmatched`, not ignorable). Removed — a
ratchet tightening, exactly one entry, deletions only. `composer analyse` now passes.

The brief's "clean committed boundary: 33 tests green, tsc at baseline" did not cover Larastan.

## 6. What was NOT done, and why

- **The browser rendering of all four legs.** No Chrome-extension tooling in this session. Everything
  *behind* the screen is proven above; what remains unobserved is the pixels — the kind badge, the
  retry-window wording, the reason text as laid out, and whether finding A's path suffix is visually
  disruptive.
- **The three "after the drive" items** — `rc_level`'s insert-only `is_ccm` param, the
  `progression-panel.tsx` toggle, and `bin/quality` → PR. The brief ordered these explicitly *after*
  the drive, and findings A and B may change what the panel should say, so racing ahead would invert
  an ordering that was set deliberately.
- **Nothing is committed.** Three new files plus the one-entry baseline deletion sit in the working
  tree for review.

## 7. Files

| File | What it is |
| --- | --- |
| `database/seeders/CcmFoldDriveSeeder.php` | both worlds; the single definition the unit proof and the drive share |
| `tests/Feature/CcmFoldDriveFixtureTest.php` | six arms proving the fixture can go red, plus the no-backoff invariant |
| `app/Console/Commands/SeedCcmFoldDrive.php` | drive-DB-guarded seed + the count table and its structural-zero notes |
| `phpstan-baseline.neon` | one stale entry removed (§ 5) |

**On the guards:** the command refuses unless `APP_ENV=drive` **and** the live connection's database
name matches `/(^|_)drive(_|$)/`. The env guard is currently **inert** — the committed `.env` for the
dev instance itself carries `APP_ENV=drive` against `DB_DATABASE="portal-test"` — so the database
allowlist is the only load-bearing guard, and it reads the name off the **live connection**, not the
env var. Verified both ways as a predicate rather than by pointing a `migrate:fresh` at the dev
database: `portal-test`, `portal_demo`, `school_uat`, `driver` refused; `portal_drive`, `drive`,
`my_drive_db` allowed. Note `portal-drive` (hyphen) is **refused** — name a clone with an underscore.

---

# Addendum — A and B fixed; one BLOCKER open

## A — fixed at the producer

`App\Exceptions\CcmFoldRefused` overrides `__toString()` to return the message. `failed_jobs.exception`
is `(string) $throwable`, so the value Laravel persists **is** the sentence and no consumer parses
anything. No path-stripping regex was added — a message may itself contain " in ", and a
strip-by-pattern is one more thing that works on a fixture and breaks on reality.

It costs no trace: `Handler::report()` logs `getMessage()` with the throwable in
`['exception' => $e]`, and Monolog reads class/file/line/trace off the **object**, never
`__toString()`. Verified against the real queue — the panel now ends at "…then fold again."

The `CcmFoldSurfaceTest` fixture no longer hand-writes an exception string: it stringifies a real
throwable (`ccmf_stringifiedThrowable`), and the assertion is `toBe($message)` — exact equality
against the message the guard was given, so a path suffix, a class prefix or a truncation all red.

## B — fixed in the SHARED panel, as a shared defect

> **⚠️ SUPERSEDED — READ § "B, CORRECTED" BELOW BEFORE TRUSTING THIS SECTION.** The
> `pending === failed` derivation described here shipped and was WRONG on both sides of a retry;
> cold review caught it. The section is kept unedited because this project's account of a branch is
> what was believed at each point, not a tidied version of it — but nothing below describes the code
> on this branch, and the watched reds it lists are for predicates that no longer exist.

`RolloverController::settledState()` derives the terminal state from counts:
`pending === failed` ⟺ every job resolved, because pending is decremented **only** by successes.
It subsumes the clean case (0 === 0 at the same instant `finished_at` is written) rather than
special-casing it, and stays false between retries, so the $tries = 3 window still reads as draining.

Confirmed deliberate and **vendor-owned** before choosing the locus: `decrementPendingJobs` has
exactly one caller (`recordSuccessfulJob`), and `incrementFailedJobs` writes
`'pending_jobs' => $batch->pending_jobs` as an explicit no-op. Reading around it in the panel was
therefore correct; patching the source would have meant patching Laravel.

The word is **"Stopped with N failure(s) — it will not resume on its own"**, not "Finished": those
jobs are still pending in the queue's own sense, awaiting a `queue:retry` a human must issue.

This is on the path **rollover batches share**, so a failed `MoveFromTermJob` batch is hardened too.

Three watched reds, each verified applied: `__toString` removed → the path assertion reds;
`is_draining` reverted to `finished_at` → the stopped arm reds; `pending <= failed` widened to
`failed > 0` → the new "failed but others still working" arm reds. That third arm exists because
neither original arm crossed that axis.

Re-driven against `portal_drive`: `settled_state=stopped draining=false`, reason clean, and every
leg-4 negative still holds (gate up, 422, nothing batched). The clean path still reads `finished`.

## RESOLVED — `pest --group=arch` exited 255 with NO output: a prod→test import, via Pint

**Root cause, and it is a real boundary violation rather than a tooling quirk.**

`Pint`'s `fully_qualified_strict_types` fixer rewrote the docblock reference
`{@see \Tests\Feature\CcmFoldDriveFixtureTest}` in `CcmFoldDriveSeeder` and `SeedCcmFoldDrive`
into a genuine `use Tests\Feature\CcmFoldDriveFixtureTest;` **import in production files**. I wrote
a docblock; the formatter promoted it to a dependency, and its run output named only the fixer.

`composer.json` maps `Tests\` → `tests/` (autoload-dev). The arch pass reflects over `app/` and
`database/`, resolves that import, and Composer loads `tests/Feature/CcmFoldDriveFixtureTest.php` —
which Pest had **already** loaded. The second include redeclares the file's top-level helpers:

```
Fatal error: Cannot redeclare ccmd_rollover() (previously declared in
  tests/Feature/CcmFoldDriveFixtureTest.php:41) in .../CcmFoldDriveFixtureTest.php on line 41
```

That explains every observation: only this file (only it was imported by a production class); fine
when run alone (running it never triggers the arch reflection); and a trivial new test file with a
top-level helper is harmless (nothing imports it).

**Fix:** the imports are gone and the docblocks now carry the plain path
`` `tests/Feature/CcmFoldDriveFixtureTest.php` `` — text, never a resolvable class name, so Pint has
nothing to promote. Verified Pint-stable (re-running it does not reintroduce them) and arch green 3/3.

### Why it was invisible, which is the more useful half

The fatal was written into an **unflushed output buffer** (`ob_get_level() === 2`) that Pest never
drained on the way out, so stdout, stderr, `--log-junit` and the PHP error log were all EMPTY. It
surfaced in one step with a `register_shutdown_function` via `auto_prepend_file` reading
`error_get_last()` and draining the buffers.

**I spent four bisection rounds against that silence and produced two wrong conclusions** — a
multi-line docblock, then the function body — because `head -n`/`sed -n` probes cut mid-function and
**Pest silently skips a syntactically invalid test file and exits 0**, which reads exactly like
"this range is innocent". The rule that generalises: **a silent failure is a void measurement until
it has been made to report.** Bisecting against a non-reading cannot converge. Make it speak first;
`php -l` every bisect candidate and treat a syntax failure as void, never as a pass.

### An open gap, reported not fixed

**Nothing in the enforcement floor catches production code importing a test class.** With the
`use Tests\Feature\...` restored in `database/seeders/`, `bin/ci-boundary-lint.php` exits 0,
`bin/ci-authz-lint.php` exits 0 and `composer analyse` passes. The only gate that reacts is the arch
pass, and it reacts by dying silently. A `Tests\` import from `app/` or `database/` is exactly the
kind of one-line boundary breach a lint should refuse; whether to add that rule is the project
lead's call, not this branch's.

## (superseded — original blocker write-up, kept for the record)

Not root-caused. Reproducible and deterministic (3/3 with, 3/3 without):

- remove `tests/Feature/CcmFoldDriveFixtureTest.php` → arch exits 0
- restore it → arch exits 255, zero bytes on stdout, stderr and `--log-junit`
- the file's own six tests pass, and every other gate passes with it present

Ruled out: helper-name collision (renaming every `ccmd_*` changes nothing); memory limit (2G,
unchanged); stale caches (`.pest`, `.phpunit.cache`, `bootstrap/cache` cleared); any single arch
file (each passes alone); adding a trivial new Feature test file (arch stays green).

**Two of my intermediate conclusions in this investigation were wrong, and the reason is worth
recording because it will mislead the next person too: Pest SILENTLY SKIPS a syntactically invalid
test file and exits 0.** Bisecting by `head -n`/`sed -n` line ranges cuts mid-function, and the
resulting "green" reads exactly like "this line range is innocent". I concluded first that a
multi-line docblock on a helper was the trigger, and then that the function body was — both were
artifacts of measuring a file PHP never compiled. Any bisect here must `php -l` each candidate and
treat a syntax failure as a **void measurement**, never as a pass. That is the same class as the
corrupt-`node_modules` tsc lie: the instrument reported on something other than what was asked.

**Superseded by the section above — this was resolved; arch is green 3/3.**

---

# Step 2 — the producer, wired last and deliberately

## `rc_level` gains `$ccmSlots`, appended

Sixth parameter, after `$attrs`, so all **33** existing call sites are byte-unchanged — inserting it
beside `$slots` where it belongs logically would have shifted the positional `$attrs` argument that
several rollover tests pass. Default `[]` writes every slot `is_ccm = false`, which is exactly the
behaviour every caller had before.

**A per-slot list rather than a bare boolean**, which is a deliberate departure from the literal
brief and is worth saying out loud: CCM participation is held at (class level, term slot)
granularity, so the shape this branch is actually about — "runs [1, 3], and slot 3 is the CCM
variant" — cannot be expressed by a boolean, and a caller wanting one CCM slot would have to write
the participation rows by hand, which is the duplication the helper exists to remove. It also
matches `mft_world`'s existing `$ccmSlots` parameter, so the suite has one spelling of this idea.

Arm: `[1, 3]` with `ccmSlots: [3]` yields `[1 => false, 3 => true]`, and the default yields all
false — the second half is what stops a default of "all true" passing the first. Watched red:
`in_array($slot, $ccmSlots, true)` → `$ccmSlots !== []` (whole-level instead of per-slot) reds it.
All 62 tests across the five rc_level-consuming files still pass.

## The toggle

`progression-panel.tsx` gains a per-slot CCM checkbox calling
`PATCH /api/class-levels/{level}/participation/{slot}`.

**It sends the desired state, never `!slot.is_ccm`.** The endpoint is a setter taking
`is_ccm` as `required|boolean`; computing the negation client-side would reintroduce the inverter
one layer up, where a double-submit, a retry or a stale panel lands the flag opposite to what the
operator saw with no error, because inverting twice is legal. A `pendingSlot` guard disables the
row's control while its write is in flight, and nothing is applied optimistically — the row
re-renders from the server's own progression payload on success and is untouched on failure, so the
screen never shows a state the server did not confirm.

`UpdateClassLevelParticipationRequest` already validated `required|boolean` from step 1; no change
was needed there, only its first real caller.

## The endpoint had NO test until now

Step 1 converted the inverter to a setter and said so — "no component, no test". The toggle makes it
live, so it needed arms before it shipped:

- **sending the same value twice lands the same row** — this is the assertion, not the first write.
  A test asserting only the first write passes against the inverter too; the second call is what
  tells them apart. Both directions, so "always writes true" cannot pass.
- **an absent `is_ccm` is a 422 and leaves the row alone** — `required`, not `sometimes`, and the
  refusal must not have already written.
- **a slot belonging to another level is a 404** — the same nested-route integrity the delete path
  enforces, so the panel cannot write across levels within a school.

Watched red: restoring `['is_ccm' => ! $participation->is_ccm]` reds the idempotence arm alone.

## Still outstanding

**The live browser pass is a PRE-MERGE gate, not done.** A and B were both *rendering-truth*
defects — a path in the reason, a state that read as draining forever — and the re-drive proved the
panel's DATA through real jobs and controller read-back, not the rendered component. What still
needs eyes: leg 4's rendered failure state (the "CCM fold" badge, the "Stopped … will not resume on
its own" terminal copy, the path-free reason), and the new CCM toggle on the progression panel,
which has never been rendered at all.

---

# The gate

`bin/quality` — **PASS, 17/17**. One green run: this project records byte-identical code producing
both PASS and FAIL (ADR 0053), so this is a pass, not a determinism claim. Per-run suite artefacts
are kept.

## The first run FAILED, and the regression was mine

```
✗ quality: FAIL (1): test-ratchet
  ✗ tests/Feature/Quality/PestNegatedExpectationMessagesTest.php
      ::it no test passes a custom failure message to a negated Pest expectation
```

I had written, in the arm guarding fixture vacuity:

```php
expect($ccm)->not->toBeNull('the rollover did not create a CCM arrival — the rest of this fixture is vacuous');
```

`toBeNull()` declares a `$message` parameter, so under `->not->` Pest's `OppositeExpectation` runs
the POSITIVE assertion, discards its exception, and composes a generic sentence with every argument
pushed through `Exporter::shortenedExport()` — the sentence would have been exported and truncated
mid-string rather than printed. It existed solely to tell a future reader the fixture had gone
vacuous, so it would have failed at the one job it had, in the arm whose entire purpose is noticing
that. Not a nuisance rule.

Fixed by making it positive — `expect($ccm !== null)->toBeTrue('…')` — so the message survives.

The other five `->not->` calls in the changed files were checked and left: `toContain('#0 ')`,
`toContain('/app/Jobs')` and `toStartWith('RuntimeException')` pass `$expected`/needle arguments
rather than `$message`, which the rule explicitly permits, and `ClassLevelProgressionTest:342`'s
`->not->toBeEmpty()` is pre-existing and argument-free.

## And the runner's exit code is not the gate's

The background-task notification reported **"completed (exit code 0)"** for the run that FAILED —
that was the wrapping shell's status, not `bin/quality`'s, which printed `EXIT=1`. Reported as a
pass it would have put a real regression behind a green claim. Read the exit status of the thing
being measured, never of its wrapper: the same class as everything else in this report.

---

# The PRE-MERGE gate — what must still happen, and why each item is on it

These are pre-**merge**, not pre-**push**. Pushing opens the pipeline in which they get resolved;
holding the branch off the remote resolves nothing.

## 1. A SECOND `bin/quality` green

One green run is a single reading of an instrument this repository has recorded producing both
PASS 14/14 and FAIL 23 over byte-identical code (ADR 0053, cause investigated and not found, one
failure in twelve runs). A single PASS is therefore evidence, not a determinism claim — and the merge
decision is exactly where a second reading is worth its cost. It is the same "do not trust one
reading" discipline this branch spent itself on, turned on the gate itself.

If the second run reds, the rule from ADR 0053 applies and is NOT optional: a red cannot be told from
a flake by looking at it, and retrying until green is indistinguishable from fixing. The per-run
suite artefacts exist so that run is diagnosable.

## 2. The browser pass must prove the LOOP, not the pixels

Rendering leg 4's badge and copy is necessary and not sufficient. The toggle exists to steer a
PLACEMENT, so the gate is the full round trip:

1. set slot 3 CCM **through the progression panel**, not through a seeder or a console;
2. run the end-of-term migration;
3. confirm the pupil lands in the **CCM** curriculum — resolved on the five-key construction, not by
   "the newest curriculum", which would pass whatever arrived;
4. and the sibling level with the flag OFF lands non-CCM, so the observation is about the FLAG rather
   than about the rollover.

Without step 3 the drive proves a checkbox writes a row, which was never in doubt. The suite proves
the participation flag decides the landing (`CcmFoldDriveFixtureTest`), and the panel now proves the
flag can be set — but nothing yet proves those two are the SAME flag end to end through the screen.
That join is exactly where a control that looks enforced turns out not to be.

Then, and separately, the rendered failure state: the "CCM fold" kind badge, the
"Stopped … will not resume on its own" terminal copy, and the reason with no server path in it. A and
B were both rendering-truth defects; the re-drive proved the panel's DATA through real jobs and real
controller read-back, never the rendered component. The new toggle has never been rendered at all.

## 3. Cold review findings resolved

Attack targets recorded with the review: whether `pending === failed` is genuinely equivalent to
"every job resolved" across cancelled, zero-job and mid-retry batches — the load-bearing claim of the
whole B fix — plus fixture degeneracy in leg 4, the byte-unchanged claim for all 33 `rc_level` call
sites, whether the idempotence arm really separates a setter from an inverter, and `school_id`
isolation in the two-school seeder.

---

# B, CORRECTED — the derivation in the section above never should have keyed on `failed_jobs`

Cold review round 1 found the shipped fix wrong on BOTH sides of a retry. The mechanism, verified in
vendor rather than taken on the finding's word:

`job_batches.failed_jobs` is **monotone**. `DatabaseBatchRepository::decrementPendingJobs` prunes the
uuid out of `failed_job_ids` on a retry-success but writes `'failed_jobs' => $batch->failed_jobs` —
unchanged. It counts failures EVER RECORDED, never failures currently outstanding. So
`pending === failed` compared two accumulators as though they described the batch at this instant:

- after `queue:retry` SUCCEEDS the counter still reads 1 over a complete batch, and the panel
  rendered "Stopped with 1 failure(s) — it will not resume on its own" with no reason beside it,
  because the ids had been pruned while the counter had not;
- and while a retried job was IN FLIGHT the counts were unchanged, so `is_draining` went false and
  "do not change the current session yet" disappeared with a worker still running. **The
  `finished_at === null` reading it replaced was CORRECT in exactly that window.**

The second is the one that matters: the fix did not remove the retry-window lie, it swapped which
half of the window told it, toward the FALSELY-SAFE half. Generalised in `CLAUDE.md` — a monotone
counter is an accumulator, never a current-state signal, and severity has a sign, not just a
magnitude.

**What ships instead:** `outstandingFailures()` counts ids in `failed_job_ids` that STILL HAVE a
`failed_jobs` row, because `queue:retry` deletes the row before re-dispatch — so a listed id with no
row is a retry in flight. `terminal ⟺ pending === outstanding`. This catches the in-flight case that
counting the ids alone still gets wrong.

Round 2 then found three more, all fixed: the panel counted **de-duplicated reason sentences**
instead of failures, so a mass failure with one shared message read "1 failure(s)" beside a
`failed_jobs` of N — and a vitest arm of mine had **encoded that undercount as expected**, so no
mutation could red it; the settled state was computed **twice from two independent queries**, and the
torn pair fell through to the most reassuring word available; and a dead `total_jobs > 0` guard
carried a test comment claiming coverage it did not have.

Watched reds, each verified applied before measuring — one of which initially reported a false PASS
because prettier had rewrapped the line my substitution targeted, so the mutation never applied. That
is the instrument rule biting inside the fix for a review finding: **verify the mutation is in the
file before believing its result.**

## Known and NOT fixed

`queue:forget`, `queue:prune-failed` and `queue:flush` delete a `failed_jobs` row without a retry,
leaving the id listed forever — `outstandingFailures()` then reads 0, the batch never settles, and
the panel says "draining" permanently with no reason: defect B reinstated in a new place.
`queue:forget` is the likely one, since a fold refusal is deterministic config that retrying never
clears. Recorded in `outstandingFailures()`'s docblock and left unguarded: it needs a deliberate
manual command, nothing schedules a prune here, and the direction is falsely-CAUTIOUS — a warning
that overstays rather than one that vanishes.

---

# THE BROWSER GATE — RUN, AND PASSED

Driven by a human operator through the Chrome extension against `http://localhost:8001` (the
decoupled drive instance) on `de3c4153`. Screenshots/GIF: `docs/handoff/drives/2026-08-26-ccm-fold/`.

**Two evidentiary levels, kept separate throughout, because they are not the same claim:**

| | school#1 | school#2 |
| --- | --- | --- |
| State | **PRISTINE** — nothing CCM, nothing rolled over | **STAGED** — gate handed to the driver |
| Claim | the whole loop, end to end | the fold fails and RENDERS correctly *on* that gate |
| NOT claimed | — | that the gate arose |

## Scenario 1 — the loop, proven as a TRANSITION

Not "the pupil landed in CCM" — that is an endpoint, and an endpoint is equally explained by a
seeded flag. The pre-state was read BEFORE the click and the row was read AFTER:

1. `/setup` → Class Structure → **Configure progression for Year 7** → all three slots' CCM boxes
   **OFF**, Term 3 (slot 3 = participation#2) unchecked — zoomed and recorded.
2. Clicked Term 3's CCM box → checked, blue "CCM" badge appeared, toast **"Slot marked CCM."**
   Only Term 3 changed. **Persisted on click** — no separate save, which is the inverter→setter
   conversion behaving as a setter.
3. DB read-back, independently: `participation#2 slot3 is_ccm=true`, and **every sibling still
   false** — one row moved, the one clicked.
4. `/academics/rollover` → End of term → **2026/2027 — Term 1** → preview: *2 class(es), 3 pupil(s)
   would move* (Year 7's 2 + Year 8's 1), *Progression graph: not applicable*.
5. Commit → **queued**, batch `rollover:end-of-term:school:1:term:1`, `Done 0/2`, **"Draining — do
   not change the current session yet."**
6. Drained by ONE `queue:work --stop-when-empty`, which exited on its own.

**The landing, from the rows:**

```
Year 7 (toggled CCM)         curriculum#1 term#1 is_ccm=false closed  pupils=2 promoted_out=2
Year 7 (toggled CCM)         curriculum#5 term#3 is_ccm=TRUE  active  pupils=2
Year 8 (control, untouched)  curriculum#2 term#1 is_ccm=false closed  pupils=1 promoted_out=1
Year 8 (control, untouched)  curriculum#6 term#3 is_ccm=false active  pupils=1
```

Same session, same movement 1→3 (skipping slot 2, as configured), same exam type. **The only
difference between the two levels is the click.** That is the join nothing had proven: the suite
showed the flag decides the landing; the panel showed the flag can be set; this shows they are the
SAME flag end to end through the screen. Screen-side second leg: batch **Done 2/2, Finished**.

**The negative arm, at its honest strength.** `operator@` reaches Class Structure (it holds
`academic_data.view`) but **403s on `/api/class-levels/{yr7-uuid}/progression`** — the modal opens and
hangs on "Loading…" with *"Failed to load progression settings"*. Both responses for the SAME uuid
sat side by side in the network buffer: `setup@` 200, `operator@` 403. Claimed as *the rollover-only
seat cannot reach the progression surface at all* — NOT "sees rows without the control", which is
structurally unavailable because the panel's data and its CCM control share one permission.

## Scenario 2 — the refusal, rendered

Gate re-previewed on term#6 → *0 classes, 0 pupils would move* + **"1 CCM class(es) sit in a final
slot and must be moved first" — Year 9 A**, with an inline **"Fold these now"**. Fold dispatched
`ccm-fold:school:2:term:6`; ONE worker took it through **three attempts, no backoff**, and exited.

Settled state — the payload and the render agreeing:

```
BATCH  total=1 pending=1 failed=1 finished_at=NULL cancelled=NULL
PANEL  kind=ccm-fold settled='stopped' draining=false done=0/1 failed=1 outstanding=1
```

**`finished_at` is null and the panel says `stopped`.** That is the entire B-fix in one line: Laravel
never marks this batch finished, because a failed job does not decrement `pending_jobs`. The old rule
read *draining* forever. The new one settles because `outstanding === pending`, derived from a live
`failed_jobs` ROW rather than the monotone counter.

Confirmed rendered, by eye:

1. **"CCM fold"** badge on the SETTLED row, not only while draining.
2. **"Stopped with 1 failure(s) — it will not resume on its own."**
3. Reason ending at *"…then fold again."* — **no `in /path:line` suffix**. The A-fix holds through
   the render; the string that crossed the wire is byte-identical to what appeared.
4. Gate **still up** on re-preview, still naming Year 9 A, "Fold these now" still offered.
   `curriculum#4 status=active is_ccm=true promoted_out=0` — refused inside its transaction,
   nothing folded, nothing promoted, and the operator can retry.

## Operator-facing findings from the driver (suggestions, not defects)

**The reason names INTERNAL IDS where the gate names the class.** The message says
`curriculum#4` and `subject#2`; six lines above, the gate says **"Year 9 A"**. The failure message is
the one place an operator most needs the human label, and it is the one place that reverts to ids.
**This is the strongest of the three and arguably crosses from polish into defect** — a remedy
naming entities the operator cannot look up is a remedy they cannot act on. Ticketed.

**It renders as one unbroken ~150-character line** — past the measure where the eye tracks reliably,
and it is really two sentences: the diagnosis (ending `(2 score(s)).`) and the remedy (`Add matching
component(s)…`). Wants a break or visual separation at that period, and a ~70–80ch max-width so it
wraps deliberately rather than spanning the panel.

**"…it will not resume on its own" is actionable by OMISSION** — it forecloses waiting, which is the
false hope the B-fix exists to kill, but it is a status, not an instruction; the remedy lives in the
row beneath. The driver judged that split correct and would not change it: the remedy is genuinely
too long for a status cell.

## Incidental findings, logged

- **The commit modal states the staleness gate**: *"It is checked again at this moment — if anything
  changed since the preview, it will be refused rather than run."* That control existed server-side
  and had never been seen stated to an operator.
- **`Done 0/2` while draining** — the live count, which is the axis the monotone counter would have
  misreported.
- **School#2's panel showed "No rollover batches for this school"** while school#1's finished batch
  existed — batch-panel school scoping holding, an isolation observation neither of us asked for.
- **The post-login landing route 403s** for these seats; the pages themselves are reachable directly.
- **Cross-school reads return 404, not 403** — the correct shape.
- **Every seat renders the display name "Drive Operator"** — the seeder gives them all the same
  first/last name. Harmless here only because the driver checked identity by email and by id
  throughout; it is exactly the label-standing-in-for-identity trap and should be fixed.
- **The session expired twice mid-drive**, forcing re-authentication.
