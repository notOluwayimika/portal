<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| This is the first scheduled task in the codebase — the registration point
| for all future scheduling (§5.4). Run the scheduler with `php artisan
| schedule:work` (dev) or a single system cron entry calling `schedule:run`
| every minute (prod).
|
| §5.4 requires School-scoped scheduled commands to iterate Schools explicitly
| (ActiveSchool::runFor per School). `authz:prune` is deliberately EXEMPT: it
| deletes rollout-evidence rows by AGE across the whole table and reads no
| School-owned data, so it is School-agnostic and must NOT iterate Schools.
| Future commands that touch School-owned data DO need the runFor loop — this
| exemption is specific to age-based pruning, not a precedent for them.
*/
/*
| OBSERVING FAILURE — read this before adding a sixth task.
|
| Every command below is a DETECTOR: it exits non-zero to say something is wrong. Until now that
| signal went nowhere. `finance:audit-duty-separation` has been scheduled since 43dfbe8 (2026-07-25)
| and has exited non-zero on EVERY run — 10 pre-existing result.* findings on the production copy —
| with no onFailure, no emailOutputOnFailure, no sendOutputTo and no ping registered anywhere in this
| file. Four detectors signalling into nothing, one of them failing daily for eleven days.
|
| `observed()` is the floor, not the ceiling: a Log::error naming the command and its exit code, so a
| failure is findable in the log the app already keeps. It is applied to ALL of them — fixing it for
| the two RBAC ones and leaving the finance ones silent would be arbitrary.
|
| THESE RUN IN PRODUCTION. `schedule:run` is in the production cron — confirmed by the project lead,
| 2026-08-05. So the exit codes below and the onFailure log lines they trigger are live signal from
| the server, not something that only fires on a developer machine when someone happens to run
| `schedule:work`. That is what makes the baseline worth having: a nightly non-zero on the real
| database now means something, and it reaches a real log.
|
| THE MAIL RECIPIENT IS CONFIGURATION, not a hard-coded address: `monitoring.alerts.recipients`, from
| the comma-separated `MONITORING_ALERT_RECIPIENTS`. There is no default, deliberately — inventing an
| address is how an alert arrives somewhere nobody reads, which is worse than no alert because it
| looks like coverage.
|
| WITH NONE SET, Log::error is the whole channel, and that is a SUPPORTED state rather than a broken
| one: a failure stays findable in the log the app already keeps, it is simply not pushed to anyone.
|
| Log::error IS THE FLOOR AND IS ATTACHED UNCONDITIONALLY, mail only on top of it. That asymmetry is
| deliberate: `emailOutputOnFailure` fails SILENTLY when the mailer is misconfigured — and
| `.env.example` still points MAIL_HOST at smtp.mailtrap.io, so "misconfigured" is the state a fresh
| deployment starts in. A channel that can vanish without saying so cannot be the only one.
|
| AND THE ATTACH ORDER BELOW IS LOAD-BEARING, not stylistic. `Event::callAfterCallbacks` (vendor
| Event.php:251-256) is a bare foreach with no try/catch, so an exception in one callback aborts every
| callback registered AFTER it. Log::error is attached FIRST for that reason alone: a mailer that
| throws — misconfigured host, refused connection, empty recipient list — must not be able to take the
| log line with it on the one run that was already failing. Swap the two statements and the floor
| disappears exactly when it is needed. ScheduleTest pins the order with a throwing mailer.
|
| SETTING A RECIPIENT ALSO WRITES TO DISK, which is a second surface behind one switch and is easy to
| miss. `emailOutputOnFailure` calls `ensureOutputIsBeingCaptured()` (vendor Event.php:435), which
| calls `sendOutputTo(storage_path('logs/schedule-'.sha1($mutexName).'.log'))` — one file per event,
| named by the sha1 of its mutex, and written on EVERY run including the successful ones, not only on
| failure and not only to the inbox. `sendOutputTo`'s `$append` defaults to false (vendor
| Event.php:375-382), so it is five files overwritten daily rather than unbounded growth.
|
| What lands in them matters: `finance:audit-duty-separation` prints ids only (`user#<id>`,
| `school#<id>`), but `finance:check-staffing-readiness` prints school DISPLAY NAMES in its first
| column. So configuring a recipient puts those names on the server's disk as well as in someone's
| mail — two surfaces, one switch. Recorded here rather than changed; the disclosure decision is the
| project lead's.
|
| The Event parameter is injected by name (`Event::eventParametersForCallback`), and `$event->exitCode`
| is public and set in `finish()` — so the log line carries WHAT failed and HOW, not just that
| something did.
*/
/** @var list<string> $alertRecipients Read ONCE — this file is evaluated at boot, not per run. */
$alertRecipients = (array) config('monitoring.alerts.recipients', []);

