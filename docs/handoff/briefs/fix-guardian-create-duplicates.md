# Brief — `fix/guardian-create-duplicates`

**Base:** `feat/guardian-merge-command` once it has merged to `staging`; otherwise
`staging`. Re-derive with `git rev-parse --short staging` before branching. **Never
stack branches** (CLAUDE.md) — if slice 1 has not landed, branch from `staging` and
do not depend on `GuardianService::merge`.
**Branch:** `fix/guardian-create-duplicates`.
**Shape:** ~4 PHP files (service, request, controller, a new matcher class), ~4 TSX
files, one route, two test files. One commit.

This is **slice 2 of 3**. Slice 1 (`GuardianService::merge` + the two commands) and
slice 3 (the uniqueness constraint) are not yours — see *Not in scope*.

---

## The finding

A school reported adding a mother via the guardians page using both her children's
admission numbers, "the information was not saving"; adding her again from each
child's page worked; she now appears **three times**, two rows with no email.

That single report is four confirmed defects compounding.

**1. `student_links` is entirely unvalidated and silent failures are indistinguishable
from success.** `GuardianController::store` (`app/Http/Controllers/GuardianController.php:171-183`)
reads `student_links` via `$request->input()`. The key appears nowhere in
`app/Http/Requests/GuardianRequest.php` — there is no rule for it, so
`validated()` would not contain it and the controller bypasses `validated()`
entirely. An admission number that does not resolve hits `if ($student)` (line 174)
and is **discarded with no error, no log, and a `201 Created` response** carrying a
redirect to a guardian page showing zero children. There is no transaction: the
guardian persists, the links do not. That is precisely "it was not saving".

Same block, line 178: `$link['relationship'] ?? 'other'`. The modal always sends the
key, as `''` (`add-standalone-guardian-modal.tsx:44`), so the `?? 'other'` fallback
never fires and an **empty string is written into the pivot's `relationship`
column** with no enum check.

**2. The bulk modal discards every non-422 response.**
`resources/js/components/guardians/add-standalone-guardian-modal.tsx:62-68` handles
only `status === 422`. A 403 from `Authz::abilityCheck` (`GuardianController.php:133`),
a 419, a 500 from the same-school trigger — all caught and dropped; the modal sits
open with no message. Errors also render by exact field key only (line 75), so nested
`student_links.*` errors could not display even if they existed.

**3. Creation never dedupes the guardian row.**
`GuardianService::createGuardianWithUser` (`app/Services/GuardianService.php:225-287`)
dedupes the **User** by email (line 257) but always calls `Guardian::create()` (line
274). With no email, `$userEmail = null` (line 252) and `User::where('email', null)`
never matches under MySQL, so every email-less submission mints a fresh User **and** a
fresh Guardian. With an email the User is reused but a second `guardians` row against
the same `(user_id, school_id)` is still created.

Meanwhile `GuardianImportService::lookupExistingInDb`
(`app/Services/GuardianImportService.php:237-283`) implements correct
school-scoped email-then-phone matching. **The spreadsheet import dedupes; the two
interactive forms do not.** That asymmetry is the bug.

**4. The correct path is closed.** `GuardianRequest.php:51` applies
`Rule::unique('users','email')` on create, so re-adding a parent who already has a
`users` row 422s with "The email has already been taken" — while the service it
guards is explicitly written to reuse that user ("One human = one User §6.2",
`GuardianService.php:254-256`). The rule fights its own service and leaves the
operator no way forward, which is what pushed the school to the per-child workaround
that produced the duplicates.

**Environment: production, invisible locally.** No fixture produces this state.
**No test anywhere in the repository references `student_links`** — verify with
`grep -rn student_links tests/`; the bulk-link path has zero coverage.

---

## What NOT to do

- **Do not make the server silently reuse a match and say nothing.** The decision on
  this slice was *warn, let the operator choose*. Silent reuse discards the details
  the operator just typed and leaves them unsure which record they edited. The
  server-side dedupe (Part 2) is the **backstop** for a caller that proceeds anyway,
  not the primary UX.
- **Do not hard-block a duplicate with a 422.** That is what defect 4 already does
  and it is why the school ended up with three rows.
- **Do not write a second matching rule.** `lookupExistingInDb` already exists and is
  correct. Extract it and have both callers use the extraction. A third definition of
  "same person" is drift waiting for a deploy.
- **Do not merge existing duplicates here** and do not add the unique index. Slices 1
  and 3.
- **Do not remove `Rule::unique('users','email')` from the UPDATE path.** Only the
  create path. On update, changing a guardian's email to one already registered is a
  genuine collision.
- **Do not "fix" `Guardian::applySchoolScope`** (`app/Models/Guardian.php:88-94`),
  whose OR branch makes a multi-school parent's other-school rows visible. Known,
  flagged, not this change — work around it with `withoutGlobalScopes()` and pinned
  predicates, as `forUserInActiveSchool` (`GuardianService.php:761-766`) does.
- **Do not reset or re-seed the local database.**

---

## Part 1 — Extract the matcher

