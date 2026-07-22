# Phase-1 first production deploy — runbook

**Status: assembled and repo-verified 2026-07-20. NOT executed.**
The repo-side items below are done and evidenced. The environment items are
human-gated and are **not** claimed — each names the exact observation required.

This is the least reversible action in the project: all Phase-1 migrations land
together, against the first real multi-School data these migrations have ever met.
Run the steps **in order**. A failure at any step stops the deploy; nothing
irreversible runs before its guard.

---

## Deploy condition 0 — what "gates green" does and does not certify

Not a blocker. A standing condition to understand before relying on the floor.

- **CI is intentionally off** and is not being restored. `bin/quality` is the
  permanent enforcement floor.
- Every gate in that floor is **audited and bite-proven to detect and `exit 1`
  locally** (see `docs/testing.md` § Gate audit — six of ten were found defective
  across the arc and fixed).
- **A red gate does not block a merge.** There are no required status checks; a
  web-UI merge or `--no-verify` bypasses the local hook.

So **"gates green" certifies _detected-clean locally on the promoted commit_ — not
_blocked-at-merge_.** The deploy's safety therefore rests on a **human-discipline
dependency**: that promotion actually happened as a stamped `--ff-only` push from a
machine with the hook installed. If that discipline is not followed, the gates prove
nothing about what shipped. State it; do not paper over it.

---

## Step 1 — §C1 enrollment-School drift. **Runs FIRST, before any migration.**

**Why first:** slice (i) creates a composite FK on `student_curricula`. Any episode
whose student's School disagrees with its curriculum's School **fails FK creation and
aborts the migration mid-deploy**. Ending or withdrawing the episode does not help —
the FK constrains `curriculum_id` regardless of status.

|                    |                                                                                      |
| ------------------ | ------------------------------------------------------------------------------------ |
| **Check**          | Run the C1 query in `prod-divergence-and-cascade-queries.sql` against **production** |
| **Pass criterion** | **0 rows**                                                                           |
| **Failure action** | STOP. Remediate per class (below) to zero, re-run, then proceed                      |
| **Gate**           | **Human-gated** (production data)                                                    |

**Prod is this query's first real exercise, and dev's zero carries no information.**
The dev database holds exactly one School, so a mismatch is _structurally impossible_
there. What dev proved is the **mechanics** — `agree(977) + disagree(0) = total(977)`,
and zero episodes fail to join either parent, so the join is correct and nothing is
silently dropped. The disagree branch has never matched a real row. **Treat a non-zero
prod result as the expected outcome and a zero as pleasant news, not as confirmation
that the query works.**

Remediation must be **ready before deploy day, not improvised at 2am**:

| drift class            | remediation                                                                      |
| ---------------------- | -------------------------------------------------------------------------------- |
| childless / uninvoiced | delete, or repoint to the correct same-School curriculum                         |
| graded (has results)   | **escalate** — entangled with Option-B's re-key; do not improvise                |
| invoiced               | its own slice. **Cannot arise at first deploy** — `finance_invoices` ships empty |

Detail: `docs/runbooks/slice-i-preflight-and-remediation.md`.

---

## Step 2 — `students.school_id` null-free

The tenant anchor the entire isolation model rests on. A NULL here means a student
belonging to no School, which `BelongsToSchool` and every scope silently mis-handle.

|                    |                                                                                                                               |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------- |
| **Check**          | `SELECT COUNT(*) FROM students WHERE school_id IS NULL;`                                                                      |
| **Pass criterion** | **0**                                                                                                                         |
| **Failure action** | STOP. Assign the correct School per row; there is no safe default (Constitution 13 forbids defaulting from `users.school_id`) |
| **Gate**           | **Human-gated** (production data)                                                                                             |

---

## Step 3 — migrate

Only after steps 1 and 2 are zero.

|                    |                                                                        |
| ------------------ | ---------------------------------------------------------------------- |
| **Check**          | `php artisan migrate --force`                                          |
| **Pass criterion** | exit 0                                                                 |
| **Failure action** | STOP — see "if migrate fails mid-run" below. Do **not** re-run blindly |
| **Gate**           | Human-executed; repo-verified that the chain applies cleanly from zero |

**If migrate fails mid-run:** MySQL DDL is **not transactional**. A migration that
fails partway leaves a **half-applied schema**, and the same is true in reverse for a
failed `down()` — this was observed directly during the four-path audit, where an
aborted `down()` left the database in a state the next attempt could not parse. Do not
loop `migrate`. Capture the error, and treat recovery as restore-from-backup unless the
partial state is understood.

---

## Step 4 — `audit:verify-immutability`, **wired into the pipeline after migrate**

A **pipeline step, not a one-time check**. `activity_log`'s append-only guarantee is
enforced by database triggers, and **triggers do not reliably survive a logical
restore**. Without this assertion running after every migrate/restore, a restore that
silently dropped the triggers leaves the audit log quietly mutable — the partial-restore
hazard, undetectable by reading the data.

|                    |                                                                                                                                                                                                                   |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Check**          | `php artisan audit:verify-immutability` as a deploy-pipeline step after `migrate`                                                                                                                                 |
| **Pass criterion** | exit 0                                                                                                                                                                                                            |
| **Failure action** | STOP; triggers are missing — re-apply before accepting traffic                                                                                                                                                    |
| **Gate**           | **Human-gated** (pipeline configuration). Verified present in the repo: the command exists and self-describes as _"Assert the activity_log immutability triggers exist (fail loudly if a restore stripped them)"_ |

