<?php

use App\Finance\Models\Invoice;
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

/**
 * Finance API ACCEPTANCE HARNESS — the permanent integration gate.
 *
 * Every prior Finance proof drove the Actions directly (app(GenerateInvoice)) or the DB
 * guards. Those prove each slice in isolation; NONE proves the pieces COMPOSE through the
 * real HTTP surface — auth → tenant (school context) → permission → controller → action →
 * DB → wire response. This file is the first real consumer to bind to /api/v1/finance/*,
 * so it validates the CONTRACT (wire shapes, status codes) and the COMPOSITION (a full
 * bursar lifecycle: generate → pay → credit-note → statement) end to end.
 *
 * AUTH IS TOKEN-BASED on purpose. A Sanctum token carries school_id (ActiveSchool path 2),
 * so a pure-API client resolves its own tenant context with no session — the path a mobile
 * or server-to-server consumer uses, and one nothing else exercises. Session/SPA auth is
 * the bursar UI's path (step 2); the middleware chain under test is the same either way.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * A bursar in $school holding $permissions, and a school-scoped Sanctum token for them.
 * Mirrors the login controller: createToken then forceFill school_id onto the token, which
 * is what ActiveSchool reads for a pure-token request.
 *
 * @param  list<string>  $permissions
 * @return array{0: User, 1: string} the user and the plaintext bearer token
 */
function bursarWithToken(School $school, array $permissions): array
{
    // A DEDICATED role holding EXACTLY $permissions — not `admin`, whose seeded grants
    // already include finance.credit-note.issue (which would make the 403 gate test a
    // false green). The role name is keyed to the permission set so distinct sets get
    // distinct roles and never bleed into one another.
    $roleName = 'ftest_'.substr(md5(implode(',', $permissions)), 0, 12);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $roleName);
    $user->flushSchoolAccessCache();

    $token = $user->createToken('acceptance');
    $token->accessToken->forceFill(['school_id' => $school->id])->save();

    return [$user, $token->plainTextToken];
}

/** An active enrollment episode for a fresh student in $school; returns its uuid. */
function acceptanceEnrollment(School $school): string
{
    $student = Student::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]))->uuid;
}

it('COMPOSES — the full bursar lifecycle through the real API: generate → pay → credit-note → statement', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access', 'finance.credit-note.issue']);
    $enrollment = acceptanceEnrollment($school);

    // 1 ── Generate a 10000 invoice. The wire carries LINES, never a total (F6).
    $invoice = $this->withToken($token)
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment,
            'lines' => [['description' => 'Tuition', 'amount_minor' => 10000]],
        ])
        ->assertCreated()
        ->assertJsonPath('total.amount_minor', 10000)
        ->assertJsonPath('total.currency', 'NGN')
        ->json();

    $invoiceUuid = $invoice['id'];

    // 2 ── Record a 10000 payment against it → the invoice is settled.
    $this->withToken($token)
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments", [
            'amount_minor' => 10000,
            'payer_name' => 'A Parent',
        ])
        ->assertCreated()
        ->assertJsonPath('amount.amount_minor', 10000);

    // 3 ── Issue a 3000 credit note → an account credit balance appears.
    $this->withToken($token)
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/credit-notes", [
            'amount_minor' => 3000,
        ])
        ->assertCreated()
        ->assertJsonPath('amount.amount_minor', 3000)
        ->assertJsonPath('kind', 'credit_note');

    // 4 ── The statement COMPOSES all of it: the invoice at its full amount, the credit
    //      note as its own document, and the account credit balance — never netted.
    $studentUuid = Student::query()->where('school_id', $school->id)->firstOrFail()->uuid;

    $this->withToken($token)
        ->getJson("/api/v1/finance/students/{$studentUuid}/invoices")
        ->assertOk()
        ->assertJsonPath('invoices.0.total.amount_minor', 10000)        // full, not netted
        ->assertJsonPath('credit_notes.0.amount.amount_minor', 3000)    // own document
        ->assertJsonPath('account.available_credit.amount_minor', 3000) // account credit surfaced
        ->assertJsonPath('account.balance.amount_minor', -3000);
});

it('CONTRACT — a pure token client (no session) resolves its own school context and can bill', function () {
    // Proves ActiveSchool path 2: the token's school_id drives tenant context and the
    // permission team, with no session at all. Otherwise entirely untested.
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    $enrollment = acceptanceEnrollment($school);

    $this->withToken($token)
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment,
            'lines' => [['description' => 'Tuition', 'amount_minor' => 5000]],
        ])
        ->assertCreated();
});

it('GATE — issuing a credit note without finance.credit-note.issue is 403, even with finance.access', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']); // NOT the credit-note permission
    $enrollment = acceptanceEnrollment($school);

    $invoiceUuid = $this->withToken($token)
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment,
            'lines' => [['description' => 'Tuition', 'amount_minor' => 10000]],
        ])->assertCreated()->json('id');

    $this->withToken($token)
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/credit-notes", ['amount_minor' => 1000])
        ->assertForbidden();
});

it('ISOLATION — a bursar cannot bill an enrollment in another School (cross-school guard composes through the API)', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    [, $tokenA] = bursarWithToken($schoolA, ['finance.access']);
    $enrollmentB = acceptanceEnrollment($schoolB); // belongs to School B

    // School A's token, School B's enrollment → the GenerateInvoice cross-School guard
    // rejects it (422). Isolation is not just a scope on a query — it holds through the
    // whole HTTP stack.
    $this->withToken($tokenA)
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollmentB,
            'lines' => [['description' => 'Tuition', 'amount_minor' => 10000]],
        ])
        ->assertStatus(422);

    // And School B has no invoice as a result.
    expect(Invoice::withoutGlobalScopes()->where('school_id', $schoolB->id)->count())->toBe(0);
});
