# `CLAUDE.md`'s testing lessons are split across two branches and will auto-merge into a broken list

**Status:** open · **Severity:** fix-at-merge (silent; no gate catches it)
**Applies to:** whoever merges the SECOND of these two branches.

## The problem

Four testing lessons were written in the same session, on two branches that were cut from
different bases:

| Lesson | Branch |
| --- | --- |
| Never build a test's input from the value under test (the self-referential cap) | `feat/reassignment-ui` |
| A green suite after a KEY change means the old behaviour survived | `feat/reassignment-ui` |
| Re-arming a tripwire means grepping for every sibling | `fix/allocate-payment-tripwire-9-7` |
| A red is not a regression until you've seen the same code green somewhere | `fix/allocate-payment-tripwire-9-7` |

They belong in **one** list in `CLAUDE.md`'s "Testing & verification" section. They are currently
two lists anchored at different points, because the second pair was written on a branch that could
not see the first pair.

## Why this needs a ticket rather than a merge-order convention

**Git will merge them cleanly.** The two pairs touch different lines, so there is no conflict, no
prompt, and no gate that fires. The result is a `CLAUDE.md` whose testing section contains two
separate bullet runs saying related things in different places — the exact outcome nobody reviews
because nothing drew attention to it.

Merge ORDER does not fix this on its own: order is not enforced anywhere, and both orders produce
the same split list. Only a deliberate consolidation does.

## What to do

When the **second** of the two branches merges to `staging`:

1. Read `CLAUDE.md`'s "Testing & verification" section end to end.
2. Fold the four lessons into one contiguous run, in this order — general to specific, because the
   last one subsumes two of the others as corollaries:
   - **a red is not a regression until you have seen the same code green somewhere** (the general
     form: the ratchet compares against a baseline, not a clean run, so "not in the baseline" means
     newly *observed*, not newly *broken*);
   - re-arming a tripwire means sweeping for siblings (a corollary: the alarm fired correctly and
     the ratchet mislabelled it "new");
   - never build a test's input from the value under test;
   - a green suite after a key change means the old behaviour survived.
3. Delete this ticket in the same commit.

## Why it is worth the five minutes

Each lesson exists because it cost a session. A split list is how a rule gets read as two smaller
observations instead of one principle — and the general form above is the one that would have saved
the most time, so burying it halfway down a second run is the specific loss to avoid.
