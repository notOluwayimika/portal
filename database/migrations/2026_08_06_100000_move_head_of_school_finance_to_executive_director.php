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
 * Executive Director takes every finance checker side; Head of School loses finance entirely.
 *
 * Business decision, 2026-08-04 (docs/handoff/executive-director-role-brief.md). Brookstone: "The
 * heads of school have never approved any of the items listed — they initiate it for my approval",
 * and "nothing changed except switching every permission and ability held by HoS to ED. HoS doesn't
 * have access to finance." Nine grants move:
 *
 *   head_of_school     -> executive_director   finance.access
 *                                              finance.fee-schedule.change.approve / .reject
 *                                              finance.discount-policy.change.approve / .reject
 *   accounts_supervisor -> executive_director   finance.credit-note.approve / .reject
 *                                              finance.invoice.void-request.approve / .reject
 *
 * `principal` KEEPS `finance.access` — answered 2026-08-04, "The Principal role should be able to
 * view finance." It is NOT governed here and this migration must never touch it. A secondary
 * Principal who also holds head_of_school therefore still reaches the finance area, deliberately.
 *
 * WHY THIS MIGRATION IS REQUIRED, AND WHY IT IS THE FIRST REMOVAL-ONLY ONE. `RbacSeeder::sync()` is
 * non-destructive for grants in both directions (`RbacSeeder.php:490-500`):
 *
 *   · The ED ADDS land on their own. `executive_director` is absent from `$existingRoles` on every
 *     seeded environment, so it takes the `: $permissions` branch and receives all nine even though
 *     none of the permissions is new. No migration needed for that half.
 *   · The REMOVALS land nowhere, ever. `rbac:sync` revokes nothing. Deleting five lines from
 *     `grantsMap()['head_of_school']` and four from `['accounts_supervisor']` changes no seeded
 *     database, on any environment, at any point in the future.
 *
 * So the entire reason this file exists is the revoke half. Every prior convergence migration was
 * add-or-move; this one's job is subtraction, and a reader who skims it for an "add" half will not
 * find one on HoS or AS.
 *
 * The pairs this migration converges, in the marker syntax `bin/ci-grants-convergence-lint.php`
 * reads. There is exactly ONE, and its presence needs explaining rather than copying:
 *
 * @converges accounts_supervisor finance.access
 *
 * `accounts_supervisor` ALREADY holds `finance.access` — in the map before this change and in the
 * live database — so nothing about that pair drifted. The lint flags it because it diffs LINES, not
 * GRANTS: the new `executive_director` block contains an identical
 * `PermissionEnum::FINANCE_ACCESS->value,` line, git pairs the old AS line with it, and AS's
 * unchanged grant renders as an addition. The declaration is nonetheless TRUE — AS is a governed role
 * here and `finance.access` is in its derived target, so this migration does force that pair — which
 * is the only reason it is written rather than the lint being argued with. Do not read it as evidence
 * that AS lost and regained the grant.
 *
 * AND NOTE WHAT THE LINT DID NOT SAY: it reported nothing at all about the nine REMOVALS. It is
 * structurally blind to them — it walks added lines in `grantsMap()` and has no removal path. A
 * removal-only convergence therefore passes that gate with zero findings. Recorded here because the
 * gate's silence is not agreement.
 *
 * ED MAY NOT EXIST YET, AND THIS MIGRATION ABORTS RATHER THAN CREATING IT. Whether
 * `executive_director` has a role row depends on whether `rbac:sync` has run since it entered
 * `RbacSeeder::ROLES`, which is not ordered with respect to `migrate`. Creating it here was
 * considered and rejected: `two_factor_required` is applied ONLY at role creation
 * (`RbacSeeder.php:461-477` — `firstOrCreate` defaults, or `--fresh`), and ED is in
 * `TWO_FACTOR_REQUIRED`. A role row created by this migration would be created with the flag FALSE
 * and no later `rbac:sync` would ever correct it — silently stripping two-factor enrolment from the
 * one seat that can approve money leaving four different ways. Aborting costs an operator one
 * command; creating costs a permanent, invisible security downgrade.
 *
 * The abort runs with the other pre-flights, BEFORE any write, and the writes are in one transaction
 * besides — the failure mode worth designing against is HoS stripped with ED not yet able to receive.
 *
 * Everything else follows 2026_08_03_100000 / 2026_08_05_100000: fresh-install guard keyed on the
 * permission substrate; target DERIVED from `grantsMap()` and never hardcoded; global rows only,
 * school-scoped rows counted and never written; diff-based revoke+give inside one transaction so both
 * Spatie events reach `LogRbacChange` (NOT `syncPermissions`, which detaches RAW with no event);
 * idempotent, short-circuiting before any activity row; a post-write duty-separation walk over users;
 * BEFORE/AFTER holder counts per school as ids and counts only; `down()` a deliberate no-op.
 */
