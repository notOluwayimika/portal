# Brief — `@converges` follow-up 2: disclose one hole, extend one notice, then merge

Continue on `fix/converges-marker` @ `e6600eb`. One commit. Then merge.

Verified against the repo at `e6600eb`, not against your report. Both fixes confirmed landed;
findings 1 and 2 confirmed real, but finding 2 is two things and only one of them is a defect.
Findings 3 and 4 are tickets — I agree, do not do them here.

Your `{@see $inferRole}` → `see $inferRole in this file` deviation is right and I should have caught
it: `{@see}` is an IDE affordance, and this string's only reader is a terminal.

---

## Finding 1 — confirmed, and it is the dominant idiom. Disclose here, fix next.

I verified both halves:

- Permission resolution accepts exactly two forms — `PermissionEnum::X->value`, and a quoted string
  that is a real enum value at head. `...$activityAdmin,` is neither, so an added spread line
  resolves zero permissions and generates zero findings.
- `RbacSeeder::grantsMap()` builds `'admin' => [` from five consecutive spreads before its first
  literal permission. This is not an edge shape; it is how the map is written.

So: spreading an existing fragment into a pre-existing role grants N pre-existing permissions to a
pre-existing role — `rbac:sync` grants none of them — and this lint exits 0. That is the canonical
defect class, invisible, via the map's most common line.

**What lands on this branch: the disclosure only.** I first sent you at the "by construction" phrase
and that was wrong — I checked it after writing. `:44`'s "by construction" says the MARKER is
per-pair, which is true and narrow; amending it would make a correct sentence read as an admission.
The over-claim is the header at `:30`:

> THE RULE. Fail when the diff `<base>..<head>` adds a permission to `grantsMap()` and NONE of these
> four exemptions holds:

An added `...$fragment,` adds permissions to `grantsMap()`, satisfies no exemption, and does not
fail. So the rule as stated is false. Qualify it there — the rule is what an author reads first —
in these terms:

> Coverage is per-pair for permissions this lint can RESOLVE. It resolves `PermissionEnum::X->value`
> and quoted enum values. It does NOT resolve `...$fragment,` — an added spread of a pre-existing
> fragment into a pre-existing role grants every permission in that fragment and produces no
> finding. That is a live blind spot in this gate's own defect class, not a shape outside it.

I am telling you to write this because the sentence being replaced is the failure mode I named to
you last brief in the other direction: a false justification in a comment is worse than none,
because the next author reasons from it. It applies to my rationale too.

**What does NOT land here: the resolution.** Resolving `...$fragment,` means finding
`$fragment = [...]` in the head seeder and extracting its enum values — a behaviour change that
multiplies finding volume and needs its own step 0 and its own arms. It is not a rider on a
text-fix commit.

It is also not backlog. It is the next brief, immediately after this merges. Say so in your report
and I will write it. A disclosed blind spot with no dated successor becomes a forgotten one.

Note in passing, because it constrains that design: at a spread line `$inferRole` resolves a REAL
role — the spread sits inside `'admin' => [`, not in a fragment above `return [`. So findings from
the resolution work will be attributable, and exemption 3 will work on them normally. It is fix 1's
`?` case only when the permission is added to the fragment's own definition.

---

## Finding 2 — half of this is correct behaviour. Say so, then fix the other half.

Confirmed: `$addedMigrations` is built with `--diff-filter=A`, and `$unparsedMarkers` iterates that
same array. Confirmed on this branch's own range: `--diff-filter=A` over
`merge-base(origin/staging, HEAD)...HEAD` returns ZERO migrations; both convergence migrations come
back `M`, and `2026_08_03_100000` is present on `origin/staging`.

**First, narrow it — this matters for severity.** The range is `base...head`, not commit-to-commit.
A migration added in an earlier commit OF THIS BRANCH is still `A`. So the ordinary workflow — lint
goes red, author adds the marker to the migration they committed an hour ago — works. The gap needs
the migration to predate the BASE.

**Second: "exempts nothing" is not the bug. It is the rule.** A migration already on base has
already run on every seeded environment. Adding a marker to it does not make it run again, so the
pair it now claims was never converged anywhere. Exempting on it would be a false green of exactly
the kind this gate exists to stop. Collecting `M` migrations into `$addedMigrations` would be a
regression, not a fix. Do not do that.

Write the rule down where the filter is chosen, next to `--diff-filter=A`:

```php
// A: added only, and that is a rule rather than a convenience. A migration already present on the
// base has already RUN on every seeded environment; a marker added to it now declares a
// convergence that never happened. Collecting M here would exempt on a promise nothing kept.
```

