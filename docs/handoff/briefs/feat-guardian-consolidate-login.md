# Brief — `feat/guardian-consolidate-login`

**Base:** cut from `staging` after `feat/guardian-merge-command` lands — re-derive
with `git rev-parse --short staging` before you branch.
**Branch:** `feat/guardian-consolidate-login`.
**Shape:** a service path, a console surface, and arms. No migration.

**Read this before writing anything.** This feature already existed once, on
`feat/guardian-merge-command`, as a `--consolidate-login` flag. It was removed
after **five review rounds each found the same class of defect in it and not one
in the merge engine it was bolted to**. A sixth round, after the removal, added
one more. You are not starting from zero. You are starting from six findings
that are, between them, most of your specification.

---

## What consolidation has to do

One human ends up with two `users` rows — commonly because
`createGuardianWithUser` dedupes by email and an email-less submission matches
nobody (`User::where('email', null)->first()` never matches under MySQL). Both
accounts may be able to sign in. Collapsing their two guardian **records** is
`guardians:merge`'s job and is done. Collapsing their two **accounts** is this
branch's job, and it is not the same thing:

- end the access on the account that is going away,
- make sure the surviving account can actually be used,
- and **tell the person**, because their password is about to stop working.

`guardians:merge` now refuses outright whenever an absorbed record's account can
authenticate and is not the keeper's. That refusal is unconditional and terminal
and it must stay that way until this branch gives it something safe to call.

---

## The findings. This is the specification.

### 1. `users.disabled_at` is account-global, not per-school

`GuardianService::disableLogin` writes `users.disabled_at`. There is no
school dimension to it. So disabling an account inside a school-A cleanup removes
that person's access **at every school they have any access to**, and the
credentials email a school-A merge sends names school-A's children only.

Demonstrated on the branch, with the guard removed — school B had deliberately
revoked the shared account, and a school-A merge handed it back:

```
BEFORE  school#2 has revoked user#2: disabled_at=SET, its school#2 records still exist: [1]
AFTER   user#2 disabled_at=NULL
AFTER   school#2 access RESTORED without school#2 asking: true
```

Both directions bite: **disabling** the donor takes access away elsewhere, and
**re-enabling** the keeper gives it back elsewhere. Guard both halves of that
write, not one.

### 2. Authentication never reads `can_login`

`FortifyServiceProvider` resolves `User::where('email', …)`, then checks
`! $user->isDisabled()` and the password hash. `guardian_student.can_login` is
not in that predicate and never has been. `disableLogin` writes `disabled_at` and
never touches the pivot; `enableLogin` clears it and re-issues a password, also
never touching the pivot.

The first version of the guard was keyed on `can_login`. Against the 14
phone-matched duplicate groups in the production copy it refused **1** and waved
through **13** — every one of which would have left an enabled, deliverable
account backing a soft-deleted guardian record. The parent signs in,
`forUserInActiveSchool` returns null, `GuardianController::wards` answers 200
with an empty list. Blank portal, no email, no error:

```
can_login rows on donor guardian: 0
AFTER MERGE  donor user#3 authenticates: true
AFTER MERGE  forUserInActiveSchool(user#3) = NULL
AFTER MERGE  wards list for that account: []
```

**Derive "can this account sign in" from what Fortify checks.** Read
`User::isDisabled()` rather than inlining `disabled_at`.

### 3. Guardian rows are the WRONG measure of an account's reach

This is the finding that killed the feature, and the one most likely to be
repeated, because the wrong measure looks obviously right.

The removed code decided whether an account was safe to disable by counting
**live guardian rows** for that user (`orphanedUserIdsAfterMerge`,
`remainingGuardianSchoolIdsFor`). A user with no other guardian row was declared
"school-exclusive" and disabled.

But a `users` row holds school access through **`school_user` plus a team-scoped
role** (`User::grantSchoolAccess`), which has nothing to do with guardian rows. A
teacher who is also a parent has one account, one guardian record, and a staff
role at another school that guardian-row counting cannot see. The reviewer
demonstrated both halves:

