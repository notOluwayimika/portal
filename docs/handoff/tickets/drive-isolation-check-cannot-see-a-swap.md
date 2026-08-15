# TICKET — the drive skill's isolation method cannot distinguish isolation from a swap on an N=1 fixture

**Status:** open, not implemented. Raised by `feat/u8-invoice-modal-discount-policy` (U8 commit 6),
whose own drive made exactly this over-claim and whose report is corrected in the same commit.
`.claude/skills/finance-drive/SKILL.md` is deliberately **not** edited here: a feature branch does
not get to rewrite the shared procedure on the way past, and the fix has options that want a
decision rather than a patch.

## What the skill asks for

Its *"Isolation is checked by id, never by label"* section is right about the thing it was written to
stop. `seedAcademicSlot()` runs identically for both schools, so every label is the same string by
construction and a screen showing "First Term" proves nothing. Its instruction — read option
**values** out of the DOM, put both seats side by side, confirm School A's newly authored row is
**absent** from School B's list — follows correctly.

## What it does not say

**Disjointness is not ownership.** When the fixture seeds exactly one row per school, "seat A sees
`uuid-X`, seat B sees `uuid-Y`, and X ≠ Y" is **bit-for-bit identical** to what a scope that swapped
the two schools would produce. Each seat still sees exactly one option; the values are still
disjoint; the labels are still identical. Every observation the skill asks for is satisfied by the
broken system.

The absence half — "School A's row must be absent from School B's list" — is likewise satisfied by a
swap: under a swap, A's row *is* absent from B's list, because B is being shown A's… which is A's
row. The check only bites when a list is a **superset**, never when it is the wrong singleton.

What would settle it is the uuid↔school mapping, derived independently of the screen, and compared
against what each seat rendered. The skill never asks for that, and the drive it governs did not do
it.

## Where this bit

U8's drive read `a282757e-f36d-…` on `maker@drive.test` and `a282757e-f774-…` on
`school-b@drive.test`, both labelled "Sibling discount", and reported it as isolation. The count
table it had already pasted says why that could not carry the claim: **Discount policies — A: 1,
B: 1.** The report's own §7 derived the mapping from the database beforehand and then did not paste
it, so the one artifact that would have closed the gap is the one that was left out.

This is not hypothetical elsewhere either. The skill's own worked example, quoted from U1, has
`MODAL account options(2)` on both seats — a placeholder plus one bank account per school. That
example has the same weakness the same way.

## What actually settles it, and it already exists

`tests/Feature/Finance/DiscountPoliciesScreenTest.php:130-145` —
*"shows School B its OWN policies, and none of School A's"*. It seeds **distinctly named** policies
in two schools, gives School A **two** of them and School B **one**, and asserts the returned ids are
exactly `[$mine->uuid]`. That arm can tell a swap from isolation: under a swap, School B's caller
would receive School A's two ids and the expectation fails on both count and value. It predates this
branch, and no drive report has ever cited it.

That is the general shape of the fix: **asymmetry**. A swap is invisible whenever the two sides are
structurally identical, and visible the moment they are not.

## Options, none chosen here

1. **Say it in the skill.** Add one paragraph to the isolation section: an N=1 fixture cannot
   distinguish isolation from a swap by this method; paste the uuid↔school mapping derived from the
   database beside the two seats' option lists, or say the check is disjointness only. Cheapest, and
   changes no fixture.
2. **Make the fixture asymmetric.** Seed School A two discount policies and School B one (and the
   same for bank accounts), so a swap changes the *count* a seat sees, which the existing method
   already reads. Costs a `DriveCastSeeder` change and every count table printed since.
3. **Ask the drive to assert the mapping.** Have the drive script query the ids per school before
   opening a browser and print them beside the DOM values. Strongest, most work per drive.
4. **Point the skill at the Pest arm** and stop claiming isolation from a drive at all — the drive
   then reports what it can see (a populated select, correct labels, no leakage of a *superset*) and
   names the arm that proves ownership.

Options 1 and 4 are complementary and cost nothing but words. 2 and 3 are real work.

## Not proposed here

Which option, and whether the same paragraph belongs in `finance-execute`'s brief template so a brief
stops asking for an isolation check the fixture cannot support. Editing
`.claude/skills/finance-drive/SKILL.md` is the whole of the remedy for option 1 and is left to a
commit whose subject is the skill.
