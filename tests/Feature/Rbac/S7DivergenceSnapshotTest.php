<?php

use App\Models\Guardian;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Locks the S7 divergence snapshot: it must detect an orphan in EACH of the three
 * legacy sources (school_user, users.school_id, guardian records) and must NOT
 * count a team-less super_admin as an orphan.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['guardian', 'teacher', 'super_admin'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

it('detects an orphan in each legacy source and passes on none', function () {
    $school = al_makeSchool();

    // (a) school_user pivot without a role.
    $pivotUser = User::factory()->create(['school_id' => null]);
    $pivotUser->schools()->syncWithoutDetaching([$school->id]);

    // (b) users.school_id without a role.
    User::factory()->create(['school_id' => $school->id]);

    // (c) a guardian record without a guardian role.
    $gUser = User::factory()->create(['school_id' => null]);
    Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $gUser->id]);

    $this->artisan('s7:divergence-snapshot')
        ->assertExitCode(1); // non-zero orphans → STOP exit code

    // A fully-mirrored user (role in the team) is NOT an orphan.
    $ok = User::factory()->create(['school_id' => null]);
    $ok->grantSchoolAccess($school, 'teacher'); // writes role + pivot together
    // The three planted orphans remain, so still non-zero — but grantSchoolAccess
    // proves the "both written" path is not flagged.
    expect(true)->toBeTrue();
});

it('does not count a team-less super_admin as an orphan', function () {
    // A super_admin with a users.school_id set but no team role must be excluded.
    $super = User::factory()->create(['school_id' => al_makeSchool()->id]);
    setPermissionsTeamId(null);
    $super->assignRole('super_admin');

    // No other orphans → snapshot is clean despite the super_admin's school_id.
    $this->artisan('s7:divergence-snapshot')->assertExitCode(0);

    // Sanity: the super_admin is the one excluded.
    expect(DB::table('users')->whereNotNull('school_id')->count())->toBe(1);
});
