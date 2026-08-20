# TICKET — a merged pull request does not mean the branch head merged

**Status:** the DETECTION is built and shipped — `bin/landed`, on branch `feat/landed-check`.
It detects instance A through **check 1** and instance B through **check 2**. The second-parent
comparison this ticket originally proposed as the stale-head detector **cannot work** and has been
demoted to an explainer; see the closing section, and § 7–8 of
`docs/handoff/reports/feat-landed-check.md` for the measurement. Both INSTANCES have since been
closed on `origin/staging` (`ca12d92`, `fail_closed_models` back to twelve entries) — the re-derivation
in the closing section was written while they were open, on 2026-08-20, and is left as the record.
Raised by PR #265 (`feat/u6-bulk-run-screen` → `staging`), which GitHub reported
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

> **The second command above does NOT do what this section says it does**, and the paragraph is left
> as written because it is the record of what was proposed. Implementing it and pointing it at this
> repository showed that a second-parent comparison cannot identify "the merge of this branch": the
> stale-head shape is topologically identical to a branch that never merged and was merely cut from a
> commit that later became a merge parent. Check 1 is the detector. See the closing section.

## Not proposed here

Whether this becomes a hook, a `bin/` script, a step in `bin/quality-promote`, or a line in
`CONTRIBUTING.md`; whether branches should be deleted at merge so they cannot drift; and whether a fix
and its only test should be separable commits. The claims this ticket makes are that PR #265 merged a
head two commits short of the branch's eventual tip, that the shortfall included the only behavioural
change on the branch, that the only detector for that change travelled with it, and that the
**first** of the two commands above would have surfaced all of it.

An earlier version of this sentence said *both* commands would have. The second — the second-parent
comparison — would not have, and is retracted above as unable to identify the merge of a branch at
all. `origin/<target>..origin/<branch>` carried the whole finding on its own.

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

Four checks, from refs alone, after one fetch — the script's only mutation, stated in its docblock
and in `--help`. It never pushes, merges, checks out, resets or writes a branch ref, and it never
calls `gh`.

**THE FETCH NAMES THE TWO REFS IT IS ABOUT TO COMPARE**, and that is load-bearing rather than a
detail. A bare `git fetch --prune origin` honours `remote.origin.fetch`, which in a `--single-branch`
or `--depth` clone covers ONE branch: the fetch succeeds, the other remote-tracking ref does not move,
and the script printed a ✓ and compared a stale ref — measured as **`✓ landed`, exit 0**, over a
branch carrying two commits the target did not have. A false green in a verification tool is worse
than no tool, because it turns "I did not check" into "I checked". Naming the refspec makes freshness
a property of the fetch rather than of the clone. `--prune` then operates only within that refspec,
which is intended.

**A shallow clone exits 2 before anything else.** The merge sits below the graft, so containment
cannot be seen and a landed branch reports NOT landed, with an outstanding count computed over a
truncated graph. Both are answers the script cannot give.

| # | Question | Verdict |
| - | -------- | ------- |
| 0 | is the graph whole, and could origin be reached? | shallow clone, unreadable shallow probe, or fetch failure → **exit 2**, never 0 |
| 1 | `origin/<target>..origin/<branch>` empty? | non-empty → fail, commits listed. **Instance A** |
| 2 | `origin/<target>..<target>` empty? | non-empty → fail, "not on origin". **Instance B** |
| 3 | `<target>..origin/<target>` empty? | non-empty → **information**, not a failure |
| 4 | **when the branch is contained**, did a merge take the head it now points at? | explains; **never fails**. See below — this check detects nothing |

Exit codes: `0` all checks pass · `1` a check failed · `2` could not determine. **2 is not collapsed
into 1.** A failed fetch, an unknown branch and an absent common ancestor are *unknown*, not *wrong*;
accepting one for the other is the defect class this whole ticket is about, and a green meaning
"I could not look" would be it wearing the script's own face.

**CHECK 4 DOES NOT DETECT THE STALE HEAD, and an earlier version of this section said it did.**
That claim was wrong and is retracted here. What detects instance A is **check 1** — the branch holds
commits the target does not have — and check 1 names them.

The first version of check 4 identified "the merge of this branch" as the merge on `origin/<target>`
whose second parent equals `merge-base(origin/<target>, origin/<branch>)`. Pointed at this repository
it reported `feat/landed-check` — a branch that has never merged — as having been merged at its own
fork point, because a `git pull` reconciliation merge on `staging` (`ca12d92`) had that fork point
(`7894c39`) as its second parent. Raw output and derivation:
`docs/handoff/reports/feat-landed-check.md` § 7.

**Instance A and that false positive are the same topology.** In both, a merge on the target has as
its second parent a commit that is an ancestor of the branch and is not the branch head:

