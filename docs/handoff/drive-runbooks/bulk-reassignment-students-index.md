# Drive runbook — bulk selection & reassignment on the students index

**Branch:** `feat/reassignment-ui` (re-derive the sha with `git rev-parse --short HEAD`).
**Why this exists:** the whole feature is lint-green, type-green and suite-green, and **none of it
has been rendered**. The three behaviours below are the exact category a passing backend suite
cannot see — a 200 with the right list, a 200 with an empty list, and a 200 rendering an error where
a list should be are the same assertion. This is the category that hid the NaN badge.

Written so the pass is identical whoever drives it. Load the `finance-drive` skill for the
environment, the fixture and the seats; this file only carries **what to watch and what would count
as a fail**.

---

## Fixture requirement (check BEFORE opening a browser)

The cohort lock is invisible unless the data can defeat the display. You need, in one school:

| Need | Why |
| --- | --- |
| ≥ 6 pupils in **one** curriculum (e.g. Year 8 B) | the happy path, and a batch worth watching |
| ≥ 1 pupil in a **sibling arm** (Year 8 S) | so a destination exists in the picker |
| pupils in **two different year groups** | the disabled-state reason — this is the cohort-spanning case, and any real data has it |
| ≥ 2 pupils on **one arm** in **different exam types** | the cross-exam-type move, which must now SUCCEED (seeded, see below) |
| ≥ 1 pupil in a **single-arm year group** | the modal's empty-state |
| a second school with pupils | isolation, checked by id |

**§2 no longer needs a fixture.** Exam type has left the eligibility key, so the disabled state now
fires on a selection spanning **cohorts** — (class level, term, is_ccm) — and two pupils from
different year groups is available in any real data. That is a deliberate simplification: the case
that discriminates is now the ordinary one.

**§2b does need one, for the opposite reason.** A cross-exam-type move must now SUCCEED, and
measured on the working database **zero** active class-level-arms carry more than one exam type, so
it is unreachable by clicking until the pair is stood up:

```bash
php artisan academics:seed-cross-exam-pair            # prints the two ids
php artisan academics:seed-cross-exam-pair --undo     # removes only what it created
```

Additive and idempotent — it does **not** `migrate:fresh`, so it is safe to run against an
already-seeded drive fixture mid-drive. Guarded by the same drive-DB allowlist as
`finance:seed-drive-fixture`: it refuses any database whose name lacks an explicit `drive` token
(`finance_demo`, `school_uat` and even `driveway_db` are refused). `--undo` keeps any curriculum
that has since acquired a live enrolment, because a pupil someone else placed there is not the
command's to discard.

Confirm by id, not by label:

```sql
SELECT sc.id AS episode, s.id AS student, c.id AS curriculum, c.exam_type_id, c.class_level_arm_id
FROM student_curricula sc
JOIN students s   ON s.id = sc.student_id
JOIN curricula c  ON c.id = sc.curriculum_id
WHERE sc.status = 'active' AND sc.ended_at IS NULL AND s.school_id = <school#>
ORDER BY c.class_level_arm_id, c.exam_type_id;
```

Two rows sharing `class_level_arm_id` and differing on `exam_type_id` is the pair you need.

---

## 1. Selection clears on navigation — the honesty of "page-scoped"

Selection is page-scoped by construction: there is no select-all-matching. What makes that *true*
rather than merely intended is that ticks are dropped whenever the visible set changes.

**Steps** — tick 3 pupils · confirm footer reads `3 selected` · go to page 2 · come back to page 1.

**Watch for:**

- footer **disappears** on navigating to page 2 (count is 0, and the bar renders nothing at 0);
- returning to page 1 shows **no ticks retained**;
- repeat with a **filter change** instead of a page change (set Class Level) — same result.

**Fail looks like:** the footer surviving navigation and reading `3 selected` while fewer than 3 rows
are on screen. That is the specific bug this behaviour prevents — `selectedIds` would hold pupils no
longer rendered, so `selectedEpisodeIds` (derived from the current page) would submit fewer than the
label promises. **A footer whose count disagrees with what the button would do.**

---

## 2. The cohort-spanning disabled state — the usability half of the lock

