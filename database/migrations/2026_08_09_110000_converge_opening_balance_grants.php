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
 * §9 step 4c — re-establish the opening-balance grants that
 * `2026_08_06_100000_move_head_of_school_finance_to_executive_director.php` strips.
 *
 * ── WHY THIS FILE EXISTS, WHICH IS NOT WHAT ITS AUTHOR FIRST BELIEVED ──
 *
 * 4c's brief said no migration was "needed or possible", on the grounds that the three
 * `finance.opening-balance.*` permissions are NEW and `bin/ci-grants-convergence-lint.php`'s
 * exemption 1 therefore waives one. THAT REASONING CONFLATES TWO DIFFERENT QUESTIONS, and the
 * distinction is the whole content of this docblock:
 *
 *   1. DOES THE LINT DEMAND A MIGRATION? No — and that is still true. A new permission lands in
 *      `$newPermissions` and `rbac:sync` grants it per `grantsMap()` on every environment, so there
 *      is no drift for the lint to catch. Exemption 1 is correct and unchanged.
 *
 *   2. DOES THE GRANT SURVIVE A DEPLOY? That is a different question, and the lint says nothing
 *      about it. 2026_08_06_100000's TARGET is FORCING — each governed role's `finance.` slice is
 *      made to EQUAL a frozen literal, not merely to contain it. On the runbook's order
 *      (`rbac:sync`, then `migrate`) the seeder writes the three grants and that migration then
 *      revokes the two it governs. No later `rbac:sync` restores them, because by then the
 *      permissions are no longer new and `RbacSeeder::sync()` grants an existing role only
 *      permissions created in that same run.
 *
 * MEASURED, not reasoned, on `portal_testing`: `migrate:fresh --seed` leaves
 * `executive_director` holding `.approve` + `.reject` and `accounts_supervisor` holding `.submit`;
 * running 2026_08_06_100000 leaves all three EMPTY. `accounts_officer` keeps its `.submit` — it is
 * not a governed role in that TARGET, which was CHECKED rather than assumed, and is why this
 * migration governs two roles and not three.
 *
 * ── THE GENERAL TRAP, RECORDED HERE BECAUSE 4c IS ONLY THE FIRST TO HIT IT ──
 *
 * A FORCING convergence TARGET freezes a NAMESPACE, not a row set, and it has no expiry. Every
 * permission added to `finance.` after 2026_08_06_100000 was written — by anyone, in any later
 * commit — is stripped by the next `migrate` on any environment where that migration has not yet
 * run, silently, whatever the seeder says. The same note is on 2026_08_06_100000 itself, in ADR
 * 0052, and beside exemption 1 in the lint.
 *
 * ── WHAT THIS MIGRATION DOES ──
 *
 * ROLL FORWARD. 2026_08_06_100000 is NOT edited: its frozen act is honest and describes exactly
 * what its author intended on the day, which is the entire value of a frozen act (ADR 0052). What
 * was wrong was not that migration; it was the assumption that a seeder grant is the end of the
 * story.
 *
 * ADDITIVE ONLY. It grants; it revokes nothing, and there is no revoke branch to delete later. Two
 * consequences worth stating rather than leaving to be inferred:
 *
 *   - It cannot create a both-sides holder that did not already exist *as a role-pair*, because it
 *     never takes a grant away and never re-points one. But it CAN put a maker and a checker of one
 *     pair onto two roles a single user already wears — so the post-write user walk below is real
 *     work, not ceremony. That is the opposite of 2026_08_06_100000's situation, whose own
 *     retraction box records that its walk can never fire because its `$grantedThisRun` is always
 *     empty (its content is the revoke half). Here `$grantedThisRun` is non-empty whenever the
 *     migration does anything at all, which is precisely when a violation could appear.
 *   - It is NOT forcing, deliberately. A forcing target here would propagate the very defect this
 *     file exists to repair one namespace further down.
 *
 * Everything else follows the two convergence precedents: target FROZEN as plain strings at the
 * commit that adds it; school-scoped rows counted and never written; `givePermissionTo` so
 * `PermissionAttachedEvent` reaches `LogRbacChange` (never `syncPermissions`, which detaches RAW
 * with no event); transaction-wrapped so the SoD walk can roll the whole thing back; idempotent,
 * short-circuiting before any activity row; fresh-install guarded; `down()` a deliberate no-op.
 *
 * The pairs this migration converges, in the marker syntax `bin/ci-grants-convergence-lint.php`
 * reads. Exemption 3 collects markers only from migrations a diff ADDS, so these are LIVE over the
 * base this branch is pushed against and inert over any base that already contains this file.
 *
 * @converges executive_director finance.opening-balance.approve
 * @converges executive_director finance.opening-balance.reject
 * @converges accounts_supervisor finance.opening-balance.submit
 */
