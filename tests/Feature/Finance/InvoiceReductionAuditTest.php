<?php

use App\Finance\Models\DiscountPolicy;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * S1 Part 0 — the reduction-audit hole, closed and bite-proven. Until now the ONLY gate on invoice
 * generation was the group's `finance.access` (the same permission that lets someone VIEW finance),
 * so anyone who could look at the page could raise an invoice with a 100%-discount line and leave no
 * record of who they were — while forgiving ₦1 AFTER issuance takes two named people (credit note).
 * This closes it three ways: every line records `created_by_user_id`; raising an invoice needs
 * `finance.invoice.generate`; applying any reduction needs `finance.invoice.reduction.apply` on top.
 *
 * Plants data, not schema, so RefreshDatabase's single-transaction wrap is not a hazard here.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** A user in $school holding EXACTLY $permissions via a dedicated role — precise permission control. */
function iraUser(School $school, array $permissions): User
{
    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'ira_'.substr(md5(implode(',', $permissions)), 0, 10), 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role->name);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A fresh student with an active enrollment; returns the enrollment uuid. */
function iraEnrollment(School $school): string
{
    $student = Student::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]))->uuid;
}

/**
 * An active, non-approval-requiring discount policy — the ONLY thing that lets a reduction line reach the
 * insert (S1 3b's DB reduction_guard). Both audit proofs below cite one so that the trigger is satisfied
 * and the ONLY remaining barrier is the PERMISSION they were written to test — which is the whole point of
 * 13b: from 3b the trigger refuses a policy-less reduction REGARDLESS of permission, so a proof that still
 * posts a policy-less line would pass on the trigger, not the permission, and its plant would stop biting.
 */
function iraPolicy(School $school): DiscountPolicy
{
    return ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create([
        'school_id' => $school->id, 'name' => 'Sibling', 'basis' => 'percent', 'percent' => 10,
        'requires_approval' => false, 'status' => 'active',
    ]));
}

// ── §0.4.1 The actor is recorded on every line ──────────────────────────────

it('records the acting user on every invoice line (the audit hole closed)', function () {
    $school = School::factory()->create();
    $user = iraUser($school, ['finance.access', 'finance.invoice.generate', 'finance.invoice.reduction.apply']);
    $enrollment = iraEnrollment($school);
    $policy = iraPolicy($school); // 3b: the discount line must cite an active policy or the trigger refuses it.

    $this->actingAs($user)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment,
            'lines' => [
                ['description' => 'Tuition', 'amount_minor' => 100000],
                ['description' => 'Sibling discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
            ],
        ])->assertCreated();

    $lines = DB::table('finance_invoice_lines')->get();
    expect($lines)->toHaveCount(2);
    $lines->each(fn ($line) => expect((int) $line->created_by_user_id)->toBe($user->id));
    // PLANT (re-run after the 3b rewrite): drop `'created_by_user_id' => $actorId` from GenerateInvoice's
    // lines()->create() → created_by_user_id is null → this assertion reds. Watched red again post-3b.
});

// ── §0.4.2 The reduction permission bites, BEFORE the transaction ────────────

it('refuses a reduction line without finance.invoice.reduction.apply — 403, and NOTHING is written', function () {
    $school = School::factory()->create();
    $user = iraUser($school, ['finance.access', 'finance.invoice.generate']); // NOT reduction.apply
    $enrollment = iraEnrollment($school);
    // 13b REWRITE: cite a REAL active policy, so the trigger is satisfied and the ONLY barrier between this
    // request and a 201 is the missing permission. Without this, from 3b the trigger would refuse the
    // policy-less line regardless of permission — the test would pass on the trigger, not the permission,
    // and deleting the can() check would no longer turn it red.
    $policy = iraPolicy($school);

    $this->actingAs($user)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment,
            'lines' => [
                ['description' => 'Tuition', 'amount_minor' => 100000],
                ['description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
            ],
        ])->assertStatus(403);

    // Refused before the Action's transaction — not a partial write.
    expect(DB::table('finance_invoices')->count())->toBe(0)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(0);
    // PLANT (re-run after the 3b rewrite): delete the assertMayReduce()/can() check → the request reaches the
    // Action, the cited policy is valid, so it 201s with rows → assertStatus(403) reds. Watched red again post-3b.
});

// ── §0.4.3 A charge-only invoice still passes — the guard is scoped ──────────

it('allows a CHARGE-ONLY invoice from the same generate-only user — scoped, not a blanket denial', function () {
    $school = School::factory()->create();
    $user = iraUser($school, ['finance.access', 'finance.invoice.generate']); // no reduction.apply
    $enrollment = iraEnrollment($school);

    $this->actingAs($user)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment,
            'lines' => [['description' => 'Tuition', 'amount_minor' => 100000]],
        ])->assertCreated();

    expect(DB::table('finance_invoices')->count())->toBe(1);
    // PLANT: make hasReductionLine() return true → this charge-only invoice trips the guard → 403.
});
