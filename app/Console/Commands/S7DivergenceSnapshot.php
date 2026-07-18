<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * S7 production divergence snapshot (roadmap §3b). Read-only. Counts users whose
 * access came from a LEGACY source but was never mirrored into model_has_roles —
 * i.e. users who would LOSE access when the columns are dropped and access
 * derives solely from roles. This is the gate before the parity soak, and must
 * run against PRODUCTION (or a fresh untouched snapshot); a seeded DB answers the
 * wrong question (its sources were written together and agree by construction).
 *
 * Covers all THREE legacy access sources against model_has_roles:
 *   (a) school_user pivot           (b) users.school_id      (c) guardian records
 * super_admin is excluded explicitly: it is team-less by design (the sole role
 * exempt from the assignRole invariant) and is a false orphan.
 *
 * Non-zero is a STOP (a backfill decision that grants real access to real
 * people), not a mechanical fix — report and escalate.
 */
class S7DivergenceSnapshot extends Command
{
    protected $signature = 's7:divergence-snapshot {--json : Emit machine-readable JSON}';

    protected $description = 'Count legacy access-source rows not mirrored into model_has_roles (S7 gate)';

    public function handle(): int
    {
        $morph = (new User)->getMorphClass();
        $team = config('permission.column_names.team_foreign_key');           // school_id
        $morphKey = config('permission.column_names.model_morph_key');        // model_id
        $mhr = config('permission.table_names.model_has_roles');
        $roles = config('permission.table_names.roles');

        // Users holding super_admin (team-less) — excluded from every orphan check.
        $superAdminIds = DB::table($mhr)
            ->join($roles, "$roles.id", '=', "$mhr.role_id")
            ->where("$roles.name", 'super_admin')
            ->pluck("$mhr.$morphKey")->all();
        $notSuper = fn ($col) => fn ($q) => $q->whereNotIn($col, $superAdminIds ?: [0]);

        // (a) school_user rows with no matching model_has_roles row (same user+school).
        $pivotOrphans = DB::table('school_user as su')
            ->when($superAdminIds, fn ($q) => $q->whereNotIn('su.user_id', $superAdminIds))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($mhr)
                ->whereColumn("$mhr.$morphKey", 'su.user_id')
                ->where("$mhr.model_type", $morph)
                ->whereColumn("$mhr.$team", 'su.school_id'))
            ->selectRaw('su.school_id, COUNT(*) as orphans')
            ->groupBy('su.school_id')->get();

        // (b) users.school_id set with no matching model_has_roles row.
        $columnOrphans = DB::table('users as u')
            ->whereNotNull('u.school_id')
            ->when($superAdminIds, fn ($q) => $q->whereNotIn('u.id', $superAdminIds))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($mhr)
                ->whereColumn("$mhr.$morphKey", 'u.id')
                ->where("$mhr.model_type", $morph)
                ->whereColumn("$mhr.$team", 'u.school_id'))
            ->selectRaw('u.school_id, COUNT(*) as orphans')
            ->groupBy('u.school_id')->get();

        // (c) guardian records whose user has no 'guardian' role in that school.
        $guardianOrphans = DB::table('guardians as g')
            ->whereNull('g.deleted_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($mhr)
                ->join($roles, "$roles.id", '=', "$mhr.role_id")
                ->whereColumn("$mhr.$morphKey", 'g.user_id')
                ->where("$mhr.model_type", $morph)
                ->whereColumn("$mhr.$team", 'g.school_id')
                ->where("$roles.name", 'guardian'))
            ->selectRaw('g.school_id, COUNT(*) as orphans')
            ->groupBy('g.school_id')->get();

        $total = $pivotOrphans->sum('orphans') + $columnOrphans->sum('orphans') + $guardianOrphans->sum('orphans');

        $snapshot = [
            'taken_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'database' => DB::connection()->getDatabaseName(),
            'super_admins_excluded' => count($superAdminIds),
            'sources' => [
                'school_user' => $pivotOrphans->mapWithKeys(fn ($r) => [$r->school_id => (int) $r->orphans]),
                'users_school_id' => $columnOrphans->mapWithKeys(fn ($r) => [$r->school_id => (int) $r->orphans]),
                'guardian_records' => $guardianOrphans->mapWithKeys(fn ($r) => [$r->school_id => (int) $r->orphans]),
            ],
            'total_orphans' => $total,
            'verdict' => $total === 0 ? 'PASS (safe to proceed to the parity soak)' : 'STOP (backfill decision — escalate for review)',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $total === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info("S7 divergence snapshot — {$snapshot['taken_at']}");
        $this->line("  env={$snapshot['environment']}  db={$snapshot['database']}  super_admins_excluded={$snapshot['super_admins_excluded']}");
        foreach (['school_user', 'users_school_id', 'guardian_records'] as $src) {
            $rows = $snapshot['sources'][$src];
            $this->line("  {$src}: ".($rows->isEmpty() ? '0 orphans' : $rows->sum().' orphans '.$rows->map(fn ($n, $s) => "school#$s:$n")->implode(', ')));
        }
        $this->line('');
        $total === 0 ? $this->info("  TOTAL 0 — {$snapshot['verdict']}") : $this->error("  TOTAL {$total} — {$snapshot['verdict']}");

        return $total === 0 ? self::SUCCESS : self::FAILURE;
    }
}
