# Result workstream duty-separation readiness — is it safe to widen `enforcedPairs()`?

**Status:** evidence gathered, **decision open**. Nothing was changed;
`DutySeparation::enforcedPairs()` is untouched. Producing this evidence and acting on
it are two hands, on purpose.

**Verdict up front: the audit is NOT clean.** Five users across both schools hold both
sides of a result pair today. Widening now would refuse ten existing grants.

`enforcedPairs()`'s own docblock names the condition:

> Finance pairs only — widen to `pairs()` (all, incl. result) ONLY when the result
> workstream's own audit is clean AND they have agreed to enforcement on the record.

This document supplies the first half. The second half is the workstream's to give.

---

## 1. The pairs, derived not enumerated

`DutySeparation::pairs()` is **convention-derived**, not a written list: every
`Permission` case that `ApprovalAbility::isExcludedFromSuperAdminBypass()` marks as a
checker is paired with `ApprovalAbility::matchingMakerFor()`. A future instance
(refunds) joins with no edit — and, equally, an action **not** named
`<prefix>.submit` / `.approve` / `.reject` is invisible to this machinery entirely.

| | count |
|---|---|
| `pairs()` — all | **10** |
| `enforcedPairs()` — refused today | **8** (all `finance.`) |
| **Detected, never refused** | **2** (both `result.`) |

The unenforced two, which are this document's entire scope:

```
result.approve  <-  result.submit
result.reject   <-  result.submit
```

Two checkers, **one shared maker** — `ApprovalAbility::CHECKER_SEGMENTS` is
`['approve','reject']`, so every family contributes two pairs. That is also why the
finance side is 8 rather than 4: four families (credit-note, invoice.void-request,
discount-policy.change, fee-schedule.change), each × 2.

The gap is not theoretical. Both directions were exercised on a scratch database:

```
RESULT pair:  guard did NOT throw — detected, never refused
FINANCE pair: guard THREW DutySeparationViolationException — enforcement is live
```

---

## 2. The two existing commands

**Run 2026-08-01 against a local copy.** ⚠️ **This is a stale local copy, not
production evidence.** It is the same database the 2026-08-01 sweep used. It reports
what was true when the copy was taken; production may differ in either direction.
Running either command against production is the project lead's call.

Users are shown as `user#<id>` — the real ids from this copy, not pseudonyms — so a
row can be traced without putting a name or email in the repository. Schools by id.

### `finance:audit-duty-separation` — does anyone hold both sides?

Covers **all ten** pairs despite the `finance:` prefix. Result rows only:

| School | User | Checker | Maker |
|---|---|---|---|
| 1 | `user#4` | `result.approve` / `result.reject` | `result.submit` |
| 1 | `user#6` | `result.approve` / `result.reject` | `result.submit` |
| 1 | `user#35` | `result.approve` / `result.reject` | `result.submit` |
| 1 | `user#51` | `result.approve` / `result.reject` | `result.submit` |
| 2 | `user#3199` | `result.approve` / `result.reject` | `result.submit` |

**10 findings — 5 distinct users, 2 schools.** Zero finance findings.

### `finance:check-staffing-readiness` — are two distinct people available?

Result rows only:

| School | Pair | makers | checkers | Two-person flow |
|---|---|---|---|---|
| 1 | `result` / `result.reject` | 68 | 6 | **OK** |
| 2 | `result` / `result.reject` | 46 | 2 | **OK** |

Both schools are staffed for the two-person flow **as things stand**. Note this
command counts heads; it does not subtract the both-sides holders above.

---

## 3. Blast radius — what widening would refuse today

**The number: 10 grants, 5 users, 2 schools.** Identical to §2's finding, which is the
cross-check: the audit and the simulation agree.

| School | User | Pair | Checker via role | Maker via role |
|---|---|---|---|---|
| 1 | `user#4` | both result pairs | `head_of_school` | `teacher` |
| 1 | `user#6` | both result pairs | `head_of_school` | `teacher` |
| 1 | `user#35` | both result pairs | `head_of_school` | `teacher` |
| 1 | `user#51` | both result pairs | `head_of_school` | `teacher` |
| 2 | `user#3199` | both result pairs | `admin` | `teacher` |

895 `(user, school)` combinations examined; 0 already refused (no finance violations).

