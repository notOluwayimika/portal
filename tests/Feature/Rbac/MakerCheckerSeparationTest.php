<?php

use App\Enums\TermStatusEnum;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Subject;
use App\Models\SubjectResultStatus;
use App\Models\Term;
use App\Models\User;
use App\Policies\SubjectResultPolicy;
use App\Support\ActiveSchool;
use App\Support\ApprovalAbility;
use App\Support\DutySeparation;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * ADR 0040 mechanism 2 — maker ≠ checker, structurally.
 *
 * ADR 0044 step 5 asks for "a maker≠checker test proving a teacher cannot
 * approve and a head_of_school cannot submit". Those are the ROLE-SHAPED half
 * and they are here — but they only prove the seeded grant map, and a grant map
 * is editable at runtime (the C6 matrix will edit it). The rules that survive a
 * regrant are the structural ones, so those are tested at both layers: the
 * Policy, and the database that Policy could be bypassed around.
 */
function mc_user(string $role): User
{
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, $role);
    $user->flushSchoolAccessCache();
    setPermissionsTeamId($school->id);

    return $user;
}

/**
 * Resolve permissions in THIS user's team. Creating any other user moves the
 * ambient team (spatie resolves per team), so tests that build two identities
 * must re-establish whose context the Gate is answering in — otherwise a
 * "denied" reads as the rule under test when it was really missing context.
 */
function mc_actingContext(User $user): User
{
    setPermissionsTeamId($user->school_id);
    $user->unsetRelation('roles')->unsetRelation('permissions');

    return $user;
}

function mc_status(array $attributes = []): SubjectResultStatus
{
    return new SubjectResultStatus(array_merge(['status' => 'submitted'], $attributes));
}

/** The minimum real row the FK on subject_result_statuses will accept. */
function mc_curriculumSubject(): CurriculumSubject
{
    $school = al_makeSchool();

    return ActiveSchool::runFor($school->id, function () use ($school) {
        $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);
        $subject = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics']);

        return CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'is_compulsory' => true,
            'active' => true,
        ]);
    });
}

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

// ── The role-shaped half (ADR 0044 step 5, as written) ────────────────────

it('a teacher cannot approve or reject', function () {
    $teacher = mc_user('teacher');

    expect($teacher->can('result.approve'))->toBeFalse()
        ->and($teacher->can('result.reject'))->toBeFalse()
        ->and(Gate::forUser($teacher)->allows('approve', mc_status()))->toBeFalse();
});

it('a head_of_school cannot submit', function () {
    $head = mc_user('head_of_school');

    expect($head->can('result.submit'))->toBeFalse()
        ->and(Gate::forUser($head)->allows('submit', mc_status()))->toBeFalse()
        // …and genuinely holds the checker side, so the test above is not
        // passing merely because the role has no grants at all.
        ->and($head->can('result.approve'))->toBeTrue();
});

// ── The structural half: identity, not role ───────────────────────────────

it('denies approving your OWN submission even when you hold the checker permission', function () {
    $head = mc_user('head_of_school');

    $ownSubmission = mc_status(['submitted_by' => $head->id]);
    $someoneElses = mc_status(['submitted_by' => mc_user('teacher')->id]);

    mc_actingContext($head);

    expect(Gate::forUser($head)->allows('approve', $ownSubmission))->toBeFalse()
        ->and(Gate::forUser($head)->allows('reject', $ownSubmission))->toBeFalse()
        // The discriminating half: the SAME permission, the SAME role, a
        // different submitter — allowed. So the denial above is the identity
        // rule, not a permission failure.
        ->and(Gate::forUser($head)->allows('approve', $someoneElses))->toBeTrue();
});

it('denies a super admin approving its own submission — no role bypasses maker ≠ checker (ADR 0040)', function () {
    setPermissionsTeamId(null);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    $superAdmin->flushSchoolAccessCache();

    config(['auth.gate_before_superadmin' => true]);

    // Both halves deny here, and that redundancy is the design: the bypass
    // exclusion stops platform authority granting approval at all, and the
    // structural rule would still stop self-approval if a future change
    // granted super_admin result.approve outright.
    expect(Gate::forUser($superAdmin)->allows('approve', mc_status(['submitted_by' => $superAdmin->id])))
        ->toBeFalse();
});

