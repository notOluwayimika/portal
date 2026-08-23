# Brief — M4 · Rollover operator surface (B4)

**Base:** `staging`, **after** `feat/reassignment-ui` merges. Re-derive with
`git rev-parse --short staging`. Never stack branches.
**Branch:** `feat/m4-rollover-surface`.
**Shape:** two slices. **(1)** `RolloverPlanner` extraction — no UI, no new routes, CLI behaviour
unchanged. **(2)** the controller, the permission and the screen. Slice 1 ships on its own and is
worth having regardless.

---

## Why M4 is being built now

The plan deferred M4 with a **named trigger**, not a sequence position
(`docs/handoff/student-progression-admin-ui-plan.md` § (d) decision 1): build it when either
*"there are more schools than a developer is willing to babysit"* or *"a registrar is expected to
run one themselves"*.

**The second has fired.** That is the stronger of the two, and it changes what the milestone owes:
shell access stops being the gate, so the permission, both on-screen gates and the "queued, not
done" progress view all become load-bearing rather than decorative. A registrar cannot read an exit
code, cannot see a console buffer, and cannot be told "it worked" about a batch that has not drained.

## Verified state (re-derive before trusting)

| Thing | State |
| --- | --- |
| M1 config controllers | shipped — `ClassLevelProgressionController`, `ClassLevelArmProgressionController` |
| M2 graph | shipped — `App\Services\ProgressionGraph`, `ClassLevelArmProgression` |
| M3 reassignment | shipped, plus bulk cohort reassignment |
| `academics:run-end-of-term` | exists, 189 lines |
| `academics:run-end-of-year` | exists, 262 lines |
| **`RolloverPlanner`** | **DOES NOT EXIST** |
| `academics.rollover` permission | does not exist, by decision |

## THE FINDING THAT SHAPES SLICE 1

`RunEndOfYear.php:94` runs the cycle gate by invoking **another command**:

```php
if ($this->call('academics:validate-progression', ['--school' => $schoolId]) !== self::SUCCESS) {
    $this->error('Refusing to queue: the progression graph is not acyclic (reported above).');
    return self::FAILURE;
}
```

The result is an **exit code**, and the ring is printed to a console buffer.

B4 requires the UI to render *"`academics:validate-progression` output, **naming the ring**, with a
link to B1 to fix it"*. **An exit code cannot name a ring.** A controller that called this the same
way would have a boolean and a buffer it cannot show a registrar — and the obvious workaround
(`Artisan::call` + `Artisan::output()`, scraping console text) is the same defect B4 already forbids
when it says the controller must not shell out.

The fix is already half-built. Verified against the files, not remembered:

```php
// app/Services/ProgressionGraph.php
public static function findCycle(int $schoolId, ?int $candidateFrom = null, ?int $candidateTo = null): ?array
// @return list<string>|null   — the ring, as data

// app/Console/Commands/ValidateProgressionGraph.php  (handle)
$cycle = ProgressionGraph::findCycle((int) $schoolId);   // per school, then formats + exit code
```

So the walk is ALREADY one definition with two callers — the config screen (via
`ProgressionGraph::cycleIfPointed`) and this command. The defect is narrower than "the walk is
duplicated": it is that **`RunEndOfYear` reaches the walk through the command instead of through the
service**, and so can only ever have the exit code.

Therefore:

- `RolloverPlanner` calls `ProgressionGraph::findCycle` **directly** and returns the ring;
- `RunEndOfYear` becomes a thin caller of the planner and stops invoking a sibling command;
- the controller gets the same structured ring the CLI gets.

That command-calls-command hop is removed as a **consequence** of the extraction, not as a separate
tidy-up — do not leave it in place and add a second path beside it.

### The correctness criterion, stated precisely — and one thing it must NOT be read as

**The criterion:** after slice 1 there is exactly one code path that decides *what a rollover would
do and whether it may run*, and both `RunEndOfYear`/`RunEndOfTerm` and `RolloverController` reach it
through `RolloverPlanner`. No second selection query, no second gate evaluation, no `Artisan::call`.

**What it must not be read as:** "`academics:validate-progression` should also call
`RolloverPlanner`." It should not. That command validates the graph for **every school** (or one
named by `--school`) as a standalone config check — it is not scoped to a rollover and has no
session, no term, no selection. Routing it through a rollover planner would widen the planner's job
to fit a caller that does not want it. It stays a thin presenter over `ProgressionGraph`, which is
already the shared definition and already satisfies "one walk, two callers".

