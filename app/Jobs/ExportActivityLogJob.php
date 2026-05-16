<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\ActivityLogExportReadyNotification;
use App\Services\ActivityLog\ActivityLogQueryService;
use App\Services\ActivityLog\ActivitySeverityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Async export for >1000 rows. Re-resolves the same tenant/permission
 * scope as the controller by running the query as the requesting user.
 */
class ExportActivityLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $userId,
        public readonly array $filters,
    ) {
    }

    public function handle(ActivityLogQueryService $queries): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }
        auth()->setUser($user);

        $query = $queries->baseQuery($user, (bool) ($this->filters['include_system'] ?? false));
        $queries->applyFilters($query, $this->filters);

        $severity = ActivitySeverityService::make();
        $filename = "activity-export-{$this->userId}-" . now()->format('Ymd_His') . '.csv';
        $path = "activity-exports/{$filename}";

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['ID', 'Date', 'Log', 'Event', 'Severity', 'Causer', 'Subject', 'Description']);

        $count = 0;
        $query->orderByDesc('activity_log.created_at')->chunk(1000, function ($rows) use ($handle, $severity, &$count) {
            foreach ($rows as $a) {
                fputcsv($handle, [
                    $a->id,
                    $a->created_at?->toIso8601String(),
                    $a->log_name,
                    $a->event,
                    $severity->for($a->log_name, $a->event),
                    $a->causer?->full_name ?? ($a->causer_id ? "Deleted #{$a->causer_id}" : 'System'),
                    $a->subject_type ? class_basename($a->subject_type) . " #{$a->subject_id}" : '',
                    $a->description,
                ]);
                $count++;
            }
        });

        rewind($handle);
        Storage::put($path, stream_get_contents($handle));
        fclose($handle);

        $user->notify(new ActivityLogExportReadyNotification(
            url("/api/activity-logs/exports/{$filename}"),
            $count,
        ));
    }
}
