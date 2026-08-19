# Implementation report — `fix/guardian-create-duplicates`

**FULL-REVIEW TIER — subagent review attached; recommend a cold session before merge.**
This touches `school_id` isolation, a validation boundary, the guardian write path, a
login invariant and the drive fixture.

---

> **ROUND 4 — FINAL.** The lead is merging this branch. The reported duplicates were
> already fixed by hand on production, so the deliverable is **prevention** — this
> branch plus the uniqueness constraint that follows it. Two fix-tier findings from the
> third review are closed here (§ *Round 4*), the falsified `--stat` block is **deleted**
> rather than refreshed, and everything else is a ticket. Any finding the final review
> returns will be **ticketed, not fixed**, by instruction, so the residual risk is
> written down rather than iterated on.
>
> **ROUND 3.** Rewritten again after a second cold review, which returned two fix-tier
> findings — both in behaviour **this branch newly created**, neither named by me. They
> are closed here (§ *Round 3*). The drive undertaken to prove one of them turned up the
> most serious defect in this whole record, and it is **not this branch's**: student
> registration through the admin UI has been broken at `staging` since `6bfed87`
> — see the ticket named in *Findings raised, not fixed*.
>
> **ROUND 2.** This report has been rewritten after a cold review. Findings 1–4 are
> fixed here; 5–7 are dispositioned. **Four things I asserted in round 1 were wrong**
> and are corrected in place rather than appended — the tsc-ratchet red (§ *Correction
> 1*), the reuse path's handling of a submitted email (§ *Correction 2*), the update
> request refusing on field presence (§ *Correction 3*), and the pasted `git diff
> --stat` (§ *Correction 4*). Read those first.

## Headline

Done, with deviations and two premise corrections — one of which makes defect 3
strictly worse than the brief describes and is a live cross-school identity defect
on `staging` today. Branch `fix/guardian-create-duplicates`, based on `staging`
@ `e484a46` (NOT on `feat/guardian-merge-command`; nothing here depends on
`GuardianService::merge`). Four commits — the original and three fix rounds.

**Methods touched in `app/Services/GuardianService.php`, for the eventual merge with
`feat/guardian-merge-command` — final list:** the constructor (one added dependency);
`createGuardianWithUser` (which gained a fifth parameter,
`bool $confirmExistingAccount = true`); `attachToStudent`, whose existing-pivot branch
gained an activity record; and two NEW methods, private
`fillBlankGuardianFields` and public `attachUnlessAlreadyLinked`. Nothing else in that
file was edited — `merge` does not exist on this base and no other method's body was
touched.

---

## My own corrections — four round-1 claims that were wrong

Ahead of everything else, because three of them are things I asserted and one of them
would have damaged a gate if acted on.

### Correction 1 — the tsc ratchet is GREEN. My measurement omitted `--with-form`.

Round 1 reported "54 > baseline 42, red at clean `staging`, someone should own it".
That was wrong, and the way it was wrong is documented in the repository as its own
trap. `bin/quality:169-170` runs:

```
step "wayfinder:generate --with-form (must match vite.config.ts formVariants)"
check "wayfinder:generate" php artisan wayfinder:generate --with-form
```

and `bin/quality:161-168` says why, in its own words: *"11 files call `.form()`, and
the login page renders blank. It is not a harmless difference — it is a different
codebase, and the tsc baseline was calibrated against it, which is how a blank login
page sat inside a green ratchet."* I generated without the flag, so I measured a tree
missing every `.form` helper and counted its 12 resulting errors as drift.

Re-measured the way the gate does:

```
$ php artisan wayfinder:generate --with-form
$ pnpm run types:check > tsc.txt
$ grep -c "error TS" tsc.txt
42
$ php bin/ci-tsc-ratchet.php tsc.txt
tsc-ratchet: OK (42 == baseline 42).
```

**42 == 42, green, with this change applied.** The two invocations side by side: without
`--with-form`, 54; with it, 42. Nothing was baselined in either direction, and the
round-1 recommendation is **withdrawn** — acting on it would have regenerated the
baseline at 54 and loosened the type floor by 12 permanently.

The general lesson, stated as a rule so it can be checked: **a gate's number is only
meaningful when produced by the gate's own command.** I reproduced one step of
`bin/quality` from memory instead of reading the step, and a report is not evidence
that the reader can distinguish from a measurement.

### Correction 2 — the reuse path silently discarded a submitted email

Round 1 shipped this and did not see it. When the match came from **phone**, `$user`
is the reused guardian's user, so the email branch never runs; and
`fillBlankGuardianFields` walks `Guardian`'s fillable, which has no `email` — the
address lives on `users`. A phone-matched reuse carrying a freshly typed address
therefore stored it nowhere and answered **201 with `reused_existing_guardian: true`**.
That is the branch's own defect — a write reported as saved and dropped — reappearing
on the path the branch added. Fixed: the reuse path now REFUSES rather than writing or
dropping, for the reasons set out under *Round 4* and *Deviations*.

### Correction 3 — the update request refused on field PRESENCE, which is a lockout

Round 1 replaced a silent strip with `abort(403)` keyed on `$this->request->has($field)`.
`edit-guardian-modal.tsx` prefills the form from the record (`:60-79`) and posts every
non-empty key (`:138-141`), and `phone` is required and therefore always present — so
an actor holding `guardian.update` without `guardian.update_credentials` could not save
**anything**, including an occupation-only edit, and was told to remove a field the
modal gives no way to omit. Item 20's intent was to replace a false success with an
honest refusal, not with an unconditional one. Fixed; now keyed on an attempted change.

### Correction 4 — the pasted `git diff --stat` was not this branch's

Round 1 presented a hand-summarised stat under a heading claiming it had been read
against my own model of the change. It omitted the new matcher, the new test file, the
report, the brief and the screenshots, and several counts differed. The conclusion (no
unrelated file swept) happened to hold, but **the control did not perform** — a
summarised stat cannot catch a Pint sweep, which is the only thing it is for.

**Round 4 finished this off by deleting the pasted block entirely** rather than
refreshing it for a fourth time — the derived table in *What changed* is now the only
copy. See *Round 4*, fix 3.

---

## Round 4 — the third review's two fix-tier findings

Both were on the **reuse backstop** again: paths this branch introduced reuse to and did
not then re-examine. Four rounds in, that is the pattern and it is the most useful thing
in this record — **every** fix-tier finding has been in code this branch *added*, never
in code it merely touched, and each survived because the arms varied the wrong dimension.

### Fix 1 — `attach` had no already-linked guard

The guard shipped in `store` and nowhere else, so `GuardianController::attach` — the
per-child Add Guardian modal — kept the whole defect for a round. It reuses a guardian
exactly as `store` does (on `mode=new` through `createGuardianWithUser`, on
`mode=existing` by definition) and then rewrote an existing link from that form's
defaults: login unticked meant a **silent revocation**, ticked meant a password rotation
and an email. And it is the screen that renders `GuardianDuplicateBanner` via
`GuardianRow`, so the operator proceeded on the banner's written promise that *"any child
already linked to them is left exactly as it is"*.

**The guard is now one method** — `GuardianService::attachUnlessAlreadyLinked` — and both
call sites use it. That is the actual lesson: the rule was right in round 3 and the
*copy* was the defect. Two inline copies drift, and this one drifted before it was ever
written twice.

Applied to **both** modes of `attach` deliberately. `mode=existing` means "attach this
person to this child", not "edit the link you already have"; POST adds, and `updatePivot`
(PUT) is where a link changes — permissioned, audited, record on screen.

### Fix 2 — an ambiguous phone match picked arbitrarily, on the common path

`emailRefutesMatch` returns false the moment the submitted email is empty, and the phone
branch was a bare `->first()` on an unordered query. Because `email` is required only
when portal login is on, **a phone-only submission is the ordinary one**, not an edge —
so the arbitrary pick sat on the common path, and `fillBlankGuardianFields` then
discarded the typed name in favour of whichever row came back.

Sized before deciding, read-only on the production copy: **14 (school, phone) groups
already hold more than one guardian row, covering 28 of 776.** Against that, the case
round 2 *did* refuse — a matched row with no stored address — affects **0** guardians
today. The priorities were inverted.

