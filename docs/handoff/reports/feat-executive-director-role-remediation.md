# Implementation report — `feat/executive-director-role`, pre-merge remediation

Base: `origin/staging` @ `6890edb`. Branch: `feat/executive-director-role`.
Merge commit `81bb0ac`, then `24a8909` (ADR), `6756596` (oracle), `db68a6f` (test fix).
Not pushed. Not merged.

---

## Headline

**Done with one item withdrawn by the project lead mid-task, and one defect the merge surfaced.**
Staging is merged in (45 commits, one conflict, in `docs/adr/0052`); item 1 is ruled **(a) keep the
edit**, with the replay evidence that decides it and an argued carve-out written into ADR 0052's
corollary; item 3 is proved and the ordering is **not** relied on; item 4 re-derived all three
oracles from clean throwaway databases and caught one entry the auto-merge did not carry. Item 2 was
**withdrawn** — see below. The first `bin/quality` run failed the ratchet on two arms of a test that
arrived with the merge (`DutySeparationBaselineTest` ARM 3 and ARM 4) — real, reproducible, and
fixed in `db68a6f`; bite-proving that fix found the arm was additionally passing for the wrong
reason.

**This is full-review tier** — it touches an applied migration, RBAC grants, and two fixture
oracles. Subagent review attached; recommend a cold session before merge.

---

## Round 2 — the review's two `fix` findings, closed

Everything above this line is round 1. The `finance-reviewer` subagent returned two `fix` findings
and three `ticket`s; the project lead ruled on them and this section is the work. **Both `fix`
findings reproduced.** Neither was taken on the reviewer's word.

### R2 deviation, and it was the lead's to resolve

The brief's item 2 said "delete the claim wherever the docblock says it" while its footer said "do
not edit `2026_08_06` further — byte-identical to `17da5c3`". The loudest instance of the claim is
inside that file. **I stopped and asked rather than picking.** The lead's ruling: the footer was
over-broad and meant the **executing half** — which is what ADR 0052's corollary actually governs,
what its carve-out argues about, and what item 1's replay was proving. A comment has never been in
scope, and `2026_08_08_100000`'s retraction box is the standing precedent. Second and stronger:
`2026_08_06` has **never applied to an environment that persists** — unmerged branch, every run a
throwaway replay database — so the divergence the corollary exists for cannot have occurred.

So the claim was corrected **in place**, in `2026_08_08_100000`'s retraction form, and the wider
claim is narrowed everywhere it appears: **"executing half byte-identical to `17da5c3`"**, never
"byte-identical". The wider claim was never the one that mattered and is now false. `2026_08_03` is
untouched in both halves — its carve-out is settled and was not reopened.

### R2 finding 1 — the deploy fails by design and no runbook said so (reproduced)

Not taken on the reviewer's word. Reproduced at the **release boundary**: the seven release
migrations moved out of `database/migrations/`, a throwaway database migrated to the pre-release
state, `rbac:sync` run and then the `executive_director` row deleted — the exact shape of a
production database whose seeder has not been run since ED entered `RbacSeeder::ROLES` — the files
moved back, and then the deploy step exactly as both runbooks write it.

```text
=== 2. seed the RBAC substrate, then remove executive_director ===
executive_director rows: 0
finance.* permission rows: 17
=== 3. the deploy step, exactly as both runbooks write it ===
   php artisan migrate --force  exit=1
=== 4. what actually landed ===
APPLIED 2026_08_06_100000_create_finance_opening_balance_tables
PENDING 2026_08_06_100000_move_head_of_school_finance_to_executive_director
PENDING 2026_08_07_100000_add_file_row_count_to_opening_balance_batches
PENDING 2026_08_07_110000_add_provenance_to_finance_payments
PENDING 2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file
PENDING 2026_08_08_110000_opening_balance_posting_state_and_guards
PENDING 2026_08_08_120000_opening_balance_posted_rows_are_terminal
=== 5. and the documented recovery: rbac:sync, then migrate again ===
   php artisan migrate --force  exit=0
pending after recovery: 0
head_of_school finance.* grants: 0
--- migration files restored: 0 dirty paths (0 expected)
```

The abort itself, verbatim:

```text
2026_08_06_100000_create_finance_opening_balance_tables .. 305.15ms DONE
2026_08_06_100000_move_head_of_school_finance_to_executive_director  6.71ms FAIL

  move-hos-finance-to-ed ABORTED: the [executive_director] role row does not exist yet. It is new
  in RbacSeeder::ROLES, so run `php artisan rbac:sync` first and then re-migrate. This migration
  deliberately does NOT create the row: two_factor_required is applied only at role creation
  (RbacSeeder.php:461-477) and executive_director is in TWO_FACTOR_REQUIRED, so a row created here
  would carry the flag FALSE permanently.
```

**The abort is right and was not weakened, not made to skip, and not touched at all.** Its executing
half is byte-identical to `17da5c3` (proof below). One correction to the record that the reviewer did
not raise and the runbooks now carry: **this is not a half-applied schema**, which is what both
runbooks tell an operator to STOP and re-clone on. The throw is a pre-flight — it fires before any
write and before the transaction opens — so nothing of that migration is partially applied, and the
recovery is one command plus a re-run. A runbook that classed it with the DDL hazard would have sent
someone to restore a backup over a state that needed `rbac:sync`.

**The runbooks were wrong. Both now carry `rbac:sync` before `migrate --force`**, with why, what a
skip looks like, and the recovery — see the two diffs below.

#### The two runbook diffs, verbatim

Prettier was NOT run on either file. `bin/lint-changed.sh:46` scopes Prettier to
`resources/*.{ts,tsx,js,jsx,vue,css,json}`, so markdown under `docs/` is outside every gate —
running it would have reflowed emphasis markers and table padding across both files and buried
the change in cosmetic churn. (It was run once and reverted: it turned a 56-line insertion into
an 84-add / 28-remove diff.)

```diff
diff --git a/docs/runbooks/clone-dress-rehearsal.md b/docs/runbooks/clone-dress-rehearsal.md
index d6e78e4..2928c25 100644
--- a/docs/runbooks/clone-dress-rehearsal.md
+++ b/docs/runbooks/clone-dress-rehearsal.md
@@ -139,7 +139,55 @@ ## Step 2 — run the migrations
 php artisan migrate:status          # what's pending against the cloned (old) schema
 ```
 
