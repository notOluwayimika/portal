<?php

namespace App\Jobs;

use App\Jobs\Middleware\SchoolAware;
use App\Models\Guardian;
use App\Notifications\GuardianAnnouncementNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BulkMessageGuardiansJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly array $guardianIds,
        public readonly int $schoolId,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $channels,
    ) {}

    public function middleware(): array
    {
        return [new SchoolAware];
    }

    public function handle(): void
    {
        Guardian::whereIn('id', $this->guardianIds)
            ->where('school_id', $this->schoolId)
            ->with('user')
            ->get()
            ->each(function (Guardian $guardian) {
                $user = $guardian->user;

                if (! $user || ! $user->email || str_ends_with($user->email, '@no-email.local')) {
                    return;
                }

                $user->notify(new GuardianAnnouncementNotification($this->subject, $this->body));
            });
    }
}