**The rule, stated once and now applied three times: a create form does not resolve
ambiguity on the operator's behalf.** It refused an email-versus-phone conflict, it
refused to write an identity key onto a reused account, and it now refuses an ambiguous
phone. Where the evidence does not single out one person the write refuses, and the
operator chooses from the duplicate-check banner — the surface built to show them the
candidates. A **single** phone match is still reused without ceremony; the refusal is
about ambiguity, not about phones.

Two consequences worth naming:

- **An email naming one of the phone candidates DISAMBIGUATES rather than conflicts**,
  and is checked before the refusal. The operator supplied the very evidence that singles
  a row out; refusing then would be refusing to read what they typed. Its own arm.
- **Every matcher query is now `orderBy('guardians.id')`**, including single-row ones.
  Not a tie-break — a tie is refused, not broken — but so two runs of the same query
  cannot disagree about what the candidate set *is*.

`AmbiguousPhoneMatchException` **extends** `ImportConflictException`, so the spreadsheet
import's single catch is untouched: an ambiguous row fails with its own message instead
of 500-ing, and no second catch has to be remembered. The interactive callers catch the
subclass first, because the message belongs on `phone`, not `email`.

### Fix 3 — the falsified `--stat` block is deleted, and I now know why it went stale

Deleted, not refreshed — see *What changed*: one derived source, no duplicate.

**And the mechanism behind the third review's finding 4 turned out to be mine.** That
finding observed two cross-references pointing at a section that does not exist. The
cause: the script I used to regenerate the *What changed* table replaces everything
between `## What changed` and `## Proof`, and the round-2 and round-3 narrative sections
sat inside that window. Regenerating the table **silently deleted the prose the
references pointed at**, and the commit went out that way. The automation I introduced to
stop one class of error in this document quietly introduced another.

This section is therefore placed **above** `## What changed`, outside the regeneration
window, and the two dead references are removed rather than left dangling. Written up in
full at
`docs/handoff/tickets/guardian-branch-report-cross-references-and-undescribed-drive-shots.md`.

---

## Contradictions of the premise

Put first because one of them changes what the defect is.

### 1. Defect 3's email-less mechanism is the REVERSE of what the brief says, and worse

The brief (§ finding 3) says:

> With no email, `$userEmail = null` (line 252) and `User::where('email', null)`
> never matches under MySQL, so every email-less submission mints a fresh User
> **and** a fresh Guardian.

`where('email', null)` does **not** compile to a never-matching `email = NULL`.
Laravel's query builder short-circuits a null value into a null-check —
`vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:983-987`:

```php
// If the value is "null", we will just assume the developer wants to add a
// where null clause to the query. …
if (is_null($value)) {
    return $this->whereNull($column, $boolean, ! in_array($operator, ['=', '<=>'], true));
}
```

So the pre-fix line was `WHERE email IS NULL`. Three facts make that reachable and
severe:

- `users.email` has been **nullable** since
  `database/migrations/2026_08_04_160000_make_users_email_nullable.php`, and the
  synthetic-address mint was retired at the same time
  (`GuardianService.php:242-252`), so email-less `users` rows genuinely exist.
- `User` is **explicitly exempt from `SchoolScope`** —
  `app/Models/Scopes/SchoolScope.php:33-37`, *"Users are identities, not tenant
  data"* — so that lookup ran unscoped across every school.
- The very next line is `$user->grantSchoolAccess(School::findOrFail($schoolId),
  'guardian')` (`:270`), which writes a `school_user` pivot **and** a team role.

Net effect before this change: **every email-less guardian creation bound itself to
whichever email-less `users` row the database returned first — a different person,
possibly in another school — and then granted that account access to this school.**
Two unrelated parents share one login identity; `users.disabled_at` is
account-global, so disabling one revokes the other's access everywhere.

I did **not** stop and wait on this, and that is a judgement call the reviewer
should check. The reason: the corrected premise does not change the prescribed fix's
direction — it makes it more necessary — and the fix (never match on a null email)
is one clause. Stopping would have left a live isolation defect unpatched for a
round. It is pinned by its own arm and its own watched red (arm 5b below).

### 2. The `guardian-sub-form.tsx` label is NOT inverted (brief item 18)

The brief says the label *"I don't have any other child in this school"* labels
`mode = 'new'` and calls it inverted. Reading `toggleMode`
(`resources/js/components/students/guardian-sub-form.tsx:253-263`): checked ⇒
`mode: 'new'`, and "this parent has no other child here" ⇒ "this parent is not yet a
guardian here" ⇒ new. **The polarity is correct.** What is actually wrong is the
voice: it is written in the guardian's first person while an administrator is the
one reading and filling it, so "I" is the one person who is definitely not at the
keyboard. Reworded to *"This guardian is new to this school"* with the polarity and
the helper text's meaning unchanged. **I did not invert it**, which is what a
literal reading of item 18 would have produced.

### 3. The existing test for brief item 20 passes vacuously (new finding)

`tests/Feature/GuardianManagementTest.php:240-266` asserts a hard 403 when a
`registrar` submits an email change, and carries a 2026-07-21 ruling in its comment.
That test does **not** exercise the credential gate. `registrar` holds no route
access at all — `database/seeders/RbacSeeder.php:299-306`, *"No route access:
registrar appeared in no pre-swap role: group, so it reaches no role-gated route"* —
so its 403 comes from the route group's own `permission:academic_setup.manage`
middleware, before `GuardianUpdateRequest` is ever constructed. The assertion holds
identically with or without any credential logic in that class. So the brief's item
20 premise (silent strip → 200) stood, and the test that appeared to cover it never
did. I left that test untouched and added an arm that acts as a role which actually
reaches the controller.

Everything else in the brief's finding reproduced exactly, at the cited lines:
`GuardianController.php:171-183` (unvalidated `student_links`, `if ($student)`
silent skip, `?? 'other'`), `add-standalone-guardian-modal.tsx:62-68` and `:75`
(422-only handler, exact-key errors), `GuardianRequest.php:51`
(`Rule::unique('users','email')` on create), `GuardianImportService.php:237-283`
(`lookupExistingInDb`), `student-guardians-panel.tsx` `console.error('Pivot update
failed')`, and `GuardianService::update`'s `array_filter(… ! is_null …)`.
`grep -rn student_links tests/` returned nothing — the path had zero coverage.

---

## Deviations from the brief

1. **Arm 3's assertion is stronger than the brief specified, because the brief's
   version could not see what it was written to protect.** As specified (422 +
   `Guardian::count()` unchanged + no pivot) the arm **still passed with the
   `->where('school_id', …)` predicate deleted** — watched, not assumed. There are
   two guards on that path, and both answer 422 on the same error key, so status and
   key together cannot distinguish them. The arm now also pins the framework's own
   `exists()` message, which is what makes the predicate's removal red. Full
   evidence under *The watched red*.

2. **Arm 2 does not exercise the transaction; a new arm 2b does.** The brief said
   removing the transaction wrapper would fail arm 2 on the guardian count. It does
   not — watched: arm 2 stayed green, because a typo'd admission number is now
   rejected by `GuardianRequest` **before** the controller runs and nothing is ever
   written. The transaction is only load-bearing for a post-validation failure, so
   arm 2b manufactures the reachable one (`can_login=true` with an
   `@no-email.local` address clears every rule, then `attachToStudent`'s login
   invariant refuses at the pivot write). Arm 2b goes red on the guardian count.

3. **The matcher is `App\Services\GuardianMatcher`, not a
   `GuardianRepository::findMatchInSchool`.** `GuardianRepository`'s four existing
   finders are all deliberately looser — each matches `guardians.school_id = X OR
   the guardian's user has access to X` (`GuardianRepository.php:29-32`, `:52-55`) —
   because they answer *"can this actor reach this record"*. The matcher answers
   *"is this person already a guardian row owned by this school"* and must be
   strictly `school_id = X`, because its consumer is a write. Two predicates
   differing by one OR branch as sibling methods on one repository is the shape of
   the next defect.

