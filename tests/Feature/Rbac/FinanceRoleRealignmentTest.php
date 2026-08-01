<?php

// Finance seat realignment (2026-08-01): the roles now map to Brookstone's five seats, and no role holds
// both sides of a maker-checker pair. head_of_school gained the fee-schedule/discount APPROVE side; the
// guard must refuse the half-done state where the SUBMIT side is still present.

use App\Exceptions\DutySeparationViolationException;
use App\Models\Role;
use App\Models\School;
use App\Support\DutySeparation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('the guard REFUSES the half-done HoS set (approve added, submit still present)', function () {
    $school = School::factory()->create();

    // The realigned head_of_school grants + the fee-schedule SUBMIT re-added — the exact both-sides state a
    // half-applied grant change would leave. assertRoleSetAllowed evaluates the combined role abilities.
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'hos_halfdone', 'guard_name' => 'web'])->syncPermissions([
        'finance.access',
        'finance.fee-schedule.change.approve', 'finance.fee-schedule.change.reject',
        'finance.discount-policy.change.approve', 'finance.discount-policy.change.reject',
        'finance.fee-schedule.change.submit', // STILL PRESENT — the defect the guard must catch
    ]);
    setPermissionsTeamId(null);

    try {
        DutySeparation::assertRoleSetAllowed('hos@x', $school->id, ['hos_halfdone']);
        throw new RuntimeException('expected DutySeparationViolationException, none thrown');
    } catch (DutySeparationViolationException $e) {
        expect($e->getMessage())->toContain('fee-schedule.change'); // names the offending pair
    }
});

it('the REAL realigned HoS set (submit removed, approve only) passes', function () {
    $school = School::factory()->create();

    expect(fn () => DutySeparation::assertRoleSetAllowed('hos@x', $school->id, ['head_of_school']))
        ->not->toThrow(DutySeparationViolationException::class);
});

it('NO seeded role holds both sides of any maker-checker pair', function () {
    $pairs = DutySeparation::pairs();
    $bad = [];

    foreach (Role::with('permissions')->where('guard_name', 'web')->get() as $role) {
        $abilities = $role->permissions->pluck('name');
        foreach ($pairs as $pair) {
            if ($abilities->contains($pair['checker']) && $abilities->contains($pair['maker'])) {
                $bad[] = "{$role->name} holds both {$pair['checker']} and {$pair['maker']}";
            }
        }
    }

    expect($bad)->toBe([]);
});

it('the role set matches the realigned seats — old roles gone, new roles present', function () {
    $names = Role::where('guard_name', 'web')->pluck('name');

    expect($names)->not->toContain('finance_director')
        ->and($names)->not->toContain('finance_void_approver')
        ->and($names)->toContain('accounts_supervisor')
        ->and($names)->toContain('finance_lead')
        ->and($names)->toContain('internal_auditor')
        ->and($names)->toContain('accounts_officer');
});