-Then, **only after Step 1 is all-pass**:
+### 2a — `rbac:sync` runs BEFORE `migrate`, and the ordering is PROCEDURAL
+
+```bash
+php artisan rbac:sync               # BEFORE migrate — see why, below
+```
+
+**Why.** `2026_08_06_100000_move_head_of_school_finance_to_executive_director` **governs a
+role the seeder creates**. `executive_director` is new in `RbacSeeder::ROLES`, and the
+migration deliberately refuses to create the row itself — `two_factor_required` is applied
+only at role creation (`RbacSeeder.php:507-517`) and ED is in `TWO_FACTOR_REQUIRED`, so a row
+created by a migration would carry the flag **false permanently**, silently stripping
+two-factor from the one seat that can approve money leaving four different ways. Aborting
+costs one command; creating costs an invisible security downgrade.
+
+**A migration cannot run a seeder, so nothing enforces this ordering.** It is the same
+enforced-versus-procedural split §11 uses: an enforced control fails a build, a procedural one
+needs a person who knows. This is procedural, and this line is the only thing carrying it.
+
+**Before you run it**, confirm `rbac:sync` is safe on this database: `php artisan
+rbac:diff-grants` → Section A must show `missing_rows` only. **Any `extra_rows` and you STOP** —
+`rbac:sync` hard-deletes permission rows the enum no longer declares and both pivots cascade, so
+it would take runtime matrix grants with it, without an audit trace. Full procedure:
+[`rbac-grants-reconciliation.md`](rbac-grants-reconciliation.md) §2a / §2b.
+
+**If you skip this and go straight to `migrate`, here is exactly what you get** — reproduced on
+a throwaway database at the release boundary:
+
+```text
+2026_08_06_100000_create_finance_opening_balance_tables .. 305.15ms DONE
+2026_08_06_100000_move_head_of_school_finance_to_executive_director  6.71ms FAIL
+
+  move-hos-finance-to-ed ABORTED: the [executive_director] role row does not exist yet. It is
+  new in RbacSeeder::ROLES, so run `php artisan rbac:sync` first and then re-migrate. …
+```
+
+`migrate` exits **1**, `create_finance_opening_balance_tables` has **landed**, and
+`2026_08_07_*` and both `2026_08_08_*` migrations **never ran**. That looks like the
+half-applied schema this step tells you to STOP on — but it is not one. The abort is a
+**pre-flight**: it fires before any write and before the transaction opens, so nothing of that
+migration is half-applied.
+
+**Recovery is one command, then re-run:** `php artisan rbac:sync && php artisan migrate --force`.
+Measured on the same database: exit **0**, zero pending, and `head_of_school` ends with zero
+`finance.*` grants. **This is the one `migrate` failure on this release that you re-run rather
+than re-clone** — check the error names `move-hos-finance-to-ed` before treating it as such.
+
+### 2b — the migrations
+
+Then, **only after Step 1 is all-pass and 2a has run**:
 
 ```bash
 php artisan migrate --force
@@ -150,7 +198,9 @@ ## Step 2 — run the migrations
   half-applied schema. Do **not** loop `migrate`. On the clone this is cheap (drop and
   re-clone), but capture the exact error — it's the one you'd have hit in prod. The
   slice-(i) migration (`2026_07_19_130000_add_school_id_to_student_curricula`) is the
-  most likely abort point, and Step 1a is what prevents it.
+  most likely abort point, and Step 1a is what prevents it. **Exception:** the
+  `move-hos-finance-to-ed` abort in 2a, which is a pre-flight and is recovered by
+  running `rbac:sync` and re-running `migrate`.
 
 ### Reversibility — separate, via the throwaway-DB gate
 
@@ -298,7 +348,9 @@ ## Pass / fail summary
 | 1b | `students.school_id` null | `0` | STOP — assign School per row |
 | 1c | DB default collation | `utf8mb4_unicode_ci` | ALTER + recreate triggers (on prod too) |
 | 1d | S7 divergence A1–A3 | (record only) | note for future backfill; not a blocker |
-| 2 | `migrate --force` | exit 0 | STOP — half-applied schema, re-clone |
+| 2a | `rbac:diff-grants` Section A | `missing_rows` only | STOP on any `extra_rows` — see `rbac-grants-reconciliation.md` §2b |
+| 2a | `rbac:sync` **before** migrate | exit 0 | PROCEDURAL — nothing enforces the ordering; skipping it aborts `move-hos-finance-to-ed` |
+| 2 | `migrate --force` | exit 0 | STOP — half-applied schema, re-clone. **Except** a `move-hos-finance-to-ed` abort: run `rbac:sync`, re-run `migrate` |
 | 2 | `bin/quality-clean-db` | four paths green | fix the `down()` bug it names |
 | 3 | `audit:verify-immutability` | exit 0 | triggers missing — re-apply |
 | 3 | `rbac:sync` | clean, super_admin healed | fix null-team seed context |
```

```diff
diff --git a/docs/runbooks/phase1-deploy.md b/docs/runbooks/phase1-deploy.md
index 69a8d2e..1466bf5 100644
--- a/docs/runbooks/phase1-deploy.md
+++ b/docs/runbooks/phase1-deploy.md
@@ -83,12 +83,55 @@ ## Step 3 — migrate
 
 Only after steps 1 and 2 are zero.
 
-|                    |                                                                        |
-| ------------------ | ---------------------------------------------------------------------- |
-| **Check**          | `php artisan migrate --force`                                          |
-| **Pass criterion** | exit 0                                                                 |
-| **Failure action** | STOP — see "if migrate fails mid-run" below. Do **not** re-run blindly |
-| **Gate**           | Human-executed; repo-verified that the chain applies cleanly from zero |
+### 3a — `rbac:sync` FIRST — the ordering is procedural and nothing enforces it
+
+|                    |                                                                                                                     |
+| ------------------ | ------------------------------------------------------------------------------------------------------------------- |
+| **Check**          | `php artisan rbac:diff-grants`, then `php artisan rbac:sync` — **before** `migrate`                                 |
+| **Pass criterion** | diff-grants Section A shows `missing_rows` only; `rbac:sync` exits 0                                                |
+| **Failure action** | Any `extra_rows` in Section A → **STOP, do not run `rbac:sync`** — see `rbac-grants-reconciliation.md` §2b          |
+| **Gate**           | **Human-executed and PROCEDURAL.** A migration cannot run a seeder, so no gate, lint or test can enforce this order |
+
+**Why this exists as a step.** `2026_08_06_100000_move_head_of_school_finance_to_executive_director`
+**governs a role the seeder creates.** `executive_director` is new in `RbacSeeder::ROLES`,
+and the migration deliberately refuses to create the row: `two_factor_required` is applied
+only at role creation (`RbacSeeder.php:507-517`) and ED is in `TWO_FACTOR_REQUIRED`, so a row
+created by a migration would carry the flag **false permanently** — silently stripping
+two-factor from the one seat that can approve money leaving four different ways. Aborting
+costs one command; creating costs an invisible security downgrade.
+
+This is §11's enforced-versus-procedural split in its clearest form: an enforced control fails
+a build, a procedural one needs a person who knows. **This line is the only thing carrying
+it**, and `bin/quality-clean-db` cannot cover it — that script migrates from **zero**, where
+the migration's fresh-install guard returns before the pre-flight is ever reached.
+
+**What a skipped 3a looks like** — reproduced on a throwaway database at the release boundary:
+
+```text
+2026_08_06_100000_create_finance_opening_balance_tables .. 305.15ms DONE
+2026_08_06_100000_move_head_of_school_finance_to_executive_director  6.71ms FAIL
+
+  move-hos-finance-to-ed ABORTED: the [executive_director] role row does not exist yet. It is
+  new in RbacSeeder::ROLES, so run `php artisan rbac:sync` first and then re-migrate. …
+```
+
+`migrate` exits **1**; `create_finance_opening_balance_tables` has landed; `2026_08_07_*` and
+both `2026_08_08_*` migrations never ran.
+
+**This is NOT the half-applied schema below, and must not be treated as one.** The abort is a
+pre-flight: it throws before any write and before the transaction opens, so nothing of that
+migration is partially applied. **Recovery is `php artisan rbac:sync && php artisan migrate
+--force`** — measured on the same database: exit **0**, zero pending, `head_of_school` ends
+with zero `finance.*` grants.
+
+### 3b — migrate
+
+|                    |                                                                                                                                                            |
+| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
+| **Check**          | `php artisan migrate --force`                                                                                                                              |
+| **Pass criterion** | exit 0                                                                                                                                                     |
+| **Failure action** | STOP — see "if migrate fails mid-run" below. Do **not** re-run blindly. **Unless** the error names `move-hos-finance-to-ed`: that one is 3a, and you re-run |
+| **Gate**           | Human-executed; repo-verified that the chain applies cleanly from zero                                                                                     |
 
 **If migrate fails mid-run:** MySQL DDL is **not transactional**. A migration that
 fails partway leaves a **half-applied schema**, and the same is true in reverse for a
@@ -97,6 +140,9 @@ ## Step 3 — migrate
 loop `migrate`. Capture the error, and treat recovery as restore-from-backup unless the
 partial state is understood.
 
+**The one exception, named so nobody restores a backup over it:** a `move-hos-finance-to-ed
+ABORTED` error is a pre-flight refusal, not a partial write. Run 3a and re-run `migrate`.
+
 ---
 
 ## Step 4 — `audit:verify-immutability`, **wired into the pipeline after migrate**
```

