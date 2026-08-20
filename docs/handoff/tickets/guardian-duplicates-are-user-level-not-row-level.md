# TICKET — the duplicate-parent residual is at the USER level, and no constraint can see it

**Status:** open, not implemented. Raised by `feat/guardian-uniqueness-constraint` after a cold review
pointed out that the branch's own evidence had been read backwards.

## The mistake this ticket exists to stop being repeated

`2026_08_19_100000_add_guardian_live_identity_uniqueness` adds a unique index over
`(user_id, school_id)` for live guardian rows. It was verified against a query returning **0**
offending groups, and that zero was initially reported as "the premise holds — the constraint applies
cleanly".

It is the opposite. Zero `(user_id, school_id)` duplicates over 776 live rows **with zero
soft-deletes** means every live guardian already has its own account, so the duplication that
actually exists in the data cannot be of that shape. A `(user_id, school_id)` key constrains one
guardian per **account** per school. The reported incident — a parent appearing three times — is one
**person** holding several accounts. Different class, invisible to this index, and it will stay
invisible no matter how the index is tightened.

## The residual, re-derived

Against the production copy, live rows only. Ids and counts only, per the privacy rule.

```sql
SELECT COUNT(*) total,
       SUM(deleted_at IS NULL) live,
       COUNT(DISTINCT CASE WHEN deleted_at IS NULL THEN user_id END) distinct_users
FROM guardians;

SELECT COUNT(*) FROM (
  SELECT user_id FROM guardians WHERE deleted_at IS NULL
  GROUP BY user_id HAVING COUNT(*) > 1) t;

SELECT COUNT(*) FROM (
  SELECT school_id, phone FROM guardians WHERE deleted_at IS NULL AND phone <> ''
  GROUP BY school_id, phone HAVING COUNT(*) > 1) t;

SELECT COUNT(*) FROM (
  SELECT school_id, first_name, last_name, phone FROM guardians
  WHERE deleted_at IS NULL AND phone <> ''
  GROUP BY school_id, first_name, last_name, phone HAVING COUNT(*) > 1) t;
```

At the time of writing:

| Measure | Value |
|---|---|
| guardian rows / live / soft-deleted | 776 / 776 / 0 |
| distinct `user_id` over live rows | 776 |
| accounts holding >1 live guardian in any school | 0 |
| live rows sharing a phone within one school | **14 groups**, all in `school#1` |
| live rows sharing name *and* phone within one school | **1 group**, in `school#1` |

**Re-derive before acting on any of these.** They are a snapshot, and the point of this ticket is
that a stale number was what caused the misreading in the first place.

## What each number is and is not

- The **14 phone groups** are NOT 14 defects. `feat/contact-points`' own brief already records that
  two spouses sharing a household landline are two people with two accounts, and collapsing them
  would be data loss. This is a candidate set to be reviewed by a human, not a cleanup to run.
- The **1 name-and-phone group** is the strongest single candidate for a genuine duplicate person,
  and is the one worth a human looking at first. It is still a candidate — same name and same
  landline describes a father and son as readily as it describes one person twice.

## The three layers, and which one covers what

| Layer | Covers | Where |
|---|---|---|
| Interactive-form dedupe — match email, then normalised phone, before creating anyone | the reported incident: one person, several accounts, created via the forms | `fix/guardian-create-duplicates` |
| Merge command — consolidate accounts that already duplicate | the existing residual above | `feat/guardian-merge-command` (parked) |
| `guardians_live_identity_unique` | one account holding two live rows in one school | this branch |

Only the third is enforced by the database. The first two are application code, which is exactly why
the third exists — and exactly why it must not be described as covering their ground.

## What would close this

Not a constraint. There is no column that identifies a person, so there is nothing to make unique.
Closing it means a human reviewing the candidate groups above with the merge command, and a decision
recorded per group. Until then the honest statement is: **the (account, school) class is closed; the
person class is open, bounded, and enumerated here.**
