<?php

namespace App\Services;

use App\Models\ClassLevel;
use App\Models\Scopes\SchoolScope;
use Illuminate\Support\Collection;

/**
 * The one walk over `class_levels.next_class_level_id`, shared by every caller that needs to know
 * whether a school's progression graph is a DAG.
 *
 * ── WHY THIS IS A SERVICE AND NOT TWO IMPLEMENTATIONS ─────────────────────────────────────────────
 * Two callers need this answer and they need it at different moments:
 *
 *   • `academics:validate-progression` — the rollover's pre-flight gate, asking about the graph AS
 *     STORED, immediately before dispatching a fleet of jobs.
 *   • `UpdateClassLevelProgressionRequest` — the config screen, asking whether a pointer an operator
 *     is ABOUT TO SAVE would create a ring.
 *
 * Written twice they would drift, and the command is the one with the tests. So the walk lives here
 * and both call it — the same reasoning the plan applies to `RolloverPlanner` for the rollover
 * surface. A cycle the screen accepts and the gate later refuses is the worst of both: the operator
 * is told their configuration is fine, and the rollover refuses at the moment it matters.
 *
 * ── THE CANDIDATE EDGE IS A PARAMETER, WHICH IS THE WHOLE POINT ───────────────────────────────────
 * The two questions differ only in one edge. Rather than give the request its own walk over a
 * hypothetical graph, {@see findCycle()} takes the proposed edge and applies it OVER the persisted
 * map before walking:
 *
 *   findCycle($schoolId)                        → the stored graph (the command)
 *   findCycle($schoolId, $levelId, $targetId)   → the stored graph with THIS pointer replaced
 *
 * So the request can reject `B → A` while the stored graph is still perfectly acyclic — which is the
 * only interesting case, because by the time a ring is persisted the screen has already failed at
 * its job. `$candidateTo = null` models clearing a pointer (a terminal year), which can never
 * introduce a cycle but is passed through the same code path rather than special-cased.
 *
 * ── WHAT THIS DOES NOT COVER ──────────────────────────────────────────────────────────────────────
 * The SELF-LOOP `A → A` is also rejected by the database: `class_levels_progression_guard_bi/_bu`
 * (2026_08_20_130000) refuses it on write, on 5.7 and 8.0 alike. This walk catches it too — a
 * one-node ring is still a ring — so the operator gets a readable message instead of a driver error,
 * but the trigger remains the backstop and neither replaces the other.
 */
final class ProgressionGraph
{
    /**
     * The first cycle reachable in this school's progression graph, as level NAMES in walk order with
     * the entry point repeated at the end (`['Year 7', 'Year 8', 'Year 7']`), or null when acyclic.
     *
     * @param  int|null  $candidateFrom  a level whose pointer is being proposed rather than read
     * @param  int|null  $candidateTo  the proposed target; null models clearing the pointer
     * @return list<string>|null
     */
    public static function findCycle(int $schoolId, ?int $candidateFrom = null, ?int $candidateTo = null): ?array
    {
        $levels = ClassLevel::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->get(['id', 'name', 'next_class_level_id']);

        if ($levels->isEmpty()) {
            return null;
        }

        return self::walk($levels, self::edges($levels, $candidateFrom, $candidateTo));
    }

    /**
     * Convenience for the config screen: does applying this edge create a ring?
     *
     * @return list<string>|null the cycle it would create, or null
     */
    public static function cycleIfPointed(int $schoolId, int $from, ?int $to): ?array
    {
        return self::findCycle($schoolId, $from, $to);
    }

    /**
     * id => next_class_level_id, with the candidate edge applied over the persisted map.
     *
     * The override is unconditional when `$candidateFrom` is given, INCLUDING when `$candidateTo` is
     * null — clearing a pointer must actually clear it in the map being walked, or the request would
     * evaluate a graph that still holds the old edge and could reject a change that removes a cycle.
     *
     * @param  Collection<int, ClassLevel>  $levels
     * @return array<int, int|null>
     */
    private static function edges(Collection $levels, ?int $candidateFrom, ?int $candidateTo): array
    {
        $edges = [];

        foreach ($levels as $level) {
            $edges[(int) $level->id] = $level->next_class_level_id === null
                ? null
                : (int) $level->next_class_level_id;
        }

        if ($candidateFrom !== null && array_key_exists($candidateFrom, $edges)) {
            $edges[$candidateFrom] = $candidateTo;
        }

        return $edges;
    }

    /**
     * Iterative, not recursive: a ring would recurse until the stack gave out, which is a worse way
     * to learn there is a cycle. Nodes settled on an earlier walk are skipped, so the whole graph
     * costs one pass rather than one per starting node.
     *
     * @param  Collection<int, ClassLevel>  $levels
     * @param  array<int, int|null>  $edges
     * @return list<string>|null
     */
    private static function walk(Collection $levels, array $edges): ?array
    {
        $names = $levels->pluck('name', 'id')->all();
        $settled = [];

        foreach (array_keys($edges) as $start) {
            if (isset($settled[$start])) {
                continue;
            }

            $seen = [];
            $path = [];
            $node = $start;

            while ($node !== null && array_key_exists($node, $edges)) {
                if (isset($settled[$node])) {
                    break;
                }

                if (isset($seen[$node])) {
                    // Trim the lead-in so the report names the RING, not the walk that reached it.
                    $from = array_search($node, $path, true);
                    $ring = $from === false ? $path : array_slice($path, $from);

                    return array_map(
                        fn (int $id) => (string) ($names[$id] ?? $id),
                        [...$ring, $node]
                    );
                }

                $seen[$node] = true;
                $path[] = $node;
                $node = $edges[$node];
            }

            foreach ($path as $walked) {
                $settled[$walked] = true;
            }
        }

        return null;
    }
}
