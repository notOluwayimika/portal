# `StudentResource.curriculum_id` puts a raw auto-increment id on the wire

**Status:** open · **Severity:** ticket (convention breach; no known exploit)
**Found:** while adding `current_episode_id` and `curriculum_uuid` for bulk reassignment.

## The defect

`app/Http/Resources/StudentResource.php`:

```php
'curriculum_id' => $curriculum?->id,
```

That is the database primary key. `StudentCurriculumResource` states the opposite convention — uuids
on the wire, ids never — and every route in this area binds on `{...:uuid}` for the same reason:
sequential ids are enumerable and couple external callers to internal row numbering.

## Why it was not fixed in `feat/reassignment-ui`

Repointing the field to a uuid is a one-line change with an unknown blast radius: whatever reads
`curriculum_id` today would keep parsing, silently comparing a uuid string against an integer, and
fail as "no match" rather than as an error. Finding those consumers is its own change with its own
verification, and doing it inside a feature branch would have buried it.

So the two fields bulk reassignment needs were added **beside** it:

```php
'curriculum_id'      => $curriculum?->id,       // untouched
'current_episode_id' => $currentCurriculum?->uuid,
'curriculum_uuid'    => $curriculum?->uuid,
```

This ticket exists so that "we added fields next to it" does not read, later, as the leak having
been reviewed and accepted.

## Suggested fix

1. Enumerate consumers — `grep -rn "curriculum_id" resources/js` plus any external API client; the
   TS type is marked `@deprecated` in `resources/js/types/models.ts` to surface them in editors.
2. Migrate each to `curriculum_uuid`.
3. Remove `curriculum_id` from the resource. Do **not** repoint it in place to a uuid under the same
   key — a consumer that still expects an integer would then fail silently rather than loudly.

## Related

The same audit should check whether any other `*Resource` on this path emits a raw id; this one was
found incidentally rather than by a sweep.
