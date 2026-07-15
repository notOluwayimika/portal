<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\Permission;
use Database\Seeders\ArmsDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the previously-unseeded guardian and student-subject permissions', function () {
    $this->seed(ArmsDatabaseSeeder::class);

    expect(Permission::where('name', 'guardian.view')->exists())->toBeTrue();
    expect(Permission::where('name', 'student_subject.view')->exists())->toBeTrue();
});

it('requires authentication for shared read endpoints (public leak closed)', function () {
    $this->getJson('/api/sessions')->assertStatus(401);
    $this->getJson('/api/curricula')->assertStatus(401);
    $this->getJson('/api/subjects')->assertStatus(401);
    // Previously-unique public routes that were moved into the auth group:
    $this->getJson('/api/curricula/active')->assertStatus(401);
    $this->getJson('/api/class-level-arms')->assertStatus(401);
});

it('removes the public /curricula/queued route', function () {
    // The dedicated public queued-curricula endpoint is gone; the path now falls
    // through to the authenticated /curricula/{curriculum} route, so it is no
    // longer reachable without auth (was previously public).
    $this->getJson('/api/curricula/queued')->assertStatus(401);
});

it('fails closed on the disabled public self-registration path', function () {
    expect(fn () => app(CreateNewUser::class)->create([]))
        ->toThrow(RuntimeException::class);
});