---

## Step 5 — scheduler EXECUTION, before observe-mode traffic

**Binding condition: observe mode must not receive production traffic until scheduler
execution is verified in that environment.** `authz_observations` is a temporary
evidence store pruned by a scheduled job; with nothing invoking it the table grows
unbounded and ADR 0043's retention guarantee is void.

`php artisan schedule:list` **proves the wrong thing.** It shows the job is
_registered_ — verified in this repo: `0 0 * * * php artisan authz:prune
--older-than=30`. Registration says nothing about whether the OS ever invokes
`schedule:run`. A registered job that nothing calls is inert, and looks identical to a
working one from inside the application.

|                    |                                                                                                                                                                   |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Check**          | Exactly one of: system cron running `schedule:run` every minute, a supervisor-managed `schedule:work`, or the platform scheduler — **and its execution observed** |
| **Pass criterion** | An **actual `authz:prune` run visible in logs** at/after its scheduled time, or a health ping from the scheduled job. Not `schedule:list`                         |
| **Failure action** | Do not enable observe-mode traffic. Fix the invoker first                                                                                                         |
| **Gate**           | **Human-gated** (OS/platform). The repo cannot prove this and must not infer it                                                                                   |

---

## What each step actually proves — and where the obvious check is insufficient

| step                    | the obvious check      | why it is insufficient                                                                                       | the sufficient check                                                  |
| ----------------------- | ---------------------- | ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------- |
| §C1 drift               | dev returns 0          | dev is single-School; a mismatch is structurally impossible, so the disagree branch never matched a real row | run against **prod**; treat non-zero as expected                      |
| migration reversibility | `migrate:fresh` passes | `fresh` never calls `down()` — it is structurally blind to every reversibility bug                           | **rollback, then migrate again** (four-path)                          |
| scheduler               | `schedule:list`        | proves registration, not invocation                                                                          | an **observed `authz:prune` run** in logs                             |
| immutability triggers   | they exist today       | a later restore can strip them silently                                                                      | `audit:verify-immutability` **wired after every migrate**             |
| gates green             | all 10 pass            | certifies detection locally, not merge-blocking                                                              | confirm the **stamped `--ff-only` promotion discipline** was followed |

---

## Appendix — the four-path migration audit (repo-verifiable, DONE)

Every migration was run through **fresh → data → rollback → re-upgrade** against a
throwaway database. `migrate:fresh` never rolls back, so **none of these `down()`
methods had ever executed** — the audit was their first run, and it found **seven real
bugs**, five of which aborted the rollback chain outright.

| #   | migration                                                  | defect                                                                                                                                                                        | fix                                                             |
| --- | ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- |
| 1   | `2026_06_14_213743_add_comment_to_student_subjects`        | `dropColumn` before `dropForeign` → MySQL 1828                                                                                                                                | drop the constraint first                                       |
| 2   | `2026_05_06_085734_update_terms_and_curricula`             | same shape on `term_id`                                                                                                                                                       | drop the constraint first                                       |
| 3   | `2026_05_02_094000_add_teams_support_to_permission_tables` | `try { $table->dropUnique() } catch {}` is **inert** — Blueprint defers, so the exception is thrown outside the try                                                           | real `INFORMATION_SCHEMA` existence check                       |
| 4   | `2026_05_01_100910_add_level_type_to_class_levels`         | `down()` drops an index `up()` never created → 1091                                                                                                                           | remove the drop                                                 |
| 5   | same migration                                             | `unique_class_level_arm_stream`'s **leftmost prefix** is the only backing index for `fk_class_level_arms_class_level_id`, so it cannot be dropped while that FK exists → 1553 | give `class_level_id` its own index first, then drop the unique |
| 6   | same migration                                             | the fix for #5 introduced drift: on re-upgrade the helper index was the FK's _only_ index and could not be dropped early                                                      | drop it **after** the composite unique is recreated             |
| 7   | `2026_05_13_185946_create_activity_log_table`              | **no `down()` at all** — rollback silently no-ops, re-upgrade fails 1050                                                                                                      | add `dropIfExists`                                              |

**Result: all four paths green.** Fresh 90/90; rollback 66 migrations, halting exactly
at `2026_04_29_000001_update_foreign_keys_to_integer_ids`, which **deliberately throws**
_"cannot be safely reversed. Please restore from a database backup."_ — a designed
irreversibility floor, not a bug; re-upgrade 66/66, ending at 90/90 applied.

Two of these deserve emphasis. **#3** is the more dangerous kind of bug: a guard that
_looks_ defensive and catches nothing, because Laravel's Blueprint defers its commands
past the `try`. And **#6 was a bug in my own fix for #5** — caught by the re-upgrade
leg, the exact leg `migrate:fresh` cannot see. The audit paid for itself twice.

**Operational note for a first deploy:** rollback is not the realistic recovery path
here anyway — for a first deploy, recovery is restore-or-drop. The value of this audit
is that the `down()` chain is no longer silently broken for the _incremental_ deploys
that follow.

> **RBAC env lockdown (Ask 1 resolution, 2026-07-22):** set
> `AUTH_GATE_BEFORE_SUPERADMIN=true` **explicitly** in the production
> environment before the C2/C3 stack deploys. The config default is already
> true (verified in code; guarded by a test), so this is belt-and-suspenders —
> an implicit default leaves super_admin's access to 27 of 28 route groups
> hanging on a line nobody is looking at. The explicit value makes the intent
> visible; the flag itself is retired by ADR 0045 when that lands.
