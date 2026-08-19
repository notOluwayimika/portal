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

## 3. `GuardianImportService`'s docblock claims a behaviour-preserving extraction, and it is not one

Raised by the fourth review. `app/Services/GuardianImportService.php`'s
`lookupExistingInDb` wrapper says the body *"MOVED to App\Services\GuardianMatcher,
**behaviour unchanged**."* Two changes now reach the import through that delegate:

1. An **ambiguous phone throws** where the old body did a bare unordered `->first()` and
   picked one. `AmbiguousPhoneMatchException`'s own docblock says so directly, so the
   file contradicts itself two hops apart.
2. The matcher **normalises** email/phone/whatsapp on entry where the old body took them
   as given. Harmless — the import normalises upstream and `PhoneNormalizer` is
   idempotent — but it is still not "unchanged".

The green import suite proves nothing here: **no import fixture has two guardians sharing
a number**, so the new throw is unexercised through that path. A false sentence in a file
is what the next reader will believe.

Closing it: reword the docblock to state what changed, and add one import arm on an
ambiguous row.

## What closing it looks like

1. Delete `$isUpdate` and inline `'nullable'` on `status`; move the unique-rule comment's
   "on UPDATE it stays" sentence to `GuardianUpdateRequest`, where the rule that
   delivers it actually lives.
2. Make the matcher's `?->user` a `->user` and carry the same one-line reason across.
3. Neither needs a new arm; the existing arms cover both paths and should stay green
   through the edit. If either turns red, the premise above is wrong and that is the
   more interesting finding.