return new class extends Migration
{
    /** The namespace this migration governs. Prefix test on a namespace, never on a permission name. */
    private const NAMESPACE = 'finance.opening-balance.';

    /**
     * The grants this migration was written to establish, FROZEN at the commit that adds it, sliced
     * to the governed namespace.
     *
     * PLAIN STRINGS, not `PermissionEnum::` constants: an enum case can be renamed or deleted, and a
     * frozen historical act must not depend on today's enum any more than on today's map (ADR 0052).
     *
     * `accounts_officer` IS DELIBERATELY ABSENT. It also holds `finance.opening-balance.submit` from
     * the seeder, but 2026_08_06_100000 does not govern that role and so does not strip it —
     * verified against the database, not inferred from its docblock. Listing it here would mean
     * governing a role this change has no business touching.
     *
     * @var array<string, list<string>>
     */
    private const TARGET = [
        'executive_director' => [
            'finance.opening-balance.approve',
            'finance.opening-balance.reject',
        ],
        'accounts_supervisor' => [
            'finance.opening-balance.submit',
        ],
    ];

    public function up(): void
    {
        $inNs = fn (string $p): bool => str_starts_with($p, self::NAMESPACE);

        // Fresh-install guard, keyed on the PERMISSION substrate (seeder-owned, absent at
        // migrate-from-zero). It is also on the hot path of the whole suite: RefreshDatabase migrates
        // an empty database on every run, so this is the only reason `migrate` succeeds there.
        $substrate = Permission::query()
            ->where('guard_name', RbacSeeder::GUARD)
            ->where('name', 'like', self::NAMESPACE.'%')
            ->exists();

        if (! $substrate) {
            echo "  converge-opening-balance-grants: opening-balance permissions unseeded — nothing to converge.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        $skipped = 0;

        // A frozen target may name a permission row that no longer exists; that is the world moving
        // on, not a danger, so it is skipped with a line naming it rather than aborting (ADR 0052).
        $wanted = collect(self::TARGET)->flatten()->unique()->values()->all();
        $present = Permission::query()->whereIn('name', $wanted)
            ->where('guard_name', RbacSeeder::GUARD)->pluck('name')->all();

        $target = [];
        foreach (self::TARGET as $roleName => $permissions) {
            foreach (array_diff($permissions, $present) as $absent) {
                echo "  converge-opening-balance-grants SKIPPED: permission [{$absent}] has no row — not granted to [{$roleName}].\n";
                $skipped++;
            }
            $target[$roleName] = collect($permissions)->intersect($present)->sort()->values()->all();
        }

        // A governed role row that does not exist cannot hold a grant that needs converging. Skip it,
        // say so, continue — the same choice 2026_08_03_100000 makes, and for its reason: a dated act
        // must not die because a later release renamed a role.
        $roles = [];
        foreach (array_keys($target) as $roleName) {
            $role = Role::query()->where('name', $roleName)
                ->where('guard_name', RbacSeeder::GUARD)->whereNull('school_id')->first();

            if ($role === null) {
                echo "  converge-opening-balance-grants SKIPPED: global role [{$roleName}] does not exist — nothing to converge for it.\n";
                $skipped++;
                unset($target[$roleName]);

                continue;
            }

            $roles[$roleName] = $role;
        }

        // School-scoped footprint (no action — C6 local authority stays put).
        $schoolScoped = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->whereNotNull('r.school_id')->where('r.guard_name', RbacSeeder::GUARD)
            ->where('p.name', 'like', self::NAMESPACE.'%')
            ->distinct()->count(DB::raw('CONCAT(r.id, "-", p.id)'));
        echo '  converge-opening-balance-grants: school-scoped role rows carrying '
            .self::NAMESPACE."* (UNTOUCHED): {$schoolScoped}\n";

        // Idempotency: every wanted grant already held ⇒ clean no-op, no second batch of activity
        // rows. Note the test is CONTAINMENT, not equality — this migration is additive, so a
        // governed role holding MORE than the target is aligned as far as this file is concerned.
        $missingByRole = [];
        foreach ($target as $roleName => $wantedForRole) {
            $current = $roles[$roleName]->permissions->pluck('name')->filter($inNs)->values()->all();
            $missing = array_values(array_diff($wantedForRole, $current));
            if ($missing !== []) {
                $missingByRole[$roleName] = $missing;
            }
        }

        if ($missingByRole === []) {
            echo '  converge-opening-balance-grants: already aligned — no grants changed, no activity rows written'
                .($skipped > 0 ? " ({$skipped} skipped)" : '')."\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        DB::transaction(function () use ($roles, $missingByRole) {
            $grantedThisRun = [];

            foreach ($missingByRole as $roleName => $grant) {
                // givePermissionTo, never syncPermissions: HasPermissions::syncPermissions detaches
                // RAW with no event, so its removals are invisible to the rbac audit listener. This
                // migration removes nothing, but the habit is what matters — the next person tidying
                // a convergence migration into one `sync` call is the failure mode.
                $roles[$roleName]->givePermissionTo($grant);
                $grantedThisRun = array_merge($grantedThisRun, $grant);
                echo '  converge-opening-balance-grants: granted ['.implode(', ', $grant)."] to [{$roleName}]\n";
            }

            $grantedThisRun = array_values(array_unique($grantedThisRun));

            // The writes above leave the registrar's permission cache stale; flush it so the walk
            // below reads the real post-convergence state (uncommitted, but visible in this
            // transaction).
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // POST-WRITE DUTY-SEPARATION WALK, still inside the transaction so a finding rolls the
            // whole thing back. A grant-map change RETROACTIVELY turns an already-assigned,
            // previously-legal role pair into a both-sides violation, and nothing re-validates
            // existing users when the map moves — the assignment-time guard runs only on assignment,
            // and there is no assignment here.
            //
            // THIS WALK CAN ACTUALLY FIRE, unlike 2026_08_06_100000's (see its retraction box). That
            // migration's content is the REVOKE half, so its `$grantedThisRun` is empty on every
            // sequence rbac:sync produces and its throw is unreachable. This migration's content is
            // entirely grants, and it puts a maker (accounts_supervisor) and a checker
            // (executive_director) of the SAME pair onto two different roles — so a user wearing both
            // becomes a both-sides holder the moment this commits, which is exactly what it must
            // refuse to do.
            //
            // SCOPED TO WHAT THIS RUN WROTE (ADR 0052): a pair is this migration's to block on only
            // when at least one of its two sides is a permission this run actually granted. Anything
            // else is a pre-existing state it did not create — reported, never blocked on. Reuses
            // DutySeparation::violations so it cannot disagree with finance:audit-duty-separation,
            // and enforcedPairs() so pre-existing result.* findings do not abort a finance act.
            $enforced = collect(DutySeparation::enforcedPairs())
                ->filter(fn (array $pair): bool => in_array($pair['checker'], $grantedThisRun, true)
                    || in_array($pair['maker'], $grantedThisRun, true));

            $offenders = [];
            $outOfScope = 0;

            foreach (School::query()->orderBy('id')->get() as $school) {
                $userIds = DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->where('school_id', $school->id)
                    ->distinct()->pluck('model_id');

                foreach (User::query()->whereIn('id', $userIds)->orderBy('id')->get() as $user) {
                    foreach (DutySeparation::violations($user, (int) $school->id) as $violation) {
                        $inScope = $enforced->contains(
                            fn (array $pair): bool => $pair['checker'] === $violation['checker']
                                && $pair['maker'] === $violation['maker']
                        );

                        if (! $inScope) {
                            $outOfScope++;

                            continue;
                        }

                        $offenders[] = "user#{$user->id} @ school#{$school->id} "
                            ."[{$violation['checker']} + {$violation['maker']}]";
                    }
                }
            }

            if ($outOfScope > 0) {
                echo "  converge-opening-balance-grants: both-sides findings OUTSIDE this run's scope (not blocked on): {$outOfScope}\n";
            }

            if ($offenders !== []) {
                throw new RuntimeException(
                    'converge-opening-balance-grants ABORTED and rolled back: this convergence would leave '
                    .count($offenders).' user(s) holding both sides of a finance maker-checker pair — '
                    .implode('; ', array_unique($offenders))
                    .'. Resolve the role assignments (one of the two roles must come off the user), then re-migrate.'
                );
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * A DELIBERATE NO-OP, the same ruling 2026_08_02_100000 and 2026_08_03_100000 made: rolling this
     * back would restore the state where the opening-balance gate has a checker ability nobody holds
     * and a cutover nobody can approve. Roll forward with a new named migration instead.
     */
    public function down(): void
    {
        echo '  converge-opening-balance-grants: down() is a deliberate no-op — reverting would leave the '
            ."opening-balance approval gate with no holders. Roll forward instead.\n";
    }
};
