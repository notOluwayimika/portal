# TICKET — consolidation rotates the keeper's password even when that account is working, and an arm pins it

**Status:** open, not implemented. Raised in review of `feat/guardian-merge-command` and ruled a ticket:
no data is wrong, the parent can recover from the credentials email or a reset, and on today's
population no keeper account is in active use (0 of the 28 accounts across the 14 duplicate groups has
`email_verified_at` set). Recorded because **a test now pins the behaviour, which makes an incidental
choice look decided.**

## What happens

`GuardianService::applyLoginConsolidation` runs, whenever `consolidating` is true:

```php
$user->update([
    'disabled_at' => null,
    'password' => $plainPassword,
]);
```

with no test of whether the keeper's account was disabled, unusable, or working perfectly well. The
parent is then emailed the new password.

## The trade-off, named — because it is a real one and it was not stated

**For rotating unconditionally.** After consolidation the parent has exactly one working account, and
the merge cannot know which of the two they had been using. If they were using the donor account, "keep
using your other password" is advice about a password they have never seen. Sending one fresh
credential for the surviving account is the only instruction that is true regardless of which account
they were on.

**Against.** A parent who was using the keeper account daily has that password invalidated by a cleanup
performed on a *different* record, and must fish a new one out of an email they were not expecting.

Neither the code nor the original report named this as a choice; it read as incidental. This ticket
exists to make it a decision someone made rather than a behaviour someone inherited.

## The arm that pins it

`tests/Feature/Guardian/GuardianMergeTest.php`, `consolidates the login when asked: donor disabled,
keeper enabled, parent notified once, after commit`, asserts:

```php
->and(User::find($keeper->user_id)->password)->not->toBe($before)
```

That is a correct assertion about current behaviour and it is **pinning a trade-off, not a
requirement**. Whoever picks this ticket up should expect to change that line, and should not read its
existence as evidence the question was settled.

## The shape a fix takes

Either:

1. **Keep it and say so** — one paragraph in `applyLoginConsolidation`'s docblock stating why the
   rotation is unconditional (the argument above), and a note on the arm that it pins a decision rather
   than an invariant. Cheapest, and defensible.
2. **Condition it** — rotate only when the keeper account is unusable (disabled, or no password set),
   and otherwise notify with instructions that name the surviving address without issuing a new
   password. This needs a second notification shape, which is why it is not the default proposal.

## Not this ticket

The `guardians:merge` notification's contents generally. Whether the email should name the school, the
students or the old address is a copy question, separate from whether a password is rotated at all.
