# TICKET — consolidation rotates the keeper's password even when that account is working, and an arm pins it

**Status:** open, not implemented. Raised in review of `feat/guardian-merge-command` and ruled a ticket:
no data is wrong, the parent can recover from the credentials email or a reset, and on today's
population no keeper account is in active use (0 of the 28 accounts across the 14 duplicate groups has
`email_verified_at` set). Recorded because **a test now pins the behaviour, which makes an incidental
choice look decided.**

**Amended after a later review round: the original framing below was derived from single-school
reasoning and understates the case.** See *The cross-school case* — the rotation lands on an account
that may serve other schools, and the email that carries the replacement names only this school's
children. Whoever picks this up is choosing against the wider case, not the narrow one.

## What happens

`GuardianService::applyLoginConsolidation` runs, whenever `consolidating` is true:

```php
$user->update([
    'disabled_at' => null,
    'password' => $plainPassword,
]);
```

The parent is then emailed the new password.

**The `disabled_at` half of that write is now gated** — consolidation refuses when the keeper account
is disabled and still serves another school — but **the password half is not**. Nothing tests whether
the keeper's account was unusable or working perfectly well before it is rotated, and no refusal
depends on the answer. That asymmetry inside one `update()` is deliberate as far as it goes (clearing
`disabled_at` can restore access another school revoked; rotating a password cannot restore anything)
and it is exactly why the rotation needs its own decision rather than inheriting the gate's.

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

## The cross-school case — added, and it is the reason this ticket was amended

`users` rows are shared across schools by design (§6.2; `resolveOrCreateGuardianForUserInSchool` exists
to produce that shape). So the keeper account this consolidation rotates may be the account a parent
uses at **another school**, and:

- the password rotation invalidates their sign-in for that school too, as a side effect of a cleanup
  they were not party to;
- the credentials email is built from `$keeper->students()` — **this school's children only** — so the
  message that carries the replacement password does not mention, and gives no reason to connect it to,
  the other school it also governs;
- nothing in the other school's audit trail records that their parent's password changed.

Access is not lost — the parent holds the new password and can use it anywhere the account works —
which is why this stayed a ticket rather than becoming a fix. The re-ENABLE half of the same write did
become a fix, and is closed: consolidation now refuses when the keeper account is disabled and still
serves another school.

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

## Not this ticket — REVISIT THIS SECTION FIRST

The original version of this ticket parked the notification's contents as "a copy question, separate
from whether a password is rotated at all". **That parking is what needs revisiting**, and it is now
the first thing to decide rather than the thing excluded:

once the rotation is understood to land on an account that may serve several schools, "what does the
email say" stops being copy and becomes part of whether the rotation is safe. A password email that
names one school's children, for a credential that governs two schools, is a message the parent cannot
act on correctly. Decide the email's scope and the rotation's scope together.

Still genuinely out of scope: which address the email is sent to (that is
`guardian-merge-offers-no-basis-for-choosing-the-keeper.md`) and whether the send is observed at all
(that is `guardian-merge-consolidation-rests-on-a-best-effort-email.md`).
