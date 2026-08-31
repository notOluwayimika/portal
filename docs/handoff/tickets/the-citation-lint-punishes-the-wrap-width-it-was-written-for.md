# The citation lint punishes the wrap width it was written for

**Status:** open, small. Met twice on 31 August while adding a `BelongsToSchool.php:21` pointer to a
docblock, and again while writing tickets in `docs/`.

## The collision

`bin/ci-citation-lint.php` requires a new `path:LINE` citation to name its symbol, in either
spelling, ON THE SAME LINE — the reader is line-based. This repository wraps prose and docblocks at
100 characters. A citation near the end of a line therefore has its symbol pushed onto the next line
by correct wrapping, and the lint then reports it as a bare citation carrying no symbol.

The author's fix is to reflow the paragraph so the pair lands together, which is a formatting change
made to satisfy a reader rather than to help a human — and the next person to edit that paragraph
re-breaks it without knowing why the words were arranged that way.

## Why it is worth fixing rather than living with

The rule itself is right and was earned: `stale-path-line-citations.md` records six wrong citations
across four branches, none caught by anything automatic. This ticket is not an argument against the
rule. It is that the instrument's line-based reading makes correct house formatting look like a
violation, so the lint's failures are not all defects — and a gate whose red sometimes means
"rewrap" is a gate people start reading past.

## What closes it

Read a citation and its trailing symbol across a line break — the same two spellings, allowing the
`(symbol)` to sit at the start of the following line when the path ends the previous one. Docblock
and Markdown continuation lines are already identifiable by their leading `*` or indentation.

**Do not close it by widening the wrap.** The wrap width is not the defect, and changing it would
reformat the tree to suit a lint.

**Do not close it by baselining the instances.** The baseline may only shrink and is keyed by count
per file, so parking correctly-formatted citations in it spends the ratchet on a formatting quirk
and hides the next genuinely stale citation in the same file.
