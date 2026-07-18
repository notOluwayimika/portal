<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Overrides Spatie's activitylog:clean. The audit log is permanent (§15C) — it
 * must never be pruned. This refuses instead of deleting; the BEFORE DELETE
 * trigger on activity_log is the backstop if the spatie command is somehow still
 * reached (its mass ->delete() would SIGNAL an error at the database).
 *
 * The signature mirrors spatie's so this command wins the `activitylog:clean`
 * name and its options do not error.
 */
class ActivitylogClean extends Command
{
    protected $signature = 'activitylog:clean
                            {log? : (ignored) log name}
                            {--days= : (ignored) age in days}
                            {--force : (ignored)}';

    protected $description = 'DISABLED — the audit log is permanent and immutable (§15C); it is never pruned.';

    public function handle(): int
    {
        $this->error('activitylog:clean is disabled: the audit log is append-only and permanent (Constitution §15C).');
        $this->line('No records were deleted. Immutability is also enforced by BEFORE UPDATE/DELETE database triggers.');

        return self::FAILURE;
    }
}
