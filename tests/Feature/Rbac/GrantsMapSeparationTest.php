<?php

// Pins RbacSeeder::grantsMap() against DutySeparation::pairs(): no seeded role may grant BOTH sides
// of a maker-checker pair. This is the invariant PR #190 left unguarded — violationsFromRolePermissionSync
// has exactly one caller (the C6 runtime matrix-edit request), so the SEEDED map is checked by nothing.
// A map edit that hands one role a maker and its matching checker would ship green and rbac:sync would
// write it, and finance:audit-duty-separation only notices once a user is ASSIGNED that role.
//
// NOTE ON THE CHECK. The brief proposed running each role through
// DutySeparation::violationsFromRolePermissionSync. That method is the wrong tool for THIS invariant,
// proven empirically (a scratch role granting finance.credit-note.submit + .approve returns [] from it):
//   - it filters out pairs where the requested set carries BOTH sides (its $relevant is an XOR — the
//     same-role-both-sides case it is meant to catch is exactly what it excludes, because that case is
//     the ASSIGNMENT-time guard's job, not its own);
//   - it is holder-scoped (a both-sides role with zero members yields no finding);
//   - it hardcodes enforcedPairs(), so it cannot assert over all pairs() as item 2 requires.
// So this asserts the property DIRECTLY: for every role in the map, over every pair, the role does not
// hold maker AND checker together. Iterates the map (never a hardcoded role list), so a role added to
// grantsMap() tomorrow is covered the moment it lands.

use App\Enums\Permission as PermissionEnum;
use App\Support\DutySeparation;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('no role in grantsMap() grants both sides of any maker-checker pair (all pairs, not just enforced)', function () {
    $pairs = DutySeparation::pairs(); // ALL pairs — the map should be clean regardless of runtime enforcement scope
    $map = RbacSeeder::grantsMap();

    $bad = [];
    foreach ($map as $role => $abilities) {
        $held = collect($abilities);
        foreach ($pairs as $pair) {
            if ($held->contains($pair['maker']) && $held->contains($pair['checker'])) {
                $bad[] = "{$role} holds both maker [{$pair['maker']}] and checker [{$pair['checker']}]";
            }
        }
    }

    expect($bad)->toBe([]);
});

it('super_admin holds NO maker-checker ability in grantsMap() (ADR 0040)', function () {
    // The brief expected super_admin to be ABSENT from the map. It is not, by design: RbacSeeder's
    // own docblock and ADR 0045 put its platform fallback set (rbac.impersonate, rbac.manage_users,
    // activity_log.view_system, activity_log.view_cross_school) in the map so its access does not
    // silently couple to the Gate::before flag. What ADR 0040 actually guarantees is the narrower,
    // real invariant asserted here: super_admin holds no maker OR checker side of any pair, so it can
    // never approve its own work even with the bypass on. ("Absent from the map" would be false.)
    $sides = collect(DutySeparation::pairs())->flatMap(fn ($p) => [$p['maker'], $p['checker']])->unique();
    $held = collect(RbacSeeder::grantsMap()['super_admin'] ?? [])->filter(fn ($a) => $sides->contains($a))->values()->all();

    expect($held)->toBe([]);
});

// ── The isolation boundary: no BUSINESS role may cross school_id ────────────
//
// v10 §7.2 (docs/Finance Module — Implementation Master Plan - v10.md:375) requires the
// isolation-crossing set to be "an explicit list, itself asserted" — no segment rule can derive it,
// because `view_cross_school` is read-shaped like `view`/`export` and what makes it different is its
// EFFECT (ActivityLogQueryService::baseQuery drops the school predicate entirely for a holder), not
// its name. The list lives at PermissionEnum::ISOLATION_CROSSING, NOT in this file: the runtime C6
// matrix guard (App\Http\Requests\SyncRolePermissionsRequest) reads the same constant, and a second
// hardcoded copy here would be drift waiting for a deploy. These tests are the "itself asserted" half.

it('the isolation-crossing list names only real, currently-declared permissions', function () {
    // Without this the list can rot into a set of strings that match nothing, and every assertion
    // below it passes vacuously while the real permission goes unguarded.
    expect(PermissionEnum::ISOLATION_CROSSING)->not->toBeEmpty()
        ->and(array_diff(PermissionEnum::ISOLATION_CROSSING, PermissionEnum::values()))->toBe([]);
});

it('no role in grantsMap() except super_admin grants an isolation-crossing permission (ADR 0036)', function () {
    // super_admin is the ONE justified exemption, and it is justified rather than merely excluded:
    // ADR 0045 A3 puts its platform set (RbacSeeder::SUPER_ADMIN_PLATFORM, which carries
    // activity_log.view_cross_school) in the map explicitly so its access does not silently couple
    // to the Gate::before flag, and it is platform support rather than a business seat. It is also
    // unreachable through the C6 matrix (SyncRolePermissionsRequest::authorize()), so exempting it
    // here strands nothing. An UNEXPLAINED exemption is how a deny-list goes stale — hence this
    // paragraph. Iterates the map, never a hardcoded role list, so a role added tomorrow is covered
    // the moment it lands.
    $bad = [];
    foreach (RbacSeeder::grantsMap() as $role => $abilities) {
        if ($role === 'super_admin') {
            continue;
        }

        foreach (PermissionEnum::ISOLATION_CROSSING as $crossing) {
            if (in_array($crossing, $abilities, true)) {
                $bad[] = "{$role} grants isolation-crossing [{$crossing}]";
            }
        }
    }

    expect($bad)->toBe([]);
});

it('super_admin holds the isolation-crossing set through SUPER_ADMIN_PLATFORM, not incidentally', function () {
    // The exemption above is only safe while super_admin's holding comes from the self-healed
    // platform constant. If it ever arrived some other way, the exemption would be covering an
    // ordinary grant rather than the sanctioned one.
    $superAdmin = RbacSeeder::grantsMap()['super_admin'] ?? [];

    foreach (PermissionEnum::ISOLATION_CROSSING as $crossing) {
        expect($superAdmin)->toContain($crossing)
            ->and(RbacSeeder::SUPER_ADMIN_PLATFORM)->toContain($crossing);
    }
});
