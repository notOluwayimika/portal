# `reissueCredentialsIfPossible` rotates a password and mails it from inside a transaction

**Raised by:** the second cold review of `fix/guardian-create-duplicates` (finding 3).

**The `/api/guardians` half is CLOSED** by that branch's third round, and closed
structurally rather than by care: `GuardianController::store` no longer enters
`attachToStudent`'s existing-pivot branch at all, because a create form may not rewrite
an existing link. The general problem is still open on the other callers, which is what
this ticket is for.

## The mechanism

`GuardianService::reissueCredentialsIfPossible` does two things in order:

```php
$user->update(['password' => $plainPassword]);
$this->notifyGuardian($user, $plainPassword, [$student->full_name]);
```

It is called from `attachToStudent`'s existing-pivot branch on a `can_login` false→true
transition. `attachToStudent` has no transaction of its own — it inherits whatever its
caller opened.

**If the surrounding transaction later rolls back, the password write is undone and the
email carrying that password has already left.** The guardian is then holding a mailed
credential that authenticates nothing, and the account's real password is whatever it
was before. Nothing reports this: `notifyGuardian` catches and logs its own throwables
(`GuardianService.php`), so even a send failure is invisible to the caller.

## Where it is still reachable

| Caller | Transaction | Reaches the false→true branch |
| --- | --- | --- |
| `GuardianController::store` | yes, spans every link | **No** — the already-linked guard skips the branch entirely |
| `GuardianController::attach` | no transaction | Yes, but nothing to roll back |
| `StudentController::processGuardianEntry` | **yes**, spans student + every guardian | **Yes** |
| `GuardianImportService::createAndAttach` | yes, per row | Yes, via `attachExisting` |

So the live shapes are student registration — where a second guardian entry failing
rolls back a password already mailed for the first — and the spreadsheet import.

**The pattern that solves it already exists in the same codebase and is used two
methods away.** `StudentController::store` collects `$deferredNotifications` inside the
transaction and flushes them after it commits, with the comment *"Notifications run
after the transaction commits so a rollback can't strand emails."* `GuardianImportService`
does the same thing with `deferredNotifications` + `flushNotifications()`. The reissue
path is the one credential mailer that never adopted it.

## What closing it looks like

1. Give `reissueCredentialsIfPossible` the deferral shape its siblings have: return the
   `(user, plainPassword, studentNames)` tuple rather than sending, and let the caller
   flush after commit. That is a signature change across `attachToStudent`'s callers,
   which is why it is a ticket and not a drive-by.
2. Audit the rest of `app/` for `notify(` / `Notification::send` reachable from inside
   a `DB::transaction`. This ticket was written from one instance found by a reviewer;
   nothing establishes it is the only one.
3. While in there: `attachToStudent`'s `can_login` true→false transition does **not**
   call `cascadeDisableIfNoLoginPivots`, while `updatePivot`'s does
   (`GuardianService.php`). Two pivot writers, two different behaviours on the same
   transition — a separate divergence, noticed in the same reading, and worth folding
   into the same fix.
