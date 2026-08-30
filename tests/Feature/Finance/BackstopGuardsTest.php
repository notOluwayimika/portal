<?php

// Backstop-reachability audit — the two REACHABLE DB backstops now have an application guard in FRONT,
// so violating input is a 422 with a readable sentence, not the constraint's 500. B-3: 422 + the
// constraint is STILL present (a guard that silently replaced the backstop is worse than the bug).

use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Models\Curriculum;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function bgUser(School $school, string $role): User
{
    $u = User::factory()->create(['school_id' => $school->id]);
    $u->grantSchoolAccess($school, $role);
    $u->flushSchoolAccessCache();

    return $u;
}

/** A CHECK constraint is still present. */
function bgCheckPresent(string $name): bool
{
    return DB::selectOne(
        'SELECT 1 AS x FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
        [$name, 'CHECK'],
    ) !== null;
}

/** A trigger is still present. */
function bgTriggerPresent(string $name): bool
{
    return DB::selectOne(
        'SELECT 1 AS x FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
        [$name],
    ) !== null;
}

// ── Finding 1: discount terms_shape — guarded to 422; CHECK survives ──

it('discount change with basis=amount AND percent is a 422 (guard), and terms_shape still exists', function () {
    $school = School::factory()->create();
    $head = bgUser($school, 'accounts_officer');

    $this->actingAs($head)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/discount-policy-changes', [
            'kind' => 'create', 'name' => 'Bad', 'basis' => 'amount',
            'value_minor' => 100000, 'value_currency' => 'NGN',
            'percent' => 50, 'requires_approval' => false, 'reason' => 'probe',
        ])
        ->assertStatus(422); // PLANT: drop prohibited_if from the request → 500 (terms_shape 3819).

    expect(bgCheckPresent('finance_discount_policy_changes_terms_shape'))->toBeTrue();
});

it('the symmetric case basis=percent AND value_minor is also a 422', function () {
    $school = School::factory()->create();
    $head = bgUser($school, 'accounts_officer');

    $this->actingAs($head)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/discount-policy-changes', [
            'kind' => 'create', 'name' => 'Bad2', 'basis' => 'percent', 'percent' => 10,
            'value_minor' => 100000, // prohibited when basis=percent
            'requires_approval' => false, 'reason' => 'probe',
        ])
        ->assertStatus(422);
});

// ── Finding 2: credit-note currency — guarded to 422; insert_guard survives ──

it('credit note in a currency other than the invoice is a 422 (guard), and insert_guard still exists', function () {
    $school = School::factory()->create();
    $bursar = bgUser($school, 'accounts_officer');
    $student = Student::factory()->create(['school_id' => $school->id]);
    $invoice = ActiveSchool::runFor($school->id, function () use ($school, $student) {
        $enr = StudentCurriculum::create(['student_id' => $student->id, 'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id, 'status' => 'active']);

        return app(GenerateInvoice::class)->handle($enr->uuid, [new InvoiceLineSpec('Tuition', Money::fromKobo(100000), bankAccountId: testBankAccountId())], InvoiceKind::Scheduled);
    });

    $this->actingAs($bursar)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/credit-notes", ['amount_minor' => 1000, 'currency' => 'USD'])
        ->assertStatus(422); // PLANT: drop the currency check in SubmitCreditNote → 500 (insert_guard 1644).

    expect(bgTriggerPresent('finance_credit_notes_insert_guard'))->toBeTrue();
    // A same-currency note still works (the guard is not over-broad).
    $this->actingAs($bursar)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/credit-notes", ['amount_minor' => 1000, 'currency' => 'NGN'])
        ->assertCreated();
});

// ── Confirmed UNREACHABLE: guardian same-school stays a 4xx, never the SIGNAL 500 ──

it('a cross-school guardian pivot is a 404, never the same-school SIGNAL 500', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $admin = bgUser($schoolB, 'admin');
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);
    $guardianA = Guardian::factory()->create(['school_id' => $schoolA->id]);

    $this->actingAs($admin)->withSession(['school_id' => $schoolB->id])
        ->putJson("/api/students/{$studentB->uuid}/guardians/{$guardianA->uuid}", ['relationship' => 'uncle'])
        ->assertStatus(404);
});
