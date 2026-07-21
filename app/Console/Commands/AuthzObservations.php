<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * On-demand aggregation of observe-mode authorization evidence (S5). Turns the
 * raw authz_observations rows into operational evidence grouped by the axes the
 * classification needs: permission, controller, route, role, School.
 */
class AuthzObservations extends Command
{
    /**
     * Classification record for §24 condition 3 ("every would-be denial
     * reviewed and classified"). Lives in the REPO, not the observations
     * table: rows are pruned at 30 days and the whole table is dropped at the
     * ADR 0043 §5 teardown, but the review evidence must survive both — and a
     * git-reviewed file gives each classification an author and a PR trail.
     */
    public const CLASSIFICATIONS_PATH = 'docs/runbooks/authz-observation-classifications.json';

    protected $signature = 'authz:observations
        {--by=ability : Group by one of: ability, controller_action, route, role, school}
        {--summarize : Denial CLASSES — (ability × controller_action) with role breakdown + classification status}
        {--unclassified : List classes with no classification; exits 1 if any exist (the §24-condition-3 gate)}
        {--since= : Only rows on/after this datetime (e.g. 2026-07-17)}
        {--json : Emit JSON instead of a table}';

    protected $description = 'Aggregate observe-mode authorization denials for review and classification';

    public function handle(): int
    {
        $by = $this->option('by');

        $base = DB::table('authz_observations');
        if ($since = $this->option('since')) {
            $base->where('occurred_at', '>=', $since);
        }

        if ($this->option('summarize') || $this->option('unclassified')) {
            return $this->classes($base, unclassifiedOnly: (bool) $this->option('unclassified'));
        }

        $total = (clone $base)->count();
        if ($total === 0) {
            $this->warn('No observations recorded. Either enforcement was never exercised, or observe mode is not wired on the exercised routes.');

            return self::SUCCESS;
        }

        $rows = $by === 'role'
            ? $this->groupByRole($base)
            : $this->groupByColumn($base, $this->column($by));

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("Authorization observations grouped by [{$by}] — {$total} total would-be denials");
        $this->table(
            ['group', 'denials', 'distinct_users', 'distinct_schools', 'first_seen', 'last_seen', 'sample_uri'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * The classification unit is the (ability, controller_action) pair — a
     * "denial class". §24 condition 3 closes only when every class observed on
     * real traffic carries a reviewed classification: `expected` (the denial
     * is correct — the caller genuinely lacks the ability) or `regression`
     * (legitimate access that enforcement would break; fix before flipping).
     */
    private function classes($base, bool $unclassifiedOnly): int
    {
        $classifications = $this->classifications();

        $rows = (clone $base)
            ->selectRaw('ability, controller_action,
                COUNT(*) as denials,
                COUNT(DISTINCT user_id) as distinct_users,
                COUNT(DISTINCT school_id) as distinct_schools,
                MIN(occurred_at) as first_seen,
                MAX(occurred_at) as last_seen')
            ->groupBy('ability', 'controller_action')
            ->orderByDesc('denials')
            ->get()
            ->map(function ($r) use ($base, $classifications) {
                $key = $r->ability.'|'.$r->controller_action;
                $roles = (clone $base)
                    ->where('ability', $r->ability)
                    ->where('controller_action', $r->controller_action)
                    ->pluck('roles')
                    ->flatMap(fn ($json) => json_decode($json ?: '[]', true) ?: ['(no role)'])
                    ->countBy()
                    ->map(fn ($n, $role) => "{$role}×{$n}")
                    ->implode(', ');

                return [
                    'ability' => $r->ability,
                    'controller_action' => $r->controller_action,
                    'denials' => $r->denials,
                    'distinct_users' => $r->distinct_users,
                    'distinct_schools' => $r->distinct_schools,
                    'roles' => $roles,
                    'first_seen' => $r->first_seen,
                    'last_seen' => $r->last_seen,
                    'classification' => $classifications[$key]['classification'] ?? 'UNCLASSIFIED',
                ];
            });

        if ($unclassifiedOnly) {
            $rows = $rows->filter(fn ($r) => $r['classification'] === 'UNCLASSIFIED')->values();

            if ($rows->isEmpty()) {
                $this->info('authz:observations — every observed denial class is classified (§24 condition 3 input satisfied for this window).');

                return self::SUCCESS;
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows->values()->all(), JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['ability', 'controller_action', 'denials', 'users', 'schools', 'roles', 'first_seen', 'last_seen', 'classification'],
                $rows->values()->all(),
            );
        }

        if ($unclassifiedOnly) {
            $this->error($rows->count().' unclassified denial class(es). Classify each in '
                .self::CLASSIFICATIONS_PATH.' via a reviewed PR (see docs/runbooks/authz-observation-review.md).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<string, array{classification: string}> keyed "ability|controller_action" */
    private function classifications(): array
    {
        $path = base_path(self::CLASSIFICATIONS_PATH);

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true) ?: [];

        return collect($decoded['classes'] ?? [])
            ->keyBy(fn ($c) => $c['ability'].'|'.$c['controller_action'])
            ->all();
    }

    private function column(string $by): string
    {
        return match ($by) {
            'controller_action' => 'controller_action',
            'route' => 'route',
            'school' => 'school_id',
            default => 'ability',
        };
    }

    private function groupByColumn($base, string $column): array
    {
        return (clone $base)
            ->selectRaw("$column as grp,
                COUNT(*) as denials,
                COUNT(DISTINCT user_id) as distinct_users,
                COUNT(DISTINCT school_id) as distinct_schools,
                MIN(occurred_at) as first_seen,
                MAX(occurred_at) as last_seen,
                MIN(request_uri) as sample_uri")
            ->groupBy('grp')
            ->orderByDesc('denials')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function groupByRole($base): array
    {
        // roles is a JSON array; explode by counting occurrences of each role name.
        $counts = [];
        (clone $base)->orderBy('id')->each(function ($row) use (&$counts) {
            foreach (json_decode($row->roles ?: '[]', true) ?: ['(no role)'] as $role) {
                $counts[$role] ??= ['group' => $role, 'denials' => 0, 'distinct_users' => [], 'distinct_schools' => [], 'first_seen' => $row->occurred_at, 'last_seen' => $row->occurred_at, 'sample_uri' => $row->request_uri];
                $counts[$role]['denials']++;
                $counts[$role]['distinct_users'][$row->user_id] = true;
                $counts[$role]['distinct_schools'][$row->school_id] = true;
                $counts[$role]['last_seen'] = $row->occurred_at;
            }
        });

        return collect($counts)->map(fn ($c) => [
            'group' => $c['group'],
            'denials' => $c['denials'],
            'distinct_users' => count($c['distinct_users']),
            'distinct_schools' => count($c['distinct_schools']),
            'first_seen' => $c['first_seen'],
            'last_seen' => $c['last_seen'],
            'sample_uri' => $c['sample_uri'],
        ])->sortByDesc('denials')->values()->all();
    }
}
