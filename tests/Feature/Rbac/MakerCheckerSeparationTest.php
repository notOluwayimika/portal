<?php

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Subject;
use App\Models\SubjectResultStatus;
use App\Models\User;
use App\Policies\SubjectResultPolicy;
use App\Support\ActiveSchool;
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
