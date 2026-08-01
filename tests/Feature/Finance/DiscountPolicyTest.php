<?php

use App\Exceptions\DutySeparationViolationException;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\DiscountPolicyChange;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\DutySeparation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Finder\Finder;

/**
 * S1 commit 3a — discount-policy GOVERNANCE (axis A). Proofs 1–10, 19 (policies + changes only). The
 * catalog changes only on approval; maker ≠ checker is proven at both the Policy (403) and DB CHECK
 * layers. Axis B (the reduction line trigger) is NOT here — it ships in 3b with its migration.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** A user holding the seeded accounts_officer role (finance.discount-policy.change.submit — the MAKER). */
function dpMaker(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'accounts_officer');
    $user->flushSchoolAccessCache();

    return $user;
}

/** A user holding the seeded head_of_school role (change.approve + change.reject — the CHECKER/ED). */
function dpChecker(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'head_of_school');
    $user->flushSchoolAccessCache();

    return $user;
}

/**
 * A user holding a role with BOTH change.submit and change.approve, assigned via a RAW model_has_roles
 * insert — grant-time SoD refuses this through the spatie API (it is a Finance pair), so the only way to
 * construct a both-sides user is outside it. Used to prove the RECORD-level Policy refusal in isolation.
 */
function dpBothHolder(School $school): User
{
    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'dp_both', 'guard_name' => 'web']);
    foreach (['finance.access', 'finance.discount-policy.change.submit', 'finance.discount-policy.change.approve'] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $role->syncPermissions(['finance.access', 'finance.discount-policy.change.submit', 'finance.discount-policy.change.approve']);
    setPermissionsTeamId(null);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->schools()->syncWithoutDetaching([$school->id]);
    DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => User::class, 'model_id' => $user->id, 'school_id' => $school->id]);
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** POST a create change (percent policy) as $user; returns the TestResponse. */
function dpCreate($test, User $user, School $school, string $name, bool $requiresApproval = false)
{
    return $test->actingAs($user)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/discount-policy-changes', [
            'kind' => 'create', 'name' => $name, 'basis' => 'percent', 'percent' => 10,
            'requires_approval' => $requiresApproval, 'reason' => 'because',
        ]);
}

/** A directly-created active policy (arranges catalog state without the approval flow). */
function dpActivePolicy(School $school, string $name): DiscountPolicy
{
    return ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create([
        'school_id' => $school->id, 'name' => $name, 'basis' => 'percent', 'percent' => 10,
        'requires_approval' => false, 'status' => 'active',
    ]));
}

// ── Proof 1 — a submitted change does nothing ──────────────────────────────

it('proof 1 — a submitted change does NOT touch the catalog', function () {
    $school = School::factory()->create();
    $maker = dpMaker($school);

    dpCreate($this, $maker, $school, 'Sibling 10%')->assertCreated()->assertJsonPath('status', 'submitted');

    expect(DiscountPolicy::count())->toBe(0); // PLANT: move the insert into Submit… → 1.
});

// ── Proof 2 — the submitter cannot approve their own change (both layers) ───

it('proof 2 (Policy) — the submitter is refused approval of their own change (403)', function () {
    $school = School::factory()->create();
    $both = dpBothHolder($school);

    $change = dpCreate($this, $both, $school, 'Self')->assertCreated()->json('id');
    // Holds change.approve (route passes) but IS the maker → DiscountPolicyChangePolicy denies → 403.
    $this->actingAs($both)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/discount-policy-changes/{$change}/approve")
        ->assertForbidden();
});

it('proof 2 (DB CHECK) — a raw write setting decided_by = submitted_by is refused by the CHECK', function () {
    $school = School::factory()->create();
    $maker = dpMaker($school);
    $change = DiscountPolicyChange::where('uuid', dpCreate($this, $maker, $school, 'Raw')->json('id'))->firstOrFail();

    expect(fn () => DB::table('finance_discount_policy_changes')->where('id', $change->id)
        ->update(['status' => 'approved', 'decided_by' => $change->submitted_by, 'decided_at' => now()]))
        ->toThrow(QueryException::class);
});

// ── Proof 3 — permission split ──────────────────────────────────────────────

it('proof 3 — a submit-only holder is 403 on approve; an approve-only holder is 403 on submit', function () {
    $school = School::factory()->create();
    $maker = dpMaker($school);        // change.submit only
    $checker = dpChecker($school);    // change.approve/reject only
    $change = dpCreate($this, $maker, $school, 'Split')->json('id');

    // maker (no approve) → the route permission gate 403s.
    $this->actingAs($maker)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/discount-policy-changes/{$change}/approve")->assertForbidden();
    // checker (no submit) → the submit route gate 403s.
    $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/discount-policy-changes', ['kind' => 'create', 'name' => 'X', 'basis' => 'percent', 'percent' => 5, 'reason' => 'r'])
        ->assertForbidden();
});

