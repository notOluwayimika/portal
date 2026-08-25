# Drive runbook — M4 · year rollover surface

**Branch:** `feat/m4-rollover-surface` (re-derive the sha with `git rev-parse --short HEAD`)
**Screen:** `/academics/rollover`
**Why this exists:** the backend is 22 tests and 9 mutation-checked guards, and **none of it has been
rendered**. A 200 with the right plan, a 200 with an empty plan, and a 200 rendering an error where a
plan should be are the same assertion to the suite. This is the category that hid the NaN badge.

Load the `finance-drive` skill for the environment and seats. This file carries only **what to watch
and what counts as a fail**.

---

## Before the browser

### Seat

You need a seat holding **`academics.rollover`**. Only `admin` has it — that is deliberate (it is the
most destructive action in the system, so it shipped with the smallest grant that can exercise it).

You also need a **second seat WITHOUT it** for §6. Any non-admin role works; `registrar` is the
sharpest because it holds `academic_setup.manage`-adjacent config permissions and still must be
refused — proving rollover is not inheriting the config permission.

### Data

| Need | Why |
| --- | --- |
| two academic sessions (a closing one and a target) | the two dropdowns |
| ≥ 1 class level with a **participation slot** and an active non-CCM curriculum in the closing session's term at that slot | otherwise every plan is empty and §3–§5 render nothing |
| pupils enrolled in that curriculum | so the pupil count is not 0 |

**Confirm the data can produce a runnable plan before opening the browser** — a screen that says
"nothing to migrate" is indistinguishable from a screen that is broken, and you will not be able to
tell which you are looking at:

```sql
SELECT c.id, c.school_id, c.term_id, c.is_ccm, c.status,
       (SELECT COUNT(*) FROM student_curricula sc
         WHERE sc.curriculum_id = c.id AND sc.ended_at IS NULL) AS live_pupils
FROM curricula c
JOIN class_level_arms cla ON cla.id = c.class_level_arm_id
JOIN class_level_term_participations p
  ON p.class_level_id = cla.class_level_id AND p.school_id = c.school_id
JOIN terms t ON t.id = c.term_id AND t.`order` = p.term_order
WHERE c.status = 'active' AND c.is_ccm = 0 AND t.academic_session_id = <closing session id>;
```

At least one row with `live_pupils > 0` is what §3 needs.

**⚠️ Do not drive this against a database you care about.** §5 QUEUES A REAL BATCH. If a worker is
running, pupils actually move. Use a throwaway drive database, or confirm no queue worker is
running and clear `jobs` afterwards.

---

## 1. The gate is invisible without the permission

**Steps** — sign in as the seat **without** `academics.rollover` · navigate to `/academics/rollover`.

**Watch for:** a **403**, not a rendered page.

**Fail:** the page rendering with the form visible. The page route and the API carry the same
permission on purpose — a screen gated differently from its API is a live defect elsewhere in this
codebase (`/guardians` gates its page on `admin_area.access` and its API on `academic_setup.manage`,
which presents to the operator as a broken login). If this page renders for a seat the API refuses,
that defect has been reproduced here.

## 2. A clean preview

**Steps** — as the admin seat, pick the closing session and the target session · **Preview**.

**Watch for:**

- a class count and a pupil count, both **non-zero**;
- *"Progression graph: checked, no cycles."* — the **middle** of three states;
- **Run rollover** enabled;
- **nothing queued** — check `/academics/rollover`'s batch panel and the `job_batches` table; a
  preview must never dispatch.

**Fail:** *"not applicable"* appearing here. That is the end-of-**term** state and means the screen
is reading `progression_cycle` alone rather than `progression_check_ran` first — the two-meanings bug
the DTO was reshaped to prevent.

## 3. The cycle gate names the ring

**Steps** — in class-structure, point two levels at each other (Year 7 → Year 8 → Year 7) · Preview
again.

**Watch for:**

- a red panel: *"The progression graph contains a cycle."*;
- **the ring itself, in order**, e.g. `Year 7 → Year 8 → Year 7` — not "a cycle was found";
- a link to fix the progression config;
- **Run rollover disabled**.

**Fail:** a generic "blocked" with no ring. Naming the ring is the entire reason the planner calls
`ProgressionGraph` directly instead of reading a command's exit code — if the ring is missing, that
seam has been undone.

Then break the cycle and re-preview: the panel returns to *checked, no cycles*.

## 4. The CCM gate lists the offenders

**Steps** — set `is_ccm = 1` on a curriculum sitting in a final slot for the closing session ·
Preview.

**Watch for:** the offending class(es) **listed by name**, and **Run rollover disabled**.

**Fail:** a count with no names. An operator told "3 CCM classes block this" cannot act on it.

## 5. The commit — and the words that matter

**Steps** — restore a clean, runnable state · Preview · **Run rollover** · read the confirm dialogue
**before** confirming.

**Watch the confirm for:**

- both session names;
- the **pupil count and class count** it is about to move — not a bare "are you sure?";
- the line saying it is re-checked at this moment and will be refused rather than run if anything
  changed.

**Then confirm, and watch for:**

- a message containing **"Queued"**, **"not finished"**, and **"do not change the current session"**;
- the batch appearing in the panel below with **done / queued / failed** counts and
  **"Draining — do not change the current session yet"**;
- the word **"complete"** or **"done"** appearing nowhere while jobs are pending.

**Fail:** any wording that reads as finished. A registrar who reads "done" and switches the current
session mid-drain is the specific failure this phrasing exists to prevent.

## 6. The stale preview — the one that cannot be tested by looking

