# Brief — follow-up 4: colour-blind `git()`, and two docblocks that assert a range property as a file property

Branch off `staging` @ `49e77a4`. One branch, one commit, `--ff-only` back. **Do not push `staging`
until this is in** — findings 1 and 2 are on merged code and the second one is refuted by the very
gate `bin/quality-promote` runs.

Verdict on the five: **1 fix · 2 fix (widened) · 3 fix, report only · 4 fix · 5 standing rule.**

Three corrections to my own record first, because two of them are why this brief exists.

---

## 0. Where I was wrong

**a. The `:411` overrule was mine and it was wrong.** You are right and so is the reviewer. I read
`RbacSeeder.php:411` as the anchor because it is where the array key sits; the citation is to the
DECIDED/UNIMPLEMENTED *record*, which is the comment block, and that block opens at `:377`. It says
so itself — `:389` reads *"What :377 adds is why that is a deliverable"*, a self-reference I walked
straight past. `:377-394` is correct. Do not revert it.

**b. `:10` (`:147` → `:169`) was right to fix and I should have listed it.** Same drift, same file,
same commit. My §4 named two citations and you found the third by applying the argument I gave rather
than the list I gave. That is the behaviour I want.

**c. My "live on this branch right now" premise was unreachable, and your correction to it was
unreachable in the same way.** I verified: `database/seeders/RbacSeeder.php` is **not** in
`git diff --name-only 8c354a5...f871ba8`. So over that range the lint exits at `:447` —
*"OK — …RbacSeeder.php is unchanged in this diff"*, `exit 0` — long before `$markersOnModified` is
printed. The notice cannot name anything on that range, so it neither "is live" (my claim) nor "still
names both files, and that's right" (your correction). Reviewer finding 3 is correct against both of
us.

The fix still stands, and on better ground than either of us gave it: the reviewer's out-of-tree
reproduction, and MARKER 9b, which the reviewer confirmed genuinely discriminates the two
implementations. That is the evidence. Say so in the report rather than repeating a range claim.

---

## 1. Finding 1 — `git()` is colour-blind by luck, not by construction. Fix in one place.

Confirmed at `bin/ci-grants-convergence-lint.php:120-125`:

```php
$cmd = 'git '.implode(' ', array_map('escapeshellarg', $args)).' 2>/dev/null';
```

No colour suppression anywhere. `shell_exec` gives no tty so git's *auto* colour is off, which is why
step 7 is green today — but `color.ui=always` overrides auto, and then ANSI prefixes break the `^+`
tests at `:445` and again at `:585-592`. Your own reproduction on `7370e89` is the proof: exit 1 with
2 findings by default, exit 0 with zero findings under injected colour. A gate whose docblock says
**"IF IT CANNOT LOOK, IT IS NOT GREEN"** and which goes silently green on a config flag is exactly the
shape it was written to forbid.

**Fix in `git()`, not at the call sites.** Two call sites today, more later, and a per-call
`--no-color` teaches the next author to remember something. In the command string, before the
subcommand:

```
git -c color.ui=false -c diff.external= …
```

`color.ui=false` beats `always` and also covers `color.diff`; the empty `diff.external` forces the
internal diff, which the reviewer named and which `--no-color` alone would not have covered. Put the
reason in the docblock above `git()` — one sentence, that this function's output is parsed by
`^+` tests so it must be byte-plain regardless of the invoking user's git config.

**Then bite-prove it the way the reviewer did**: re-run `7370e89` with `color.ui=always` injected and
show exit 1 with 2 findings restored. Paste both runs raw.

**And give it a witness if the fixture can carry one.** `gclRun` builds a temp repo; if you can set
`color.ui=always` in that repo's config for one arm, the arm belongs in `GrantsConvergenceLintTest`
and it is the only thing that stops this regressing. If the fixture cannot carry it — if the config
does not reach the subprocess the way the arm would need — say so plainly and leave it unarmed with a
note in the docblock. Do not fake an arm that passes for a different reason.

## 2. Finding 2 — accepted, and widen the fix: stop asserting inertness as a property of the file

Your diagnosis is right and I verified the facts, which are narrower than either of us stated:

| ref | `2026_08_03_100000` | `2026_08_05_100000` |
|---|---|---|
| `origin/main` (`1ee3d59`, the base `bin/quality-promote:79` actually passes) | **PRESENT** | **ABSENT** |
| local `main` (`5eda307`, 2026-07-31, **32 commits behind** `origin/main`) | ABSENT | ABSENT |

