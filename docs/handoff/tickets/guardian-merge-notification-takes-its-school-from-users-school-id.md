# TICKET — the merge's credentials email names the school from `users.school_id`, which the merge is forbidden to trust

**Status:** open, not implemented. Raised in review of `feat/guardian-merge-command` and ruled a
ticket: it is a branding/copy defect on an email, the helper is pre-existing, and the divergence is
unreachable on today's data.

**Why it is recorded anyway, and the distinction that matters:** `notifyGuardian` is old, but
`merge()` is a **new** caller, and it is a caller that *has* an authoritative school context and does
not pass it. That is the difference between a legacy fallback — a place where nothing better was
available when it was written — and a fresh one, introduced with the correct value in scope. The
baselined legacy fallbacks (ADR 0042) carry expiries precisely so that new ones are not added beside
them.

## The mechanism

`GuardianService::notifyGuardian` resolves the school for the email's subject and body as:

```php
$schoolName = optional($user->school)->name ?? config('app.name');
```

`merge()` runs inside `ActiveSchool::runFor($keeper->school_id, …)` and asserts a non-zero active
school before it does anything (`assertMergeable`). It then calls `notifyGuardian` with the user, the
password and the student names — and not the school.

Constitution 13 is explicit that context must never default from `users.school_id`. Here it is not
being used as *context* — nothing is scoped by it — but it is being used as an *identity*: the name a
parent reads at the top of an email telling them their password changed.

## What it costs

`users.school_id` is the school a user was first created in, not the school a merge is running in. One
`users` row serves a parent at more than one school by design (§6.2). So:

- keeper account whose `users.school_id` is school#B, merged at school#A;
- the parent receives a **school#B-branded** email, containing **school#A's** children, about a
  password rotation performed by **school#A**.

Every fact in the message is true and the heading is wrong, which is the version of this that is
hardest to notice and easiest to distrust. A parent who does not recognise the sender treats a genuine
credentials email as phishing — the failure mode is not confusion, it is the parent correctly ignoring
it.

## The shape a fix takes

Give `notifyGuardian` an explicit school:

```php
public function notifyGuardian(User $user, string $plainPassword, array $studentNames = [], ?School $school = null): void
```

falling back to today's behaviour when the argument is absent, so the five existing callers are
unaffected, and have `merge()` pass the keeper's school. An arm asserting the notification carries the
active school's name when the keeper's `users.school_id` is a different school is what turns it from a
fix into a rule.

## Not this ticket

The other five `notifyGuardian` callers. Each has its own context question and some of them genuinely
have nothing better than `users.school_id` available; converting them is a separate sweep and should be
scoped against the ADR 0042 baseline rather than done opportunistically here.
