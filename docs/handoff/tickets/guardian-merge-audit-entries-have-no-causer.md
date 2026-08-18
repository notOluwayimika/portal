# TICKET — merge audit entries record `causer_id = NULL`, so "who ran this" has no answer

**Status:** open, not implemented. Raised in review of `feat/guardian-merge-command` and classed a
ticket: the entries themselves are complete and correct, only attribution is missing, and there is no
authenticated principal to attribute to today.

**It becomes a fix the moment the admin merge UI calls `merge()`** — at that point a real causer exists,
and dropping it would be losing information the request already has rather than recording information
nobody has.

## The mechanism

`GuardianService::merge` writes its trail through `causedBy(auth()->user())` — the `merged` entry, the
per-link `attached` / `detached` entries via `logPivotEvent`, and the `login_enabled` /
`login_disabled` entries a consolidation emits.

`MergeGuardians::handle` runs off-request. It establishes school context through
`ActiveSchool::runFor()` from the keeper guardian's own `school_id` and never authenticates anybody —
which is correct, and deliberately so: Constitution 13 forbids `auth()->setUser($causer)` for context,
and ADR 0036/0042 forbid defaulting a school from `users.school_id`. So `auth()->user()` is null and
every entry the command can currently produce carries `causer_id = NULL`.

## What it costs

The guardian audit page (`resources/js/pages/admin/guardians/audit.tsx`) renders a merge that
soft-deleted a guardian record, hard-deleted pivot rows and possibly disabled a parent's portal account,
with no answer to "who did this". The *what* is now complete — student ids, the relationship and both
booleans as the deleted row held them — and the *who* is absent.

This is the same class of hole as the one `attachToStudent`'s comment records: an access change with no
attribution is not much better than an access change with no record, once someone is asking why.

## The shape a fix takes

Two halves, and they are independent:

1. **Console.** An `--as=<user uuid>` option resolving a real `User` and passing it through to the
   activity calls, or — weaker but zero-friction — a `properties` key recording the invocation
   (`'via' => 'console'`, hostname, PID). The first is auditable, the second is a breadcrumb. Do not
   `auth()->setUser()` to achieve either: that is exactly the pattern Constitution 13 exists to stop,
   and it would silently re-establish school context as a side effect.
2. **Admin UI.** When a controller calls `merge()`, `auth()->user()` is populated and the existing
   `causedBy` already does the right thing — but nothing pins that. An arm asserting the causer
   survives the UI path is what turns it from "works today" into a rule.

## Not this ticket

Back-filling attribution onto merges already run. There is nothing to back-fill it from.
