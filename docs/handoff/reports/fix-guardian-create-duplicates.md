# Implementation report — `fix/guardian-create-duplicates`

**FULL-REVIEW TIER — subagent review attached; recommend a cold session before merge.**
This touches `school_id` isolation, a validation boundary, the guardian write path, a
login invariant and the drive fixture.

---

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
`GuardianService::merge`). Two commits — the original and the fix round.

**Methods touched in `app/Services/GuardianService.php`, for the eventual merge with
`feat/guardian-merge-command` — unchanged by round 2:** the constructor (one added
dependency), `createGuardianWithUser` (which gained a fifth parameter,
`bool $confirmExistingAccount = true`), and one NEW private method
`fillBlankGuardianFields` inserted immediately above
`assertLoginRequiresDeliverableEmail`. Nothing else in that file was edited —
`merge` does not exist on this base and no other method's body was touched.

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
on the path the branch added. Fixed below; see *What changed in round 2*.

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
summarised stat cannot catch a Pint sweep, which is the only thing it is for. The real
output is pasted in *Proof*, unedited.

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

| File | Δ | What |
| --- | --- | --- |
| `app/Services/GuardianMatcher.php` | +129 new | The one definition of "same person, already a guardian in this school". Behaviour moved from `lookupExistingInDb`; adds `PhoneNormalizer` on the lookup key. `candidatesInSchool()` (read surface, no adjudication) + `findInSchool()` (write surface, raises on conflict). |
| `app/Services/GuardianImportService.php` | −39 | `lookupExistingInDb` is now a one-line delegate. Constructor takes the matcher. No call site or catch changed. |
| `app/Services/GuardianService.php` | +100 | `createGuardianWithUser` resolves an existing guardian first, **never matches a null email**, reuses + blank-fills instead of always `Guardian::create`, returns `reused`. New `fillBlankGuardianFields`. |
| `app/Http/Requests/GuardianRequest.php` | +117 | `student_links.*` rules incl. the `school_id`-pinned `Rule::exists`; `Rule::unique('users','email')` now update-only; `prepareForValidation` trims admission numbers; `withValidator` rejects a repeated admission number. |
| `app/Http/Controllers/GuardianController.php` | +187 | New `duplicateCheck` + masking helpers; `store` wrapped in one `DB::transaction`, reads `validated()`, pins `school_id` on the student lookup, throws instead of skipping, drops `?? 'other'`, returns `reused_existing_guardian`. |
| `app/Http/Requests/GuardianUpdateRequest.php` | +64 | Refuses with 403 naming the submitted credential field instead of stripping it and answering 200. |
| `routes/endpoints/guardian.php` | +3 | `GET /guardians/duplicate-check`, beside `lookup`. |
| `resources/js/hooks/use-guardian-lookup.ts` | +122 | `useGuardianDuplicateCheck` — debounced, abortable, unmount-safe, fails silent. |
| `resources/js/components/guardians/guardian-duplicate-banner.tsx` | +128 new | The warning banner; masked contacts; the "existing account that is not a guardian here" case as its own panel. |
| `resources/js/components/guardians/add-standalone-guardian-modal.tsx` | +90 | Non-422 handling, 419 message, `_general` banner, per-row nested errors, blur-triggered duplicate check. |
| `resources/js/components/students/guardian-sub-form.tsx` | +50 | Banner + one-click switch to the existing-guardian flow; label reworded. Shared by the per-child modal and student registration. |
| `resources/js/components/students/student-guardians-panel.tsx` | +34 | Pivot failures surfaced instead of `console.error`; state re-read after a failure. |
| `database/seeders/DriveCastSeeder.php` | +24 | Two admin seats. |
| `app/Console/Commands/SeedDriveFixture.php` | +26 | Students/Guardians columns; printed admission numbers. |
| `tests/Feature/Guardian/GuardianCreateDeduplicationTest.php` | +380 new | 14 arms. |

---

## What changed in round 2

| Finding | Disposition | Where |
| --- | --- | --- |
| 1 — tsc ratchet | **Withdrawn.** Re-measured green with `--with-form`; nothing baselined. | *Correction 1* |
| 2 — email silently dropped on reuse | **Refused, not written.** Decision below. | `GuardianService.php`, `GuardianMatcher.php` |
| 3 — account binding had no control | **Server-side confirmation, fail-closed.** | `GuardianService.php`, `GuardianRequest.php`, `GuardianController.php`, banner |
| 4 — 403 on field presence | **Keyed on an attempted change.** | `GuardianUpdateRequest.php` |
| 5 — phones unnormalised on update | Ticket, **sized first**. | `docs/handoff/tickets/guardian-update-writes-phones-and-cannot-clear-a-field.md` |
| 6 — `reused_existing_guardian` unread | **Wired**, not deleted. | `add-standalone-guardian-modal.tsx` |
| 7 — wrong diff stat | Corrected, real output pasted. | *Proof* |

