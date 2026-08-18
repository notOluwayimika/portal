# Brief — `feat/guardian-merge-command`

**Base:** `staging` @ `e484a46` (identical to `main` at time of writing — re-derive with
`git rev-parse --short staging` before you branch).
**Branch:** `feat/guardian-merge-command`, cut from `staging`.
**Shape:** one service method + two console commands + one test file. Roughly
4 files. One commit.

This is **slice 1 of 3**. Slices 2 and 3 are not yours — see *Not in scope*.

---

## The finding

A school reported a parent appearing **three times** in the portal, two of the rows
with no email address. The report is accurate and the cause is confirmed in the
repository:

`GuardianService::createGuardianWithUser`
(`app/Services/GuardianService.php:225-287`) dedupes the **User** by email but
always calls `Guardian::create()` (line 274). It never looks for an existing
`guardians` row. Two consequences, both live:

- With no email, `$userEmail = null` (line 252) and
  `User::where('email', null)->first()` (line 257) never matches under MySQL, so
  **every** email-less submission mints a fresh `users` row *and* a fresh
  `guardians` row.
- With an email, the User is reused but a **second `guardians` row against the
  same `user_id` and `school_id`** is still created.

Nothing at the schema level forbids either. `guardians` carries non-unique indexes
on `user_id` and `school_id` and no unique key beyond `uuid` — a fact
`GuardianService.php:745-751` already documents in the docblock of
`forUserInActiveSchool`, which exists solely to work around it with `orderBy('id')`.

**Environment: this bites in production and is invisible locally.** No fixture or
seeder creates the duplicate state, and no test in the repository asserts against
it. It is produced only by an operator adding the same person more than once,
which is exactly what the school did.

**This slice does not fix the creation path.** It builds the remediation engine —
the thing that collapses duplicates that already exist — because a school is
blocked on it today, and because slice 3's data migration will call the same
method before it can add a uniqueness constraint. Slice 2 fixes creation.

---

## What NOT to do

- **Do not hard-delete anything, and do not touch `users` rows.**
  `guardians.user_id` is `NOT NULL` with `cascadeOnDelete`
  (`database/migrations/2026_05_13_132246_create_guardians_table.php:18`), so
  deleting a `users` row hard-deletes that person's guardian records **in every
  other school**. Absorbed guardians are **soft-deleted**; their users are left
  exactly as they are.
- **Do not fix `createGuardianWithUser` here.** It is slice 2 and it has its own
  review. A merge command that also changes the write path cannot be reviewed as
  either one.
- **Do not add the unique index.** Slice 3. It cannot be added before the data is
  clean, and cleaning the data is what you are building.
- **Do not use `$student->guardians()->syncWithoutDetaching()` or the existing
  `attachToStudent` to move pivots.** `attachToStudent` re-issues credentials on a
  `can_login` false→true transition (`GuardianService.php:385-387`) — a merge would
  silently email a parent a new password. Move pivot rows deliberately.
- **Do not rely on the default `Guardian` query scope.**
  `Guardian::applySchoolScope` (`app/Models/Guardian.php:88-94`) matches
  `school_id = active` **OR** `user_id has access to active`, so a multi-school
  parent's rows from *other* schools are visible under it. Every query in this
  slice uses `withoutGlobalScopes()` and pins `school_id` and `deleted_at`
  explicitly, the way `forUserInActiveSchool` (`:761-766`) does.
- **Do not reset or re-seed the local database.** It is a production copy and that
  is what makes it useful for confirming the duplicate shape.

---

## Part 1 — `GuardianService::merge()`

Add to `app/Services/GuardianService.php`:

```php
public function merge(Guardian $keeper, Collection $absorbed, bool $apply): array
```

Returns a plan/result array (same shape whether or not `$apply`) so the command can
print a dry run and an applied run identically. Wrap the whole write in
`DB::transaction`.

Numbered requirements:

1. **Refuse a cross-school merge.** If any absorbed row's `school_id` differs from
   the keeper's, throw before any write. The same-school triggers
   `guardian_student_same_school_bi`/`_bu`
   (`database/migrations/2026_07_16_000003_add_guardian_student_same_school_constraint.php:17-30`)
   would `SIGNAL SQLSTATE '45000'` mid-transaction otherwise, which is a 500 rather
   than a message.
