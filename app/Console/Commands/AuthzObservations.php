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
    protected $signature = 'authz:observations
        {--by=ability : Group by one of: ability, controller_action, route, role, school}
        {--since= : Only rows on/after this datetime (e.g. 2026-07-17)}
        {--json : Emit JSON instead of a table}';

    protected $description = 'Aggregate observe-mode authorization denials for review';

    public function handle(): int
    {
        $by = $this->option('by');

        $base = DB::table('authz_observations');
        if ($since = $this->option('since')) {
            $base->where('occurred_at', '>=', $since);
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
