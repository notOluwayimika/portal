# The schedule refusal is anonymous while the response beside it names the schedule

**Found:** cold review of `fix/refusals-name-the-bill-and-the-person`, 2026-09-05. Recorded rather
than fixed — see "Why this is not fixed here".

## The two halves, on one response body

`fix/refusals-name-the-bill-and-the-person` made a ruling and stated it generally:

> **A refusal may name an object in the reader's vocabulary only when the object belongs to the
> reader's School.** Where it does not, name nothing.

It applied that to `FeeScheduleLineMapper`'s first two guards, which fire precisely when the
schedule belongs to a School the reader is outside. Both now name the schedule by nothing —
`FeeScheduleLineMapper::linesFor() (app/Finance/Services/FeeScheduleLineMapper.php:108)` and line
132 — because the schedule's `label` is that other School's authored text.

The **same response body** carries it anyway. `BulkInvoiceRunController::preview()
(app/Finance/Http/Controllers/BulkInvoiceRunController.php:235)` builds:

```php
'schedule' => $schedule instanceof FeeSchedule ? [
    'uuid'   => $schedule->uuid,
    'label'  => $schedule->label,
    'status' => $schedule->status->value,
    'mandatory_item_count' => $mandatoryItems,
] : null,
'refusal' => $refusal,
```

from the same `$schedule`, on the refusal path, two keys away from the sentence that was
deliberately anonymised. The prose says "That fee schedule"; the JSON says which one.

## Why it does not bite today

Both consumers of `FeeScheduleLineMapper::linesFor()` — and there are exactly two, measured —
obtain their schedule from `FeeScheduleLookup::activeFor()`:

| caller | line | resolves via |
| --- | --- | --- |
| `BulkInvoiceRunController::preview()` | `app/Finance/Http/Controllers/BulkInvoiceRunController.php:170` | `activeFor()` |
| `ProcessBulkInvoiceRun::process()` | `app/Finance/Jobs/ProcessBulkInvoiceRun.php:264` | `activeFor()` |

So a foreign schedule cannot be the object handed to `linesFor()`, and guard 1 is unreachable
through either path.

**But the mechanism is weaker than "it is scoped", and that is the part worth recording.**
`FeeScheduleLookup::activeFor()` carries **no explicit `school_id` predicate** — it filters on
`term_id`, `class_level_id` and status only. The isolation comes entirely from `FeeSchedule`'s
`BelongsToSchool` global scope (`app/Finance/Models/FeeSchedule.php:30`). And `FeeSchedule` is
**not** in `rbac.fail_closed_models` — the finance CATALOG models are deliberately excluded
(`config/rbac.php:132`), so with no ambient School context that scope is **fail-open**, exactly as
`FeeScheduleLineMapper`'s own docblock already says of `FeeItem`.

Neither caller reaches that state today: the controller runs behind the `tenant` middleware, and
the job carries `SchoolAware` (`app/Finance/Jobs/ProcessBulkInvoiceRun.php:156`). So the guard is
unreachable — via an ambient-context invariant, not via a predicate on the query.

## Why this is not fixed here

Widening `fix/refusals-name-the-bill-and-the-person` into the bulk-run response **shape** is out of
scope: that payload has its own consumers (`resources/js/` reads `schedule.uuid` as row identity),
and changing what a screen receives on a path nobody can reach is a change with a blast radius and
no defect behind it.

The finding is not "this leaks". It is that **the ruling was stated generally and implemented only
on the string** — which is the same class of gap the branch's own gate exists to close one level
down, and the kind of thing that is invisible six months later when somebody makes guard 1
reachable.

## Two options. Choose neither here.

1. **Scope the ruling to message text.** Amend the ruling in
   `docs/handoff/reports/fix-refusals-name-the-bill-and-the-person.md` to say it governs the prose
   an operator reads, and that structured identity fields are a separate decision — the same split
   the branch already made for the activity log and for the 207's per-item `uuid` key. Cheapest,
   and arguably already the true intent.
2. **Null the `schedule` block when the refusal came from guards 1 or 2.** Honours the ruling as
   written, at the cost of teaching `BulkInvoiceRunController` which of the mapper's five refusals
   it is looking at — which today it cannot tell apart, because it receives a string.

Option 2's cost is the interesting one: it would need the mapper to distinguish its refusals
structurally rather than by sentence, which is a larger change than the disclosure it closes.

## Related

- `docs/handoff/reports/fix-refusals-name-the-bill-and-the-person.md` § "Deviations", item 2
- `docs/handoff/tickets/the-fold-refusal-names-ids-where-the-gate-names-the-class.md` — the
  precedent this whole line of work descends from