#### Every other path that runs `migrate`, checked

Derived with a repo-wide grep for `artisan migrate`, `migrate --force`, `migrate:fresh|refresh|rollback`
across `*.php`, `*.md`, `*.sh`, `*.yml`, `Makefile`, `composer.json`, excluding `vendor/`,
`node_modules/` and `build/`. **There is no deploy script, no Makefile, no Envoy/Docker/Procfile/Vapor
artifact in the repo at all** — `ls -a | grep -iE 'envoy|deploy|docker|procfile|fly|vapor|forge'`
returns nothing, and `bin/` contains only the lints and the three gate scripts.

| path                                            | runs migrate                      | affected?                                                                                                                                          |
| ----------------------------------------------- | --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `docs/runbooks/clone-dress-rehearsal.md:145`    | `migrate --force` on a prod clone | **YES — fixed**                                                                                                                                    |
| `docs/runbooks/phase1-deploy.md:88`             | `migrate --force` on production   | **YES — fixed**                                                                                                                                    |
| `.github/workflows/tests.yml:89`                | `migrate --force`                 | No — Actions is billing-locked and has never executed a job (ADR 0053); and it migrates an empty service DB, where the fresh-install guard returns |
| `composer.json:59` (`composer setup`)           | `migrate --force`                 | No — new-developer bootstrap against an empty DB                                                                                                   |
| `composer.json:107` (`post-create-project-cmd`) | `migrate --graceful`              | No — never runs for a clone of an existing project                                                                                                 |
| `bin/quality-clean-db:87`                       | `migrate --force`                 | No, **and this is the blindness** — from zero, so the guard returns before the pre-flight                                                          |
| `app/Console/Commands/SeedDriveFixture.php:68`  | `migrate:fresh`                   | No — refuses outside local (`:51`), from zero                                                                                                      |
| `README.md:26`, `docs/testing.md:38`            | `migrate --force`                 | No — `portal_testing`, from zero                                                                                                                   |
| Pest `RefreshDatabase`                          | `migrate:fresh`                   | No — from zero, per run                                                                                                                            |

So: two affected paths, both documentation, both fixed. **Nothing executable in this repository runs
`migrate` against a database that would hit the abort** — which is exactly why the control has to be
procedural and why the runbook line is the only thing carrying it.

### R2 finding 2 — the post-write abort cannot fire (confirmed, and wider than stated)

Confirmed twice: by reading, and by running.

**By reading.** `RbacSeeder::syncLogged` snapshots `$existingRoles` at `:492`, **before** the
role-creation loop at `:507`. `executive_director` is therefore absent from that snapshot and takes
the whole-slice `: $permissions` branch at `:542-544`, receiving all nine.
`TARGET['executive_director']` is those same nine, so its grant diff is empty; `head_of_school`'s
target is `[]`; `accounts_supervisor`'s is a subset of what it holds. Every branch grants nothing.

**By running**, on a production-shaped throwaway database — `rbac:sync`, then `head_of_school` and
`accounts_supervisor` left holding their pre-seat-move grants (because `rbac:sync` revokes nothing,
which is the entire reason this migration exists), then one user holding `executive_director`
alongside `accounts_officer`, then `2026_08_06`'s `migrations` row cleared and `migrate --force`:

```text
move-hos-finance-to-ed REPORT: 8 both-sides finding(s) this run did NOT create — not blocked on:
  user#2 @ school#1 [finance.credit-note.approve + finance.credit-note.submit]
  user#2 @ school#1 [finance.credit-note.reject + finance.credit-note.submit]
  user#2 @ school#1 [finance.invoice.void-request.approve + finance.invoice.void-request.submit]
  user#2 @ school#1 [finance.invoice.void-request.reject + finance.invoice.void-request.submit]
  user#2 @ school#1 [finance.discount-policy.change.approve + finance.discount-policy.change.submit]
  user#2 @ school#1 [finance.discount-policy.change.reject + finance.discount-policy.change.submit]
  user#2 @ school#1 [finance.fee-schedule.change.approve + finance.fee-schedule.change.submit]
  user#2 @ school#1 [finance.fee-schedule.change.reject + finance.fee-schedule.change.submit]
  These are real and they matter. They belong to `php artisan finance:audit-duty-separation`, not to a migration.
move-hos-finance-to-ed [AFTER] holders per school (out-of-scope both-sides findings=8):
… 232.12ms DONE
```

**REPLAY EXIT=0.** All **eight** findings — all four ED pairs, both directions, exactly the case the
struck comment named as "the reachable direction" — reported and committed. The migration also did
real work on that run (it stripped `head_of_school`'s five and `accounts_supervisor`'s four), so this
is not the idempotent-no-op case.

**The abort was KEPT.** Deleting a throw because today's sequences cannot reach it is how the next
sequence gets no guard at all. What changed is the claim, in four places:

1. `2026_08_06`'s walk comment — struck in place, in a retraction box, in `2026_08_08_100000`'s form.
2. ADR 0052 — a new section, _"And say what the narrowing costs, because for `2026_08_06` it costs
   the whole abort"_, under the `DutySeparation` boundary it belongs to.
3. `MoveHosFinanceToEdConvergenceTest` ARM 7 — retitled _"rolls back, from a state `rbac:sync` cannot
   produce"_, with a box saying the arm proves the branch **executes**, not that it **guards**. ARM 8
   now says it, not ARM 7, is the production sequence.
