# feat/landed-check — a command that answers whether the remote contains what was reviewed

Branch `feat/landed-check`, off `origin/staging` at `7894c39` (the #266 merge).

Closes the detection proposed in
`docs/handoff/tickets/a-merged-pull-request-does-not-mean-the-branch-head-merged.md`. Three files are
new — `bin/landed`, `tests/Feature/Quality/LandedCheckCoverageTest.php`, this report — and two are
amended: `CLAUDE.md` § Workflow and the ticket itself.

---

## 1. The two instances, re-derived

> **§8 SUPERSEDES THIS SECTION'S ATTRIBUTION OF THE DETECTION, and §9 supersedes its account of
> the fetch.** Check 4 does not detect anything; it explains. §2 and §4 carried this banner and
> §1 did not, which left the report's own first section still crediting a detector that had been
> demoted three sections later.

The brief said to re-derive both against the merged history and to write what was found if a sha did
not say what the brief said it said. Both shas hold. **What has changed since the ticket was written
is that neither instance has closed**, and `origin/staging` has moved on past both.

### Instance A — PR #265's merge commit does not carry the branch head

```
$ git log -1 --format='%H %P' 9849689
98496897b84027a1dc7160a76e717a948cde02e7 6545bcc9d08cb02e43016939255ca4f66e1fa03b 37500c8fa9ad2b59dd457448ff6f5e0158d2267f
```

Second parent `37500c8` (`Merge branch 'staging' into feat/u6-bulk-run-screen`), not the branch head
`6e770ae`. As the ticket recorded.

Against `origin/staging` at `7894c39` — the current remote, two merges past #265 — the gap is
unchanged:

```
$ git rev-parse origin/staging
7894c39327fa0821a96254c2f66a2e171b343417

$ git log --oneline origin/staging..origin/feat/u6-bulk-run-screen
6e770ae docs(finance): the Referer mechanism was already written down, and the drive now prints two tables
c7ec9a6 fix(finance): the run tables are fail-closed, and three documents stop claiming more than was measured

$ git merge-base --is-ancestor c7ec9a6 origin/staging && echo YES || echo NO
NO

$ git show origin/staging:config/rbac.php | sed -n "/'fail_closed_models'/,/^    \],/p" | grep -c "::class"
10
$ git show origin/feat/u6-bulk-run-screen:config/rbac.php | sed -n "/'fail_closed_models'/,/^    \],/p" | grep -c "::class"
12
```

`fail_closed_models` stands at ten on the remote and twelve on the branch. `BulkInvoiceRun` and
`BulkInvoiceRunRow` are still unregistered on `origin/staging`.

### Instance B — the local merge is still unpushed, and local `staging` has since diverged

The ticket recorded local `staging` as *ahead by 2* at merge `5a3f212`. It is now **diverged**: #266
merged on the remote in the meantime, so the same branch is ahead by three and behind by three.

```
$ git rev-parse staging
5a3f21200f5f2e1685e655e4b374ad3d3dc65230

$ git log --oneline origin/staging..staging
6e770ae docs(finance): the Referer mechanism was already written down, …
c7ec9a6 fix(finance): the run tables are fail-closed, …
5a3f212 Merge feat/u6-bulk-run-screen: the two commits PR #265 left behind

$ git log --oneline staging..origin/staging
8b3b680 docs(tickets): ticket 1 cited a prune line and a step number wrong when it wrote them
98995b6 docs(tickets): no parallel path for the suite, a session on /api is inert, …
```

Landing the fix is a separate push and is not part of this branch. This branch builds the thing that
refuses to call it landed until it is.

### The script, run against the live state

```
$ ./bin/landed feat/u6-bulk-run-screen
✗ origin/feat/u6-bulk-run-screen has 2 commit(s) that origin/staging does not have
    6e770ae docs(finance): the Referer mechanism was already written down, and the drive now prints two tables
    c7ec9a6 fix(finance): the run tables are fail-closed, and three documents stop claiming more than was measured
✗ your local staging has 3 commit(s) that are not on origin
    5a3f212 Merge feat/u6-bulk-run-screen: the two commits PR #265 left behind
    6e770ae docs(finance): …
    c7ec9a6 fix(finance): …
  a merge nobody else can see has not landed. Push staging.
ℹ your local staging is 3 commit(s) behind origin — normal; `git pull --ff-only` when you need them
✗ PR merged 37500c8f, branch head is 6e770aea, 2 commit(s) between them
  merge 98496897 — "Merge pull request #265 from notOluwayimika/feat/u6-bulk-run-screen" (subject read from the merge commit)

✗ NOT landed (3 check(s) failed) — do not report feat/u6-bulk-run-screen as merged.
EXIT=1
```

All three failing checks fire on the real repository. **Instance A is detected by check 1** — the
branch holds two commits the target does not have, and check 1 names them; check 4 also printed a
line here, but §8 retracts the claim that it detected anything, and §7 is the measurement that
retracted it. Instance B is detected by check 2, and the behind-line is printed as information
alongside them. On a healthy branch (`docs/test-infrastructure-tickets`, merged as #266) check 1
passes, check 4 names the merge, and only check 2 fires — the same instance B from a different
branch.

---

## 2. What each check does

> **§8 SUPERSEDES CHECK 4 IN THIS SECTION.** Check 4 was demoted from detector to explainer
> after §7. What follows describes the first version; the "How check 4 finds the merge"
> subsection in particular no longer describes the script. Checks 0–3 are unchanged.

`bin/landed <branch> [target]`, target defaults to `staging`. Bash, alongside `bin/quality` and
`bin/quality-promote`; the PHP convention in `bin/` is for tree lints and this is a question about
refs.

**The one mutation is `git fetch origin --prune`**, stated in the docblock and in `--help`. Nothing
else is written. It never pushes, merges, checks out, resets or writes a branch ref, and it never
calls `gh` — every fact it reports is answerable from refs, and `gh` can be absent, unauthenticated
or rate-limited.

Unlike `bin/quality` and `bin/quality-promote`, it does **not** `cd` to its own repo root: it operates
on the repository containing the current directory. That is what makes it testable against planted
repositories, and it is stated in the docblock.

| # | What it compares | Outcome |
| - | ---------------- | ------- |
| 0 | `git fetch origin --prune` | failure → **exit 2** with "could not reach origin — this check is meaningless offline". Never exit 0. |
| 1 | `origin/<target>..origin/<branch>` | non-empty → fail; the commits are listed. Instance A. |
| 2 | `origin/<target>..<target>` | non-empty → fail, "your local `<target>` has N commits that are not on origin"; listed. Instance B. Skipped when no local branch of that name exists. |
| 3 | `<target>..origin/<target>` | non-empty → **information**, not a failure. Being behind is normal; being ahead is the defect. |
| 4 | second parent of the merge on `origin/<target>` vs `origin/<branch>` | mismatch → fail, "PR merged `<sha>`, branch head is `<sha>`, N commits between them", gap listed. |

Exit codes: `0` all checks pass, `1` a check failed, `2` could not determine (fetch failed, no such
branch, not a git repository — and, since §9, a shallow clone or an unreadable shallow probe).
**2 is not collapsed into 1.**

> **"no common ancestor" was listed here and is wrong.** Measured: an orphan branch exits **1**,
> reported as one outstanding commit. The `merge-base` call that produced that exit-2 path was
> removed with the redesign in §8 and the sentence was not updated with it.
>
> **Corrected rather than re-added, and the reason is the distinction the exit codes carry.** An
> orphan branch is not undecidable: the target plainly does not contain it, check 1 names
> everything on it, and "not landed" is the true answer. Exit 2 means *I could not tell*. Spending
> it on a case the script can tell would weaken the one signal this whole script is built around.
> Exit 2 is reserved for a graph or a remote it could not read.

### How check 4 finds the merge

The merge that took the branch is the one on `origin/<target>` whose **second parent equals
`merge-base(origin/<target>, origin/<branch>)`**. With a healthy merge that merge-base *is* the branch
head; with a stale one it is the older commit the merge actually took, which is exactly the quantity
to report. One `git rev-list --merges --parents` and one `awk` — newest first, first match wins, no
cap on how far back it looks.

The obvious alternative — "the newest merge whose second parent is an ancestor of `origin/<branch>`" —
is wrong, and arm (e) is planted to hold that line. A branch cut from the target *after* some other
branch merged carries that other branch's head in its own ancestry, so the loose form matches an
unrelated merge and reports a stale-head mismatch for a branch that simply never merged.

Where no such merge exists, two further outcomes are distinguished rather than forced into one:

- **contained anyway** — `origin/<branch>` is an ancestor of `origin/<target>`. A fast-forward, or a
  branch that merged the target back into itself after its own PR closed. Reported, exit 0, and the
  verdict line says "with no merge commit to check" instead of the ordinary "the merge took the
  reviewed head", because there was no merge to check and claiming one would be asserting more than
  the checks established.
- **not contained** — "no merge of `<branch>` found on `origin/<target>`", exit 1. A squash or rebase
  merge lands here too: it leaves neither a merge commit nor ancestry, and the script says so in the
  output rather than leaving the reader to infer it.

### The honest limit, as written in the docblock

A green proves `origin/<target>` contains every commit on `origin/<branch>`, and that the merge took
the head `origin/<branch>` now points at. It proves nothing about whether the merge was correct,
whether the reviewer read the right diff, whether the merged tree passes `bin/quality`, or whether the
branch should have been merged at all. It answers one question and no others.

---

## 3. Where it is wired, and where it is not

**Not in `bin/quality`.** `.githooks/pre-push:3-20` and `phpunit.xml:34-39` describe a floor that is
deliberately self-contained and offline. A network call inside the per-push gate would make every push
depend on GitHub being reachable, and the failure this catches happens *after* a merge — a moment the
per-push hook never observes. It would be a step that cannot see the thing it is named for.

**Not a `post-merge` hook.** Local-only, and it never fires for a web merge, which is how instance A
happened.

**Documented in `CLAUDE.md` § Workflow**, where `.githooks/pre-push:20` already points readers. One
paragraph, placed directly after the branch-flow paragraph it qualifies.

**The ticket is amended** with a closing section: the check's name, the four checks, the eight arms,
what a green does not mean, and a statement that the ticket's stale-head-versus-late-push question is
already answered in the ticket itself (from `gh pr view 265` and commit timestamps — the commits were
written about four hours after the merge) and that `bin/landed` does not answer it and could not. It
reads refs; refs carry no timeline. It detects the outcome, not the cause.

---

## 4. The coverage test

> **§8 SUPERSEDES THE ARM TABLE AND THE RAW OUTPUT BELOW FOR ARMS (b) AND (e).** Both were
> rewritten for the redesigned check 4, and arm (i) was added. Arms (a), (c), (d), (f), (g),
> (h) and the source-shape test are unchanged.

`tests/Feature/Quality/LandedCheckCoverageTest.php`, `uses()->group('arch')`, shaped after
`tests/Arch/SqlClockLintCoverageTest.php`.

Each arm builds a real repository under `mktemp -d` with a bare repository wired as `origin` **by
path**, so `git fetch origin` works and every arm runs offline. Nothing is planted inside the
repository the suite lives in — unlike the sql-clock coverage test, which plants into the real tree —
so a leaked fixture cannot become a committable untracked file.

The fixtures are config-isolated: `GIT_CONFIG_GLOBAL` and `GIT_CONFIG_SYSTEM` point at `/dev/null` and
`HOME` is redirected into the temp directory, so a developer's signing key, commit template or
`core.hooksPath` cannot change what the arms assert.

Every arm asserts **the exit code and the message**. Three of the four failure modes exit 1, so an
exit-code-only arm would pass while the script reported the wrong one. Arms (b), (c) and (e)
additionally assert the *number* of failed checks, for a reason recorded in §5.

A ninth test asserts the shape of the source: with comments and the usage heredoc stripped,
`bin/landed` contains no `git push`, `git merge `, `git checkout`, `git reset`, `git branch` or
`git update-ref`, and does still contain `git fetch --prune origin` and `git merge-base` — the
non-vacuity guard, so a stripping regex that ate the file cannot pass. It is a source assertion and
says so in its own comment: it pins the shape, the arms prove the behaviour.

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Quality/LandedCheckCoverageTest.php
{"tool":"pest","result":"passed","tests":9,"passed":9,"assertions":57,"duration_ms":7139}
```

### Raw output of every arm

Reproduced by planting each fixture and running the real script; shas differ per run because the
fixtures are built fresh.

```text
═══ ARM (a) healthy ═══
LANDED?  feat/x → staging

✓ fetched origin (--prune)
  origin/feat/x = c13405b0 · origin/staging = 87b257a8

✓ origin/staging contains every commit on origin/feat/x
✓ your local staging is not ahead of origin
✓ the merge on origin/staging took the head origin/feat/x points at (c13405b0)
  merge 87b257a8 — "Merge pull request #N from o/feat/x" (subject read from the merge commit)

✓ landed — origin/staging contains origin/feat/x, and the merge took the reviewed head.
This says nothing about whether the merge was correct or the merged tree is green.
EXIT=0

═══ ARM (b) instance A — merge took an earlier sha ═══
LANDED?  feat/x → staging

✓ fetched origin (--prune)
  origin/feat/x = cd94249e · origin/staging = f0b24759

✗ origin/feat/x has 2 commit(s) that origin/staging does not have
    cd94249 and its documentation
    67dc834 the fix that never landed
✓ your local staging is not ahead of origin
✗ PR merged 8f333ab9, branch head is cd94249e, 2 commit(s) between them
  merge f0b24759 — "Merge pull request #N from o/feat/x" (subject read from the merge commit)
    cd94249 and its documentation
    67dc834 the fix that never landed

✗ NOT landed (2 check(s) failed) — do not report feat/x as merged.
EXIT=1

═══ ARM (c) instance B — local target ahead of origin ═══
LANDED?  feat/x → staging

✓ fetched origin (--prune)
  origin/feat/x = ee48d9d0 · origin/staging = c8cff003

✓ origin/staging contains every commit on origin/feat/x
✗ your local staging has 1 commit(s) that are not on origin
    409352f a merge nobody else can see
  a merge nobody else can see has not landed. Push staging.
✓ the merge on origin/staging took the head origin/feat/x points at (ee48d9d0)
  merge c8cff003 — "Merge pull request #N from o/feat/x" (subject read from the merge commit)

✗ NOT landed (1 check(s) failed) — do not report feat/x as merged.
EXIT=1

═══ ARM (d) local target behind origin ═══
LANDED?  feat/x → staging

✓ fetched origin (--prune)
  origin/feat/x = e4223bc9 · origin/staging = 8e609721

✓ origin/staging contains every commit on origin/feat/x
✓ your local staging is not ahead of origin
ℹ your local staging is 2 commit(s) behind origin — normal; `git pull --ff-only` when you need them
✓ the merge on origin/staging took the head origin/feat/x points at (e4223bc9)
  merge 8e609721 — "Merge pull request #N from o/feat/x" (subject read from the merge commit)

✓ landed — origin/staging contains origin/feat/x, and the merge took the reviewed head.
This says nothing about whether the merge was correct or the merged tree is green.
EXIT=0

═══ ARM (e) never merged, past an unrelated merge ═══
LANDED?  feat/x → staging

✓ fetched origin (--prune)
  origin/feat/x = 4475eab7 · origin/staging = aa520657

✗ origin/feat/x has 1 commit(s) that origin/staging does not have
    4475eab never merged
✓ your local staging is not ahead of origin
✗ no merge of feat/x found on origin/staging
  (a squash or rebase merge leaves neither a merge commit nor ancestry, and is
   indistinguishable here from a branch that never merged at all)

✗ NOT landed (2 check(s) failed) — do not report feat/x as merged.
EXIT=1

═══ ARM (f) origin unreachable ═══
LANDED?  feat/x → staging

✗ could not reach origin — this check is meaningless offline
fatal: '/var/folders/xn/r_j68kr101n4phk2l4wfkr2c0000gn/T/tmp.TRDYZz1IRO/this-path-does-not-exist.git' does not appear to be a git repository
fatal: Could not read from remote repository.

Please make sure you have the correct access rights
and the repository exists.
EXIT=2

═══ ARM (g) fast-forward, no merge commit ═══
LANDED?  feat/x → staging

✓ fetched origin (--prune)
  origin/feat/x = 434d6200 · origin/staging = 434d6200

✓ origin/staging contains every commit on origin/feat/x
✓ your local staging is not ahead of origin
ℹ no merge commit of feat/x found on origin/staging, but origin/feat/x is contained in origin/staging
  fast-forward, or the branch merged staging back in after its own merge. Check 1 is the signal here.

✓ landed — origin/staging contains origin/feat/x, with no merge commit to check.
This says nothing about whether the merge was correct or the merged tree is green.
EXIT=0

═══ ARM (h) branch deleted from origin ═══
LANDED?  feat/x → staging

✓ fetched origin (--prune)
✗ no such branch origin/feat/x — cannot determine whether it landed
  (a branch deleted at merge is indistinguishable from one that never existed)
EXIT=2
```

---

## 5. The mutations, and which arm caught each

> **§8 CARRIES THE MUTATION MATRIX FOR THE REDESIGNED CHECK 4.** M5, M6, M9, M10 and M12 below
> target code that no longer exists. M1–M4, M7, M8, M11, M13–M15 still hold.

`bin/landed` was broken one line at a time and the whole file re-run. An arm that passes with its
check removed is testing the fixture, not the script.

| Mutation | Arms that went RED |
| -------- | ------------------ |
| M1 · check 1 pinned to pass (`OUTSTANDING="0"`) | (b) (e) |
| M2 · check 1 pinned to fail (`OUTSTANDING="1"`) | (a) (b) (c) (d) (g) |
| M3 · check 2 pinned to pass (`AHEAD="0"`) | (c) |
| M4 · check 3's informational line removed (`if false`) | (d) |
| M5 · check 4's mismatch branch pinned to pass (`if true`) | (b) |
| M6 · check 4's no-merge outcome stops counting as a failure | (e) |
| M7 · failed fetch exits 0 instead of 2 | (f) |
| M8 · the fetch is dropped entirely (`if false`) | (f), source-shape |
| M9 · check 4's contained-anyway branch removed (`elif false`) | (g) |
| M10 · merge match widened from merge-base to "newest merge" | (e) |
| M11 · check 2 skipped entirely (`if false`) | (c) (d) |
| M12 · the two green verdict wordings collapsed into one | (g) |
| M13 · failed fetch exits 1 — unknown collapsed into wrong | (f) |
| M14 · missing branch exits 1 instead of 2 | (h) |
| M15 · the missing-branch check removed (`if false`) | (h) |

M2 is the non-vacuity control for the passing arms: with check 1 pinned to always fail, every arm that
expects exit 0 goes red, so none of them is passing because the script cannot fail.

### Two mutations survived on the first pass, and the arms were strengthened

Recorded because a mutation matrix that only reports catches is a claim, not a measurement.

**M6 survived.** Removing `FAILED=$((FAILED + 1))` from check 4's no-merge branch left arm (e) green.
An unmerged branch always has commits the target lacks, so check 1 fails in that arm too and holds the
exit at 1 on its own, and the message was still printed. There is no way to construct a repository in
which the no-merge failure occurs *without* check 1 also failing — the two are structurally joined. So
the arm now asserts the count in the verdict line: `(2 check(s) failed)` is check 1 plus check 4, and
`(1 check(s) failed)` after the mutation is red. Arms (b) and (c) gained the same assertion.

**M9 and M10 survived.** Neither the contained-anyway branch nor the merge-base discriminator had an
arm at all. Arm (g) was added for the first — a branch landed by `--ff-only`, which also drove a
change to `bin/landed` itself, since the single green verdict line claimed "the merge took the
reviewed head" in a case where there is no merge. Arm (e) was rewritten for the second: its fixture now
merges an unrelated branch `other/y` into `staging` *before* cutting `feat/x`, so `other/y`'s head sits
in `feat/x`'s ancestry and the loose match reports it as `feat/x`'s merge.

**M14's first form was inert** — it appended to a line above the `exit 2` rather than replacing it, so
it changed nothing and nothing went red. That is a defective mutation, not a surviving one. It did
surface that the missing-branch path had no arm; (h) was added, and the corrected M14 and M15 both go
red on it.

---

## 6. What could not be verified

- **That `bin/landed` will be run.** It is a command with an exit code, documented in `CLAUDE.md`, not
  a gate anything invokes. §3 gives the reasons it is not in `bin/quality` or a `post-merge` hook; the
  consequence is that nothing forces it. The ticket's own framing — that the missing thing was a
  command that can refuse — is what this delivers, and no more.
- **Squash and rebase merges.** The script cannot tell a squash-merged branch from one that never
  merged; both reach check 4's not-contained outcome. The output says so. No repository state was
  planted to confirm what a squash merge produces here, because this repository merges with merge
  commits — every merge on `origin/staging` inspected during this work is a `Merge pull request #N`
  commit with two parents.
- **Git versions other than the one on this machine.** The fixtures avoid `git init -b` (2.28+) in
  favour of `symbolic-ref` before the first commit, and use no other recent flag, but this was not
  exercised against an older git.
- **Non-`origin` remotes, and multiple remotes.** `origin` is hard-coded. No arm plants a second
  remote or a differently-named one.
- **The behind-count in arm (d) is 2, not 1.** The fixture rewinds past the merge, which drops both
  the merge commit and the branch commit. The arm asserts the word "behind", not a number.
- **Whether the two commits from instance A should now be landed.** They are still outside
  `origin/staging` and `fail_closed_models` is still at ten entries there. Landing them is a push this
  branch does not make.
- **`bin/landed feat/landed-check` itself.** It cannot report a landing until this branch merges.
  It was run against this branch before merge and the result is in §7 — it did not print what was
  expected, and that finding is recorded there.

---

## 7. Against the live repository

Run on 2026-08-20 after `staging` was corrected: `origin/staging` is `ca12d92` and
`config/rbac.php` there carries four `BulkInvoiceRun` lines — instance A's two commits have landed
and `fail_closed_models` is at twelve entries. Local `staging` is in sync with origin, so checks 2
and 3 are quiet in all three runs.

Raw output, ANSI colour codes stripped and nothing else altered. Nothing is cut.

### `./bin/landed feat/u6-bulk-run-screen staging` — expected exit 0

```text
LANDED?  feat/u6-bulk-run-screen → staging

✓ fetched origin (--prune)
  origin/feat/u6-bulk-run-screen = 6e770aea · origin/staging = ca12d926

✓ origin/staging contains every commit on origin/feat/u6-bulk-run-screen
✓ your local staging is not ahead of origin
✓ the merge on origin/staging took the head origin/feat/u6-bulk-run-screen points at (6e770aea)
  merge 5a3f2120 — "Merge feat/u6-bulk-run-screen: the two commits PR #265 left behind" (subject read from the merge commit)

✓ landed — origin/staging contains origin/feat/u6-bulk-run-screen, and the merge took the reviewed head.
This says nothing about whether the merge was correct or the merged tree is green.
EXIT=0
```

### `./bin/landed docs/test-infrastructure-tickets staging` — expected exit 0

```text
LANDED?  docs/test-infrastructure-tickets → staging

✓ fetched origin (--prune)
  origin/docs/test-infrastructure-tickets = 8b3b680d · origin/staging = ca12d926

✓ origin/staging contains every commit on origin/docs/test-infrastructure-tickets
✓ your local staging is not ahead of origin
✓ the merge on origin/staging took the head origin/docs/test-infrastructure-tickets points at (8b3b680d)
  merge 7894c393 — "Merge pull request #266 from notOluwayimika/docs/test-infrastructure-tickets" (subject read from the merge commit)

✓ landed — origin/staging contains origin/docs/test-infrastructure-tickets, and the merge took the reviewed head.
This says nothing about whether the merge was correct or the merged tree is green.
EXIT=0
```

### `./bin/landed feat/landed-check staging` — expected exit 1, "no merge … found"

```text
LANDED?  feat/landed-check → staging

✓ fetched origin (--prune)
  origin/feat/landed-check = 0b26cef2 · origin/staging = ca12d926

✗ origin/feat/landed-check has 1 commit(s) that origin/staging does not have
    0b26cef feat(quality): a command that answers whether the remote contains what was reviewed
✓ your local staging is not ahead of origin
✗ PR merged 7894c393, branch head is 0b26cef2, 1 commit(s) between them
  merge ca12d926 — "Merge branch 'staging' of github.com:notOluwayimika/portal into staging # Please enter a commit message to explain why this merge is necessary, # especially if it merges an updated upstream into a topic branch. # # Lines starting with '#' will be ignored, and an empty message aborts # the commit." (subject read from the merge commit)
    0b26cef feat(quality): a command that answers whether the remote contains what was reviewed

✗ NOT landed (2 check(s) failed) — do not report feat/landed-check as merged.
EXIT=1
```

---

### RUN 3 DISAGREES WITH THE EXPECTATION, AND THAT IS THE FINDING

The exit code is 1 as predicted, but for the **wrong reason**, and check 4 printed the wrong
outcome. The prediction was `no merge of feat/landed-check found on origin/staging`. What it printed
is a stale-head mismatch:

```
✗ PR merged 7894c393, branch head is 0b26cef2, 1 commit(s) between them
  merge ca12d926 — "Merge branch 'staging' of github.com:notOluwayimika/portal into staging …"
```

`feat/landed-check` has never merged. There is no PR for it on `origin/staging`. Check 4 matched a
`git pull` reconciliation merge and attributed it to this branch.

The mechanism, derived rather than assumed:

```
$ git log -1 --format='%H%n  %P' ca12d926
ca12d9269c41803d901327db58cfbec62b7b8df2
  5a3f21200f5f2e1685e655e4b374ad3d3dc65230 7894c39327fa0821a96254c2f66a2e171b343417

$ git rev-parse ca12d926^2
7894c39327fa0821a96254c2f66a2e171b343417

$ git merge-base origin/staging origin/feat/landed-check
7894c39327fa0821a96254c2f66a2e171b343417
```

`feat/landed-check` was cut from `origin/staging` at `7894c39`. `staging` then diverged locally and
was reconciled with `git pull`, producing `ca12d92` — a merge whose **second parent is `7894c39`**,
the branch's own fork point. Check 4 looks for the merge on the target whose second parent equals
`merge-base(origin/<target>, origin/<branch>)`; here that is `ca12d926`, so it matched, and reported
the fork point as "the head the PR merged".

**This is a false positive in check 4, in the repository, on the first branch it was pointed at.**
The merge-base discriminator was chosen over the looser "newest merge whose second parent is an
ancestor of the branch" precisely to stop an unrelated merge being read as this branch's — §2 says
so and arm (e) plants a fixture for it. That fixture does not cover this shape: in arm (e) the
unrelated merge commit **is** the merge-base, so no second parent equals it and the match correctly
fails. Here the unrelated merge's **second parent** is the merge-base, which is the case the
discriminator does not separate. Any branch cut from a commit that later becomes the second parent
of a reconciliation merge on the target will report this way.

**The script has not been changed to match the prediction.** The instruction for this commit was to
publish what the runs produced and stop. The output above is what `bin/landed` at `0b26cef` does.
The consequence for check 4 is stated and not repaired here.

Runs 1 and 2 match their expectations, exit 0, and check 4 named the right merge in both — `5a3f212`
for `feat/u6-bulk-run-screen` (the local merge that has now been pushed, not PR #265's `9849689`)
and `7894c39` for `docs/test-infrastructure-tickets` (PR #266).

### What is NOT in this section

**The stale-head mismatch — the outcome the script was built for — could not be captured live,
because staging was corrected before the tool was pointed at it. Instance A is reproducible only
from the fixture in arm (b).**

Run 1 is the branch that carried instance A, and it now exits 0: `origin/staging` contains
`6e770ae`, and check 4 finds `5a3f212` whose second parent is that head. The live run demonstrates
the *repaired* state. It does not demonstrate detection of the defect, and the three runs above must
not be read as covering it.

The mismatch text that **does** appear, in run 3, is the false positive analysed above — a branch
that never merged, matched against a reconciliation merge. It is not instance A, it is not the
outcome the script was built for, and it is not evidence that the script detects that outcome.

Arm (b) in `tests/Feature/Quality/LandedCheckCoverageTest.php` is the only place instance A is
reproduced: a branch merged at one sha with two commits pushed to it afterwards, asserting both shas
and "2 commit(s) between them". §5's M5 records that pinning check 4's mismatch branch to pass turns
that arm red, so the arm is load-bearing rather than decorative — but it is a fixture, not the
repository.

---

## 8. Check 4 demoted from detector to explainer

§7 stands exactly as published. It is the evidence for this section: pointing the tool at a real
repository found in one run what fifteen mutations against fixtures did not.

### No matcher can do the job

Instance A and §7's run-3 false positive are **the same topology**. In both, a merge commit on
`origin/<target>` has as its second parent a commit that is an ancestor of `origin/<branch>` and is
not the branch head:

| | the merge | its second parent | the branch |
| --- | --- | --- | --- |
| Instance A | `9849689` (PR #265) | `37500c8` — merged, then the branch advanced | `6e770ae` |
| Run 3 | `ca12d926` (a `git pull` reconciliation) | `7894c393` — the branch's own fork point | `0b26cef2` |

**Git records no branch identity in the DAG.** A commit does not know which branch it was on; a
merge's second parent is a commit, not a branch. So there is no ancestry test that answers "was this
merge a merge *of this branch*" — the two rows above are the same graph shape, and a matcher
sharpened until it passed one would fail the other. The merge-base form was already the sharpened
version: it was chosen over the looser ancestor form specifically to stop this class, and arm (e)
plants a fixture for it. It still got run 3 wrong.

### What check 4 does now

It makes a topological claim **only when `origin/<branch>` is contained in `origin/<target>`** —
which is a fact about the graph rather than a guess about intent.

| State | Check 4 |
| ----- | ------- |
| contained, via a merge whose **second parent is the branch head** | ✓ "the merge on origin/`<target>` took the head origin/`<branch>` points at", merge named |
| contained, no such merge (fast-forward, or branch merged the target back in) | ℹ as before — wording unchanged, it was right |
| **not contained** | ℹ "origin/`<branch>` is not contained in origin/`<target>` — no merge-head claim is made. Check 1 above is the signal." **Asserts nothing. Adds nothing to the failure count.** |

That last row is the fix. An unmerged branch is check 1's business, and check 1 already names the
outstanding commits.

Inside the containment gate the second-parent match and the old merge-base match are the **same
expression** — if `origin/<branch>` is an ancestor of `origin/<target>` then
`merge-base(target, branch)` *is* the branch head. Verified on both live merged branches. The gate
is the fix; the matcher never was.

### What the tool now detects

- **Check 1 detects instance A** — a branch holding commits the target does not have, whatever the
  reason. It named both commits in every arm and in the live run.
- **Check 2 detects instance B** — a merge that exists only on one machine.
- **Check 4 detects nothing. It explains.** A quiet check 4 is not evidence of anything, and on an
  uncontained branch it says so in the output rather than staying silent.

This is written into the docblock under "WHICH CHECK DETECTS WHAT" and into `--help`.

### The diagnosis, demoted to a hint

"PR merged `37500c8`, branch head is `6e770ae`" is still the useful sentence for instance A. It is
not derivable from topology — only from what a merge commit **says about itself**. So for an
uncontained branch the script scans merge subjects on `origin/<target>` for
`Merge pull request #N from <owner>/<branch>` or `Merge branch '<branch>'`, and prints that merge's
second parent as a hint, labelled as read from the message, contributing nothing to the failure
count. Same treatment and same reason as the PR number, which was already labelled that way.

A rename, an edited merge message or a squash defeats it entirely. The docblock says so next to the
existing squash/rebase limit.

### Arm (e) was necessary and insufficient — precisely which shape it misses

Arm (e) killed the loose ancestor-based match and stays. It could not have killed the merge-base
match, and the difference is one edge:

- **In arm (e)** the unrelated merge commit **is** the merge-base. Nothing's second parent equals it,
  so the merge-base form correctly found nothing.
- **In arm (i)** the unrelated merge's **second parent** is the merge-base. The merge-base form
  matched, and reported the branch's fork point as the head a PR merged.

One fixture, one shape. That is why check 4 now gates on containment instead of on any matcher.

### Arm (i), red against the pre-redesign script

Written and run **before** `bin/landed` was changed, as the measurement rather than a regression
test written after the fact:

```text
✗ origin/feat/x has 1 commit(s) that origin/staging does not have
    0f81507 branch work that never merged
✓ your local staging is not ahead of origin
✗ PR merged 63eca1dc, branch head is 0f81507b, 1 commit(s) between them
  merge 2dc732f7 — "Merge branch 'staging' of example.test:o/portal into staging" (subject read from the merge commit)
    0f81507 branch work that never merged

✗ NOT landed (2 check(s) failed) — do not report feat/x as merged.
___EXIT:1

Failed asserting that '…' contains "(1 check(s) failed)".
```

`feat/x` never merged, and the pre-redesign script reported its fork point `63eca1dc` as a head a PR
had merged — run 3, reproduced in a fixture.

### Raw output of the changed and new arms, after the redesign

**Arm (b) — instance A. Check 1 detects; check 4 declines; the hint carries the diagnosis.**

```text
LANDED?  feat/x → staging

✓ fetched origin (--prune)
  origin/feat/x = 1768bf02 · origin/staging = 039cab39

✗ origin/feat/x has 2 commit(s) that origin/staging does not have
    1768bf0 and its documentation
    608e3d8 the fix that never landed
✓ your local staging is not ahead of origin
ℹ origin/feat/x is not contained in origin/staging — no merge-head claim is made. Check 1 above is the signal.
  hint, READ FROM A MERGE MESSAGE and not from the graph: merge 039cab39 says it
  merged this branch and took 4cd78e8c; origin/feat/x is now 1768bf02, 2 commit(s) beyond that.
  merge 039cab39 — "Merge pull request #2 from o/feat/x"
  A hint, not a verdict: a rename, an edited message or a squash defeats it.

✗ NOT landed (1 check(s) failed) — do not report feat/x as merged.
EXIT=1
```

**Arm (e) — never merged, past an unrelated merge.**

```text
LANDED?  feat/x → staging

✓ fetched origin (--prune)
  origin/feat/x = 6a48c42a · origin/staging = 9a1302bc

✗ origin/feat/x has 1 commit(s) that origin/staging does not have
    6a48c42 never merged
✓ your local staging is not ahead of origin
ℹ origin/feat/x is not contained in origin/staging — no merge-head claim is made. Check 1 above is the signal.

✗ NOT landed (1 check(s) failed) — do not report feat/x as merged.
EXIT=1
```

**Arm (i) — the run-3 shape.**

```text
LANDED?  feat/x → staging

✓ fetched origin (--prune)
  origin/feat/x = 36f99dd7 · origin/staging = de25024b

✗ origin/feat/x has 1 commit(s) that origin/staging does not have
    36f99dd branch work that never merged
✓ your local staging is not ahead of origin
ℹ origin/feat/x is not contained in origin/staging — no merge-head claim is made. Check 1 above is the signal.

✗ NOT landed (1 check(s) failed) — do not report feat/x as merged.
EXIT=1
```

**Arms (e) and (i) now produce identical output**, modulo shas and commit subjects. That is not a
weakness of the fixtures — it is the finding, made visible: the two are the same graph shape, and
the tool no longer pretends to tell them apart.

### Mutation matrix for the redesigned check 4

Every existing arm was re-run. All ten pass.

| Mutation | Arms that went RED |
| -------- | ------------------ |
| N1 · the containment gate removed (`if true`) | (b) (e) (i), source-shape |
| N2 · second-parent match reverted to merge-base | **inert — see below** |
| N3 · the "not contained" line removed | (b) (e) (i) |
| N4 · the merge-message hint removed | (b) |
| N5 · the hint increments the failure count | (b) |
| N6 · containment test reversed (`--is-ancestor` args swapped) | (a) (c) (e) |
| N7 · hint pattern loosened to match any pull-request merge | (e) |
| N8 · the contained-no-merge branch stops setting its flag | (g) |

N1 also reddens the source-shape test, which asserts `bin/landed` still contains `git merge-base` —
the non-vacuity guard catching a mutation that deleted the only call.

**N2 is an equivalent mutant, not a survivor**, and is recorded as such rather than left looking like
a gap. Inside the containment gate `merge-base(target, branch)` *is* the branch head, so the two
matchers are the same expression:

```
$ git merge-base --is-ancestor origin/feat/u6-bulk-run-screen origin/staging && echo contained
contained
$ git merge-base origin/staging origin/feat/u6-bulk-run-screen
6e770aea9e86278d6eda2062ba0ed1259df56299
$ git rev-parse origin/feat/u6-bulk-run-screen
6e770aea9e86278d6eda2062ba0ed1259df56299
```

Same for `docs/test-infrastructure-tickets`. No arm can distinguish them because there is nothing to
distinguish — which is itself the argument that the gate, not the matcher, is what changed.

### Arm letters

The brief named the two new arms `h` and `i`. `(h)` was already taken by the branch-deleted-from-origin
arm from the first commit, so the run-3 arm shipped as **(i)** and the re-planted instance A is
**arm (b)**, which already plants exactly that fixture and was rewritten in place rather than
duplicated.

### Live, after the redesign

```
$ ./bin/landed feat/landed-check staging
✗ origin/feat/landed-check has 2 commit(s) that origin/staging does not have
    6f2ffa9 docs(quality): bin/landed against the live repository, …
    0b26cef feat(quality): a command that answers whether the remote contains what was reviewed
✓ your local staging is not ahead of origin
ℹ origin/feat/landed-check is not contained in origin/staging — no merge-head claim is made. Check 1 above is the signal.

✗ NOT landed (1 check(s) failed) — do not report feat/landed-check as merged.
EXIT=1
```

`feat/u6-bulk-run-screen` and `docs/test-infrastructure-tickets` still exit 0 with check 4 naming
`5a3f212` and `7894c39`, unchanged from §7.

---

## 9. Cold review — six findings, and the one that is a stop

All of it in one commit with its arms. A behaviour change whose only test arrives later is the shape
this branch's own ticket is about.

### F1 — a stale remote-tracking ref reported as landed. **STOP.**

Severity as set for this commit: **a stop**, raised from the review's own grading of it as a ticket.
`#267` does not merge until it is closed.

In a `--single-branch` or `--depth` clone, `remote.origin.fetch` covers one branch. A bare
`git fetch --prune origin` honours that refspec: it **succeeds**, updates nothing for the other ref,
and `refs/remotes/origin/<branch>` keeps whatever it last held. The script printed its ✓ fetch line
and then compared a ref that had not moved — **reporting instance A as landed**.

**A false green in a verification tool is worse than no tool, because it turns "I did not check" into
"I checked."** Everything else this branch built rests on the exit code meaning something.

Fixed by naming the two refs the script is about to compare:

```bash
git fetch --prune origin \
  "+refs/heads/$BRANCH:refs/remotes/origin/$BRANCH" \
  "+refs/heads/$TARGET:refs/remotes/origin/$TARGET"
```

Freshness is now a property of the fetch this script runs, not of the clone's configuration.
`--prune` consequently operates only within that refspec — intentional, and noted in the docblock:
pruning unrelated remote-tracking refs was never this script's business, and a verification tool
should not delete refs it is not talking about.

The two unknowns stay distinct. A fetch that fails because the ref is absent on origin prints
**"no such branch on origin"**; a fetch that fails because origin is unreachable prints **"could not
reach origin"**. Collapsing them would leave a reader retrying the network over a typo. Mutation R11
collapses them and reddens arms (h) and (n).

### F2 — shallow clone

`git rev-parse --is-shallow-repository` → exit 2. A shallow clone gives a **false red** (a landed
branch reported NOT landed, because the merge sits below the graft and `--is-ancestor` cannot see
containment) and a **wrong outstanding count**. Both are answers the script cannot give. Output that
is neither `true` nor `false` also exits 2 — an unexpected value is not evidence of a whole graph.

### F3 — the hint glob spanned slashes

`*"/$BRANCH"` matches `/` like any other character, so `bin/landed x` attributed a merge of
`feat/x`. Now the subject is split on `" from "` and the remainder must equal `"<owner>/$BRANCH"`
with a slash-free owner — the branch component compared whole, not as a suffix. The hint stays out
of the failure count.

### F4 — the arms

Seven, each measured against the pre-fix script before anything was changed: **(j)** stale ref,
**(k)** shallow clone, **(l)** check 4's comparison, **(m)** arm (i)'s hint assertion, **(n)** the
four unpinned exit paths, plus **(o)** and **(p)**, which close two branches of the fixes themselves
that no arm would otherwise have bitten. Reds and mutants below.

### F5 — the documentation corrections

- **Report §1** carried no supersession banner while §2 and §4 did, and its prose credited
  "instance A through checks 1 and 4". Both fixed: the banner is added and the attribution now
  reads that **check 1 detects instance A**.
- **Report's exit-2 cause list** included "no common ancestor". Measured: an orphan branch exits
  **1**, reported as one outstanding commit; the `merge-base` call that produced that path was
  removed in §8 and the sentence was not updated with it. **Corrected rather than re-added** — an
  orphan branch is not undecidable, the target plainly does not contain it, and spending exit 2 on
  a case the script *can* decide would weaken the one signal the script is built around.
- **Ticket** — "the two commands above would have surfaced all of it". Corrected to the **first**
  command; the second is the second-parent comparison retracted a few lines above it.

### F6 — octopus merges

The second-parent lookup reads field 3 of `git rev-list --merges --parents`, so a branch taken as a
third or later parent is not found. **The exit code stays correct** — the branch is contained, check 1
passes, the verdict is still "landed" — but check 4 explains it as "no merge commit found", which is
the wrong explanation for the right answer. One paragraph in the docblock; no code. Verified on this
repository before writing it: all **297** merges on `origin/staging` have exactly two parents.

```
$ git log --merges --format='%H %P' origin/staging | awk '{print NF-1}' | sort | uniq -c
 297 2
```

---

## The arms, and the red measured for each

Every new arm was run against the **pre-fix** script first. Two go red directly; the rest close
mutants that were measured surviving the suite as it stood.

| Arm | What it plants | Measured before |
| --- | -------------- | --------------- |
| **j** | stale remote-tracking ref | **RED — `✓ landed`, EXIT 0.** The false green itself |
| **k** | `--depth 1` clone, target advanced | **RED — EXIT 1.** The false red |
| **l** | arm (g) plus one unrelated merge on the target | arm passed; mutant `$3 == h` → `$3 != ""` **survived 10/10** |
| **m** | arm (i) gains `not->toContain('READ FROM A MERGE MESSAGE')` | arm passed; mutant loosening `Merge branch '$BRANCH'` **survived 10/10** |
| **n** | four unpinned exit paths | all four mutants **survived 10/10** |
| **o** | branch `x`, target carries a merge of `feat/x` | mutant restoring the slash-spanning glob **survived** |
| **p** | `git` shim answering the shallow probe `perhaps` | deleting the branch outright **survived 15/15** |

### (j) — the false green, verbatim, against the pre-fix script

```text
LANDED?  feat/x → staging

✓ fetched origin (--prune)
  origin/feat/x = cee19efe · origin/staging = fe43e339

✓ origin/staging contains every commit on origin/feat/x
✓ your local staging is not ahead of origin
✓ the merge on origin/staging took the head origin/feat/x points at (cee19efe)
  merge fe43e339 — "Merge pull request #8 from o/feat/x" (subject read from the merge commit)

✓ landed — origin/staging contains origin/feat/x, and the merge took the reviewed head.
This says nothing about whether the merge was correct or the merged tree is green.
EXIT=0
```

`origin`'s `feat/x` carried two commits `staging` did not have. Exit 0.

### (j) — after the fix

```text
LANDED?  feat/x → staging

✓ fetched origin/feat/x and origin/staging by name (--prune, within that refspec)
  origin/feat/x = 2318cde3 · origin/staging = 5c060489

✗ origin/feat/x has 2 commit(s) that origin/staging does not have
    2318cde and one more
    dabbbeb outstanding after the merge
✓ your local staging is not ahead of origin
ℹ origin/feat/x is not contained in origin/staging — no merge-head claim is made. Check 1 above is the signal.
  hint, READ FROM A MERGE MESSAGE and not from the graph: merge 5c060489 says it
  merged this branch and took c874cadd; origin/feat/x is now 2318cde3, 2 commit(s) beyond that.
  merge 5c060489 — "Merge pull request #8 from o/feat/x"
  A hint, not a verdict: a rename, an edited message or a squash defeats it.

✗ NOT landed (1 check(s) failed) — do not report feat/x as merged.
EXIT=1
```

The refspec brought the ref forward, check 1 names both commits, and the merge-message hint supplies
instance A's diagnostic sentence without claiming it.

### (k) — the false red, verbatim, against the pre-fix script

```text
LANDED?  feat/x → staging

✓ fetched origin (--prune)
  origin/feat/x = a61ebb37 · origin/staging = 4a817dc8

✗ origin/feat/x has 1 commit(s) that origin/staging does not have
    a61ebb3 the reviewed commit
ℹ no local staging branch — the ahead/behind checks do not apply
ℹ origin/feat/x is not contained in origin/staging — no merge-head claim is made. Check 1 above is the signal.

✗ NOT landed (1 check(s) failed) — do not report feat/x as merged.
EXIT=1
```

`feat/x` **is** merged here. The merge is below the graft, so containment could not be seen and the
outstanding count was computed over a truncated graph.

### (k) — after the fix

```text
LANDED?  feat/x → staging

✗ this is a SHALLOW clone — the merge may sit below the graft
  containment and the outstanding count are both unanswerable here. Unshallow first:
  git fetch --unshallow
EXIT=2
```

### (m) — what the loosened hint printed

The mutant that widens `Merge branch '$BRANCH'` to `Merge branch '`* matches arm (i)'s
`Merge branch 'staging' of …` and prints:

```
  hint, READ FROM A MERGE MESSAGE and not from the graph: merge 2b96fb5e says it
  merged this branch and took e8b46ac6; origin/feat/x is now 03022689, 1 commit(s) beyond that.
```

**That is § 7's false positive, verbatim, arriving through the message channel instead of the
topological one** — the same defect returning by another door, in a repository where `feat/x` never
merged. It survived 10/10 before arm (m)'s line existed.

### (o) — after the fix

```text
LANDED?  x → staging

✓ fetched origin/x and origin/staging by name (--prune, within that refspec)
  origin/x = 6773dff1 · origin/staging = f6235876

✗ origin/x has 1 commit(s) that origin/staging does not have
    6773dff the branch actually being asked about
✓ your local staging is not ahead of origin
ℹ origin/x is not contained in origin/staging — no merge-head claim is made. Check 1 above is the signal.

✗ NOT landed (1 check(s) failed) — do not report x as merged.
EXIT=1
```

`feat/x`'s merge is not a lead about `x`. No hint at all.

### (p)

```
✗ could not determine whether this clone is shallow (got [perhaps])
EXIT=2
```

---

## Mutation matrix after the fixes

Sixteen arms, all green. Each mutation below reddens the named arms.

| Mutation | RED |
| -------- | --- |
| R1 · refspec dropped, bare fetch restored | (j), source-shape |
| R2 · shallow guard exits 1 instead of 2 | (k) |
| R3 · shallow probe pinned `false` | (k) |
| R4 · unparseable shallow output accepted as fine | (p) |
| R5 · hint glob back to slash-spanning | (o) |
| R6 · hint `Merge branch '<branch>'` loosened | (i) |
| R7 · second-parent comparison dropped (`$3 != ""`) | (l) |
| R8 · no branch given exits 1 | (n) |
| R9 · not a git repository exits 1 | (n) |
| R11 · the two fetch unknowns collapsed into one message | (h) (n) |

**R10 — the post-fetch missing-target guard removed — now SURVIVES, and the reason is the fix.** With
the refspec naming `refs/heads/<target>`, a target absent from origin fails at the *fetch*, so the
`rev-parse` guard below it is no longer reached in any fixture that can be constructed. It is
belt-and-braces against a remote that serves a ref-less success, which is a state not reproducible
here. Recorded as unreachable rather than as covered; arm (n) still pins the outcome, which is now
delivered by the fetch.

### The fixture's last unguarded channel

`GIT_CONFIG_COUNT` and its `GIT_CONFIG_KEY_n` / `GIT_CONFIG_VALUE_n` pairs **outrank every config
file**, including the two the fixture already redirects to `/dev/null`. They are now unset.

**This closes a channel; it does not fix a live break.** The review measured that poisoning those
variables kills the fixture **loudly** — the fixture's own `git` calls fail and the arm errors —
rather than producing a false pass. It is closed because an isolation claim with a known hole in it
is not an isolation claim.

---

## The review's own limits, in its words

- the fresh clone could not complete (network); **a local clone at the identical commit was used and
  `bin/landed`'s blob sha verified identical**
- `composer install` and `npm run build` were skipped, because the ten arms need neither a database
  nor a Vite manifest
- the M-series mutations could not be re-derived, because that code is gone
