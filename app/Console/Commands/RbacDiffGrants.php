<?php

namespace App\Console\Commands;

use App\Enums\Permission as PermissionEnum;
use App\Listeners\LogRbacChange;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY reconciler: `RbacSeeder::grantsMap()` (the intent, in code) against the live `roles` /
 * `role_has_permissions` rows (the reality, in the database) — and, for every difference, a DIAGNOSIS
 * derived from `activity_log`.
 *
 * IT REVOKES NOTHING AND GRANTS NOTHING. No writes of any kind. It is run against the production copy,
 * and a reconciler that repairs is a reconciler that can destroy a legitimate C6 matrix edit before
 * anyone has read the diff. Same posture as {@see AuditDutySeparation}: report, exit non-zero on
 * findings, leave the decision to a human.
 *
 * WHY THIS EXISTS. `RbacSeeder::sync()` is non-destructive by contract — runtime matrix edits must
 * survive `rbac:sync`. The mechanism is `RbacSeeder.php:494-496`:
 *
 *     $toGrant = in_array($roleName, $existingRoles, true)
 *         ? array_values(array_intersect($permissions, $newPermissions))
 *         : $permissions;
 *
 * `$newPermissions` (`:478`) is the set of permissions CREATED ON THIS RUN. So for a role that already
 * exists, `rbac:sync` grants only permissions that did not exist before this run. Consequence: adding
 * an ALREADY-EXISTING permission to an ALREADY-EXISTING role in `grantsMap()` grants NOTHING on every
 * environment where the seeder has already run. `array_intersect` provably excludes it. The map says
 * the role holds it; the database says it does not; until this command, nothing in the repo noticed.
 *
 * THE CLASSIFICATION, AND THE FACT IT RESTS ON. `RbacSeeder::sync():432` wraps the ENTIRE body of the
 * private `syncLogged()` (`:435-515`, its only caller) in `activity()->withoutLogs(...)`, and
 * {@see LogRbacChange} writes through the `activity()` helper, which returns early when
 * logging is disabled (vendor `ActivityLogger::log():161-163`). Therefore THE SEEDER WRITES NO ACTIVITY
 * ROWS AT ALL, and every `activity_log` row with `log_name = 'rbac'` and an
 * `permission_attached`/`permission_detached` event is a NON-SEEDER mutation: the C6 matrix UI, a
 * convergence migration, or tinker. That asymmetry is what makes a difference diagnosable rather than
 * merely visible. If that ever stops being true, every diagnosis below inverts.
 *
 * SCOPE: global rows only — `roles.school_id IS NULL`, `guard_name = 'web'` (`RbacSeeder::GUARD`).
 * `grantsMap()` has no school dimension, so a school-scoped role cannot be diffed against it without
 * inventing an expectation. School-scoped `web` role rows are COUNTED and reported in a one-line
 * footer, never diffed.
 *
 * PRIVACY: role names, permission names, counts and log-row metadata only. No user rows, no emails,
 * no names. (Deliberately does NOT copy `AuditDutySeparation.php:55`, which emits an email.)
 */
class RbacDiffGrants extends Command
{
    protected $signature = 'rbac:diff-grants {--json : Emit machine-readable JSON}';

    protected $description = 'READ-ONLY: reconcile RbacSeeder::grantsMap() against the live grants and diagnose each difference from activity_log (exit non-zero on findings)';

    /** In the map, not on the role in the database — and no rbac log row for the pair. */
    private const SYNC_ADD_GAP = 'SYNC_ADD_GAP';

    /** In the map, not on the role — most recent rbac row is a detach. */
    private const DELIBERATE_REVOKE = 'DELIBERATE_REVOKE';

    /** In the map, not on the role — most recent rbac row is an ATTACH. The removal was never logged. */
    private const ATTACHED_THEN_LOST = 'ATTACHED_THEN_LOST';

    /** On the role, not in the map — most recent rbac row is an attach. */
    private const MATRIX_GRANT = 'MATRIX_GRANT';

    /** On the role, not in the map — and no rbac log row for the pair. */
    private const MAP_REMOVAL_GAP = 'MAP_REMOVAL_GAP';

    /** On the role, not in the map — most recent rbac row is a DETACH. The re-grant was never logged. */
    private const DETACHED_THEN_REGAINED = 'DETACHED_THEN_REGAINED';

