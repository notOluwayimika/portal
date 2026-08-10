# Two docblock defects the draft-edit Action inherited by copying `CreateFeeSchedule`

**Raised by:** cold review of PR #234 (findings C3 and C4). **Severity:** ticket — both are comments,
neither changes behaviour. Recorded because both are the kind of wrong that sends a reader to the
wrong conclusion, and because one of them is now duplicated rather than merely present.

Both verified against the repo and against `vendor/` before recording.

## C3 — the `valueOrFail` comment names the wrong failure, and the failure is unreachable anyway

`CreateFeeSchedule` and `EditFeeScheduleDraft` both resolve the bank account with:

```php
'bank_account_id' => BankAccount::query()->where('uuid', (string) $item['bank_account_id'])->valueOrFail('id'),
```

and both explain it the same way — that resolving through the School-scoped model means a foreign
uuid "resolves to nothing here rather than being trusted and refused later by the composite foreign
key, which would surface as a **500 instead of a 422**".

**Two things in that sentence are wrong.**

1. **It is not a 422.** `Builder::valueOrFail()` is `firstOrFail([$column])`
   (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:870-875`), which throws
   `ModelNotFoundException`. `bootstrap/app.php:153-155` renders that as **404**
   (`response()->not_found(...)`). So the described "friendly 422" is a 404 with a message naming a
   model class.
2. **It cannot fire from the route.** `FeeScheduleRequest`'s `items.*.bank_account_id` rule is a
   `Rule::exists` on `BankAccount` scoped to the active School and non-deactivated. A foreign or
   unknown uuid is refused by validation — a real 422, with a field name — before either Action is
   reached. The `valueOrFail` is a backstop for a caller that bypasses the FormRequest, which today
   is only the test suite.

The comment is therefore describing a mechanism (composite-FK 500 avoided) and an outcome (422) that
neither match what the code does. It is *pre-existing* — copied verbatim from `CreateFeeSchedule` —
but PR #234 reproduced it into a second file, which is what turns a stale comment into a pattern.

**Fix, when taken:** state what is true — the FormRequest is the refusal an operator sees; this
resolution is a backstop for a non-HTTP caller and raises a 404-rendered `ModelNotFoundException`, not
a 422 — and correct BOTH copies, or extract the resolution so there is one.

## C4 — one array shape, two descriptions

```
CreateFeeSchedule:39      @param … each: description, amount_minor, currency?, is_mandatory?, …
EditFeeScheduleDraft:38   @param … each: description, bank_account_id (uuid), amount_minor, currency?, …
```

The two Actions consume the **same** item-spec array — both are fed by `FeeScheduleRequest::itemSpecs()`
and both read `$item['bank_account_id']` — but only one documents it. `CreateFeeSchedule`'s list is
the stale one: #233 made `bank_account_id` required and did not update the `@param`.

A reader building a caller from `CreateFeeSchedule`'s docblock omits the field and gets a PHP
undefined-array-key error, which is the worst of the available failures.

**Fix, when taken:** make them identical, or better, define the shape once — a `@phpstan-type` on one
of the two Actions, or a small DTO — since "one array shape, two hand-maintained descriptions" is the
condition that produced the divergence.
