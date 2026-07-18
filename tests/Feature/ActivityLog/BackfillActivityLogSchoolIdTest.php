<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function insertActivity(array $overrides = []): int
{
    return DB::table('activity_log')->insertGetId(array_merge([
        'log_name' => 'test',
        'description' => 'legacy row',
        'event' => 'created',
        'school_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * The backfill was a completed one-time repair. Since 1.4c made activity_log
 * immutable (§15C, BEFORE UPDATE trigger), its write path can no longer run —
 * new rows are tagged at creation and the residual nulls are unresolvable. The
 * command therefore refuses to write when the log is locked, and only --dry-run
 * (which never updates) still works as a diagnostic. (In this test the lock is
 * always present — RefreshDatabase applies every migration.)
 */
it('refuses to write when the audit log is immutable (§15C) and changes nothing', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $id = insertActivity(['causer_type' => User::class, 'causer_id' => $user->id]);

    $this->artisan('activity-log:backfill-school-id')->assertExitCode(1);

    // Nothing was written — the immutable log is untouched.
    expect(DB::table('activity_log')->find($id)->school_id)->toBeNull();
});

it('does not write changes on --dry-run (diagnostic path still works under the lock)', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $id = insertActivity(['causer_type' => User::class, 'causer_id' => $user->id]);

    $this->artisan('activity-log:backfill-school-id', ['--dry-run' => true])
        ->assertSuccessful();

    expect(DB::table('activity_log')->find($id)->school_id)->toBeNull();
});