New `App\Services\GuardianMatcher` (or `GuardianRepository::findMatchInSchool` — your
call, state which and why).

1. Move `lookupExistingInDb` (`GuardianImportService.php:237-283`) **behaviour
   unchanged**: lowered `users.email` first, then `phone`/`whatsapp_number` with the
   whatsapp fallback, school-scoped, `deleted_at IS NULL`, `withoutGlobalScopes()`;
   conflicting email-vs-phone matches raise rather than guess.
2. Normalise phone inputs with `App\Support\PhoneNormalizer` — the same call the
   write path makes (`GuardianService.php:230-238`) — so a match key and a stored
   value cannot disagree on format. Check whether the import currently normalises
   before comparing; **if it does not, that is a pre-existing bug — report it, do not
   silently change import behaviour inside this refactor.**
3. `GuardianImportService` calls the extraction. Its existing tests
   (`tests/Feature/GuardianCrossSchoolImportTest.php` and the import suite) must stay
   green **without modification**. If one needs changing, you have changed behaviour —
   stop and report.

## Part 2 — Dedupe at the write (the backstop)

In `createGuardianWithUser`:

4. After the User is resolved, look for a live guardian for that
   `(user_id, school_id)` — the guard `resolveOrCreateGuardianForUserInSchool`
   (`GuardianService.php:199-216`) already performs — and when found, **reuse it and
   fill only blank fields** rather than `Guardian::create()`. Never overwrite a
   non-empty existing value with form input on this path.
5. For the email-less case, fall back to `GuardianMatcher`'s phone key.
6. Return whether the guardian was reused, so the controller can say so in the
   response.

## Part 3 — Warn before the write

7. `GET /api/guardians/duplicate-check?email=&phone=&whatsapp_number=` →
   `GuardianController::duplicateCheck`, gated by the same ability as
   `GuardianController::lookup` (`:73-113`). Returns candidates: `uuid`, full name,
   **masked** contact, linked-student count. Route in
   `routes/endpoints/guardian.php` alongside the existing `lookup` route.
8. Both modals call it debounced on blur of email and phone and render an inline
   banner: *"A guardian matching this already exists — link them to these students
   instead?"* with a button that switches to the existing-guardian flow. Reuse the
   existing plumbing — `resources/js/hooks/use-guardian-lookup.ts` — rather than a
   second hook.
9. `GuardianRequest.php:51`: `Rule::unique('users','email')` applies **only when
   `$isUpdate`**. On create the service reuses the user by design.
10. If the matched email belongs to a user who is **not** a guardian in this school
    (staff, for instance), that is not a duplicate guardian — surface it as an
    explicit confirmation ("this address belongs to an existing account"), never a
    silent link.

## Part 4 — Validate `student_links`, make the submission atomic

11. In `GuardianRequest::rules()`:

```php
'student_links'                    => ['nullable', 'array'],
'student_links.*.admission_number' => ['required', 'string',
    Rule::exists('students', 'admission_number')
        ->where('school_id', ActiveSchool::id())
        ->whereNull('deleted_at')],
'student_links.*.relationship'     => ['required', 'string', Rule::in(GuardianRelationshipEnum::values())],
'student_links.*.is_primary'       => ['nullable', 'boolean'],
```

Confirm `ActiveSchool::id()` is resolvable at rule-construction time in this request
context; if not, build the rule inside a closure. The `school_id` predicate is
**required** — do not rely on `Student`'s global scope inside a `Rule::exists`, which
runs raw SQL and does not apply Eloquent scopes. This is the isolation-critical line
in the slice.

12. `withValidator`: reject the same admission number twice in one submission, with a
    message naming the row.
13. `prepareForValidation`: trim admission numbers. Decide on case — check how
    `admission_number` is generated (`HasAdmissionNumber`) and whether the column
    collation is already case-insensitive before adding a lowering step; state what
    you found.
14. Wrap creation + notification + all attachments in **one** `DB::transaction` in the
    controller, with `notifyGuardian` moved to **after commit** — the pattern
    `StudentController.php:78-96` already uses. Nesting inside
    `createGuardianWithUser`'s own transaction becomes a savepoint, which is fine.
15. Drop the now-dead `?? 'other'` fallback (line 178); relationship is validated.
16. Read the links from `validated()`, not `input()`.

## Part 5 — Make the UI tell the truth

17. `add-standalone-guardian-modal.tsx`: mirror the error handling already correct in
    `add-guardian-modal.tsx:143-153` — a `_general` banner for non-422 and for
    `message`-only responses — and render nested
    `student_links.N.admission_number` errors **against their row**.
18. Fix the inverted label at
    `resources/js/components/students/guardian-sub-form.tsx:316`: "I don't have any
    other child in this school" currently labels `mode = 'new'`. Read the surrounding
    logic and state what the label should be before changing it.
19. `student-guardians-panel.tsx:78-90` swallows every pivot-update failure into
    `console.error('Pivot update failed', err)` — toggling primary or login fails
    invisibly. Surface it.
