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
