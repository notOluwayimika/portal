# Prod-clone dress rehearsal — validate the Phase-1 deploy before it's real

**Purpose.** Load a **clone of production** into a staging/test environment, run the
whole Phase-1 migration + the staging code against it, and prove nothing breaks —
*before* the real deploy. This is the only place several risks are catchable, because
prod is currently on the **old schema + old (`role:`) code**, and dev holds a single
School so it can't reproduce multi-School data hazards at all.

**Provenance.** The SQL and commands marked ✅ are copied verbatim from the repo's
own runbooks (`prod-divergence-and-cascade-queries.sql`,
`slice-i-preflight-and-remediation.md`, `phase1-deploy.md`). The ones marked ⚠️
**VERIFY** are constructed here from inferred column names — confirm them against the
actual schema before trusting a result. I only have `docs/`, not `app/` or
`database/`, so anything about specific migration files must be enumerated on the
clone itself (Step 3).

> **Golden rule from the deploy runbook:** on a single-School dev DB, a **zero means
> nothing**. On the prod clone, these queries run against real multi-School data for
> the first time — treat a non-zero on the drift query as the *expected* outcome, and
> a zero as pleasant news, not proof the query works.

---

## Step 0 — build the clone

1. Take a **read-consistent dump of production** (mysqldump `--single-transaction
   --triggers --routines`, or a physical snapshot). Triggers matter — the audit-log
   and Finance guards are triggers; a dump without `--triggers` silently drops them.
2. Restore into a **throwaway database whose name contains `test`** — `Tests\TestCase`
   and `bin/quality-clean-db` refuse to run against anything else, which is your
   safety net against pointing at a live DB.
3. Point a staging checkout (the merged RBAC stack) at that DB. Do **not** run
   `migrate` yet — the pre-migration checks run against the *old* schema first.

⚠️ The clone reflects prod **as it is now**: old schema, `role:` middleware, **no
`finance_*` tables**, no `student_curricula.school_id`. That's correct — it's the
real pre-migration state the migration must survive.

---

## Step 1 — PRE-migration checks (all must pass before `migrate`)

### 1a — §C1b partition proof, then §C1 enrollment-School drift ✅ THE BLOCKING ONE

Run **C1b first, in the same session**, to prove the join isn't dropping rows; then
C1. If C1 returns rows, the slice-(i) composite FK **aborts the migration mid-run**.

```sql
-- C1b — partition proof: agree + disagree MUST equal joined_total AND episodes_total.
SELECT
  (SELECT COUNT(*) FROM student_curricula sc
     JOIN students s ON s.id = sc.student_id
     JOIN curricula c ON c.id = sc.curriculum_id
    WHERE s.school_id = c.school_id)                                AS agree,
  (SELECT COUNT(*) FROM student_curricula sc
     JOIN students s ON s.id = sc.student_id
     JOIN curricula c ON c.id = sc.curriculum_id
    WHERE s.school_id <> c.school_id
       OR s.school_id IS NULL OR c.school_id IS NULL)               AS disagree,
  (SELECT COUNT(*) FROM student_curricula sc
     JOIN students s ON s.id = sc.student_id
     JOIN curricula c ON c.id = sc.curriculum_id)                   AS joined_total,
  (SELECT COUNT(*) FROM student_curricula)                          AS episodes_total;
```

```sql
-- C1 — episodes whose student's School disagrees with their curriculum's School.
-- Each row WILL fail the curriculum composite FK. Zero = migration can run.
SELECT sc.id AS episode_id, sc.uuid AS episode_uuid, sc.status, sc.ended_at,
       sc.student_id, s.school_id AS student_school_id, s.admission_number,
       sc.curriculum_id, c.school_id AS curriculum_school_id,
       (SELECT COUNT(*) FROM student_subjects ss        WHERE ss.student_curriculum_id = sc.id) AS subject_rows,
       (SELECT COUNT(*) FROM behavioral_assessments ba  WHERE ba.student_curriculum_id = sc.id) AS behavioural_rows,
       (SELECT COUNT(*) FROM psychomotor_skills ps      WHERE ps.student_curriculum_id = sc.id) AS psychomotor_rows
FROM student_curricula sc
JOIN students  s ON s.id = sc.student_id
JOIN curricula c ON c.id = sc.curriculum_id
WHERE s.school_id <> c.school_id OR s.school_id IS NULL OR c.school_id IS NULL
ORDER BY sc.id;
```