4. **The duplicate-check plumbing is a second hook in the SAME file, not a second
   hook module.** Brief item 8 says reuse `use-guardian-lookup.ts` rather than a
   second hook. Literal reuse is impossible — different endpoint, different scope
   (all schools vs active school), different response shape, and the lookup endpoint
   has no notion of the "account exists but is not a guardian here" case.
   `useGuardianDuplicateCheck` is appended to `resources/js/hooks/use-guardian-lookup.ts`
   so both definitions of "find this guardian" live in one module and cannot drift
   across files.

5. **`duplicateCheck` carries NO in-method `Authz::abilityCheck`.** Brief item 7
   says "gated by the same ability as `GuardianController::lookup`". `lookup`
   (`:76-116`) carries no in-method check at all; its only gate is the route group's
   `permission:academic_setup.manage`. Adding a `guardian.view` check here would make
   the new endpoint **stricter** than the sibling it was told to match — an access-
   surface change that belongs in its own reviewed commit. The route sits beside
   `lookup` in the same file and group, so the stacks are identical.

6. **Brief item 21 is a ticket, not a fix.** `GuardianService::update`'s
   `array_filter($attributes, fn ($v) => ! is_null($v))` (`:453-456`) means a field
   cannot be cleared to null. It does **not** fall out cleanly: whether the edit
   modal sends nulls/empty strings for untouched optional fields decides whether
   removing the filter blanks data, and that needs a drive of the edit screen, which
   is not this brief's screen. Filed below under *Findings raised, not fixed*; no
   ticket file written (see *Not done*).

7. **The drive fixture was extended, in this commit, as the brief's own drive
   section requires.** Not one seat in `DriveCastSeeder` could open `/guardians` —
   every seat is a finance seat and that route group needs `academic_setup.manage`
   plus `guardian.create`. Added `admin@drive.test` (School A) and `admin-b@drive.test`
   (School B, the isolation seat — `school-b@drive.test` holds `accounts_officer`
   and would 403 before proving anything). Added `Students` and `Guardians` columns
   to the count table, plus a printed list of the generated admission numbers, since
   the screen authors by admission number and the numbers are unknowable from the
   seeder source. Per `finance-drive`: a screen depending on something the table does
   not count needs a column before it needs a browser.

8. **One pre-existing lint violation in a file I touched had to be fixed to pass the
   gate.** `student-guardians-panel.tsx`'s `fetchGuardians()` call inside the open
   effect is a `react-hooks/set-state-in-effect` error **at HEAD** (verified by
   stashing). `bin/lint-changed.sh` has no ratchet, so it becomes ship-blocking the
   moment the file is touched. Replaced with a microtask hop, commented in place.

---

## What changed

**DERIVED, NOT MAINTAINED, and now the ONLY copy.** Every hand-written version of this
fact was wrong — round 1 summarised it and presented the summary as output, round 2
carried round-1 numbers, round 3 re-pasted a round-2 snapshot under a stronger claim.
A reviewer caught it all three times. It is generated from
`git diff --numstat e484a46...HEAD` at the moment of writing, and the second,
hand-pasted `--stat` block that used to sit in *Proof* has been **deleted** rather than
refreshed: a duplicate of a number is not corroboration, it is a second thing to keep
true, and this one never once was.

| File | +/− | What |
| --- | --- | --- |
| `app/Console/Commands/SeedDriveFixture.php` | +28 / −4 | Students/Guardians columns, printed admission numbers, three new seat rows. |
| `app/Http/Controllers/GuardianController.php` | +273 / −40 | `duplicateCheck` + masking; `store` made atomic, validated, school-pinned, no silent skip, confirmation control, already-linked guard; `attach` given the same guard. |
| `app/Http/Controllers/StudentController.php` | +80 / −22 | Re-keys guardian refusals onto the row; reports `reused_guardians`. |
| `app/Http/Requests/GuardianRequest.php` | +155 / −26 | `student_links.*` rules incl. the `school_id`-pinned `Rule::exists` and `max:50`; unique-on-create removed; trim; duplicate-row check; `confirm_existing_account`. |
| `app/Http/Requests/GuardianUpdateRequest.php` | +141 / −29 | Refuses on an ATTEMPTED CHANGE to a credential field instead of stripping it and answering 200. |
| `app/Services/AmbiguousPhoneMatchException.php` | +16 / −0 | NEW. Subclass of ImportConflictException, so the import’s single catch is untouched. |
| `app/Services/GuardianImportService.php` | +7 / −44 | `lookupExistingInDb` is now a one-line delegate to the extraction. No call site or catch changed. |
| `app/Services/GuardianMatcher.php` | +214 / −0 | NEW. The one definition of "same person in this school"; `emailRefutesMatch`; ordered queries; an ambiguous phone refuses rather than picks. |
| `app/Services/GuardianService.php` | +304 / −15 | Reuse + blank-fill; never matches a null email; email refusal; account-binding control; the pivot-update audit record; `attachUnlessAlreadyLinked`. |
| `database/seeders/DriveCastSeeder.php` | +64 / −0 | Three drive seats: two admins and the partial guardian editor. |
| `resources/js/components/guardians/add-standalone-guardian-modal.tsx` | +349 / −55 | Non-422 handling, per-row nested errors, duplicate check on blur, confirm checkbox, reuse toast. |
| `resources/js/components/guardians/guardian-duplicate-banner.tsx` | +177 / −0 | NEW. The warning, the confirm control, and the corrected promise. |
| `resources/js/components/students/guardian-sub-form.tsx` | +69 / −5 | Flat error fallback (the safety net), duplicate banner, reworded label. |
| `resources/js/components/students/student-guardians-panel.tsx` | +283 / −108 | Pivot failures surfaced instead of `console.error`. |
| `resources/js/hooks/use-guardian-lookup.ts` | +137 / −1 | `useGuardianDuplicateCheck` — debounced, abortable, unmount-safe, fails silent. |
| `routes/endpoints/guardian.php` | +3 / −0 | `GET /guardians/duplicate-check`, beside `lookup`. |
| `tests/Feature/Guardian/GuardianCreateDeduplicationTest.php` | +1110 / −0 | NEW. 29 arms. |

Totals: **58 files changed, 5431 insertions(+), 349 deletions(-)** overall; **17 files changed, 3410 insertions(+), 349 deletions(-)** counting only `*.php`,
`*.ts` and `*.tsx`. The remainder is this report, the brief, nine tickets and the drive
screenshots.

## Proof

All raw. MySQL, `DB_DATABASE=portal_testing`.

**The new file, alone:**

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Guardian/GuardianCreateDeduplicationTest.php
{"tool":"pest","result":"passed","tests":13,"passed":13,"assertions":65,"duration_ms":13655}
```
(13 before arm 2b was added; 14 after — see the directory run below.)

**Everything Guardian, after the extraction:**

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest --filter=Guardian
{"tool":"pest","result":"failed","tests":96,"passed":94,"assertions":284,"duration_ms":39892,"failed":2,
 "failures":[… GuardianProfileTest::it sends a password reset notification to the guardian email …,
             … GuardianProfileTest::it returns empty activity list when no events exist …]}
```

Both failures are in `tests/ratchet-baseline.txt:6-7`. **The import suite is green
without any modification**, which is the brief's stop-condition:

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Import tests/Feature/GuardianCrossSchoolImportTest.php
{"tool":"pest","result":"passed","tests":6,"passed":6,"assertions":24,"duration_ms":10198}
```

**Full suite + ratchet:**

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest --log-junit junit.xml
{"tool":"pest","result":"failed","tests":1683,"passed":1666,"assertions":7023,"duration_ms":477406,"failed":6,…,"errors":1,"skipped":10,"risky":3}

$ php bin/ci-test-ratchet.php junit.xml
ratchet: OK — no new failures beyond the baseline (7 known-failing).
```

The 7 failures are exactly the 7 baseline entries, name for name:

```
FAIL  ActivityLogApiTest :: it blocks users without activity_log.view
FAIL  ActivityLogApiTest :: it returns a paginated scoped feed
FAIL  ActivityLogApiTest :: it does not leak activity across schools
FAIL  ActivityLogApiTest :: it hides sensitive entries without view_sensitive
FAIL  GuardianProfileTest :: it sends a password reset notification to the guardian email
FAIL  GuardianProfileTest :: it returns empty activity list when no events exist
ERROR AuthenticationTest :: users are rate limited
```

`GrantsConvergenceLintTest` did **not** appear. Nothing else ran concurrently.

**Gates:**

```
$ ./vendor/bin/pint <changed files, explicit list, empty-list guarded>
{"tool":"pint","result":"fixed","files":[
  {"path":"app/Http/Requests/GuardianRequest.php","fixers":["function_declaration","trailing_comma_in_multiline","unary_operator_spaces","not_operator_with_successor_space","binary_operator_spaces"]},
  {"path":"app/Http/Requests/GuardianUpdateRequest.php","fixers":["unary_operator_spaces","not_operator_with_successor_space","binary_operator_spaces"]},
  {"path":"app/Services/GuardianMatcher.php","fixers":["fully_qualified_strict_types","unary_operator_spaces","not_operator_with_successor_space","ordered_imports"]}]}

$ ./vendor/bin/pint app/Console/Commands/SeedDriveFixture.php database/seeders/DriveCastSeeder.php
{"tool":"pint","result":"passed"}

$ php bin/ci-authz-lint.php
authz-lint: OK — no new commented-out authorization checks (0 known).

$ php bin/ci-boundary-lint.php
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).

$ DB_DATABASE=portal_testing ./vendor/bin/pest --group=arch
{"tool":"pest","result":"passed","tests":32,"passed":32,"assertions":181,"duration_ms":5160,"warnings":2}

$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}
```

All re-run after round 2's edits; Pint reports `passed` on the changed-file list now
that round 1's reformatting is committed. Larastan caught one thing in round 2 worth
recording: `$existingGuardian->user?->email` was a **dead nullsafe**, because
`guardians.user_id` is NOT NULL. Changed to `->user->email` with the reason written in
the code rather than silenced.

Frontend, changed files only:

```
$ npx prettier --check <5 changed TS/TSX files>
Checking formatting...
All matched files use Prettier code style!

$ npx eslint <5 changed TS/TSX files>
(no output — clean)
```

**On reading the diff against my own model of the change.** There is no pasted
`git diff --stat` here any more, and its absence is deliberate. Three rounds running,
a hand-maintained copy of this fact was wrong — round 1 summarised it and presented the
summary as output, round 2 carried round-1 numbers, round 3 re-pasted a round-2 snapshot
under the *stronger* claim "the actual command, unedited". Each time the derived table
above was right and the hand copy was wrong, and each time a reviewer caught it.

So there is now **one source and no duplicate**: the *What changed* table is generated
from `git diff --numstat e484a46...HEAD` at the moment of writing. A second copy of a
number is not corroboration, it is a second thing to keep true — and this one was never
once true.

What the check is actually for — catching a Pint sweep — still holds and was still done:
of the sixteen code files, four are larger than the lines I wrote, each for a named
reason. `GuardianRequest.php` and `GuardianUpdateRequest.php` carried pre-existing
aligned-`=>` formatting that Pint normalises; `add-standalone-guardian-modal.tsx` and
`student-guardians-panel.tsx` were already Prettier-dirty at HEAD (verified by stashing),
so reformatting untouched lines is the price of touching them at all, which is the
changed-files gate's stated "legacy drift burns down as files are touched" design. No
file outside the derived table was modified.

**Frontend:** Prettier and ESLint clean on all five changed/added TS files (both
required a `--write`/`--fix` pass; `add-standalone-guardian-modal.tsx` and
`student-guardians-panel.tsx` were already Prettier-dirty at HEAD — verified by
stashing — so their diffs include reformatting of untouched lines).

### The tsc ratchet — GREEN, and round 1's red was my measurement error

See *Correction 1*. Measured the way `bin/quality` measures, with this change applied:

```
$ php artisan wayfinder:generate --with-form
$ pnpm run types:check > tsc.txt
$ grep -c "error TS" tsc.txt
42
$ php bin/ci-tsc-ratchet.php tsc.txt
tsc-ratchet: OK (42 == baseline 42).
```

Nothing baselined. Round 1's "54 > 42" came from generating **without** `--with-form`,
which drops every `.form` helper and manufactures 12 type errors — the trap
`bin/quality:161-168` names by hand.

---

## The watched red

Four mutations, each restored and re-verified green.

### 1. Delete the `school_id` predicate from `Rule::exists` (the isolation line)

First attempt, with the brief's arm-3 assertions (422 + counts):

```
--- MUTATION: school_id predicate deleted from Rule::exists ---
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":6,"duration_ms":9282}
```

**It did not go red.** Probed the payload:

```
PROBE-ERRORS: {"student_links.1.admission_number":["Student ADM72375 could not be found in this school. Nothing was saved."]}
```

— the **controller's** message. The second guard (the explicit `school_id` pin on
the `Student` lookup in `store`) caught it and returned the same status on the same
key. Defence in depth working, and an assertion that could not see the rule it was
written to protect. With the predicate restored the payload is the framework's:

```
PROBE-ERRORS: {"student_links.1.admission_number":["The selected student_links.1.admission_number is invalid."]}
```

Arm 3 now pins that message. Re-running the same mutation:

```
--- MUTATION: school_id predicate deleted from Rule::exists ---
{"result":"failed",…,"message":"Failed asserting that two strings are identical.
--- Expected
+++ Actual
-'The selected student_links.1.admission_number is invalid.'
+'Student ADM57175 could not be found in this school. Nothing was saved.'"}
```

Red, naming the right thing.

**Both application guards removed** (predicate *and* the controller's `school_id`
pin), to confirm a cross-school attach is genuinely what stands behind them:

```
--- MUTATION: BOTH school pins removed ---
Expected response status code [422] but received 500.
PDOException: SQLSTATE[45000]: <<Unknown error>>: 1644 guardian and student must belong to the same school
  … Illuminate\Database\…\InteractsWithPivotTable … insert into `guardian_student` …
```

A **third**, database-level guard — a MySQL trigger — is what actually stops the
row. Worth knowing: it surfaces as a **500**, because 1644 is not in
`bootstrap/app.php`'s mapped set. Both restored:

```
--- RESTORED ---
{"tool":"pest","result":"passed","tests":13,"passed":13,"assertions":65}
```

### 2. Remove the `DB::transaction` wrapper from `store()`

Against arm 2 as the brief specified it:

```
--- MUTATION: DB::transaction wrapper removed from store() ---
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":6,"duration_ms":10071}
```

**Green** — validation rejects the typo'd number before the controller runs, so
nothing is ever written and there is nothing to roll back. Arm 2b (post-validation
failure) against the same mutation:

```
--- MUTATION: DB::transaction wrapper removed from store() ---
{"result":"failed",…,"file":"…/GuardianCreateDeduplicationTest.php","line":147,
 "message":"Failed asserting that 1 is identical to 0."}
```

Red on the **guardian count**, not the status code — exactly what the brief asked
for. Restored, green.

### 3. Restore the un-guarded `User::where('email', $userEmail)` (the corrected premise)

```
--- MUTATION: restored the pre-fix User::where('email', $userEmail) with no null guard ---
{"result":"failed",…,"line":278,"message":"Expecting 3 not to be 3."}
```

The new email-less guardian's `user_id` equals the unrelated other-school
email-less account's id. The defect reproduces on demand. Restored.

### 4. Disable the guardian-reuse branch (the dedupe backstop)

```
--- MUTATION: guardian-reuse branch disabled (always Guardian::create) ---
{"result":"failed","tests":2,"passed":0,
 "…twice_with_an_email":"Failed asserting that actual size 2 matches expected size 1.",
 "…twice_without_an_email":"Failed asserting that actual size 2 matches expected size 1."}
```

Two guardian rows for one person — the reported defect. Restored.

### Round 2 — three more, one per new control

**5. Remove the finding-2 refusal** (`if (false && $existingGuardian && $userEmail && …)`):