2. **Refuse if the keeper is in `$absorbed`,** or if `$absorbed` is empty, or if any
   row is already soft-deleted.
3. **Move pivots.** For each absorbed guardian, for each `guardian_student` row:
   - **No keeper row for that student:** re-point the pivot to the keeper
     (`UPDATE guardian_student SET guardian_id = <keeper>`), preserving
     `relationship`, `is_primary`, `can_login`.
   - **Keeper already has a row for that student:** the pivot has
     `unique(guardian_id, student_id)`
     (`database/migrations/2026_05_13_140000_create_guardian_student_table.php:20`),
     so a blind update raises a duplicate-key error. Keep the keeper's row, **OR-merge**
     `is_primary` and `can_login` into it, keep the keeper's `relationship`, and delete
     the absorbed row.
4. **`can_login` may not survive onto an email-less keeper.** Before writing any
   pivot with `can_login = true`, run the existing invariant —
   `assertLoginRequiresDeliverableEmail` (`GuardianService.php:317-331`). It is
   currently `private`; widen it only as far as needed. A merge must not be able to
   mint the state `tests/Feature/Guardian/GuardianLoginInvariantTest.php` pins.
   If the keeper cannot carry it, **abort the merge with a message naming the
   problem** — do not silently downgrade the flag, and do not silently proceed.
5. **Re-assert single-primary per student.** `is_primary` is enforced in code only
   (`GuardianService.php:390-396`). After an OR-merge raises `is_primary` on a
   keeper row, clear `is_primary` on that student's *other* guardians' rows.
6. **Back-fill blanks only.** Copy an absorbed row's value onto the keeper only
   where the keeper's field is `null` or `''`. The keeper's own values always win.
   Iterate the keeper's `$fillable` (`app/Models/Guardian.php:49-71`) minus
   `school_id`, `user_id`, `uuid`, `status`. Record every field taken and which
   absorbed row it came from.
7. **Soft-delete the absorbed guardians.** `users` untouched. If an absorbed user is
   left backing no live guardian in any school, **report it in the result array**;
   do not act on it.
8. **Log to the activity trail.** One `activity('guardian')` entry performed on the
   keeper, event `merged`, properties naming absorbed guardian **ids**, moved pivot
   count, collision count, and back-filled field names. This is the trail
   `resources/js/pages/admin/guardians/audit.tsx` renders. Follow the shape of
   `logPivotEvent` (`GuardianService.php:769-780`).

---

## Part 2 — `guardians:merge`

`app/Console/Commands/MergeGuardians.php`.

```
php artisan guardians:merge --keep=<uuid> --absorb=<uuid> [--absorb=<uuid> …] [--apply]
```

1. **Dry run is the default.** Without `--apply` it prints the plan and writes
   nothing. With `--apply` it writes and prints the same table.
2. **Off-request, so context comes from `ActiveSchool::runFor()`** — resolve the
   school from the **keeper guardian's `school_id`**, never from `users.school_id`
   and never from an authenticated user (Constitution 13; ADR 0036/0042).
   `app/Console/Commands/AuditGuardianLoginInvariant.php` is the closest existing
   guardian command; follow its query style (`withoutGlobalScopes()`, ids in output).
3. **Print by id, never by name or email.** `guardian#<id>`, `student#<id>`,
   `school#<id>`. The command is run by engineers against a production copy.
4. Exit non-zero on refusal (cross-school, absent uuid, invariant abort).
5. Print the plan as a table: pivots to move, pivot collisions and how each resolves,
   fields back-filled, rows to soft-delete, users left orphaned.

---

## Part 3 — `guardians:find-duplicates`

`app/Console/Commands/FindDuplicateGuardians.php`.

```
php artisan guardians:find-duplicates [--school=<id>]
```

Two candidate groupings over **live** guardians only:

1. same `(user_id, school_id)` — the certain duplicates, and the exact set slice 3's
   migration must clear before it can add a unique index;
2. same normalised `phone` within a school — the likely duplicates, which is the
   email-less case from the report. Normalise with the same
   `App\Support\PhoneNormalizer` the write path uses
   (`GuardianService.php:230-238`) rather than a second definition. Compare
   `phone` and `whatsapp_number` the way
   `GuardianImportService::lookupExistingInDb` (`app/Services/GuardianImportService.php:237-283`)
   already does — read it and match its semantics; do not invent a third rule.

