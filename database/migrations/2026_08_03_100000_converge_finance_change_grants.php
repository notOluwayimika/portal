<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\DutySeparation;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Finance seat realignment (a0ab3d7) — the GRANT-GAIN mirror of 2026_08_02_100000.
 *
 * `rbac:sync` is non-destructive in BOTH directions (RbacSeeder::sync, ~L462): for a role that
 * already exists it applies only permissions CREATED in that same run. 2026_08_02_100000 fixed the
 * REVOKE side (principal/head_of_school kept grants the realigned map had removed). The ADD side was
 * never handled: the realignment ADDED finance.fee-schedule.change.submit + finance.discount-policy.
 * change.submit to accounts_officer, and finance.fee-schedule.change.submit to accounts_supervisor —
 * and those permissions already existed, so on any environment where those role rows already exist
 * (production carries them via the 2026_08_01 rename migration) the grants NEVER LAND. Net: fee-
 * schedule change ships with ZERO makers system-wide — nobody able to submit at all. Credit-note /
 * void read OK only because AO's *.submit grants pre-dated the realignment.
 *
 * This migration forces the grants of the GLOBAL (school_id IS NULL) rows of ALL FIVE finance roles,
 * within the two namespaces (finance.discount-policy.change.*, finance.fee-schedule.change.*), to
 * match grantsMap() exactly. Governing all five (not just the three that drifted) makes the two
 * namespaces CONVERGE to the map for every global finance role, so no drift in either direction can
 * hide — principal/head_of_school are already aligned by #186 and contribute nothing, which is the
 * point. Same shape as #186: target DERIVED from grantsMap(); school-scoped rows counted, never
 * written; diff-based revoke+give through Spatie events so LogRbacChange audits every delta (NOT
 * withoutLogs — a governance act); transaction-wrapped; idempotent; fresh-install guarded; down() a
 * deliberate no-op.
 *
 * The pairs this migration converges, in the marker syntax `bin/ci-grants-convergence-lint.php` reads.
 *
 * RECORDED FOR THE READER, UNREADABLE BY THE GATE — and permanently so, not pending. Exemption 3
 * reads markers only on migrations the diff ADDS (`--diff-filter=A`), because a migration already on
 * the base has already run and a marker on it would declare a convergence nothing performed. This
 * file predates the lint and is on `staging`, so no future `base...head` will ever mark it `A`. From
 * here on, a pair needing exemption gets a NEW convergence migration and declares it there; do not
 * copy this file expecting these lines to do work.
 *
 * They are kept because they record which pairs the author actually converged, which the prose alone
 * does not state precisely: the three ADD-side gaps named above; the other two governed roles
 * (principal, head_of_school) were already aligned by 2026_08_02_100000 and this migration converges
 * nothing for them.
 *
 * @converges accounts_officer finance.fee-schedule.change.submit
 * @converges accounts_officer finance.discount-policy.change.submit
 * @converges accounts_supervisor finance.fee-schedule.change.submit
 *
 * ONE thing #186 does not have — the check my convergence brief was missing (added by its addendum):
 * a grant-map change RETROACTIVELY turns an already-assigned, previously-legal role pair into a
 * both-sides violation, and nothing re-validates existing users when the map changes (the
 * assignment-time guard runs only on assignment — there is no assignment here). So this migration is
 * a PRODUCTION HAZARD unless it checks users, not just roles. After the diff — still inside the
 * transaction — it walks every user in every school and, via DutySeparation::violations (the audit
 * command's own primitive, so it cannot disagree with finance:audit-duty-separation), throws and rolls
 * back if any user would hold both sides of an ENFORCED (finance) pair via their combined roles,
 * naming each as user#<id> @ school#<id>. A role-scoped check (violationsFromRolePermissionSync) was
 * considered and dropped. NOT because the user-scoped check subsumes it — it does not: violations() is
 * holder-scoped, so a role granting both sides but held by nobody is invisible to it. The role-scoped
 * check is redundant *here* only because this migration writes a FIXED target derived from grantsMap(),
 * which carries no both-sides role (a one-shot over a known-clean map). The general "the seeded map has
 * no both-sides role" invariant — the real hole, since violationsFromRolePermissionSync's only caller is
 * the C6 matrix-edit request, never the seeder — is pinned separately by
 * tests/Feature/Rbac/GrantsMapSeparationTest.php.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $namespaces = [
        'finance.discount-policy.change.',
        'finance.fee-schedule.change.',
    ];

    /** @var list<string> All five global finance roles (the set #186's pre-flight 1 already allows). */
    private array $governed = ['principal', 'head_of_school', 'accounts_officer', 'accounts_supervisor', 'finance_lead'];

    public function up(): void
    {
        $inNs = fn (string $p): bool => str_starts_with($p, $this->namespaces[0])
            || str_starts_with($p, $this->namespaces[1]);

        $sixNames = Permission::query()
            ->where('guard_name', RbacSeeder::GUARD)
            ->where(fn ($q) => $q
                ->where('name', 'like', $this->namespaces[0].'%')
                ->orWhere('name', 'like', $this->namespaces[1].'%'))
            ->pluck('name')->all();

        // Fresh-install guard, keyed on the PERMISSION substrate (seeder-owned; absent at migrate-
        // from-zero). Nothing to converge — the seeder writes the correct map directly.
        if ($sixNames === []) {
            echo "  converge-finance-change-grants: finance RBAC substrate unseeded (no finance-change permissions) — nothing to converge.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Target per governed role, DERIVED from the seeder map, sliced to the two namespaces.
        $map = RbacSeeder::grantsMap();
        $target = [];
        foreach ($this->governed as $roleName) {
            $target[$roleName] = collect($map[$roleName] ?? [])->filter($inNs)->sort()->values()->all();
        }

        // Pre-flight: every permission we must GRANT has to exist (run rbac:sync first otherwise).
        $mustGrant = collect($target)->flatten()->unique()->values()->all();
        $present = Permission::query()->whereIn('name', $mustGrant)
            ->where('guard_name', RbacSeeder::GUARD)->pluck('name')->all();
        $missing = array_values(array_diff($mustGrant, $present));
        if ($missing !== []) {
            throw new RuntimeException(
                'converge-finance-change-grants ABORTED: target permission(s) absent from the permissions table — '
                .'run `php artisan rbac:sync` first, then re-migrate: '.implode(', ', $missing)
            );
        }

        // Pre-flight: no OTHER global role (outside the five) grants any of the six.
        $offenders = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->whereNull('r.school_id')->where('r.guard_name', RbacSeeder::GUARD)
            ->whereIn('p.name', $sixNames)->whereNotIn('r.name', $this->governed)
            ->distinct()->pluck('r.name')->all();
        if ($offenders !== []) {
            $detail = collect($offenders)->map(function (string $name) {
                $holders = DB::table('model_has_roles as mhr')
                    ->join('roles as r', 'r.id', '=', 'mhr.role_id')
                    ->where('mhr.model_type', User::class)
                    ->where('r.name', $name)->where('r.guard_name', RbacSeeder::GUARD)
                    ->distinct()->count('mhr.model_id');

                return "{$name} (holders={$holders})";
            })->implode(', ');
            throw new RuntimeException(
                'converge-finance-change-grants ABORTED: unexpected global role(s) grant the governed permissions: '
                .$detail.'. Investigate before widening this migration.'
            );
        }

        // Pre-flight: every governed role exists as a global row.
        $roles = [];
        foreach ($this->governed as $roleName) {
            $role = Role::query()->where('name', $roleName)
                ->where('guard_name', RbacSeeder::GUARD)->whereNull('school_id')->first();
            if ($role === null) {
                throw new RuntimeException("converge-finance-change-grants ABORTED: global role [{$roleName}] is missing.");
            }
            $roles[$roleName] = $role;
        }

        // School-scoped footprint (no action — C6 local authority stays put).
        $schoolScoped = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->whereNotNull('r.school_id')->where('r.guard_name', RbacSeeder::GUARD)
            ->whereIn('p.name', $sixNames)
            ->distinct()->count(DB::raw('CONCAT(r.id, "-", p.id)'));
        echo "  converge-finance-change-grants: school-scoped role rows carrying any of the six (UNTOUCHED): {$schoolScoped}\n";

        // Idempotency: already converged ⇒ clean no-op, no second batch of activity rows.
        $needsWork = false;
        foreach ($this->governed as $roleName) {
            $current = $roles[$roleName]->permissions->pluck('name')->filter($inNs)->sort()->values()->all();
            if ($current !== $target[$roleName]) {
                $needsWork = true;
            }
        }

        $this->report('BEFORE', $sixNames);

        if (! $needsWork) {
            echo "  converge-finance-change-grants: already aligned — no grants changed, no activity rows written.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Diff-based revoke + give, atomically. revoke fires PermissionDetachedEvent, give fires
        // PermissionAttachedEvent — both reach LogRbacChange. (syncPermissions detaches RAW, no event.)
        DB::transaction(function () use ($roles, $target, $inNs) {
            foreach ($this->governed as $roleName) {
                $role = $roles[$roleName];
                $current = $role->permissions->pluck('name')->filter($inNs)->values()->all();
                $revoke = array_values(array_diff($current, $target[$roleName]));
                $grant = array_values(array_diff($target[$roleName], $current));

                foreach ($revoke as $perm) {
                    $role->revokePermissionTo($perm);
                }
                if ($grant !== []) {
                    $role->givePermissionTo($grant);
                }
            }

            // The grant writes above may leave the registrar's permission cache stale; flush it so
            // the two SoD checks below read the real post-convergence grant state (still uncommitted,
            // but visible inside this transaction).
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // Post-write SoD guard #1 — USER-scoped (the primary, and the one production needs). A
            // grant-map change RETROACTIVELY turns an already-assigned, previously-legal role pair into
            // a both-sides violation, and nothing re-validates existing users when the map changes (the
            // assignment-time guard runs only on assignment). So walk every user in every school and
            // refuse to commit if any holds both sides of an ENFORCED (finance) pair via their combined
            // roles. Reuses the audit command's own primitive (DutySeparation::violations) against the
            // real post-convergence state, so it agrees with finance:audit-duty-separation afterwards.
            // Filtered to enforced pairs so pre-existing result.* findings (a different owner's area)
            // do not block this. Names each offender as user#<id> @ school#<id> (never an email).
            $enforced = collect(DutySeparation::enforcedPairs());
            $bothSidesUsers = [];
            foreach (School::query()->orderBy('id')->get() as $school) {
                $userIds = DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->where('school_id', $school->id)->pluck('model_id')->unique();
                foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
                    foreach (DutySeparation::violations($user, (int) $school->id) as $pair) {
                        $isFinance = $enforced->contains(
                            fn ($e) => $e['checker'] === $pair['checker'] && $e['maker'] === $pair['maker']
                        );
                        if ($isFinance) {
                            $bothSidesUsers[] = "user#{$user->id} @ school#{$school->id} {$pair['maker']}<>{$pair['checker']}";
                        }
                    }
                }
            }
            setPermissionsTeamId(null);

            if ($bothSidesUsers !== []) {
                $list = collect($bothSidesUsers)->unique()->values();
                throw new RuntimeException(
                    'converge-finance-change-grants ABORTED (rolled back): '.$list->count().' user(s) would hold both '
                    .'sides of a finance maker-checker pair after convergence — '.$list->implode('; ')
                    .'. Reassign one of the two roles for each listed user, then re-run the migration.'
                );
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->report('AFTER', $sixNames);
    }

    /**
     * DELIBERATE no-op. Rolling back would re-remove the maker side and re-create the zero-maker
     * state (fee-schedule change with nobody able to submit) — worse than no rollback. Roll FORWARD
     * with a new named migration instead.
     */
    public function down(): void
    {
        // intentionally empty — see the docblock above.
    }

    /**
     * Per-school holder counts for each governed permission — counts, school ids and permission
     * names only, no user names/emails. Holders derived as CheckStaffingReadiness does (raw grant).
     *
     * @param  list<string>  $sixNames
     */
    private function report(string $label, array $sixNames): void
    {
        echo "  converge-finance-change-grants [{$label}] holders per school per governed permission:\n";

        foreach (School::query()->orderBy('id')->get() as $school) {
            $userIds = DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('school_id', $school->id)
                ->pluck('model_id')->unique();
            $users = User::query()->whereIn('id', $userIds)->get();

            foreach ($sixNames as $perm) {
                $count = $users->filter(
                    fn (User $u) => DutySeparation::holdsViaGrant($u, (int) $school->id, $perm)
                )->count();
                echo "    school#{$school->id}  {$perm}  holders={$count}\n";
            }
        }

        setPermissionsTeamId(null);
    }
};