it('lets the permission decide alone when no maker is recorded (draft / pre-C3 rows)', function () {
    $head = mc_user('head_of_school');

    // submitted_by NULL = "unknown maker", which is not evidence of a
    // violation. The DB constraint carries the same NULL guard.
    expect(Gate::forUser($head)->allows('approve', mc_status(['submitted_by' => null])))->toBeTrue();
});

// ── The database says the same thing, for writers that never touch the Policy ──

it('BITE-PROOF — the DB rejects decided_by = submitted_by even on a raw write', function () {
    $curriculumSubject = mc_curriculumSubject();
    $actor = mc_user('head_of_school');

    $insert = fn (?int $submitted, ?int $decided) => DB::table('subject_result_statuses')->insert([
        'uuid' => (string) Str::uuid(),
        'curriculum_subject_id' => $curriculumSubject->id,
        'status' => 'approved',
        'updated_by' => $actor->id,
        'submitted_by' => $submitted,
        'decided_by' => $decided,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Same identity on both sides — denied by the CHECK constraint, with no
    // application code in the path at all.
    // Asserting the MESSAGE, not merely the exception class: any SQL mistake in
    // this fixture would throw QueryException too, and a bite-proof that passes
    // for the wrong reason proves nothing.
    expect(fn () => $insert($actor->id, $actor->id))
        ->toThrow(QueryException::class, 'subject_result_statuses_maker_ne_checker');

    // The constraint is not simply rejecting everything:
    $insert($actor->id, mc_user('admin')->id);
    expect(DB::table('subject_result_statuses')->count())->toBe(1);
});

it('BITE-PROOF — the DB also rejects an UPDATE that makes the checker the maker', function () {
    $curriculumSubject = mc_curriculumSubject();
    $maker = mc_user('teacher');
    $checker = mc_user('head_of_school');

    DB::table('subject_result_statuses')->insert([
        'uuid' => (string) Str::uuid(),
        'curriculum_subject_id' => $curriculumSubject->id,
        'status' => 'approved',
        'updated_by' => $checker->id,
        'submitted_by' => $maker->id,
        'decided_by' => $checker->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('subject_result_statuses')
        ->update(['decided_by' => $maker->id]))
        ->toThrow(QueryException::class, 'subject_result_statuses_maker_ne_checker');
});

// ── The same guarantee, per approval table, asserted by CHECK NAME ────────────
//
// SchemaConventionsTest's SCHEMA INVARIANT proves every table with submitted_by +
// decided_by carries a CHECK NAMING both columns — but not that the clause says `<>`.
// The two proofs above pin the DIRECTION for subject_result_statuses only. These pin
// it for the four finance approval tables too, on a RAW write with no application code
// in the path, asserting each table's OWN constraint name so a QueryException thrown for
// any other reason (an FK, a currency/terms CHECK, an update guard) fails the test rather
// than passing it for the wrong reason. Deliberately NOT a loop: the four constraint
// names are the thing under test, so they are written out. (The credit-note / fee-schedule
// tables carry a BEFORE-INSERT/UPDATE guard; the rows below are otherwise valid, so the
// guard passes and the maker≠checker CHECK is what bites — verified by name.)

/**
 * Two distinct users plus one valid FK parent per finance approval table, so the only
 * thing wrong with a violating row is submitted_by = decided_by.
 *
 * @return array{0:School,1:User,2:User,3:Student,4:object,5:object}
 */
function mc_financeContext(): array
{
    $school = School::factory()->create();
    $maker = User::factory()->create(['school_id' => $school->id]);
    $checker = User::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);

    [$invoice, $schedule] = ActiveSchool::runFor($school->id, function () use ($school, $student) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);
        $invoice = app(GenerateInvoice::class)->handle($enrollment->uuid, [new InvoiceLineSpec('Tuition', Money::fromKobo(100000))]);

        $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'slug' => 'sess-'.Str::random(8), 'is_current' => true]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
            'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
        ]);
        $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);
        $schedule = app(CreateFeeSchedule::class)->handle($term->id, $level->id, 'v1', [['description' => 'Tuition', 'amount_minor' => 100000]]);

        return [$invoice, $schedule];
    });

    return [$school, $maker, $checker, $student, $invoice, $schedule];
}

