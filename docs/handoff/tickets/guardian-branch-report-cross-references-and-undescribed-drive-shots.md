# The `fix/guardian-create-duplicates` report has two dead cross-references and three undescribed screenshots

**Raised by:** the third cold review of `fix/guardian-create-duplicates` (finding 4).
Recorded rather than fixed: the branch is merging, and this is a defect in the record,
not in the code.

## The state

The report's most severe claim — that student registration through the admin UI has been
broken since `6bfed87` — points twice at a section that does not exist:

- near the top: *"(§ Round 3 — what the drive found instead)"*
- in the findings table: *"(see what the drive found instead)"*

`grep -n '^#'` over `docs/handoff/reports/fix-guardian-create-duplicates.md` returns no
such heading. The claim itself is carried by a table row and by
`docs/handoff/tickets/student-registration-spoofs-every-create-to-PATCH.md`, and it is
**true and independently verified** — the reviewer confirmed
`resources/js/components/students/student-form.tsx` appends `_method = PATCH` outside
the `isEdit` branch and is byte-identical at `e484a46`. Only the signposting is broken.

Separately, three screenshots are committed under
`docs/handoff/drives/2026-08-19-guardian-create/` with no prose describing them:

- `registration-01-form.png`
- `registration-02-filled.png`
- `registration-03-error-rendered.png`

They are from the attempt to drive the registration screen that the `_method` defect
blocked; the last one is misleadingly named, since what it actually shows is the screen
displaying **nothing** after a discarded 400.

## Why it matters enough to write down

The report is the artifact the merge is judged on. A reader following either
cross-reference lands nowhere, and committed evidence that nobody describes reads as
evidence somebody chose not to explain.

## The reusable part — a generator whose replacement window is defined by document structure

**This is worth more than the finding it explains, and it is not specific to this
report.** The cause was automation I introduced to fix a *different* staleness problem.

The table under `## What changed` had been wrong by hand three rounds running, so I
generated it instead — with a script that replaces everything between two headings:

```python
start = s.index('## What changed\n')
end   = s.index('## Proof')
s = s[:start] + generated_table + s[end:]
```

The narrative sections written in the intervening rounds sat **inside that window**. So
regenerating the table silently deleted the prose the cross-references pointed at, the
commit went out that way, and a reviewer found the dangling references without being
able to see why they dangled.

**The general shape: a generator whose replacement window is defined by document
structure will eventually eat something a human put there.** The window is implicit —
"everything up to the next heading" — and nothing in the script knows the difference
between the region it owns and a region a person added later. It fails silently, because
deleting prose produces a document that still parses and still reads plausibly. And the
failure arrives at exactly the moment someone is trusting the tool most, having adopted
it specifically because the manual version kept going wrong.

Three ways out, in ascending order of robustness:

1. **Explicit delimiters the generator owns** — `<!-- generated:start -->` /
   `<!-- generated:end -->` — so the window is declared rather than inferred.
2. **Generate into its own file** and include or link it, so there is no window at all.
3. **Assert the invariant**: have the generator diff its own output region and fail if it
   is about to remove a line it did not write.

Any of them would have caught this. None of them was in place because the script was
written to solve staleness and its own blast radius was never considered.

## What closing it looks like

Either write the section both references point at, or drop both references and let the
ticket carry the finding; and either describe the three screenshots or rename
`registration-03-error-rendered.png` to what it depicts — `registration-03-no-error-shown.png`
would be honest — and say in one line why they are there.
