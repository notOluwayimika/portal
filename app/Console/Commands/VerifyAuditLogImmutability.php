<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Post-restore / post-deploy assertion that the audit-log immutability triggers
 * exist (§15C). The model guard is application code and always present, but it
 * does NOT stop raw SQL or a mass ->delete() — the BEFORE UPDATE/DELETE triggers
 * on activity_log are the layer a database restore can silently strip
 * (mysqldump --triggers is frequently disabled by managed-DB export tooling; a
 * logical restore can land the table without them). Without them the audit log
 * is quietly mutable again and nothing else reports it.
 *
 * Run this after ANY database restore, and in the deploy pipeline after
 * migrations. Exit 0 = both triggers present; exit 1 = missing (fail loudly).
 */
class VerifyAuditLogImmutability extends Command
{
    protected $signature = 'audit:verify-immutability';

    protected $description = 'Assert the activity_log immutability triggers exist (fail loudly if a restore stripped them)';

    private const REQUIRED = ['activity_log_no_update', 'activity_log_no_delete'];

    public function handle(): int
    {
        $present = DB::table('information_schema.triggers')
            ->where('trigger_schema', DB::getDatabaseName())
            ->where('event_object_table', 'activity_log')
            ->selectRaw('LOWER(trigger_name) AS name')
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff(self::REQUIRED, $present));

        if ($missing === []) {
            $this->info('audit-log immutability OK — both activity_log triggers are present.');

            return self::SUCCESS;
        }

        $this->error('AUDIT LOG IS MUTABLE: missing immutability trigger(s): '.implode(', ', $missing).'.');
        $this->line('The audit log can be edited/deleted by raw SQL until this is fixed (§15C).');
        $this->line('Remediate: re-run `php artisan migrate` (the 2026_07_18_200000 migration recreates the triggers), '
            .'or restore with triggers included (mysqldump --triggers).');

        return self::FAILURE;
    }
}
