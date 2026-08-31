# Two index endpoints paginate on unclamped user input

**Status:** open. Found 31 August while checking whether the shared pagination control's ceiling
could be raised for one screen.

## The two

`app/Http/Controllers/NoticeController.php:76`

    $notices = $query->paginate($request->input('per_page', 15));

Raw request input, not even cast to an integer, straight into `paginate()`.

`app/Http/Controllers/CurriculumController.php:183`

    $curricula = $curricula->paginate($request->integer('per_page', $limit));

Cast, but with no ceiling.

A caller can ask either endpoint for a million rows and it will try.

## The inconsistency is the wider point

The platform does not agree on what a legal `per_page` is. `GuardianController:778` and
`CurriculumController:425` validate `max:200` on their other paths. The manual invoice run roster
clamps, deliberately, and its docblock explains why a clamp beats a validation error mid-selection:
"a client asking for more should get the most it may have, not an error in the middle of a
selection." These two do neither.

That matters beyond these two endpoints, because the shared `Pagination` component
(`resources/js/components/pagination.tsx`) has 15 consumers and one `LIMITS` list. Anyone raising
that list to serve one screen would be offering options to servers that variously clamp, validate at
200, or accept anything. The roster commit of 31 August deliberately did not raise it for that
reason, and left a comment saying so.

## What closes it

Clamp both, in the roster's shape rather than as a validation rule — the ceiling is the most a
caller may have, not an error. Then decide once, in one place, what the platform's page ceiling is,
so a shared control can be trusted to offer only what servers serve.

**Do not close it by adding `max:200` to these two alone.** That produces a third convention beside
the two that already exist, and the next screen inherits whichever one it copies.
