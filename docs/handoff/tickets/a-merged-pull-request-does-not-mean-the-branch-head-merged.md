# TICKET — a merged pull request does not mean the branch head merged

**Status:** the DETECTION is built and shipped — `bin/landed`, on branch `feat/landed-check`.
The two INSTANCES it was raised for are **both still open on `origin/staging`** as of 2026-08-20;
see "Closing section" at the bottom, which re-derives them. Raised by PR #265 (`feat/u6-bulk-run-screen` → `staging`), which GitHub reported
merged while two commits of that branch — including the only behavioural fix on it — were not in
`staging` and were not in the merge commit.

Sibling to `docs/handoff/tickets/a-green-pre-push-hook-does-not-mean-the-push-landed.md`. Same family,
one step further down the pipe: that ticket is about a green gate that says nothing about the push;
this one is about a merged PR that says nothing about the branch.

## What happened, measured

The merge commit's second parent is not the branch head that was believed complete:

```
$ git log -1 --format='%H %P' 9849689
98496897b84027a1dc7160a76e717a948cde02e7 6545bcc9d08cb02e43016939255ca4f66e1fa03b 37500c8fa9ad2b59dd457448ff6f5e0158d2267f
```

`37500c8` is `Merge branch 'staging' into feat/u6-bulk-run-screen` — the conflict-resolution merge,
not the tip. Two commits sat above it and were never in the PR:

```
$ git log --oneline origin/staging..origin/feat/u6-bulk-run-screen
6e770ae docs(finance): the Referer mechanism was already written down, and the drive now prints two tables
c7ec9a6 fix(finance): the run tables are fail-closed, and three documents stop claiming more than was measured

$ git diff --stat origin/staging origin/feat/u6-bulk-run-screen
 .claude/skills/finance-drive/SKILL.md              | 145 +++++++++++++---
 config/rbac.php                                    |  16 ++
 database/seeders/DriveCastSeeder.php               |   1 -
 docs/handoff/reports/feat-u6-bulk-run-screen.md    | 186 ++++++++++++++++++++-
 .../bulk-run-buckets-have-no-page-past-200.md      |  68 ++++++++
 .../current-term-resolution-is-unordered.md        |  70 ++++++++
 .../tickets/fail-closed-allowlist-is-opt-in.md     | 112 +++++++++++++
 .../pages/admin/finance/bulk-invoice-runs/show.tsx |  28 +++-
 tests/Feature/Finance/BulkInvoiceRunScreenTest.php | 135 +++++++++++++++
 .../Isolation/FinanceFailClosedBatchTest.php       |  21 ++-
 10 files changed, 740 insertions(+), 42 deletions(-)

$ git show origin/staging:config/rbac.php | grep -c BulkInvoiceRun
0
```

### Which form was run, and why it still returns the same thing

**The ref comparison above still reproduces, because the fix has not reached `origin/staging` either.**
The brief for this ticket said to re-derive "against the merged history now that both commits have
landed". They have landed — on the **local** `staging`, in merge commit `5a3f212`, which at the time
of writing is **not pushed**:

```
$ git rev-parse origin/staging
2838e55bf197aee62abd5949a60d6c3a7cdb4a5b

$ git log --oneline origin/staging..staging
6e770ae docs(finance): the Referer mechanism was already written down, and the drive now prints two tables
c7ec9a6 fix(finance): the run tables are fail-closed, and three documents stop claiming more than was measured

$ git log --oneline staging..origin/feat/u6-bulk-run-screen
(empty)

$ git show staging:config/rbac.php | grep -c BulkInvoiceRun
4
```

So both forms were run. Against `origin/staging` the gap is open; against local `staging` it is
closed. **That the remedy for a "merged, but not really" bug is currently sitting unpushed on one
machine is the same class of failure, and the detection below catches it too** — which is the argument
for the detection being a fetch-and-compare of *refs*, not a look at a local branch.

The shas (`9849689`, `37500c8`, `6e770ae`, `c7ec9a6`) are stable and reproduce anywhere. The ref
comparisons are not; state which you ran.

## The behavioural item

Eight of the ten files were documentation. The one that was not is `config/rbac.php`:

```
$ git show origin/staging:config/rbac.php | sed -n "/'fail_closed_models'/,/^    \],/p" | grep -c "::class"
10
$ git show staging:config/rbac.php | sed -n "/'fail_closed_models'/,/^    \],/p" | grep -c "::class"
12
```

`fail_closed_models` stood at **ten** entries instead of twelve, with `BulkInvoiceRun` and
`BulkInvoiceRunRow` absent — while everything they guard was present and merged:

