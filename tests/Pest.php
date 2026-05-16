<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
function al_makeSchool(): \App\Models\School
{
    return \App\Models\School::create([
        'name' => 'Test School ' . \Illuminate\Support\Str::random(6),
        'slug' => (string) \Illuminate\Support\Str::uuid(),
    ]);
}

/**
 * Create a User row directly. The bundled UserFactory is out of sync with
 * the schema (it inserts a `name` column that doesn't exist), so tests
 * build users from the real columns instead.
 */
function al_makeUser(int|string $schoolId): \App\Models\User
{
    return \App\Models\User::forceCreate([
        'uuid'       => (string) \Illuminate\Support\Str::uuid(),
        'first_name' => 'Test',
        'last_name'  => 'User ' . \Illuminate\Support\Str::random(5),
        'email'      => \Illuminate\Support\Str::uuid() . '@example.test',
        'password'   => bcrypt('password'),
        'school_id'  => $schoolId,
    ]);
}

function al_makeGuardian(int|string $schoolId, int|string $userId): \App\Models\Guardian
{
    return \App\Models\Guardian::forceCreate([
        'uuid'       => (string) \Illuminate\Support\Str::uuid(),
        'school_id'  => $schoolId,
        'user_id'    => $userId,
        'first_name' => 'Guardian',
        'last_name'  => 'Test',
        'phone'      => '0800' . random_int(1000000, 9999999),
        'status'     => 'active',
    ]);
}
