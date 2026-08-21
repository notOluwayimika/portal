<?php

namespace App\Console\Commands;

use App\Models\ClassLevel;
use App\Models\Scopes\SchoolScope;
use App\Services\ProgressionGraph;
use Illuminate\Console\Command;

/**
 * Pre-flight for the end-of-year rollover — refuse to dispatch over a class-level graph that is not a DAG.
 *
 * WHY THIS IS NOT A DATABASE CONSTRAINT. 2026_08_20_130000 installs a trigger pair that rejects the
 * SELF-LOOP (a class level whose next_class_level_id is itself). That is as far as a row-level guard
 * reaches: a trigger sees one row, and a multi-node cycle — A -> B -> A, or any longer ring — is legal
 * at every row in it. Detecting one means walking the chain, which is why it lives outside the schema.
 *
 * WHY IT MATTERS EVEN THOUGH THE JOB IS SINGLE-HOP. MoveToNextYearJob promotes one level to its
 * successor and stops, so a cycle cannot spin WITHIN a run. The damage is at dispatch scale: a
 * year-end run over every level in a ring promotes each cohort into a level that promotes into
 * theirs, so the cohorts swap places and nobody's year advances. Every individual job succeeds. The
 * whole rollover is wrong, and nothing in it reports an error — which is exactly the class of failure
 * worth spending a pre-flight on.
 *
 * THE WALK ITSELF LIVES IN {@see ProgressionGraph}, NOT HERE. The progression config screen must
 * reject a pointer that WOULD create a ring, and this command must reject one that already has —
 * the same question about two graphs. Two implementations would drift, and this command is the one
 * with the tests, so it became the caller rather than the owner. This class is now the CLI face of
 * that service: argument parsing, per-school grouping, and an exit code an orchestrator can gate on.
 *
 * Runs per school over the SchoolScope-free set, so a cycle in one school is not masked by another's
 * clean graph.
 */
class ValidateProgressionGraph extends Command
{
    protected $signature = 'academics:validate-progression {--school= : Limit to one school id}';

    protected $description = 'Refuse the end-of-year rollover if next_class_level_id contains a cycle.';

    public function handle(): int
    {
        $schoolIds = ClassLevel::withoutGlobalScope(SchoolScope::class)
            ->when($this->option('school'), fn ($q, $school) => $q->where('school_id', $school))
            ->distinct()
            ->orderBy('school_id')
            ->pluck('school_id');

        if ($schoolIds->isEmpty()) {
            $this->warn('No class levels found — nothing to validate.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($schoolIds as $schoolId) {
            // The PERSISTED graph — no candidate edge. The config screen passes one; this does not.
            $cycle = ProgressionGraph::findCycle((int) $schoolId);

            if ($cycle === null) {
                $this->info("school {$schoolId}: progression graph is acyclic.");

                continue;
            }

            $failed = true;
            $this->error("school {$schoolId}: next_class_level_id contains a CYCLE — ".implode(' -> ', $cycle));
        }

        if ($failed) {
            $this->newLine();
            $this->error(
                'Refusing the rollover. Every job in a ring would succeed individually while the cohorts '
                .'simply swap levels and nobody advances. Break the cycle by clearing or repointing one '
                .'next_class_level_id, then re-run this check.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
