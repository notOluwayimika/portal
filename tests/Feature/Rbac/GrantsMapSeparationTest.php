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
use App\Support\ApprovalAbility;
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

// ── The pairs themselves: both sides must be permissions that EXIST ─────────
//
// The same "itself asserted" discipline as the isolation-crossing block below, applied one level
// earlier — to the pair set rather than to who holds it.

it('every maker and checker in DutySeparation::pairs() names a real, currently-declared permission', function () {
    // WHAT GOES WRONG WITHOUT THIS. ApprovalAbility::matchingMakerFor() is string surgery: it
    // replaces the terminal segment with `submit` and NEVER checks the result exists. So a checker
    // whose real maker is not `<prefix>.submit` still produces a pair — one naming a permission
    // nobody can hold. pairs() emits it, enforcedPairs() includes it if it starts with `finance.`,
    // and violations() then asks holds($user, $school, <fictional>), which is false for everyone
    // forever. The pair is listed, counted, and structurally incapable of firing: a duty-separation
    // control that reads as present and refuses nothing.
    //
    // WHY THE super_admin ARM BELOW DOES NOT COVER IT — and this is the whole reason this test
    // exists as a separate one. "super_admin holds NO maker-checker ability in grantsMap()"
    // flattens both sides of every pair and asserts super_admin holds none of them. A FICTIONAL
    // NAME SATISFIES THAT FOR FREE: nobody holds a permission that does not exist, so the more
    // broken the pair, the more comfortably that arm passes. It is a true assertion about a set
    // whose membership it cannot vouch for.
    //
    // Non-empty first, for the same reason the isolation-crossing arm asserts it: an empty pair set
    // makes every assertion over it vacuous, and a convention-derived set CAN go empty (rename the
    // segments and pairs() silently returns []).
    expect(DutySeparation::pairs())->not->toBeEmpty();

    $declared = PermissionEnum::values();
    $fictional = [];

    foreach (DutySeparation::pairs() as $pair) {
        foreach (['checker', 'maker'] as $side) {
            if (! in_array($pair[$side], $declared, true)) {
                $fictional[] = "{$side} [{$pair[$side]}] of pair [{$pair['checker']}]";
            }
        }
    }

    // Named IN THE FAILURE, not merely collected. A bare toBe([]) prints "two arrays are identical"
    // and leaves the reader to diff them; a gate that fails without saying WHICH entry is wrong is
    // one people learn to regenerate past.
    //
    // And a fictional side is a finding — an inert control — never a number to baseline: baselining
    // it would freeze the inertness and leave the duty-separation report claiming a pair it can
    // never evaluate.
    expect($fictional)->toBe([], 'ApprovalAbility derives a maker-checker pair naming a permission that does not exist, '
        ."so DutySeparation can never evaluate it:\n  - ".implode("\n  - ", $fictional));
});

it('any maker-override map on ApprovalAbility names only real permissions on BOTH sides', function () {
    // WRITTEN BEFORE THE MAP EXISTS, DELIBERATELY. There is no override map on ApprovalAbility
    // today — CHECKER_SEGMENTS is the only constant, and it holds SEGMENTS (`approve`, `reject`),
    // not permission names, so it is excluded here with that reason rather than skipped silently.
    //
    // An override map is the obvious way to make a checker's real maker something other than
    // `<prefix>.submit` (finance.invoice.approve -> finance.invoice.generate). It is also an
    // ENUMERATED LIST, which is exactly what DutySeparation's docblock says the convention avoids
    // so that "a future instance (refunds) joins with no edit". An enumerated list goes stale in
    // one direction the derivation cannot: a permission is renamed or removed and the map keeps
    // pointing at the old name, restoring the very defect the test above catches. So the assertion
    // is written now, by name, and covers the map from the commit that introduces it.
    //
    // UNRECOGNISED CONSTANTS RED. Asserting only a name we chose in advance would miss a map that
    // arrives under a different one, which is the failure this whole test is about — a check
    // looking at the wrong thing. So every constant on the class must be either classified below
    // or a permission map that passes; a new one fails until somebody says which it is.
    $reflection = new ReflectionClass(ApprovalAbility::class);

    $segmentConstants = ['CHECKER_SEGMENTS'];   // segments, not permission names — excluded, with the reason
    $permissionMaps = ['MAKER_OVERRIDES'];      // checker => maker, both sides asserted below

    $declared = PermissionEnum::values();
    $problems = [];

    foreach ($reflection->getConstants() as $name => $value) {
        if (in_array($name, $segmentConstants, true)) {
            continue;
        }

        if (! in_array($name, $permissionMaps, true)) {
            $problems[] = "UNRECOGNISED constant [{$name}] on ApprovalAbility — classify it as a "
                .'segment list or a permission map in this test';

            continue;
        }

        foreach ((array) $value as $checker => $maker) {
            if (! in_array((string) $checker, $declared, true)) {
                $problems[] = "{$name} key [{$checker}] is not a declared permission";
            }

            if (! in_array((string) $maker, $declared, true)) {
                $problems[] = "{$name}[{$checker}] maps to [{$maker}], which is not a declared permission";
            }
        }
    }

    expect($problems)->toBe([], "ApprovalAbility's constants do not all name real permissions:\n  - "
        .implode("\n  - ", $problems));
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
