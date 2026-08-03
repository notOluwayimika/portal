# Brief — `@converges` marker: replace exemption 3's free-text match

One branch. Three things: redesign exemption 3 of the grants-convergence lint, close the two
unproven aborts on `2026_08_05`, and one unrelated one-liner.

Suggested branch: `fix/converges-marker`.

---

## Step 0 — before writing anything

Run `./bin/quality origin/main` and paste the grants-convergence-lint section.

`2026_08_05_100000_converge_finance_access_grants.php` is on `staging` but not on `main`, so it is
still an ADDED migration in the release-scoped diff (`bin/quality-promote:79` runs
`./bin/quality origin/main`). I believe it exempts nothing there — that convergence fixed historical
drift and did NOT change `grantsMap()`, so there is no finding for it to exempt, and tightening
exemption 3 cannot turn the release gate red. **If step 0 shows any exempted line citing that
migration, stop and tell me** — my model of the release window is wrong and the sequencing changes.

---

## The defect

Exemption 3 (`bin/ci-grants-convergence-lint.php:572-578`) decides that a migration converges a
`(role, permission)` pair by looking for the two names *anywhere in the file's text*:

```php
$migration = null;
foreach ($addedMigrations as $path => $content) {
    if ($role !== null && namesPermission($content, $permission) && namesRole($content, $role)) {
        $migration = $path;
        break;
    }
}
```

Every convergence migration we write names the roles it **excludes** and names roles in prose. I ran
the lint's own two predicates verbatim against the real
`2026_08_05_100000_converge_finance_access_grants.php`:

```
namesPermission('finance.access')  = true
namesRole(...) TRUE for: super_admin, admin, principal, head_of_school,
                         registrar, accounts_officer, accounts_supervisor,
                         finance_lead, internal_auditor        → 9 of 14 roles
```

`internal_auditor` matches off the sentences that say it must NOT receive the permission.
`registrar` matches off the words *"registrar cache flushed after"*. That file is a blanket
exemption carrier for 9 of the 14 roles for as long as it sits inside a diff range.

**This is not fixable by rewording one migration.** Every future convergence migration will have the
same property, because documenting the exclusions is the right thing for a migration to do. The
predicate is being asked a question it cannot answer: prose mentions and author assertions are the
same bytes.

Third repair to this same lint for the same class — `d08edf0` (substring), `dde75e4` (silent
greens), now prose. Fix the mechanism, not the instance.

---

## The change

Exemption 3 stops reading prose and reads a **declaration**. The migration author states each pair
the migration converges, one per line:

```php
 * @converges head_of_school finance.access
 * @converges principal finance.access
```

A pair is exempt only if some added migration in the diff declares that exact pair. Nothing else in
the file's text can exempt anything.

### 1. Extract markers once, next to `$addedMigrations` (`:466-474`)

Build a set of declared pairs alongside the existing loop, so the per-finding code is a lookup, not
a rescan:

```php
$declared = [];   // "role\0permission" => 'database/migrations/<file>'
```

Line-anchored regex over each added migration's content:

```php
'/^[ \t]*(?:\*|\/\/|#)?[ \t]*@converges[ \t]+([A-Za-z0-9_]+)[ \t]+([A-Za-z0-9_.\-]+)[ \t]*$/m'
```

**`[ \t]`, not `\s`.** Under `/m`, `\s` matches `\n`, so `\s*$` would let the tail run onto the next
line and a trailing-prose smuggle would pass. This is the whole point of the redesign — do not
loosen it. The optional `*`/`//`/`#` lead-in lets the marker live in a docblock, a line comment or a
hash comment; nothing else may precede it on the line.

### 2. Replace the loop at `:572-578`

```php
$migration = $role !== null
    ? ($declared[$role."\0".$permission] ?? null)
    : null;
```

The `$role !== null` guard is unchanged and stays load-bearing: a null inferred role still does not
exempt (`:565-571` documents why — an exemption on an unknown pair is a guess in the silent
direction).

### 3. Update the `$exemption` arm at `:583`

Currently: `"migration [{$migration}] in this diff names it AND names role [{$role}]"`.
Make it say *declares*, e.g.
`"migration [{$migration}] declares @converges {$role} {$permission}"`.

Note `GrantsConvergenceLintTest.php:490` asserts on the literal string
`names it AND names role [auditor]`, and `:238`/`:270` on `in this diff names it`. Those assertions
move with the message.

### 4. Delete `namesPermission` (`:270-273`) and `namesRole` (`:291-297`)

`:574` is the only call site of each — grep confirms it. Leaving them is dead code.

**But move their derived facts into the new marker docblock, do not lose them.** They record *why*
free text cannot work: the enum has 9 prefix pairs / 0 suffix / 0 mid, role names have the opposite
shape (`admin` ⊂ `super_admin`, `teacher` ⊂ `form_teacher`), so a free-text test needs a right
boundary on one side and both boundaries on the other — and even with both boundaries perfectly
placed it still cannot tell an assertion from a mention. That is the argument for the marker; it
belongs in the file.

### 5. Rewrite the header docblock's exemption 3 (`:38-45`)

It currently describes the two-predicate design and cross-references both functions. Replace with
the marker rule, and keep the `7370e89` example — a migration converging one of two roles must not
exempt the other, which the marker enforces by construction rather than by inference.

### 6. Rewrite the failure heredoc (`:645-649`)

Currently:

> · Ship a convergence migration that grants the permission to the role, and make its
>   content name BOTH the permission and the role …

This instruction now produces a red. Replace with the marker syntax, shown literally so the author
can copy it, including the `?` case:

```
· Ship a convergence migration that grants the permission to the role, and declare the
  pair in it, one line per pair, nothing else on the line:
      @converges <role> <permission>
  Naming them in prose is NOT enough and is no longer read — a migration that documents
  which roles it EXCLUDES would otherwise exempt them. If the role above reads `?`, the
  addition is in a shared fragment: declare a line for every pre-existing role it spreads to.
```

### 7. Echo unrecognised markers on the failing path only

If a marker's role is not in `ROLES` at head and not in `$newRoles`, or its permission is not an
enum value at head, print it under the exemption list so a typo is visible:
`⚠ 2099_..._converge.php declares @converges bursor finance.access — no such role`.

**Do not make this a hard failure.** A typo already fails the run by not exempting, so the red the
author reads is already there; a hard failure would also fire on diffs the lint currently exits
early on (`GrantsConvergenceLintTest.php:225` — seeder not in the diff at all) and would break that
arm. Right-sized: echo it, do not gate on it.

### 8. Backfill markers into the two shipped convergence migrations

`2026_08_03_100000_converge_finance_change_grants.php` and
`2026_08_05_100000_converge_finance_access_grants.php` — add the `@converges` lines for the pairs
each actually converges, in the docblock.

Comment-only edits; Laravel does not checksum migration bodies, so this is safe on environments
where they have already run. Functionally inert today (neither is an added migration in any diff
that changes `grantsMap()`). The reason to do it is that the next author copies one of these as a
template, and a template without the marker teaches the wrong thing.

---

## Test — `tests/Feature/Rbac/GrantsConvergenceLintTest.php`

### Arms that MUST be updated (they will go red otherwise)

- **`:232`** — *exemption 3 exact vs longer sibling*. Its fixture is
  `"// converge: grants '{$names}' to auditor"`. Rewrite both halves to markers:
  `@converges auditor activity_log.view` (exempt) and `@converges auditor activity_log.view_all`
  (must not exempt `activity_log.view`). The arm keeps its value — the sibling must still not
  exempt — but state in the comment that the mechanism changed from a boundary regex to exact
  equality, so a future reader does not re-derive the prefix-pair argument here.
- **`:425` (4c)** — *role A does not exempt role B*. Fixture migration becomes
  `@converges auditor activity_log.view` only. Assertion string at `:490` updates with the message.

### New arms

1. **PROSE IS NOT A DECLARATION — the reviewer's finding, armed permanently.** Fixture migration
   declares `@converges auditor activity_log.view` and, in prose, contains
   `` `bursar` deliberately does NOT receive `activity_log.view` `` plus a sentence naming
   `activity_log.view` again. Assert exit 1, that the failure block names `role: bursar`, and that
   the exemption block names auditor and NOT bursar. Reverting the fix must turn this arm red, not
   merely change a message — check that by reverting locally before you commit.
2. **NO MARKER AT ALL.** Migration names both the permission and the role in prose, no `@converges`
   line. Assert exit 1. This is the direct regression arm for the old behaviour.
3. **TRAILING PROSE DOES NOT SMUGGLE.** `@converges auditor activity_log.view and also bursar` on
   one line. Assert exit 1 for **both** roles — the line-end anchor means it declares nothing at all,
   not "declares auditor". Assert the exemption block is empty or does not cite that migration.
4. **MULTI-PAIR.** One migration with two `@converges` lines exempts both pairs in a single run.
   Assert exit 0 and both pairs cited.
5. **DOCBLOCK LEAD-IN.** The same marker written as ` * @converges auditor activity_log.view`
   inside a `/** … */` block exempts identically to a `//` line. Cheap, and it is the form every
   real migration will use.

Do not add an arm for the unrecognised-marker echo unless it falls out easily — it is a message, not
a gate.

---

## Second: the two unproven aborts on the finance-access migration

`tests/Feature/Rbac/FinanceAccessGrantConvergenceTest.php` has 4 arms. The offender pre-flight is
proven; the other three exits are not. Only one of them can fail *silently*, so only that one is
worth arming plus its sibling:

1. **Fresh install returns quiet green.** Delete every `finance.*` permission row, run `up()`,
   assert it returns without throwing and writes no grant and no `activity_log` row. This is the
   `:79-90` guard.
2. **Broken substrate aborts.** `finance.*` rows present but `finance.access` absent, run `up()`,
   assert it throws and that the message names `rbac:sync`. This is `:110`. It is the arm that
   matters: without it, arm 1's guard could widen by accident and swallow a real breakage as a quiet
   green, which is the one failure mode of this migration nobody would ever see.

`:147` (governed role missing) and `:176` (idempotency short-circuit) — `:176` is already covered by
the idempotency arm; `:147` throws loudly and I am leaving it as a ticket, not arming it here. If
you disagree, say so rather than silently adding it.

---

## Third: one unrelated line

`app/Console/Commands/AuditDutySeparation.php:55`:

```php
'user' => $user->email ?? ('user#'.$user->id),
```

PR #195 stopped credential material reaching `activity_log.properties`; this line still prints real
email addresses into audit output. Change it to `'user' => 'user#'.$user->id,` unconditionally —
drop the `??` entirely. Separate commit, so it can be reverted independently.

---

## Report

Run `bin/quality` (13/13) before reporting. Report as:

- the step 0 output, and whether it matched my prediction
- which existing arms you had to update and what the message change was
- for each new arm: red-before / green-after, proven by actually reverting the fix
- anything in this brief you think is wrong — including the marker syntax itself if you have a
  better one, and including step 7 if you think the echo should be a gate after all
