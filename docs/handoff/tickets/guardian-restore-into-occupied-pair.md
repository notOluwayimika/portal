# TICKET — restoring a soft-deleted guardian into an occupied pair will 409 with no explanation

**Status:** open, not implemented. Raised by `feat/guardian-uniqueness-constraint`, which created the
condition. Not fixed there because the branch is deliberately a migration plus its proof, and because
**the path this describes does not exist yet** — this is a trap laid for whoever writes it, not a
live defect.

## What the constraint does

`2026_08_19_100000_add_guardian_live_identity_uniqueness` adds a generated column
`guardians.live_identity` — `IF(deleted_at IS NULL, CONCAT(user_id, ':', school_id), NULL)` — and a
unique index over it. Soft-deleted rows evaluate to NULL and are exempt, so any number of them may
coexist for one pair.

Clearing `deleted_at` recomputes the column from NULL back to `user:school`. If a live row already
holds that pair, the UPDATE is refused with MySQL driver code **1062**. That refusal is correct: it is
the retroactive collision a creation-path guard cannot see, because nothing is being inserted. It is
asserted in `tests/Feature/Guardian/GuardianUniquenessTest.php`, arm *"refuses restoring a
soft-deleted guardian while a live one holds the pair"*.

## Why it is not a live defect today

There is no guardian restore path anywhere in the application. Re-derive:

```bash
git grep -rn "withTrashed\|onlyTrashed\|->restore(" -- app/ routes/
```

At the time of writing that returns two hits, both in `app/Models/StudentCurriculum.php`, neither
about guardians. `Guardian` does use `SoftDeletes`, so `$guardian->restore()` would issue exactly the
offending UPDATE — the door is unlocked, nobody has walked through it.

## What the fix looks like when someone does

`bootstrap/app.php:197` maps 1062 to `response()->conflict('Duplicate entry detected.')`. So the first
implementation of "undelete this parent" will surface a bare 409 with a message that names neither the
guardian, the school, nor the live row standing in the way — and the caller's reasonable next move
("try again") cannot succeed.

The restore action should look before it writes: query for a live `guardians` row with the same
`(user_id, school_id)` and, if one exists, refuse with a `ValidationException` that says the parent
already has a record in this school and offers the live row. The DB constraint stays as the backstop
for the race, exactly as `resolveOrCreateGuardianForUserInSchool` and the index now relate.

Do **not** fix it by making the restore silently merge or silently no-op. Two rows for one person is
the class of defect this whole effort exists to close; a restore that quietly picks a winner
reintroduces it above the database instead of below it.

## Related

- `docs/handoff/briefs/feat-guardian-uniqueness-constraint.md` — the brief, whose Part 3 arm 5 asks
  for exactly this statement of what the application does today.
- The guardian merge command (parked) and the creation-path fix (`fix/guardian-create-duplicates`) are
  the other two layers; neither touches the restore direction.