The shared thing is the **walk** (`ProgressionGraph`, already shared) and the **plan**
(`RolloverPlanner`, being extracted). Those are two seams, not one, and collapsing them would create
the coupling the criterion is trying to prevent.

---

## Slice 1 — `RolloverPlanner`, no UI

`App\Services\RolloverPlanner`. Both commands become thin callers; **their observable behaviour and
their existing tests must not change.**

1. **Selection**, one method per kind — the term walk currently at `RunEndOfTerm::activeCurricula`
   (:127) and the year walk at `RunEndOfYear::selectFinalSlotCurricula` (:169). Move them; do not
   re-derive them.
2. **Both gates return structured results, never exit codes**:
   - *cycle* (year only) → the ring from `ProgressionGraph::findCycle`, or null;
   - *CCM in a final slot* → the offending curricula, by id (`RunEndOfTerm:70`, `RunEndOfYear:106`).
3. **De-duplicate the two helpers that are already copied verbatim**: `resolveOperator`
   (`RunEndOfTerm:135` / `RunEndOfYear:222`) and `warnIfBatchStillDraining` (`:172` / `:249`). Two
   copies of a guard is a guard that will drift.
4. **The planner plans; it does not dispatch.** `Bus::batch` stays in the caller
   (`RunEndOfTerm:109`, `RunEndOfYear:144`), so a preview is structurally incapable of dispatching —
   the property slice 2's tests assert, made true by construction rather than by remembering.
5. Batch naming (`rollover:{kind}:school:{id}:…`) is read by the progress view, so pin the format in
   a test now, before a second reader exists.

**Prove it:** the existing command tests pass **unmodified**. If one needs changing you have changed
behaviour — stop and report. Add planner-level tests for each gate returning its data.

**Watched red:** break each gate's predicate in the planner and confirm the *command* test reds — if
it does not, the command was never the thing under test and the extraction has silently dropped a
guard.

## Slice 2 — controller, permission, screen

6. **`academics.rollover` is created here**, and only here: the plan's rule is *"no permission exists
   until something checks it"* — M4 is the first thing that checks it. Full filing: enum case,
   `PermissionGroup`, `grantsMap()`, and a convergence migration if the RBAC path needs one. Confirm
   against `RbacSeeder` rather than assuming.
7. **Routes** (`RolloverController`, **no** artisan invocation anywhere in it):

```
POST /api/rollover/end-of-term/preview     → the plan, no dispatch
POST /api/rollover/end-of-term             → --commit equivalent
POST /api/rollover/end-of-year/preview
POST /api/rollover/end-of-year
GET  /api/rollover/batches                 → progress
```

8. **The surface says "queued", not "done".** `--commit` dispatches a batch and returns; the
   migration happens as workers drain. Progress reads `job_batches` (`total_jobs`, `pending_jobs`,
   `failed_jobs`, `finished_at`, `cancelled_at`) and shows **queued N / done X / failed Y**, with the
   explicit line *"the rollover is not finished until this batch drains — do not change the current
   session yet."* A registrar who reads "done" and switches session mid-drain is the failure this
   wording exists to prevent.
9. **Both gates render as on-screen errors, not exit codes** — the cycle naming the ring with a link
   to B1; CCM listing the offending curricula with a link to the CCM move screen. This is what
   slice 1's structured returns are for.
10. Re-running while a batch drains is safe but wasteful — warn, as the commands do. A partially
    failed batch needs re-queuing, so **the commit action stays available**; do not disable it on
    `failed_jobs > 0`.

**Tests:** `Bus::fake()` + `assertNothingBatched()` on **each** gate; preview/commit parity
(the same selection, one dispatching and one not); the batch view reporting the counts it reads.
Permission: a runtime 403 arm — `ci-authz-lint` only scans for commented-out checks against a frozen
baseline and does **not** verify a route is gated, so the runtime arm is the only pin.

**Do not** derive a test's input from the value under test (CLAUDE.md) — if a batch-size or
selection limit appears, pin the value and use a literal payload.

## Drive

Required, and the operator is the point of the milestone. Runbook per
`docs/handoff/drive-runbooks/`, driven as a **registrar-permissioned seat**, not as a developer:
preview showing the plan with nothing dispatched; each gate rendering its error with its link; a
commit showing queued counts and the do-not-switch-session warning; and the progress view while a
batch is genuinely mid-drain — the state most likely to be wrong and least likely to be looked at.

## Not in scope

`is_ccm` participation editing (decision 5), the old setup tabs' error handling (decision 4 — ticket
exists), and anything in the rollover **jobs** themselves. M4 is a surface over machinery that
already works.
