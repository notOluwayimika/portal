<?php

namespace App\Jobs;

use App\Jobs\Middleware\SchoolAware;
use App\Models\Guardian;
use App\Models\User;
use App\Services\GuardianService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Activitylog\CauserResolver;

class BulkEnableGuardianLoginJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly array $guardianIds,
        public readonly int $schoolId,
        public readonly int $causedByUserId,
    ) {}

    public function middleware(): array
    {
        return [new SchoolAware];
    }

    public function handle(GuardianService $service): void
    {
        $causer = User::find($this->causedByUserId);

        // Audit attribution only — never auth()->setUser() (§5.6): the causer is
        // not an execution identity, and School context comes solely from the
        // declared schoolId via SchoolAware/runFor().
        if ($causer) {
            app(CauserResolver::class)->setCauser($causer);
        }

        try {
            Guardian::whereIn('id', $this->guardianIds)
                ->where('school_id', $this->schoolId)
                ->with('user')
                ->get()
                ->each(function (Guardian $guardian) use ($service, $causer) {
                    $service->enableLogin($guardian, $guardian->students()->pluck('first_name')->toArray());

                    activity('guardian')
                        ->performedOn($guardian)
                        ->causedBy($causer)
                        ->event('bulk_login_enabled')
                        ->log('Login enabled via bulk action');
                });
        } finally {
            // Long-running workers reuse the process: never leak the causer
            // into the next job.
            app(CauserResolver::class)->setCauser(null);
        }
    }
}