// ── Proof 4 — an approved policy's terms cannot be edited (update guard) ─────

it('proof 4 — an active policy\'s terms are immutable at the database', function () {
    $school = School::factory()->create();
    $policy = dpActivePolicy($school, 'Frozen');

    expect(fn () => DB::table('finance_discount_policies')->where('id', $policy->id)->update(['percent' => 50]))
        ->toThrow(QueryException::class); // PLANT: drop percent from the update-guard column list → passes.
    // status IS allowed to move.
    DB::table('finance_discount_policies')->where('id', $policy->id)->update(['status' => 'retired']);
    expect($policy->fresh()->status->value)->toBe('retired');
});

// ── Proof 5 — amend supersedes, never mutates ───────────────────────────────

it('proof 5 — approving an amend supersedes the original and inserts a new active row linked back', function () {
    $school = School::factory()->create();
    $maker = dpMaker($school);
    $checker = dpChecker($school);

    // Create + approve v1.
    $c1 = dpCreate($this, $maker, $school, 'Bursary')->json('id');
    $this->actingAs($checker)->withSession(['school_id' => $school->id])->postJson("/api/v1/finance/discount-policy-changes/{$c1}/approve")->assertOk();
    $v1 = DiscountPolicy::where('name', 'Bursary')->firstOrFail();

    // Amend v1 (new percent) → approve.
    $c2 = $this->actingAs($maker)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/discount-policy-changes', ['kind' => 'amend', 'target' => $v1->uuid, 'name' => 'Bursary', 'basis' => 'percent', 'percent' => 25, 'reason' => 'raise'])
        ->assertCreated()->json('id');
    $this->actingAs($checker)->withSession(['school_id' => $school->id])->postJson("/api/v1/finance/discount-policy-changes/{$c2}/approve")->assertOk();

    $v2 = DiscountPolicy::where('name', 'Bursary')->where('status', 'active')->firstOrFail();
    expect($v1->fresh()->status->value)->toBe('superseded')     // original never mutated, only status
        ->and((int) $v1->fresh()->percent)->toBe(10)            // its terms are exactly what was approved
        ->and((int) $v2->percent)->toBe(25)
        ->and($v2->supersedes_policy_id)->toBe($v1->id);
});

// ── Proof 6 — retire needs approval; only approval flips it ─────────────────

it('proof 6/7 — submitting a retire leaves the policy active; only approval retires it', function () {
    $school = School::factory()->create();
    $maker = dpMaker($school);
    $checker = dpChecker($school);
    $policy = dpActivePolicy($school, 'ToRetire');

    $c = $this->actingAs($maker)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/discount-policy-changes', ['kind' => 'retire', 'target' => $policy->uuid, 'reason' => 'sunset'])
        ->assertCreated()->json('id');
    expect($policy->fresh()->status->value)->toBe('active'); // PLANT: flip status in Submit… → retired.

    $this->actingAs($checker)->withSession(['school_id' => $school->id])->postJson("/api/v1/finance/discount-policy-changes/{$c}/approve")->assertOk();
    expect($policy->fresh()->status->value)->toBe('retired');
});

// ── Proof 8 — approval is atomic ────────────────────────────────────────────

it('proof 8 — a failing amend leaves NOTHING moved (original active, change submitted)', function () {
    $school = School::factory()->create();
    $maker = dpMaker($school);
    $checker = dpChecker($school);
    $a = dpActivePolicy($school, 'Alpha');
    dpActivePolicy($school, 'Beta'); // occupies the active name "Beta"

    // Amend Alpha → rename to "Beta" (collides with Beta's active-name unique). The amend supersedes
    // Alpha then inserts → the insert throws 1062 → the whole transaction rolls back.
    $c = $this->actingAs($maker)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/discount-policy-changes', ['kind' => 'amend', 'target' => $a->uuid, 'name' => 'Beta', 'basis' => 'percent', 'percent' => 15, 'reason' => 'clash'])
        ->assertCreated()->json('id');
    $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/discount-policy-changes/{$c}/approve")->assertStatus(422);

    expect($a->fresh()->status->value)->toBe('active')                                  // NOT superseded
        ->and(DiscountPolicyChange::where('uuid', $c)->firstOrFail()->status->value)->toBe('submitted'); // NOT approved
});

// ── Proof 9 — one open request per policy ───────────────────────────────────