20. `GuardianUpdateRequest::prepareForValidation` (`:25-43`) silently strips `email`
    and `phone` when the actor lacks `guardian.update_credentials`, and the request
    then returns **200 with the field unchanged**. Return a 403 or 422 naming the
    field instead of a false success. This is a second, independent "it was not
    saving" mechanism and belongs with this fix.
21. `GuardianService::update` (`:444-460`) does
    `array_filter($attributes, fn ($v) => ! is_null($v))` — a field **cannot be
    cleared to null** through the update path. Report it; fix only if it falls out
    cleanly, otherwise file a ticket under `docs/handoff/tickets/`.

---

## Prove it

MySQL only; SQLite does not work here.

```bash
DB_DATABASE=portal_testing ./vendor/bin/pest --filter=Guardian
DB_DATABASE=portal_testing ./vendor/bin/pest --log-junit junit.xml && php bin/ci-test-ratchet.php junit.xml
```

New arms — each a distinct failure mode:

1. Bulk create, two valid admission numbers → **one** guardian row, **two** pivots,
   correct relationship on each.
2. Bulk create, one valid + one typo'd admission number → 422, **and
   `Guardian::count()` is unchanged**. Assert the count, not just the status — the
   count is the atomicity proof and the status is not.
3. Bulk create with an admission number belonging to **another school** → 422, not a
   silent skip. Assert no pivot and no cross-school row.
4. Same person submitted twice **with** an email → one guardian row, both students
   attached.
5. Same person submitted twice **without** an email (the reported case) → one
   guardian row, and **not two `users` rows**.
6. `duplicate-check` returns the existing guardian for a known email; returns nothing
   for an unrelated one; and does **not** return a guardian from another school
   (isolation — assert by **id**).
7. Create with an email already registered to a user → **no longer 422** on the
   create path; still 422 on update.
8. Empty-string relationship in `student_links` → 422 (the `?? 'other'` defect).
9. `GuardianUpdateRequest` without `guardian.update_credentials` → 403/422, not a 200
   with an unchanged field.

Gates before committing:

```bash
files=$(git diff --name-only HEAD -- '*.php')
[ -n "$files" ] && ./vendor/bin/pint $files
php bin/ci-authz-lint.php
php bin/ci-boundary-lint.php
DB_DATABASE=portal_testing ./vendor/bin/pest --group=arch
composer analyse
```

Pint through an explicit changed-file list **with the empty-list guard** — a bare
`pint` lints the whole project and has swept unrelated files into a commit three
times (CLAUDE.md). Frontend goes through `bin/quality`'s Prettier/ESLint/tsc steps.
Then read `git diff --stat` against your own model of the change.

Paste **raw** output.

---

## The watched red

Required; a deliverable, not a step.

**Arm 3 is the one that matters** — it is the isolation arm, and it is exactly the
arm that passes vacuously if the `->where('school_id', …)` predicate is missing but
the fixture happens to have only one school. **Delete the `school_id` predicate from
the `Rule::exists`**, run arm 3, confirm it fails by *attaching a cross-school
student*. Restore. Paste both states.

Also watch arm 2 red: remove the transaction wrapper, confirm arm 2 fails on the
**guardian count** (an orphan guardian created) rather than on the status code.
Restore. Paste both.

If either refuses to go red, report that and stop — it is more important than the
change.

---

## Drive it

Screens changed, so a drive is required. **Load the `finance-drive` skill** — do not
improvise the procedure.

- **Screens:** `/guardians` (the Add Guardian modal) and a student's detail page
  (its guardians panel).
- **Seats:** one that can create guardians, and one from a **second school** to
  confirm its admission numbers do not resolve.
- **Look at:** (a) two admission numbers → two children actually listed on the new
  guardian's page, not an empty list; (b) one typo'd number → a visible error **on
  that row**, and no guardian created; (c) re-adding the same person → the duplicate
  banner and the one-click link; (d) the second school's seat cannot reach the first
  school's students. Check isolation **by id**, not by label.

---

## Stop and report

Halt rather than improvise if:

- extracting the matcher requires changing any existing import test;
- `ActiveSchool::id()` is not resolvable where the validation rule is built and the
  closure form does not fix it;
- the ratchet reports a regression you did not cause;
- removing the create-path unique rule turns any existing test red — that test
  encodes an intent this brief may have misread; report it before changing it;
- you conclude any part of the finding above is wrong. **The code wins over this
  brief** — say so before writing anything.

**Do not weaken an assertion to make a test pass.** A failing assertion on seeded
data is a finding.

---

## Not in scope

- `GuardianService::merge`, `guardians:merge`, `guardians:find-duplicates` — slice 1.
- The unique index / generated column — slice 3.
- The admin merge UI — deferred.
- `Guardian::applySchoolScope`'s OR branch — known, flagged, not this change.

---

## Hand-off

Report to `docs/handoff/reports/fix-guardian-create-duplicates.md` using the
`finance-execute` report template, then spawn `finance-reviewer` with **only** the
report path and the branch name. Return its findings raw and unanswered.

Commit on the branch. **Do not push.**

**Full-review tier** — this touches `school_id` isolation, a validation boundary, the
guardian write path and a login invariant. Say so in your headline and recommend a
cold session before merge.
