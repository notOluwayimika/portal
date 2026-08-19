# `GuardianService::update` writes phones unnormalised, and cannot clear a field to null

**Raised by:** the cold review of `fix/guardian-create-duplicates` (findings 5 and, from the same
method, the branch's own deferred item 21). Two defects in one method, filed together because they
would be fixed in one edit and both need the same verification — a drive of the guardian **edit**
modal, which that branch did not have in scope.

**Sized before filing, not after.** See *How big is it today* — the answer is **zero rows**, and that
is why this is a ticket and not a fix.

## 1. The write path does not normalise phones, so the matcher's central claim is false

`app/Services/GuardianMatcher.php` states, in its own comment, that normalising the lookup key means
"a match key and a stored value cannot disagree on format". That is true for every row written by
`GuardianService::createGuardianWithUser`, which normalises `phone`, `whatsapp_number` and
`emergency_contact` at the storage boundary, and for every row written by the spreadsheet import,
which normalises upstream in `GuardianImportRowValidator`.

It is **false** for `GuardianService::update`. That method writes `$attributes` straight through with
no `PhoneNormalizer` pass, and `app/Models/Guardian.php` carries no phone mutator — the fillable list
has `phone` and `whatsapp_number` and nothing intercepts either.

**Consequence.** A guardian whose phone was last saved through `edit-guardian-modal.tsx` is stored as
the operator typed it — `08031110001` — while both the duplicate-check banner and the create-path
reuse backstop look it up as `+2348031110001`. The returning-parent case that
`fix/guardian-create-duplicates` exists to fix then silently produces a second guardian row again,
for exactly the population most likely to hit it: parents whose record has been edited.

Email matching is unaffected (lowered on both sides at every writer), so this degrades the phone arm
only.

## 2. No field can be cleared to null through the update path

`GuardianService::update` does:

```php
$guardian->update(array_filter(
    $attributes,
    fn ($v) => ! is_null($v),
));
```

So `{"occupation": null}` is dropped and the request answers 200 with the field unchanged. That is
the same "it reported success and did not save" shape that
`fix/guardian-create-duplicates` removed from the create path and from
`GuardianUpdateRequest`'s credential strip — this is the third instance, in the method those two sit
either side of.

## How big is it today

Measured read-only against the local production copy on 2026-08-19, no write of any kind:

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

**Zero today.** Every stored number is already E.164. So defect 1 has no current victims and is
purely forward-looking: the first guardian edit that changes a phone number creates the first one,
silently, and nothing will report it. That is what makes this a ticket rather than a fix — and it is
also why it should not sit indefinitely, because the population only ever grows and each new member
is a duplicate guardian waiting to be created.

## What closing it looks like

1. Normalise `phone`, `whatsapp_number` and `emergency_contact` in `GuardianService::update`, the
   same three fields and the same call `createGuardianWithUser` makes. Better still, move the
   normalisation onto the model so no future writer can skip it — there are three writers today and
   the count has only gone up.
2. Correct `GuardianMatcher`'s comment, which asserts the invariant rather than describing it.
3. Add a **positive** phone arm to `duplicate-check`'s coverage. Every phone assertion in
   `tests/Feature/Guardian/GuardianCreateDeduplicationTest.php`'s isolation arm asserts an EMPTY
   result, so the suite cannot currently tell a working phone match from a broken one — which is how
   this would have shipped unnoticed.
4. Decide the null-clearing question deliberately, and **drive the edit modal before changing the
   filter**: `edit-guardian-modal.tsx` builds its payload from every non-empty form key, so removing
   `array_filter` naively is safe for that client but not necessarily for any other. If clearing is
   to be supported, the honest shape is an explicit null in `validated()`, not the absence of a key.
5. Whatever is decided, do not leave the 200-with-no-change behaviour: refuse it or perform it.