**Steps** — tick one pupil from Year 8 and one from Year 9.

**Watch for:**

- the **Reassign** button is **disabled**;
- an amber line reads *"Reassign works within one class level and term; your selection spans 2
  cohorts."*;
- hovering shows *"…Select pupils from a single year group and term."*;
- **Export selected (2)** stays **enabled** — the lock constrains reassignment only.

**Fail looks like:** the button disabled with **no reason rendered**. An operator ticks a mixed set,
finds a dead button, and cannot tell whether the feature is broken or they are holding it wrong.
A disabled control with no explanation is the failure, not the disabling.

**Also fail:** the message still saying *"spans 2 classes"*. That is the pre-change wording and it is
now wrong in a way that matters — a selection spanning two arms, or two exam types in one level, is
legitimate, and an operator doing exactly what the eligibility change enabled would read a message
telling them it is not allowed.

Then untick the Year 9 pupil and tick another Year 8 one: button **enables**, amber line **clears**.

## 2b. Cross-exam-type must SUCCEED

Run `academics:seed-cross-exam-pair` and use the two ids it prints — same arm, different exam type,
identical class label.

**Steps** — tick **both**, press Reassign, and send them into either class.

**Watch for:** the button **enabled** (they are one cohort); the destination list **offering** the
other-exam class; the move succeeding; both pupils showing the destination afterwards.

**Fail looks like:** the button disabled, or the destination list omitting the other-exam class.
Either means the client or `CohortSiblings` still has exam type in the key — the exact regression
the inverted lock test exists to catch, seen from the screen.

---

## 3. Footer count honesty, and the two export scopes

**Steps** — tick 4 pupils · read the footer · press **Export selected (4)** · open the file.
Then clear, set a Class Level filter, press the **toolbar Export**, open that file.

**Watch for:**

- the footer button label carries the **live count** and tracks every tick/untick;
- **Export selected (4)** yields **exactly 4 rows** — even with a filter active that would exclude
  one of them (the scopes are orthogonal; the control names its own scope);
- the **toolbar Export** yields **the filter set**, not the whole school — this is defect 2, and the
  pre-fix behaviour was a file containing every pupil in the school with no clue it was wider than
  the screen;
- **no "select all matching" control exists anywhere.** If one is visible, something reintroduced it.

**Fail looks like:** either export returning a row count that disagrees with what its control claims.
Count the rows; do not eyeball the file.

---

## 4. The happy path, end to end

**Steps** — filter to Year 8 B · tick 5 pupils · **Reassign** · pick Year 8 S · submit.

**Watch for:**

- the modal names the current class and says *"All 5 move together. If any one of them cannot be
  moved, none of them are."*;
- destination list contains **every class in this cohort** — other arms AND other exam types —
  but **no other year group**, no other term, and nothing across the CCM boundary;
- success toast reads *"5 pupils reassigned from Year 8 B to Year 8 S"*;
- the list **refreshes** and those pupils now show Year 8 S;
- selection is **cleared** afterwards (their episodes are ended; keeping the ticks would show a live
  selection of ids the server would now refuse as stale);
- on a pupil's own page, the audit reads *"Reassigned from Year 8 B to Year 8 S"*.

**Empty-state check:** select a pupil from the single-arm year group and open the modal — it must say
*"… has no other arm to move these pupils into."* and the Reassign button stays disabled. **Not an
empty dropdown.**

---

## 5. Isolation — by id, never by label

Sign in as the **second school's** seat. Confirm its students index shows **only** its own pupils,
and that the first school's pupils are absent **by id**, not by looking at names — the fixture
deliberately reuses first names across schools, because that is what makes a label check useless.

---

## What to hand back

- screenshots: footer at 3 selected; the amber two-curricula reason with Reassign disabled; the
  modal's destination list; the success toast; the single-arm empty state;
- a GIF of §1 (tick → navigate → return) — the clearing is a transition and a still frame cannot
  show it;
- the two export files' **row counts**, stated as numbers;
- for §5, the **ids** observed in each school, not the names.

Anything that renders differently from the above is a finding, including "it worked but looked
wrong" — this pass exists precisely because the suite cannot see rendering.