| | the merge | its second parent | the branch |
| --- | --- | --- | --- |
| Instance A | `9849689` (PR #265) | `37500c8` — merged, then the branch advanced | `6e770ae` |
| The false positive | `ca12d926` (a `git pull` reconciliation) | `7894c393` — the branch's own fork point | `0b26cef2` |

Git records no branch identity in the DAG. A merge's second parent is a commit, not a branch, so
there is no ancestry test that answers "was this a merge *of this branch*" — and a matcher sharpened
until it passed one row would fail the other. The merge-base form was already the sharpened version,
chosen over a looser ancestor form precisely to stop this class, with a fixture planted for it. It
still got the real repository wrong.

So check 4 now makes a topological claim **only when `origin/<branch>` is contained in
`origin/<target>`** — a fact about the graph, not a guess about intent:

- **contained, via a merge whose second parent is the branch head** → ✓, merge named. Reliable:
  containment plus that equality leaves nothing else the merge could have taken.
- **contained, no such merge** (fast-forward, or the branch merged the target back in) → ℹ, exit 0,
  wording that does not claim a merge was checked.
- **not contained** → ℹ "origin/`<branch>` is not contained in origin/`<target>` — no merge-head
  claim is made. Check 1 above is the signal." It asserts nothing and adds nothing to the failure
  count.

The useful *sentence* for instance A — "PR merged `37500c8`, branch head is `6e770ae`" — is not
derivable from topology, only from what a merge commit says about itself. For an uncontained branch
the script scans merge subjects for `Merge pull request #N from <owner>/<branch>` or
`Merge branch '<branch>'` and prints that merge's second parent as a **hint, labelled as read from
the message**, contributing nothing to the failure count. A rename, an edited message or a squash
defeats it. It is a lead for a human, never a verdict — the same treatment the PR number already had,
for the same reason.

### The arms that prove it fires

Sixteen tests, each planting a real repository under `mktemp -d` with a bare repo wired as `origin`
by path, so every arm runs offline. The nine below are the topological ones; the rest pin the fetch,
the shallow guard, the merge-message hint and the four exit paths — see
`docs/handoff/reports/feat-landed-check.md` § 9. Each asserts the exit code **and** the message: the failure modes that
exit 1 are several, so a code-only arm would pass while the script reported the wrong one.

| Arm | Planted | Asserts |
| --- | ------- | ------- |
| a | merged at its head, everything pushed | exit 0, PR subject read from the merge commit |
| b | **instance A** — merge took an earlier sha, two commits added after | exit 1, check 1 names both commits, **1** check failed, check 4 makes no claim, the message hint appears and costs nothing |
| c | **instance B** — local target one commit ahead of origin | exit 1, "not on origin", exactly 1 check failed |
| d | local target behind origin, otherwise clean | exit 0, informational line present |
| e | never merged, cut *past an unrelated merge* | exit 1, check 1 only, no verdict wording, no hint |
| f | origin pointed at a nonexistent path, from a healthy state | **exit 2**, not 1, not 0 |
| g | landed by fast-forward, no merge commit | exit 0, and the ordinary green's wording is *absent* |
| h | branch deleted from origin | exit 2, "cannot determine whether it landed" |
| i | **the false positive** — fork point is a later reconciliation merge's second parent | exit 1, check 1 only, and no stale-head text anywhere |

Arms (f) and (h) build a fully healthy, fully pushed state *first* and only then break the remote, so
a script that ignored the failure would sail through every check and print a green. That is what makes
them non-vacuous rather than merely present.

**Arms (e) and (i) now produce identical output**, modulo shas. That is the finding made visible:
they are the same graph shape and the tool no longer pretends to tell them apart. Arm (e) was
*necessary and insufficient* — it killed the loose ancestor-based match, but could not kill the
merge-base match, because in (e) the unrelated merge commit **is** the merge-base, whereas in (i) the
unrelated merge's **second parent** is. One fixture, one shape.

Every arm was bite-proved by breaking `bin/landed` one line at a time; the mutation each caught is in
`docs/handoff/reports/feat-landed-check.md` §§ 5 and 8. Arm (i) was written and confirmed RED against
the pre-redesign script before anything was changed, so it is a measurement rather than a regression
test written after the fact. Two mutations survived the first round and the arms were strengthened
until they did not, and one later mutation is recorded as an *equivalent mutant* rather than left
looking like a gap.

### What a green does not mean

It proves `origin/<target>` contains every commit on `origin/<branch>`, and — where a merge commit
took that head — that it did. It proves **nothing** about whether the merge was correct, whether the
reviewer read the right diff, whether the merged tree passes `bin/quality`, or whether the branch
should have been merged. It also cannot see a squash or rebase merge as a merge: those leave neither a
merge commit nor ancestry, so a squash-merged branch reports as not contained, exactly like one that
never merged.

**And a quiet check 4 is not evidence of anything.** On an uncontained branch it deliberately makes no
claim; read check 1. Which check detects what: **check 1 detects instance A**, **check 2 detects
instance B**, **check 4 explains**.

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

### Both instances were still open when this was written — since closed

Re-derived on 2026-08-20 against `origin/staging` at `7894c39`, and `bin/landed` reported them. Left
as written: `origin/staging` has since advanced to `ca12d92`, where `fail_closed_models` carries
twelve entries and `bin/landed feat/u6-bulk-run-screen` exits 0. The paragraph below is the state at
the time of measurement, not the state now.

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
