<?php

namespace App\Jobs\Middleware;

use App\Support\ActiveSchool;
use Closure;

/**
 * Job middleware that runs handle() inside the job's School context.
 *
 * A SchoolAware job carries `public readonly int $schoolId` and adds
 * `new SchoolAware()` to its middleware(). Combined with runFor()'s
 * finally-restore, two jobs for different Schools can run back-to-back on one
 * worker without the second inheriting the first's team id — and jobs no longer
 * need to impersonate a causer via auth()->setUser() to obtain context.
 */
class SchoolAware
{
    public function handle(object $job, Closure $next): mixed
    {
        return ActiveSchool::runFor($job->schoolId, fn () => $next($job));
    }
}
