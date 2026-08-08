<?php

use Database\Seeders\RbacSeeder;

// A FORCING CONVERGENCE MIGRATION FREEZES A NAMESPACE, NOT A ROW SET — so it keeps acting on
// permissions that did not exist when it was written. This test is the enforcement for that.
//
// THE DEFECT IT EXISTS TO STOP RECURRING. §9 step 4c added
// finance.opening-balance.submit/.approve/.reject to the seeder's grants map.
// 2026_08_06_100000_move_head_of_school_finance_to_executive_director makes each governed role's
// `finance.` slice EQUAL a frozen literal, so on the deploy order (`rbac:sync`, then `migrate`) the
// seeder wrote all three and that migration revoked the two it governs. `rbac:sync` does not put
// them back: by then the permissions are no longer new, and RbacSeeder::sync() grants an existing
// role only permissions created in that same run. Measured on a real database, not reasoned — the
// probe output is in docs/handoff/reports/feat-finance-ob-approval-gate.md §R1.
//
// Nothing caught it. The convergence lint's exemption 1 waives a migration for a NEW permission, and
// it is right to: that exemption answers "will the grant LAND?", which is a different question from
// "will the grant SURVIVE?". Three comments were written about the trap — on the forcing migration,
// in ADR 0052, and beside exemption 1 — and three comments are a wish, not a rule. This is the rule.
//
// THE INVARIANT. For every role a forcing migration governs, every permission the grants map gives
// that role INSIDE the frozen namespace must be covered EITHER by that migration's own TARGET
// literal, OR by an `@converges <role> <permission>` marker on a migration dated AFTER it.
// Uncovered ⇒ the grant is written by the seeder and revoked by the migration on the next deploy.
//
// BOTH SIDES ARE DERIVED FROM SOURCE, never restated here: the namespace and the TARGET by
// reflecting the migration's own constants, the grants from RbacSeeder::grantsMap(), the markers by
// scanning database/migrations/. Nothing in this file knows today's answer, which is the point — a
// grant added tomorrow with no convergence migration turns it red without anyone editing it.
//
// ── EXACTLY ONE FORCING MIGRATION EXISTS TODAY, AND THIS FILE SAYS SO RATHER THAN IMPLYING MORE ──
//
// FORCING is a property of the migration's BODY, not of its name or its constants: it is forcing
// when it computes `array_diff($current, $target)` and REVOKES the difference. There is no marker
// for it and this test does not try to infer one — a heuristic that silently classified a migration
// as non-forcing would be a green proving nothing, which is the failure class this file replaces.
//
// So the list below is MANUAL, and registering a second one is a deliberate act: add its filename
// here. A forcing migration must expose a `NAMESPACE` string constant and a `TARGET` array constant
// keyed by role name — the two this test reflects — and the assertions below fail loudly rather than
// skipping if either is missing.
const FORCING_MIGRATIONS = [
    '2026_08_06_100000_move_head_of_school_finance_to_executive_director.php',
];

/** The marker regex is `bin/ci-grants-convergence-lint.php:414` verbatim, so the two cannot disagree. */
const CONVERGES_MARKER = '/^[ \t]*(?:\*|\/\/|#)?[ \t]*@converges[ \t]+([A-Za-z0-9_]+)[ \t]+([A-Za-z0-9_.\-]+)[ \t]*$/m';