```
--- MUTATION: the silent-email-drop refusal removed (finding 2) ---
{"result":"failed",…,"message":"Expected response status code [422] but received 201.
Failed asserting that 201 is identical to 422."}
```

Red, and red on exactly the shape the review described: a 201 for a submission whose
address goes nowhere. Restored.

**6. Remove the finding-3 confirmation control** (`if (false && ! $existingGuardian && $user && ! $confirmExistingAccount)`):

```
--- MUTATION: the existing-account confirmation control removed (finding 3) ---
{"result":"failed",…,"message":"Expected response status code [422] but received 201.
Failed asserting that 201 is identical to 422."}
```

Restored.

**7. Revert finding 4 to refusing on presence** (`fn ($field) => $this->request->has($field)`):

```
--- MUTATION: reverted to refusing on field PRESENCE (finding 4) ---
{"result":"failed",…,"message":"Expected response status code [200] but received 403.
Failed asserting that 403 is identical to 200."}
```

Red on the round-trip arm — the lockout, reproduced. Restored; all 20 arms green:

```
{"tool":"pest","result":"passed","tests":20,"passed":20,"assertions":106,"duration_ms":17776}
```

### Round 3 — three more, one per new guard

**8. Remove the already-linked guard** (`if (false && $linkExists)`):

```
--- MUTATION: already-linked guard removed (finding 1), pivot asserted first ---
{"result":"failed",…,"line":471,"message":"Failed asserting that false is true."}
```

The failing assertion is `(bool) $after->can_login` — **the silent revocation,
reproduced**. The arm's assertions are ordered pivot-state-first on purpose: ordered the
other way it goes red on the missing `already_linked` report and never reaches the
damage, which is the less useful message of the two. The sibling arm goes red on the
credential rotation, and names it exactly:

```
{"result":"failed",…,"message":"Failed asserting that two strings are identical.
-'$2y$04$oGRPBGEbCZLz6sth5zlueeFc4YdgAM3ulLnXF3k0WFgd3z3qt2Kr6'
+'$2y$04$KiwTeLVDv8VRE/nyslmdo.26Ow1q9b2tASxXMFf6dXERdrcaO2PDe'"}
```

Two different password hashes: the account's credential was rotated by a create-form
resubmission. Restored.

**9. Remove the re-keying from `processGuardianEntry`** (`throw $e;`):

```
--- MUTATION: re-keying removed from processGuardianEntry (finding 2) ---
Failed to find a validation error in the response for key: 'guardians.0.email'

Response has the following JSON validation errors:
{ "email": [ "This person is already a guardian in this school and has no email address on record. …" ] }
```

Red, and the payload shows exactly the key the registration form does not read.
Restored.

**10. Remove the pivot-update audit record** (`if (false && $before !== $after)`):

```
--- MUTATION: pivot-update audit record removed (point 4) ---
{"result":"failed",…,"line":547,"message":"Failed asserting that 1 is identical to 2."}
```

Restored. All 24 arms green.

### Round 4 — two more, one per new guard

**11. Revert `attach` to an unconditional `attachToStudent`:**

```
--- MUTATION: attach reverted to unconditional attachToStudent (fix 1) ---
{"result":"failed",…,"message":"Failed asserting that false is identical to true."}
```

Red on `already_linked`. Restored.

**12. Remove the ambiguous-phone refusal** (`if (false && $byPhone->count() > 1)`):

```
--- MUTATION: ambiguous-phone refusal removed (fix 2) ---
{"result":"failed",…,"message":"Expected response status code [422] but received 201.
Failed asserting that 201 is identical to 422."}
```

A 201 where the refusal should be: the arbitrary pick, reproduced. Restored; all 29
arms green.

**One further red was not planted by me and is worth recording**, because it is a
guard catching a real thing: `GuardianLoginInvariantTest`'s cardinality pin went red
on my first full Guardian run, reporting `GuardianRequest.php` as a third pivot
writer. Cause: a **comment** I wrote naming the pivot mutator, in a file that also
carries `'can_login'` as a validation rule, which is exactly the pair its regex
greps for. I reworded the comment. **I did not widen the guard's regex** — the
reword and the reason are recorded at `GuardianRequest.php:132-138`.

---

## The drive

Environment: `APP_ENV=drive`, database `portal_drive` (throwaway, created for this),
`php artisan serve --port=8001`, `pnpm run build` before seeding. Browser:
`puppeteer-core` against system Chrome, installed in a temp directory **outside the
repository** — `node_modules` was not mutated. Screenshots (11) in
`docs/handoff/drives/2026-08-19-guardian-create/`.

**Friction, paid here, worth recording:** `.env.drive.example:38` sets
`SESSION_DOMAIN=localhost`. Driving `http://127.0.0.1:8001` authenticates
successfully and then loses the session on the very next request — every page bounces
to `/login` and every API call 401s, which looks exactly like broken authentication.
Use `http://localhost:8001`. Separately, `admin@drive.test` lands on `/dashboard`
cleanly, unlike the finance seats' documented `/dashboard` 403.

### Fixture count table, pasted verbatim

```
Drive fixture seeded. Sign in at APP_URL with any user below (password: drive-password):
+--------------------------------------------+-------------------------+
| Role in the drive                          | Email                   |
+--------------------------------------------+-------------------------+
| Maker (accounts_officer)                   | maker@drive.test        |
| Full checker (executive_director)          | checker@drive.test      |
| Void-only checker (no credit-note.approve) | void-checker@drive.test |
| Super admin                                | super@drive.test        |
| School B bursar (isolation)                | school-b@drive.test     |
| Admin (guardians screen)                   | admin@drive.test        |
| School B admin (guardian isolation)        | admin-b@drive.test      |
+--------------------------------------------+-------------------------+

+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+----------+-----------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Students | Guardians |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+----------+-----------+
| A (school#1) | 1                 | 1     | 2            | 1             | 1                 | 3                 | 0                   | 8        | 0         |
| B (school#2) | 1                 | 1     | 2            | 1             | 1                 | 0                 | 0                   | 1        | 0         |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+----------+-----------+
 School A (school#1) admission numbers: ADM95722, ADM11534, ADM37081, ADM35600, ADM65025, ADM80898, ADM41582, ADM90914
 School B (school#2) admission numbers: ADM18334
```

`Payments (migrated) = 0` is the documented by-construction exemption, not an abort.
No other column is zero. `Guardians = 0` is the starting denominator for the counts
below.

### Seat 1 — `admin@drive.test` (admin, school#1)

**(a) Two admission numbers → two children actually listed.** This is the case the
school reported as "not saving".

```
=== Seat 1 — admin@drive.test (admin, school#1) ===
  relationship row0: true
  relationship row1: true
  after submit URL: http://localhost:8001/guardians/a289764a-03d9-4794-ac55-8dc2308dae8d
  errors on screen: []
  CHILDREN ON PAGE: ["ADM95722","ADM11534"]
```

Establishes: the modal submitted, the server answered 201 with a redirect, the
redirect resolved, and the new guardian's page renders **both** admission numbers —
not an empty list, which is the state the old code produced with the same 201.

**(b) One typo'd number → error on that row, and no guardian created.**

```
  --- (b) typo'd admission number ---
  guardians before: 1
  [console.error] Failed to load resource: the server responded with a status of 422 (Unprocessable Content)
  URL after submit (modal should still be open): http://localhost:8001/guardians
  ROW ERRORS RENDERED: ["The selected student_links.1.admission_number is invalid."]
  error is on ROW 2 input: The selected student_links.1.admission_number is invalid.
  guardians after: 1 (atomicity: unchanged -> true )
```

Establishes: the 422 is caught and rendered (the old handler dropped everything that
was not a 422 and rendered nested keys nowhere); the message is attached to the
**second** row's input, not to a page-level blob; and `1 → 1` is the atomicity
proof — the valid first link did not create a half-saved guardian.

**(c) Re-adding the same person → the duplicate banner, before submit.**

