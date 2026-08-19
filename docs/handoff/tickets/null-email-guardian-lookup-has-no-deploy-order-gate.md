# TICKET — the null-email/uniqueness deploy order is a rule with no mechanism

**Status:** open, not implemented. Raised by `feat/guardian-uniqueness-constraint`. The ordering
itself has been accepted by the project lead; what is missing is anything that would *enforce* it.

## The ordering

`2026_08_19_100000_add_guardian_live_identity_uniqueness` must not reach an environment whose
`GuardianService::createGuardianWithUser` still resolves a null email through
`User::where('email', $userEmail)->first()`.

`fix/guardian-create-duplicates` merges to `staging` before this branch and carries the guard:

```php
$user = $userEmail ? User::where('email', $userEmail)->first() : null;
```

The guard is deliberately **not** duplicated on the uniqueness branch — two copies of one fix collide
at merge. So the correctness of the deploy depends entirely on branch order.

## Why the order matters

A guardian with no email is legal: `GuardianRequest` requires an email only when `can_login` is true,
and `2026_08_04_160000_make_users_email_nullable.php` exists to support exactly that.

`Illuminate\Database\Query\Builder::where()` converts a null value into `whereNull`, so the
unguarded lookup returns **the first account in the system with a NULL email** — an unrelated person.

- **Before the index:** that silently bound the new guardian to a stranger's account. A wrong row,
  no error.
- **After the index:** the first email-less guardian in a school creates a NULL-email account; every
  subsequent email-less guardian in that school resolves to it and trips
  `guardians_live_identity_unique`. MySQL raises 1062, which `bootstrap/app.php:197` renders as a
  bare 409 "Duplicate entry detected."

Through `StudentController::store` the guardian work runs inside the student's `DB::transaction`, so
the refusal **rolls back the entire student registration**. The operator is told "duplicate entry"
about a duplicate they cannot see, on a student they were creating for the first time.

Accounts with a NULL email numbered **0** on the production copy when this was written, so the trap
is prospective. It springs on the first email-less guardian after deploy, not on deploy.

## Why this is a ticket and not a paragraph in a docblock

Per `finance-method`: a convention written in a doc, a comment or a brief is a wish. Both the
migration docblock and the implementation report now state the ordering; nothing fails a build if it
is violated. A release that cherry-picks the migration ahead of the fix, or a hotfix branch cut from
before it, gets no signal at all.

## The shape of the mechanism — specified, not built

Either of these would do; the first is cheaper and fails at the right moment.

**A migration-time pre-flight.** In `up()`, before the `ALTER TABLE`, read
`app/Services/GuardianService.php` and abort if the unguarded call is still present:

```php
$source = file_get_contents(app_path('Services/GuardianService.php'));
if (str_contains($source, "User::where('email', \$userEmail)->first()")
    && ! str_contains($source, "\$userEmail ? User::where('email', \$userEmail)->first() : null")) {
    throw new RuntimeException(
        'Refusing to add guardians_live_identity_unique: GuardianService still resolves a null '
        .'email through User::where(). Land fix/guardian-create-duplicates first. See '
        .'docs/handoff/tickets/null-email-guardian-lookup-has-no-deploy-order-gate.md'
    );
}
```

The throw rolls the migration back and the deploy stops with an actionable message. The precedent for
a migration refusing to run against a state it cannot safely constrain is
`2026_07_16_000003_add_guardian_student_same_school_constraint.php` and the promotion-link guard in
the S1 closure — both abort with an instruction rather than proceeding.

Its weakness is that it is a source-string match, which drifts if the call is reformatted. That is
acceptable for a guard whose whole job is to survive one merge window, but it should be deleted once
both branches are on `main` rather than left to rot into a false green.

**Or a behavioural test.** An arm that creates two email-less guardians in one school through the
real `GuardianService` and asserts both succeed with *different* `user_id`s. It fails today on
`staging` and passes once the fix lands, so it is a permanent statement of the invariant rather than
a one-window guard — but it does not stop a deploy, it only fails a suite.

**Do not** close this by duplicating the guard onto the uniqueness branch. That is the thing the
ordering exists to avoid.
