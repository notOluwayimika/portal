# A `.gitkeep` makes a documentation push pay the full gate

**Status:** open · **Filed:** 2026-08-21, from the targeted review of `feat/docs-only-gate`
**Location:** [bin/is-docs-only-push](../../../bin/is-docs-only-push) — the `DOC_EXTENSIONS` test

## The papercut

Every new directory under `docs/` that would otherwise be empty carries a `.gitkeep`. Its
basename is `.gitkeep`, so its extension is `gitkeep`, which is not on the allowlist — and the
push that CREATES the directory therefore runs the full fifteen steps, roughly ten and a half
minutes, for a zero-byte placeholder alongside whatever prose it was shipping.

This is real, not hypothetical: `docs/handoff/reports/.gitkeep` is tracked in this repository.

```
$ bin/is-docs-only-push <base> <head>
docs/handoff/newthing/.gitkeep
docs/handoff/newthing/report.md
is-docs-only-push: not documentation (extension "gitkeep" is not one of: md png jpg jpeg gif svg): docs/handoff/newthing/.gitkeep
exit=1
```

## This is the safe direction, and the allowlist is not to be widened for it

The rule refuses what it does not recognise. That is the property that closed the original hole,
and `.gitkeep` costing a full gate is the same property doing its job on a file that happens to
be harmless. **Do not add `gitkeep` to `DOC_EXTENSIONS` to make this go away** — that trades a
ten-minute cost for a precedent that the list may be widened by whatever is currently annoying,
which is exactly how a skip rule stops gating.

The cost is recorded here so the decision is made deliberately, once, by a person, rather than
inside a branch that is annoyed by it.

## What a fix would have to decide

1. Whether a zero-byte `.gitkeep` is a distinguishable case from any other unrecognised file
   (it is not, to the current rule — the rule reads paths, not contents).
2. Whether the repository should stop using `.gitkeep` under `docs/` at all, given that every
   directory there acquires a real file almost immediately.
3. Whether the checker should ignore a fixed, named set of VCS placeholder basenames — a
   different mechanism from the extension allowlist, and one that would need its own arm and its
   own argument about what else could be smuggled through a name-based exception.

Any of the three changes what the gate skips, so each needs an arm in
`tests/Feature/Quality/DocsOnlyPushCoverageTest.php`.
