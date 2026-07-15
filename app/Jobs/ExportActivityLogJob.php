<?php

namespace App\Jobs;

use App\Models\Export;
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
        public readonly int $schoolId,
        public readonly array $filters,
    ) {}

    public function handle(ActivityLogQueryService $queries): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }
        auth()->setUser($user);

        $query = $queries->baseQuery($user, (bool) ($this->filters['include_system'] ?? false));
        $queries->applyFilters($query, $this->filters);

        $export = Export::create([
            'school_id' => $this->schoolId,
            'user_id' => $this->userId,
            'type' => 'activity_log',
            'disk' => 'local',
            'file_name' => 'activity-log-'.now()->format('Ymd_His').'.csv',
            'file_path' => '',
            'expires_at' => now()->addDays(7),
        ]);

        // Exports are partitioned by school and owner and served by DB id (the
        // export uuid), never by a client-supplied filename.
        $path = "exports/{$this->schoolId}/{$this->userId}/{$export->uuid}.csv";

        $severity = ActivitySeverityService::make();
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
                    $a->subject_type ? class_basename($a->subject_type)." #{$a->subject_id}" : '',
                    $a->description,
                ]);
                $count++;
            }
        });

        rewind($handle);
        Storage::disk($export->disk)->put($path, stream_get_contents($handle));
        fclose($handle);

        $export->update(['file_path' => $path, 'row_count' => $count]);

        $user->notify(new ActivityLogExportReadyNotification(
            url("/api/activity-logs/exports/{$export->uuid}"),
            $count,
        ));
    }
}