it('BITE-PROOF — finance_credit_notes rejects maker = checker on a raw INSERT and UPDATE, by CHECK name', function () {
    [$school, $maker, $checker, $student, $invoice] = mc_financeContext();

    $row = fn (?int $submitted, ?int $decided, string $status) => [
        'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'student_id' => $student->id,
        'invoice_id' => $invoice->id, 'number' => random_int(1, 2_000_000_000), 'amount_minor' => 3000,
        'amount_currency' => 'NGN', 'kind' => 'credit_note', 'status' => $status,
        'submitted_by' => $submitted, 'decided_by' => $decided, 'created_at' => now(), 'updated_at' => now(),
    ];

    // INSERT: same identity both sides → the maker≠checker CHECK, named.
    expect(fn () => DB::table('finance_credit_notes')->insert($row($maker->id, $maker->id, 'approved')))
        ->toThrow(QueryException::class, 'finance_credit_notes_maker_ne_checker');

    // Not simply rejecting everything: distinct maker/checker inserts.
    DB::table('finance_credit_notes')->insert($row($maker->id, $checker->id, 'approved'));
    expect(DB::table('finance_credit_notes')->count())->toBe(1);

    // UPDATE: a submitted row whose approval sets the checker = the maker.
    DB::table('finance_credit_notes')->insert($row($maker->id, null, 'submitted'));
    $id = DB::table('finance_credit_notes')->where('status', 'submitted')->value('id');
    expect(fn () => DB::table('finance_credit_notes')->where('id', $id)
        ->update(['status' => 'approved', 'decided_by' => $maker->id, 'decided_at' => now()]))
        ->toThrow(QueryException::class, 'finance_credit_notes_maker_ne_checker');
});

