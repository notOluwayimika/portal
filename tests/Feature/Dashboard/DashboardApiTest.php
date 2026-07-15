<?php

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['admin', 'head_of_school', 'teacher', 'guardian'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

function dashboardAdmin(): array
{
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('admin');
    return [$school, $user];
}

test('unauthenticated users are redirected from dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('admin can access dashboard and receives analysis props', function () {
    [$school, $user] = dashboardAdmin();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) =>
        $page->component('dashboard')
             ->has('analysis')
             ->has('widgets')
             ->has('onboarding')
    );
});

test('dashboard analysis is scoped to the logged-in user school', function () {
    [$schoolA, $userA] = dashboardAdmin();
    [$schoolB, $userB] = dashboardAdmin();

    $responseA = $this->actingAs($userA)->get(route('dashboard'));
    $responseA->assertOk();
    $responseA->assertInertia(fn ($page) =>
        $page->where('analysis.school_id', fn ($id) => $id !== (string) $schoolB->uuid)
    );
});

test('refresh endpoint requires authentication', function () {
    $this->post(route('dashboard.refresh'))->assertRedirect(route('login'));
});

test('refresh endpoint returns success for authenticated admin', function () {
    [$school, $user] = dashboardAdmin();

    $response = $this->actingAs($user)->post(route('dashboard.refresh'));

    $response->assertOk();
    $response->assertJson(['success' => true]);
    $response->assertJsonStructure(['success', 'analyzed_at']);
});

test('refresh endpoint is rate-limited to 1 per minute', function () {
    [$school, $user] = dashboardAdmin();

    $this->actingAs($user)->post(route('dashboard.refresh'))->assertOk();
    $this->actingAs($user)->post(route('dashboard.refresh'))->assertStatus(429);
});

test('onboarding endpoint returns step structure', function () {
    [$school, $user] = dashboardAdmin();

    $response = $this->actingAs($user)->get(route('dashboard.onboarding'));

    $response->assertOk();
    $response->assertJsonStructure([
        'is_onboarding',
        'steps' => [['key', 'title', 'description', 'is_complete', 'action_label', 'action_href']],
        'completed_count',
        'total_count',
    ]);
});

test('teacher can access dashboard', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('teacher');

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});
