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
