# `SavedActivityFilter` carries `school_id` but not `BelongsToSchool`

**Raised:** 2026-09-01 · **From:** `fix/three-authorisation-holes` · **Severity:** ticket

## What

`app/Models/SavedActivityFilter.php` has a `school_id` column and a `school_id` value written on
every insert (`SavedActivityFilterController@store`), and does **not** use the
`App\Concerns\BelongsToSchool` trait. It therefore has no global `SchoolScope`, and every read of
the model returns rows from every school unless the caller narrows by hand.

The route binds it on a **sequential integer id** (`/saved-filters/{savedActivityFilter}`, no
`:uuid` segment as the rest of the API uses), so a cross-school row resolves through route-model
binding by guessing a number.

## Why this ticket exists rather than a fix in that branch

`fix/three-authorisation-holes` closed the live hole — `destroy()` now refuses a row from another
school with a 404, matching the explicit `currentSchoolId` narrowing `index()` and `store()` already
did. Adding the trait is a **different** change: a global scope alters every read of the model, and
it belongs in a change whose review is about that, not one riding along inside an authorization fix.

## Blast radius, measured

Three query sites today, all in `SavedActivityFilterController`, and **all three already narrow by
school explicitly**:

| Site | Narrowing |
| --- | --- |
| `index()` | `->where('school_id', $this->queries->currentSchoolId(...))` |
| `store()` | writes `school_id` from `currentSchoolId(...)` |
| `destroy()` | `abort_if` on `school_id !== currentSchoolId(...)`, 404 (added by that branch) |

So the trait is currently **belt-and-braces, not load-bearing** — nothing depends on it today, which
is exactly why it is safe to schedule and exactly why it will be forgotten. The value is for the
*next* reader of the model, who will not know to narrow by hand and will get no red if they don't.

## The fix

1. Add `BelongsToSchool` to the model and confirm the three sites above still behave (the explicit
   narrowing becomes redundant but is not wrong — decide in that change whether to keep it as
   defence-in-depth or remove it as duplication; **removing it makes the scope load-bearing**, which
   is a real trade and should be stated rather than defaulted).
2. Decide separately whether the route should bind on `uuid` like the rest of the API. A 404 already
   refuses the cross-school id, so this is enumeration hygiene rather than an open hole.
3. `destroy()`'s ownership refusal stays regardless: a scope answers "which school", never "whose
   filter".

## The general finding underneath

A model with a `school_id` column and no `BelongsToSchool` is invisible to every isolation gate this
repo has — `bin/ci-boundary-lint.php` does not enumerate school-owned models by column, and nothing
else compares the two sets. Worth deciding whether a lint should assert that **every table with a
`school_id` column has a model using the trait, or an explicit reviewed exemption**. That check would
have surfaced this the day the table was created, instead of an authorization census finding it by
walking the delete path.
