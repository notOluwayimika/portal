# `ForcingMigrationsDoNotStripLaterGrantsTest` redefines two constants — harmless now, not later

**Status:** open, not implemented. Raised by the cold review of `feat/server-side-money-formatter`,
2026-08-23.

## What is measured

Every full-suite run reports two PHPUnit warnings, and has for as long as the artefacts go back:

```
warnings: 2
  tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php:43
      Constant FORCING_MIGRATIONS already defined
  tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php:48
      Constant CONVERGES_MARKER already defined
```

Both are top-level `const` declarations in a Pest test file (`:43`, `:48`). Running that file alone
produces no warning — `./vendor/bin/pest tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php`
is clean — so the redefinition needs the file to be loaded more than once in one process, which is
what a full-suite run does.

## Why it is worth writing down while it is still harmless

It is harmless **today** because the values are identical each time: redefining a constant to the
same value is a warning and nothing else, and the suite is serial, so there is exactly one process
and the warning count is a stable 2.

It stops being harmless on the day the suite goes parallel — which is an open intention, recorded in
`docs/handoff/tickets/the-suite-runs-serial-and-nothing-makes-it-parallel.md`. Then the warning count
becomes a function of how work was distributed across processes, and a number that moves with the
scheduler is a number nobody can read. Worse, a constant is *process*-global: two workers that both
load this file share nothing, but a future edit that makes either value depend on anything
worker-specific would produce a silent first-writer-wins, not a warning.

The general shape: **a warning that is stable noise is a warning people learn to skip**, and the
suite's own artefacts are the thing a red is meant to be diagnosed from. Two permanent entries in
`warning_details` are two entries that are not evidence.

## Shape of a fix (not chosen)

Move both values off the top level — a `private const` on a class, a plain `function` returning the
array, or `defined()` guards. Any of the three is a small edit; picking one is the whole decision,
and it belongs with whoever is doing the parallel-suite work rather than bolted onto a money commit.
