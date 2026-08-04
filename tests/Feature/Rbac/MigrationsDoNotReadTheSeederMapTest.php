<?php

// ADR 0052 — A MIGRATION IS A DATED ACT, NOT A LIVE QUERY.
//
// A migration that writes grants carries its target as a frozen literal, dated and attributed to the
// commit that added it. It never reads `RbacSeeder`'s grants map.
//
// THE DEFECT THIS EXISTS TO STOP RECURRING. Four shipped convergence migrations computed their target
// from the seeder map at RUN TIME while freezing their governed role set as a literal, and their
// docblocks described that split as a design. The two halves moved in opposite directions, so every
// later map edit silently rewrote what an already-shipped migration does on replay. Two failure modes
// came out of it, and one seat move on 2026-08-04 triggered both:
//
//   A. THE MIGRATION CHANGES IDENTITY. 2026_08_05 was authored to GRANT finance.access to
//      head_of_school. Replayed against the post-seat-move map, where HoS holds no finance grant at
//      all, it REVOKES it. Same filename, same batch row, opposite act — on every migrate:fresh,
//      migrate:refresh, restored backup, and the release gate's rollback-and-re-up.
//   B. THE MIGRATION BRICKS. The offender pre-flights aborted when a role outside the frozen governed
//      set held a governed permission. The new executive_director role holds five of them by design,
//      so `migrate` died — first at 2026_08_02, which had no test file at all and so was invisible in
//      a suite reporting six red arms in two other files.
//
// The four files this branch converted:
//   database/migrations/2026_08_02_100000_realign_finance_governance_grants.php      (frozen at f143b40)
//   database/migrations/2026_08_03_100000_converge_finance_change_grants.php         (frozen at 01fdeda)
//   database/migrations/2026_08_04_100000_revoke_internal_auditor_cross_school.php   (frozen at 4d4c9c5)
//   database/migrations/2026_08_05_100000_converge_finance_access_grants.php         (frozen at af9db7a)
//
// The rule is RETROACTIVE, not just forward-looking: this test scans every migration in the tree, not
// only the ones a branch adds.
//
// WHY THIS IS NOT IN bin/ci-grants-convergence-lint.php (bin/quality step 7). That gate is
// diff-based — it reads only the files a branch ADDS (`--diff-filter=A`), by a rule of its own that
// is correct for what it does. A migration already on the base is structurally invisible to it, and
// files already on the base are exactly the population this invariant governs.

it('no migration reads the seeder grants map — a migration is a dated act, not a live query', function () {
    $files = glob(database_path('migrations/*.php'));

    // THE ORDER OF THESE TWO ASSERTIONS IS THE POINT. A scan of zero files finds no offender and
    // reports green, and that is the one failure mode this test may not have: a moved directory, a
    // renamed path, a glob that silently returns [] would leave the rule enforced by nothing while
    // looking enforced. Assert the population FIRST.
    expect($files)->toBeArray()
        ->and(count($files))->toBeGreaterThan(100, 'the migrations glob matched almost nothing — this test is scanning the wrong directory, and a green here would mean it looked at zero files');

    $offenders = [];
    foreach ($files as $file) {
        if (str_contains((string) file_get_contents($file), 'grantsMap')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], 'these migrations read the seeder grants map at run time; freeze their target as a literal instead — see ADR 0052');
});
