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
| DELIBERATELY NOT emailOutputOnFailure. That needs a real recipient, and inventing one is how an
| alert arrives somewhere nobody reads. The address is the project lead's to give; when it exists,
| add `->emailOutputOnFailure($address)` here — the hook is one line and the reasoning is already
| written down.
|
| The Event parameter is injected by name (`Event::eventParametersForCallback`), and `$event->exitCode`
| is public and set in `finish()` — so the log line carries WHAT failed and HOW, not just that
| something did.
*/
$observed = function (Event $event, string $command): Event {
    return $event->onFailure(function (Event $event) use ($command): void {
        Log::error('Scheduled detector failed', [
            'command' => $command,
            'exit_code' => $event->exitCode,
        ]);
    });
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