$observed = function (Event $event, string $command) use ($alertRecipients): Event {
    $event->onFailure(function (Event $event) use ($command): void {
        Log::error('Scheduled detector failed', [
            'command' => $command,
            'exit_code' => $event->exitCode,
        ]);
    });

    if ($alertRecipients !== []) {
        $event->emailOutputOnFailure($alertRecipients);
    }

    return $event;
};

$observed(
    Schedule::command('authz:prune --older-than=30')
        ->daily()
        ->description('Prune authz_observations older than the 30-day rollout retention window (ADR 0043 §4)'),
    'authz:prune',
);

// Wallet drift detector (§15F). UNLIKE authz:prune, this reads School-owned data
// (finance_student_accounts + the ledger), so the command iterates Schools
// explicitly via ActiveSchool::runFor — the §5.4 rule the prune above is exempt
// from. Detect-only by default; a drifted account exits non-zero.
$observed(
    Schedule::command('finance:reconcile-accounts')
        ->daily()
        ->description('Reconcile finance_student_accounts.balance_minor against SUM(signed ledger); report drift (§15F)'),
    'finance:reconcile-accounts',
);

// Document↔ledger coherence detector (ADR 0047). reconcile-accounts trusts the ledger and
// checks the projection against it; THIS checks the ledger against the documents that
// produced it — the boundary nothing else guards. Also School-scoped (runFor). Detect-only:
// there is no --fix (append-only ledger, unknowable right side — see the command docblock).
$observed(
    Schedule::command('finance:audit-ledger-coherence')
        ->daily()
        ->description('Verify the subledger is coherent with its source documents; exit non-zero on incoherence (ADR 0047)'),
    'finance:audit-ledger-coherence',
);

// Segregation-of-duties detector (shipped in 73f47f7, never scheduled until now). The
// grant-time enforcement slice relies on this as its backstop for the paths enforcement
// cannot reach (raw model_has_roles inserts, role_has_permissions edits outside the matrix),
// so it must actually run somewhere. Read-only; exits non-zero on any both-sides user. It is
// School-agnostic in its own iteration (it scans model_has_roles across schools internally),
// so no runFor wrapper here — like authz:prune, and unlike the two finance commands above.
// --baseline is what makes this task's exit code mean something again. Without it the command has
// exited non-zero every night since it was scheduled, because the production copy carries 10
// pre-existing result.* findings — so "failed" carried no information and nothing could act on it.
// With the baseline, non-zero stops meaning "this database has findings" and starts meaning
// "something appeared that nobody has accepted". A finance finding fails regardless of the file.
$observed(
    Schedule::command('finance:audit-duty-separation --baseline='.base_path('duty-separation-baseline.txt'))
        ->daily()
        ->description('FAILURE MEANS: a user holds both sides of a maker-checker pair that is NOT in the accepted baseline — or holds both sides of a FINANCE pair, which is never acceptable'),
    'finance:audit-duty-separation',
);

// Not scheduled at all until now, which was the other half of the gap: the platform could be
// configured into a state where a module can never approve anything, and nothing would say so until
// a bursar tried. Its pair with the audit above — that one says "nobody holds both", this says
// "enough people hold each".
$observed(
    Schedule::command('finance:check-staffing-readiness')
        ->daily()
        ->description('FAILURE MEANS: some school cannot run a two-person approval flow — a maker-checker pair there has no holder, or the only holders are the same user, so that module cannot approve anything until it is staffed'),
    'finance:check-staffing-readiness',
);