**This is one shape, not five problems.** Every case is *a senior person who also
teaches*: `head_of_school` (or `admin`) carries the checker, `teacher` carries the
maker. Nobody has been given a strange bespoke grant — the roles are behaving exactly
as designed, and the pair only forms because one human holds both jobs.

### How the simulation was built, and what is NOT the real guard

⚠️ **`assertRoleSetAllowed()` could not be driven over all pairs.** It calls
`self::enforcedPairs()` directly, there is no injection point, and `DutySeparation` is
`final` — so no subclass and no override. Editing the class was out of scope.

The workaround, stated so the number can be judged:

- **Reused verbatim, via reflection:** the class's own private `abilitiesByRole()` and
  `rolesCarrying()`. That is the role→ability resolution — the part where a
  reimplementation could actually diverge.
- **Re-expressed:** only the containment test, quoted from the class docblock — *"a
  user holding BOTH C and M WITHIN THE SAME SCHOOL is a violation"* — over `pairs()`
  instead of `enforcedPairs()`.

Deliberately **not** built on `violations()`. That evaluates *effective* ability via
`$user->can()`, honouring the `super_admin` `Gate::before` bypass and its
checker-exclusion (ADR 0040). The enforcement path evaluates *role-granted*
abilities. They give different answers for the same user, and a simulation built on
the wrong one would not describe what happens when the line is flipped.

### The simulation was bite-proved

An unproven zero is worth nothing, and so is an unproven ten.

| Scratch DB state | Newly refused |
|---|---|
| Freshly seeded, no role grants | **0** |
| One user granted `teacher` + `head_of_school` in one school | **2** — exactly that user, both result pairs |

The script lived in `/tmp` and is **not committed**.

---

## 4. Recommendation

**Do not widen yet. Widen after the five grants are resolved — and the resolution is
a role-design decision, not a revocation.**

Widening today would throw `DutySeparationViolationException` on the next role edit
touching any of those five users, in a system they are actively using. That is a
lockout introduced by a control, not by a fault.

The blast radius is small (5 users) and uniform (one shape), so this is tractable.
Three options, and the third is the one worth arguing for:

1. **Revoke the checker side** — take `head_of_school` / `admin` off those five.
   Staffing survives: school 1 keeps **2** clean checkers of 6, school 2 keeps **1**
   of 2. Both retain ≥1 checker and dozens of makers, so the two-person flow still
   runs. But school 2 would rest on **one** person, and a single absence stops result
   approval there entirely.
2. **Accept and document** — widen `pairs()` minus the result pairs, i.e. change
   nothing, and record that result segregation is capability-level only. Honest, but
   it leaves the control permanently unbuilt.
3. **Restructure — recommended.** These people are heads of school who also teach.
   The pair forms because `teacher` carries `result.submit` and they need `teacher`
   for their own classes. Splitting the maker out of `teacher` — or giving heads a
   non-submitting teaching role — removes the conflict without removing anyone's job.
   That is a change to the role catalogue, and it belongs to the result workstream.

Whichever is chosen, the act-level guarantee is **already absolute and unaffected**:
`CHECK (submitted_by <> decided_by)` in the database means none of these five can
approve their own submission today. What is unguarded is the weaker
capability-level property — one person able to approve a *colleague's* work in both
directions.

**Re-run this audit against production before deciding.** The five users here are from
a stale copy.

---

## 5. What this audit cannot see

- **It is a stale local copy, not production.** Every number above describes one
  snapshot. Production may hold more both-sides users, or fewer, or different ones.
- **`CheckStaffingReadiness`'s own recorded residual:** two "distinct" users who are
  the same human with two accounts read as staffed. School 2's *one* remaining clean
  checker under option 1 is exactly where that would hurt.
- **The simulation is not the guard.** It reuses the real ability resolution but
  re-expresses the containment loop (§3). If `assertRoleSetAllowed()` gains a
  condition beyond both-sides containment, this number silently stops matching.
- **Convention-invisible pairs.** A checker action not following the
  `.submit`/`.approve`/`.reject` naming is absent from `pairs()` and therefore from
  every line of this document — including the audit command's. A missing result pair
  would mean a checker action nobody is auditing at all.
- **Role-granted only, per §3.** A user holding an ability by a **direct permission
  grant** rather than through a role does not appear here. `model_has_roles` is the
  only source read.
- **Point in time.** A grant made after 2026-08-01 is not in this snapshot, and
  nothing continuously re-checks. This document expires.