    /** @var array<string, string> */
    private const SUMMARIES = [
        self::SYNC_ADD_GAP => 'NON-DESTRUCTIVE-SYNC ADD GAP — grantsMap() gained this after the permission already existed, so rbac:sync never granted it. Needs a convergence migration.',
        self::DELIBERATE_REVOKE => 'DELIBERATE REVOKE — something revoked this at runtime (C6 local authority, or a convergence migration whose map was never updated). HUMAN DECISION: do not converge.',
        self::ATTACHED_THEN_LOST => 'UNLOGGED REMOVAL — the latest rbac row ATTACHES this, yet the role does not hold it and no detach was logged. A raw syncPermissions detach (no event) or an out-of-app write. INVESTIGATE.',
        self::MATRIX_GRANT => 'RUNTIME MATRIX GRANT — C6 local authority, and the non-destructive contract exists to preserve it. LEGITIMATE: do not revoke.',
        self::MAP_REMOVAL_GAP => 'MAP-REMOVAL GAP (the mirror) — this was in grantsMap() when it was seeded and has since been REMOVED from the map with no revoke migration.',
        self::DETACHED_THEN_REGAINED => 'UNLOGGED RE-GRANT — the latest rbac row DETACHES this, yet the role holds it and no attach was logged. A raw syncPermissions attach (no event) or an out-of-app write. INVESTIGATE.',
    ];

