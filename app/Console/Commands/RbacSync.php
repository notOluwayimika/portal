<?php

namespace App\Console\Commands;

use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;

class RbacSync extends Command
{
    protected $signature = 'rbac:sync
        {--fresh : Reset every role\'s grants exactly to the seeded defaults (discards runtime matrix edits)}';

    protected $description = 'Sync roles, permissions and default grants from the RbacSeeder map. '
        .'Non-destructive by default: runtime grant/revoke edits survive; --fresh resets to defaults.';

    public function handle(): int
    {
        $fresh = (bool) $this->option('fresh');

        if ($fresh && $this->getLaravel()->environment('production')
            && ! $this->confirm('--fresh DISCARDS runtime grant edits in production. Continue?')) {
            return self::FAILURE;
        }

        (new RbacSeeder)->sync(fresh: $fresh);

        $this->info($fresh
            ? 'rbac:sync — roles/permissions synced; grants RESET to seeded defaults.'
            : 'rbac:sync — roles/permissions synced; existing grants preserved (non-destructive).');

        return self::SUCCESS;
    }
}
