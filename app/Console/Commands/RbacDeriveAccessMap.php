<?php

namespace App\Console\Commands;

use App\Support\RouteAccessMap;
use Illuminate\Console\Command;

class RbacDeriveAccessMap extends Command
{
    protected $signature = 'rbac:derive-access';

    protected $description = 'Snapshot every route\'s allowed-role set to '
        .'tests/fixtures/route-access-map.json — the access oracle the role:→permission: swap must preserve. '
        .'Regenerating against routes with permission: middleware reads grants from the connected DB (run rbac:sync first).';

    public function handle(): int
    {
        $map = RouteAccessMap::derive();

        file_put_contents(
            base_path('tests/fixtures/route-access-map.json'),
            json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->info('route-access-map.json written ('.count($map).' routes).');

        return self::SUCCESS;
    }
}
