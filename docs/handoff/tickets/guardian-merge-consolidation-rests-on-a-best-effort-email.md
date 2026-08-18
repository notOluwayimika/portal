# TICKET — `--consolidate-login`'s safety argument rests on an email that is allowed to fail silently

**Status:** open, not implemented. Raised in review of `feat/guardian-merge-command` and ruled a ticket:
the swallow is pre-existing `notifyGuardian` behaviour shared with `enableLogin` and `resendInvitation`,
the resulting state is repairable through the existing login-enable path, and the parent can still reach
the surviving address through the password-reset broker. What is new is only that a failed send now
lands on a parent whose *other* account was ended in the same operation.

## The distinction that is not mechanised

`GuardianService::assertLoginDecisionAllowed` refuses `--consolidate-login` when the keeper's account
has no deliverable address, and the merge docblock calls that "the whole reason the consolidation is
safe". It is not the same claim the safety argument needs:

- **What is checked:** an address EXISTS and is not the synthetic sentinel — `hasDeliverableEmail()` →
  `deliverableEmailAddress()`, a string test (or a contact-point read once the backfill marker flips).
- **What the argument requires:** the credentials were DELIVERED, because the parent's previous
  password has just been invalidated on an account that has just been disabled.

Only the first is enforced. Nothing anywhere observes the second.

## The mechanism

`notifyGuardian` (`app/Services/GuardianService.php`) does two things that matter here, both by design
and both pre-existing:

1. it returns early and silently when `hasDeliverableEmail()` is false, and
2. it wraps the `notify()` call in `try { … } catch (\Throwable $e) { Log::error(…) }` — "best-effort:
   failures are logged but do not abort the request", per its own docblock.

`merge()` calls it after the transaction returns and ignores the return value (there is none).
`MergeGuardians` then prints `APPLIED.` and exits `0`.

## What it costs

`--consolidate-login` commits. The donor account is disabled and the keeper's password is rotated. The
send then fails — transport error, queue worker down, an address that is well-formed and undeliverable.
The parent now has a working password on neither account, has been told nothing, and the operator's
console reported success. Recovery depends on somebody reading a `Log::error` line.

The reset broker is a genuine fallback (the surviving address is real, so `sendResetLink` resolves it),
which is why this is a ticket and not a fix. But it is a fallback the parent has to think to use, for a
change they did not ask for and were not told about.

## The shape a fix takes

Cheapest honest version: have `notifyGuardian` return a `bool`, and have `MergeGuardians` print a loud
failure line naming the account — `credentials email FAILED for user#<id> — re-issue manually with
guardians:resend-invitation` — and exit non-zero even though the merge committed. The merge must NOT be
rolled back for a send failure: the state is correct and re-sending is cheap, while unwinding a
committed consolidation is not.

Stronger version, if consolidation ever becomes routine: queue the notification with retries and have
the command report the job id, so "was this parent told" has an answer that is not a log grep.

## Not this ticket

Changing `notifyGuardian`'s best-effort contract for its other five callers. That is a wider decision
about how the application treats mail failures generally, and it should not be made as a side effect of
a merge command.
