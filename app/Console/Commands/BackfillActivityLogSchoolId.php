<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Support\ActivitySchoolResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('activity-log:backfill-school-id {--dry-run : Report what would change without writing} {--chunk-size=1000 : Rows processed per chunk} {--since= : Only backfill rows created on/after YYYY-MM-DD}')]
#[Description('Backfill the school_id column on existing activity_log rows from their causer/subject.')]
class BackfillActivityLogSchoolId extends Command
{
    public function handle(ActivitySchoolResolver $resolver): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk-size'));
        $since     = $this->option('since');

        $query = Activity::query()
            ->with(['causer', 'subject'])
            ->whereNull('school_id');

        if ($since) {
            $query->where('created_at', '>=', $since . ' 00:00:00');
        }

        $stats = [
            'started_at'  => now()->toIso8601String(),
            'dry_run'     => $dryRun,
            'chunk_size'  => $chunkSize,
            'since'       => $since,
            'processed'   => 0,
            'updated'     => 0,
            'unresolved'  => 0,
            'unresolved_breakdown' => [
                'no_causer_or_subject' => 0,
                'causer_no_school'     => 0,
                'subject_no_school'    => 0,
            ],
        ];

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Backfilling activity_log.school_id...');

        $query->chunkById($chunkSize, function ($activities) use ($resolver, $dryRun, &$stats) {
            $pendingUpdates = [];

            foreach ($activities as $activity) {
                $stats['processed']++;

                $schoolId = $resolver->resolveFromRelations($activity);

                if ($schoolId !== null) {
                    $stats['updated']++;
                    if (! $dryRun) {
                        $pendingUpdates[$schoolId][] = $activity->id;
                    }
                    continue;
                }

                $stats['unresolved']++;
                $stats['unresolved_breakdown'][$this->categorize($resolver, $activity)]++;
            }

            // Group writes by resolved school_id; avoid touching updated_at so
            // the append-only log keeps its original timestamps.
            foreach ($pendingUpdates as $schoolId => $ids) {
                DB::table('activity_log')->whereIn('id', $ids)->update(['school_id' => $schoolId]);
            }
        });

        $stats['finished_at'] = now()->toIso8601String();

        $reportPath = storage_path('logs/activity-log-backfill-' . now()->format('Ymd_His') . '.json');
        file_put_contents($reportPath, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                [$dryRun ? 'Would update' : 'Updated', $stats['updated']],
                ['Unresolved', $stats['unresolved']],
                ['  no causer or subject', $stats['unresolved_breakdown']['no_causer_or_subject']],
                ['  causer has no school', $stats['unresolved_breakdown']['causer_no_school']],
                ['  subject has no school', $stats['unresolved_breakdown']['subject_no_school']],
            ]
        );

        $this->info("Report written to: {$reportPath}");

        return self::SUCCESS;
    }

    private function categorize(ActivitySchoolResolver $resolver, Activity $activity): string
    {
        $hasCauser  = $activity->causer !== null;
        $hasSubject = $activity->subject !== null;

        if (! $hasCauser && ! $hasSubject) {
            return 'no_causer_or_subject';
        }

        if ($hasCauser && $resolver->schoolIdOf($activity->causer) === null && ! $hasSubject) {
            return 'causer_no_school';
        }

        // A subject exists (or causer existed alongside it) but neither yielded a school.
        return 'subject_no_school';
    }
}