4. This report.

#### The corrected claim, verbatim

From `database/migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php`:

```text
╔═════════════════════════════════════════════════════════════════════════════════════╗
║ RETRACTED 2026-08-08 — THIS ABORT CANNOT FIRE. READ BEFORE THE STRUCK SENTENCE.     ║
║                                                                                     ║
║ The struck sentence below was written as the reason this walk THROWS, and it is not ║
║ one. It is kept rather than deleted because it is the reasoning the next removal-   ║
║ only convergence migration would copy, and a deleted paragraph teaches nobody why   ║
║ it went.                                                                            ║
║                                                                                     ║
║ ON EVERY SEQUENCE `rbac:sync` PRODUCES, `$grantedThisRun` IS EMPTY. ED is new in    ║
║ RbacSeeder::ROLES, so `syncLogged` snapshots `$existingRoles` BEFORE creating it    ║
║ (RbacSeeder.php:492 then :507) and ED takes the whole-slice `: $permissions`        ║
║ branch (:542-544), receiving all nine. TARGET['executive_director'] is those same   ║
║ nine, so `array_diff($wanted, $current)` is []. head_of_school's target is [] and   ║
║ accounts_supervisor's is a subset of what it holds, so both grant [] too. The       ║
║ transfer's only real work is the REVOKE half — which is exactly why this file       ║
║ exists — and a revoke can never put a side into `$grantedThisRun`.                  ║
║                                                                                     ║
║ MEASURED, on a production-shaped throwaway database: rbac:sync, then HoS and AS     ║
║ left holding their pre-seat-move grants (rbac:sync revokes nothing), then one user  ║
║ holding executive_director + accounts_officer. The walk found EIGHT both-sides      ║
║ findings for that user — all four ED pairs, both directions — and reported every    ║
║ one of them as out of scope. `migrate` exited 0 and committed.                      ║
║                                                                                     ║
║ SO: a test that reaches the throw does so through a state the system cannot         ║
║ produce, and proves the branch EXECUTES rather than that it GUARDS. What actually   ║
║ covers the ED direction is DutySeparation::assertAssignmentAllowed at grant time    ║
║ (app/Models/User.php:412), with `finance:audit-duty-separation` as the detector for ║
║ pairings that predate it. The throw is RETAINED because it costs nothing and        ║
║ guards a path nobody has enumerated — not because it is load-bearing today.         ║
║                                                                                     ║
║ Only this comment is amended. up() and down() are untouched — ADR 0052's corollary  ║
║ governs the EXECUTING half, and its carve-out section records that a comment-only   ║
║ amendment is inside it. (This file has in any case never applied to an environment  ║
║ that persists: it is unmerged, and every run was a throwaway replay database.)      ║
╚═════════════════════════════════════════════════════════════════════════════════════╝

~~The reachable direction is the ED one: four maker-checker pairs now terminate on a
single role, so any user who ends up holding executive_director alongside
accounts_officer, finance_lead or accounts_supervisor is a both-sides holder.~~ — TRUE
AS A STATEMENT ABOUT USERS, FALSE AS A STATEMENT ABOUT THIS ABORT; see the box.
```

**And this is ADR 0052's own hazard turned on itself**, which is worth stating rather than leaving
implicit: the narrowing froze a false guarantee into a file the ADR then makes hard to correct. The
correction was possible only because the corollary's scope is the executing half — which the ADR did
not say plainly until this branch, and now does.

#### Proof the executing half is untouched

`sed -n '/^return new class/,$p'` — the form `2026_08_08_100000`'s remediation used — **does not work
here**, because the amended comment lives _inside_ the class body rather than in a docblock above it.
That slice reports the comment as a difference. So both revisions were stripped of every `T_COMMENT`
and `T_DOC_COMMENT` via `token_get_all`, blank-only lines dropped, and the remainder diffed:

```console
$ git show 17da5c3:database/migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php > 08_06.at17da5c3.php
$ diff <(php strip-comments.php 08_06.at17da5c3.php) \
       <(php strip-comments.php database/migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php)
(no output — EXECUTING HALF byte-identical to 17da5c3, after Pint)

$ git diff --quiet HEAD -- database/migrations/2026_08_03_100000_converge_finance_change_grants.php
08_03: whole file untouched
```

Re-run after Pint, not before — Pint reformatting the box would have been a silent executing-half
change if the box had been malformed.

### R2 tickets

| review # | what                                                                                     | done                                                                                                                                                                                                                                                                                                                                                                   |
| -------- | ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 3        | This report's raised finding #2 was false as written                                     | Struck and corrected in **Findings raised, not fixed** below, with the narrower real gap re-derived (`accounts_officer`, `admin`, `finance_lead` — **not** the reviewer's `internal_auditor`, which holds no finance grant at all) and the 4c merge warning                                                                                                            |
| 4        | `MoveHosFinanceToEdConvergenceTest:105` said `principal` "is GOVERNED by this migration" | Comment corrected to "is NOT governed", with the migration's own `:121-124` cited and an explicit "do not read this as a reason to add principal to TARGET"                                                                                                                                                                                                            |
| 5        | ADR 0052's `DutySeparation` inventory said "RUNTIME — 7 call sites" and named six files  | Re-derived rather than copied. **RUNTIME is 6 files / 11 call sites, not 10** — the reviewer's own list totals 11. And a second error they did not catch: **REPORT is 5 call sites, not 4** — `2026_08_03:363` has a `holdsViaGrant` in its `report()` like the other four and was omitted. DECIDE is 2 walks / 4 call sites. 11 + 5 + 4 = 20, which is the whole grep |

### R2 — throwaway databases

Dropped at the end of this session: `portal_replay_test`, `portal_oracle_test`, `portal_grants_test`,
`portal_edabort_test`, `portal_deploysim_test`. Nothing was left behind, and none of them was ever the
dev copy `portaa10_portal` or `portal_testing`.

---

## Deviations from the brief

**Item 2 was withdrawn by the project lead after I reported its premise was false.** The brief said
staging now has `finance.opening-balance.approve/.reject` and asked me to add them to
`2026_08_06_100000`'s `TARGET` and to the seeder's `executive_director` grants, removing them from
`head_of_school`. None of that is possible or safe on this tree:

- The two permissions do **not** exist on `origin/staging` or on this merged branch. `grep -rn
"opening-balance" app/Enums/Permission.php` returns nothing; a repo-wide grep for the literal
  finds only opening-balance _tables_ and _spec prose_. They exist only on
  `feat/finance-ob-approval-gate` @ `911adc2` (unmerged), at `app/Enums/Permission.php:159-160`,
  where `database/seeders/RbacSeeder.php:240-241` grants them to `head_of_school`.
- So there is nothing here to remove from `head_of_school`, and no enum case to add to
  `executive_director`'s slice — the seeder edit would not compile.
