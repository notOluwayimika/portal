# Drive brief — the parent fees screen (`/parent/finance`)

Load `finance-drive` before starting; it carries the throwaway instance, the sign-in, the
isolation-by-id method and the report shape. This brief carries only what is specific to this screen.

**Branch:** `feat/parent-portal-finance-screens`. **Screen:** `/parent/finance`, gated on
`parent_portal.access`.

## Why this screen needs a named fixture rather than whatever the seed produces

The suite is structurally blind to rendering — a 200 with the right list, a 200 with an empty list and
a 200 rendering an error where a list should be are the same assertion. That is the usual reason to
drive. **The specific risk here is different and sharper: the states most likely to be wrong are the
ones a happy-path seed does not contain.**

Driving this against a single student who owes money verifies the case that was never in doubt, and
leaves both states this branch actually wrote unit tests for unrendered. A green drive over the wrong
fixture is the drive-shaped version of a degenerate test fixture, and this project has already paid
for that once (2026-08-25, the rollover re-plan that returned `unconfigured=0` because every pupil was
already promoted — the flag was never evaluated and the zero read as proof).

## The three fixtures. Plant all three, on ONE guardian, before opening a browser

They must sit on one guardian so a single screen shows all three at once — that is also the only way
to see that they render differently from each other rather than each looking plausible alone.

| # | Ward state | What it proves | How it can look right and be wrong |
|---|---|---|---|
| **1** | **Outstanding invoices** — at least one `scheduled`, ideally plus one `supplementary` | The ordinary path: invoice rows, `display_number`, `academic_context`, billed vs outstanding, and the supplementary badge distinguishing the two kinds | This is the case never in doubt. Its passing says nothing about 2 or 3 |
| **2** | **No outstanding invoices at all** (`invoices: []`, balance zero) | The ward **still appears**, saying "nothing outstanding" | If the screen iterated invoices to build the ward list, this child **vanishes** — and the parent concludes the school has lost their record. A drive that never plants a paid-up ward cannot see it |
| **3** | **Credit AND a newer outstanding invoice** — `available_credit > 0` while `balance > 0` | The credit line shows *alongside* invoices, not only when the list is empty | The natural implementation gates credit on an empty list. That renders correctly for a purely-in-credit ward, so fixture 3 is the **only** one that catches it — and it is the case a parent finds most confusing, because their money is invisible while they are being asked to pay |

**A fourth, cheap:** a guardian with **no wards in the active school** — legitimate (they may hold
wards in a school they have not switched to), and it must read as "no children linked", never as an
error or a blank page.

**And the negative-balance variant of 3** — `balance < 0`, the school owing the parent — should render
as *In credit* with the signed figure, not as a debt and not as nothing.

## What to look at, in order

1. All wards from fixture 1–3 present on one screen, each visibly different from the others.
2. Every money figure formatted through `formatNaira` — `₦` and two decimal places. **Any bare
   integer on screen (e.g. `185000`) means a figure escaped the formatter**, which is the exact shape
   the design draft carries.
3. The supplementary badge on the supplementary invoice and not on the scheduled one.
4. **No pay button anywhere.** Its absence is deliberate this round; if one appears, something has
   been copied from `parent/dashboard.tsx`.

## Isolation, by id and not by label

Sign in as a guardian of another school and confirm `/parent/finance` shows **their** wards — checked
by student uuid, not by name. Two test schools can easily hold similarly-named students, and a check
by label passes on the wrong data.

## What the drive report must add beyond the skill's template

The **count table for all four fixtures before the browser opens** — a drive whose fixture was never
planted is a drive that proved nothing, and the count is the only thing that distinguishes "state 2
rendered correctly" from "state 2 was never on the page".
