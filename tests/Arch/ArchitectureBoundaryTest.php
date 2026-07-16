<?php

/*
 * Cross-cutting architecture boundary rules (§17.1) — the enforcement floor that
 * lands BEFORE any Finance code exists, so the boundary is mechanical from the
 * first Finance commit. Cross-cutting Kernel/boundary rules live here; when a
 * module lands it gains its own tests/Arch/<Module>Test.php (§9/§15) and its
 * module-specific rules migrate there.
 *
 * The Finance-subject rules below are guarded DYNAMICALLY on the presence of
 * app/Finance — evaluated at runtime on every test run, so they activate
 * automatically the moment the namespace appears. There is no permanent skip.
 *
 * §17.1 rule 4 (escape hatches: withoutGlobalScope / withoutSchoolScope /
 * hasRole / auth()->setUser / DB::table inside App\Finance) concerns METHOD
 * CALLS, which arch tests cannot see — it is enforced by bin/ci-boundary-lint.php.
 */

uses()->group('arch');

$financeExists = is_dir(dirname(__DIR__, 2).'/app/Finance');

// 1 · Kernel never depends on a Module. Subjects exist TODAY, so these rules are
// live now, not vacuous — they prove the arch machinery itself works.
arch('kernel (App\Support) never depends on the Finance module')
    ->expect('App\Support')
    ->not->toUse('App\Finance');

arch('kernel (App\Casts) never depends on the Finance module')
    ->expect('App\Casts')
    ->not->toUse('App\Finance');

if ($financeExists) {
    // 2 · Module internals are private.
    arch('Finance models are private to the Finance module')
        ->expect('App\Finance\Models')
        ->toOnlyBeUsedIn('App\Finance');

    arch('Finance actions are private to the Finance module')
        ->expect('App\Finance\Actions')
        ->toOnlyBeUsedIn('App\Finance');

    arch('Finance services are private to the Finance module')
        ->expect('App\Finance\Services')
        ->toOnlyBeUsedIn('App\Finance');

    // 3 · Finance may not reach into Academics.
    arch('Finance does not reach into Academics models')
        ->expect('App\Finance')
        ->not->toUse([
            'App\Models\Curriculum',
            'App\Models\Score',
            'App\Models\StudentResult',
            'App\Models\StudentCurriculum',
        ]);

    // 5 · School scoping is mandatory on Finance models.
    arch('Finance models are School-scoped')
        ->expect('App\Finance\Models')
        ->toUse('App\Concerns\BelongsToSchool');

    // 6 · Layering.
    arch('Finance actions are final and expose handle()')
        ->expect('App\Finance\Actions')
        ->toBeFinal()
        ->toHaveMethod('handle');

    arch('Finance controllers do not use the DB facade')
        ->expect('App\Finance\Http\Controllers')
        ->not->toUse('Illuminate\Support\Facades\DB');

    arch('Finance models do not use the DB facade')
        ->expect('App\Finance\Models')
        ->not->toUse('Illuminate\Support\Facades\DB');

    // 7 · No circular Module dependencies (extend per Module pair as Modules land).
    arch('Finance does not depend on Admissions')
        ->expect('App\Finance')
        ->not->toUse('App\Admissions');
} else {
    // Visible, honest placeholder: the Finance-subject rules are armed but the
    // namespace does not exist yet. This test disappears from relevance the
    // moment app/Finance is created — the guard above is re-evaluated every run.
    test('Finance arch rules are armed and auto-activate when app/Finance appears', function () {
        expect(is_dir(dirname(__DIR__, 2).'/app/Finance'))->toBeFalse();
    });
}
