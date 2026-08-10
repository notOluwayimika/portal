<?php

// f293358 finish — the three nested/nested-ish currency fields that still built a Money from unvalidated
// input now mirror Money's ^[A-Z]{3}$ invariant at the edge. Two 500'd before a write (#1 generate, #2 fee
// schedule); the third PERSISTED silently because value_currency is not cast through Money, so its proof
// asserts the row was NOT created, not merely a 422.

use App\Enums\TermStatusEnum;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function ncvUser(School $s, string $role): User
{
    $u = User::factory()->create(['school_id' => $s->id]);
    $u->grantSchoolAccess($s, $role);
    $u->flushSchoolAccessCache();

    return $u;
}

function ncvEnrollment(School $s): string
{
    return ActiveSchool::runFor($s->id, fn () => StudentCurriculum::create([
        'student_id' => Student::factory()->create(['school_id' => $s->id])->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $s->id])->id, 'status' => 'active',
    ]))->uuid;
}

/** @return array{0: Term, 1: ClassLevel} */
function ncvTermLevel(School $s): array
{
    $sess = AcademicSession::create(['school_id' => $s->id, 'name' => '2026/2027', 'slug' => 'x'.Str::random(6), 'is_current' => true]);
    $term = Term::create(['academic_session_id' => $sess->id, 'school_id' => $s->id, 'name' => 'T1', 'slug' => 't'.Str::random(6), 'order' => 1, 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value]);
    $lvl = ClassLevel::create(['school_id' => $s->id, 'name' => 'JSS1', 'order' => 1]);

    return [$term, $lvl];
}

// ── #1 GenerateInvoice lines.*.currency ──

it('generate: a line currency "ngn" is a 422 naming the field; "NGN" succeeds', function () {
    $s = School::factory()->create();
    $u = ncvUser($s, 'accounts_officer');
    $enr = ncvEnrollment($s);

    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $enr,
        'lines' => [['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000, 'currency' => 'ngn']],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.currency']); // PLANT: drop regex → 500.

    // D-4: valid NGN still bills.
    $enr2 = ncvEnrollment($s);
    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $enr2,
        'lines' => [['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000, 'currency' => 'NGN']],
    ])->assertCreated();
});

// ── #2 FeeSchedule items.*.currency ──

it('fee schedule: an item currency "ngn" is a 422 naming the field; "NGN" succeeds', function () {
    $s = School::factory()->create();
    $u = ncvUser($s, 'accounts_officer');
    [$term, $lvl] = ncvTermLevel($s);

    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson('/api/v1/finance/fee-schedules', [
        'term_id' => $term->id, 'class_level_id' => $lvl->id, 'label' => 'L',
        'items' => [['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000, 'currency' => 'ngn']],
    ])->assertStatus(422)->assertJsonValidationErrors(['items.0.currency']); // PLANT: drop regex → 500.

    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson('/api/v1/finance/fee-schedules', [
        'term_id' => $term->id, 'class_level_id' => $lvl->id, 'label' => 'L2',
        'items' => [['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000, 'currency' => 'NGN']],
    ])->assertCreated();
});

// ── #3 Discount value_currency — the silent one: assert NO ROW is created ──

it('discount change: value_currency "ngn" is a 422 AND persists nothing (the silent bug)', function () {
    $s = School::factory()->create();
    $u = ncvUser($s, 'accounts_officer');

    $before = DB::table('finance_discount_policy_changes')->count();
    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson('/api/v1/finance/discount-policy-changes', [
        'kind' => 'create', 'name' => 'Sib', 'basis' => 'amount', 'value_minor' => 5000, 'value_currency' => 'ngn',
        'requires_approval' => false, 'reason' => 'probe',
    ])->assertStatus(422)->assertJsonValidationErrors(['value_currency']); // PLANT: drop regex → 201, row persists 'ngn'.

    // The watched-red for a bug that never throws: the row must NOT exist.
    expect(DB::table('finance_discount_policy_changes')->count())->toBe($before);
});

it('discount change: valid "NGN" amount submits; and basis=percent needs no value_currency (prohibited_if intact)', function () {
    $s = School::factory()->create();
    $u = ncvUser($s, 'accounts_officer');

    // D-4a: valid amount currency submits.
    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson('/api/v1/finance/discount-policy-changes', [
        'kind' => 'create', 'name' => 'AmtOk', 'basis' => 'amount', 'value_minor' => 5000, 'value_currency' => 'NGN',
        'requires_approval' => false, 'reason' => 'ok',
    ])->assertCreated();

    // D-4b: the regex must not resurrect a field prohibited_if keeps absent — percent with NO value_currency submits.
    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson('/api/v1/finance/discount-policy-changes', [
        'kind' => 'create', 'name' => 'PctOk', 'basis' => 'percent', 'percent' => 10,
        'requires_approval' => false, 'reason' => 'ok',
    ])->assertCreated();
});
