# Brief — `feat/guardian-uniqueness-constraint`

**Base:** `staging`. Re-derive with `git rev-parse --short staging`. Do **not** branch
from `fix/guardian-create-duplicates` — this project never stacks branches.
**Branch:** `feat/guardian-uniqueness-constraint`.
**Shape:** one migration + one test file. Two files. One commit.

Small on purpose. Resist growing it.

---

## The finding

Nothing at the schema level stops two live `guardians` rows existing for the same
person in the same school. `guardians` carries non-unique indexes on `user_id` and
`school_id` and no unique key beyond `uuid` — a fact
`app/Services/GuardianService.php:745-751` already documents in the docblock of
`forUserInActiveSchool`, which exists to work around it with `orderBy('id')`.

A school reported a parent appearing three times. That has been fixed by hand on
production, and the creation-path defects that produced it are fixed on
`fix/guardian-create-duplicates`. **This branch is the layer that makes the class
unrepeatable.** The other two layers are conventions enforced by application code;
this one is enforced by the database, and it is the only one that survives a future
feature that writes a guardian without reading any of that code.

Per `finance-method`: a rule with no mechanism is a wish. This is the mechanism.

---

## What NOT to do

- **Do not add a data migration.** Re-derive the count first (query below) — it was
  **0** at the time of writing, so the index applies to existing data unchanged. If
  your re-derivation returns non-zero, **stop and report**; do not write a cleanup.
  That is a different change with a different risk profile and it needs its own
  decision.
- **Do not touch the 14 shared-phone groups.** Two spouses sharing a household
  landline are two people with two accounts. Collapsing them would be data loss, not
  cleanup. The constraint is on `(user_id, school_id)` and nothing else.
- **Do not use `UNIQUE(user_id, school_id, deleted_at)`.** MySQL treats NULLs as
  distinct in unique indexes, so it would permit unlimited live rows and enforce
  nothing — a green migration that guards nothing, which is worse than no migration.
- **Do not depend on `GuardianService::merge`.** It lives on a parked branch.
- **Do not modify `forUserInActiveSchool`'s behaviour.** Its docblock comment may be
  updated to record that the schema now guarantees at most one row; the `orderBy('id')`
  stays as defence in depth.

---

## Part 1 — Derive before you write

Run read-only and put the result in the report:

```sql
SELECT user_id, school_id, COUNT(*) AS n
FROM guardians
WHERE deleted_at IS NULL
GROUP BY user_id, school_id
HAVING n > 1;
```

Expected: empty set. Report the row count under the privacy rule — counts and
`school#<id>` only, never names or addresses. **If it is non-empty, stop and report.**

## Part 2 — The migration

A stored generated column emulates a partial unique index, which MySQL lacks:

```sql
ALTER TABLE guardians
  ADD COLUMN live_identity VARCHAR(64)
    GENERATED ALWAYS AS (
      IF(deleted_at IS NULL, CONCAT(user_id, ':', school_id), NULL)
    ) STORED,
  ADD UNIQUE KEY guardians_live_identity_unique (live_identity);
```

Soft-deleted rows evaluate to NULL and are exempt automatically — so a person can be
soft-deleted and re-created, and two soft-deleted rows never collide. Only live rows
are constrained.

1. Write it as a Laravel migration. `DB::statement` is acceptable for the generated
   column; check whether this project has a precedent for generated columns and follow
   it if so.
2. **`down()` drops the index and the column**, in that order.
3. Confirm `live_identity` does not appear in `Guardian`'s `$fillable`
   (`app/Models/Guardian.php:49-71`) and cannot be mass-assigned. A generated column
   rejects writes, so an accidental write is a runtime error rather than silent
   corruption — verify which, and say so.
4. Check `GuardianFactory` and any `replicate()` call sites (the parked merge branch
   uses `$template->replicate(['uuid'])`) still work against a table with a generated
   column.

## Part 3 — Prove it

MySQL only; SQLite does not work here.

`tests/Feature/Guardian/GuardianUniquenessTest.php`:

1. **The bite-proof.** Insert a live guardian, then attempt a second for the same
   `(user_id, school_id)`, and assert **the database** rejects it — assert on the
   integrity-constraint violation, not on an application guard. Use
   `withoutGlobalScopes()` and write directly enough that no service-layer check can
   be what actually refuses; a test that passes because `GuardianService` refused
   proves nothing about the index.
2. Soft-delete the first row, then create a second for the same pair → **allowed**.
3. Two soft-deleted rows for the same pair → **allowed** (this is what a plain
   `deleted_at` in the index would have broken).
4. Same `user_id`, different `school_id` → allowed. This is the multi-school parent
   and it must keep working.
5. Restoring a soft-deleted row while a live row exists for the same pair → rejected.
   State what the application does with that today; if it is unhandled, ticket it.

**The migration `down()` audit — read `docs/testing.md` § "`--step=N` is relative to
the branch" first.** Find your migration in `migrate:status`, roll back **to it**, and
assert `live_identity` and the index are gone. Do not trust `--step=1` or a bare
exit-0 — `--step=N` counts from the branch's latest migrations and has previously
rolled back a different stream's migration while the audit passed testing nothing.
Then re-up and assert the column and index return.

**Reversibility against real data:** plant rows, migrate, roll back, re-up, and confirm
the planted rows survive intact.

Gates:

```bash
files=$(git diff --name-only HEAD -- '*.php')
[ -n "$files" ] && ./vendor/bin/pint $files
php bin/ci-authz-lint.php
php bin/ci-boundary-lint.php
DB_DATABASE=portal_testing ./vendor/bin/pest --group=arch
composer analyse
DB_DATABASE=portal_testing ./vendor/bin/pest --log-junit junit.xml && php bin/ci-test-ratchet.php junit.xml
```

Run serially. Paste raw. `GrantsConvergenceLintTest` failures are known, investigated
and unresolved (`docs/handoff/tickets/grants-convergence-lint-nondeterminism.md`) —
report as red, do not chase, do not baseline, do not retry for green.

## The watched red

Required. Drop the unique index only (leave the column), run arm 1, and confirm it
fails — the second row inserts successfully. Restore. Paste both.

This matters more than usual: an index that is not actually enforcing is
indistinguishable from one that is, from the passing side.

---

## Stop and report

- The `HAVING n > 1` query returns anything.
- Generated columns collide with something in this schema you did not expect.
- Any existing test goes red. Do not weaken an assertion to pass.
- You conclude the finding is wrong. The code wins over this brief.

## Not in scope

The creation-path fix (`fix/guardian-create-duplicates`), the merge command (parked),
the admin merge UI, `Guardian::applySchoolScope`'s OR branch, and the 14 shared-phone
groups.

## Hand-off

Report to `docs/handoff/reports/feat-guardian-uniqueness-constraint.md`, spawn
`finance-reviewer` with only the report path and branch name, return its findings raw.
Commit on the branch. **Do not push.**

**Full-review tier** — it is a migration against production data with a rollback path.
Say so in your headline.
