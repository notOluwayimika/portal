# Two `active` terms in one session has no constraint

**Raised by:** the fix on `fix/current-term-resolution-is-ordered`, 2026-08-30 — filed *because*
that commit deliberately did not fix it.
**Severity:** ticket — the resolver is now deterministic in this state, so nothing is currently
non-reproducible. The STATE is still wrong and nothing refuses it.
**Local server for every measurement below:** MySQL **8.0.43** (`select version()`, taken today
against `portal_testing`). Production is Percona **5.7.23**, which is why the shape of the fix is
not obvious — see "Why not a `CHECK`".

## The fact

`terms.status` is an enum of three values defaulting to `upcoming`
(`database/migrations/2026_05_06_082137_create_terms_table.php:22`). The table's only uniqueness is
`unique(['academic_session_id', 'order'])` (`:25`). **Nothing anywhere says at most one term in a
session may be `active`**, and two terms in one session being active simultaneously is therefore an
authorable state.

It is not hypothetical in the way schema gaps usually are, because of *when* it arises: term
activation is a human act at a date boundary — Term 1 of 2026/2027 goes active on **2026-09-05** —
and the act that activates the next term is separate from the act that completes the current one.
Doing the first without the second produces exactly this state, for as long as nobody notices.

## Nothing in the application can enforce it, either

There is no application write path to constrain. `TermController` omits `status` from its validated
input **by design** — `:21` ("`status` IS NOT WRITEABLE THROUGH THIS CONTROLLER, by omission") and
`:73` ("it is a lifecycle transition (active → completed), not a field"). Grepping `app/` for a
write of `terms.status` returns nothing: the only writers are seeders
(`database/seeders/DriveCastSeeder.php:392`, `:451` — each in a *different* session, so the seeded
fixtures are not themselves an instance of this defect).

So activation today happens **out of band** — direct SQL, tinker, or a DBA. A guard in a
FormRequest, an action, or a model observer would bind no path that anybody actually uses. That is
the same argument `2026_07_28_120000_add_term_date_order_check.php` already makes for this very
table, in its own words: *"application validation only binds the one path that runs it."* **The
enforcement has to be in the database.**

## Two resolvers read "the active term", and they tie-break differently

This is the sharper reason the state matters. There are two independent resolvers, and in the
two-active state they can return **different terms for the same school in the same request**:

| Resolver | Tie-break | Line |
| --- | --- | --- |
| `App\Support\CurrentTerm::forSchoolModel()` | `orderByDesc('order')` — added by this commit | `app/Support/CurrentTerm.php:143` |
| `App\Concerns\ResolvesTermFilter` | **none** on the current-session branch; `latest('id')` on the fallback | `app/Concerns/ResolvesTermFilter.php:34-40` |

`order` and `id` are not the same ordering — `terms` carries no constraint tying insertion order to
`order`, and `CurrentTermTest` exists precisely because they disagree in the live data. So the two
resolvers agreeing is a coincidence of row layout, not a property.

`CurrentTerm` is the one that reaches money: it is the single resolver behind
`App\Finance\Contracts\BillableEnrollment::$termId` and
`App\Finance\Services\FeeScheduleLookup::activeFor()` (`app/Finance/Services/FeeScheduleLookup.php:31`),
so it decides which term a bulk run bills and which schedule prices it.

**Aligning the two resolvers is NOT the fix and should not be attempted as one.** Making them agree
would make a state that should not exist merely *consistent*, which removes the last chance anyone
has of noticing it. Fix the state; the tie-breaks then never fire.

## What the commit that raised this did, and did not, do

Did: added `->orderByDesc('order')` to `CurrentTerm`'s first step, so that step no longer lets MySQL
return whichever of the two active rows it likes. That is **determinism, not correctness** — a wrong
answer stays wrong, it just stays the *same* wrong answer and can be explained. The class docblock
says so in those terms.

Did not: prevent the state. Deliberately — a constraint is a migration against a table already in
production with real term rows, it needs the pre-flight below, and it does not belong bolted to a
one-line ordering fix.

## Why not a `CHECK` — and why this is not a settled shape

Production is Percona **5.7.23**; `CHECK` is *parsed and ignored* before MySQL 8.0.16. The house
pattern for a constraint that must hold on production is therefore a **trigger** —
`2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php` is the reference,
with `2026_08_20_140000_promoted_requires_link_as_trigger.php` and
`2026_08_20_130000_class_levels_next_level_not_self.php` following it.

Note the local precedent on this exact table cuts the other way and is already a known problem:
`terms_end_after_start_check` is a `CHECK`, and `term-date-order-check-absent-from-schema.md` tracks
it being in the migration ledger and not in the schema. **Do not treat that migration as the pattern
to copy here.**

But "at most one active per session" is a *partial* uniqueness constraint, and there is a second
candidate a trigger-by-reflex would miss:

> a nullable generated column — `active_session_id` = `IF(status = 'active', academic_session_id,
> NULL)` — carrying a `UNIQUE` index. InnoDB treats NULLs in a unique index as distinct, so
> non-active rows do not collide with each other while at most one active row per session can exist.
> Generated columns are 5.7.6+ and indexes on them 5.7.8+, so this is *nominally* available on
> production, and it is declarative: no trigger body, no dump-safety question, no `SIGNAL` message
> to escape.

**That is a candidate, not a measurement.** It has not been tried on either server, its interaction
with the existing `unique(['academic_session_id','order'])` has not been checked, and whether Percona
5.7.23-23 specifically honours it is unverified. Whoever takes this should measure both shapes before
choosing, not inherit this paragraph as a decision.

## Before writing the migration

1. **Count the violations on production and on the dev copy first.** `SELECT academic_session_id,
   COUNT(*) FROM terms WHERE status = 'active' GROUP BY academic_session_id HAVING COUNT(*) > 1`. A
   constraint added over existing violations fails at `ALTER`/`CREATE TRIGGER` time or, worse for a
   trigger, applies only to new writes and leaves the bad rows sitting. Not counted here: this ticket
   was raised from the code, and neither database was queried for it.
2. **Decide what happens to rows that already violate it**, if any — that is a data decision with an
   owner, not a migration detail.
3. **Cover UPDATE as well as INSERT.** The realistic way in is an `UPDATE terms SET status='active'`
   on the next term, not an insert.
4. **Prove the `down()` against the four-path audit**, re-deriving the rollback depth per run rather
   than trusting `--step=1` (`docs/testing.md` § "`--step=N` is relative to the branch").
5. **Give it an arm that reds without the constraint.** The arm in `CurrentTermTest` proves the
   resolver is deterministic in this state; it deliberately does not prove the state is refused, and
   it must keep passing after the constraint lands only if the constraint is not enforced in the test
   database — check which, and if the arm becomes unconstructible, say so in the commit rather than
   quietly deleting it.

## Related

- `app/Support/CurrentTerm.php` — the docblock section "STEP 2 ORDERS BY `order` DESCENDING, AND
  THAT IS DETERMINISM — NOT CORRECTNESS" points back here.
- [`term-date-order-check-absent-from-schema.md`](term-date-order-check-absent-from-schema.md) — the
  other `terms` constraint, and the reason a `CHECK` on this table is not trustworthy.
