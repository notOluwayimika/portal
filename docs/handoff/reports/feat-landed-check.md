# feat/landed-check — a command that answers whether the remote contains what was reviewed

Branch `feat/landed-check`, off `origin/staging` at `7894c39` (the #266 merge).

Closes the detection proposed in
`docs/handoff/tickets/a-merged-pull-request-does-not-mean-the-branch-head-merged.md`. Three files are
new — `bin/landed`, `tests/Feature/Quality/LandedCheckCoverageTest.php`, this report — and two are
amended: `CLAUDE.md` § Workflow and the ticket itself.

---

## 1. The two instances, re-derived

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

All three failing checks fire on the real repository — instance A through checks 1 and 4, instance B
through check 2 — and the behind-line is printed as information alongside them. On a healthy branch
(`docs/test-infrastructure-tickets`, merged as #266) checks 1 and 4 pass and only check 2 fires, which
is the same instance B seen from a different branch.

---

## 2. What each check does

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
branch, no common ancestor, not a git repository). **2 is not collapsed into 1.**

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
- **`bin/landed feat/landed-check` itself.** It cannot be run against this branch until this branch
  merges. That is the first thing to run afterwards.