This is the behaviour the whole plan/dispatch split exists for, and the only way to see it is to
change the world between two clicks.

**Steps** — Preview a clean, runnable plan and **leave the result on screen** · in another tab,
introduce a cycle (as in §3) · come back and press **Run rollover**, then confirm.

**Watch for:** the commit is **refused**, and the panel now shows the **cycle**, not the clean plan
you were looking at.

**Fail:** the rollover running. The preview is advisory; the commit re-plans. If a cycle introduced
after the preview still dispatches, a whole year group has migrated through a ring in which every job
succeeds individually and nobody advances — the exact unrecoverable outcome the gate exists for.

## 7. Count honesty when the plan changes underneath

**Steps** — Preview a runnable plan and note the **class count** · in another tab, close one of the
classes in that plan (`status` → `closed`, so it drops out of the selection without blocking) · return
and confirm.

**Watch for:** the result panel reporting the **queued** count (the smaller one) **and** an amber line
saying the preview showed N and M were queued, with an instruction to check which did not move.

**Fail:** the result echoing the **previewed** number. "Previewed 240, dispatched 238, screen says
240" is the moved-vs-skipped lie, and the re-plan makes it a real state rather than a hypothetical.

## 8. The placement table — where every pupil actually lands

The count never answered this. Arm placement is the least obvious part of the operation (an explicit
map, then a stream-aware label match, then `student_id % armCount` over the receiving level's arms by
id), and before this it was invisible until the batch had drained.

**Steps** — Preview a runnable end-of-year plan over a level whose **receiving level has two or more
arms** · read the **Moving up** table · expand **names** on one row · note one pupil by name and the
class the preview says they land in · commit, let the batch drain, then open that pupil's record.

**Watch for:** the pupil sitting in **exactly the class the preview named**. Also that the table is
grouped by destination with counts, not a flat list of every pupil in the year group.

**Fail:** any pupil landing somewhere other than the previewed class. That is preview/commit drift,
and it is the whole reason the placement rules were extracted to one resolver both sides call.

**Also check the two other buckets exist when they should:** a pupil marked `repeated` appears under
**Held (repeating)** and *not* under Moving up; a level whose receiving level has no arms (or is
`explicit_only` with no match) appears under **Would not move** with a reason, not silently absent.

## 9. The subject warning — and the ordering it is telling you about

End-of-year does **not** carry subjects across; the new class level defines its own. If the
destination curriculum does not exist yet, the rollover creates it **empty** and the pupils land with
no subjects — and **nothing re-attaches them afterwards**, because every path that attaches
compulsory subjects runs at enrollment-creation time.

**Steps** — With a destination curriculum that does **not** yet exist, preview · read the red panel ·
press **Run rollover** and read the **confirm dialogue** before confirming · cancel · now create that
destination curriculum (right term slot, right exam type, right arm) · preview again.

**Watch for:** the red panel naming the count and the three things that must match; the **confirm
dialogue repeating it** — that is the half you cannot scroll past, and it is there because the failure
is unrecoverable; and after the curriculum is created, the warning **gone** and the row no longer
badged "no curriculum yet".

**Fail:** the warning appearing on an **end-of-term** rollover. That kind clones its subjects onto the
target, so the warning would be false there — and a screen that warns about something that cannot
happen teaches operators to skip warnings.

**Fail:** the warning still showing after the destination exists. A flag stuck on is as useless as one
stuck off.

## 10. The swap — the acknowledgment is binding, not decorative

The confirm used to be theatre: the commit received two session ids and nothing else, so the server
could not tell an acknowledged plan from an unacknowledged one. This is that fix, and it is the arm a
count-based check cannot catch.

**Steps** — Arrange **two** destinations with no curriculum · preview (the panel says 2) · in another
tab, **create** one of those two curricula **and delete** a different destination's curriculum that
did exist · return and confirm.

**Watch for:** the commit **refused** with a message saying a destination became unconfigured, and
**nothing queued** — even though the count is still 2.

**Fail:** the rollover running. The number did not move, so a check comparing counts sees nothing
wrong while a destination the operator never saw takes pupils with no subjects.

**Then check the other direction:** preview with an unconfigured destination, configure it, and
confirm. That must **proceed** — refusing someone for fixing the thing they were warned about teaches
people to stop fixing it.

## 11. Isolation

Sign in as a second school's admin seat. Its `/academics/rollover` must offer **only its own
sessions**, and its batch panel must show **only its own batches** — checked by **id**, not by
looking at names.

---

## What to hand back

- screenshots: the three cycle states (not-applicable if you can reach an end-of-term plan,
  checked-acyclic, and the named ring); the CCM list; the confirm dialogue with its counts; the
  queued message; the batch panel mid-drain;
- a **GIF of §6** — the stale preview is a transition between two screens and a still cannot show it;
- for §7, the two numbers as digits (previewed N, queued M) and whether the divergence line appeared;
- for §8, the **Moving up** table with one row expanded, plus the pupil id and the class the preview
  named against the class they actually landed in — **by id**, not by name;
- for §9, three shots: the red panel, the **confirm dialogue** carrying the same warning, and the
  panel gone after the destination curriculum exists. Also state plainly whether the warning appeared
  on end-of-term (it must not);
- a **GIF of §10** — the swap is a transition and the count is unchanged across it, so a still of
  either end proves nothing; note whether the refusal named the destination and whether the
  configure-then-commit direction proceeded.
- for §8, the **ids** seen in each school, not the names.

Anything rendering differently is a finding, including "it worked but read wrong" — this pass exists
because the suite cannot see rendering, and on this screen the wording *is* the safety feature.
