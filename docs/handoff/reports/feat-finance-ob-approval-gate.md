# §9 step 4c — the opening-balance approval gate

> **ROUND 2 (2026-08-09) — read §R first.** `origin/staging` was merged in after PR #218
> (`executive_director`). The checker side moved off `head_of_school`, and a **forcing** convergence
> migration was found to strip all three 4c grants on deploy. §R records that round; §0–§10 below are
> round 1 and are left as written except where §R supersedes them.

---

## §R. Round 2 — the ED merge, and the trap underneath it

**Base moved.** `origin/staging` @ `c200d08` merged into the branch at `c161aa4`. Conflicts in
`RbacSeeder.php` (resolved to staging's side — HoS keeps no finance, mine included) and
`rbac-grants-baseline.json` (resolved by **re-derivation**, not by choosing a side — §R4).

### R1. My brief's premise was wrong, and the correction is the reusable part

The brief said *"no migration is needed or possible"* because the three permissions are new and
convergence-lint exemption 1 waives one. **That conflates two questions:**

1. **Does the LINT demand a migration?** No — still true. A new permission lands in
   `$newPermissions`, `rbac:sync` grants it per `grantsMap()` everywhere, no drift to catch.
2. **Does the grant SURVIVE A DEPLOY?** The lint says nothing about this. And the answer was **no**.

`2026_08_06_100000_move_head_of_school_finance_to_executive_director`'s TARGET is **forcing** — each
governed role's `finance.` slice is made to **equal** a frozen literal, not to contain it. On the
runbook order (`rbac:sync`, then `migrate`) the seeder writes the grants and that migration revokes
the two it governs. No later `rbac:sync` restores them: by then the permissions are not new, and
`RbacSeeder::sync()` grants an existing role only permissions created in that same run.

**Measured on `portal_testing`, not reasoned** — this is the probe output that produced the decision:

```
BEFORE migration (seeded map):
  executive_director OB: ["finance.opening-balance.approve","finance.opening-balance.reject"]
  accounts_supervisor OB: ["finance.opening-balance.submit"]
AFTER migration:
  executive_director OB: []
  accounts_supervisor OB: []
```

`accounts_officer`'s `.submit` **survives** — that role is not in the forcing TARGET. **Checked, not
assumed**, which is why the repair governs two roles and not three.

### R2. The repair — roll forward, never edit the frozen act

`database/migrations/2026_08_09_110000_converge_opening_balance_grants.php`. Dated after the forcing
migration. **Additive only** — it grants, it revokes nothing, and there is no revoke branch for
anyone to extend later. `2026_08_06_100000`'s executing half is untouched.

Its post-write duty-separation walk is scoped to what the run actually granted, and — unlike
`2026_08_06_100000`'s, whose own retraction box records that its walk can never fire — **this one
can**: the migration puts a maker (`accounts_supervisor`) and a checker (`executive_director`) of the
same pair onto two roles, so a user wearing both would be caught and the transaction rolled back.

**Bite-proof, raw** (seed → strip → converge → idempotency):

```
── STEP 1  after seed
   accounts_officer     OB=["finance.opening-balance.submit"]
   accounts_supervisor  OB=["finance.opening-balance.submit"]
   executive_director   OB=["finance.opening-balance.approve","finance.opening-balance.reject"]
   head_of_school       OB=[]  allFinance=0
── STEP 2  after 2026_08_06 (the strip)
   accounts_officer     OB=["finance.opening-balance.submit"]
   accounts_supervisor  OB=[]
   executive_director   OB=[]
   head_of_school       OB=[]  allFinance=0
  converge-opening-balance-grants: granted [finance.opening-balance.approve, finance.opening-balance.reject] to [executive_director]
  converge-opening-balance-grants: granted [finance.opening-balance.submit] to [accounts_supervisor]
── STEP 3  after 2026_08_09_110000 (the convergence)
   accounts_officer     OB=["finance.opening-balance.submit"]
   accounts_supervisor  OB=["finance.opening-balance.submit"]
   executive_director   OB=["finance.opening-balance.approve","finance.opening-balance.reject"]
   head_of_school       OB=[]  allFinance=0
── STEP 4  idempotency: second run
  converge-opening-balance-grants: already aligned — no grants changed, no activity rows written
```

`head_of_school` holds zero finance at every step, which is the property PR #218 exists to establish.

### R3. The `@converges` watched red — and the honest limit on it

**The requested red is UNREACHABLE on this branch's real diff, and that is a finding, not a
shortcut.** `bin/ci-grants-convergence-lint.php:1220-1226` is a `match(true)`: exemption 1
(*permission is NEW*) is the **first** arm, so it fires for all four pairs and exemption 3 (the
marker) is never consulted. Removing a marker changes nothing:

```
=== RED attempt (marker for AS/submit removed), base origin/staging ===
grants-convergence-lint: OK — no unexempted grant addition ... (c200d08..20bef27; 4 exempted).
  · finance.opening-balance.submit @ ...:383 — exempt: permission is NEW in this diff
  ...
```

So on this diff the markers are **documentation, not enforcement** — which the migration's own
docblock already says, and which the round-1 review would rightly have called wallpaper if I had
claimed otherwise.

To prove the markers themselves are well-formed I built a **synthetic range** where exemptions 1 and
2 cannot fire — base `59c0f37` (enum cases present, ED role present, convergence migration absent),
head `728f076` (grant added + migration added):

```
=== GREEN — only @converges can carry it ===
grants-convergence-lint: OK — no unexempted grant addition (59c0f37..728f076; 2 exempted).
  · finance.opening-balance.approve @ ...:425 — exempt: migration [.../2026_08_09_110000_converge_opening_balance_grants.php] declares @converges executive_director finance.opening-balance.approve
  · finance.opening-balance.reject  @ ...:426 — exempt: migration [.../2026_08_09_110000_converge_opening_balance_grants.php] declares @converges executive_director finance.opening-balance.reject

=== RED — the .approve marker removed ===
grants-convergence-lint: 1 grant addition(s) ... that rbac:sync will NOT apply (59c0f37..44180e0):
  ✗ finance.opening-balance.approve  @  database/seeders/RbacSeeder.php:425
      role: executive_director (INFERRED from the nearest preceding '<role>' => [ — verify it)
  1 addition(s) in the same diff were EXEMPT:
  ✓ finance.opening-balance.reject  @ ...:426 — migration [...] declares @converges executive_director finance.opening-balance.reject
```

Per-pair, exactly as designed: `.approve` flags, `.reject` still carried by its own marker. The
scratch branch was deleted; the tree is unchanged.

### R4. Oracles — re-derived, not hand-resolved

The `rbac-grants-baseline.json` merge conflict was **not** resolved by picking a side. I took
staging's version as the base, then re-ran the three oracles in the required order against a freshly
migrated + seeded `portal_testing`: `rbac:sync` (via `migrate:fresh --seed`) →
`rbac:derive-access` → the baselines (the grants map recomputed by the exact expression
`PermissionEnumTest` uses, then `rbac:derive-map`).

Result, diffed against `origin/staging` rather than against my pre-merge state:

- `rbac-grants-baseline.json` — **+6 −2**: `.submit` into `accounts_officer` and
  `accounts_supervisor`, `.approve` + `.reject` into `executive_director`. Nothing else moved.
- `route-middleware-baseline.json` — **one line**, the derived approvals-route middleware string.
- `route-access-map.json` — **zero diff against staging.** Which retires round 1's §6 finding: PR
  #218 regenerated it, so the ten-route staleness I reported is gone. Re-derivation produced a
  byte-identical file, which is the strongest available evidence that nothing was hand-edited.

### R5. Item 2 — the §8 / U16 queue finding, re-measured

**It stands, unchanged.** `git diff 6890edb origin/staging --stat -- resources/js routes/ app/Finance`
returns **nothing**: PR #218 touched no frontend, no routes and no Finance code. So the count is
still four pending feeds at the API (`credit-notes`, `void-requests`, `fee-schedule-changes`,
`discount-policy-changes`), **two** of them rendered by `approvals.tsx:72-75`, and now **five**
request types in the domain. §8's "six on the queue" is no closer.

What #218 *did* change is **who can open the queue**: the derived route gate now admits
`executive_director` and no longer `head_of_school`.

### R6. Item 3 — the illegal pair DISSOLVES, and the workaround is gone

**Answer: it dissolves.** Round 1's regression was `accounts_supervisor` holding `.submit` against
`head_of_school` holding `.approve`. `head_of_school` now holds **no finance at all**, so that pair
cannot form. The ARM 4 workaround I added in round 1 has been **removed in full**, and
`FinanceChangeGrantConvergenceTest` passes 6/6 with no substitute.

**And the larger question the brief asked me to name if it did not dissolve — it does not arise, but
here is the derivation so it is not taken on faith.** The checker side now sits on
`executive_director`, so the pair to worry about is `accounts_supervisor` + `executive_director` on
one person. That combination was **already illegal before 4c**: AS holds
`finance.fee-schedule.change.submit` and ED holds `finance.fee-schedule.change.approve`. 4c adds a
second pair to an already-forbidden combination; it forbids no combination that was previously
allowed. `accounts_supervisor` remains a maker-and-viewer seat, and the test now pins that as a
**property** (`no .approve, no .reject`) alongside the exact list, so the list cannot be edited into
a lie.

### R7. `ARM 3`'s detach count is 10, not 9 — expected

`MoveHosFinanceToEdConvergenceTest` ARM 3 counts `permission_detached` events from
`2026_08_06_100000`. 4c gives `accounts_supervisor` a fifth finance ability in the seeded map while
that migration's frozen TARGET still names two, so the forcing diff revokes one more than before.
Said at the assertion, not in a commit message, so the next reader does not treat it as drift. ARM 0
(9→11 on ED, 6→7 on AS) and ARM 5 (6→7) moved for the same reason and carry the same note.

Two exact-list pins in `FinanceRoleRealignmentTest` also gained entries — `executive_director` (+2
checker) and `accounts_supervisor` (+1 maker) — because those roles **genuinely hold** them.
`head_of_school`'s `toBe([])` was **not** touched, which was the arm the brief warned about. Each
edited list now carries a property assertion beside it (ED holds no `.submit`; AS holds no
`.approve`/`.reject`) so an equality array cannot silently stop being about the thing it names.

### R7b. `bin/quality` round 1 failed on a gate my PROSE tripped

The first round-2 `bin/quality` failed the ratchet with one new failure:

```
✗ tests/Feature/Rbac/MigrationsDoNotReadTheSeederMapTest.php::it no migration reads the seeder grants map
  --- Expected: []
  +++ Actual:   ['2026_08_06_100000_move_head_of_school_finance_to_executive_director.php',
                 '2026_08_09_110000_converge_opening_balance_grants.php']
```

`MigrationsDoNotReadTheSeederMapTest.php:49` is a raw `str_contains($source, 'grantsMap')` over every
migration file — **it cannot tell a MENTION from a READ**. Both flagged files read nothing; they
*named* the accessor in a comment, and one of those comments was the trap note I had just added to
`2026_08_06_100000`.

**Fixed by rewording the prose, not by touching the gate.** Weakening it to strip comments first
would trade a real invariant for my sentence structure, and blunt-in-the-safe-direction is the right
setting for a rule whose failure mode is a migration silently re-shaping itself on replay. The
limitation is now recorded in `2026_08_09_110000`'s own docblock so the next person does not
rediscover it as a mystery.

`tests/ratchet-baseline.txt` and `phpstan-baseline.neon` were **not** touched.

The comment-only claim on `2026_08_06_100000` was re-proved after the reword: **executing half still
byte-identical to `c200d08`**.

### R9. Round 3 — the trap gets a gate

Round 2 closed by naming its own weakest part: the forcing-target trap was written in three places
and enforced by nothing. `tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php` is the
enforcement. **One test file, no helper** — the scope fuse was not tripped.

**The invariant.** For every role a forcing migration governs, every permission the grants map gives
that role inside the frozen namespace must appear **either** in that migration's `TARGET` literal
**or** in an `@converges <role> <permission>` marker on a migration dated **after** it. Uncovered ⇒
the seeder writes it and the migration revokes it on the next deploy.

**Nothing is hardcoded.** The namespace and target come from reflecting the migration's own
constants (`getConstants()` returns private ones, which is what makes it work on the anonymous class
a migration file returns); the grants from `RbacSeeder::grantsMap()`; the markers by scanning
`database/migrations/` with **the lint's own regex, copied verbatim from
`bin/ci-grants-convergence-lint.php:414`**, so the two cannot disagree. "Dated after" is a plain
string comparison on the `YYYY_MM_DD_HHMMSS_` prefix — which *is* the order Laravel runs them in, and
that ordering is the load-bearing part: a marker on a migration running *before* the forcing one
would be re-stripped by it.

**One forcing migration exists, and the test says so rather than implying more.** `FORCING_MIGRATIONS`
is a manual one-entry list. "Forcing" is a property of the **body** — it revokes
`array_diff($current, $target)` — that no constant declares, and a heuristic that silently
classified a migration as non-forcing would be a green proving nothing, which is the failure class
this file replaces. Registering a second one is a deliberate act: add its filename, and it must
expose `NAMESPACE` and `TARGET`; the test fails loudly rather than skipping if either is missing.

**Non-vacuity guards both arms separately.** The TARGET-covered and marker-covered counts must each
be non-zero. Today ED's nine finance grants are covered by the TARGET and 4c's three by markers on
`2026_08_09_110000`, so both fire — if either ever falls to zero, that exemption path has stopped
being tested and the guard is half wallpaper again. The population is asserted first (>100 migration
files), which is `MigrationsDoNotReadTheSeederMapTest`'s lesson.

**Watched red, raw.** Mutation: `PermissionEnum::FINANCE_INVOICE_GENERATE->value` added to
`executive_director`'s slice in `grantsMap()`, with no convergence migration.

```
{"result":"failed","tests":1,"passed":0,"assertions":11,"failures":[{
 "test":"...it_no_forcing_convergence_migration_strips_a_grant_the_seeder_map_adds_after_it",
 "message":"these grants are written by the seeder map and then REVOKED by a forcing convergence
 migration on the next deploy (rbac:sync, then migrate), and no later rbac:sync restores them. Ship
 an additive convergence migration dated after the forcing one, carrying an @converges marker per
 pair — 2026_08_09_110000_converge_opening_balance_grants.php is the worked example. See ADR 0052
 § \"A FORCING target freezes a namespace, not a row set\".
 --- Expected: []
 +++ Actual:   ['executive_director + finance.invoice.generate (governed by
                2026_08_06_100000_move_head_of_school_finance_to_executive_director.php, in neither
                its TARGET nor any @converges marker dated after it)']"}]}
```

It names the exact pair and tells the reader what to ship. Restored; green at 11 assertions.

**One thing found while writing it, worth carrying.** The first draft used
`expect($constants)->toHaveKey('NAMESPACE', "<message>")` — Pest's second argument there is the
expected **value**, not a message, so it asserted the constant equalled that sentence. It failed with
`Failed asserting that two strings are equal: '<my message>' vs 'finance.'`, which is a red that
reads as gibberish; had the constant happened to match, it would have been a green that meant
nothing. Replaced with `expect(array_key_exists(...))->toBeTrue("<message>")` and noted at the line.

The three comments now **point at the gate** instead of standing in for it — the forcing migration's
box, ADR 0052, and the lint's exemption-1 note. `2026_08_06_100000`'s edit is comment-only for the
third time: executing half re-proved **byte-identical to `c200d08`**.

### R8. Round-2 residuals

- The `@converges` markers on `2026_08_09_110000` are **inert on the base this branch is pushed
  against** (§R3). They are correct, they are per-pair, and they are not a gate here.
- ~~The forcing-target trap is recorded in three places but **nothing enforces it**.~~ **CLOSED in
  round 3** — see §R9. It is now a test, and the three comments point at it.
- The comment-only amendment to `2026_08_06_100000` was proved non-executing per ADR 0052's
  condition 2 (`token_get_all` comment-strip diff, since the amended block is inside the class body):
  **executing half byte-identical to `c200d08`**.
- Round 1's §6 finding (`route-access-map.json` stale) is **retired** — see §R4.

---


**Branch** `feat/finance-ob-approval-gate` · **Base** `origin/staging` @ `6890edb` ·
**Commit** `8e9e79e` · one commit, 17 files.

**This is full-review tier** — money, a migration, a new Permission triple, grants, a
`school_id`-scoped Action, two lints and three fixture oracles. Subagent review attached;
recommend a cold session before merge.

---

## 0. Deviations from the brief — read these first

**D1. `PostOpeningBalanceBatch`'s entry state moved from `validated` to `submitted`, and 4b's
test file moved with it.** The brief said "do not re-implement any of 4b's guards" in
`ApproveOpeningBalanceBatch`, and I have not — but the two Actions could not both be right with
Post still requiring `validated`: Approve hands Post a batch that is `submitted`. Leaving Post
accepting `validated` as well would have left the pre-gate door open — anything holding a
`validated` batch could post it without a second signature, which is the one thing 4c exists to
prevent. So `PostOpeningBalanceBatch` now refuses anything but `submitted`
([PostOpeningBalanceBatch.php:159-166](../../../app/Finance/Actions/PostOpeningBalanceBatch.php#L159)),
and `OpeningBalancePostingTest`'s `obpBatch()` helper stages `submitted` instead of `validated`.
Three assertions in that file moved from `Validated` to `Submitted`, and its
"refuses to post a batch that is not validated" case now plants a `validated` batch **as the
refused state** — a strictly stronger claim than it made before. No assertion was weakened.

**D2. The brief's step 4 said "update the command's docblock". I also changed the refusal
STRING and the test that asserts it.** The old message read *"the approval gate is §9 step 4c —
not built"*, and the test demanded the words `the approval gate is §9 step 4c`
([OpeningBalanceImportTest.php:829](../../../tests/Feature/Finance/OpeningBalanceImportTest.php#L829)).
After 4c both would have been assertions that the feature is still unbuilt. The refusal itself
is untouched — same position (before any option is read), same `self::FAILURE`, no `--post`
flag. What changed is that it now points at the approval rather than at a milestone.

**D3. `tests/Unit/Finance/ApprovalRequirementTest.php`'s maker list is now derived, not typed.**
It hard-listed four maker abilities; 4c makes five. Rather than adding a fifth name to a list
that will go stale again, it derives from the enum with the same predicate the lint uses, and
asserts the count equals the number of `Submit*.php` files so it cannot pass vacuously.

**D4. I did NOT regenerate `tests/fixtures/route-access-map.json`.** See §6 — regenerating it
folds 153 lines of pre-existing, unrelated drift into this commit.

**D5. I edited one line of setup in `FinanceChangeGrantConvergenceTest` ARM 4 — and the reason is
a real consequence of the grant, not a nuisance.** The first `bin/quality` run failed the ratchet
with exactly one new failure, and it is worth reading before the fix:

```
Segregation of duties: [<redacted>] would hold BOTH the checker
[finance.opening-balance.approve] (via role head_of_school) and the maker
[finance.opening-balance.submit] (via role accounts_supervisor) in school #1.
```

ARM 4 reconstructs the pre-convergence production timeline: it strips the fee-schedule maker from
`accounts_supervisor` (`ccPlantDrift()`), which makes assigning `accounts_supervisor` +
`head_of_school` to one user **legal at assignment time**, and then proves the convergence
migration's user-scoped pre-flight catches the both-sides state it retroactively creates. Putting
the opening-balance maker on `accounts_supervisor` means that dual-hat assignment is now refused
**before the migration is reached**, so the test would abort on the wrong pair and stop exercising
the thing it exists to exercise.

Fixed by revoking `finance.opening-balance.submit` from `accounts_supervisor` **inside ARM 4
only** — not in the shared `ccPlantDrift()` helper, since it is a precondition of this one arm
and not part of the drift the other five are about. **No assertion was changed or weakened**; the
throw ARM 4 asserts still comes from the fee-schedule pair (`accounts_supervisor` holds no
discount-policy maker, so no other pair can produce it).

**The underlying fact a reviewer should weigh, since it is a production consequence and not a test
one:** `accounts_supervisor` + `head_of_school` on one person is now an illegal combination
*unconditionally*, where before 4c it was illegal only once the 2026-08-03 convergence had run.
In the real, converged production state it was already illegal via the fee-schedule pair, so this
adds no new prohibition that is reachable today — but it removes the last state in which the two
seats could legally sit on one person, and that is a staffing constraint someone should agree to
rather than discover. It follows directly from the brief's instruction to grant the maker side
where `fee-schedule.change.submit` already sits; granting it to `accounts_officer` alone would
avoid it entirely. **That is a call for the lead, not for me.**

---

## 1. The four pre-edit verifications

All four confirmed. Three were corrections to the original scoping and each holds.

| Check | What the file actually says |
|---|---|
| `bin/ci-grants-convergence-lint.php` exemption 1 | *"THE PERMISSION IS NEW — the same diff adds its `case` to `app/Enums/Permission.php`. It then lands in `$newPermissions` and `rbac:sync` grants it. **No migration needed**."* Confirmed **by running the lint against the commit** — see §5. |
| `DutySeparation::pairs()` | Walks `Permission::cases()`, keeps those `ApprovalAbility::isExcludedFromSuperAdminBypass()` accepts, and derives the maker via `matchingMakerFor()`. Nothing is registered anywhere; the catalog **is** the source. |
| `ApprovalAbility` | The convention is the **terminal segment only** — `terminalSegment()` is `substr` after the last dot, and `CHECKER_SEGMENTS = ['approve','reject']`. Not a prefix, not a substring. `matchingMakerFor()` swaps that last segment for `submit`. |
| `bin/ci-boundary-lint.php` 150-201 | `approval-seam-missing` enumerates `app/Finance/Actions/Submit*.php` from the **filesystem** and requires `ApprovalRequirement::for(` on a **live** (non-comment) line. `approval-seam-count` greps `case FINANCE_[A-Z_]*SUBMIT =` from the enum and requires that count to equal the number of `Submit*.php` files. Both **zero baseline entries**. |

The count rule is what makes 4c indivisible: `FINANCE_OPENING_BALANCE_SUBMIT` matches that regex,
so the case and `SubmitOpeningBalanceBatch.php` had to land in the same commit.

---

## 2. What was built

**Permissions** — `finance.opening-balance.submit` / `.approve` / `.reject`, added to
`app/Enums/Permission.php` and to `PermissionGroup::FINANCE` (`group()` has no default;
`PermissionGroupTest` asserts the groups partition the enum exactly, so an unfiled case fails).

**Grants** — read off `RbacSeeder::grantsMap()`, not chosen. §3 below.

**State** — `Submitted` only. No `approved`: `ApproveOpeningBalanceBatch` posts inside the same
transaction, so `approved` would be a value no row ever holds between two commits.

**Migration** `2026_08_09_100000_opening_balance_approval_gate.php` — `submitted_by_user_id`,
`submitted_at`, `decided_by_user_id`, `decided_at`, `rejection_reason`, plus the
`..._maker_ne_checker` CHECK the other four approval tables carry. No CHECK on `status` had to be
widened: that column is a plain `string` with no constraint behind it
(`2026_08_06_100000:89`), so the legal set is the enum and nothing else.

Two notes a reviewer should push on:

- The two user columns are `*_user_id` **lookups with no FK**, unlike the other four request
  tables' `submitted_by` / `decided_by` FKs. That follows **this table's own** convention
  (`uploaded_by_user_id`, `posted_by_user_id`, both lookups, for the reason 2026_08_08_110000
  records: attribution must survive a deleted user). Two columns on one table under one
  convention and two under another looked worse than the divergence.
- `rejected` is now reached two ways — the validator's structural rejection and a checker's
  governance rejection. They are distinguished by `rejection_reason` + `decided_by_user_id`,
  non-null only on the governance path. This reuse is what the brief specified
  ("batch → rejected"); I have recorded the ambiguity in the enum, the migration and the Action
  rather than resolving it by coining a sixth state.

**Three Actions** — verbatim in §8.

**The command's refusal stays.** Same position, same exit code, no flag. Docblock and message now
say it is permanent.

---

## 3. Which roles, and where I read that from

Read from `database/seeders/RbacSeeder.php` by locating every existing
`FINANCE_FEE_SCHEDULE_CHANGE_*` grant and following it:

| Ability | Roles | Source line I read |
|---|---|---|
| `finance.opening-balance.submit` | `accounts_officer`, `accounts_supervisor` | `RbacSeeder.php:352` (AO) and `:367` (AS) both hold `FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT` |
| `finance.opening-balance.approve` / `.reject` | `head_of_school` | `RbacSeeder.php:231-232` holds `FINANCE_FEE_SCHEDULE_CHANGE_APPROVE` / `_REJECT` |

`finance_lead` does **not** get it, because `finance_lead` does not hold
`fee-schedule.change.submit` either — that role holds only the credit-note and discount-policy
maker sides.

This is pinned by a test written as a **comparison, not a name list**
(`OpeningBalanceApprovalGateTest.php`, last case): the holders of each opening-balance ability
must equal the holders of the corresponding fee-schedule-change ability, with a non-vacuity
assertion so an ability nobody holds cannot make all three comparisons trivially true. A future
seat realignment that moves one triple therefore moves this one or fails loudly.

**No convergence migration exists in this diff, and none is needed — exemption 1.** The lint
says so itself, by name, per pair. Raw output in §5. A reviewer should not go looking for one.

**One thing that disagrees with the grant, recorded rather than resolved.**
`docs/finance/authority-matrix-decisions-2026-08-03.md:83` row 17 — *"Change an opening
balance"* — is `D | D | D | V | V`: **no approver at all**, and HoS is a viewer. That row is
about *changing* an opening balance as an ongoing transaction, not about the cutover import,
and spec §8 is explicit that the import is maker-checker with the batch as the unit of approval.
I followed §8 and the brief. But the two documents use the same words for different acts, and
whoever owns the matrix should say so out loud before row 17 is ever built.

---

## 4. §8 / U16 — the queue does NOT pick the new type up

§8 claims 4c "makes **six** on the approvals queue (U16)". **It does not, and it could not.**
How I checked, in three steps:

1. **The page ROUTE does pick it up.** `routes/web.php:167-172` derives the middleware string
   from the `ApprovalAbility` convention over the catalog, so the new checker abilities joined
   it with no route edit. Evidence: `tests/fixtures/route-middleware-baseline.json` gained
   `|finance.opening-balance.approve|finance.opening-balance.reject` on the
   `GET /finance/approvals` entry — a one-line diff, regenerated by `rbac:derive-map`, not
   hand-edited. So a holder of the new checker ability can open the queue.

2. **The page CONTENT does not.** `resources/js/pages/admin/finance/approvals.tsx:70-77` fetches
   exactly **two** feeds — `CreditNoteController@pending` and `VoidRequestController@pending` —
   merges them, and discriminates on `type: 'credit_note' | 'void'`
   (`resources/js/types/finance.ts:100`). There is no third fetch and no third discriminator.

3. **There is no opening-balance feed to fetch.** `routes/endpoints/finance.php` has four
   `…/pending` routes (credit-notes, void-requests, fee-schedule-changes,
   discount-policy-changes). 4c adds no controller, no Resource and no route, because the brief
   scopes 4c to the domain and puts the operator screen at step 5.

So the honest count today is: **four pending feeds at the API, two of them rendered in the
queue, and five request types in the domain.** `docs/handoff/finance-mvp-cut-brief.md:140`
records U16 as *"approvals.tsx exists, covers four"* — that is wrong at the UI layer; it covers
two. U16 remains open and is now two types further from done, not one.

---

## 5. Gates — raw

```
$ php bin/ci-boundary-lint.php
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).

$ php bin/ci-authz-lint.php
authz-lint: OK — no new commented-out authorization checks (0 known).

$ php bin/ci-grants-convergence-lint.php origin/staging
grants-convergence-lint: OK — no unexempted grant addition in database/seeders/RbacSeeder.php (6890edb..8e9e79e; 4 exempted).
  · finance.opening-balance.approve @ database/seeders/RbacSeeder.php:240 — exempt: permission is NEW in this diff (lands in $newPermissions)
  · finance.opening-balance.reject @ database/seeders/RbacSeeder.php:241 — exempt: permission is NEW in this diff (lands in $newPermissions)
  · finance.opening-balance.submit @ database/seeders/RbacSeeder.php:365 — exempt: permission is NEW in this diff (lands in $newPermissions)
  · finance.opening-balance.submit @ database/seeders/RbacSeeder.php:383 — exempt: permission is NEW in this diff (lands in $newPermissions)

$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}

$ ./vendor/bin/pest --group=arch
{"tool":"pest","result":"passed","tests":23,"passed":23,"assertions":140}

$ ./vendor/bin/pint --test --dirty
{"tool":"pint","result":"passed"}
```

`bin/quality` raw output is in §9.

---

## 6. The fixture oracles

Regenerated in the required order — `rbac:sync` (via `migrate:fresh --seed` on
`portal_testing`) → `rbac:derive-access` → the baselines.

- **`rbac-grants-baseline.json`** — regenerated by re-running the exact computation
  `PermissionEnumTest` performs against a freshly migrated + seeded `portal_testing`. Diff is
  **+5 −1**: `finance.opening-balance.submit` into `accounts_officer` and
  `accounts_supervisor`, `.approve` + `.reject` into `head_of_school`. Nothing else moved.
- **`route-middleware-baseline.json`** — regenerated by `rbac:derive-map`. Diff is **one line**,
  the derived approvals-route middleware string (§4).
- **`route-access-map.json` — REGENERATED, THEN REVERTED, DELIBERATELY.** `rbac:derive-access`
  produced **+153 lines and 0 removals**, all of them route keys absent from the committed
  fixture: `GET /api/notifications`, `/api/notifications/unread-count`,
  `/api/notifications-queue-health`, `/api/parent/wards`, `/notifications/queue-health`,
  `PATCH /api/notifications/{uuid}/read`, `POST /api/notifications/read-all`, `/seen`,
  `/ses-events`, `/{notification}/actions/{action}` — ten routes, none of them mine. The
  `GET /finance/approvals` entry did not change at all. Folding a 153-line correction of
  someone else's drift into this commit would hide it, so I reverted the file and
  `RouteAccessParityTest` still passes against the committed version.

  **That is itself the finding: the map is stale by ten routes and the test that reads it does
  not notice.** `RouteAccessParityTest` passes both before and after, so whatever it asserts, it
  is not route-set completeness. A fixture that can fall ten routes behind without any gate
  going red is wallpaper for exactly the thing it was written to oracle. I have not fixed it —
  it belongs in its own commit, with someone deciding whether the parity test should be
  tightened.

---

## 7. The watched reds — mutation, raw failure, restore

Every one was watched red **before** it was watched green. All five restored; the working tree
after restoration is byte-identical to the committed state.

### Red 1 — the maker approving their own batch

Mutation: delete the `submitted_by_user_id === $checker->id` refusal from
`ApproveOpeningBalanceBatch::handle`.

```
{"result":"failed","tests":3,"passed":2,"failed":1,"failures":[{
 "test":"...it_PROOF_1_—_the_MAKER_who_submitted_a_batch_cannot_approve_it__refused__and_NOTHING_posts",
 "message":"Failed asserting that 'SQLSTATE[HY000]: General error: 3819 Check constraint
 'finance_opening_balance_batches_maker_ne_checker' is violated. (Connection: mysql, ...,
 SQL: update `finance_opening_balance_batches` set `decided_by_user_id` = 2, ... where `id` = 1)'
 contains \"maker ≠ checker\"."}]}
```

Worth reading closely: with the PHP guard removed, **the CHECK constraint caught it** — the test
went red on the *wrong exception type*, not on a successful self-approval. The two layers are
genuinely independent, which is what `PROOF 1b` asserts directly (3819 by driver code).

### Red 2 — super_admin's checker exclusion

Mutation: the historical denylist-drift bug — an early
`if (str_starts_with($ability, 'finance.opening-balance.')) return false;` in
`ApprovalAbility::isExcludedFromSuperAdminBypass()`.

```
{"result":"failed","tests":2,"passed":0,"failed":2,"failures":[
 {"test":"...PROOF_1c_—_the_pair_is_what_the_CONVENTION_derives__not_a_list_anyone_maintains",
  "message":"Failed asserting that null is identical to Array &0 [
    'checker' => 'finance.opening-balance.approve', 'maker' => 'finance.opening-balance.submit']."},
 {"test":"...PROOF_2_—_super__admin_CANNOT_approve_or_reject_a_cutover__and_CAN_still_hold_the_maker_side",
  "message":"Failed asserting that true is false."}]}
```

Both arms fire: the pair stops being derived **and** the bypass reaches the checker ability.

### Red 3 — approval must post

Mutation: `ApproveOpeningBalanceBatch` records the decision and returns without calling the poster.

```
{"result":"failed","tests":2,"passed":0,"failed":2,"failures":[
 {"test":"...PROOF_3_—_APPROVE_posts_the_batch...",
  "message":"Failed asserting that two variables reference the same object.\n
  -App\\Finance\\Enums\\OpeningBalanceBatchStatus Enum #7316 (Posted, 'posted')\n
  +App\\Finance\\Enums\\OpeningBalanceBatchStatus Enum #7249 (Submitted, 'submitted')"},
 {"test":"...PROOF_3b_—_approval_is_ONE_transaction...",
  "message":"Failed asserting that 0 is identical to 1062."}]}
```

### Red 4 — rejection must move no money

Mutation: the copy-paste-from-`Approve` hazard — `RejectOpeningBalanceBatch` calls
`PostOpeningBalanceBatch` before writing the rejection.

```
{"result":"failed","tests":3,"passed":2,"errors":1,"error_details":[{
 "test":"...PROOF_4_—_REJECT_leaves_the_batch_rejected_with_ZERO_ledger_rows_and_ZERO_payments",
 "message":"SQLSTATE[45000]: <<Unknown error>>: 1644 A posted opening-balance batch is terminal
 (G1b): neither its status nor its School can move. (..., SQL: update
 `finance_opening_balance_batches` set `status` = rejected, `decided_by_user_id` = 3, ...)"}]}
```

Again the database got there first: G1b refused the `posted → rejected` move with 1644 before the
test could reach its zero-rows assertion. The mutation is caught; it is caught one layer below
where I aimed it.

### Red 5 — the two lints, and what each would have caught

**5a — `approval-seam-count`.** Mutation: the triple lands **without** the Submit action
(`SubmitOpeningBalanceBatch.php` moved out of the tree — exactly the split the brief said is
impossible).

```
boundary-lint: 1 NEW boundary violation(s):
  ✗ approval-seam-count  app/Enums/Permission.php  finance *_SUBMIT abilities (5) != Submit* actions (4) — ADR 0051 seam-coverage drift
```

**That is the answer to "what would it have caught":** had I added the Permission triple and the
grants and shipped the Actions in a later commit, this lint would have failed the push on the
count 5 ≠ 4. It is why 4c is one commit.

**5b — `approval-seam-missing`.** Mutation: comment out the `ApprovalRequirement::for(…)` call in
`SubmitOpeningBalanceBatch` (the authz-rule-15 shape — leaving the `use` in place).

```
boundary-lint: 1 NEW boundary violation(s):
  ✗ approval-seam-missing  app/Finance/Actions/SubmitOpeningBalanceBatch.php  does not call ApprovalRequirement::for() — the maker-checker seam (ADR 0051)
```

It would have caught a Submit action that hard-wires "always needs a checker" at its own call
site instead of routing the decision through the one configurable seam — which is what makes the
comment-out, not just the deletion, a violation.

Restored, both green:

```
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).
```

---

## 8. The three Actions, verbatim

See `git show 8e9e79e -- app/Finance/Actions/SubmitOpeningBalanceBatch.php
app/Finance/Actions/ApproveOpeningBalanceBatch.php
app/Finance/Actions/RejectOpeningBalanceBatch.php` — reproduced in the chat transcript
accompanying this report.

Shape, for a reader who wants the summary before the source:

- **`SubmitOpeningBalanceBatch`** — school-context refusal, `validated`-only refusal,
  `ApprovalRequirement::for(FINANCE_OPENING_BALANCE_SUBMIT)` on a live line, then a transaction
  that re-reads under lock, re-checks `validated`, and writes `submitted` +
  `submitted_by_user_id` + `submitted_at`. `notifyApprovalCheckers` fires **after** the commit,
  outside the closure.
- **`ApproveOpeningBalanceBatch`** — one transaction: lock, refuse unless `submitted`, refuse if
  submitter == checker, write `decided_by_user_id` / `decided_at`, then
  `PostOpeningBalanceBatch::handle` inside the same transaction. It re-implements none of 4b's
  guards; the decision is written *before* the post so a failed post rolls it back with it.
- **`RejectOpeningBalanceBatch`** — reason required (trimmed, non-empty), lock, refuse unless
  `submitted`, refuse if submitter == checker, write `rejected` + `decided_by_user_id` +
  `decided_at` + `rejection_reason`. Nothing posts.

---

## 9. `bin/quality` — raw

Fourteen steps, re-derived from this run (the substrate note saying "13" is stale).

**First run — FAILED, and the failure was real.** See D5 above for the finding and the fix.

```
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✗ test-ratchet
       ratchet: 1 NEW test failure(s) not in the baseline (regression):
         ✗ tests/Feature/Rbac/FinanceChangeGrantConvergenceTest.php::it ARM 4 — user-scoped pre-flight bites: a user holding accounts_supervisor + head_of_school aborts the convergence, then converges once resolved
       Fix the regression, or — if the failure is intentional — add it to tests/ratchet-baseline.txt.

✗ quality: FAIL (1): test-ratchet
```

`tests/ratchet-baseline.txt` was **not** touched — the brief forbids it and the failure was a real
consequence to fix, not one to grandfather.

**Second run, on `911adc2` — PASS.**

```
quality gate — base 6890edb

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

Note the tsc ratchet is a known false-green (it can rise and does not hard-block); step 4 passing
is not evidence of a type floor here. No TypeScript was written in this change.

---

## 10. What I did NOT do, and what I could not verify

- **No controller, route, Resource or UI.** 4c is domain-only; the operator screen is step 5.
  Consequence stated in §4: the new type cannot appear on the approvals queue yet.
- **No `open_key`-style "one open submission per school".** The other four request types have
  one; this table does not. Reasoning is in `SubmitOpeningBalanceBatch`'s docblock — G1 already
  permits at most one *posted* batch per school ever and G1b makes it irreversible, so a second
  submitted batch cannot become a second post; its approval fails at 1062, which `PROOF 3b`
  exercises. **This is the weakest argument in the change** and the thing I would attack first:
  it means two makers can each have a submission pending on one school and a checker sees no
  signal that approving one kills the other.
- **`route-access-map.json` staleness** (§6) — found, reported, not fixed.
- **The authority-matrix row 17 conflict** (§3) — found, reported, not resolved. Not mine to
  resolve.
- **Not driven in the running app.** There is no UI to drive: no route reaches these Actions.
  The dev database (`brookstone_portal_db`) was **not** migrated or seeded — everything here was
  derived on `portal_testing`. `rbac:sync` has not been run against any production-derived copy,
  and must be (the catalog diff will be `missing_rows` only, three of them, which is the safe
  case) before these grants exist anywhere but a fresh install.
- **No severity calls on my own work**, and nothing here is nominated as contentious on my own
  authority. §4, §6 and the `open_key` note in this section are the three places I would send a
  reviewer first.
