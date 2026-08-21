<?php

namespace App\Console\Commands;

use App\Models\ClassLevel;
use App\Models\Scopes\SchoolScope;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Pre-flight for the end-of-year rollover — refuse to dispatch over a class-level graph that is not a DAG.
 *
 * WHY THIS IS NOT A DATABASE CONSTRAINT. 2026_08_20_130000 installs a trigger pair that rejects the
 * SELF-LOOP (a class level whose next_class_level_id is itself). That is as far as a row-level guard
 * reaches: a trigger sees one row, and a multi-node cycle — A -> B -> A, or any longer ring — is legal
 * at every row in it. Detecting one means walking the chain, which is why it lives here.
 *
 * WHY IT MATTERS EVEN THOUGH THE JOB IS SINGLE-HOP. MoveToNextYearJob promotes one level to its
 * successor and stops, so a cycle cannot spin WITHIN a run. The damage is at dispatch scale: a
 * year-end run over every level in a ring promotes each cohort into a level that promotes into
 * theirs, so the cohorts swap places and nobody's year advances. Every individual job succeeds. The
 * whole rollover is wrong, and nothing in it reports an error — which is exactly the class of failure
 * worth spending a pre-flight on.
 *
 * Runs per school, over the SchoolScope-free set, so a cycle in one school is not masked by another's
 * clean graph. Exits FAILURE naming the cycle, so an orchestrator can gate on the exit code.
 */
class ValidateProgressionGraph extends Command
{
    protected $signature = 'academics:validate-progression {--school= : Limit to one school id}';

    protected $description = 'Refuse the end-of-year rollover if next_class_level_id contains a cycle.';

    public function handle(): int
    {
        $levels = ClassLevel::withoutGlobalScope(SchoolScope::class)
            ->when($this->option('school'), fn ($q, $school) => $q->where('school_id', $school))
            ->get(['id', 'school_id', 'name', 'next_class_level_id']);

        if ($levels->isEmpty()) {
            $this->warn('No class levels found — nothing to validate.');

            return self::SUCCESS;
        }

        $byId = $levels->keyBy('id');
        $failed = false;

        foreach ($levels->groupBy('school_id') as $schoolId => $schoolLevels) {
            $cycle = $this->findCycle($schoolLevels, $byId);

            if ($cycle === null) {
                $this->info("school {$schoolId}: progression graph is acyclic ({$schoolLevels->count()} level(s)).");

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

    /**
     * Iterative walk from each node, colouring as it goes. Not recursive: a ring would recurse until
     * the stack gave out, which is a worse way to learn there is a cycle.
     *
     * @param  Collection<int, ClassLevel>  $schoolLevels
     * @param  Collection<int, ClassLevel>  $byId
     * @return list<string>|null the cycle as level names, or null
     */
    private function findCycle($schoolLevels, $byId): ?array
    {
        $settled = [];

        foreach ($schoolLevels as $start) {
            $seen = [];
            $path = [];
            $node = $start;

            while ($node !== null) {
                if (isset($settled[$node->id])) {
                    break;
                }

                if (isset($seen[$node->id])) {
                    // Trim the lead-in so the report names the ring itself, not the walk that found it.
                    $from = array_search($node->id, array_column($path, 'id'), true);
                    $ring = array_slice($path, $from === false ? 0 : $from);

                    return array_map(fn ($n) => $n['name'], [...$ring, ['name' => $node->name]]);
                }

                $seen[$node->id] = true;
                $path[] = ['id' => $node->id, 'name' => $node->name];

                $next = $node->next_class_level_id;
                $node = $next === null ? null : $byId->get($next);
            }

            foreach ($path as $walked) {
                $settled[$walked['id']] = true;
            }
        }

        return null;
    }
}
