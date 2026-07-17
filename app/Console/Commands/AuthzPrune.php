<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bounds the observe-mode evidence store (S5). authz_observations is temporary
 * rollout evidence (ADR 0043), not the audit log and not permanent telemetry —
 * so it is prunable without ceremony. Two modes:
 *
 *   --older-than=DAYS  drop rows whose occurred_at is older than DAYS (default 30),
 *                      keeping a rolling recent window while the rollout runs.
 *   --all              truncate everything (used at rollout completion, right
 *                      before the table itself is dropped).
 *
 * Growth is naturally low (a row accrues only on a would-be denial), but a
 * misconfigured caller or a hot unauthorized path could still accumulate rows;
 * this command is the deliberate bound so the table can never grow unattended.
 */
class AuthzPrune extends Command
{
    protected $signature = 'authz:prune
        {--older-than=30 : Delete observations older than this many days}
        {--all : Delete every observation (rollout teardown)}
        {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Prune the temporary authz_observations evidence store (ADR 0043)';

    public function handle(): int
    {
        $table = DB::table('authz_observations');

        if ($this->option('all')) {
            $count = (clone $table)->count();
            if ($this->option('dry-run')) {
                $this->info("[dry-run] would delete all {$count} observation(s).");

                return self::SUCCESS;
            }
            $table->truncate();
            $this->info("Deleted all {$count} observation(s).");

            return self::SUCCESS;
        }

        $days = (int) $this->option('older-than');
        if ($days < 0) {
            $this->error('--older-than must be a non-negative number of days.');

            return self::FAILURE;
        }

        // now() is the audit clock, consistent with how observations are stamped
        // on write (Authz::record), so the cutoff is symmetric with insertion.
        $cutoff = now()->subDays($days);
        $stale = (clone $table)->where('occurred_at', '<', $cutoff);
        $count = (clone $stale)->count();

        if ($this->option('dry-run')) {
            $this->info("[dry-run] would delete {$count} observation(s) older than {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $stale->delete();
        $this->info("Deleted {$count} observation(s) older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