- Adding the two strings to `2026_08_06`'s `TARGET` would make its missing-permission pre-flight
  (`database/migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php:182-187`)
  throw `move-hos-finance-to-ed ABORTED: target permission(s) absent from the permissions table —
run 'php artisan rbac:sync' first` on every environment until 4c merges. `rbac:sync` cannot create
  a permission absent from the enum, so that abort has **no exit**. Under ADR 0052's two-part test
  that is part 1 YES / part 2 NO: _do not convert and do not leave it — escalate._

**The lead's ruling, recorded so a later reader does not re-open the gap:** the fix belongs on the
4c branch, moving `finance.opening-balance.approve/.reject` from `head_of_school` to
`executive_director` in `RbacSeeder`. They are **new** permissions, so convergence-lint exemption 1
applies correctly and `rbac:sync` grants them per `grantsMap()` on every environment. The **map is
the whole mechanism**. No migration is needed, and none is possible while the enum cases live only
on the 4c branch. `2026_08_06` and its `TARGET` were left untouched.

**No other deviations.**

## Contradictions of the premise

Two, both in item 1's framing, and both change the answer rather than decorating it.

**1. The from-zero replay the brief asked for does not reach the walk at all.** On `migrate`
against an empty database, `2026_08_03`'s fresh-install guard
(`database/migrations/2026_08_03_100000_converge_finance_change_grants.php:128-135`, keyed on the
seeder-owned permission substrate) returns before anything else runs — identically on the pre-edit
and post-edit files. A from-zero replay that does not abort is therefore **not evidence the abort
is harmless**, and taken alone it would have produced the wrong ruling. The proof below seeds the
substrate with `rbac:sync` and clears the `migrations` row, which is what makes the walk reachable.

**2. The brief's option (b) — "revert the edit on `2026_08_03` and carry the narrowing in
`2026_08_06_100000`, which has never been applied" — is not available.** `2026_08_06` has its own
walk over its own `$grantedThisRun`; narrowing it does nothing to `2026_08_03`'s abort predicate.
No later migration can stop an earlier migration's `up()` from throwing on the next replay. So the
choice was never (a) or (b); it was _edit the applied file, or leave it unreplayable_. That is what
makes the carve-out a carve-out rather than a shortcut, and it is stated as such in the ADR.

## What changed

| file                                                | ±                      | what                                                                                                                                                                                                                            |
| --------------------------------------------------- | ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `docs/adr/0052-a-migration-is-a-dated-act.md`       | conflict resolved + 62 | merge kept **both** new sections (the branch's `DutySeparation` boundary and staging's applied-migration corollary); then the argued carve-out for `2026_08_03`, with the replay evidence and the four conditions that scope it |
| `tests/fixtures/route-access-map.json`              | +20                    | re-derived; gains `POST /api/notifications/ses-events`                                                                                                                                                                          |
| `tests/Feature/Rbac/DutySeparationBaselineTest.php` | +30 −9                 | ARM 3 / ARM 4 re-pointed at `executive_director` as the checker seat; ARM 3's baseline derived from the current finding set so it is precise again; ARM 4's counts made to line up                                              |

Nothing else. In particular: **no migration file was edited in this session**, and
`phpstan-baseline.neon` and `tests/ratchet-baseline.txt` were not touched.

```text
$ git show --name-status --format='%s' 81bb0ac | head -5
Merge remote-tracking branch 'origin/staging' into feat/executive-director-role

$ git show --name-status --format='%s' 24a8909
docs(adr): argue the carve-out for editing 2026_08_03 after it had applied
M	docs/adr/0052-a-migration-is-a-dated-act.md

$ git show --name-status --format='%s' 6756596
chore(rbac): re-derive the fixture oracles after the staging merge
M	tests/fixtures/route-access-map.json

$ git show --name-status --format='%s' db68a6f
fix(rbac): re-point the duty-separation baseline arms at the seat that holds the checker side
M	tests/Feature/Rbac/DutySeparationBaselineTest.php
```

### The 2026_08_06 TARGET, unchanged

Item 2 was withdrawn, so the TARGET the brief asked me to edit is exactly as `5236242` froze it, and
`2026_08_03` is exactly as `17da5c3` left it:

```console
$ git diff --quiet 17da5c3..HEAD -- database/migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php \
    && echo "08_06: IDENTICAL to 17da5c3" || echo "08_06: DIFFERS"
08_06: IDENTICAL to 17da5c3

$ git diff --quiet 17da5c3..HEAD -- database/migrations/2026_08_03_100000_converge_finance_change_grants.php \
    && echo "08_03: IDENTICAL to 17da5c3" || echo "08_03: DIFFERS"
08_03: IDENTICAL to 17da5c3
```

Every migration difference between `17da5c3` and `HEAD` is an **addition** carried in by the merge —
seven files, all `A`, none `M`:

```console
$ git diff 17da5c3..HEAD --name-status -- database/migrations/
A	database/migrations/2026_08_05_120000_create_notification_suppressions.php
A	database/migrations/2026_08_06_100000_create_finance_opening_balance_tables.php
A	database/migrations/2026_08_07_100000_add_file_row_count_to_opening_balance_batches.php
A	database/migrations/2026_08_07_110000_add_provenance_to_finance_payments.php
A	database/migrations/2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file.php
A	database/migrations/2026_08_08_110000_opening_balance_posting_state_and_guards.php
A	database/migrations/2026_08_08_120000_opening_balance_posted_rows_are_terminal.php
```

## Item 1 — the ruling

**(a) Keep the edit, and add the carve-out to ADR 0052's corollary.** Written at
`docs/adr/0052-a-migration-is-a-dated-act.md`, section _"The carve-out: `2026_08_03`, edited after
it had already applied"_.

### The diff the brief asked me to verify

```text
$ mb=$(git merge-base origin/staging feat/executive-director-role)   # 806f8f7
$ f=database/migrations/2026_08_03_100000_converge_finance_change_grants.php
$ diff <(git show $mb:$f | sed -n '/^return new class/,$p') <(sed -n '/^return new class/,$p' $f)
138c138
<         $this->report('BEFORE', $sixNames, $skipped);
---
>         $this->report('BEFORE', $sixNames, $skipped, 0);
149c149,155
<         DB::transaction(function () use ($roles, $target, $inNs) {
---
>         $outOfScope = 0;
>
>         DB::transaction(function () use ($roles, $target, $inNs, &$outOfScope) {
[...]
189,190c211,212
<                         if ($isFinance) {
<                             $bothSidesUsers[] = "user#{$user->id} @ school#{$school->id} ...";
---
>                         if (! $isFinance) {
>                             continue;
[...]
>                         $thisRunWroteASide = in_array($pair['maker'], $grantedThisRun, true)
>                             || in_array($pair['checker'], $grantedThisRun, true);
[...]
209c254
<         $this->report('AFTER', $sixNames, $skipped);
---
>         $this->report('AFTER', $sixNames, $skipped, $outOfScope);
```

Confirmed: the brief is right that `17da5c3` edits the **executing** half of an applied migration —
the walk's scope, an `$outOfScope` counter, and `report()`'s new argument. This is not the
freeze ADR 0052 sanctioned.

### The counter-argument, tested

**Setup, on a from-zero throwaway database (`portal_replay_test`), for both file versions:**

