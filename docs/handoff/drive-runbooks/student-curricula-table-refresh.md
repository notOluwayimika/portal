# Drive runbook — #286 · the curricula table reflects a reassignment without a manual refresh

**Branch:** `feat/reassignment-ui` · **Fix:** `ca3260b6` ·
**File:** `resources/js/components/student-curricula-page.tsx`
**Screen:** a student's detail page → their enrolments/curricula table.

**This is the 3→4 re-check.** It is short, and it is the single hardest kind of bug for a test suite
to see: the server was always right, the payload always arrived, and the screen ignored it.

---

## What was wrong, so you know what you are looking at

Reassigning a pupil updated the database, closed the panel, and toasted the correct sentence — then
showed the **old rows** until the operator pressed browser-refresh.

`router.reload({ only: ['student'] })` fired correctly and the fresh payload landed. The table was
seeded with `useState(student.student_curricula)`, and **`useState` reads its argument on the first
render only**. Inertia re-renders the same component instance when new props arrive, so the table
kept the array it had mounted with and discarded every update after it.

**Why no test caught it:** the request was correct, the response was correct, and the component's
state was internally consistent. Every assertion a feature test can make was already true. The defect
existed only between the payload arriving and the pixels changing.

---

## Fixture

Any student with **at least two enrolment episodes** — one live, one ended — so the table has rows
that visibly change. A pupil in a class with a sibling arm to move into (Year 8 B with a Year 8 S
available).

Confirm before opening the browser, by id:

```sql
SELECT sc.id AS episode, sc.status, sc.ended_at, c.id AS curriculum
FROM student_curricula sc
JOIN curricula c ON c.id = sc.curriculum_id
WHERE sc.student_id = <student id>
ORDER BY sc.id;
```

Note the **count of rows** and the **live episode's curriculum id**. Those two numbers are what you
are checking against.

---

## The check

**Steps** — open the student's detail page · note the number of rows in the curricula table and the
class shown as current · reassign the pupil into a sibling arm · **do not touch the browser refresh.**

**Watch for:**

- the table showing the **new** row count (3 → 4) **immediately** after the panel closes;
- the previously-current episode now rendering as ended / *Reassigned*;
- the new class showing as current;
- **all of it without any manual refresh** — that is the entire point.

**Fail:** the row count staying at 3, or the old class still showing as current, until you refresh.
That is the original defect returning.

**Also fail, and this one is subtler:** the table showing the **stale** rows for a visible instant
before correcting itself. The fix adjusts state **during render** rather than in an effect,
specifically so React discards that render and re-runs before touching the DOM. A visible flash of
the old rows means it has been moved into a `useEffect` — which passes any test you could write and
is a worse screen.

## The half that must NOT regress

The same component patches its rows **optimistically** for status changes and promotion — those paths
never reload, so the prop identity does not change and the sync branch must not run.

**Steps** — on the same table, change an episode's **status** (not a reassignment) · then promote a
pupil.

**Watch for:** the change appearing immediately, and **not** being reverted a moment later by a
server payload overwriting it.

**Fail:** an optimistic edit flickering back to its old value. That means the sync is firing when it
should not, and the fix has traded one stale-render bug for another.

---

## What to hand back

- a **GIF**, not a screenshot — the whole finding is a transition, and a still frame of a correct
  table is indistinguishable from a still frame of a stale one that happens to look right;
- the row count before and after, as digits;
- one line confirming the optimistic status-change path still applies instantly and is not reverted.
