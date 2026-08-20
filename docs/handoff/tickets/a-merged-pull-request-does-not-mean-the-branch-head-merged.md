# TICKET — a merged pull request does not mean the branch head merged

**Status:** open. Raised by PR #265 (`feat/u6-bulk-run-screen` → `staging`), which GitHub reported
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