So your statement is exactly right against the base that matters: over `origin/main` the `08_05`
markers are `A` and parse into `$declared`, and the `08_03` ones do not. I nearly refuted you by
checking local `main` — which would have made *both* docblocks false — and that near-miss is the
whole lesson.

**The code is correct and must not change.** Relative to `origin/main`, `2026_08_05_100000` has not
run on anything main-derived, so exempting on its markers over that range is the gate working, not
failing.

**What changes is both docblocks, and the reframing is the fix.** Do not merely correct `08_05`'s
paragraph to say "except on promote" and leave `08_03`'s standing as an absolute — `08_03`'s claim is
true today only because it happens to sit on `origin/main`, and it becomes false the moment someone
runs the lint against a base that predates it. "Permanently inert" is not a property either file can
carry. State instead **which base makes the markers inert and that a wider base re-arms them**, and
name `bin/quality-promote`'s `origin/main` as the concrete wider base. This is your own range
argument from 800 lines away in the same file; the two paragraphs should now read as if the same
person wrote both, because they did.

## 3. Finding 3 — fix, and it is the report, not the code

The report is the record and a record that describes a run which cannot exist is worse than no
record. Correct the paragraph: state that over `staging...HEAD` the lint short-circuits at the
seeder-unchanged early return (`:447`), that the OLD/NEW counts came from the extracted loop rather
than a lint run, and that the fix's evidence is the out-of-tree reproduction plus MARKER 9b.

I am correcting the same error in `docs/handoff/converges-marker-followup-3-brief.md` myself,
including the `:411` claim at `:103`. Do not touch that file.

## 4. Finding 4 — fix both sites

`RbacSeeder.php:377-391` → `:377-394` at
`database/migrations/2026_08_05_100000_converge_finance_access_grants.php:36` **and `:158`**. I
verified both. `:158` is inside the `RuntimeException` an operator reads at 2am while a migration is
aborting, which makes it the one that most needs to point at the right lines.

`docs/handoff/finance-access-convergence-brief.md:35` carries it too. Leave that one — a handoff brief
is a dated record of what was said at the time, not a live citation, and editing history to match the
present is a habit worth not starting.

## 5. Finding 5 — accepted as a standing rule, effective now

Reformatting evidence — hand-adding a line-number column to `sed -n` output, eliding a watched-red
haystack — defeats the only thing the evidence is for. I verify against the repo, so the substance
survives; what does not survive is my ability to tell a real run from a reconstructed one, and that
distinction is the whole method.

From now on: **command output in a report is pasted raw and unedited, or it is not evidence.** If it
is long, cut whole lines from the middle and mark the cut; never re-render it. If you want a
line-numbered view, run the command that produces line numbers.

## 6. Ticket, recorded not worked

The accepted prose false positive. Removing your own reworded lead-in rather than tightening the
parse was the right call on this branch — the tolerance predates you and narrowing a marker parser
inside an unrelated fix is how parsers acquire silent holes. But the consequence is now on the record:
any migration whose *prose* contains the marker word is reportable, and the next author to write about
convergence in a docblock trips it. Ticket, with that sentence.

## 7. Do NOT

- Do not change the exemption behaviour of `2026_08_05_100000`'s markers on any range.
- Do not touch `$markersOnModified`, `$addedMigrations`, `$declared`, or `--diff-filter=A`.
- Do not revert `:377-394` or `:169`.
- Do not tighten the prose parse.
- Do not push `staging` before this merges.
- Do not start `docs/handoff/credit-note-approver-move-brief.md`.

## 8. Report, then merge, then push

`bin/quality` 13/13, and this time **`bin/quality-promote` as well** — finding 2 is a promote-range
claim and the promote gate is the only thing that exercises it. Report as:

- `git()` after the fix, and both `7370e89` runs raw
- the colour arm, or the reason the fixture cannot carry one
- both reworded docblock paragraphs in full
- the corrected report paragraph
- the two `:377-394` sites
- commit count from `git rev-list --count $(git merge-base staging HEAD)..HEAD`, command pasted
- anything here you think is wrong

Then merge `--ff-only` and push `staging`. Report the pushed tip.

Next, in order: the fragment-resolution brief (mine to write), then
`docs/handoff/credit-note-approver-move-brief.md`.