### Finding 2 — the decision, and why it went the way it did

The brief offered two options. **I chose to refuse, not to write**, and the reasoning
is the recurring defect class the brief itself names — *a guard scoped to the record
in front of it while the write reaches further*.

`users.email` is not a profile field. It is the sole authentication key
(`FortifyServiceProvider` resolves the account by it and by nothing else) and the
identity key `Password::sendResetLink` resolves. One `users` row backs a guardian in
**every** school that person has a child in (§6.2). So filling it from the create form
would let an operator who can see one school set the password-reset address for an
account reaching schools they cannot see, on the strength of a **phone** match, from a
form that never showed them the account. `fillBlankGuardianFields`'s "blanks are the
safe direction" principle is true for the guardian row's profile columns and false the
moment it is extended to a shared credential — that boundary is now stated in the code.

There is already a correct path: the update endpoint, gated on
`guardian.update_credentials`, with the record on screen and the change audited — and
the duplicate banner links straight to it. So the refusal is a redirection, not the
dead end the create-path unique rule was.

**One thing fell out of this that is worth more than the fix.** Deciding the "no stored
address" case forced the question of the "different stored address" case, and that one
is a live false-positive in the matcher I extracted: a household shares one landline,
so a phone match plus a *different* email is evidence of **two people**, and reusing
there would attach the mother's child to the father's record. `GuardianMatcher::emailRefutesMatch`
now makes a differing email refute a phone-only match, and the create proceeds as a new
guardian. That was not in the brief, not in the review, and is pinned by its own arm.

### Finding 3 — a control, not prose

`confirm_existing_account` is now a validated field on the create path, passed into
`createGuardianWithUser`, and the service refuses when the submitted email resolves to
a `users` row that is not already a guardian in this school. **Absent means not
confirmed**, so a client that never renders the banner — a stale tab, a script — fails
closed rather than binding by omission. The banner grew the checkbox that supplies it.

**The parameter defaults to `true`, deliberately, and that is the one place this
change is narrower than it could be.** Two other callers reach the same method —
`GuardianController::attach` and `StudentController`'s registration path — and both
bind to an existing account unguarded **today, at HEAD**, exactly as they did before
this branch. Defaulting to refusal would silently narrow two paths this change never
examined and never drove. Filed under *Findings raised, not fixed*, not hidden.

**Arm 7 was rewritten.** It previously pinned the binding as intended behaviour, which
is precisely what the review objected to; it now asserts the control in both
directions, and the refusal arm additionally asserts that the refused write did not
leave a `school_user` pivot behind — the reach a guardian count cannot see.

### A defect this round introduced and the tests caught

`$validated` was not in the `store` closure's `use` list, so
`$validated['confirm_existing_account'] ?? false` inside it evaluated to `false` for
every caller and the confirmation **could never be given**. It answered 422 forever,
which looks exactly like a working control until someone tries to proceed. The arm
that asserts the *confirmed* path is what caught it; the arm that asserts the refusal
was green throughout. Recorded because it is the argument for testing both directions
of a control rather than only the one it exists to block.

---

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

**Read `git diff --stat` against my own model of the change.** Round 1 pasted a
summary of this and called it the output; the actual command, unedited
(`git diff --stat e484a46...HEAD`), is:

