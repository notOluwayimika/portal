<?php

namespace App\Jobs;

use App\Models\Guardian;
use App\Models\User;
use App\Services\GuardianService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BulkEnableGuardianLoginJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly array $guardianIds,
        public readonly int $schoolId,
        public readonly int $causedByUserId,
    ) {}

    public function handle(GuardianService $service): void
    {
        $causer = User::find($this->causedByUserId);

        Guardian::whereIn('id', $this->guardianIds)
            ->where('school_id', $this->schoolId)
            ->with('user')
            ->get()
            ->each(function (Guardian $guardian) use ($service, $causer) {
                auth()->setUser($causer);
                $service->enableLogin($guardian, $guardian->students()->pluck('first_name')->toArray());

                activity('guardian')
                    ->performedOn($guardian)
                    ->causedBy($causer)
                    ->event('bulk_login_enabled')
                    ->log('Login enabled via bulk action');
            });
    }
}
