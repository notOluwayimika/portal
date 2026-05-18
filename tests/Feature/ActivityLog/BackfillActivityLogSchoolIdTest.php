<?php

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function insertActivity(array $overrides = []): int
{
    return DB::table('activity_log')->insertGetId(array_merge([
        'log_name'    => 'test',
        'description' => 'legacy row',
        'event'       => 'created',
        'school_id'   => null,
        'created_at'  => now(),
        'updated_at'  => now(),
    ], $overrides));
}

it('backfills school_id from the causer in chunks', function () {
    $school = al_makeSchool();
    $user   = al_makeUser($school->id);

    $ids = collect(range(1, 5))->map(fn () => insertActivity([
        'causer_type' => User::class,
        'causer_id'   => $user->id,
    ]));

    $this->artisan('activity-log:backfill-school-id', ['--chunk-size' => 2])
        ->assertSuccessful();

    foreach ($ids as $id) {
        expect(DB::table('activity_log')->find($id)->school_id)->toEqual($school->id);
    }
});

it('does not write changes on --dry-run', function () {
    $school = al_makeSchool();
    $user   = al_makeUser($school->id);
    $id     = insertActivity(['causer_type' => User::class, 'causer_id' => $user->id]);

    $this->artisan('activity-log:backfill-school-id', ['--dry-run' => true])
        ->assertSuccessful();

    expect(DB::table('activity_log')->find($id)->school_id)->toBeNull();
});

it('limits scope with --since', function () {
    $school = al_makeSchool();
    $user   = al_makeUser($school->id);

    $oldId = insertActivity([
        'causer_type' => User::class,
        'causer_id'   => $user->id,
        'created_at'  => now()->subDays(10),
    ]);
    $newId = insertActivity([
        'causer_type' => User::class,
        'causer_id'   => $user->id,
        'created_at'  => now(),
    ]);

    $this->artisan('activity-log:backfill-school-id', [
        '--since' => now()->subDay()->format('Y-m-d'),
    ])->assertSuccessful();

    expect(DB::table('activity_log')->find($oldId)->school_id)->toBeNull();
    expect(DB::table('activity_log')->find($newId)->school_id)->toEqual($school->id);
});