```
$ git ls-tree -r --name-only origin/staging | grep -i bulkinvoicerun
app/Finance/Enums/BulkInvoiceRunOutcome.php
app/Finance/Enums/BulkInvoiceRunStatus.php
app/Finance/Http/Controllers/BulkInvoiceRunController.php
app/Finance/Http/Requests/BulkInvoiceRunCoordinatesRequest.php
app/Finance/Http/Resources/BulkInvoiceRunResource.php
app/Finance/Jobs/ProcessBulkInvoiceRun.php
app/Finance/Models/BulkInvoiceRun.php
app/Finance/Models/BulkInvoiceRunRow.php
tests/Feature/Finance/BulkInvoiceRunScreenTest.php
tests/Feature/Finance/BulkInvoiceRunTest.php
```

Models, controller, request, resource, job and tables from `1279623` all shipped. The registration
that makes them fail closed did not. **A finance table shipped without its fail-closed registration
and nothing in the tree said so.**

What that cost, in the words of the docblock the fix added to
`tests/Feature/Isolation/FinanceFailClosedBatchTest.php`:

> a super admin with no school selected read EIGHT runs across both drive schools from
> `GET /api/v1/finance/bulk-invoice-runs`, and opened either school's run detail.

## The part worth writing down

The arms that assert those two models are fail-closed **travelled in the same commit as the fix**:

```
$ git show c7ec9a6 --name-only --format=
.claude/skills/finance-drive/SKILL.md
config/rbac.php
docs/handoff/reports/feat-u6-bulk-run-screen.md
docs/handoff/tickets/bulk-run-buckets-have-no-page-past-200.md
docs/handoff/tickets/current-term-resolution-is-unordered.md
docs/handoff/tickets/fail-closed-allowlist-is-opt-in.md
resources/js/pages/admin/finance/bulk-invoice-runs/show.tsx
tests/Feature/Finance/BulkInvoiceRunScreenTest.php
tests/Feature/Isolation/FinanceFailClosedBatchTest.php
```

`config/rbac.php` and `FinanceFailClosedBatchTest.php`, one commit. The diff of the test:

```
-        BulkInvoiceRun::class,      ← added by c7ec9a6
-        BulkInvoiceRunRow::class,   ← added by c7ec9a6
- * WHY THESE TEN AND NOT THE OTHER SIX...
+ * WHY THESE TWELVE AND NOT THE OTHER SIX...
```

**So the merged tree contained neither the registration nor the test that checks it, and a full green
suite on `staging` proved nothing about the gap.** The ratchet was clean. The arch group was clean.
Every gate was green over a tree with a fail-open finance table in it, correctly, because the only
detector for that condition had not landed either.

Generalised: **a commit that carries both a fix and the only test of that fix leaves no detector
behind when it fails to land.** The failure is invisible in exact proportion to how completely the
commit was lost. A half-landed commit is loud; a fully-lost one is silent.

Whether that is avoidable — whether a fix and its first test should be separable commits, what that
costs in review and in bisectability, and whether it is even coherent for a test that cannot pass
before the fix — is the question this ticket raises. **It is not answered here.**

## Was the head stale at merge time, or were the commits pushed afterwards? — ESTABLISHED

The brief for this ticket recorded this as undetermined, on the grounds that a local clone cannot see
the PR timeline. `gh` is available and authenticated here, so it was read:

```
$ gh pr view 265 --json number,state,mergedAt,createdAt,headRefOid,mergeCommit,baseRefName,headRefName
{"baseRefName":"staging","createdAt":"2026-08-19T21:39:33Z","headRefName":"feat/u6-bulk-run-screen",
 "headRefOid":"37500c8fa9ad2b59dd457448ff6f5e0158d2267f",
 "mergeCommit":{"oid":"98496897b84027a1dc7160a76e717a948cde02e7"},
 "mergedAt":"2026-08-20T04:06:58Z","number":265,"state":"MERGED"}

$ for c in c7ec9a6 6e770ae 37500c8 9849689; do echo "$c  authored=$(git log -1 --format=%aI $c)  committed=$(git log -1 --format=%cI $c)"; done
c7ec9a6  authored=2026-08-20T08:54:22+01:00  committed=2026-08-20T09:03:18+01:00
6e770ae  authored=2026-08-20T09:48:04+01:00  committed=2026-08-20T09:48:04+01:00
37500c8  authored=2026-08-20T05:06:43+01:00  committed=2026-08-20T05:06:43+01:00
9849689  authored=2026-08-20T05:06:58+01:00  committed=2026-08-20T05:06:58+01:00
```