1. drop/create the database, `migrate --force` from zero;
2. `rbac:sync` — seeds the permission substrate at today's map;
3. plant `school#1` and `user#2`, holding `executive_director` **and** a bespoke role granting only
   `finance.credit-note.submit` (both inserted raw into `model_has_roles`: grant-time enforcement
   refuses the pairing through the spatie API, which is exactly why a migration is the only thing
   that can meet it already in place);
4. delete `2026_08_03`'s `migrations` row;
5. `migrate --force` — the migration replays for real.

Neither `finance.credit-note.submit` nor `finance.credit-note.approve/.reject` is in either
namespace this migration governs, and neither of the two roles is one it can touch.

**PRE-EDIT file (merge-base `806f8f7`) — ABORTS. `migrate` exit 1.**

```text
 INFO Running migrations.

 2026_08_03_100000_converge_finance_change_grants   converge-finance-change-grants REPORT: global role(s) outside this migration's scope also grant the governed permissions: executive_director (holders=1). Not an error — this migration governs principal, head_of_school, accounts_officer, accounts_supervisor, finance_lead only and cannot touch them.
  converge-finance-change-grants: school-scoped role rows carrying any of the six (UNTOUCHED): 0
  converge-finance-change-grants [BEFORE] holders per school per governed permission (skipped=0):
    school#1  finance.discount-policy.change.approve  holders=1
    school#1  finance.discount-policy.change.reject  holders=1
    school#1  finance.discount-policy.change.submit  holders=0
    school#1  finance.fee-schedule.change.approve  holders=1
    school#1  finance.fee-schedule.change.reject  holders=1
    school#1  finance.fee-schedule.change.submit  holders=0
.. 155.33ms FAIL

   RuntimeException

  converge-finance-change-grants ABORTED (rolled back): 2 user(s) would hold both sides of a finance maker-checker pair after convergence — user#2 @ school#1 finance.credit-note.submit<>finance.credit-note.approve; user#2 @ school#1 finance.credit-note.submit<>finance.credit-note.reject. Reassign one of the two roles for each listed user, then re-run the migration.

  at database/migrations/2026_08_03_100000_converge_finance_change_grants.php:276
```

Post-state: `head_of_school` finance-change grants **0** (rolled back), `migrations` row for
`2026_08_03` **absent**. The ADD-side gap the migration exists to close stays open, and no
`migrate` command can close it.

**POST-EDIT file (branch `17da5c3`) — REPORTS and COMMITS. `migrate` exit 0.**

```text
 2026_08_03_100000_converge_finance_change_grants   converge-finance-change-grants REPORT: global role(s) outside this migration's scope also grant the governed permissions: executive_director (holders=1). Not an error — this migration governs principal, head_of_school, accounts_officer, accounts_supervisor, finance_lead only and cannot touch them.
  converge-finance-change-grants: school-scoped role rows carrying any of the six (UNTOUCHED): 0
  converge-finance-change-grants [BEFORE] holders per school per governed permission (skipped=0, out-of-scope both-sides findings=0):
    school#1  finance.discount-policy.change.approve  holders=1
    school#1  finance.discount-policy.change.reject  holders=1
    school#1  finance.discount-policy.change.submit  holders=0
    school#1  finance.fee-schedule.change.approve  holders=1
    school#1  finance.fee-schedule.change.reject  holders=1
    school#1  finance.fee-schedule.change.submit  holders=0
  converge-finance-change-grants REPORT: 2 both-sides finding(s) this run did NOT create — not blocked on:
    user#2 @ school#1 finance.credit-note.submit<>finance.credit-note.approve
    user#2 @ school#1 finance.credit-note.submit<>finance.credit-note.reject
    These are real and they matter. They belong to `php artisan finance:audit-duty-separation`, not to a migration.
  converge-finance-change-grants [AFTER] holders per school per governed permission (skipped=0, out-of-scope both-sides findings=2):
    school#1  finance.discount-policy.change.approve  holders=1
    school#1  finance.discount-policy.change.reject  holders=1
    school#1  finance.discount-policy.change.submit  holders=0
    school#1  finance.fee-schedule.change.approve  holders=1
    school#1  finance.fee-schedule.change.reject  holders=1
    school#1  finance.fee-schedule.change.submit  holders=0
.. 171.93ms DONE
```

Post-state: `head_of_school` finance-change grants **4** (the frozen target, granted), `migrations`
row **present**.

**And the pair replayed together, in filename order** — `migrations` rows for both `2026_08_03` and
`2026_08_06` cleared, then `migrate --force`:

```text
exit=0
  move-hos-finance-to-ed REPORT: 2 both-sides finding(s) this run did NOT create — not blocked on:
HoS finance.* after replaying BOTH: 0
AO fee-schedule.change.submit: yes
```

The dated act, reproduced: `head_of_school` ends with zero `finance.*` grants and
`accounts_officer` keeps the maker side `2026_08_03` exists to give it. `2026_08_03`'s intermediate
re-grant to `head_of_school` is stripped again by `2026_08_06`, which sorts after it.

### The from-zero path, for completeness — it decides nothing

```text
$ env DB_DATABASE=portal_replay_test php artisan migrate --force
 2026_08_03_100000_converge_finance_change_grants   converge-finance-change-grants: finance RBAC substrate unseeded (no finance-change permissions) — nothing to converge.
```

Identical on both file versions. The walk is unreachable; the guard returns first.

### Why the edit stands

Four conditions, all of which hold here and all of which are written into the ADR as the scope of
the carve-out:

1. **`down()` is a documented no-op** (`:339-342`), so the `up()`/`down()` shape divergence the
   corollary was written from cannot arise, and "roll it back first" is a tautology.
2. **The edit is behaviour-identical on the state the original run met.** The out-of-scope set was
   empty on 2026-08-02 — it had to be, or that run would have aborted instead of committing. With
   it empty the two versions are the same program: same diff, same writes, same activity rows,
   differing only by an `out-of-scope=0` field in one echo. Same property that made the four target
   freezes behaviour-preserving.
3. **The file is otherwise unreplayable** — proved above, raw.
4. **No new dated migration could carry the change** — nothing a later file writes stops
   `2026_08_03::up()` throwing.

`2026_08_06` is edited on the same branch by the same commit and needs no carve-out: it has never
merged, so it has applied nowhere except possibly a local database — and its `down()` is likewise a
documented no-op, so the same four conditions cover it. **If it has been applied to a local database
on this branch, that is worth checking before merge**; I could not check the lead's machine.

## Item 3 — the timestamp collision

```text
$ env DB_DATABASE=portal_replay_test php artisan migrate --force
 2026_08_05_120000_create_notification_suppressions .. 40.60ms DONE
 2026_08_06_100000_create_finance_opening_balance_tables .. 264.00ms DONE
 2026_08_06_100000_move_head_of_school_finance_to_executive_director   move-hos-finance-to-ed: finance RBAC substrate unseeded (no finance.* permissions) — nothing to converge.
 0.93ms DONE
 2026_08_07_100000_add_file_row_count_to_opening_balance_batches  13.33ms DONE
```

