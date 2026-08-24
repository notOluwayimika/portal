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

## 8. Isolation

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
- for §8, the **ids** seen in each school, not the names.

Anything rendering differently is a finding, including "it worked but read wrong" — this pass exists
because the suite cannot see rendering, and on this screen the wording *is* the safety feature.