```
PROBE A — donor is a teacher at school#B on the same users row
  "donor school_exclusive (guardian rows only)" => true
  "donor remaining_school_ids"                  => []
  "donor action"                                => "disable"
  "donor still has school access to school#B"   => [2]
  "donor disabled_at set (staff login killed)"  => true

PROBE B — keeper is a teacher at school#B, and school#B deliberately disabled the account
  "keeper_school_exclusive"                          => true
  "keeper_other_school_ids"                          => []
  "keeper_re_enable_blocked"                         => false
  "keeper still has school access to school#B"       => [4]
  "keeper disabled_at now NULL (revocation undone)"  => true
```

**Both merges completed with no refusal, and neither school appeared anywhere in
the plan.** Probe A ends a teacher's staff login as a side effect of a parent
cleanup. Probe B undoes another school's deliberate revocation, recorded only as
a `login_enabled` on a guardian in the acting school — a trail the revoking
school cannot see.

**The right measure is the account's own access**, not the guardian records
hanging off it: union `$user->schools()` (the `school_user` pivot) with
`model_has_roles.school_id` for `model_type = 'App\Models\User'`, and treat any
school other than the one you are acting in as a blocker. Re-derive that pivot
and that filter against the schema before you rely on this sentence — the
doubled-backslash `model_type` filter is a known trap.

**These two probes are arms this branch must pass.** Write them first.

### 4. The donor half and the keeper half are two writes, and both need gating

`applyLoginConsolidation` did `$user->update(['disabled_at' => null, 'password' => …])`
on the keeper and `disableLogin()` on the donor. Round 4 gated the donor and
shipped; round 5 found the keeper half ungated, in the same function, with the
fact needed to gate it already computed and used only to word a message.

Gate on **the write being reachable**, not on a property. An already-enabled
keeper has no `disabled_at` to clear, so there is nothing to refuse and refusing
anyway is noise that gets a guard switched off. A guard that fires where nothing
is at stake is worse than no guard, because it trains people to remove it.

### 5. The notification is best-effort and swallowed

`notifyGuardian` returns silently when the address is undeliverable and wraps the
send in `try { … } catch (\Throwable) { Log::error(…) }`. The removed code called
it and ignored the result; the command then printed `APPLIED.` and exited 0.

So the safety argument — "we disabled their old account, but we told them" —
rested on something that can fail without anybody noticing. **The deliverability
check asks whether an address EXISTS. The argument needs it DELIVERED. Only the
first was ever mechanised.** Decide explicitly how a failed send surfaces: a
non-zero exit naming the account, a queued job with retries whose id the command
prints, or an accepted, documented residual. Do not leave it implicit.

Related, and inherited: `notifyGuardian` names the school from
`optional($user->school)->name` — i.e. `users.school_id`, which Constitution 13
forbids as a context source. Any new caller that has an authoritative
`ActiveSchool` context and does not pass it is introducing a **fresh** divergence
rather than inheriting a legacy one. Pass the school.

### 6. "Can this account authenticate" is a SNAPSHOT of two facts, and both can be cleared from outside this school

Raised by the cold review of the merge branch after consolidation was removed, and it lands here
rather than there because it is a specification point for you, not a loose end of the merge.

`guardians:merge` refuses when a donor account is enabled **and** has a password set. Both halves are
account-global and mutable after that decision is taken:

- **`disabled_at` can be cleared by another school.** A donor absorbed *because* it was disabled is
  soft-deleted here. If that person still holds a live guardian record at another school, an
  `enableLogin` there clears `disabled_at` globally — and the account then signs in against nothing in
  this school. The empty-portal state the refusal exists to prevent, reached by a later transition
  instead of by the merge.
- **An empty password is not a lock.** `Password::sendResetLink` resolves a user by email as an
  *identity key* — that is the whole reason the deliverability invariant exists. A donor with a real
  address and an empty password reads "cannot authenticate" today and can set one tomorrow.

**This is the branch's own general rule applied to time rather than to rows:** a guard scoped to the
state in front of it, while the reach of what it permits extends past that moment.

It is a genuine fork and you have to take it deliberately:

1. **Accept the snapshot** — decide that "cannot authenticate at merge time" is the contract, and
   write that into the refusal's docblock so the next reader knows it was chosen rather than missed;
   or
2. **Widen the predicate** to "could this account be *made* to authenticate" — enabled or
   re-enablable, password set or resettable — which in practice means treating almost any account with
   a deliverable address as live, and is a much broader refusal.