**GitHub did not merge a stale head.** Two independent facts say so:

1. `headRefOid` at merge is `37500c8`, which **is** the merge commit's second parent. GitHub merged
   exactly the commit the PR's head pointed at.
2. `mergedAt` is `2026-08-20T04:06:58Z` = `05:06:58+01:00`. `c7ec9a6` was committed at `09:03:18+01:00`
   and `6e770ae` at `09:48:04+01:00` — **roughly four and four-and-three-quarter hours after the
   merge**. A commit cannot have been pushed before it was written.

So: **the two commits were created and pushed to the branch after PR #265 had already merged.** The PR
was complete and correct at the moment the button was pressed. The mistake was upstream of GitHub
entirely — work continued on a branch whose PR had closed, and nothing observed that the branch had
drifted above its own merge.

Committer time is a lower bound on push time and is clock-dependent, so it does not pin the push
instant; it does not need to. Both commits' *commit* times postdate the merge, which is sufficient.

**That changes what the remedy is for.** It is not a race against the merge button. It is that after a
merge, `origin/<branch>` remains writable and nothing compares it to `origin/<target>` ever again.

## The detection proposed

Stated so it survives a machine change — two commands, neither depending on the GitHub UI being right.
After **any** PR merge:

```bash
git fetch origin --prune

# 1. Nothing may remain on the branch that is not in the target.
git log --oneline origin/<target>..origin/<branch>      # must print nothing

# 2. The merge commit's second parent must equal the branch head that was reviewed.
git log -1 --format='%P' origin/<target>                # second sha == reviewed head
```

Check 1 catches this instance in both directions — the original gap, and the current state where the
fix exists locally and `origin/staging` has not got it. Check 2 catches the stale-head case that did
*not* happen here but is the other way to reach the same tree.

Neither requires `gh`, a network beyond `fetch`, or trusting a "Merged" badge.

## Not proposed here

Whether this becomes a hook, a `bin/` script, a step in `bin/quality-promote`, or a line in
`CONTRIBUTING.md`; whether branches should be deleted at merge so they cannot drift; and whether a fix
and its only test should be separable commits. The claims this ticket makes are that PR #265 merged a
head two commits short of the branch's eventual tip, that the shortfall included the only behavioural
change on the branch, that the only detector for that change travelled with it, and that the two
commands above would have surfaced all of it.

## See also

- `docs/handoff/tickets/a-green-pre-push-hook-does-not-mean-the-push-landed.md` — the same shape one
  step earlier: a gate reporting on itself rather than on the outcome.
- `docs/handoff/tickets/fail-closed-allowlist-is-opt-in.md` — why `fail_closed_models` had a
  registration to miss in the first place.

---

## Closing section — the check exists