    public function handle(): int
    {
        $guard = RbacSeeder::GUARD;
        $map = RbacSeeder::grantsMap();

        // ── Section A — catalog. Run FIRST because it interprets everything below: the seeder creates
        // and prunes permission rows on every run (RbacSeeder.php:447-457), so a non-empty catalog diff
        // means rbac:sync has NOT been run on this database, and in that state every Section B `missing`
        // is explained by that alone.
        $enumValues = PermissionEnum::values();
        $rows = Permission::query()->where('guard_name', $guard)->pluck('name')->all();

        $catalog = [
            'missing_rows' => array_values(array_diff($enumValues, $rows)),
            'extra_rows' => array_values(array_diff($rows, $enumValues)),
        ];
        sort($catalog['missing_rows']);
        sort($catalog['extra_rows']);
        $catalogDirty = $catalog['missing_rows'] !== [] || $catalog['extra_rows'] !== [];

        // ── Section B — grants. Global rows only.
        /** @var array<string, Role> $globalRoles */
        $globalRoles = Role::query()
            ->where('guard_name', $guard)
            ->whereNull('school_id')
            ->with('permissions')
            ->get()
            ->keyBy('name')
            ->all();

        $roleFindings = [];
        $missingRoleRows = [];

        foreach ($map as $roleName => $expected) {
            $role = $globalRoles[$roleName] ?? null;

            if ($role === null) {
                // The map names a role with no global row. Not a grant difference — a structural one.
                $missingRoleRows[] = $roleName;

                continue;
            }

            $actual = $role->permissions->pluck('name')->all();
            $missing = array_values(array_diff($expected, $actual));
            $extra = array_values(array_diff($actual, $expected));
            sort($missing);
            sort($extra);

            if ($missing === [] && $extra === []) {
                continue;
            }

            $roleFindings[$roleName] = [
                'role_id' => (int) $role->id,
                // missing and extra are reported SEPARATELY and never merged into one "drift" count:
                // they have opposite causes and opposite remedies.
                'missing' => array_map(fn (string $p) => $this->diagnose((int) $role->id, $p, 'missing'), $missing),
                'extra' => array_map(fn (string $p) => $this->diagnose((int) $role->id, $p, 'extra'), $extra),
            ];
        }

        // Roles present in the database but ABSENT from grantsMap() — reported, never skipped. That is
        // the `rogue_platform` shape, and skipping is how it would survive a reconciler.
        $unmapped = [];
        foreach ($globalRoles as $roleName => $role) {
            if (array_key_exists($roleName, $map)) {
                continue;
            }

            $grants = $role->permissions->pluck('name')->sort()->values()->all();
            $unmapped[$roleName] = ['role_id' => (int) $role->id, 'grants' => $grants, 'grant_count' => count($grants)];
        }
        ksort($unmapped);

        // ── Footer — school-scoped `web` role rows. Counted, never diffed.
        $schoolScopedRoleRows = Role::query()
            ->where('guard_name', $guard)
            ->whereNotNull('school_id')
            ->count();

        $totals = [
            'catalog_missing_rows' => count($catalog['missing_rows']),
            'catalog_extra_rows' => count($catalog['extra_rows']),
            'roles_with_findings' => count($roleFindings),
            'missing_grants' => array_sum(array_map(fn ($r) => count($r['missing']), $roleFindings)),
            'extra_grants' => array_sum(array_map(fn ($r) => count($r['extra']), $roleFindings)),
            'mapped_roles_with_no_global_row' => count($missingRoleRows),
            'unmapped_global_roles' => count($unmapped),
        ];

        $findingsPresent = $catalogDirty
            || $roleFindings !== []
            || $missingRoleRows !== []
            || $unmapped !== [];

        $report = [
            'generated_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'database' => DB::connection()->getDatabaseName(),
            'guard' => $guard,
            'scope' => 'global role rows only (roles.school_id IS NULL)',
            'catalog_interpretable' => ! $catalogDirty,
            'catalog' => $catalog,
            'roles' => $roleFindings,
            'mapped_roles_with_no_global_row' => $missingRoleRows,
            'unmapped_global_roles' => $unmapped,
            'school_scoped_role_rows' => $schoolScopedRoleRows,
            'totals' => $totals,
            'verdict' => $findingsPresent ? 'FINDINGS (detection only — nothing was changed)' : 'CLEAN',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $findingsPresent ? self::FAILURE : self::SUCCESS;
        }

        $this->render($report);

        return $findingsPresent ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The diagnosis for one (role, permission) difference, from the most recent rbac activity row
     * naming that pair.
     *
     * Match on subject_type + subject_id + JSON membership of the permission name in
     * `properties->permissions` — the key {@see LogRbacChange} writes at `:45`
     * (`$subjectKind.'s'`). Most recent by id, which is monotonic and (unlike created_at) never ties.
     *
     * @param  'missing'|'extra'  $kind
     * @return array{permission: string, diagnosis: string, summary: string, log: null|array{id: int, event: string, created_at: string, causer_id_null: bool}}
     */
    private function diagnose(int $roleId, string $permission, string $kind): array
    {
        $row = DB::table('activity_log')
            ->where('log_name', 'rbac')
            ->where('subject_type', (new Role)->getMorphClass())
            ->where('subject_id', $roleId)
            ->whereIn('event', ['permission_attached', 'permission_detached'])
            ->whereRaw("JSON_CONTAINS(properties, JSON_QUOTE(?), '$.permissions')", [$permission])
            ->orderByDesc('id')
            ->first();

        $event = $row->event ?? null;

        $diagnosis = match (true) {
            $kind === 'missing' && $event === null => self::SYNC_ADD_GAP,
            $kind === 'missing' && $event === 'permission_detached' => self::DELIBERATE_REVOKE,
            $kind === 'missing' => self::ATTACHED_THEN_LOST,
            $event === null => self::MAP_REMOVAL_GAP,
            $event === 'permission_attached' => self::MATRIX_GRANT,
            default => self::DETACHED_THEN_REGAINED,
        };

        return [
            'permission' => $permission,
            'diagnosis' => $diagnosis,
            'summary' => self::SUMMARIES[$diagnosis],
            // The evidence, not just the verdict: a reconciler whose reasoning cannot be checked is a
            // reconciler that gets believed when it is wrong.
            'log' => $row === null ? null : [
                'id' => (int) $row->id,
                'event' => (string) $row->event,
                'created_at' => (string) $row->created_at,
                'causer_id_null' => $row->causer_id === null,
            ],
        ];
    }

    /** @param array<string, mixed> $report */
    private function render(array $report): void
    {
        $this->info('rbac:diff-grants — RbacSeeder::grantsMap() vs the live grants');
        $this->line("  env={$report['environment']}  db={$report['database']}  guard={$report['guard']}");
        $this->line('  scope: '.$report['scope']);
        $this->line('');

        // ── Section A
        $this->line('SECTION A — permission catalog (enum vs `permissions` rows)');
        if ($report['catalog_interpretable']) {
            $this->line('  clean — the enum and the permission rows agree.');
        } else {
            foreach (['missing_rows' => 'declared in the enum, NO permission row', 'extra_rows' => 'permission row present, NOT declared in the enum'] as $key => $label) {
                if ($report['catalog'][$key] !== []) {
                    $this->line('  '.count($report['catalog'][$key])." {$label}:");
                    foreach ($report['catalog'][$key] as $name) {
                        $this->line("    - {$name}");
                    }
                }
            }
            $this->line('');
            $this->error('  BANNER: the catalog does not agree with the enum, which means `rbac:sync` has NOT been');
            $this->error('  run on this database. SECTION B IS UNINTERPRETABLE until it is: every `missing` below');
            $this->error('  may be explained by that alone. Run `php artisan rbac:sync`, then re-run this command.');
        }
        $this->line('');

        // ── Section B + C
        $this->line('SECTION B/C — grants per global role, with the diagnosis for each difference');

        if ($report['roles'] === []) {
            $this->line('  clean — every role in grantsMap() holds exactly its mapped grants.');
        }

        foreach ($report['roles'] as $roleName => $finding) {
            $this->line('');
            $this->line("  ROLE {$roleName} (role#{$finding['role_id']})");

            if ($roleName === 'super_admin') {
                $this->error('    super_admin has a finding. Its grants are SELF-HEALED to SUPER_ADMIN_PLATFORM on');
                $this->error('    every run (RbacSeeder.php:506-512, syncPermissions in a transaction), so a difference');
                $this->error('    here means that block did not run. That is a different and more serious problem than');
                $this->error('    the sync-ADD gap this command was written for.');
            }

            foreach (['missing' => 'MISSING (in the map, not on the role)', 'extra' => 'EXTRA (on the role, not in the map)'] as $key => $label) {
                if ($finding[$key] === []) {
                    continue;
                }

                $this->line('    '.count($finding[$key])." {$label}");
                $this->table(
                    ['Permission', 'Diagnosis', 'Log row', 'Event', 'When', 'causer NULL'],
                    array_map(fn (array $d) => [
                        $d['permission'],
                        $d['diagnosis'],
                        $d['log']['id'] ?? '—',
                        $d['log']['event'] ?? 'no rbac row',
                        $d['log']['created_at'] ?? '—',
                        $d['log'] === null ? '—' : ($d['log']['causer_id_null'] ? 'yes' : 'no'),
                    ], $finding[$key]),
                );

                foreach (array_unique(array_map(fn (array $d) => $d['diagnosis'], $finding[$key])) as $code) {
                    $this->line("      {$code}: ".self::SUMMARIES[$code]);
                }
            }
        }
        $this->line('');

        // ── Structural findings
        if ($report['mapped_roles_with_no_global_row'] !== []) {
            $this->error('  '.count($report['mapped_roles_with_no_global_row']).' role(s) in grantsMap() have NO global role row — rbac:sync would create them:');
            foreach ($report['mapped_roles_with_no_global_row'] as $name) {
                $this->line("    - {$name}");
            }
            $this->line('');
        }

        if ($report['unmapped_global_roles'] !== []) {
            $this->error('  '.count($report['unmapped_global_roles']).' global role(s) present in the database but ABSENT from grantsMap():');
            foreach ($report['unmapped_global_roles'] as $name => $row) {
                $this->line("    - {$name} (role#{$row['role_id']}, {$row['grant_count']} grant(s))".
                    ($row['grants'] === [] ? '' : ': '.implode(', ', $row['grants'])));
            }
            $this->line('');
        }

        // ── Footer
        $this->line("FOOTER — school-scoped `{$report['guard']}` role rows (school_id IS NOT NULL), counted, NEVER diffed: {$report['school_scoped_role_rows']}");
        if ($report['school_scoped_role_rows'] > 0) {
            $this->warn('  Non-zero. grantsMap() has no school dimension, so these cannot be diffed against it without');
            $this->warn('  inventing an expectation — but their existence is itself worth a ticket.');
        }
        $this->line('');

        $t = $report['totals'];
        $this->line("TOTALS  catalog: {$t['catalog_missing_rows']} missing row(s), {$t['catalog_extra_rows']} extra row(s)"
            ."  |  grants: {$t['missing_grants']} missing, {$t['extra_grants']} extra across {$t['roles_with_findings']} role(s)"
            ."  |  roles: {$t['mapped_roles_with_no_global_row']} mapped-without-row, {$t['unmapped_global_roles']} unmapped");

        if ($report['verdict'] === 'CLEAN') {
            $this->info('  CLEAN — grantsMap() and the live grants agree.');

            return;
        }

        $this->error("  {$report['verdict']}");
        $this->warn('DETECTION ONLY — nothing was granted, revoked or written. A SYNC_ADD_GAP needs a convergence');
        $this->warn('migration; a MATRIX_GRANT is C6 local authority and must be left alone; a DELIBERATE_REVOKE is a');
        $this->warn('human decision. Deciding which is which is the point of the diagnosis column, not this command.');
    }
}