```
  >> REQUEST http://localhost:8001/api/guardians/duplicate-check?phone=08031110001
  << RESPONSE 200 {"data":{"guardians":[{"uuid":"a289764a-03d9-4794-ac55-8dc2308dae8d","full_name":"Adaeze Okonkwo","masked_email":null,"masked_phone":"••••••••••0001","student_count":3}],"account":null}}
  DUPLICATE BANNER: "… A guardian matching this already exists — link them to these students instead? | Adaeze Okonkwo · ••••••••••0001 · 3 children linked | Open their record | If you continue, the existing record is reused and only its empty fields are filled — nothing already recorded is overwritten. …"
```

The phone is rendered **masked** (`••••••••••0001`); the stored `+2348031110001`
never reaches the banner.

**A measurement artifact I nearly filed as a defect, recorded so nobody repeats it:**
the first run reported `DUPLICATE BANNER: null`. The harness set inputs
programmatically and dispatched `new Event('blur')`, which does not bubble; React
listens for `focusout` at the root and never saw it. With real typing and a real
`Tab` the banner renders, as above. Not a defect — a harness artifact.

**Proceeding anyway → the backstop reuses rather than duplicates.**

```
  proceeded anyway; guardians total now: 1 (reused, not duplicated -> true )
  CHILDREN NOW ON THAT GUARDIAN: ["ADM11534","ADM37081","ADM95722"]
```

One guardian row, now three children. Two submissions of the same person produced
one record — the reported defect produced three.

**Per-child modal (`/students/{uuid}` → Add Guardian), which shares `GuardianRow`
with student registration:**

```
  STUDENT PAGE title: Part, Paula — Student Profile - Brookstone School
  clicked: "Add Guardian"
  PANEL TEXT: "Add guardian for Part, Paula | Guardian 1 | Primary | Can log in | This guardian is new to this school(check this to create a new guardian record; uncheck if they already have a child here, to look them up) | Relationship | Select relationship | Father | Mother | Guardian | … "
  << dup-check 200 {"data":{"guardians":[{"uuid":"a289764a-…","full_name":"Adaeze Okonkwo","masked_email":null,"masked_phone":"••••••••••0001","student_count":3}],…
  LINK BUTTON PRESENT: true
```

The reworded label renders, the banner fires here too, and the one-click "Link this
guardian" button is present.

### Seat 2 — `admin-b@drive.test` (admin, school#2) — isolation, by id

```
=== Seat 2 — admin-b@drive.test (admin, school#2) — isolation ===
  seat 1 guardian uuids (school#1): ["a289764a-03d9-4794-ac55-8dc2308dae8d"]
  seat 2 guardian uuids (school#2): []
  disjoint by id: true
  DUP-CHECK seat2 for seat1 phone 08031110001 -> {"guardians":[],"account":null}
  DUP-CHECK seat1 for same phone       -> {"guardians":[{"uuid":"a289764a-03d9-4794-ac55-8dc2308dae8d","full_name":"Adaeze Okonkwo","masked_email":null,"masked_phone":"••••••••••0001","student_count":3}],"account":null}
  School B submitting School A's ADM95722 -> URL http://localhost:8001/guardians
  ROW ERRORS: ["The selected student_links.0.admission_number is invalid."]
  school#2 guardian uuids after the attempt: [] (unchanged -> true )
```

Side by side, by **id**: the same phone number returns one uuid for school#1 and an
empty set for school#2 — the matcher's `school_id` pin, not a label. School A's
`ADM95722` does not resolve for School B's seat, the error lands on that row, and
school#2's guardian set is still empty afterwards. Admission numbers are disjoint by
construction (`ADM95722…` vs `ADM18334`), so this is not the labels-are-identical
trap the fee-schedules drive documented.

### Round 2 re-drive — findings 2, 3 and 4, on a freshly reseeded fixture

`finance-drive` reloaded rather than worked from memory. Assets rebuilt, fixture
reseeded (`migrate:fresh`), server restarted. The count table is unchanged in shape;
the **admission numbers differ from the round-1 drive because the fixture was reseeded
and they are generated** — which is exactly why the command prints them:

```
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+----------+-----------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Students | Guardians |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+----------+-----------+
| A (school#1) | 1                 | 1     | 2            | 1             | 1                 | 3                 | 0                   | 8        | 0         |
| B (school#2) | 1                 | 1     | 2            | 1             | 1                 | 0                 | 0                   | 1        | 0         |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+----------+-----------+
 School A (school#1) admission numbers: ADM08552, ADM35658, ADM40200, ADM05034, ADM46763, ADM41936, ADM90484, ADM04324
 School B (school#2) admission numbers: ADM55182
```

**A THIRD FIXTURE SEAT WAS ADDED, and the first attempt at finding 4 failed without
it.** `guardian-editor@drive.test` holds `guardian.view` + `guardian.update` +
`academic_setup.manage` + `admin_area.access` and **NOT**
`guardian.update_credentials` — the exact shape no canonical role produces
(`RbacSeeder.php:153-164` bundles the two guardian grants; `:299-306` gives `registrar`
one without the other and no route access at all). Same justification and same shape as
the existing `void-checker@drive.test`: a fixture-local role holding a deliberately
partial set, because a partial holder is the case that breaks.

**The `admin_area.access` half is a finding in itself, paid for by a failed run.** The
`/guardians` PAGE is gated on `permission:admin_area.access` (`routes/web.php:353`)
while `/api/guardians*` is gated on `permission:academic_setup.manage`
(`routes/api.php:47`). A seat holding only the API grant signs in, reaches the page and
gets a **full-page 403** — which is what the first attempt produced, and it looks like a
broken login rather than a missing grant. Recorded below as a ticket.

#### Finding 3 — the confirmation is a control

`checker@drive.test` is a real staff account in school#1 and not a guardian, so it is
the honest subject.

```
=== FINDING 3 — the existing-account confirmation is a CONTROL, not prose ===
  seat admin@drive.test: landed http://localhost:8001/dashboard title="Dashboard - Brookstone School"
  guardians before: 0
  ACCOUNT PANEL: "… This address belongs to an existing account | c••••••@drive.test is already registered, but is not a guardian in this school. Continuing will attach this guardian to that account and give it access to this school. If it belongs to someone else — a colleague, say — disabling this guardian's login later will lock that person out everywhere. | Yes — this is the same person. Link this guardian to that account. …"
  CONFIRM CHECKBOX RENDERED: 1
  submit unconfirmed -> URL http://localhost:8001/guardians
  ERRORS: ["This email address already belongs to an account that is not a guardian in this school. Nothing was saved. Confirm that it is the same person — continuing links this guardian to that account and gives it access to this school."]
  guardians after unconfirmed submit: 0 (unchanged -> true )
  submit CONFIRMED -> URL http://localhost:8001/guardians/a28984c6-d1f8-4f7e-891c-c42c69fe0ddd
  guardians after confirmed submit: 1
```

What each line establishes: the panel renders with the address **masked**
(`c••••••@drive.test`, not the stored value); the checkbox exists and is the only one in
the dialog; submitting without it returns 422 with the refusal rendered on screen and
`0 → 0` guardians; ticking it and resubmitting returns 201 and `0 → 1`. Round 1's
version of this panel had the same prose and no checkbox, and the server bound the
account either way.

#### Finding 2 — refused, never dropped

```
=== FINDING 2 — a submitted email is REFUSED, never silently dropped ===
  created email-less guardian -> URL http://localhost:8001/guardians/… | total 1
  stored email on that guardian: [{"uuid":"a289854c-8e71-42f2-b651-ac98dc430519","email":null}]
  resubmit WITH an address -> URL http://localhost:8001/guardians
  ERRORS: ["This person is already a guardian in this school and has no email address on record. Nothing was saved. Open their record and add the address there, so the change is made against the account it affects."]
  total after: 1
  stored email after the refusal: [{"uuid":"a289854c-8e71-42f2-b651-ac98dc430519","email":null}]
```

Establishes: the first submission stores `email: null`; the second, on the **same phone**
and carrying an address, is refused on screen rather than answering 201; the guardian
count is unchanged at 1; and the address is still `null`, i.e. the refusal did not
half-write. Round 1 answered 201 here with the address discarded.

#### Finding 4 — the partial editor can save again

