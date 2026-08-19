# Implementation report — `fix/guardian-create-duplicates`

**FULL-REVIEW TIER — subagent review attached; recommend a cold session before merge.**
This touches `school_id` isolation, a validation boundary, the guardian write path, a
login invariant and the drive fixture.

---

## Headline

Done, with deviations and two premise corrections — one of which makes defect 3
strictly worse than the brief describes and is a live cross-school identity defect
on `staging` today. Branch `fix/guardian-create-duplicates`, based on `staging`
@ `e484a46` (NOT on `feat/guardian-merge-command`; nothing here depends on
`GuardianService::merge`). One commit.

**Methods touched in `app/Services/GuardianService.php`, for the eventual merge with
`feat/guardian-merge-command`:** the constructor (one added dependency),
`createGuardianWithUser`, and one NEW private method `fillBlankGuardianFields`
inserted immediately above `assertLoginRequiresDeliverableEmail`. Nothing else in
that file was edited — `merge` does not exist on this base and no other method's
body was touched.

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

**Read `git diff --stat` against my own model of the change.** Two files grew beyond
what I edited: `GuardianRequest.php` 127 → 173 changed lines and
`GuardianUpdateRequest.php` 69 → 107, because both carried pre-existing aligned-`=>`
formatting that Pint normalises. That is confined to the two files I edited (no
unrelated file was swept), and `bin/lint-changed.sh` would do the same on push. Final:

```
 app/Console/Commands/SeedDriveFixture.php          |  26 +-
 app/Http/Controllers/GuardianController.php        | 231 ++++++++++++++++----
 app/Http/Requests/GuardianRequest.php              | 173 ++++++++++++---
 app/Http/Requests/GuardianUpdateRequest.php        | 107 +++++++---
 app/Services/GuardianImportService.php             |  51 +----
 app/Services/GuardianService.php                   | 132 ++++++++++--
 database/seeders/DriveCastSeeder.php               |  24 +-
 resources/js/... (4 files)                         | 307 +++++++++++++++++++++
 routes/endpoints/guardian.php                      |   3 +
```

**Frontend:** Prettier and ESLint clean on all five changed/added TS files (both
required a `--write`/`--fix` pass; `add-standalone-guardian-modal.tsx` and
`student-guardians-panel.tsx` were already Prettier-dirty at HEAD — verified by
stashing — so their diffs include reformatting of untouched lines).

### The tsc ratchet is RED, and it is red at clean `staging` with zero contribution from this change

Reported as red, not baselined and not chased, per the brief.

```
$ php bin/ci-tsc-ratchet.php <tsc output, WITH my change>
tsc-ratchet: type errors INCREASED — 54 > baseline 42.
```

Clean HEAD, everything stashed **including untracked**, wayfinder regenerated:

```
$ git stash -q -u && php artisan wayfinder:generate && pnpm run types:check > base.txt
$ grep -c "error TS" base.txt
54
$ php bin/ci-tsc-ratchet.php base.txt
tsc-ratchet: type errors INCREASED — 54 > baseline 42.
```

54 at clean `staging`, 54 with this change. **Net contribution: zero.** The
committed `tsc-baseline` (42) is stale against `staging`; that is a pre-existing
gate failure for someone to own, and regenerating it here would launder it.

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

### Not driven

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
- **No production copy was touched.** The dev database was not migrated, not seeded
  and not read. `portal_testing` was used by the suite only.

---

## Not done

- **No ticket file written** for the `array_filter` null-clearing issue (brief item
  21 offers "fix cleanly or file a ticket under `docs/handoff/tickets/`"). I chose
  to record it in this report rather than create a file, because the fix's blocker
  is a drive of the guardian **edit** modal, which is a different screen and a
  different brief; a ticket asserting a cause I have not driven would be a guess
  written down. **Name the choice: this is a gap, and if the lead wants the ticket it
  is one file.**
- **No test for the email-vs-phone conflict 422** I added to
  `createGuardianWithUser`. It is reachable only when two distinct guardians in one
  school hold the submitted email and the submitted phone respectively; I reasoned
  the import cannot reach the catch (it resolves the same matcher first,
  `GuardianImportService.php:76-85`, and only creates on a null match) but **did not
  prove that with a test**. Unproven arm.
- **No test for the `student_links` capacity/perf shape** — no `max:` on the array.
  A submission with 500 rows runs 500 `exists` queries.
- **No test that `duplicate-check` refuses an unauthenticated or under-permissioned
  caller.** It inherits the group middleware, and `RouteMiddlewareBaselineTest`'s
  second arm proves it carries *auth* middleware, but nothing asserts the specific
  ability.
- **Oracles were NOT regenerated, deliberately.** Both `RouteAccessParityTest` and
  `RouteMiddlewareBaselineTest` iterate the *fixture* keys, so a new guarded route
  passes freely; the new route carries the group's auth middleware. `rbac:sync` was
  never run — no permission or grant changed.
- **`.env.drive` now exists** in the working directory (untracked, gitignored). It is
  the documented one-time drive setup (`docs/finance/drive-environment.md`), built
  from the committed `.env.drive.example`, and holds no real credential. `.env` was
  never read.
- The `ImportConflictException` class name is now wrong (thrown from a non-import
  caller). Deliberately not renamed — that is a behaviour-neutral rename for its own
  commit, and doing it inside a refactor is how a rename hides a behaviour change.

---

## Findings raised, not fixed

| Severity | Finding |
| --- | --- |
| **stop** (fixed here, but flagged for the record) | `GuardianService.php` pre-fix `User::where('email', $userEmail)` with a null email resolved to `WHERE email IS NULL` against an unscoped `User` model and bound unrelated people to one account, then granted that account school access. Live on `staging` until this branch. |
| **fix** | `tsc-baseline` is stale: clean `staging` produces 54 type errors against a baseline of 42, so `bin/quality` step 4 fails for anyone, on any branch. Not baselined here. |
| **fix** | `tests/Feature/GuardianManagementTest.php:240-266` asserts the credential-strip ruling but 403s from route middleware, never reaching `GuardianUpdateRequest`. Vacuous with respect to its stated intent. Left untouched (not mine to weaken or rewrite); covered by a new arm. |
| **ticket** | `GuardianService::update` (`:453-456`) `array_filter(… ! is_null …)` — no field can be cleared to null through the update path. |
| **ticket** | The same-school pivot trigger raises SQLSTATE 45000 / driver **1644**, which `bootstrap/app.php` does not map, so it surfaces as a **500**. Reached only with both application guards removed today, but any future pivot writer without a school pin gets an opaque 500 instead of a 409/422. |
| **ticket** | `resources/js/components/students/student-guardians-panel.tsx` `fetchGuardians` swallows load failures into `console.error('Failed to load guardians')` — the same class as the pivot bug fixed here, one function above it. Out of the brief's item 19 scope. |
| **ticket** | `app/Services/GuardianImportService.php:8` imports `App\Models\User` unused (pre-existing). |
| **ticket** | `GuardianRequest` has no `max:` on `student_links`; an unbounded array costs one `exists` query per row. |