Whichever you choose, the arm is the same shape: absorb a dormant donor, then perform the external
transition (re-enable from the other school, or set a password) and assert the resulting state is the
one you decided on. Nothing in the current working set takes either path — all 28 accounts across the
14 groups are enabled with a password, so today every group is refused on the first predicate — which
is exactly why it needs deciding rather than discovering.

---

## Two decisions that were never made, and are yours

Both were ticketed on the merge branch and are carried here rather than
rediscovered:

**The password rotation.** The removed code rotated the keeper's password
unconditionally, including when that account was working fine. The argument for:
consolidation cannot know which of the two accounts the person was using, so one
fresh credential for the survivor is the only instruction that is true either
way. The argument against: it invalidates a working password as a side effect of
a cleanup on a different record, and if the surviving account also serves another
school, that school's access is disrupted too by an email naming this school's
children. Name the choice in the docblock and let an arm pin it as a decision,
not as an invariant.

**Which account survives.** Nothing told the operator anything to choose `--keep`
by. On the copy at the time, all 14 groups carried two distinct addresses and
**0 of the 28 accounts had `email_verified_at` set** — nobody had activated
either account, so the choice was close to harmless. That stops the moment one
group contains an activated account: pick the wrong survivor and the address the
parent actually uses is the one you disable, and a reset link sent to it resolves
the disabled row, so the reset succeeds and the login still fails. Surface
`activated` (i.e. `email_verified_at` non-null) per account, recommend the
activated side, and refuse or warn when `--keep` names the unactivated side of an
activated pair. **Re-derive that zero before trusting it.**

---

## What NOT to do

- **Do not re-key off `can_login`.** Finding 2.
- **Do not measure an account by its guardian rows.** Finding 3. This is the one.
- **Do not gate one half of a two-sided write.** Finding 4.
- **Do not treat "an address exists" as "the person was told".** Finding 5.
- **Do not prescribe a remedy in a refusal message without checking it clears the
  check.** That error was made twice on the merge branch. One of the two
  prescriptions locked a parent out on the way to not working.
- **Do not put this back behind a flag on `guardians:merge`.** The reason it was
  removed is that a flag made a second feature look like an option on a first
  one, and every review round then found the second feature's defects inside the
  first one's blast radius.
- **Do not reset or re-seed the local database.** It is a production copy.

---

## Prove it

Suite runs on **MySQL**: `DB_DATABASE=portal_testing ./vendor/bin/pest`.

Arms, at minimum — the first two are the reviewer's probes and are not optional:

1. **Probe A**: donor account holds a staff role / `school_user` access at another
   school; consolidation refuses; `disabled_at` unchanged; the other school's
   access intact.
2. **Probe B**: keeper account is disabled and holds access at another school;
   consolidation refuses; `disabled_at` stays set.
3. Donor and keeper both single-school; consolidation proceeds; donor disabled,
   keeper enabled, notification sent exactly once, after commit.
4. Consolidation refused when the surviving account has no deliverable address.
5. A rollback mid-apply sends nothing.
6. The audit trail uses the existing `login_enabled` / `login_disabled` events on
   the right subjects, not new merge-only ones.

**Watched red, required.** For each of the two probes: remove the guard, run the
arm, and show the other school's access actually changing — `disabled_at`
transitioning, and that school's records becoming reachable or unreachable — not
merely that an exception failed to throw. Restore, paste both.

⚠️ **Check the mutation is not inert before you write it up.** On the merge
branch, removing a refusal twice left the demonstration inert because a second
condition independently gated the same write, and the honest mutation was to
remove both halves. A red you watched that proves nothing is worse than no red,
because you will believe it.

---

## Stop and report

- if you cannot make a probe go red;
- if the right measure of account reach turns out to be something other than
  `school_user` + team roles — say so before building on it;
- if you conclude any finding above is wrong. **The code wins over this brief.**

---

## Not in scope

The merge engine itself — pivot moves, collisions, back-fill, single-primary,
the cross-school and active-school refusals, the audit trail, the orphan report.
It has been reviewed five times and held. Do not modify it beyond replacing the
terminal refusal's message when this branch gives it a route to point at.
