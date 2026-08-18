# `FeeScheduleLookup::activeFor()` ends in `->first()` with no `orderBy`

**Raised** 2026-08-19, on `feat/u6-bulk-invoice-run` (U6 commit 3), by cold review.
**Severity** ticket. Determinate today — by a unique index, not by the query.

## The claim that needed checking

U6 commit 3 says the bulk run "pins ONE fee schedule for the whole run", and it does: it resolves the
schedule once and reuses one mapped set of lines. But *which* schedule it pins comes from

```php
FeeSchedule::query()
    ->where('term_id', $termId)
    ->where('class_level_id', $classLevelId)
    ->whereIn('status', FeeScheduleStatus::billableValues())
    ->with(['items' => fn ($q) => $q->orderBy('sort_order')])
    ->first();
```

`->first()` with **no `orderBy`**. MySQL guarantees nothing about which row a `LIMIT 1` returns when
more than one matches.

## Why it is determinate anyway

`finance_fee_schedules_active_unique` — `UNIQUE(school_id, active_term_key, active_class_level_key)`
over generated columns that are non-NULL only while `status = 'active'`
(`2026_07_26_130000_create_finance_fee_schedules.php:52-65`) — means **at most one** active schedule
can exist per `(school, term, class level)`. `FeeScheduleStatus::billable()` is a one-member set
(`active`), so the predicate above can match at most one row and `->first()` has no choice to make.

## The coupling that is not written down anywhere

**Widening `FeeScheduleStatus::billable()` silently makes this query non-deterministic.** The enum's
own docblock treats the billable set as the thing that may move — that is why it exists as a shared
symbol rather than a repeated literal — and the moment it contains two states, a term/class level can
carry two matching schedules and this `->first()` starts choosing arbitrarily between two price
lists. The bulk run would then pin a different one on different days, from the same data.

The index protects the current set. Nothing protects the next one, and nothing announces the
dependency.

## Options

- **Cheapest, and it is enough:** add `->orderByDesc('id')` (or an explicit precedence order) so the
  query is deterministic on its own terms, and say in the docblock that the index makes it moot today
  and the ordering is what survives a widening.
- **Strongest:** assert the singularity — `->sole()` — so a second billable schedule is a loud error
  rather than a coin flip. Changes the failure mode from silent to fatal on a path that runs during
  billing, so it wants deliberate agreement.
- **Structural:** a test that fails if `FeeScheduleStatus::billable()` gains a member without this
  query gaining an order. Turns the undocumented coupling into a gate, which is the only form of it
  that is a rule rather than a wish.

Not done in U6 commit 3: the run is correct today, and changing a shared read path used by the
bursar's prefill belongs in a change scoped to that read.