```
 app/Console/Commands/SeedDriveFixture.php          |  32 +-
 app/Http/Controllers/GuardianController.php        | 245 +++++--
 app/Http/Requests/GuardianRequest.php              | 181 +++++-
 app/Http/Requests/GuardianUpdateRequest.php        | 170 ++++-
 app/Services/GuardianImportService.php             |  51 +-
 app/Services/GuardianMatcher.php                   | 165 +++++
 app/Services/GuardianService.php                   | 213 +++++-
 database/seeders/DriveCastSeeder.php               |  64 ++
 .../briefs/fix-guardian-create-duplicates.md       | 331 ++++++++++
 .../admin-01-guardians-list.png                    | Bin 0 -> 120611 bytes
 .../admin-02-add-modal.png                         | Bin 0 -> 151555 bytes
 .../confirm-01-account-panel-with-checkbox.png     | Bin 0 -> 183786 bytes
 .../confirm-02-refused-unconfirmed.png             | Bin 0 -> 196616 bytes
 .../confirm-03-accepted-after-tick.png             | Bin 0 -> 176931 bytes
 .../editor-01-guardians-list.png                   | Bin 0 -> 120103 bytes
 .../editor-02-after-edits.png                      | Bin 0 -> 120103 bytes
 .../email-01-duplicate-banner-before-submit.png    | Bin 0 -> 181010 bytes
 .../email-02-refused-not-dropped.png               | Bin 0 -> 192500 bytes
 ...olation-01-school-b-rejects-school-a-number.png | Bin 0 -> 171161 bytes
 .../maker-01-two-links-filled.png                  | Bin 0 -> 175789 bytes
 .../maker-02-guardian-page-two-children.png        | Bin 0 -> 187099 bytes
 .../maker-03-row-error.png                         | Bin 0 -> 180777 bytes
 .../maker-04-duplicate-banner.png                  | Bin 0 -> 176045 bytes
 .../maker-05-reused-three-children.png             | Bin 0 -> 197917 bytes
 .../maker-06-student-detail.png                    | Bin 0 -> 187298 bytes
 .../maker-07-student-guardians-panel.png           | Bin 0 -> 199488 bytes
 .../maker-08-subform-duplicate-banner.png          | Bin 0 -> 218546 bytes
 .../reports/fix-guardian-create-duplicates.md      | 669 +++++++++++++++++++
 ...pdate-writes-phones-and-cannot-clear-a-field.md |  86 +++
 .../guardians/add-standalone-guardian-modal.tsx    | 404 ++++++++++--
 .../guardians/guardian-duplicate-banner.tsx        | 163 +++++
 .../js/components/students/guardian-sub-form.tsx   |  52 +-
 .../students/student-guardians-panel.tsx           | 391 ++++++++---
 resources/js/hooks/use-guardian-lookup.ts          | 138 +++-
 routes/endpoints/guardian.php                      |   3 +
 .../Guardian/GuardianCreateDeduplicationTest.php   | 724 +++++++++++++++++++++
 36 files changed, 3758 insertions(+), 324 deletions(-)
```

Code only (`-- '*.php' '*.tsx' '*.ts'`): **15 files, 2672 insertions, 324 deletions.**

**Reading it against my model, which is what the control is for.** Four files are
larger than the lines I wrote, and each has a named reason, none of them a sweep of an
unrelated file:

- `GuardianRequest.php` and `GuardianUpdateRequest.php` carried pre-existing
  aligned-`=>` formatting that Pint normalises; both are files I edited, and
  `bin/lint-changed.sh` would do the same on push.
- `add-standalone-guardian-modal.tsx` (404) and `student-guardians-panel.tsx` (391)
  were already Prettier-dirty at HEAD — verified by stashing — so formatting untouched
  lines is the price of touching them at all, and it is the changed-files gate's stated
  "legacy drift burns down as files are touched" design.

No file outside this list was modified, and every file in it is one this change
deliberately touches.

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

### Not driven

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

| Severity | Finding |
| --- | --- |
| **fix** | `GuardianController::attach` and `StudentController`'s registration path bind a new guardian to an already-registered `users` row with no confirmation and no unique rule — pre-existing at HEAD, deliberately not narrowed by this change, and now the only remaining unguarded doors to the hazard finding 3 closed on `store`. |
| **fix** | The `/guardians` PAGE is gated on `admin_area.access` (`routes/web.php:353`) while `/api/guardians*` is gated on `academic_setup.manage` (`routes/api.php:47`). A role holding one without the other signs in and gets a full-page 403 on a screen it is otherwise permitted to use. Cost this drive one run, and would present to a school as a broken login. |
| **ticket** | `GuardianService::update` writes phones unnormalised, and no field can be cleared to null. Filed and **sized at 0 affected rows today** — `docs/handoff/tickets/guardian-update-writes-phones-and-cannot-clear-a-field.md`. |
| **ticket** | `tests/Feature/GuardianManagementTest.php:240-266` asserts the 2026-07-21 credential ruling but 403s from route middleware, never reaching `GuardianUpdateRequest`. Vacuous with respect to its stated intent; left untouched and covered by new arms. |
| **ticket** | The same-school pivot trigger raises SQLSTATE 45000 / driver **1644**, unmapped in `bootstrap/app.php`, so it surfaces as a **500**. Reachable today only with both application guards removed, but any future pivot writer without a school pin gets an opaque 500 rather than a 409/422. |
| **ticket** | `student-guardians-panel.tsx`'s `fetchGuardians` still swallows load failures into `console.error('Failed to load guardians')` — the same class as the pivot bug fixed here, one function above it. |
| **ticket** | `app/Services/GuardianImportService.php:8` imports `App\Models\User` unused (pre-existing). |
| **ticket** | `ImportConflictException` is now thrown from a non-import caller; the name is wrong. |