- **Pass:** C1 = 0 rows **and** C1b partition holds (agree + disagree = both totals).
- **Fail:** STOP. Classify each offender (childless/uninvoiced → delete or repoint;
  graded → escalate, do not SQL-fix; invoiced → own slice) per
  `slice-i-preflight-and-remediation.md` §4, remediate to zero, re-run C1. **Ending
  the episode does NOT fix it** — the FK constrains `curriculum_id` regardless of status.
- The clone is exactly where you *want* to hit this: fix the remediation plan here, so
  deploy day runs a query you already know returns zero.

### 1b — `students.school_id` null-free ✅

```sql
SELECT COUNT(*) FROM students WHERE school_id IS NULL;
```

- **Pass:** `0`. **Fail:** STOP — assign the correct School per row; there is no safe
  default (Constitution 13 forbids defaulting from `users.school_id`). The slice-(i)
  backfill copies `school_id` *from* the student, so a NULL here breaks it.

### 1c — production DB default collation ✅ (the silent-outage one)

A trigger's `DECLARE` variable inherits the **database default** collation. If prod
was created with the MySQL-8 server default (`utf8mb4_0900_ai_ci`), every Finance/
audit trigger that compares a variable to a column raises `1267 Illegal mix of
collations` on **every write** — a silent, total outage of the guard. Check it on the
clone (it carries prod's collation):

```sql
SELECT @@collation_database;

SELECT DISTINCT COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME LIKE 'finance_%'          -- (empty until after migrate; re-run in Step 4)
  AND COLLATION_NAME IS NOT NULL;
```

- **Pass:** both report `utf8mb4_unicode_ci`.
- **Fail:** `ALTER DATABASE <db> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`
  **and recreate the triggers** (a trigger's variable collation is frozen at
  creation, so the ALTER alone doesn't fix already-created triggers). Do this on prod
  before deploy, not just the clone.

### 1d — S7 divergence baseline ✅ (informational now, not a migration blocker)

S7 (dropping `users.school_id` + `school_user`) is a *late* post-deploy step, but
capture the real numbers now while you have a prod clone — dev's 0/0 is meaningless.
Run A1/A2/A3 from `prod-divergence-and-cascade-queries.sql` (the `school_user`,
`users.school_id`, and `guardians` unmirrored-access queries). Non-zero anywhere =
a future backfill decision before S7, **not** a deploy blocker today. Record and move on.

---

## Step 2 — run the migrations

Enumerate what will run (I can't list filenames from here — the clone can):

```bash
php artisan migrate:status          # what's pending against the cloned (old) schema
```

Then, **only after Step 1 is all-pass**:

```bash
php artisan migrate --force
```

- **Pass:** exit 0.
- **Fail:** STOP. **MySQL DDL is not transactional** — a mid-run failure leaves a
  half-applied schema. Do **not** loop `migrate`. On the clone this is cheap (drop and
  re-clone), but capture the exact error — it's the one you'd have hit in prod. The
  slice-(i) migration (`2026_07_19_130000_add_school_id_to_student_curricula`) is the
  most likely abort point, and Step 1a is what prevents it.

### Reversibility — separate, via the throwaway-DB gate

Real-data forward migrate is what the clone proves. Reversibility (the `down()`
chain) is proven separately by `bin/quality-clean-db`, which does
fresh → data → rollback → **re-upgrade** (the four-path audit that already found 7
`down()` bugs — see `phase1-deploy.md` appendix). Run it too:

```bash
bin/quality-clean-db
```

`migrate:fresh` never calls `down()`, so it is structurally blind to reversibility
bugs — this gate is the only thing that isn't.

---

## Step 3 — POST-migrate checks

```bash
php artisan audit:verify-immutability   # activity_log triggers survived the migrate/restore
```
- **Pass:** exit 0. **Fail:** triggers missing (a dump without `--triggers` will cause
  this) — re-apply before trusting the audit log.

```bash
php artisan rbac:sync                    # seeds roles/permissions; self-heals super_admin
```
- Watch for: a `NullTeamRoleAssignmentException` (a role assigned with no team) — that's
  the C1 invariant catching bad data, fix the seed context. Confirm super_admin heals
  to its canonical platform set.

Re-run the **collation column query** (Step 1c) now that `finance_*` tables exist —
confirm every `finance_%` column is `utf8mb4_unicode_ci`.

Optional — the 0045-C prod-parity check (informational; only relevant when you get to
the de-bypass): super_admin(web)'s grant set should equal `RbacSeeder::SUPER_ADMIN_PLATFORM`
**member-by-name** and contain `rbac.impersonate`. A count check is insufficient.

---

## Step 4 — app-level validation (boot the staging code against the migrated clone)

This is where you prove the two things that actually change behavior at deploy.

### 4a — permission-swap access parity vs REAL users ⚠️ VERIFY (method, not one query)

`RouteAccessParityTest` already proves the `role:`→`permission:` swap is
access-equivalent on *seeded* data. The clone proves it on *real* data — users with
odd role combos, drifted grants. Inventory who to test as:

```sql
-- ⚠️ VERIFY column/table names against the schema.
-- Per role, how many real users hold it (pick a sample of each to test as).
SELECT r.name AS role, COUNT(*) AS users
FROM model_has_roles mhr
JOIN roles r ON r.id = mhr.role_id
WHERE mhr.model_type = 'App\\Models\\User'
GROUP BY r.name
ORDER BY users DESC;
```

Then, logged in as a sample real user of each role, hit representative routes from
each of the 28 swapped groups and confirm the **same** allow/deny they'd have gotten
under `role:` gating. Any divergence = a real user whose access changes — investigate
before deploy. (The 299-route oracle is the source of truth for expected access.)

### 4b — 2FA enrolment state ⚠️ VERIFY (the one deliberate deploy-time change)

C7's `rbac.two_factor_enforced` **defaults ON in prod**, so at deploy every
*unenrolled* admin/super_admin is redirected to enrol. Count how many real users that
is:

```sql
-- ⚠️ VERIFY the 2FA column name (two_factor_confirmed_at) against the users table.
SELECT r.name AS role, COUNT(*) AS unenrolled_users
FROM model_has_roles mhr
JOIN roles r ON r.id = mhr.role_id
JOIN users u ON u.id = mhr.model_id
WHERE mhr.model_type = 'App\\Models\\User'
  AND r.name IN ('admin', 'super_admin')
  AND u.two_factor_confirmed_at IS NULL
  AND u.deleted_at IS NULL
GROUP BY r.name;
```

- **Non-zero → a decision, not a bug.** Either pre-enrol those admins before deploy,
  **or** ship the 2FA flag OFF at deploy and flip it as its own managed post-deploy
  step once they're enrolled. The second is more consistent with the flag-gated
  rollout and keeps the deploy behavior-neutral except the proven-safe permission swap
  — but it means changing C7's prod default (currently on, guard-test-pinned), a
  deliberate call.

### 4c — confirm everything else is inert at deploy

Prove the "deploy ships everything OFF" claim on the clone:

- **Observe-mode:** with `AUTHZ_ENFORCE` off (default), a request that *would* be
  denied by a restored check still returns 200 and writes a row to
  `authz_observations` — records, never blocks.
- **Fail-closed:** `RBAC_FAIL_CLOSED_MODELS` empty → nothing throws.
- **super_admin bypass:** `AUTH_GATE_BEFORE_SUPERADMIN=true` (set it explicitly) →
  super_admin reaches everything, unchanged.

If those three hold, the deploy changes nothing behaviorally except 4a (proven-equal)
and 4b (your decision).

### 4d — build the production caches ✅ THE PROD-ONLY ONE

Run the deploy's own cache step against the clone, because **nothing else in the
floor does**:

```bash
npm ci && npm run build       # regenerates wayfinder (gitignored, so always stale on a fresh checkout)
php artisan optimize          # config + events + ROUTES + views
php artisan optimize:clear    # leave the clone uncached afterwards
```

- **Pass:** all four caches build, exit 0.
- **Fail:** fix before deploy — this step runs in production with the app in
  maintenance mode, and a failure there is a stalled deploy, not a red test.

**Why this is its own check.** `route:cache` is the only thing that rejects two
routes sharing a name. `route:list`, the whole Pest suite, and `bin/quality` all
resolve routes *uncached* and are structurally blind to it — the duplicate simply
wins-last and every test still passes. Bit once, 2026-07-24: three duplicate-name
groups (`setup.studentCurricula.index`, `admin.dashboard` ×3, and Fortify's
`user-password.update` colliding with `Settings\SecurityController`) sat green
through the entire rehearsal and would have thrown at `php artisan optimize` on
prod. The Fortify collision was also a live security hole — an unthrottled
`PUT /user/password` open to every authenticated role, which wayfinder had bound to
while `route()` resolved to the throttled one.

Same class as the stale Vite manifest: **generated artefacts are gitignored, so a
clone/CI checkout has none of them, and anything measured against a stale local copy
is measuring the wrong tree.** Always regenerate before believing a green.

---

## Pass / fail summary

| # | Check | Pass criterion | On fail |
|---|---|---|---|
| 1a | §C1 drift (+ C1b proof) | C1 = 0, partition holds | STOP — remediate offenders to zero |
| 1b | `students.school_id` null | `0` | STOP — assign School per row |
| 1c | DB default collation | `utf8mb4_unicode_ci` | ALTER + recreate triggers (on prod too) |
| 1d | S7 divergence A1–A3 | (record only) | note for future backfill; not a blocker |
| 2 | `migrate --force` | exit 0 | STOP — half-applied schema, re-clone |
| 2 | `bin/quality-clean-db` | four paths green | fix the `down()` bug it names |
| 3 | `audit:verify-immutability` | exit 0 | triggers missing — re-apply |
| 3 | `rbac:sync` | clean, super_admin healed | fix null-team seed context |
| 4a | permission-swap parity | real users unchanged | investigate the diverging user |
| 4b | 2FA unenrolled admins | (record) | pre-enrol OR ship flag off + flip later |
| 4c | flags inert | observe records-not-blocks; fail-closed empty; bypass on | investigate before deploy |
| 4d | `npm run build` + `php artisan optimize` | all four caches build | STOP — `route:cache` is the only duplicate-name check; nothing else sees it |

---

## What this rehearsal does NOT cover

- **Scheduler execution** (`schedule:run` actually invoked) — an OS/platform fact, not
  visible on a DB clone. Verify on prod before observe-mode takes traffic
  (`phase1-deploy.md` Step 5).
- **The stamped `--ff-only` promotion discipline** — the deploy's real safety rests on
  it, and a clone can't prove it happened.
- **Re-derive the specifics.** The migration filenames, the exact 2FA/role column
  names, and the `RbacSeeder::SUPER_ADMIN_PLATFORM` set must be confirmed against the
  actual tree — this doc is a map assembled from `docs/`, not a substitute for the code.
```
