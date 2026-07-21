<?php

use App\Console\Commands\AuthzObservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function plantObservation(array $overrides = []): void
{
    DB::table('authz_observations')->insert(array_merge([
        'user_id' => 1,
        'school_id' => 1,
        'ability' => 'guardian.update',
        'check_type' => 'permission',
        'controller_action' => 'GuardianController@update',
        'route' => 'guardians.update',
        'request_uri' => '/api/guardians/x',
        'method' => 'PATCH',
        'transport' => 'api',
        'roles' => json_encode(['registrar']),
        'occurred_at' => now(),
    ], $overrides));
}

it('summarizes denial classes with role breakdown and classification status', function () {
    plantObservation();
    plantObservation(['user_id' => 2, 'roles' => json_encode(['teacher'])]);
    plantObservation(['ability' => 'guardian.create', 'controller_action' => 'GuardianController@store']);

    expect(Artisan::call('authz:observations', ['--summarize' => true, '--json' => true]))->toBe(0);

    $out = Artisan::output();
    $classes = collect(json_decode($out, true));
    $update = $classes->firstWhere('ability', 'guardian.update');

    expect($update)->not->toBeNull()
        ->and($update['denials'])->toBe(2)
        ->and($update['roles'])->toContain('registrar')
        ->and($update['roles'])->toContain('teacher')
        ->and($update['classification'])->toBe('UNCLASSIFIED')
        ->and($classes->firstWhere('ability', 'guardian.create'))->not->toBeNull();
});

it('the unclassified gate DENIES while an observed class lacks a classification (instrument bite-proof)', function () {
    plantObservation();

    // The gate must be able to say "denied" — exit 1, naming the path to fix.
    $this->artisan('authz:observations --unclassified')
        ->expectsOutputToContain(AuthzObservations::CLASSIFICATIONS_PATH)
        ->assertFailed();
});

it('the unclassified gate passes once every observed class is classified', function () {
    plantObservation();

    // Classify via the same file the runbook's PR workflow edits.
    $path = base_path(AuthzObservations::CLASSIFICATIONS_PATH);
    $original = file_get_contents($path);
    file_put_contents($path, json_encode([
        'classes' => [[
            'ability' => 'guardian.update',
            'controller_action' => 'GuardianController@update',
            'classification' => 'expected',
            'reviewed_by' => 'test',
            'reviewed_at' => '2026-07-21',
        ]],
    ]));

    try {
        $this->artisan('authz:observations --unclassified')->assertSuccessful();
    } finally {
        file_put_contents($path, $original);
    }
});

it('reports complete when there are no observations at all (empty is a warning, not a pass-with-silence)', function () {
    $this->artisan('authz:observations --unclassified')->assertSuccessful();
    $this->artisan('authz:observations')
        ->expectsOutputToContain('No observations recorded')
        ->assertSuccessful();
});

it('keeps the single-axis aggregation working (existing behaviour unchanged)', function () {
    plantObservation();
    plantObservation(['ability' => 'guardian.create']);

    $this->artisan('authz:observations --by=ability --json')
        ->assertSuccessful()
        ->expectsOutputToContain('guardian.create');
});