`create_finance_opening_balance_tables` runs first. It is not luck and it is not chance: Laravel
keys migrations by basename and `sortBy`s on that key
(`vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:578-586`), so a shared
timestamp prefix falls through to a **deterministic** string comparison of the descriptive suffix —
`create_…` < `move_…`. What nobody chose is that the resulting order is the convenient one.

**Am I relying on the ordering? No.** The two migrations are independent:
`create_finance_opening_balance_tables` creates tables and touches no role, permission or grant;
`move_head_of_school_finance_to_executive_director` reads and writes `roles`,
`role_has_permissions`, `permissions`, `model_has_roles`, `schools` and `users` and creates no
table. Neither reads anything the other writes, so there is no unsafe order for them to fall into.
The ordering is deterministic **and** irrelevant — I am recording it rather than depending on it.

## Item 4 — how I re-derived the oracles

The 45-commit merge produced **no conflict in any fixture**, which is worse than a conflict:
nothing asked anyone to look. So I re-derived all three from scratch rather than trusting the
auto-merge, in the documented order, and diffed re-derived against auto-merged.

Two throwaway databases, neither of them the dev copy and neither of them `portal_testing`:

```bash
# A — route oracles.  portal_oracle_test
php artisan tinker --execute="DB::statement('DROP DATABASE IF EXISTS `portal_oracle_test`');
                              DB::statement('CREATE DATABASE `portal_oracle_test`
                                             CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');"
env DB_DATABASE=portal_oracle_test php artisan migrate --force
env DB_DATABASE=portal_oracle_test php artisan rbac:sync
env DB_DATABASE=portal_oracle_test php artisan rbac:derive-access   # route-access-map.json (360 routes)
env DB_DATABASE=portal_oracle_test php artisan rbac:derive-map      # route-middleware-baseline.json (360 routes)

# B — grants baseline.  portal_grants_test.  No command exists; produced with the EXACT expression
#     PermissionEnumTest.php:30-41 asserts against, so the fixture cannot drift from its own oracle.
env DB_DATABASE=portal_grants_test php artisan migrate --force
env DB_DATABASE=portal_grants_test php artisan db:seed --class=Database\\Seeders\\DatabaseSeeder --force
env DB_DATABASE=portal_grants_test php artisan tinker --execute="
\$webRoles = App\Models\Role::with('permissions')->where('guard_name','web')->get();
if (\$webRoles->pluck('name')->duplicates()->isNotEmpty()) { throw new RuntimeException('duplicate web-guard role names'); }
\$actual = \$webRoles->mapWithKeys(fn (\$r) => [\$r->name => \$r->permissions->pluck('name')->sort()->values()->all()])->sortKeys()->all();
file_put_contents(base_path('tests/fixtures/rbac-grants-baseline.json'),
    json_encode(\$actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);"
```

Separate databases on purpose: `rbac:sync` and `DatabaseSeeder` are different entry points into the
same map, and running one after the other on a shared database would make it impossible to say
which produced the fixture.

**Result — re-derived vs auto-merged:**

| fixture                          | diff lines    | verdict                            |
| -------------------------------- | ------------- | ---------------------------------- |
| `rbac-grants-baseline.json`      | 0             | auto-merge was correct             |
| `route-middleware-baseline.json` | 0             | auto-merge was correct             |
| `route-access-map.json`          | 20 (+1 entry) | auto-merge was **short one route** |

The missing entry:

```diff
     "POST /api/notifications/seen": {
         "auth": true,
+        "roles": [ ...15 roles... ]
+    },
+    "POST /api/notifications/ses-events": {
+        "auth": false,
         "roles": [ ...15 roles... ]
```

`POST /api/notifications/ses-events` is staging's SES webhook
(`routes/endpoints/notifications.php:72-74`, `SesEventController`). It is absent from **staging's
own committed fixture** — 350 entries there against 360 routes live post-merge. No gate would have
demanded it: `tests/Feature/Rbac/RouteAccessParityTest.php:17-22` documents the asymmetry — only
fixture routes are asserted, so a new route is never blocked there. That is deliberate design, not
a defect; the consequence is that the oracle only ever gains entries when someone regenerates it,
which is what this commit does.

## Database observations

Under the privacy rule — ids, counts, structure only.

Seeded finance grants after the merge (`portal_grants_test`, `DatabaseSeeder`, global rows):

| role                  | `finance.*` grants                                         |
| --------------------- | ---------------------------------------------------------- |
| `head_of_school`      | 0                                                          |
| `executive_director`  | 9                                                          |
| `accounts_supervisor` | 2 (`finance.access`, `finance.fee-schedule.change.submit`) |
| `principal`           | 1 (`finance.access`)                                       |

The merge preserved the branch's intent exactly: HoS holds no finance, ED holds all four finance
checker pairs plus `finance.access`, and `principal` keeps `finance.access` as the 2026-08-04
decision requires.

Replay database (`portal_replay_test`): 1 school, 2 users, 1 bespoke role. No production or dev
database was written to at any point — the dev copy `portaa10_portal` was never the target of a
`migrate`, a `rbac:sync` or a seeder in this session.

## Proof — `bin/quality`

Run against base `6890edb` (`origin/staging`). **Run 1 failed** at step 14 — that output is in the
`DutySeparationBaselineTest` section above. **Run 2, after `db68a6f`, raw:**

```text
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
QUALITY EXIT=0
```

**Run 3, after round 2 (`98391fa`), raw:**

```text
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
QUALITY EXIT=0
```

Two caveats on what that green covers, neither of them new to this branch. Step 14 is the failure
**ratchet**, not a clean suite — pre-existing failures in `tests/ratchet-baseline.txt` are frozen and
I did not touch that file. And `bin/quality-clean-db` (throwaway DB, migrate-from-zero against data,
rollback/re-up) is part of `bin/quality-promote`, not of the per-push floor; it was **not** run here.
The from-zero migrations in this report were run by hand, not through that script.

## The defect the merge surfaced — `DutySeparationBaselineTest`

The first `bin/quality` run failed at step 14:

```text
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✗ test-ratchet

       ratchet: 2 NEW test failure(s) not in the baseline (regression):
         ✗ tests/Feature/Rbac/DutySeparationBaselineTest.php::it ARM 3 — a FINANCE finding fails even when it IS in the baseline
         ✗ tests/Feature/Rbac/DutySeparationBaselineTest.php::it ARM 4 — the resolved-one-appeared-one case a COUNT ratchet would pass
```

Reproducible in isolation, so not the known cross-test-pollution flake:

```json
{
    "tool": "pest",
    "result": "failed",
    "tests": 8,
    "passed": 6,
    "failed": 2,
    "failures": [
        {
            "test": "...ARM_3...",
            "line": 125,
            "message": "Failed asserting that 0 is identical to 1."
        },
        {
            "test": "...ARM_4...",
            "line": 152,
            "message": "Failed asserting that 0 is identical to 1."
        }
    ]
}
```

