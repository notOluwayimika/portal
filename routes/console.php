<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
Schedule::command('authz:prune --older-than=30')
    ->daily()
    ->description('Prune authz_observations older than the 30-day rollout retention window (ADR 0043 §4)');

// Wallet drift detector (§15F). UNLIKE authz:prune, this reads School-owned data
// (finance_student_accounts + the ledger), so the command iterates Schools
// explicitly via ActiveSchool::runFor — the §5.4 rule the prune above is exempt
// from. Detect-only by default; a drifted account exits non-zero.
Schedule::command('finance:reconcile-accounts')
    ->daily()
    ->description('Reconcile finance_student_accounts.balance_minor against SUM(signed ledger); report drift (§15F)');

// Document↔ledger coherence detector (ADR 0047). reconcile-accounts trusts the ledger and
// checks the projection against it; THIS checks the ledger against the documents that
// produced it — the boundary nothing else guards. Also School-scoped (runFor). Detect-only:
// there is no --fix (append-only ledger, unknowable right side — see the command docblock).
Schedule::command('finance:audit-ledger-coherence')
    ->daily()
    ->description('Verify the subledger is coherent with its source documents; exit non-zero on incoherence (ADR 0047)');

// Segregation-of-duties detector (shipped in 73f47f7, never scheduled until now). The
// grant-time enforcement slice relies on this as its backstop for the paths enforcement
// cannot reach (raw model_has_roles inserts, role_has_permissions edits outside the matrix),
// so it must actually run somewhere. Read-only; exits non-zero on any both-sides user. It is
// School-agnostic in its own iteration (it scans model_has_roles across schools internally),
// so no runFor wrapper here — like authz:prune, and unlike the two finance commands above.
Schedule::command('finance:audit-duty-separation')
    ->daily()
    ->description('Per school, list users holding BOTH sides of any maker-checker pair; exit non-zero on findings');