it('BITE-PROOF — finance_void_requests rejects maker = checker on a raw INSERT and UPDATE, by CHECK name', function () {
    [$school, $maker, $checker, $student, $invoice] = mc_financeContext();

    $row = fn (?int $submitted, ?int $decided, string $status) => [
        'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'invoice_id' => $invoice->id, 'reason' => 'r',
        'status' => $status, 'submitted_by' => $submitted, 'decided_by' => $decided, 'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::table('finance_void_requests')->insert($row($maker->id, $maker->id, 'approved')))
        ->toThrow(QueryException::class, 'finance_void_requests_maker_ne_checker');

    DB::table('finance_void_requests')->insert($row($maker->id, $checker->id, 'approved'));
    expect(DB::table('finance_void_requests')->count())->toBe(1);

    DB::table('finance_void_requests')->insert($row($maker->id, null, 'submitted'));
    $id = DB::table('finance_void_requests')->where('status', 'submitted')->value('id');
    expect(fn () => DB::table('finance_void_requests')->where('id', $id)
        ->update(['status' => 'approved', 'decided_by' => $maker->id, 'decided_at' => now()]))
        ->toThrow(QueryException::class, 'finance_void_requests_maker_ne_checker');
});

it('BITE-PROOF — finance_discount_policy_changes rejects maker = checker on a raw INSERT and UPDATE, by CHECK name', function () {
    [$school, $maker, $checker] = mc_financeContext();

    // kind=create → target_policy_id null (target_shape) + full terms (terms_shape), so only
    // maker≠checker can be the violation.
    $row = fn (?int $submitted, ?int $decided, string $status) => [
        'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'kind' => 'create', 'name' => 'Sibling',
        'basis' => 'percent', 'percent' => 50, 'requires_approval' => 0, 'reason' => 'r', 'status' => $status,
        'submitted_by' => $submitted, 'decided_by' => $decided, 'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::table('finance_discount_policy_changes')->insert($row($maker->id, $maker->id, 'approved')))
        ->toThrow(QueryException::class, 'finance_discount_policy_changes_maker_ne_checker');

    DB::table('finance_discount_policy_changes')->insert($row($maker->id, $checker->id, 'approved'));
    expect(DB::table('finance_discount_policy_changes')->count())->toBe(1);

    DB::table('finance_discount_policy_changes')->insert($row($maker->id, null, 'submitted'));
    $id = DB::table('finance_discount_policy_changes')->where('status', 'submitted')->value('id');
    expect(fn () => DB::table('finance_discount_policy_changes')->where('id', $id)
        ->update(['status' => 'approved', 'decided_by' => $maker->id, 'decided_at' => now()]))
        ->toThrow(QueryException::class, 'finance_discount_policy_changes_maker_ne_checker');
});

it('BITE-PROOF — finance_fee_schedule_changes rejects maker = checker on a raw INSERT and UPDATE, by CHECK name', function () {
    [$school, $maker, $checker, , , $schedule] = mc_financeContext();

    $row = fn (?int $submitted, ?int $decided, string $status) => [
        'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'kind' => 'publish',
        'target_schedule_id' => $schedule->id, 'reason' => 'r', 'status' => $status,
        'submitted_by' => $submitted, 'decided_by' => $decided, 'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::table('finance_fee_schedule_changes')->insert($row($maker->id, $maker->id, 'approved')))
        ->toThrow(QueryException::class, 'finance_fee_schedule_changes_maker_ne_checker');

    DB::table('finance_fee_schedule_changes')->insert($row($maker->id, $checker->id, 'approved'));
    expect(DB::table('finance_fee_schedule_changes')->count())->toBe(1);

    DB::table('finance_fee_schedule_changes')->insert($row($maker->id, null, 'submitted'));
    $id = DB::table('finance_fee_schedule_changes')->where('status', 'submitted')->value('id');
    expect(fn () => DB::table('finance_fee_schedule_changes')->where('id', $id)
        ->update(['status' => 'approved', 'decided_by' => $maker->id, 'decided_at' => now()]))
        ->toThrow(QueryException::class, 'finance_fee_schedule_changes_maker_ne_checker');
});

// ── The seeded grant map itself is SoD-disjoint (generic, convention-driven) ──

it('SoD — no seeded role holds a checker ability together with its matching maker', function () {
    // The SyncRolePermissionsRequest grant guard forbids this at runtime; this proves the
    // SEEDED map (written directly, bypassing that request) is already disjoint. It is generic
    // over App\Support\ApprovalAbility — a convention, not a pair list — so it covers
    // result.*, finance.credit-note.*, finance.invoice.void-request.*, and any future
    // maker/checker pair the day it is seeded, with nobody remembering to extend this test.
    setPermissionsTeamId(null);

    $roles = Role::with('permissions')->get();
    expect($roles)->not->toBeEmpty();

    $checkerAbilitiesSeen = 0;
    foreach ($roles as $role) {
        $held = $role->permissions->pluck('name')->all();

        foreach ($held as $ability) {
            $matchingMaker = ApprovalAbility::matchingMakerFor($ability);
            if ($matchingMaker === null) {
                continue; // not a checker action
            }

            $checkerAbilitiesSeen++;
            expect(in_array($matchingMaker, $held, true))->toBeFalse(
                "Role [{$role->name}] holds checker [{$ability}] AND its matching maker [{$matchingMaker}] — separation of duties violated."
            );
        }
    }

    // Guard the guard: a seeder with zero checker abilities would make the loop vacuously green.
    // Assert we actually exercised the known checker abilities (result + credit-note + void).
    expect($checkerAbilitiesSeen)->toBeGreaterThanOrEqual(4);
});

it('keeps the Policy and the DB constraint agreeing on the NULL case', function () {
    $curriculumSubject = mc_curriculumSubject();

    // The Policy allows an unknown maker; the DB must accept the same row,
    // otherwise one layer forbids what the other permits.
    DB::table('subject_result_statuses')->insert([
        'uuid' => (string) Str::uuid(),
        'curriculum_subject_id' => $curriculumSubject->id,
        'status' => 'approved',
        'updated_by' => ($actor = mc_user('head_of_school'))->id,
        'submitted_by' => null,
        'decided_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('subject_result_statuses')->count())->toBe(1)
        ->and((new SubjectResultPolicy)->approve(mc_actingContext($actor), mc_status(['submitted_by' => null])))->toBeTrue();
});

// ── USER-LEVEL segregation of duties (detection, not enforcement) ──────────────
//
// The role-level SoD test above proves no ONE role holds both sides. Nothing there stops a USER
// from accumulating both by holding two roles (the exact dev-data finding: a user with
// accounts_officer + the credit-note checker seat). The DB CHECK still makes self-approval impossible; what a
// both-sides user creates is a CONFIGURATION hole (approve a colleague's work in both directions),
// which these DETECT — they never refuse a grant.

/**
 * Every (user, school) pair that effectively holds both sides of some maker-checker pair. The
 * tripwire the invariant test guards; scoped per school, evaluated on effective ability.
 *
 * @return list<array{0:int,1:int}>
 */
function allBothSidesUsers(): array
{
    $out = [];
    $rows = DB::table('model_has_roles')->where('model_type', User::class)
        ->select('model_id', 'school_id')->distinct()->get();

    foreach ($rows as $row) {
        if ($row->school_id === null) {
            continue;
        }
        $user = User::find($row->model_id);
        if ($user !== null && DutySeparation::violations($user, (int) $row->school_id) !== []) {
            $out[] = [(int) $row->model_id, (int) $row->school_id];
        }
    }
    setPermissionsTeamId(null);

    return $out;
}

/**
 * Plant a both-sides FINANCE user via a RAW write. Grant-time enforcement now REFUSES this through
 * spatie's API (User::assignRole → DutySeparation), so a both-sides user can only arise OUTSIDE
 * that API — a raw insert, a migration backfill — which is exactly the residual detection must
 * still catch. Maker via the normal (allowed) path; the checker role inserted straight into
 * model_has_roles, bypassing the guard.
 */
function plantBothSidesFinanceUser(School $school): User
{
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'accounts_officer'); // maker — allowed

    // executive_director since 2026-08-04 — it holds the credit-note and void checker sides that
    // accounts_supervisor held until then. AS is now maker-and-viewer and would plant no violation.
    $director = Role::where('name', 'executive_director')->where('guard_name', 'web')->firstOrFail();
    DB::table('model_has_roles')->insert([
        'role_id' => $director->id,
        'model_type' => User::class,
        'model_id' => $user->id,
        'school_id' => $school->id,
    ]);
    $user->flushSchoolAccessCache();

    return $user;
}

it('DETECTS a user who accumulates both sides across two roles (the dev-data shape)', function () {
    // The role guard forbids one role holding both; this user holds two roles that together do —
    // the dev-audit shape: one user holding the maker seat and the checker seat together. Planted raw
    // because grant-time enforcement now refuses this via the spatie API.
    $school = al_makeSchool();
    $user = plantBothSidesFinanceUser($school);

    $violations = DutySeparation::violations($user, $school->id);
    expect($violations)->not->toBeEmpty()
        ->and(collect($violations)->pluck('checker')->all())->toContain('finance.credit-note.approve');
});

it('a user holding only ONE side (accounts_officer, maker) is NOT a violation', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'accounts_officer');
    $user->flushSchoolAccessCache();

    expect(DutySeparation::violations($user, $school->id))->toBe([]);
});

it('super_admin is NEVER a both-sides violation — effective ability, checker abilities never bypassed', function () {
    config(['auth.gate_before_superadmin' => true]);
    $school = al_makeSchool();
    setPermissionsTeamId(null);
    $super = User::factory()->create(['school_id' => $school->id]);
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();

    // Holds every maker EFFECTIVELY (bypass) but no checker (excluded) → can never be both-sides.
    expect(DutySeparation::violations($super, $school->id))->toBe([]);
});

it('INVARIANT — no seeded user is a both-sides violator (tripwire), bite-proven by a planted user', function () {
    // The seeder creates no users, so the live scan is clean today — its value is as a TRIPWIRE: a
    // seeder/fixture that later assigns one user both a maker and a checker role fails here.
    expect(allBothSidesUsers())->toBe([]);

    // BITE-PROOF: plant exactly such a user (raw — enforcement now refuses this via the spatie API);
    // the SAME scan must now turn red.
    $school = al_makeSchool();
    plantBothSidesFinanceUser($school);

    expect(allBothSidesUsers())->not->toBe([]);
});
