<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * The observe-mode evidence store is bounded (ADR 0043 §4): authz:prune keeps it
 * from growing unattended and truncates it at rollout teardown.
 */
uses(RefreshDatabase::class);

function seedObservation(string $occurredAt): void
{
    DB::table('authz_observations')->insert([
        'ability' => 'guardian.view',
        'check_type' => 'permission',
        'controller_action' => 'GuardianController@students',
        'transport' => 'http',
        'occurred_at' => $occurredAt,
    ]);
}

it('prunes observations older than the retention window and keeps recent ones', function () {
    seedObservation(now()->subDays(45)->toDateTimeString()); // stale
    seedObservation(now()->subDays(10)->toDateTimeString()); // recent

    $this->artisan('authz:prune', ['--older-than' => 30])->assertSuccessful();

    expect(DB::table('authz_observations')->count())->toBe(1);
});

it('dry-run reports without deleting', function () {
    seedObservation(now()->subDays(45)->toDateTimeString());

    $this->artisan('authz:prune', ['--older-than' => 30, '--dry-run' => true])->assertSuccessful();

    expect(DB::table('authz_observations')->count())->toBe(1);
});

it('--all truncates the whole store', function () {
    seedObservation(now()->subDay()->toDateTimeString());
    seedObservation(now()->toDateTimeString());

    $this->artisan('authz:prune', ['--all' => true])->assertSuccessful();

    expect(DB::table('authz_observations')->count())->toBe(0);
});
