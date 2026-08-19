# Two small inconsistencies left by `fix/guardian-create-duplicates`

**Raised by:** the third cold review of that branch, as minor observations it did not
file as findings. Recorded together because they are one small edit each, in adjacent
code, and neither is worth a round on its own.

Both are cosmetic. Neither changes behaviour today.

## 1. `GuardianRequest`'s `$isUpdate` branch is dead

`app/Http/Requests/GuardianRequest.php` computes:

```php
$isUpdate = $this->isMethod('PATCH') || $this->isMethod('PUT');
```

and uses it in two places — the `status` rule and the create-only
`Rule::unique('users', 'email')`. But the class is bound only to
`GuardianController::store` (`POST /api/guardians`); the update route resolves
`GuardianUpdateRequest`. So `$isUpdate` is always `false` and both branches are dead.

The comment on the unique rule reads *"On UPDATE it stays: pointing one guardian's email
at another registered account is a genuine collision"* — and **that guarantee does hold**,
but it is supplied by `GuardianUpdateRequest`'s own `Rule::unique(...)->ignore($userId)`,
not by the dead branch the sentence sits next to. The arm covering it passes for the
right reason; only the comment's location is misleading.

## 2. `GuardianMatcher` uses `?->user` where `GuardianService` was changed to `->user`

`GuardianMatcher::emailRefutesMatch` reads `$match->user?->email`. `GuardianService` had
the identical expression and Larastan flagged the nullsafe as dead, because
`guardians.user_id` is NOT NULL (derived from `information_schema`); it was changed to
`->user` with the reason recorded in the code. The matcher's copy was not, because
Larastan did not flag it there.

Two spellings of the same fact about the same column, one of them annotated as
impossible and the other left as if it were possible.

## What closing it looks like

1. Delete `$isUpdate` and inline `'nullable'` on `status`; move the unique-rule comment's
   "on UPDATE it stays" sentence to `GuardianUpdateRequest`, where the rule that
   delivers it actually lives.
2. Make the matcher's `?->user` a `->user` and carry the same one-line reason across.
3. Neither needs a new arm; the existing arms cover both paths and should stay green
   through the edit. If either turns red, the premise above is wrong and that is the
   more interesting finding.
