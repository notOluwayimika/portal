# TICKET — `GrantsConvergenceLintTest` nondeterminism: the cause is NOT established, but two of the three standing explanations are now dead and one mechanism is bite-proved

**Status:** open. Investigation only — nothing was changed, nothing was baselined, nothing was
committed, and no run was retried for green.

**Supersedes the causal story in**
[`grants-convergence-lint-test-is-self-poisoning-against-git-auto-gc.md`](grants-convergence-lint-test-is-self-poisoning-against-git-auto-gc.md).
That ticket's operative instructions (do not baseline these arms, do not retry until green) stand
unchanged. Its **`gc --auto` pruning mechanism is now falsified outright**, not merely doubted — see
§2.1, which discriminates it by the text of the error message the run actually produced.

**The honest headline: I could not reproduce the failure in 86 executions of the file (3,096
arm-executions), and I did not find the root trigger.** What I did establish is the mechanism that
*converts* a transient subprocess failure into the exact red that was seen, and a large set of things
that are definitively not the cause.

---

## 1. What was measured

All runs on `feat/guardian-merge-command` unless stated, against a private database
(`portal_testing_flake` / `portal_testing_flake2`) so as not to collide with the agent working in the
same tree. Machine state during the runs: load average 10–15 on 10 cores, swap 6.3–6.6 GB used of
7.2 GB — i.e. *already* under sustained memory pressure, and green throughout.

| Condition | Runs | Arm-executions | `GrantsConvergenceLintTest` failures |
| --- | --- | --- | --- |
| Full suite, branch, in-suite context | **6** | 216 | **0** |
| File alone, branch, repeated | **60** | 2,160 | **0** |
| File alone, **`staging`**, in a separate clone | **20** | 720 | **0** |
| **Total** | **86** | **3,096** | **0** |

Full-suite wall times: **558, 566, 593, 609, 616, 623 s**. The `bin/quality` artefact from
2026-08-16 (`$TMPDIR/quality-runs/pest-20260816-232712-32430.log`) records **523.90 s**.

The two failing runs recorded in the prior ticket were **~1745 s** and **~3101 s** — **2.8× and 5.0×
the observed norm.** That is the single largest signal in the whole dataset and it is discussed in §3.

Rate in the wild, from the prior ticket's own record: **2 red in 6 consecutive full-suite runs** on
that branch on 2026-08-17. Rate under this investigation: **0 red in 86.** The two figures are not
reconcilable by chance alone at any comfortable confidence, which is itself the finding: **the
failure depends on a condition that was present that day and is not present now.**

### A note on the two artefact files that survive

`$TMPDIR/quality-runs/` holds only **two** runs (both 2026-08-16), and *both are clean* — 1669
testcases, 7 failures each, byte-identical sets, exactly the seven entries in
`tests/ratchet-baseline.txt`. **Neither failing run left an artefact.** `bin/quality:262-275` keeps
the last 20, so the failing runs were bare `./vendor/bin/pest` invocations, not gate runs. The ADR
0053 remedy — keep artefacts per run — therefore did **not** capture this occurrence. That is worth
recording on its own.

---

## 2. What is ruled OUT, and how

### 2.1 `git gc --auto` pruning the fixture commits — FALSIFIED, decisively

The prior ticket attributed `NOT LINTED — … the revision is unreachable` to gc pruning the
unreferenced fixture commits. The cold reviewer doubted it on `gc.pruneExpire` grounds. It can now be
killed outright, by discriminating on the message the lint emits, because **the two situations
produce different text**:

```
$ php bin/ci-grants-convergence-lint.php 0000…0001 0000…0002
grants-convergence-lint: NOT LINTED — could not resolve base '0000…0001' / head '0000…0002'
to a commit. Pass a valid base ref.
```

```
$ T=$(git mktree </dev/null); C=$(git commit-tree $T -m probe)
$ php bin/ci-grants-convergence-lint.php $C $C
grants-convergence-lint: NOT LINTED — database/seeders/RbacSeeder.php is unreadable at head
6a01e3f. Either the file moved or the revision is unreachable; this gate cannot look, so it will
not be green.
```

**A commit that does not exist gives "could not resolve … to a commit". A commit that exists but
whose tree lacks the file gives "unreadable at head `<sha>`".** The message recorded from the real
failure was the second form, naming a resolvable sha (`4af879e`). So on 2026-08-17 **the fixture
commit existed and resolved; its tree was missing the seeder.** A pruned object cannot produce that
message. The pruning story is not merely unsupported — the evidence that was offered for it is
evidence against it.

### 2.2 The loose-object threshold as a sufficient cause — ruled out

`git count-objects -v` on this repository moved from **7,294 → 7,594** loose objects across this
investigation, against an unset `gc.auto` (default 6,700), with the same stray
`.git/objects/pack/tmp_pack_rA7yBY` present throughout. All 86 runs above were green in that
condition. This reproduces the cold reviewer's `--no-hardlinks` clone result (7,294 objects, 36/36
green) in the *original* repository rather than a copy. Crossing the threshold is neither sufficient
nor, on this evidence, relevant.

