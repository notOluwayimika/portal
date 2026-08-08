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
        ->and($names)->toContain('accounts_officer')
        ->and($names)->toContain('executive_director');
});

it('ED holds every finance checker side and NO submit; HoS holds no finance at all; AS is maker+viewer', function () {
    // The 2026-08-04 seat move, pinned on the SEEDED map rather than on the migration — the migration
    // converges an already-seeded database, this is what a fresh seed produces, and both must agree or
    // the drift is back. Reverting the grantsMap() edit turns this arm red on all three roles.
    //
    // The `no submit` half is the load-bearing one. Four maker-checker pairs terminate on ED, so a
    // single stray submit grant makes it a both-sides holder — the seat that approves everything is
    // exactly the seat that must propose nothing.
    // Resolve without firstOrFail: under a reverted map `executive_director` does not exist, and a
    // ModelNotFoundException is a red that names nothing. Assert the row, then read it.
    $finance = function (string $role): array {
        $row = Role::with('permissions')
            ->where('name', $role)->where('guard_name', 'web')->whereNull('school_id')->first();

        expect($row)->not->toBeNull("global role [{$role}] is missing from the seeded map");

        return $row->permissions->pluck('name')
            ->filter(fn (string $p): bool => str_starts_with($p, 'finance.'))
            ->sort()->values()->all();
    };

    expect($finance('executive_director'))->toBe([
        'finance.access',
        'finance.credit-note.approve',
        'finance.credit-note.reject',
        'finance.discount-policy.change.approve',
        'finance.discount-policy.change.reject',
        'finance.fee-schedule.change.approve',
        'finance.fee-schedule.change.reject',
        'finance.invoice.void-request.approve',
        'finance.invoice.void-request.reject',
        // §9 step 4c, the FIFTH pair. Added to this exact list because ED genuinely holds it, not to
        // make a red go away: the 2026-08-04 decision moved every finance checker side to this seat,
        // so a checker ability created afterwards belongs here by the same rule. The `no submit`
        // assertion below is unaffected and is what stops this array from being edited into a lie.
        'finance.opening-balance.approve',
        'finance.opening-balance.reject',
    ]);

    // Stated separately from the exact list above: an equality assertion silently stops being about
    // submits the moment someone edits the expected array, and this is the property that matters.
    expect(collect($finance('executive_director'))->filter(fn (string $p): bool => str_ends_with($p, '.submit')))
        ->toBeEmpty();

    // head_of_school lost ALL finance — not a subset, not "the approve sides". `principal` keeping
    // finance.access is deliberate (2026-08-04) and is asserted below so the survival is not read as a
    // leak by whoever greps for who can still see finance.
    expect($finance('head_of_school'))->toBe([]);
    expect($finance('principal'))->toBe(['finance.access']);

    // accounts_supervisor is now a maker-and-viewer seat: it approves nothing that is built. §9 step
    // 4c adds a SECOND maker to it and nothing else — the seat's character is unchanged, which is the
    // claim this list is really making.
    expect($finance('accounts_supervisor'))->toBe([
        'finance.access',
        'finance.fee-schedule.change.submit',
        'finance.opening-balance.submit',
    ]);

    // The same property, stated so it survives an edit to the array above: AS approves NOTHING.
    expect(collect($finance('accounts_supervisor'))->filter(
        fn (string $p): bool => str_ends_with($p, '.approve') || str_ends_with($p, '.reject')
    ))->toBeEmpty();
});
