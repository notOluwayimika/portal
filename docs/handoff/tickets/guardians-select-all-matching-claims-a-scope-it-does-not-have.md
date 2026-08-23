# `Select all N matching` on the guardians index claims a scope the client cannot hold

**Status:** open · **Severity:** fix (silent wrong-scope action, no data loss)
**Found:** while building bulk reassignment on the students index, which deliberately does not
reproduce it.

## The defect

`resources/js/components/guardians/bulk-action-bar.tsx` renders

```
Select all {totalMatching} matching →
```

and sets a `selectAllMatching` flag. But the browser only ever holds the ids the server sent it for
the **current page**. Every bulk action behind that bar — message, export, enable/disable login,
status change — is driven from the client's id set, so "all 240 matching" acts on the 25 the client
happens to have.

The operator is told a scope, the control confirms it back to them ("All 240 matching selected"),
and a different, smaller thing happens. Nothing errors.

## Why the students index does not have this

The same footer pattern was copied for bulk reassignment and **`selectAllMatching` deliberately was
not**. Two things made that possible:

1. The toolbar **Export** was changed to compute the **current filter set server-side**, which is
   the question "select all matching" was invented to answer — asked of the side that can actually
   answer it.
2. The footer's **Export selected (N)** names its own scope in its label, so the two controls are
   orthogonal and neither is implied by where it sits.

Students also has no client-side id materialisation to go wrong, so it avoids the defect by
construction rather than by discipline.

## Suggested fix

Adopt the same shape rather than patching the flag:

- toolbar **Export** on guardians → server-side, current filter set (mirrors
  `App\Services\StudentIndexFilters` + `StudentsExport`'s two-scope constructor);
- footer → **exactly the ticked ids**, count in the label;
- delete `selectAllMatching`, `totalMatching` and the `onSelectAllMatching` prop.

The bulk **write** actions (enable/disable login, status) need the same treatment or an explicit
server-side "apply to filter set" endpoint — do not leave them reading a flag that overstates what
the client knows.

## Scope note

Not touched by `feat/reassignment-ui`: it is a different screen that feature never renders. Recorded
here so the convergence is deliberate when someone picks it up, rather than the two indexes drifting
into two different ideas of what a selection means.