```
=== FINDING 4 — the partial editor seat ===
  page title: "Guardians - Brookstone School" | url http://localhost:8001/guardians
  loaded record          : {"email":"login.active@drive.test","phone":"+2348077770001","occupation":"Teacher"}
  PAYLOAD (modal shape)  : {"first_name":"Login","last_name":"Active","phone":"+2348077770001","email":"login.active@drive.test","occupation":"Nurse"}
  round-trip + occupation: 200 {"data":{"message":"Guardian updated successfully.","affected_student_count":0,…}}
  occupation after       : "Nurse"
  REAL email change      : 403 {"message":"Changing email for a guardian with an active login requires the \"guardian.update_credentials\" permission. Nothing was saved — remove it from your edit, or ask an administrator to make this change."}
  email after            : "login.active@drive.test"
```

The payload is **exactly** what `edit-guardian-modal.tsx` posts: every non-empty
prefilled key, with only `occupation` altered. Establishes: the seat reaches the screen;
the round-trip save returns **200** and the occupation actually changed to "Nurse" —
under round 1's presence check this same request was a 403 and nothing could be saved;
and a genuine email change is still **403** with the address unchanged. Both directions,
same seat, same session.

#### Measurement artifact, recorded so it is not rediscovered

Round 1's first attempt reported `DUPLICATE BANNER: null`. The harness set inputs
programmatically and dispatched `new Event('blur')`, which does not bubble; React
listens for `focusout` at the root and never saw it. Every round-2 field is filled with
real `page.keyboard.type` + `Tab`. Not a defect — a harness artifact, and it would have
been filed as a phantom.

### Round 3 re-drive — finding 1 on the same child, and finding 2's mechanism

Fixture reseeded again; count table unchanged in shape, admission numbers regenerated:

```
| A (school#1) | 1 | 1 | 2 | 1 | 1 | 3 | 0 | 8 | 0 |
| B (school#2) | 1 | 1 | 2 | 1 | 1 | 0 | 0 | 1 | 0 |
 School A (school#1) admission numbers: ADM40491, ADM86912, ADM46774, ADM12806, ADM54075, ADM71247, ADM24298, ADM25749
 School B (school#2) admission numbers: ADM75126
```

#### Finding 1 — the same child, twice, through the real modal

```
=== FINDING 1 — a create form may not rewrite an existing link ===
  login checkbox ticked: true
  first submit -> http://localhost:8001/guardians/a28992b5-c239-4aff-9ecd-fa01ab6a9e69
  STATE AFTER FIRST SUBMIT : {"guardian":"a28992b5-c239-4aff-9ecd-fa01ab6a9e69","total":1,"links":[{"adm":"ADM40491","rel":"father","primary":true,"can_login":true}]}
  BANNER TEXT: "If you continue, the existing record is reused: only its empty fields are filled, and any child already linked to them is left exactly as it is — including who is primary and whether they can log in. To change an existing link, open their record."
  second submit -> http://localhost:8001/guardians/a28992b5-c239-4aff-9ecd-fa01ab6a9e69
  STATE AFTER SECOND SUBMIT: {"guardian":"a28992b5-c239-4aff-9ecd-fa01ab6a9e69","total":1,"links":[{"adm":"ADM40491","rel":"father","primary":true,"can_login":true}]}
```

The second submission carried every value that would have downgraded the link:
relationship `other` instead of `father`, primary unticked, and the login checkbox left
**unticked** — the modal's default and the revocation vector. What each line establishes:
one guardian row and one link before and after; `rel`, `primary` and `can_login` byte-identical
across the two submissions; and the corrected banner sentence rendering verbatim, so the
promise on screen and the behaviour underneath now agree.

#### Finding 2 — the flat fallback, rendered

The registration screen itself could not be driven,
so the **mechanism** was driven where it is reachable: the per-child Add Guardian modal,
which posts through the same `createGuardianWithUser` refusal and renders through the
same flat-fallback shape that `guardian-sub-form` now has.

```
  << POST /api/students/a2899259-…/guardians -> 422 {"message":"There are validation errors","errors":{"email":["This person is already a guardian in this school and has no email address on record. Nothing was saved. Open their record and add the address there, so the change is made against the account it affects."]}}
  RENDERED ERROR TEXT: ["This person is already a guardian in this school and has no email address on record. Nothing was saved. Open their record and add the address there, so the change is made against the account it affects."]
```

The server raised the refusal under the **flat** key `email`; the screen resolved and
rendered it on the guardian row. That is the fallback doing its job, seen rather than
reasoned. The registration form's own rendering of the same message is **proven by test
only** (`guardians.0.email`) and was never seen, and that is stated rather than glossed.

### Round 4 re-drive — the two screens these fixes change, and nothing else