Output ids and counts only. Exit non-zero when any group in category (1) exists —
it doubles as slice 3's pre-flight.

---

## Prove it

Suite runs on **MySQL**; SQLite does not work here.

```bash
DB_DATABASE=portal_testing ./vendor/bin/pest --filter=GuardianMerge
DB_DATABASE=portal_testing ./vendor/bin/pest --log-junit junit.xml && php bin/ci-test-ratchet.php junit.xml
```

New test file `tests/Feature/Guardian/GuardianMergeTest.php`. Arms, each one a
distinct failure mode — not variations of one:

1. Absorbed guardian linked to a student the keeper is **not** linked to → pivot
   re-pointed; keeper has both students; absorbed soft-deleted.
2. Both linked to the **same** student (the collision) → exactly one pivot survives,
   `is_primary` and `can_login` OR-merged, no duplicate-key error.
3. Absorbed row is `is_primary` for a student that a **third** guardian is also
   primary for → after merge, exactly one primary row for that student.
4. Absorbed carries `can_login = true`, keeper's user has **no deliverable email** →
   merge aborts, **nothing is written** (assert the pivot and the soft-delete flag
   are unchanged, not just that an exception was thrown).
5. Keeper has `occupation = null`, absorbed has a value → back-filled. Keeper has a
   value, absorbed has a different one → keeper's survives.
6. Cross-school absorb → refused before any write.
7. `users` rows still exist after the merge, and a guardian row for the absorbed user
   **in another school** is untouched.
8. The `merged` activity entry exists on the keeper with the absorbed ids.

Gates, before you commit:

```bash
files=$(git diff --name-only HEAD -- '*.php')
[ -n "$files" ] && ./vendor/bin/pint $files
php bin/ci-authz-lint.php
php bin/ci-boundary-lint.php
DB_DATABASE=portal_testing ./vendor/bin/pest --group=arch
composer analyse
```

Pint through an explicit changed-file list **with the empty-list guard** — a bare
`pint` lints the whole project and has swept unrelated files into a commit three
times (CLAUDE.md).

Then `git diff --stat` against your own model of the change before you finish.

Paste **raw** output, not a summary of it.

---

## The watched red

Required, and it is a deliverable, not a step.

Arm 2 (the pivot collision) is the one that matters: it is the arm that would
silently pass if the merge never actually hit the collision path. **Comment out the
collision branch** so both pivots take the plain re-point, run arm 2, and confirm it
fails with a duplicate-key error naming `guardian_student`. Restore. Paste both
states.

Do the same for arm 4: remove the `assertLoginRequiresDeliverableEmail` call, watch
arm 4 go red, restore, paste both.

If either refuses to go red, that is more important than the change — report it and
stop.

---

## Stop and report

Halt rather than improvise if:

- the pivot move cannot be done without `attachToStudent` (it can — re-read the
  constraint before concluding otherwise);
- `assertLoginRequiresDeliverableEmail` cannot be reached without restructuring more
  than its visibility;
- `GuardianLoginInvariantTest` goes red;
- the ratchet reports a regression you did not cause;
- you conclude any part of the finding above is wrong. The code wins over this brief
  — say so before writing anything.

---

## Not in scope

- **The creation-path fix** (dedupe in `createGuardianWithUser`, the
  `student_links` validation, the duplicate-warning endpoint, the modal error
  handling). Slice 2, separate branch.
- **The unique index / generated column.** Slice 3.
- **The admin merge UI.** Deliberately deferred; you are building the service method
  it will call, nothing more.
- **`Guardian::applySchoolScope`'s OR branch.** Known, flagged, not this change.
  Work around it with `withoutGlobalScopes()`; do not fix it.
- No screen changes here, so **no drive**.

---

## Hand-off

Write the report to `docs/handoff/reports/feat-guardian-merge-command.md` using the
`finance-execute` report template, then spawn the `finance-reviewer` subagent with
**only** the report path and the branch name. Return its findings raw.

Commit on the branch. **Do not push.**

This is **full-review tier** — it touches an append-only audit trail, `school_id`
isolation, a documented invariant, and it is the engine slice 3's data migration
will run against production data. Say so in your headline.
