# A wildcard validation message prints the wire path, and the framework will never soften it

**Status:** open. Found in the bulk manual invoicing drive, 30-31 August. Not a defect in the screen
commit — the wording belongs to the request class — and the mechanism behind it is platform-wide
rather than local to Finance.

## What the operator sees

Forced past the client-side guard, a charge line with no destination account is refused by
`StoreManualInvoiceRunRequest`. The refusal names the field as `lines.0.bank_account_id`. That is a
wire path: an array key, a zero-based index and a column name, shown to a bursar.

## Why the usual softening never applies here

Laravel normally renders `:attribute` through `getDisplayableAttribute`
(`vendor/laravel/framework/src/Illuminate/Validation/Concerns/FormatsMessages.php:275`), whose
fallback at `:308` is `str_replace('_', ' ', Str::snake($attribute))` — the step that turns
`bank_account_id` into `bank account id`.

That line is not reached for a wildcard rule. Nine lines above it, at `:302-306`:

    if (isset($this->implicitAttributes[$primaryAttribute])) {
        return ($formatter = $this->implicitAttributesFormatter)
            ? $formatter($attribute)
            : $attribute;
    }

An attribute produced by expanding `lines.*.bank_account_id` is IMPLICIT, so the framework returns
the raw name unmodified and deliberately — the comment above it says so. **Every wildcard rule on
this platform prints its wire path**, and no amount of rewording one message changes that.

## Scope, measured

22 wildcard rule keys across four Finance request surfaces:

- `app/Finance/Http/Requests/AllocatePaymentRequest.php` (`allocations.*.…`)
- `app/Finance/Http/Requests/Concerns/HasFeeScheduleItemRules.php` (`items.*.…`)
- `app/Finance/Http/Requests/GenerateInvoiceRequest.php` (`lines.*.…`)
- `app/Finance/Http/Requests/StoreManualInvoiceRunRequest.php` (`lines.*.…`)

None of the four declares `attributes()`. The house pattern exists and Academics uses it —
`attributes` (`app/Http/Requests/StoreClassLevelParticipationRequest.php:71`) maps `term_order` to
`term slot` — so this is an unused pattern rather than a missing one.

## Nothing pins any of these strings

Every test asserts the error KEY, not the message:

    ->assertJsonValidationErrors(['lines.0.bank_account_id'])

That is the right assertion — the key is the contract the client reads, and the message is not. It
is also the reason the message can drift to anything at all without a gate noticing. A ticket, not a
gate, is the honest instrument here: a lint that demanded `attributes()` on every request with a
wildcard rule would be satisfied by an empty array.

## What closes it, and the trade-off inside it

`attributes()` keyed on the WILDCARD form works: `getDisplayableAttribute()` checks the expanded
name and then the primary (`lines.*.bank_account_id`), so one entry covers every index.

The cost is that it covers every index. `'lines.*.bank_account_id' => 'destination account'` renders
`The destination account field is required.` — readable, and silent about WHICH line. On the manual
run screen that is survivable because the client highlights the offending row; on a surface where it
does not, the row number is the only thing the operator needs.

So the choice is a decision rather than a preference:

- name the attribute and let the screen carry the row, or
- write a `messages()` entry that keeps the index and reads as a sentence, or
- have the screen map the error key to its own field label and never show the server string.

**Do not close it by hardcoding a row number into a message string.** `lines.0` is not always line
one to the operator, and a message that says so will be wrong the first time a row is removed before
submit.