### 2.3 The git plumbing itself being fragile — ruled out at 2,000 replications

A standalone harness replicating `gclBlob`/`gclCommit`'s exact call chain — `hash-object -w` →
`update-index --add --cacheinfo` → `write-tree` → `commit-tree` → `show <rev>:<path>` → `rev-parse
--verify` — against the real `.git`, **checking every exit status the test discards**:

```
8 concurrent processes × 250 iterations = 2,000 replications
iterations=250 failures=0   (×8)
```

Zero failures, run while two Pest suites were executing. Under ordinary contention these calls do not
fail.

### 2.4 Branch-relatedness — ruled out on content and on measurement

The branch touches none of the four load-bearing files, and their blobs are **byte-identical** to
`staging`:

```
tests/Feature/Rbac/GrantsConvergenceLintTest.php  ed98058… vs ed98058…
bin/ci-grants-convergence-lint.php                4561f99… vs 4561f99…
database/seeders/RbacSeeder.php                   ef90217… vs ef90217…
app/Enums/Permission.php                          1e0c465… vs 1e0c465…
```

`git diff --name-only staging...HEAD` filtered to those paths returns nothing; the branch's diff is
two console commands, one service, one test file and docs. And empirically: **20/20 green on
`staging`** in a separate clone. This is not branch-related.

### 2.5 Shared state or ordering contamination between tests — ruled out

The file registers no `RefreshDatabase` (global `->use(RefreshDatabase::class)` is commented out at
`tests/Pest.php:27`) and touches no database. Each arm builds its own base and head commits with its
own `tempnam` scratch index. The only state shared with the rest of the suite is the git object
database, which is content-addressed and append-only here. In-suite (216 arm-executions) and solo
(2,160) results are identical: green. PHPUnit sorts files and `phpunit.xml` sets no random
`executionOrder`, so ordering is fixed anyway (already disproven for ADR 0053).

---

## 3. What IS established

### 3.1 The failure-shaping mechanism is the discarded exit statuses — BITE-PROVED

`gclBlob` (`tests/Feature/Rbac/GrantsConvergenceLintTest.php:93`) and `gclCommit` (`:112-135`)
discard the exit status of **every** git call — `read-tree` (`:116`), `update-index --add
--cacheinfo` (`:120`), `update-index --force-remove` (`:124`), `write-tree` (`:127`), `commit-tree`
(`:135`) — and read only `->output()`. A failed `hash-object` yields `''`, which makes the
`--cacheinfo` argument `100644,,<path>`, which fails, which drops the file from the scratch index —
and `write-tree`/`commit-tree` then succeed and return a **perfectly valid commit whose tree lacks
the file**.

Proved by planting the regression in an isolated clone (never in the repository), poisoning
`hash-object` at a 1-in-12 rate:

```
BEFORE (clone, unpoisoned): tests 36 passed 36 failed 0 errors 0
AFTER  (clone, poisoned):   tests 36 passed 31 failed 5 errors 0
  FAIL: 'grants-convergence-lint: NOT LINTED — database/seeders/RbacSeeder.php is unreadable at
         base e10cb8a. Either the file moved or the revision is unreachable; …'
  FAIL: 'grants-convergence-lint: NOT LINTED — no `case NAME = 'value';` parsed from
         app/Enums/Permission.php at head d5bd0c2. …'
  FAIL: Failed asserting that '' contains "declares @converges auditor activity_log.view".
```

That is the observed red, reproduced from a single swallowed exit status — including a **random
subset of arms each run**, which is the property the two real runs showed (12, then a different 9).
**The cold reviewer's alternative hypothesis is correct.** The clone was discarded; the repository's
copy of the test file was never modified.

### 3.2 The second shape is a literal subprocess timeout, and the timeout is 60 s by default

The prior ticket records
`Illuminate\Process\Exceptions\ProcessTimedOutException: … exceeded the timeout of 60 seconds`.
`Illuminate\Process\PendingProcess::$timeout = 60` (`vendor/laravel/framework/src/Illuminate/Process/PendingProcess.php:46`)
and no arm calls `->timeout()`. A lint invocation costs ~0.3 s in the measured norm (42 `gclRun`
calls inside a 19–21 s file). **Reaching 60 s is a ~200× slowdown of a single subprocess.** That is
not CPU contention; that is thrash, a stalled fork, or a blocked filesystem.

### 3.3 This is the only file in the suite that can express such an event

```
$ grep -rln 'Process::path|Process::run|Facades\Process' tests/
tests/Feature/Rbac/GrantsConvergenceLintTest.php
```

