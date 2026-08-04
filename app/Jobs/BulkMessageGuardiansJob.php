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
            // `user.contactPoints`, not just `user`: post-cutover the deliverability
            // predicate resolves through the contact point, so loading the user alone
            // leaves one query per guardian — the N+1 this cutover would otherwise
            // introduce rather than remove.
            ->with('user.contactPoints')
            ->get()
            ->each(function (Guardian $guardian) {
                $user = $guardian->user;

                if (! $user?->hasDeliverableEmail()) {
                    return;
                }

                $user->notify(new GuardianAnnouncementNotification($this->subject, $this->body));
            });
    }
}