it('proof 9 — two open amend requests against one policy collide; a fresh submit works after rejection', function () {
    $school = School::factory()->create();
    $maker = dpMaker($school);
    $checker = dpChecker($school);
    $policy = dpActivePolicy($school, 'OnePer');

    $amend = fn () => $this->actingAs($maker)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/discount-policy-changes', ['kind' => 'amend', 'target' => $policy->uuid, 'name' => 'OnePer', 'basis' => 'percent', 'percent' => 20, 'reason' => 'r']);

    $first = $amend()->assertCreated()->json('id');
    $amend()->assertStatus(422); // second open request refused (friendly pre-check / open_unique)

    // Reject the first; a fresh submit then succeeds.
    $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/discount-policy-changes/{$first}/reject", ['reason' => 'no'])->assertOk();
    $amend()->assertCreated();
});

// ── Proof 10 — duplicate active name is a friendly 422, not a 500 ───────────

it('proof 10 — two creates of one name both submit; the SECOND approval is a friendly 422, not a 500', function () {
    $school = School::factory()->create();
    $maker = dpMaker($school);
    $checker = dpChecker($school);

    $c1 = dpCreate($this, $maker, $school, 'Dup')->assertCreated()->json('id');
    $c2 = dpCreate($this, $maker, $school, 'Dup')->assertCreated()->json('id'); // both submit (null target, no open_key)

    $this->actingAs($checker)->withSession(['school_id' => $school->id])->postJson("/api/v1/finance/discount-policy-changes/{$c1}/approve")->assertOk();
    $this->actingAs($checker)->withSession(['school_id' => $school->id])->postJson("/api/v1/finance/discount-policy-changes/{$c2}/approve")
        ->assertStatus(422); // PLANT: remove the 1062 translation → 500.
});

// ── Proof 19 — School isolation (policies + changes only) ───────────────────

it('proof 19 — discount policies and changes are School-scoped', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $a = dpActivePolicy($schoolA, 'A-only');
    $b = dpActivePolicy($schoolB, 'B-only');
    dpCreate($this, dpMaker($schoolA), $schoolA, 'A-change');
    dpCreate($this, dpMaker($schoolB), $schoolB, 'B-change');

    ActiveSchool::runFor($schoolA->id, fn () => expect(DiscountPolicy::pluck('id')->all())->toBe([$a->id])
        ->and(DiscountPolicyChange::pluck('school_id')->unique()->values()->all())->toBe([$schoolA->id]));
    ActiveSchool::runFor($schoolB->id, fn () => expect(DiscountPolicy::pluck('id')->all())->toBe([$b->id]));
});

// ── Convention (§4.2): the permission names wire into the shared machinery ──

it('convention (a) — DutySeparation::pairs() derives the new change.submit/approve + submit/reject pairs', function () {
    $checkers = collect(DutySeparation::pairs())->pluck('checker')->all();
    expect($checkers)->toContain('finance.discount-policy.change.approve')
        ->and($checkers)->toContain('finance.discount-policy.change.reject');
    expect(collect(DutySeparation::pairs())->firstWhere('checker', 'finance.discount-policy.change.approve')['maker'])
        ->toBe('finance.discount-policy.change.submit');
});

it('convention (b) — grant-time SoD refuses a role granted both sides of the change pair', function () {
    $school = School::factory()->create();
    $user = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'both_sides', 'guard_name' => 'web'])
        ->syncPermissions(['finance.discount-policy.change.submit', 'finance.discount-policy.change.approve']);

    expect(fn () => $user->assignRole('both_sides'))
        ->toThrow(DutySeparationViolationException::class);
    setPermissionsTeamId(null);
});

it('convention (c) — a user holding only change.approve reaches the /finance/approvals page', function () {
    $school = School::factory()->create();
    $checker = dpChecker($school); // principal: change.approve/reject

    $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->get('/finance/approvals')->assertOk();
});

// ── Arch (§4.1) — the catalog is written in exactly one place ────────────────

it('arch — finance_discount_policies (DiscountPolicy::create) is written ONLY by ApproveDiscountPolicyChange', function () {
    $writers = collect(Finder::create()->files()->in(app_path())->name('*.php'))
        ->filter(fn ($f) => str_contains($f->getContents(), 'DiscountPolicy::create('))
        ->map(fn ($f) => $f->getFilename())
        ->values()->all();

    // The invariant that makes 2.1 real: the catalog changes in one auditable place. If this grows,
    // the ED-approves-a-set-of-terms guarantee is no longer a single-writer fact.
    expect($writers)->toBe(['ApproveDiscountPolicyChange.php']);
});