**Cause.** The file arrived with the merge (staging's scheduled-detector work) and was authored
against the pre-2026-08-04 map. Both arms plant a finance both-sides holder as `accounts_officer` +
`accounts_supervisor`. The seat move took every finance checker side off `accounts_supervisor`,
which now holds `finance.access` and `finance.fee-schedule.change.submit` only — a maker-only seat.
So the plant produced no both-sides finding, `finance:audit-duty-separation` short-circuited to
SUCCESS, and both arms asserted `1` against `0`. Re-pointed to `accounts_officer` +
`executive_director`, which is where the four finance checker pairs now live.

## The watched red

**Two, both on the test fix. Nothing was planted for items 1, 3 or 4 — see the end of this section.**

### Red 1 — the arms were red before the fix, green after

Pasted above: the ratchet output naming both arms, and the isolated run reproducing it. After
`db68a6f`, on the same command:

```json
{
    "tool": "pest",
    "result": "passed",
    "tests": 8,
    "passed": 8,
    "assertions": 21,
    "duration_ms": 10570
}
```

### Red 2 — the mutation, which found the fix was not yet enough

Re-pointing the plant made both arms green, and **that green was wrong**. Mutation planted in
`app/Console/Commands/AuditDutySeparation.php:140-144`, deleting the hard-coded finance refusal
ARM 3 exists to pin:

```diff
         $financeFindings = collect($findings)
-            ->filter(fn (array $f): bool => str_starts_with($f['checker'], self::NEVER_BASELINEABLE)
-                || str_starts_with($f['maker'], self::NEVER_BASELINEABLE))
+            ->filter(fn (array $f): bool => false) // BITE-PROOF MUTANT
```

**First attempt — ARM 3 stayed GREEN under the mutant:**

```json
{
    "tool": "pest",
    "result": "passed",
    "tests": 2,
    "passed": 2,
    "assertions": 2,
    "duration_ms": 8869
}
```

With the checker sides on `executive_director` the plant produces findings across **all four**
finance pairs, not the two `accounts_supervisor` used to give. ARM 3's carried-over four-line
baseline therefore left four findings unaccepted, and the arm exited 1 through the **ordinary
unaccepted-findings path** — passing while proving nothing about the hard-code. Fixed by deriving
ARM 3's baseline from the current finding set, so every finding is accepted and the refusal is the
only thing left that can fail the run.

**Second attempt — ARM 3 goes RED under the same mutant:**

```json
{
    "tool": "pest",
    "result": "failed",
    "tests": 1,
    "passed": 0,
    "assertions": 3,
    "failures": [
        {
            "test": "...ARM_3_—_a_FINANCE_finding_fails_even_when_it_IS_in_the_baseline",
            "line": 143,
            "message": "Failed asserting that 0 is identical to 1."
        }
    ]
}
```

The message names the right thing: exit 0 where 1 was required, i.e. the baseline silenced a finance
finding. **Restored** — `git status --short app/Console/Commands/AuditDutySeparation.php` empty, and
8/8 green afterwards.

ARM 4 stays green under this mutant (`tests":1,"passed":1`). That is correct and its own MEASURED
note says so: the tuple test refuses it independently, which is the arm's point — two guards, neither
load-bearing alone.

### Item 1's substitute for a planted red

Items 1, 3 and 4 add no guard, so there was none to bite-prove. What stands in for it on item 1 is
stronger than a mutation, because the two states came from two **real files** rather than an edit I
invented: the pre-edit file was checked out over the post-edit one and `migrate` was run for real on
a from-zero database. Exit 1, rolled back; restored, exit 0, committed. Both raw outputs above; the
file was restored byte-for-byte (`git status` clean on that path afterwards).

The arms that pin the narrowing itself — `FinanceChangeGrantConvergenceTest` ARM 7 and
`MoveHosFinanceToEdConvergenceTest` ARM 8, both added by `17da5c3` — were written by the previous
session and I did **not** independently bite-prove them. They pass in the suite run below. Their red
state is unwitnessed by me, and after what Red 2 turned up in a neighbouring arm that is worth a
reviewer's time.

## Not done

- **Item 2**: withdrawn by the project lead. The `finance.opening-balance` checker side still sits
  on `head_of_school` on `feat/finance-ob-approval-gate`. That branch is where it gets fixed, and it
  is not fixed yet. **This is a live open item, not a closed one.**
- **`2026_08_06` applied locally?** If the lead has run `migrate` on this branch against a local
  database, `2026_08_06` has applied there and `5236242` + `17da5c3` edited it afterwards. The
  carve-out's four conditions cover it, but the _state_ of that database was not checked — only the
  lead can check it.
- **Branch not pushed, not merged**, per the brief.
- Throwaway databases `portal_replay_test`, `portal_oracle_test` and `portal_grants_test` are left
  on the local server for inspection. Drop them when done.

## Findings raised, not fixed

- `tests/fixtures/route-access-map.json` on `origin/staging` is short by at least one route
  (`POST /api/notifications/ses-events`). Fixed here by re-derivation, but the _class_ recurs every
  time a route ships without regeneration, and `RouteAccessParityTest` is documented not to catch
  it. **ticket** — the asymmetry is deliberate, so the fix is a separate "every live route has a
  fixture entry" arm, not a change to this one.
- ~~`bin/ci-grants-convergence-lint.php` exemption 1 waives a migration for NEW permissions, which is
  correct — but it means a new checker-side permission landing on the wrong seat is invisible to
  every gate.~~ **FALSE AS WRITTEN — corrected 2026-08-08 after review.** `FinanceRoleRealignmentTest`
  pins the exact `finance.*` slice of four roles: `executive_director` (`:95-105`),
  `head_of_school` → `[]` (`:115`), `principal` → `['finance.access']` (`:116`) and
  `accounts_supervisor` (`:119-122`). So a checker side landing on `head_of_school` is **not**
  invisible — it turns that arm red.

    **The real gap, re-derived:** of the roles that hold any `finance.*` grant today —
    `accounts_officer` (9), `accounts_supervisor` (2), `admin` (4), `executive_director` (9),
    `finance_lead` (3), `principal` (1) — the three with **no** exact-slice pin are
    **`accounts_officer`, `admin` and `finance_lead`**. A checker side landing on one of those is
    invisible to every gate. Every other global role (`internal_auditor` included) holds no finance
    grant, and nothing asserts that it stays that way. **ticket** — the arm to add is "no global role
    outside `executive_director` holds a `finance.*` checker"; `GrantsMapSeparationTest` does not cover
    it (it catches same-role both-sides only).

    **WARNING FOR WHOEVER MERGES 4c.** `feat/finance-ob-approval-gate` grants
    `finance.opening-balance.approve/.reject` to `head_of_school` (`RbacSeeder.php:240-241` on that
    branch). When it lands on top of this one, `FinanceRoleRealignmentTest`'s
    `expect($finance('head_of_school'))->toBe([])` **goes red — correctly**. The fix is **moving those
    two grants to `executive_director`'s slice**, not editing the expected array. That file's own
    sibling comment (`:106-107`) states the trap: "an equality assertion silently stops being about
    submits the moment someone edits the expected array".
