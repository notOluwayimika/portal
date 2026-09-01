# `assignScore` / `clearScore` have no teacher-assignment check, and a comment says they do

**Raised:** 2026-09-01 · **From:** `fix/three-authorisation-holes` (found in cold review) · **Severity:** ticket

## What

`app/Http/Controllers/CurriculumSubjectController.php:382-383` carries this comment:

```php
// Authorize: the TCS must belong to the authenticated teacher, AND
// the marking_component must belong to the same curriculum_subject.
```

Nothing below it does the first half. `App\Http\Requests\UpsertScoreRequest::authorize()` returns
`true` unconditionally. The curriculum subject is resolved from a **body** uuid, not the route, and
no `TeacherCurriculumSubject` lookup happens anywhere on the path.

`clearScore` (the sibling `DELETE` on the same route, same group) has an explicit unconditional
isolation guard with a docblock spelling out its hazard — and no assignment check either.

## What it costs

`POST /api/curriculum-subjects/{uuid}/scores` and its `DELETE` sit under
`permission:score.manage`, held by `admin`, `head_of_school` and `teacher`. Any of them, in the
school, can write and clear every score on a colleague's subject.

`fix/three-authorisation-holes` just made **submitting** a result assigned-teacher-only, on the
argument that `submitted_by` is a durable maker identity and a maker who is not the assigned teacher
is false provenance. That now holds for the *act* of submitting while the *content* of the submitted
record stays authorable by anyone in the group — which is the weaker half of the same property.

Cross-school is bounded, but **by accident rather than by design**: `Student` uses
`BelongsToSchool`, so the enrolment lookup fails for a foreign student. `CurriculumSubject` itself
carries no scope and resolves across schools.

## Why it is a ticket and not part of that branch

It is pre-existing on `staging`, not introduced or widened there, so holding that merge changes
nobody's exposure. It is the more severe of the two, though, and should be scheduled rather than
filed.

## The fix

The same `TeacherCurriculumSubject` existence check `submit()` now carries, on `assignScore` and
`clearScore`, each with its own refused arm and known negative — and the explicit isolation guard on
`assignScore` that `clearScore` already has. Decide the privileged-seat question the same way it was
decided for `submit()`: read the grants first, then state the rule at the call site.

**Build the refused fixture on a plain `teacher` role, not on `admin`.** `admin` holds effectively
everything, so an arm built on it can pass for a reason other than the assignment and cannot fail on
its own axis — the degenerate-fixture trap this repo has paid for repeatedly. A `teacher` holds
`score.manage` (so the route group admits) and does not hold the assignment, which leaves the guard
under test as the only thing that can refuse. Assert 403 on **both** `assignScore` and `clearScore`;
they are separate call sites and one arm covering both goes green if either guard is removed.

Give the refusal a **message**, for the reason recorded in
[`submit-button-shown-to-unassigned-teachers.md`](submit-button-shown-to-unassigned-teachers.md):
there is no `HttpException` renderable, so a bare `abort(403)` reaches the client as
`{"message": ""}` and renders as nothing.

**Delete or correct the comment in the same change.** A comment asserting an authorization the code
does not perform is worse than no comment: it reads as verification and stops the next person
looking.