return new class extends Migration
{
    /** The namespace this migration governs. Prefix test on a namespace, never on a permission name. */
    private const NAMESPACE = 'finance.';

    /**
     * The roles whose `finance.` grants are forced to match `grantsMap()`.
     *
     * Written out here rather than derived: a migration is a fixed historical act and must not
     * re-shape itself if the map moves later. The GRANTS are derived; the SET is not.
     *
     * `principal` is deliberately absent — it keeps `finance.access` (§6a of the brief). So are
     * `admin`, `accounts_officer`, `finance_lead` and `internal_auditor`: this change does not touch
     * them, and governing a role means forcing it, which is not something to do by accident.
     *
     * @var list<string>
     */
    private array $governed = [
        'head_of_school',
        'accounts_supervisor',
        'executive_director',
    ];

    public function up(): void
    {
        // Fresh-install guard, keyed on the PERMISSION substrate (seeder-owned, absent at
        // migrate-from-zero). No finance permission rows at all ⇒ the seeder has not run here and will
        // write the correct map directly, so there is nothing to converge. Keyed on the whole
        // `finance.` namespace: a missing subset is a broken substrate, not a fresh install, and the
        // pre-flight below must abort on that instead of returning a quiet green.
        //
        // This guard is also on the hot path of the whole suite: `RefreshDatabase` migrates an empty
        // database on every run, so it is the only reason `migrate` succeeds there.
        $financeSubstrate = Permission::query()
            ->where('guard_name', RbacSeeder::GUARD)
            ->where('name', 'like', self::NAMESPACE.'%')
            ->exists();

        if (! $financeSubstrate) {
            echo "  move-hos-finance-to-ed: finance RBAC substrate unseeded (no finance.* permissions) — nothing to converge.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Target per governed role, DERIVED from the seeder map and sliced to the namespace. After
        // this runs, each governed role's `finance.` grants equal exactly this — head_of_school's
        // slice is EMPTY, which is the whole point of the change.
        $map = RbacSeeder::grantsMap();
        $target = [];
        foreach ($this->governed as $roleName) {
            $target[$roleName] = collect($map[$roleName] ?? [])
                ->filter(fn (string $p): bool => str_starts_with($p, self::NAMESPACE))
                ->sort()->values()->all();
        }

        // Pre-flight: every permission we must GRANT has to exist as a row (run rbac:sync otherwise).
        $mustGrant = collect($target)->flatten()->unique()->values()->all();
        $present = Permission::query()->whereIn('name', $mustGrant)
            ->where('guard_name', RbacSeeder::GUARD)->pluck('name')->all();
        $missing = array_values(array_diff($mustGrant, $present));
        if ($missing !== []) {
            throw new RuntimeException(
                'move-hos-finance-to-ed ABORTED: target permission(s) absent from the permissions table — '
                .'run `php artisan rbac:sync` first, then re-migrate: '.implode(', ', $missing)
            );
        }

        // Pre-flight: every governed role exists as a global row. `executive_director` gets its own
        // message because its absence is the ORDERING case, not a broken database — see the docblock
        // for why this aborts rather than creating the row.
        $roles = [];
        foreach ($this->governed as $roleName) {
            $role = Role::query()->where('name', $roleName)
                ->where('guard_name', RbacSeeder::GUARD)->whereNull('school_id')->first();

            if ($role === null) {
                throw new RuntimeException($roleName === 'executive_director'
                    ? 'move-hos-finance-to-ed ABORTED: the [executive_director] role row does not exist yet. '
                        .'It is new in RbacSeeder::ROLES, so run `php artisan rbac:sync` first and then re-migrate. '
                        .'This migration deliberately does NOT create the row: two_factor_required is applied only '
                        .'at role creation (RbacSeeder.php:461-477) and executive_director is in TWO_FACTOR_REQUIRED, '
                        .'so a row created here would carry the flag FALSE permanently.'
                    : "move-hos-finance-to-ed ABORTED: global role [{$roleName}] is missing.");
            }

            $roles[$roleName] = $role;
        }

        // School-scoped footprint (no action — C6 local authority stays put).
        $schoolScoped = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->whereNotNull('r.school_id')->where('r.guard_name', RbacSeeder::GUARD)
            ->whereIn('r.name', $this->governed)
            ->where('p.name', 'like', self::NAMESPACE.'%')
            ->distinct()->count(DB::raw('CONCAT(r.id, "-", p.id)'));
        echo '  move-hos-finance-to-ed: school-scoped role rows carrying '.self::NAMESPACE."* (UNTOUCHED): {$schoolScoped}\n";

        // Idempotency: already converged ⇒ clean no-op, no second batch of activity rows.
        $needsWork = false;
        foreach ($this->governed as $roleName) {
            if ($this->currentGrants($roles[$roleName]) !== $target[$roleName]) {
                $needsWork = true;
            }
        }

        $this->report('BEFORE');

        if (! $needsWork) {
            echo "  move-hos-finance-to-ed: already aligned — no grants changed, no activity rows written.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Diff-based revoke + give, atomically. revoke fires PermissionDetachedEvent, give fires
        // PermissionAttachedEvent — both reach LogRbacChange. (syncPermissions detaches RAW, no event.)
        DB::transaction(function () use ($roles, $target) {
            foreach ($this->governed as $roleName) {
                $role = $roles[$roleName];
                $current = $this->currentGrants($role);
                $revoke = array_values(array_diff($current, $target[$roleName]));
                $grant = array_values(array_diff($target[$roleName], $current));

                foreach ($revoke as $perm) {
                    $role->revokePermissionTo($perm);
                }
                if ($grant !== []) {
                    $role->givePermissionTo($grant);
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // POST-WRITE DUTY-SEPARATION WALK, still inside the transaction so a finding rolls the
            // whole thing back. A grant-map change retroactively turns an already-assigned, previously
            // legal role pair into a both-sides violation, and nothing re-validates existing users when
            // the map moves — the assignment-time guard runs only on assignment, and there is no
            // assignment here.
            //
            // The reachable direction is the ED one: four maker-checker pairs now terminate on a single
            // role, so any user who ends up holding executive_director alongside accounts_officer,
            // finance_lead or accounts_supervisor is a both-sides holder. Removals from HoS and AS can
            // only CLEAR violations, never create them. Uses DutySeparation::violations — the audit
            // command's own primitive, so this cannot disagree with finance:audit-duty-separation —
            // filtered to ENFORCED (finance) pairs, which is why the pre-existing result.* findings on
            // the live copy do not abort a finance migration.
            $enforced = collect(DutySeparation::enforcedPairs())
                ->map(fn (array $pair): string => $pair['checker'].'|'.$pair['maker'])->all();

            $findings = [];
            foreach (School::query()->orderBy('id')->get() as $school) {
                $userIds = DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->where('school_id', $school->id)
                    ->pluck('model_id')->unique();

                foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
                    foreach (DutySeparation::violations($user, (int) $school->id) as $pair) {
                        if (in_array($pair['checker'].'|'.$pair['maker'], $enforced, true)) {
                            $findings[] = "user#{$user->id} @ school#{$school->id} "
                                ."[{$pair['checker']} + {$pair['maker']}]";
                        }
                    }
                }
            }

            setPermissionsTeamId(null);

            if ($findings !== []) {
                throw new RuntimeException(
                    'move-hos-finance-to-ed ABORTED and ROLLED BACK: the new seat assignment would leave '
                    .count($findings).' user(s) holding both sides of an enforced finance pair — '
                    .implode('; ', $findings)
                );
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->report('AFTER');
    }

    /**
     * DELIBERATE no-op. Rolling back would hand finance approval authority back to seats Brookstone
     * has removed it from — and `rbac:sync` would not re-remove it, which is the whole defect this
     * migration exists for. Roll FORWARD with a new named migration instead.
     */
    public function down(): void
    {
        // intentionally empty — see the docblock above.
    }

    /**
     * This role's current grants inside the governed namespace, sorted. Reads the relation fresh so a
     * give/revoke earlier in the same run is visible.
     *
     * @return list<string>
     */
    private function currentGrants(Role $role): array
    {
        return $role->load('permissions')->permissions
            ->pluck('name')
            ->filter(fn (string $p): bool => str_starts_with($p, self::NAMESPACE))
            ->sort()->values()->all();
    }

    /**
     * Per-school holder counts for the governed roles' namespace — counts, school ids and permission
     * names only, no user names/emails. Holders derived as CheckStaffingReadiness does (raw grant, so
     * super_admin's Gate::before bypass never inflates the count).
     */
    private function report(string $label): void
    {
        echo "  move-hos-finance-to-ed [{$label}] holders per school:\n";

        $watched = [
            'finance.access',
            'finance.fee-schedule.change.approve',
            'finance.discount-policy.change.approve',
            'finance.credit-note.approve',
            'finance.invoice.void-request.approve',
        ];

        foreach (School::query()->orderBy('id')->get() as $school) {
            $userIds = DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('school_id', $school->id)
                ->pluck('model_id')->unique();
            $users = User::query()->whereIn('id', $userIds)->get();

            foreach ($watched as $permission) {
                $count = $users->filter(
                    fn (User $u) => DutySeparation::holdsViaGrant($u, (int) $school->id, $permission)
                )->count();
                echo "    school#{$school->id}  {$permission}  holders={$count}\n";
            }
        }

        setPermissionsTeamId(null);
    }
};
