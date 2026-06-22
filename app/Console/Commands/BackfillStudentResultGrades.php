<?php

namespace App\Console\Commands;

use App\Models\GradeBoundary;
use App\Models\StudentResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('results:backfill-grades {--dry-run : Report what would change without writing} {--chunk-size=500 : Rows processed per chunk}')]
#[Description('Backfill the grade column on student_results where it is currently null.')]
class BackfillStudentResultGrades extends Command
{
    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk-size'));

        $updated    = 0;
        $noGrade    = 0;
        $processed  = 0;

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Backfilling null grades on student_results...');

        StudentResult::query()
            ->whereNull('grade')
            ->whereNotNull('total_score')
            ->with('curriculumSubject.curriculum')
            ->chunkById($chunkSize, function ($results) use ($dryRun, &$updated, &$noGrade, &$processed) {
                foreach ($results as $result) {
                    $processed++;

                    $curriculum = $result->curriculumSubject?->curriculum;
                    if (!$curriculum) {
                        $noGrade++;
                        continue;
                    }

                    $score = floor((float) $result->total_score);

                    $grade = GradeBoundary::resolveGrade(
                        $curriculum->school_id,
                        $curriculum->exam_type_id,
                        $score,
                    );

                    if ($grade === null) {
                        $noGrade++;
                        continue;
                    }

                    if (!$dryRun) {
                        $result->updateQuietly(['grade' => $grade]);
                    }
                    $updated++;
                }
            });

        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $processed],
                [$dryRun ? 'Would update' : 'Updated', $updated],
                ['No matching grade boundary', $noGrade],
            ]
        );

        return self::SUCCESS;
    }
}