**Third: the silence IS the bug, and it is fix 2's own gap.** The author edits a pre-existing
migration, adds a valid marker, re-runs, and gets an identical red — and `$unparsedMarkers` cannot
tell them why, because it only walks added migrations. Same dead end fix 1 removed, different route.
The reviewer is right about that.

**The change.** Alongside the existing `--diff-filter=A` collection, gather modified migrations for
the NOTICE ONLY — never into `$addedMigrations`, never into `$declared`:

```php
// Markers on MODIFIED migrations, collected for the notice and nothing else. These exempt nothing
// (see the rule above) and must never reach $declared — but an author who wrote one is owed the
// reason, and $unparsedMarkers cannot give it because it walks $addedMigrations.
$markersOnModified = [];
foreach (explode("\n", git('diff', '--name-status', '--diff-filter=M', $base.'...'.$head)) as $line) {
    $parts = preg_split('/\t/', trim($line));
    if (count($parts) < 2 || ! str_starts_with($parts[1], 'database/migrations/')) {
        continue;
    }
    $count = preg_match_all('/@converges/', git('show', $head.':'.$parts[1]));
    if ($count > 0) {
        $markersOnModified[$parts[1]] = $count;
    }
}
```

Echo on the failing path only, beside the other two notices:

```
  N @converges line(s) sit on a migration that is not new in this diff (they exempt nothing):
  ⚠ database/migrations/2026_08_03_100000_….php — 3 line(s). This migration is already on the base,
    so it has already run; a marker added to it declares a convergence nothing performed. Write a
    NEW convergence migration and declare the pair there.
```

Two decisions, pre-made so you do not re-litigate them: not a gate, same reason as the other two;
failing-path only, same reason.

**Then fix this branch's own worked example.** I read the diff. The three markers in
`2026_08_03_100000` were ADDED on this branch, to a file already on `origin/staging`. The prose
above them says they are "declared for `bin/ci-grants-convergence-lint.php`'s exemption 3".

They are not, and they never will be. The file is on staging, so no future `base...head` will ever
mark it `A`. These markers are permanently inert — the reviewer is right that the branch ships two
worked examples of the shape it dead-ends on.

Do not delete them; they record the author's intent and that has value. Reword the lead-in to say
what is true — recorded for the reader, unreadable by the gate, because this migration predates it,
and a pair needing exemption from here on gets a NEW migration. Paste the before/after. A worked
example that does not work is the most expensive kind of documentation, because it is the one the
next author copies.

---

## Test

**MARKER 9 — a marker on a modified migration is reported and exempts nothing.** Fixture: a
migration present on the base carrying a syntactically valid `@converges <role> <permission>` for a
pre-existing pair, plus the seeder addition of that pair on the branch. Assert exit 1, that the
exemption block does not cite that migration, and that the new notice names the file. Revert the
notice and watch it go red — actually revert, do not read.

No arm for finding 1. A test asserting the current silence would pin the blind spot in place;
its arm belongs to the resolution brief, red first.

---

## Two things from your report I am not waving through

1. **The MARKER 3 edit.** You flagged it correctly and your reasoning is sound, but "an existing arm
   went red and I edited it" is a shape that needs eyes and the bridge dropped before I could read
   it. Paste MARKER 3's assertion block before and after in the next report. Cheap, and it closes it.
2. **The `⚠` count in 0a.** You reported the filename absent from all 40 lines of output. Good — that
   is the assertion I wanted and it is stronger than "no exempt block".

---

## Do NOT do on this branch

Findings 3 and 4 are tickets and I agree with your split:

- The `?` remedy asserts one cause; `$inferRole` also returns null on a map key not in `ROLES`. Loud
  at sync time, so a detour not a miss.
- Report header says "One commit" over `staging @ 8c354a5`; the branch carries four.

Plus the four from the last brief, still not this branch: `RbacDiffGrants.php:51`, the +9 line-ref
drift, `2026_08_03_100000:38` "the other two governed roles", the mislabelled §Step 0 artifact.

---

## Report

`bin/quality` 13/13 before reporting. Report as:

- the docblock diff for finding 1's disclosure, and the `--diff-filter=A` rule comment
- MARKER 9 red-before / green-after, proven by reverting
- MARKER 3's assertion block, before and after
- `2026_08_03_100000`'s marker prose, before and after, or a statement that it needed no change
- anything here you think is wrong — specifically, whether the modified-migration notice should
  instead be a gate, given that unlike the other two notices this one has a known-wrong author
  action behind it

Then merge.