**One file. The whole suite spawns subprocesses from nowhere else.** And it spawns a great many:
42 `gclRun` invocations (each booting PHP and issuing up to 12 `shell_exec` git calls —
`bin/ci-grants-convergence-lint.php:181`) plus 48 `gclCommit` fixture builds of ~4 git calls each —
order **700–900 process spawns inside ~20 seconds**.

This is the structural fact that explains the otherwise suspicious detail — *why only this file went
red, on a branch that touches nothing near it*. It requires nothing RBAC-specific. Any machine-wide
subprocess-latency or subprocess-failure event lands here first, hardest, and **nowhere else**,
because there is nowhere else for it to land.

### 3.4 The load correlate, stated as correlation and not as cause

2.8× and 5.0× the normal suite wall time on the two failing runs. Both observed failure shapes
(a 60 s process timeout; a git write that failed without being noticed) are what a machine in severe
resource distress produces. I regard this as the strongest available lead. **I did not prove it.**
I could not induce a 200× subprocess slowdown without doing damage to the machine I was measuring,
and at the ambient pressure that was available — load 10–15, swap 93 % full — 86 runs stayed green.

---

## 4. Is this ADR 0053? — No, but they may share a root

**Distinct as symptoms.** ADR 0053's 23 failures clustered in permission- and seed-heavy *database*
tests — `SuperAdminAuthorityTest` (6), `SharedPermissionsTest` (5), `SeededPermissionCoverageTest`
(5). Those spawn no subprocesses. This occurrence is confined to the one file that does, and its two
shapes are a process timeout and a git read that returned nothing. The failure sets are disjoint and
the machinery is disjoint.

**Possibly identical as a cause.** Both are unexplained nondeterminism, on the same machine, on
byte-identical code, resistant to reproduction (ADR 0053: one failure in twelve runs; here: none in
eighty-six). If the root is a machine-wide resource event, two disjoint symptom sets are exactly what
one would expect — the DB-heavy files fail one way, the subprocess-heavy file another. Stated as a
possibility, not a conclusion.

ADR 0053's practical ruling applies unchanged and is the reason this ticket exists rather than a
green tick: **a red cannot be told from a flake by looking at it, and retrying until green is
indistinguishable from fixing.**

---

## 5. One contamination mechanism found along the way, and it is real

During runs 1–3 the suite's **test count changed between runs — 1705, then 1697** — and
`GuardianMergeTest` produced wholly different failures each time (`--consolidate-login option does
not exist`, `Undefined array key "consolidating"`). Cause: another agent was editing
`app/Services/GuardianService.php`, `app/Console/Commands/MergeGuardians.php` and
`tests/Feature/Guardian/GuardianMergeTest.php` **while the suite ran**, and running Pest in the same
tree.

**A shared working tree makes "two consecutive runs, different failure subsets" an expected outcome
rather than a mystery**, and it should be excluded before any future flake is investigated. It
cannot explain *this* red — the four load-bearing files were untouched, and only
`4a EXPLOITED` (`:467-468`) reads the working tree at all, so at most one arm is exposed, not twelve.
But it deserves its own line in any flake triage checklist. Compare the existing ticket
`quality-gate-is-not-safe-to-run-from-two-trees.md`.

*(Measurement caveat, for honesty: my `portal_testing_flake` database was created with the server
default collation, so `SchemaConventionsTest` fails in all six of my full-suite runs. That is an
artefact of my database, not a finding, and it is excluded from every count above.)*

---

## 6. What would close this

Ordered by confidence, not convenience.

1. **Check the exit status of every `$git()` call in `gclCommit`/`gclBlob` and fail loudly.** This is
   no longer a "cheap and probably good" suggestion — §3.1 proves it is *the thing that hid the
   cause*. Had the statuses been checked on 2026-08-17, the run would have named the failing git
   command and its stderr, and this ticket would have an answer instead of an exclusion list. **Do
   this first, independently of everything else.**
2. **Set an explicit `->timeout()` on both helpers and assert on it.** A machine that is too slow
   should produce a message that says so, not an assertion about grant convergence. The current
   60 s default is inherited, not chosen.
3. **Record machine state alongside suite artefacts** — wall time, load average, `vm.swapusage`,
   `git count-objects -v` — in `bin/quality`'s artefact directory. Every hypothesis in this ticket
   and in ADR 0053 is starved of exactly that. It costs three lines.
4. **Make the failing runs leave artefacts at all.** Both reds on 2026-08-17 were bare `pest`
   invocations and left nothing; the ADR 0053 remedy only covers `bin/quality`.
5. Anchoring fixture commits behind a temporary ref, and `-c gc.auto=0`, are **no longer justified by
   this evidence** (§2.1, §2.2). Do not spend on them to fix this.

## Not this ticket

The seven entries already in `tests/ratchet-baseline.txt`. **Do not add these arms to it** — they are
green in 3,096 consecutive arm-executions across two branches, and baselining them would retire a
real gate to work around something nobody has identified.
