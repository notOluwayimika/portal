# Fee-item tie order differs between the bulk mapper and every other read path

> **⚠️ LOCAL BASELINE MOVED — 2026-08-22.** The developer machine went **MySQL 8.0.43 → 9.7.1**
> (Homebrew). Every measurement in this file was taken on **8.0.43** and has **not** been re-taken on
> 9.7.1, so read its numbers as "measured on 8.0.43" rather than "measured locally". Re-verification
> is tracked in
> [`mysql-9-local-baseline-reverification.md`](mysql-9-local-baseline-reverification.md).
> Production is unaffected: it remains **5.7.23**.


**Raised by:** cold review of `feat/u6-schedule-to-invoice-lines` (U6 commit 2), 2026-08-18.
**Severity:** ticket — real, recorded, not ship-blocking for the mapper.

## The fact

`FeeScheduleLineMapper::linesFor()` orders items by `sort_order` **then `id`**, deliberately: a bulk
run re-driven after a partial failure must not bill a differently-ordered invoice to the students it
reaches twice.

That tiebreak is **local to the mapper**. Four other sites order the same items by `sort_order` alone:

| Site | Line |
| --- | --- |
| `app/Finance/Services/FeeScheduleLookup.php` | `:27` — `->with(['items' => fn ($q) => $q->orderBy('sort_order')])`, the bursar's prefill read |
| `app/Finance/Http/Controllers/FeeScheduleController.php` | `:67` — the fee-schedules index |
| `app/Finance/Actions/EditFeeScheduleDraft.php` | `:90` — the reload after a draft edit |
| `app/Finance/Actions/CreateFeeSchedule.php` | `:85` — the reload after authoring a draft |

`finance_fee_items.sort_order` carries **no uniqueness constraint**, and `CreateFeeSchedule` defaults
it to the array index (`:70`), so ties are authorable — an operator who leaves `sort_order` unset on a
subset, or sets two items to the same value, has one.

## Why it matters

For a schedule with tied `sort_order`, the prefill the bursar sees can present the items in an order
the bulk run does not reproduce. Nothing is mispriced — the set and the amounts are identical either
way — but "the bill I previewed" and "the bill the batch raised" can list their lines differently, and
the first person to notice will be someone reconciling two documents by eye.

MySQL guarantees nothing about the order of equal-key rows. This is measured, not asserted: with
16,384 tied mandatory items at stock `sort_buffer_size`, dropping the `id` tiebreak produced **2
inversions** on MySQL 8.0.43. The threshold is roughly **8k rows** at stock settings and roughly **1k**
at `sort_buffer_size=32768`. Below it the engine's current plan happens to return insertion order,
which is why three rows cannot demonstrate the problem and why no test in this repo does.

## What would close it

Add `->orderBy('id')` after `->orderBy('sort_order')` at the four sites above, so every read path
expresses one total order. Cheap, but it is four files on the live prefill/index/draft paths and none
of them was in U6 commit 2's scope — hence a ticket rather than a drive-by.

Consider also whether the order belongs in one place at all: five sites repeating a two-key sort is the
same shape of duplication cold review called on the status filter in this very commit, which was fixed
by moving the rule onto `FeeScheduleStatus` and having both sites read it.

## Explicitly NOT the fix

Do not add a 16k-row regression test. It would pin one engine's sort-buffer behaviour at one
configuration, not the ordering contract, and it would be slow and flaky at the threshold. The
justification for the tiebreak is that MySQL promises nothing about equal-key order; the measurement
above is what demonstrates the promise is really absent.