it('no forcing convergence migration strips a grant the seeder map adds after it', function () {
    $dir = database_path('migrations');
    $files = glob($dir.'/*.php') ?: [];

    // THE ORDER OF THESE ASSERTIONS IS THE POINT, and it is MigrationsDoNotReadTheSeederMapTest's
    // lesson: a scan of zero files finds no offender and reports green. Assert the population first.
    expect(count($files))->toBeGreaterThan(100,
        'the migrations glob matched almost nothing — this test is scanning the wrong directory, and a green here would mean it looked at zero files');

    $grants = RbacSeeder::grantsMap();
    expect($grants)->toBeArray()->not->toBeEmpty('RbacSeeder::grantsMap() returned nothing — this test would pass vacuously');

    $offenders = [];
    $coveredByTarget = 0;
    $coveredByMarker = 0;

    foreach (FORCING_MIGRATIONS as $basename) {
        $path = $dir.'/'.$basename;
        expect(is_file($path))->toBeTrue("FORCING_MIGRATIONS names [{$basename}], which does not exist — rename it here or remove it");

        // Reflect the migration's OWN constants rather than re-typing them. getConstants() returns
        // private constants too, which is what makes this work on the anonymous class a migration
        // file returns.
        $constants = (new ReflectionClass(require $path))->getConstants();

        // array_key_exists, not toHaveKey: toHaveKey's second argument is the expected VALUE, so a
        // message passed there is asserted as the constant's contents — a green that means nothing
        // and a red that reads as gibberish. Found the hard way.
        expect(array_key_exists('NAMESPACE', $constants))->toBeTrue("[{$basename}] has no NAMESPACE constant — a forcing migration must expose the namespace it freezes")
            ->and(array_key_exists('TARGET', $constants))->toBeTrue("[{$basename}] has no TARGET constant — a forcing migration must expose its frozen target");

        $namespace = $constants['NAMESPACE'];
        $target = $constants['TARGET'];

        expect($target)->toBeArray()->not->toBeEmpty("[{$basename}]'s TARGET is empty — nothing would be checked against it");

        // Markers on migrations dated AFTER this one. The filename prefix is `YYYY_MM_DD_HHMMSS_`,
        // so a plain string comparison IS the date comparison — and it is the same ordering Laravel
        // runs them in, which is the ordering that matters: a marker on a migration that runs BEFORE
        // the forcing one would be re-stripped by it.
        $markers = [];
        foreach ($files as $file) {
            if (basename($file) <= $basename) {
                continue;
            }
            if (preg_match_all(CONVERGES_MARKER, (string) file_get_contents($file), $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $markers[$match[1]."\0".$match[2]] = basename($file);
                }
            }
        }

        foreach ($target as $role => $frozen) {
            foreach ($grants[$role] ?? [] as $permission) {
                if (! str_starts_with($permission, $namespace)) {
                    continue;
                }

                if (in_array($permission, $frozen, true)) {
                    $coveredByTarget++;

                    continue;
                }

                if (isset($markers[$role."\0".$permission])) {
                    $coveredByMarker++;

                    continue;
                }

                $offenders[] = "{$role} + {$permission} (governed by {$basename}, in neither its TARGET "
                    .'nor any @converges marker dated after it)';
            }
        }
    }

    // NON-VACUITY, AND BOTH ARMS SEPARATELY. A green here is only worth something if the loop
    // actually reached permissions AND both exemption paths were exercised. Today ED's nine finance
    // grants are covered by the TARGET and 4c's three by markers on 2026_08_09_110000, so both
    // counters are non-zero — if either ever falls to zero, that arm has stopped being tested and the
    // test is half wallpaper again.
    expect($coveredByTarget)->toBeGreaterThan(0, 'no grant was covered by a TARGET — the TARGET arm is not being exercised')
        ->and($coveredByMarker)->toBeGreaterThan(0, 'no grant was covered by an @converges marker — the marker arm is not being exercised');

    expect($offenders)->toBe([],
        'these grants are written by the seeder map and then REVOKED by a forcing convergence migration on the '
        .'next deploy (rbac:sync, then migrate), and no later rbac:sync restores them. Ship an additive '
        .'convergence migration dated after the forcing one, carrying an @converges marker per pair — '
        .'2026_08_09_110000_converge_opening_balance_grants.php is the worked example. See ADR 0052 '
        .'§ "A FORCING target freezes a namespace, not a row set".');
});
