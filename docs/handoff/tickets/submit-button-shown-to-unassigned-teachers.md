# The result Submit button is shown to every `teacher`, assigned or not

**Raised:** 2026-09-01 · **From:** `fix/three-authorisation-holes` (found in cold review) · **Severity:** ticket

## What

`resources/js/components/subject-result-status-panel.tsx` renders "Submit for review" for anyone
whose roles include `teacher` — `userRoles.includes('teacher')`, with no check that the user is
assigned to the subject on screen.

As of `fix/three-authorisation-holes`, `CurriculumSubjectController@submit` refuses an unassigned
submitter with a 403. So an unassigned teacher — and the census seat holding admin+teacher — sees an
enabled button that always fails.

## What that branch already did, and what it left

The refusal is no longer **silent**: both new aborts carry a message, because there is no
`HttpException` renderable in `bootstrap/app.php` and a bare `abort(403)` returns
`{"message": ""}` — which the panel's `?? 'Action failed.'` does not substitute for, so
`{error && …}` rendered nothing at all. An arm asserts the message is non-empty, and stripping the
message argument reds it.

What is left is the button itself. Naming the remedy in an error is second-best to not offering the
action; the remedy is an assignment row somebody else has to create, so the user cannot act on the
message alone either way.

## The fix

Gate the button on the assignment rather than on holding the role — the panel needs the server to
tell it whether the current user is assigned to this curriculum subject (the same
`TeacherCurriculumSubject` fact the controller checks), surfaced alongside the result status the
panel already reads.

Consider at the same time whether `bootstrap/app.php` should carry a generic `HttpException`
renderable, so that a future bare `abort(403)` anywhere in the codebase is legible by default rather
than by each call site remembering. That is the class fix; the per-call-site message is the instance.