`bin/landed <branch> [target]`, with `tests/Feature/Quality/LandedCheckCoverageTest.php` behind it.
Written on `feat/landed-check`, off `origin/staging` at `7894c39` (the #266 merge).

### What it does

Four checks, from refs alone, after one `git fetch origin --prune` — which is the script's only
mutation, stated in its docblock and in `--help`. It never pushes, merges, checks out, resets or
writes a branch ref, and it never calls `gh`.

| # | Question | Verdict |
| - | -------- | ------- |
| 0 | could origin be reached? | fetch failure → **exit 2**, never 0 |
| 1 | `origin/<target>..origin/<branch>` empty? | non-empty → fail, commits listed. **Instance A** |
| 2 | `origin/<target>..<target>` empty? | non-empty → fail, "not on origin". **Instance B** |
| 3 | `<target>..origin/<target>` empty? | non-empty → **information**, not a failure |
| 4 | did the merge take the head the branch now points at? | mismatch → fail, both shas named |

Exit codes: `0` all checks pass · `1` a check failed · `2` could not determine. **2 is not collapsed
into 1.** A failed fetch, an unknown branch and an absent common ancestor are *unknown*, not *wrong*;
accepting one for the other is the defect class this whole ticket is about, and a green meaning
"I could not look" would be it wearing the script's own face.

Check 4 finds the merge by **merge-base**: the merge commit on `origin/<target>` whose *second*
parent equals `merge-base(origin/<target>, origin/<branch>)`. Matching instead on "the newest merge
whose second parent is an ancestor of the branch" is wrong and was rejected with an arm — a branch
cut from the target after some *other* branch merged carries that other branch's head in its
ancestry, and would be reported as a stale-head mismatch against a merge that has nothing to do with
it. Where no such merge exists the script distinguishes two further outcomes rather than forcing one:
the branch is *contained anyway* (fast-forward, or the branch merged the target back in after its own
PR closed) — reported, exit 0, with wording that does not claim a merge was checked; or it is *not
contained* — "no merge of `<branch>` found on `origin/<target>`", exit 1.

### The arms that prove it fires

Eight, each planting a real repository under `mktemp -d` with a bare repo wired as `origin` by path,
so every arm runs offline. Each asserts the exit code **and** the message: three of the failure modes
exit 1, so a code-only arm would pass while the script reported the wrong one.

| Arm | Planted | Asserts |
| --- | ------- | ------- |
| a | merged at its head, everything pushed | exit 0, PR subject read from the merge commit |
| b | **instance A** — merge took an earlier sha, two commits added after | exit 1, both shas, "2 commit(s) between them", 2 checks failed |
| c | **instance B** — local target one commit ahead of origin | exit 1, "not on origin", exactly 1 check failed |
| d | local target behind origin, otherwise clean | exit 0, informational line present |
| e | never merged, cut *past an unrelated merge* | exit 1, "no merge of feat/x found", no mismatch wording |
| f | origin pointed at a nonexistent path, from a healthy state | **exit 2**, not 1, not 0 |
| g | landed by fast-forward, no merge commit | exit 0, and the ordinary green's wording is *absent* |
| h | branch deleted from origin | exit 2, "cannot determine whether it landed" |

Arms (f) and (h) build a fully healthy, fully pushed state *first* and only then break the remote, so
a script that ignored the failure would sail through every check and print a green. That is what makes
them non-vacuous rather than merely present.

Every arm was bite-proved by breaking `bin/landed` one line at a time; the mutation each caught is in
`docs/handoff/reports/feat-landed-check.md`. Two mutations initially **survived** and the arms were
strengthened until they did not — the no-merge outcome could not be told from check 1 by exit code
alone (the count in the verdict line now discriminates), and the merge-base match had no arm at all
until (e) gained its unrelated merge.

### What a green does not mean

It proves `origin/<target>` contains every commit on `origin/<branch>`, and that the merge took the
head `origin/<branch>` now points at. It proves **nothing** about whether the merge was correct,
whether the reviewer read the right diff, whether the merged tree passes `bin/quality`, or whether the
branch should have been merged. It also cannot see a squash or rebase merge as a merge: those leave
neither a merge commit nor ancestry, and arm (e)'s outcome is what such a branch would report.

`bin/landed` is deliberately **not** wired into `bin/quality` (that floor is offline by design,
`.githooks/pre-push:3-20`, and the failure happens after a merge, which the per-push hook never
observes) and **not** into a `post-merge` hook (local-only; it never fires for a web merge, which is
how instance A happened). It is documented in `CLAUDE.md` § Workflow, where `.githooks/pre-push:20`
already points readers.

### The open question this does NOT answer

The ticket's question — stale head at merge time, or commits pushed afterwards — **is answered
above**, in "Was the head stale at merge time…", from `gh pr view 265` and commit timestamps: the two
commits were written roughly four hours *after* the merge, so GitHub merged exactly the head the PR
pointed at. `bin/landed` does not answer it and could not: it reads refs, and refs carry no timeline.
It detects the outcome, not the cause. Had the cause been the other one, the same check would have
fired the same way, which is the argument for it.

The ticket's other open questions are untouched: whether a fix and its only test should be separable
commits, and whether branches should be deleted at merge so they cannot drift.

### Both instances are still open

Re-derived on 2026-08-20 against `origin/staging` at `7894c39`, and `bin/landed` reports them:

```
$ git log --oneline origin/staging..origin/feat/u6-bulk-run-screen
6e770ae docs(finance): the Referer mechanism was already written down, and the drive now prints two tables
c7ec9a6 fix(finance): the run tables are fail-closed, and three documents stop claiming more than was measured

$ git show origin/staging:config/rbac.php | sed -n "/'fail_closed_models'/,/^    \],/p" | grep -c "::class"
10
```

`fail_closed_models` still stands at **ten** entries on `origin/staging`, with `BulkInvoiceRun` and
`BulkInvoiceRunRow` still absent, and the local merge `5a3f212` that closed it is still unpushed —
local `staging` is now *diverged*, three commits ahead of origin and three behind, because #266
merged in the meantime. Building the detector did not land the fix; that is a separate push and is
not part of this branch.