Fixture reseeded; count table unchanged in shape, admission numbers regenerated
(`ADM40001 …` for school#1, `ADM35840` for school#2).

#### Fix 2 — an ambiguous phone is refused, and the banner names the candidates

```
=== FIX 2 — an ambiguous phone is refused, and the banner names the candidates ===
  << POST /api/guardians -> 201 {"data":{"id":"a289a583-…","full_name":"Chidi Household","phone":"+2348077770001",…
  << POST /api/guardians -> 201 {"data":{"id":"a289a58d-…","full_name":"Ngozi Household","phone":"+2348077770001",…
  two household guardians created; total = 2
  BANNER CANDIDATES: ["Chidi Household · c••••@drive.test · ••••••••••0001 · 0 children linked Open their record",
                      "Ngozi Household · n••••@drive.test · ••••••••••0001 · 0 children linked Open their record"]
  << POST /api/guardians -> 422 {"message":"There are validation errors","errors":{"phone":["More than one guardian in this school already has this phone number. Nothing was saved. Check the duplicate warning above and open the right record, or use a number that identifies this person."]}}
  submit -> URL http://localhost:8001/guardians
  ERRORS ON SCREEN: ["More than one guardian in this school already has this phone number. Nothing was saved. Check the duplicate warning above and open the right record, or use a number that identifies this person."]
  total after refusal: 2
```

What each line establishes, in order: **two** guardians can legitimately share a
household number (the second creation succeeded because its differing email refuted the
phone match — `emailRefutesMatch` working, incidentally, on screen); the banner lists
**both** candidates with masked contacts, which is what makes the refusal actionable
rather than a dead end; a third person on the same number is refused with the message
rendered on the guardian form; and the total is **2 → 2**, so nothing was arbitrarily
reused and no third row was created.

#### Fix 1 — the same rule on the attach screen

```
=== FIX 1 — the same rule on the attach screen ===
  BEFORE: {"guardian":"a289a5fe-…","links":[{"adm":"ADM40001","rel":"father","primary":true,"can_login":true}]}
  relationship: Mother
  << POST attach -> 201 {"message":"This guardian is already linked to this student. Nothing was changed — open their record to edit the link.","already_linked":true}
  AFTER : {"guardian":"a289a5fe-…","links":[{"adm":"ADM40001","rel":"father","primary":true,"can_login":true}]}
```

The re-submission went through the **per-child** modal on the child's own page and
carried every value that would have downgraded the link: a different relationship, and
Primary and "Can log in" both left unticked — the modal's defaults and the revocation
vector. `rel`, `primary` and `can_login` are byte-identical before and after, the
response says plainly that nothing changed and where to go to change it, and — the point
of this fix — this is the screen that had the defect while `store` did not.

### Not driven

- **The student-registration screen's rendering of a guardian refusal.** Blocked by the
  pre-existing `_method = PATCH` defect, which makes `StudentController::store`
  unreachable from the admin UI entirely. Proven by test (`guardians.0.email`) and by the
  shared fallback mechanism rendered on the sibling modal; never seen on its own screen.
- **`StudentController`'s `reused_guardians` signal.** Returned by the controller and
  consumed by nothing yet — the registration form does not read it. Same status as the
  standalone modal's toast before round 2 wired it, and named here rather than left to be
  discovered.
- **`add-standalone-guardian-modal`'s reuse toast** (finding 6). Wired to `sonner` and
  asserted by reading, not by rendering: the redirect fires in the same tick and the
  harness would be racing it. Stated rather than claimed.
- **The `student-guardians-panel` pivot-error banner.** The code path is exercised
  only by a *failing* pivot update, and the fixture has no guardian in a state that
  makes one fail (the reachable failure is the login invariant, which needs a
  guardian with a non-deliverable address). The panel renders and the error state is
  unit-covered by nothing — see *Not done*.
- **`GuardianUpdateRequest`'s 403.** Test-covered (arm 9) but never rendered; the
  drive would need a fixture seat holding `academic_setup.manage` + `guardian.update`
  **without** `guardian.update_credentials`, and no canonical role has that shape.
- **The conflicting email-vs-phone 422** from `createGuardianWithUser`'s catch. Not
  test-covered either — see *Not done*.
- The `_general` non-422 banner (403/419/500 paths) — asserted by reading, not by
  rendering.

---

## Database observations

Under the privacy rule; ids and counts only.

- Drive DB `portal_drive` (throwaway), `school#1` and `school#2`.
- `school#1`: students 8, guardians 0 → 1 across the whole drive; that one guardian
  holds 3 pivots. Three separate create submissions for the same person produced
  **one** row.
- `school#2`: students 1, guardians 0 → 0. Unchanged by a submission naming
  `school#1`'s admission number.
- `users`: the drive cast now mints 7 seats (was 5).
- **Round 2 sized finding 5 read-only against the production copy.** One `SELECT`,
  no write, no migration, no seed:

  ```sql
  SELECT COUNT(*),
         SUM(phone IS NOT NULL AND phone NOT LIKE '+%'),
         SUM(whatsapp_number IS NOT NULL AND whatsapp_number NOT LIKE '+%')
  FROM guardians;
  ```

  | database | guardians | unnormalised `phone` | unnormalised `whatsapp_number` |
  | --- | --- | --- | --- |
  | production copy | 776 | **0** | **0** |
  | dev copy | 776 | **0** | **0** |

  Zero today, so the unnormalised-phone hazard is forward-looking: the first guardian
  edit that changes a phone creates the first affected row. That number is in the
  ticket, which is why the ticket is a claim rather than a guess.
- **Nothing else on a production copy was touched.** No migration, no seed, no write.

---

## Not done

- **`StudentController`'s registration path did NOT get the already-linked guard.** It
  is the third caller of `attachToStudent` and reaches it after a reuse, exactly as
  `store` and `attach` do — but on registration the student is brand new, so no pivot
  can pre-exist and the branch is unreachable in practice. Named because "unreachable
  today" is what was true of `store` before reuse landed, and that is precisely how this
  defect got in twice.
- **The registration form does not read `reused_guardians`**, which `StudentController`
  now returns. Wiring it is a UI change on a screen that currently cannot submit at all
  (the `_method` defect), so it waits for that ticket.
- **The `emailRefutesMatch` rule changes matcher behaviour for the IMPORT too**, since
  the import calls `createGuardianWithUser` after its own null match. A spreadsheet row
  whose phone matches an existing guardian and whose email differs now creates a second
  guardian instead of reusing. I believe that is correct — it is two people — and the
  import suite is green, but no arm asserts it **through the import**, only through the
  create path.
- **The confirmation control is scoped to `store`.** `GuardianController::attach` and
  `StudentController`'s registration path still bind to an existing account unguarded,
  exactly as at HEAD. Named choice, reasoned above, filed below.
- **No test asserts `duplicate-check`'s specific ability.** It inherits the group
  middleware and `RouteMiddlewareBaselineTest`'s second arm proves it carries *auth*,
  but the ability itself is unasserted.
- **No positive phone arm on `duplicate-check`.** Every phone assertion in the
  isolation arm asserts an EMPTY result, so the suite cannot currently distinguish a
  working phone match from a broken one. Called out in the ticket as part of closing
  it; not added here because it belongs with the normalisation fix it guards.
- **The `array_filter` null-clearing question is still open**, now with a ticket
  (`docs/handoff/tickets/guardian-update-writes-phones-and-cannot-clear-a-field.md`).
  Not fixed because it needs a drive of the guardian **edit** modal, a different screen.
- **Arm 9b characterises a known defect rather than asserting correct behaviour**: it
  pins that a round-tripped local-format phone is REWRITTEN in local format by
  `GuardianService::update`. Labelled in the test with a pointer to the ticket, so
  closing the ticket turns the line red instead of leaving the regression invisible.
  That is a deliberate choice and a reviewer should decide whether they like it.
- **Oracles were NOT regenerated.** `RouteAccessParityTest` and
  `RouteMiddlewareBaselineTest` both iterate fixture keys, so a new guarded route passes
  freely; `rbac:sync` was never run and no permission or grant changed. The new drive
  role is a fixture-local role in `DriveCastSeeder`, not a change to `RbacSeeder`'s map.
- **`.env.drive` exists in the working directory** (untracked, gitignored), built from
  the committed `.env.drive.example`. `.env` was never read.
- **`ImportConflictException` still has the wrong name** now that a non-import caller
  throws it. A behaviour-neutral rename for its own commit.

## Findings raised, not fixed

**Nine ticket files.** This is the residual risk, written down rather than iterated on,
which is the deliverable the lead asked for.

| Severity | Finding | Ticket |
| --- | --- | --- |
| **stop — for the product, not for this branch** | Student registration through the admin UI does not work: `student-form.tsx` appends `_method = PATCH` on create, so `POST /api/students` becomes a route that does not exist and 400s, and the catch handles only 422 so nothing renders. Present at the base commit, untouched here, and **invisible to the suite** because tests never send `_method`. | `student-registration-spoofs-every-create-to-PATCH.md` |
| **fix** | `StudentController`'s registration path binds a new guardian to an already-registered `users` row with no confirmation — `store` and `attach` are guarded, this one is not. | *(covered in Not done; no separate file)* |
| **fix** | The `/guardians` page is gated on `admin_area.access` while `/api/guardians*` is gated on `academic_setup.manage`; a role holding one without the other gets a full-page 403 that bounces to `/login` and reads as a broken login. | `guardians-page-and-api-are-gated-on-different-permissions.md` |
| **ticket** | `reissueCredentialsIfPossible` rotates a password and mails it from inside a transaction. Closed structurally for `/api/guardians` and now for `attach` too; still live on student registration and the import. Carries the related divergence that `attachToStudent`'s true→false transition does not cascade-disable while `updatePivot`'s does. | `mail-and-password-rotation-inside-transactions.md` |
| **ticket** | `GuardianService::update` writes phones unnormalised, and no field can be cleared to null. **Sized at 0 affected rows today.** | `guardian-update-writes-phones-and-cannot-clear-a-field.md` |
| **ticket** | `duplicate-check` answers "does this account exist" platform-wide, with the address in a GET query string. Changes the cost, not the class. | `duplicate-check-is-a-platform-wide-account-existence-oracle.md` |
| **ticket** | The new route is in neither route oracle — legitimately, but a future middleware change on it is therefore uncaught. | `duplicate-check-route-is-in-neither-route-oracle.md` |
| **ticket** | This report had two dead cross-references and three undescribed screenshots — **caused by my own table-regeneration script**, which deletes everything between two headings. Mechanism now recorded. | `guardian-branch-report-cross-references-and-undescribed-drive-shots.md` |
| **ticket** | `GuardianRequest`'s `$isUpdate` branch is dead; `GuardianMatcher` still uses `?->user` where `GuardianService` was changed to `->user` for the same NOT NULL column. | `guardian-matcher-and-request-tidy-ups.md` |
| **ticket** | `GuardianManagementTest:240-266` asserts the 2026-07-21 credential ruling but 403s from route middleware, never reaching the class under test. Vacuous; left untouched, covered by new arms. | *(no file)* |
| **ticket** | The same-school pivot trigger raises driver code **1644**, unmapped in `bootstrap/app.php`, so it surfaces as a 500. | *(no file)* |
| **ticket** | `student-guardians-panel.tsx`'s `fetchGuardians` still swallows load failures into `console.error`. | *(no file)* |
| **ticket** | `ImportConflictException` is now thrown from non-import callers and the name is wrong; `GuardianImportService` imports `App\Models\User` unused. | *(no file)* |
