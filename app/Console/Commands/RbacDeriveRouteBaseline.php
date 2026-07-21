<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class RbacDeriveRouteBaseline extends Command
{
    protected $signature = 'rbac:derive-map';

    protected $description = 'Snapshot every route\'s full middleware stack (ordered) to '
        .'tests/fixtures/route-middleware-baseline.json — the pre-swap oracle for the role:→permission: slice.';

    public function handle(): int
    {
        file_put_contents(
            base_path('tests/fixtures/route-middleware-baseline.json'),
            json_encode(self::snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->info('route-middleware-baseline.json written ('.count(self::snapshot()).' routes).');

        return self::SUCCESS;
    }

    /**
     * "METHODS /uri" → the fully-gathered middleware stack, order preserved
     * (ADR 0043 §3 fixes authorization ordering; the fixture must be able to
     * catch a reorder, not just a membership change).
     *
     * @return array<string, list<string>>
     */
    public static function snapshot(): array
    {
        $map = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $methods = implode('|', array_values(array_diff($route->methods(), ['HEAD'])));
            $map[$methods.' /'.ltrim($route->uri(), '/')] = array_values($route->gatherMiddleware());
        }

        ksort($map);

        return $map;
    }
}
