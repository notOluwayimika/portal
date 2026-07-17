<?php

use App\Models\Guardian;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Create a School row. The project has no SchoolFactory, and School only
 * needs name + slug (uuid is auto-generated in the model's booted hook).
 */
function al_makeSchool(): School
{
    return School::create([
        'name' => 'Test School '.Str::random(6),
        'slug' => (string) Str::uuid(),
    ]);
}

/**
 * Create a User row directly. The bundled UserFactory is out of sync with
 * the schema (it inserts a `name` column that doesn't exist), so tests
 * build users from the real columns instead.
 */
function al_makeUser(int|string $schoolId): User
{
    return User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'Test',
        'last_name' => 'User '.Str::random(5),
        'email' => Str::uuid().'@example.test',
        'password' => bcrypt('password'),
        'school_id' => $schoolId,
    ]);
}

function al_makeGuardian(int|string $schoolId, int|string $userId): Guardian
{
    return Guardian::forceCreate([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolId,
        'user_id' => $userId,
        'first_name' => 'Guardian',
        'last_name' => 'Test',
        'phone' => '0800'.random_int(1000000, 9999999),
        'status' => 'active',
    ]);
}

/*
|--------------------------------------------------------------------------
| Authentication scenario helpers
|--------------------------------------------------------------------------
|
| Express the supported multi-School authentication scenarios (§6.5, §7.1)
| instead of hand-assembling RBAC state in every test. School access is granted
| through the real path (grantSchoolAccess = school_user pivot + per-team role),
| so these hold under both the legacy union and the single-source path.
*/

/** A user with exactly one accessible School. */
function singleSchoolUser(array $attributes = []): User
{
    $school = al_makeSchool();
    $user = User::factory()->create(array_merge(['school_id' => $school->id], $attributes));
    $user->grantSchoolAccess($school, 'admin');

    return $user;
}

/** A user with several accessible Schools and no default context (must pick one). */
function multiSchoolUser(int $schools = 2, array $attributes = []): User
{
    $user = User::factory()->create($attributes); // no school_id: access via grants only
    foreach (range(1, $schools) as $ignored) {
        $user->grantSchoolAccess(al_makeSchool(), 'admin');
    }

    return $user;
}

/** A platform super admin (global context, no School). */
function superAdminUser(array $attributes = []): User
{
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create($attributes);
    setPermissionsTeamId(null);
    $user->assignRole('super_admin');
    $user->flushSchoolAccessCache();

    return $user;
}

/** A user with zero accessible Schools (no pivot, no role, no school_id). */
function userWithNoAccessibleSchools(array $attributes = []): User
{
    return User::factory()->create(array_merge(['school_id' => null], $attributes));
}
