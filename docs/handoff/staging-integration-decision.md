# Decision — integrating `origin/staging`, the untracked docs, and the promote ordering

4 August 2026. Answers the three asks from the `887754e` report. Verified read-only against the repo.

## 0. Two errors of mine, before the answers

**a. `-c diff.external=` was prescribed without being run.** You are right that git execs the empty
string and dies, and `--no-ext-diff` after the subcommand is the correct form. I wrote a flag from
memory and called it "one line". That is the second prescription in three briefs I handed over
unrun — `git show $head:<path>` was the first. New rule on me: **any command I prescribe verbatim, I
have run it, or I mark it UNVERIFIED and you treat it as a sketch.** Nothing in this file is unrun.

Your implementation is better than what I asked for: gating `--no-ext-diff` on the subcommand keeps
`rev-parse` clean, and the docblock's `diff.external = /bin/false` measurement — *"it does not garble
the output, it replaces it"* — is the sharper half of the finding and it was not in my brief.

**b. §8's promote → merge → push ordering is impossible and I should have read the script.**
`bin/quality-promote:55` refuses a dirty tree, `:60` refuses when `staging` is not in sync with
`origin/staging`. Promote can therefore only run on a pushed, clean, in-sync tip. The correct order is
in §4. Running `php bin/ci-grants-convergence-lint.php origin/main` instead was the right
substitution — it is what `quality-promote:79` reaches through `quality:141` — and it exercises
finding 2's substance. It is not a substitute for the other twelve steps over the wider base, so
promote still runs, just last.

## 1. Integrate by REBASE

`git rebase origin/staging` while on `staging`.

Reason: your 11 commits have never been pushed, so rebasing rewrites history nobody else holds — the
one condition under which rebase is safe, and it holds exactly. Zero file overlap, `merge-tree`
predicts clean, and this staging line has been kept linear by `--ff-only` throughout; a merge commit
here would be the first one and would record a concurrent-edit story that did not happen.

**The cost, stated so it is not a surprise:** after the rebase the ten intermediate commits are green
only against a base that no longer exists. That proof is stale under *either* option — `tsc-baseline`
moved 86→42 and a migration was added — so it is not a reason to prefer merge. Re-run `bin/quality`
on the rebased tip; the pre-push hook does it anyway.

**Run `composer install` before `bin/quality`.** `origin/staging` moves `composer.json` and
`composer.lock`; a quality run against a stale vendor tree proves nothing about what ships.

## 2. What `origin/staging` brings, checked so you are not surprised

I diffed `8c354a5...origin/staging`. It touches **neither `database/seeders/RbacSeeder.php` nor
`app/Enums/Permission.php`** — so nothing in it can change what the grants-convergence lint decides.
Its one new migration, `2026_08_03_140000_create_contact_points.php`, contains **zero** `@converges`
markers, so it joins the promote-range `--diff-filter=A` set inertly and cannot reach
`$markersOnModified` at all (it is `A`, never `M`).

One thing to watch rather than assume: it changes `routes/api.php` without touching
`tests/fixtures/route-access-map.json` or `route-middleware-baseline.json`. Either those oracles do
not cover `api.php` or something is unoracled. Their PRs were green, so the first is likely — but if
either fixture goes red on the rebased tip, that is the answer and it is theirs, not yours. Report it
and stop rather than regenerating a fixture you did not author.

## 3. Commit all four docs, in their own commit, first

`docs/handoff/converges-marker-followup-3-brief.md` (my dated correction),
`docs/handoff/credit-note-approver-move-brief.md`, `docs/finance/authority-matrix-decisions-2026-08-03.md`,
`docs/handoff/finance-access-convergence-brief.md`.

Commit, do not discard. These are the record — a decisions document and three briefs that other
artifacts already cite by path — and a record that exists only on one laptop is not a record. That
`finance-access-convergence-brief.md` has been sitting untracked while a live migration docblock cites
its subject is itself the argument.

Docs-only commit, message naming it as the advisory record, separate from `887754e`'s code. Then the
tree is clean, which `quality-promote:55` requires.

**The §3 instruction that told you not to touch follow-up 3 meant do not edit its content.** Committing
it is not editing it. My fault for the ambiguity.

## 4. Order

1. commit the four docs (clean tree)
2. `composer install`
3. `git rebase origin/staging`
4. `bin/quality` on the rebased tip — 13/13, all arms
5. `git push origin staging`
6. `bin/quality-promote`

Stop and report at any red. Do not force-push at step 5: if it is rejected again, `origin/staging` has
moved a second time and the answer is another rebase, reported first.

## 5. Then

The fragment-resolution brief is mine and it is next. After it,
`docs/handoff/credit-note-approver-move-brief.md`.
