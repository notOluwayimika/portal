# Report — follow-up 4: colour-blind `git()`, and two docblocks asserting a range property

**Branch:** `fix/converges-lint-colour-blind`, merged `--ff-only` into `staging` @ `887754e`.
**This report covers `49e77a4..887754e`** (one commit).
**Brief:** `docs/handoff/converges-marker-followup-4-brief.md`

```
$ git rev-list --count $(git merge-base staging HEAD)..HEAD
1
```

(1 at the time of writing; 2 once this report commits. Measured on the branch before the merge; from
`staging`'s own tip it is 0, since the merge was a fast-forward.)

`bin/quality` 13/13. `GrantsConvergenceLintTest` 23 passed / 130 assertions;
`FinanceAccessGrantConvergenceTest` 6 / 38.

**`bin/quality-promote` could not be run. Read §Blocked before the push.** Its substance — finding
2's promote-range claim — was exercised directly instead, in §2.

Nothing from §7 is in the commit. `git show --stat 887754e` touches the lint, its arm file, the two
convergence migrations and the follow-up-3 report. `$markersOnModified`, `$addedMigrations`,
`$declared` and `--diff-filter=A` are byte-identical to `49e77a4`; `:377-394` and `:169` are intact;
the prose parse is untouched; `docs/handoff/converges-marker-followup-3-brief.md` is untouched by me
(it carries your own uncommitted edit — see §Blocked).

---

## 1. `git()` after the fix

```php
function git(string ...$args): string
{
    if (in_array($args[0] ?? '', ['diff', 'show', 'log'], true)) {
        array_splice($args, 1, 0, '--no-ext-diff');
    }

    $cmd = 'git -c color.ui=false '
        .implode(' ', array_map('escapeshellarg', $args)).' 2>/dev/null';

    return (string) shell_exec($cmd);
}
```

**Your prescribed `-c diff.external=` does not work, and fails loudly rather than silently — which
is how I caught it.** Git runs the empty string as a command:

```
git                                        len=6287  diff --git a/database/seeders/RbacSeeder.php b/database/seeders/RbacSeeder.php\nindex 16cf
git -c color.ui=false                      len=6287  diff --git a/database/seeders/RbacSeeder.php b/database/seeders/RbacSeeder.php\nindex 16cf
git -c diff.external=                      len=117  error: cannot run : No such file or directory\nfatal: external diff died, stopping at data
git -c color.ui=false -c diff.external=    len=117  error: cannot run : No such file or directory\nfatal: external diff died, stopping at data
```

The working form is `--no-ext-diff`, which is a **diff-family** option and not a main-command one —
`git --no-ext-diff diff` is not valid — so it is injected after the subcommand. That is still one
place, which is what §1 was actually asking for. Verified against a global config carrying both
hostile settings:

```
hostile cfg, unguarded   len=127    ansi=0   plusLines=0
hostile cfg, GUARDED     len=6287   ansi=0   plusLines=10
```

`-c color.ui=false` beats a config file's `always` because command-line `-c` is applied last. Both
facts are in the docblock above `git()`, with the reason the function's output must be byte-plain.

### The bite-proof, three configs × two versions

```
AFTER THE FIX
  default             : exit=1 findings=2 first=grants-convergence-lint: 2 grant addition(s) in database/seeders/RbacS
  color.ui=always     : exit=1 findings=2 first=grants-convergence-lint: 2 grant addition(s) in database/seeders/RbacS
  hostile ~/.gitconfig: exit=1 findings=2 first=grants-convergence-lint: 2 grant addition(s) in database/seeders/RbacS

BEFORE THE FIX (stashed)
  default             : exit=1 findings=2 first=grants-convergence-lint: 2 grant addition(s) in database/seeders/RbacS
  color.ui=always     : exit=0 findings=0 first=grants-convergence-lint: OK — no unexempted grant addition in database
  hostile ~/.gitconfig: exit=0 findings=0 first=grants-convergence-lint: OK — database/seeders/RbacSeeder.php is uncha
```

All six runs are `php bin/ci-grants-convergence-lint.php '7370e89^' 7370e89`; `hostile` is
`GIT_CONFIG_GLOBAL` pointing at a file carrying `color.ui = always` and `diff.external = /bin/false`.
Two of three configs were a silent green before. The full first run, raw:

```
$ php bin/ci-grants-convergence-lint.php 7370e89^ 7370e89

grants-convergence-lint: 2 grant addition(s) in database/seeders/RbacSeeder.php that rbac:sync will NOT apply (3abc2bd..7370e89):

  ✗ finance.access  @  database/seeders/RbacSeeder.php:219
      role: head_of_school (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: PermissionEnum::FINANCE_ACCESS->value,
  ✗ finance.access  @  database/seeders/RbacSeeder.php:270
      role: principal (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: PermissionEnum::FINANCE_ACCESS->value,

  3 addition(s) in the same diff were EXEMPT:
  ✓ finance.discount-policy.change.submit  @  database/seeders/RbacSeeder.php:220 — permission is NEW in this diff (lands in $newPermissions)
```

### The arm — the fixture can carry it

**MARKER 10** exists. Your §1 guessed it might not be possible because "`gclRun` builds a temp repo";
it does not — `gclRun` runs the real script in `base_path()` against unreferenced fixture commits in
the repo's own object database. So the injection point is the **subprocess environment**, not a repo
config: `GIT_CONFIG_COUNT` / `GIT_CONFIG_KEY_n` / `GIT_CONFIG_VALUE_n` via `Process::env()`, which
`shell_exec` inherits and which git treats as `-c`-equivalent. `gclCommit` already proves env reaches
the subprocess (`GIT_INDEX_FILE`).

The arm runs one fixture four ways — plain, `color.ui=always`, `diff.external=/bin/echo`, and both —
and asserts the exit code and both flagged roles are identical to the plain run. `/bin/echo` rather
than a missing binary on purpose: a driver that **fails** makes git die loudly, while one that
**succeeds** and prints something else is the silent-green shape.

Watched red, `git()`'s guard reverted to `'git '`:

```
failed 22 passed / 1 failed
   config_cannot_change_the_verdict__forced_colour__external_di :: Failed asserting that 0 is identical to 1.
```

One arm, on the exit code. Restored: 23 passed, 130 assertions.

---

## 2. Both docblock paragraphs, reworded

Identical text in both migrations, replacing the "RECORDED FOR THE READER, UNREADABLE BY THE GATE —
and permanently so" paragraph:

```
 * WHETHER THE GATE READS THESE LINES IS A PROPERTY OF THE BASE, NOT OF THIS FILE. Exemption 3
 * collects markers only from migrations the diff ADDS (`--diff-filter=A`), because a migration
 * already present on the base has already RUN and a marker on it would declare a convergence nothing
 * performed. So these lines are INERT over any base that already contains this file — the per-push
 * `staging` base, today — and LIVE over any base that predates it. That is not hypothetical:
 * `bin/quality-promote:79` runs `./bin/quality origin/main`, a wider range than the per-push one, and
 * a convergence migration sits on `staging` for a whole milestone before it reaches `main`.
 *
 * "Permanently inert" is not a property a file can carry. To know which side of it you are on, ask
 * the base rather than this comment: `git cat-file -e <base>:<path to this file>`.
 *
 * Either way this file is not a template for the marker. A NEW convergence migration is ADDED by its
 * own diff, so its markers are read on the range where they matter; these were backfilled.
```

**Deliberately identical in both files, and stating no per-file status.** Your §2 asked me not to
correct `08_05` and leave `08_03` standing as an absolute. I went further: neither paragraph now
claims a status at all, because a status decays — `08_03`'s is true only while it sits on
`origin/main`, and `08_05`'s flips the moment the next milestone promotes. The paragraph states the
rule and hands the reader the one command that answers it for their base.

### The promote-range facts, exercised directly

```
$ git rev-parse --short origin/main
1ee3d59

$ php bin/ci-grants-convergence-lint.php origin/main    # exactly what bin/quality-promote:79 invokes via bin/quality:141
grants-convergence-lint: OK — no unexempted grant addition in database/seeders/RbacSeeder.php (1ee3d59..887754e; 0 exempted).
exit=0

$ git diff --name-status --diff-filter=A origin/main...HEAD -- database/migrations/
A	database/migrations/2026_08_02_100000_create_notification_tables.php
A	database/migrations/2026_08_04_100000_revoke_internal_auditor_cross_school.php
A	database/migrations/2026_08_05_100000_converge_finance_access_grants.php
```

`2026_08_05_100000` is `A` over the promote base and `2026_08_03_100000` is not — your table exactly.
Its two markers therefore parse into `$declared` on that range. `0 exempted` because the release-range
seeder diff adds no grant line at all, so nothing needs exempting: the markers are parsed and unused,
which is the gate working. No behaviour changed; the code was and is correct.

---

## 3. The corrected report paragraph

`docs/handoff/reports/fix-converges-marker-followup-3.md`, replacing the "still names both files"
paragraph, marked as a dated correction rather than silently rewritten:

```
**CORRECTED 2026-08-03 (follow-up 4, reviewer finding 3).** The paragraph that stood here said the
notice "still names both files" over `staging...HEAD`. It does not, and it cannot: the lint's notice
is on the failing path only, and over that range `database/seeders/RbacSeeder.php` is not in the diff
at all, so the run short-circuits at the seeder-unchanged early return —
`grants-convergence-lint: OK — database/seeders/RbacSeeder.php is unchanged in this diff`, `exit 0` —
long before `$markersOnModified` is printed. The brief's "live on this branch right now" premise was
unreachable for the same reason, and so was my correction to it.

**The table above is therefore a measurement of the extracted block, not of a lint run**, and it is
labelled as such. The evidence for the fix is the reviewer's out-of-tree reproduction plus MARKER 9b,
which holds the marker byte-identical between base and head and so discriminates the two
implementations end-to-end through the real script. The counts still stand: over the comment-only
range the count goes `3→0` and `2→0`, which is the false accusation removed.
```

---

## 4. The two `:377-394` sites

```
$ grep -n "377-394" database/migrations/2026_08_05_100000_converge_finance_access_grants.php
36: * (`RbacSeeder.php:377-394` records the grant as DECIDED and UNIMPLEMENTED — Phase 2, and per-school,
165:                .$detail.'. internal_auditor holding it is a DECIDED-but-UNIMPLEMENTED grant (RbacSeeder.php:377-394) — '
```

`:165`, not `:158` — the docblock reframing above it is net +7 lines, so the line moved inside the
same commit. Re-derived after the edit rather than carried from your brief.
`docs/handoff/finance-access-convergence-brief.md:35` left alone, per §4.

---

## 5. Blocked: `bin/quality-promote` could not run

Two reasons, and the first is mine to report rather than route around.

**a. The working tree is dirty, and the dirt is yours.** `bin/quality-promote:55` refuses on any
`git status --porcelain` output. Right now:

```
$ git status --short
 M docs/handoff/converges-marker-followup-3-brief.md
?? docs/finance/authority-matrix-decisions-2026-08-03.md
?? docs/handoff/converges-marker-followup-4-brief.md
?? docs/handoff/credit-note-approver-move-brief.md
?? docs/handoff/finance-access-convergence-brief.md
```

The tracked modification is your in-progress edit to the follow-up-3 brief, which §3 told me not to
touch. I did not stash it, commit it, or work around it — a 20-minute gate run with your uncommitted
work parked in a stash is not a trade I will make without being asked.

**b. The ordering in §8 cannot hold.** `bin/quality-promote:60` also requires `HEAD` to be identical
to `origin/staging`. So it can only run **after** the push, never before it. §8 asks for
promote → merge → push; the script's own preconditions are merge → push → promote.

**What I did instead**, because the reason you wanted it was finding 2 and nothing else:
`bin/quality-promote:79` runs `./bin/quality origin/main`, which reaches the grants lint as
`php bin/ci-grants-convergence-lint.php origin/main` (`bin/quality:141`). That exact command is run
and pasted in §2, along with the `--diff-filter=A` collection over the promote range. The
promote-range claim is exercised; the rest of `quality-promote` (release-scoped lint, `quality-clean-db`
migrate-from-zero and rollback) is not, and this commit touches no migration behaviour and no schema.

**To finish it:** clear the two lines above — commit or discard the follow-up-3 brief edit, and let me
know whether the four untracked briefs should be committed — then `bin/quality-promote` on pushed
`staging`. One command, and I will run it.

---

## 6. Ticket recorded, not worked

**The accepted prose false positive is now load-bearing on authors.** `$unparsedMarkers` and
`$markersOnModified` both count the literal marker word with no boundary, so any migration whose
**prose** contains it is reportable — and the next author to write about convergence in a docblock
trips it, on an already-red run. It has already bitten once: my own reworded lead-in in `f871ba8`
contained the word and inflated the count from 0 to 1 per file, which I removed rather than tighten
the parse. Ticket, not worked here: narrowing a marker parser inside an unrelated fix is how parsers
acquire silent holes, and the tolerance predates this arc.

---

## Anything here I think is wrong

- **§1's `-c diff.external=`** — wrong as written, corrected above with the measurement. Flagging it
  because a prescription pasted into a brief is the thing most likely to be copied verbatim next time.
- **§8's ordering** — impossible against the script's own preconditions, as above.
- **§1's "gclRun builds a temp repo"** — it does not; it runs the real script against unreferenced
  objects in this repo. That is what made the arm possible, so the correction is in my favour, but the
  model matters for whoever writes the next arm.
- **Everything else in the brief I agree with**, including all three of §0's self-corrections. §0c in
  particular is the one that matters: my "still names both files, and that's right" was as unreachable
  as the claim it corrected, and I asserted it with more confidence than the original.

## Still open

- The fragment-resolution blind spot — disclosed, not closed. Yours to brief.
- `bin/quality` step 7's step-name carries the same over-claim as the qualified sentence.
- `"the other two governed roles"` in `2026_08_03_100000` — deferred again, carried verbatim.
- `--diff-filter=M` misses `R`; a migration carrying a marker and untouched at head is in neither
  list. Both unarmed, both fail safe.
- Nothing driven against the dev database or a real app flow. This commit is a lint script, one arm,
  and comment text.
