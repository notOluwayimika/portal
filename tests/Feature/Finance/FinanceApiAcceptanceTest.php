<?php

use App\Finance\Actions\ApproveCreditNote;
use App\Finance\Actions\SubmitCreditNote;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Enums\CreditNoteStatus;
use App\Finance\Enums\VoidRequestStatus;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Models\StudentAccount;
use App\Finance\Models\VoidRequest;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\ApprovalAbility;
use App\Support\Money;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

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
    // Runtime grants must invalidate Spatie's permission cache, or the request-time
    // PermissionMiddleware resolves a stale role→permission map and 403s a genuinely
    // granted ability (the maker path happened to be warm; the checker's was not).
    app(PermissionRegistrar::class)->forgetCachedPermissions();

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

/**
 * A student in $school whose account carries an EXACT signed balance (positive = owes,
 * negative = in credit, zero = settled). Plants the projection row directly so the
 * accounts-index reconciliation asserts against a known ledger position without threading
 * a full generate→pay→credit-note flow per student. Returns the Student for uuid/name asserts.
 */
function accountWithBalance(School $school, int $balanceMinor, ?string $firstName = null, ?string $lastName = null): Student
{
    $student = Student::factory()->create(array_filter([
        'school_id' => $school->id,
        'first_name' => $firstName,
        'last_name' => $lastName,
    ], fn ($v) => $v !== null));

    // BelongsToSchool stamps school_id from the active School; the raw *_minor/*_currency
    // columns are set directly (bypassing the Money cast) to pin an exact balance.
    ActiveSchool::runFor($school->id, fn () => StudentAccount::create([
        'student_id' => $student->id,
        'balance_minor' => $balanceMinor,
        'balance_currency' => 'NGN',
    ]));

    return $student;
}

/** A fresh student in $school with an active enrollment; returns the STUDENT uuid. */
function acceptanceStudent(School $school): string
{
    $student = Student::factory()->create(['school_id' => $school->id]);
    ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));

    return $student->uuid;
}

it('GENERATE BY STUDENT — the bursar bills a student (no enrollment_id); the invoice composes onto the statement, second is F7-rejected', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    $studentUuid = acceptanceStudent($school);

    // The modal's read: resolves the episode server-side + the F7 preview (not yet invoiced).
    mcApi($token)
        ->getJson("/api/v1/finance/students/{$studentUuid}/billable-enrollment")
        ->assertOk()
        ->assertJsonPath('already_invoiced', false)
        ->assertJsonStructure(['academic_context', 'already_invoiced']);

    // Generate by STUDENT — NO enrollment_id on the wire. A charge + a discount reduction;
    // the total is DERIVED server-side (F6): 50000 − 5000 = 45000.
    mcApi($token)
        ->postJson("/api/v1/finance/students/{$studentUuid}/invoices", [
            'lines' => [
                ['description' => 'Tuition', 'amount_minor' => 50000, 'kind' => 'charge'],
                ['description' => 'Sibling discount', 'amount_minor' => -5000, 'kind' => 'discount'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('total.amount_minor', 45000);

    // It appears on the statement at its full derived total.
    mcApi($token)
        ->getJson("/api/v1/finance/students/{$studentUuid}/invoices")
        ->assertOk()
        ->assertJsonPath('invoices.0.total.amount_minor', 45000);

    // F7 preview now true; a second generate for the SAME episode is rejected (422).
    mcApi($token)
        ->getJson("/api/v1/finance/students/{$studentUuid}/billable-enrollment")
        ->assertJsonPath('already_invoiced', true);
    mcApi($token)
        ->postJson("/api/v1/finance/students/{$studentUuid}/invoices", [
            'lines' => [['description' => 'Tuition', 'amount_minor' => 10000]],
        ])
        ->assertStatus(422);
});

it('NO ENROLLMENT — a student with no active enrollment cannot be billed (422 on read and write)', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    $student = Student::factory()->create(['school_id' => $school->id]); // no enrollment

    mcApi($token)
        ->getJson("/api/v1/finance/students/{$student->uuid}/billable-enrollment")
        ->assertStatus(422);
    mcApi($token)
        ->postJson("/api/v1/finance/students/{$student->uuid}/invoices", [
            'lines' => [['description' => 'Tuition', 'amount_minor' => 1000]],
        ])
        ->assertStatus(422);
});

it('PAYMENTS — appear on the statement read as their own history (Piece B)', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    $studentUuid = acceptanceStudent($school);

    $invoiceUuid = mcApi($token)
        ->postJson("/api/v1/finance/students/{$studentUuid}/invoices", [
            'lines' => [['description' => 'Tuition', 'amount_minor' => 10000]],
        ])->assertCreated()->json('id');

    mcApi($token)
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments", [
            'amount_minor' => 4000,
            'payer_name' => 'A Parent',
        ])->assertCreated();

    mcApi($token)
        ->getJson("/api/v1/finance/students/{$studentUuid}/invoices")
        ->assertOk()
        ->assertJsonPath('payments.0.amount.amount_minor', 4000)
        ->assertJsonPath('payments.0.payer_name', 'A Parent')
        ->assertJsonStructure(['payments' => [['id', 'reference', 'method', 'amount', 'created_at']]]);
});

it('COMPOSES — the full bursar lifecycle through the real API: generate → pay → SUBMIT → APPROVE → statement', function () {
    $school = School::factory()->create();
    [, $maker] = bursarWithToken($school, ['finance.access', 'finance.credit-note.submit']);
    [, $checker] = bursarWithToken($school, ['finance.access', 'finance.credit-note.approve']);
    $enrollment = acceptanceEnrollment($school);

    // 1 ── Generate a 10000 invoice. The wire carries LINES, never a total (F6).
    $invoiceUuid = mcApi($maker)
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment,
            'lines' => [['description' => 'Tuition', 'amount_minor' => 10000]],
        ])
        ->assertCreated()
        ->assertJsonPath('total.amount_minor', 10000)
        ->json('id');

    // 2 ── Record a 10000 payment against it → the invoice is settled.
    mcApi($maker)
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments", [
            'amount_minor' => 10000,
            'payer_name' => 'A Parent',
        ])
        ->assertCreated();

    $studentUuid = Student::query()->where('school_id', $school->id)->firstOrFail()->uuid;

    // 3 ── SUBMIT a 3000 credit note (maker) → pending, NO account credit yet.
    $creditUuid = mcApi($maker)
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/credit-notes", [
            'amount_minor' => 3000,
        ])
        ->assertCreated()
        ->assertJsonPath('amount.amount_minor', 3000)
        ->assertJsonPath('status', 'submitted')
        ->json('id');

    mcApi($maker)
        ->getJson("/api/v1/finance/students/{$studentUuid}/invoices")
        ->assertJsonPath('account.balance.amount_minor', 0);           // pending: no money moved

    // 4 ── APPROVE it (checker ≠ maker) → NOW the account credit balance appears.
    mcApi($checker)
        ->postJson("/api/v1/finance/credit-notes/{$creditUuid}/approve")
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    // 5 ── The statement COMPOSES all of it: the invoice at full amount, the approved credit
    //      note as its own document, and the account credit balance — never netted.
    mcApi($checker)
        ->getJson("/api/v1/finance/students/{$studentUuid}/invoices")
        ->assertOk()
        ->assertJsonPath('invoices.0.total.amount_minor', 10000)        // full, not netted
        ->assertJsonPath('credit_notes.0.amount.amount_minor', 3000)    // own document
        ->assertJsonPath('credit_notes.0.status', 'approved')
        ->assertJsonPath('account.available_credit.amount_minor', 3000) // account credit surfaced
        ->assertJsonPath('account.balance.amount_minor', -3000);
});

it('CONTRACT — a pure token client (no session) resolves its own school context and can bill', function () {
    // Proves ActiveSchool path 2: the token's school_id drives tenant context and the
    // permission team, with no session at all. Otherwise entirely untested.
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    $enrollment = acceptanceEnrollment($school);

    mcApi($token)
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment,
            'lines' => [['description' => 'Tuition', 'amount_minor' => 5000]],
        ])
        ->assertCreated();
});

// ── Ph3 maker-checker helpers ────────────────────────────────────────────────
/** @return array{0: string, 1: string} [makerToken, checkerToken] — distinct users, one side each. */
function makerCheckerTokens(School $school): array
{
    [, $maker] = bursarWithToken($school, ['finance.access', 'finance.credit-note.submit']);
    [, $checker] = bursarWithToken($school, ['finance.access', 'finance.credit-note.approve', 'finance.credit-note.reject']);

    return [$maker, $checker];
}

/**
 * A request builder bound to $token, with the auth guard forgotten first. A maker-checker
 * test switches between two token users in one test; the Sanctum guard memoises the first
 * resolved user for the app instance, so without forgetGuards the SECOND token silently
 * authenticates as the FIRST user (the exact bug this harness must not have). Single-user
 * tests never hit it — this is the two-user path.
 */
function mcApi(string $token)
{
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

/** Generate an invoice (maker) and return its uuid. */
function mcInvoice(string $token, string $enrollment, int $amount = 10000): string
{
    return mcApi($token)->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $enrollment,
        'lines' => [['description' => 'Tuition', 'amount_minor' => $amount]],
    ])->assertCreated()->json('id');
}

/** Submit a pending credit note (maker) and return its uuid. */
function mcSubmit(string $token, string $invoiceUuid, int $amount = 3000): string
{
    return mcApi($token)
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/credit-notes", ['amount_minor' => $amount])
        ->assertCreated()->assertJsonPath('status', 'submitted')->json('id');
}

function mcCreditRow(string $uuid): CreditNote
{
    return CreditNote::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
}

function mcBalance(School $school, string $studentUuid): int
{
    $studentId = Student::query()->where('school_id', $school->id)->where('uuid', $studentUuid)->value('id');

    return (int) StudentAccount::withoutGlobalScopes()->where('student_id', $studentId)->value('balance_minor') ?? 0;
}

function mcLedgerCount(CreditNote $note): int
{
    return (int) DB::table('finance_ledger_transactions')
        ->where('source_type', 'credit_note')->where('source_id', $note->id)->count();
}

// PROOF (GATE) — submitting needs the MAKER permission; finance.access alone is 403.
it('MC GATE — submitting a credit note without finance.credit-note.submit is 403 (has finance.access)', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']); // NOT the submit permission
    $enrollment = acceptanceEnrollment($school);
    $invoiceUuid = mcInvoice($token, $enrollment);

    mcApi($token)
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/credit-notes", ['amount_minor' => 1000])
        ->assertForbidden();
});

// PROOF 1 — SUBMIT posts no money.
it('MC PROOF 1 — submit creates a pending note and posts NO money (zero ledger, balance unchanged)', function () {
    $school = School::factory()->create();
    [$maker] = makerCheckerTokens($school);
    $enrollment = acceptanceEnrollment($school);
    $studentUuid = Student::query()->where('school_id', $school->id)->firstOrFail()->uuid;
    $invoiceUuid = mcInvoice($maker, $enrollment);

    $creditUuid = mcSubmit($maker, $invoiceUuid, 3000);
    $note = mcCreditRow($creditUuid);

    expect($note->status)->toBe(CreditNoteStatus::Submitted)
        ->and(mcLedgerCount($note))->toBe(0)          // no compensating credit posted
        ->and(mcBalance($school, $studentUuid))->toBe(10000); // still fully owed, nothing forgiven
});

// PROOF 2 — APPROVE by a non-maker moves money exactly once.
it('MC PROOF 2 — approve by a non-maker → approved, decided_by set, exactly one ledger credit, balance moves', function () {
    $school = School::factory()->create();
    [$maker, $checker] = makerCheckerTokens($school);
    $enrollment = acceptanceEnrollment($school);
    $studentUuid = Student::query()->where('school_id', $school->id)->firstOrFail()->uuid;
    $invoiceUuid = mcInvoice($maker, $enrollment);
    $creditUuid = mcSubmit($maker, $invoiceUuid, 3000);

    mcApi($checker)
        ->postJson("/api/v1/finance/credit-notes/{$creditUuid}/approve")
        ->assertOk()->assertJsonPath('status', 'approved');

    $note = mcCreditRow($creditUuid);
    expect($note->decided_by)->not->toBeNull()
        ->and(mcLedgerCount($note))->toBe(1)                    // exactly one compensating credit
        ->and(mcBalance($school, $studentUuid))->toBe(7000);   // 10000 − 3000 forgiven
});

// PROOF 3a — the MAKER cannot approve their OWN submission (Policy 403), even holding approve.
it('MC PROOF 3a — a maker who also holds approve is FORBIDDEN from approving their own submission (Policy)', function () {
    $school = School::factory()->create();
    // A single user holding BOTH sides (the grant guard blocks this per-ROLE; here the test
    // token is minted directly to prove the record-level rule independently of the role guard).
    [, $both] = bursarWithToken($school, ['finance.access', 'finance.credit-note.submit', 'finance.credit-note.approve']);
    $enrollment = acceptanceEnrollment($school);
    $invoiceUuid = mcInvoice($both, $enrollment);
    $creditUuid = mcSubmit($both, $invoiceUuid, 3000);

    mcApi($both)
        ->postJson("/api/v1/finance/credit-notes/{$creditUuid}/approve")
        ->assertForbidden(); // isNotTheMaker denies — they submitted it
});

// PROOF 3b — the DB CHECK is the real backstop: a raw update setting decided_by = submitted_by throws.
it('MC PROOF 3b — the DB CHECK refuses decided_by = submitted_by on a raw write (maker ≠ checker is structural)', function () {
    $school = School::factory()->create();
    [$maker] = makerCheckerTokens($school);
    $enrollment = acceptanceEnrollment($school);
    $invoiceUuid = mcInvoice($maker, $enrollment);
    $note = mcCreditRow(mcSubmit($maker, $invoiceUuid, 3000));

    expect(fn () => DB::table('finance_credit_notes')->where('id', $note->id)->update([
        'status' => 'approved',
        'decided_by' => $note->submitted_by, // the maker as checker — the illegal state
        'decided_at' => now(),
    ]))->toThrow(QueryException::class);
});

// PROOF 4 — REJECT leaves no money; a reason is required.
it('MC PROOF 4 — reject → rejected + reason, zero ledger, balance unchanged; empty reason is refused', function () {
    $school = School::factory()->create();
    [$maker, $checker] = makerCheckerTokens($school);
    $enrollment = acceptanceEnrollment($school);
    $studentUuid = Student::query()->where('school_id', $school->id)->firstOrFail()->uuid;
    $invoiceUuid = mcInvoice($maker, $enrollment);
    $creditUuid = mcSubmit($maker, $invoiceUuid, 3000);

    // Empty reason → 422 (request validation).
    mcApi($checker)
        ->postJson("/api/v1/finance/credit-notes/{$creditUuid}/reject", ['reason' => ''])
        ->assertStatus(422);

    mcApi($checker)
        ->postJson("/api/v1/finance/credit-notes/{$creditUuid}/reject", ['reason' => 'Not approved by bursar'])
        ->assertOk()->assertJsonPath('status', 'rejected')
        ->assertJsonPath('rejection_reason', 'Not approved by bursar');

    $note = mcCreditRow($creditUuid);
    expect(mcLedgerCount($note))->toBe(0)                       // never any money
        ->and(mcBalance($school, $studentUuid))->toBe(10000);  // fully owed still
});

// PROOF 5 — the ceiling fires at APPROVAL (approved-only), and the DB trigger is the backstop.
it('MC PROOF 5 — two pendings that jointly exceed total: both submit; first approves; the second approval is blocked (app AND DB)', function () {
    $school = School::factory()->create();
    [$maker, $checker] = makerCheckerTokens($school);
    $enrollment = acceptanceEnrollment($school);
    $invoiceUuid = mcInvoice($maker, $enrollment, 10000);

    // Two proposals of 6000 each — individually fine, jointly 12000 > 10000.
    $a = mcSubmit($maker, $invoiceUuid, 6000);
    $b = mcSubmit($maker, $invoiceUuid, 6000);

    // First approves (Σapproved = 6000 ≤ 10000).
    mcApi($checker)->postJson("/api/v1/finance/credit-notes/{$a}/approve")->assertOk();

    // Second approval is rejected by the app ceiling (422).
    mcApi($checker)->postJson("/api/v1/finance/credit-notes/{$b}/approve")->assertStatus(422);

    // …and by the DB trigger too: a RAW status flip to approved that over-credits throws.
    $bRow = mcCreditRow($b);
    expect(fn () => DB::table('finance_credit_notes')->where('id', $bRow->id)->update([
        'status' => 'approved',
        'decided_by' => $bRow->submitted_by + 1, // a different id → passes the CHECK; only the ceiling can stop it
        'decided_at' => now(),
    ]))->toThrow(QueryException::class);
});

// PROOF 6 — super_admin cannot bypass the approval (Gate::before exclusion holds).
it('MC PROOF 6 — a super_admin cannot approve (the checker bypass-exclusion holds for approve AND reject)', function () {
    $school = School::factory()->create();
    $super = User::factory()->create(['school_id' => $school->id]);
    $super->grantSchoolAccess($school, 'super_admin');
    $super->flushSchoolAccessCache();
    $superToken = $super->createToken('sa');
    $superToken->accessToken->forceFill(['school_id' => $school->id])->save();
    $superPlain = $superToken->plainTextToken;

    [$maker] = makerCheckerTokens($school);
    $enrollment = acceptanceEnrollment($school);
    $invoiceUuid = mcInvoice($maker, $enrollment);
    $creditUuid = mcSubmit($maker, $invoiceUuid, 3000);

    // super_admin's Gate::before does NOT bypass approve/reject (ApprovalAbility exclusion),
    // and super_admin holds no explicit approve grant → forbidden.
    mcApi($superPlain)
        ->postJson("/api/v1/finance/credit-notes/{$creditUuid}/approve")
        ->assertForbidden();
    mcApi($superPlain)
        ->postJson("/api/v1/finance/credit-notes/{$creditUuid}/reject", ['reason' => 'x'])
        ->assertForbidden();
});

// PROOF 7 — the Kernel grant guard covers the Finance pair by CONVENTION (no RBAC change needed).
it('MC PROOF 7 — the maker-checker convention pairs finance.credit-note submit/approve, so the grant guard forbids both on one role', function () {
    // ApprovalAbility (the mechanism SyncRolePermissionsRequest uses) derives the matching
    // maker for the finance checker ability — which is exactly why a single role holding both
    // is rejected without any RBAC-stream change.
    expect(ApprovalAbility::matchingMakerFor('finance.credit-note.approve'))
        ->toBe('finance.credit-note.submit')
        ->and(ApprovalAbility::isExcludedFromSuperAdminBypass('finance.credit-note.approve'))->toBeTrue()
        ->and(ApprovalAbility::isExcludedFromSuperAdminBypass('finance.credit-note.reject'))->toBeTrue();
});

// PROOF 8 (money immutable but status mutable) lives in CreditNoteTest PROOF 8 (DB-level).

// PROOF 9 — illegal state transitions are refused by canTransitionTo; approved is terminal.
it('MC PROOF 9 — approved/rejected are terminal: illegal transitions are refused (model + API)', function () {
    $school = School::factory()->create();
    [$maker, $checker] = makerCheckerTokens($school);
    $enrollment = acceptanceEnrollment($school);
    $invoiceUuid = mcInvoice($maker, $enrollment);
    $creditUuid = mcSubmit($maker, $invoiceUuid, 3000);
    mcApi($checker)->postJson("/api/v1/finance/credit-notes/{$creditUuid}/approve")->assertOk();

    $approved = mcCreditRow($creditUuid);
    expect($approved->status)->toBe(CreditNoteStatus::Approved)
        ->and($approved->canTransitionTo(CreditNoteStatus::Rejected))->toBeFalse()
        ->and($approved->canTransitionTo(CreditNoteStatus::Submitted))->toBeFalse()
        ->and($approved->canTransitionTo(CreditNoteStatus::Approved))->toBeFalse();

    // Re-approving an already-approved note through the API is rejected (not pending → 422).
    mcApi($checker)
        ->postJson("/api/v1/finance/credit-notes/{$creditUuid}/approve")
        ->assertStatus(422);
});

// PROOF 10 — the one-step finance.credit-note.issue permission and route are GONE.
it('MC PROOF 10 — the C1 one-step finance.credit-note.issue is retired (no enum case, no live grant)', function () {
    expect(App\Enums\Permission::tryFrom('finance.credit-note.issue'))->toBeNull();

    // No seeded role holds it, and the three lifecycle permissions exist.
    (new RbacSeeder)->run();
    expect(Permission::where('name', 'finance.credit-note.issue')->exists())->toBeFalse()
        ->and(Permission::where('name', 'finance.credit-note.submit')->exists())->toBeTrue()
        ->and(Permission::where('name', 'finance.credit-note.approve')->exists())->toBeTrue()
        ->and(Permission::where('name', 'finance.credit-note.reject')->exists())->toBeTrue();
});

it('ACCOUNTS INDEX — lists the School\'s accounts with LIVE student display, and every row carries the statement link', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    $student = accountWithBalance($school, 45000, 'Ada', 'Lovelace'); // owes ₦450.00

    $body = mcApi($token)
        ->getJson('/api/v1/finance/accounts')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [[
                'student' => ['uuid', 'name', 'admission_number'],
                'balance' => ['amount_minor', 'currency'],
                'available_credit' => ['amount_minor', 'currency'],
                'last_activity',
            ]],
            'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            'kpis' => ['total_receivables' => ['amount_minor', 'currency'], 'total_credit' => ['amount_minor', 'currency']],
        ])
        ->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('data.0.student.uuid', $student->uuid)   // the row → THIS student's statement
        ->assertJsonPath('data.0.student.name', 'Ada Lovelace')   // LIVE, resolved via the ACL port
        ->assertJsonPath('data.0.balance.amount_minor', 45000)
        ->json();

    // A rename surfaces immediately (live display, not a billing-time snapshot).
    $student->update(['first_name' => 'Augusta']);
    mcApi($token)->getJson('/api/v1/finance/accounts')
        ->assertJsonPath('data.0.student.name', 'Augusta Lovelace');
});

it('ACCOUNTS INDEX — paginates at 20 rows a page', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    foreach (range(1, 21) as $i) {
        accountWithBalance($school, $i * 1000);
    }

    mcApi($token)->getJson('/api/v1/finance/accounts')
        ->assertJsonPath('pagination.total', 21)
        ->assertJsonPath('pagination.per_page', 20)
        ->assertJsonPath('pagination.last_page', 2)
        ->assertJsonCount(20, 'data');

    mcApi($token)->getJson('/api/v1/finance/accounts?page=2')
        ->assertJsonPath('pagination.current_page', 2)
        ->assertJsonCount(1, 'data');
});

it('ACCOUNTS KPIs — receivables = Σ positive, credit = Σ |negative|, over ALL accounts and UNCHANGED by search/filter', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    accountWithBalance($school, 30000);   // owes
    accountWithBalance($school, 20000);   // owes
    accountWithBalance($school, -5000);   // in credit
    accountWithBalance($school, 0);       // settled

    // receivables = 50000, credit = 5000 — independent of the endpoint's own arithmetic.
    mcApi($token)->getJson('/api/v1/finance/accounts')
        ->assertJsonPath('kpis.total_receivables.amount_minor', 50000)
        ->assertJsonPath('kpis.total_credit.amount_minor', 5000);

    // A status filter narrows the LIST, but the KPIs are the School-wide denominator the
    // filtered view is read against — they must NOT move with the filter.
    mcApi($token)->getJson('/api/v1/finance/accounts?status=in_credit')
        ->assertJsonPath('pagination.total', 1)                        // only the one in-credit row
        ->assertJsonPath('kpis.total_receivables.amount_minor', 50000) // unchanged
        ->assertJsonPath('kpis.total_credit.amount_minor', 5000);      // unchanged
});

it('ACCOUNTS ISOLATION — another School\'s accounts never appear in the list OR the KPIs', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    [, $tokenA] = bursarWithToken($schoolA, ['finance.access']);
    accountWithBalance($schoolA, 10000);
    accountWithBalance($schoolB, 99999);  // B's receivable — must be invisible to A
    accountWithBalance($schoolB, -12345); // B's credit — must be invisible to A

    mcApi($tokenA)->getJson('/api/v1/finance/accounts')
        ->assertJsonPath('pagination.total', 1)                        // only A's row
        ->assertJsonPath('kpis.total_receivables.amount_minor', 10000) // B's 99999 absent
        ->assertJsonPath('kpis.total_credit.amount_minor', 0);         // B's credit absent
});

it('ACCOUNTS STATUS FILTER — outstanding / in_credit / settled partition the accounts exactly', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    accountWithBalance($school, 15000);  // outstanding
    accountWithBalance($school, -8000);  // in credit
    accountWithBalance($school, 0);      // settled

    mcApi($token)->getJson('/api/v1/finance/accounts?status=outstanding')
        ->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.balance.amount_minor', 15000);
    mcApi($token)->getJson('/api/v1/finance/accounts?status=in_credit')
        ->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.balance.amount_minor', -8000);
    mcApi($token)->getJson('/api/v1/finance/accounts?status=settled')
        ->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.balance.amount_minor', 0);
});

it('ACCOUNTS SEARCH — a name term resolves through the ACL port to filter the list; no match is an empty page', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    $ada = accountWithBalance($school, 11000, 'Ada', 'Lovelace');
    accountWithBalance($school, 22000, 'Grace', 'Hopper');

    mcApi($token)->getJson('/api/v1/finance/accounts?search=Lovelace')
        ->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('data.0.student.uuid', $ada->uuid);

    // Search matching nobody is an EMPTY page — never a silent fallback to "all".
    mcApi($token)->getJson('/api/v1/finance/accounts?search=Nonexistent')
        ->assertJsonPath('pagination.total', 0);
});

it('ISOLATION — a bursar cannot bill an enrollment in another School (cross-school guard composes through the API)', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    [, $tokenA] = bursarWithToken($schoolA, ['finance.access']);
    $enrollmentB = acceptanceEnrollment($schoolB); // belongs to School B

    // School A's token, School B's enrollment → the GenerateInvoice cross-School guard
    // rejects it (422). Isolation is not just a scope on a query — it holds through the
    // whole HTTP stack.
    mcApi($tokenA)
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollmentB,
            'lines' => [['description' => 'Tuition', 'amount_minor' => 10000]],
        ])
        ->assertStatus(422);

    // And School B has no invoice as a result.
    expect(Invoice::withoutGlobalScopes()->where('school_id', $schoolB->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Ph3b — INVOICE VOID maker-checker (the second instance of the template)
|--------------------------------------------------------------------------
|
| Void is now a two-person lifecycle, not a one-step cancel: a maker SUBMITS a
| void request (the invoice is untouched — still issued, in the balance, holding
| its F7 slot; no money moves), and a checker ≠ maker APPROVES (which flips the
| invoice to void and posts the reversing ledger entry) or REJECTS (charge stands).
|
| These reuse the acceptance helpers (bursarWithToken / mcApi / mcInvoice / mcBalance)
| and prove the void surface end to end through the real HTTP stack, plus the nine
| numbered void proofs and the inherited maker-checker guarantees.
*/

/** @return array{0: string, 1: string} [voidMakerToken, voidCheckerToken] — distinct users, one side each. */
function voidTokens(School $school): array
{
    [, $maker] = bursarWithToken($school, ['finance.access', 'finance.invoice.void-request.submit']);
    [, $checker] = bursarWithToken($school, ['finance.access', 'finance.invoice.void-request.approve', 'finance.invoice.void-request.reject']);

    return [$maker, $checker];
}

/** Submit a void request (maker) over HTTP and return its uuid. */
function vrSubmit(string $token, string $invoiceUuid, string $reason = 'entered in error'): string
{
    return mcApi($token)
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/void-requests", ['reason' => $reason])
        ->assertCreated()->assertJsonPath('status', 'submitted')->json('id');
}

function vrRow(string $uuid): VoidRequest
{
    return VoidRequest::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
}

/** The number of REVERSAL ledger rows sourced to this invoice — the double-reverse detector. */
function vrReversalCount(string $invoiceUuid): int
{
    $invoiceId = Invoice::withoutGlobalScopes()->where('uuid', $invoiceUuid)->value('id');

    return (int) DB::table('finance_ledger_transactions')
        ->where('source_type', 'invoice')->where('source_id', $invoiceId)->where('type', 'reversal')->count();
}

// GATE — submitting a void request needs the MAKER permission; finance.access alone is 403.
it('VOID GATE — submitting a void request without the submit permission is 403 (has finance.access)', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']); // NOT the void submit permission
    $invoiceUuid = mcInvoice($token, acceptanceEnrollment($school));

    mcApi($token)
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/void-requests", ['reason' => 'x'])
        ->assertForbidden();
});

// PROOF 1 (of 9) — a PENDING void does NOT free the F7 slot: the episode is still one-active-invoice.
it('VOID PROOF 1 — a pending void request leaves the invoice ACTIVE, so the episode is still F7-locked', function () {
    $school = School::factory()->create();
    [$maker] = voidTokens($school);
    $enrollment = acceptanceEnrollment($school);
    $invoiceUuid = mcInvoice($maker, $enrollment);

    vrSubmit($maker, $invoiceUuid); // pending — invoice untouched

    // The invoice is still 'issued', so a SECOND invoice for the same episode is F7-rejected.
    mcApi($maker)->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $enrollment,
        'lines' => [['description' => 'Tuition', 'amount_minor' => 5000]],
    ])->assertStatus(422);

    $episodeId = DB::table('student_curricula')->where('uuid', $enrollment)->value('id');
    expect(DB::table('finance_invoices')->where('uuid', $invoiceUuid)->value('status'))->toBe('issued')
        ->and((int) DB::table('finance_invoices')->where('student_curriculum_id', $episodeId)->count())
        ->toBe(1); // still exactly one invoice for the episode
});

// PROOF 2 (of 9) — a PENDING void moves no money: zero reversal, balance unchanged.
it('VOID PROOF 2 — a pending void posts NO ledger entry and leaves the balance unchanged', function () {
    $school = School::factory()->create();
    [$maker] = voidTokens($school);
    $studentUuid = acceptanceStudentUuidFor($school, $enrollment = acceptanceEnrollment($school));
    $invoiceUuid = mcInvoice($maker, $enrollment);

    vrSubmit($maker, $invoiceUuid);

    expect(vrReversalCount($invoiceUuid))->toBe(0)
        ->and(mcBalance($school, $studentUuid))->toBe(10000); // still fully owed
});

// PROOF 3 (of 9) — APPROVAL returns the balance EXACTLY to its pre-invoice value.
it('VOID PROOF 3 — approval reverses the full total, returning the balance to its pre-invoice value', function () {
    $school = School::factory()->create();
    [$maker, $checker] = voidTokens($school);
    $studentUuid = acceptanceStudentUuidFor($school, $enrollment = acceptanceEnrollment($school));

    expect(mcBalance($school, $studentUuid))->toBe(0); // pre-invoice
    $invoiceUuid = mcInvoice($maker, $enrollment);
    expect(mcBalance($school, $studentUuid))->toBe(10000);

    $vr = vrSubmit($maker, $invoiceUuid);
    mcApi($checker)->postJson("/api/v1/finance/void-requests/{$vr}/approve")
        ->assertOk()->assertJsonPath('status', 'approved');

    expect(mcBalance($school, $studentUuid))->toBe(0)         // exactly back to pre-invoice
        ->and(vrReversalCount($invoiceUuid))->toBe(1)         // exactly one reversal
        ->and(DB::table('finance_invoices')->where('uuid', $invoiceUuid)->value('status'))->toBe('void');
});

// PROOF 4 (of 9) — DOUBLE-APPROVAL cannot double-reverse. Three independent guards.
it('VOID PROOF 4 — a second approval is refused and posts no second reversal (terminal + one-way + DB CHECK)', function () {
    $school = School::factory()->create();
    [$maker, $checker] = voidTokens($school);
    $studentUuid = acceptanceStudentUuidFor($school, $enrollment = acceptanceEnrollment($school));
    $invoiceUuid = mcInvoice($maker, $enrollment);
    $vr = vrSubmit($maker, $invoiceUuid);

    mcApi($checker)->postJson("/api/v1/finance/void-requests/{$vr}/approve")->assertOk();

    // Guard A — the request is TERMINAL (approved): a second approve is refused (422), and the
    //           TRANSITIONS map has no approved→approved edge.
    mcApi($checker)->postJson("/api/v1/finance/void-requests/{$vr}/approve")->assertStatus(422);

    // Guard B — the invoice is ONE-WAY void: even a brand-new request cannot be submitted (already void).
    mcApi($maker)->postJson("/api/v1/finance/invoices/{$invoiceUuid}/void-requests", ['reason' => 'again'])
        ->assertStatus(422);

    // Guard C — the DB CHECK: a raw write setting decided_by = submitted_by is refused structurally.
    $row = vrRow($vr);
    expect(fn () => DB::table('finance_void_requests')->where('id', $row->id)->update([
        'decided_by' => $row->submitted_by,
    ]))->toThrow(QueryException::class);

    // The money is intact: EXACTLY one reversal, balance back to pre-invoice (0), one reversal row only.
    expect(vrReversalCount($invoiceUuid))->toBe(1)
        ->and(mcBalance($school, $studentUuid))->toBe(0);
});

// PROOF 5 (of 9) — the precondition is RE-CHECKED authoritatively at approval: a payment that
//                  lands AFTER submit blocks the approval (the submit-time check is only advisory).
it('VOID PROOF 5 — a payment landing after submit blocks approval (authoritative re-check)', function () {
    $school = School::factory()->create();
    [$maker, $checker] = voidTokens($school);
    // The maker also holds finance.access, so it can record the payment (group permission only).
    $studentUuid = acceptanceStudentUuidFor($school, $enrollment = acceptanceEnrollment($school));
    $invoiceUuid = mcInvoice($maker, $enrollment);

    $vr = vrSubmit($maker, $invoiceUuid); // eligible at submit — no payment yet

    // …then a payment is allocated (the "race": state changes between submit and approve).
    mcApi($maker)->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments",
        ['amount_minor' => 10000, 'payer_name' => 'Mr Obi'])->assertCreated();

    // Approval re-checks and REFUSES — the invoice is now settled, not cleanly voidable.
    mcApi($checker)->postJson("/api/v1/finance/void-requests/{$vr}/approve")->assertStatus(422);

    expect(vrReversalCount($invoiceUuid))->toBe(0)                                   // no reversal
        ->and(vrRow($vr)->status)->toBe(VoidRequestStatus::Submitted)                 // still pending, not decided
        ->and(DB::table('finance_invoices')->where('uuid', $invoiceUuid)->value('status'))->toBe('issued');
});

// PROOF 6 (of 9) — an invoice with an APPROVED credit note cannot be voided — refused at BOTH submit and approve.
it('VOID PROOF 6 — an approved credit note against the invoice blocks the void at submit AND at approve', function () {
    $school = School::factory()->create();
    [$cnMaker] = bursarWithToken($school, ['finance.access', 'finance.credit-note.submit']);
    [$cnChecker] = bursarWithToken($school, ['finance.access', 'finance.credit-note.approve']);
    [$maker, $checker] = voidTokens($school);
    $enrollment = acceptanceEnrollment($school);
    $invoiceUuid = mcInvoice($maker, $enrollment);

    // Submit a void FIRST (eligible now), then land an approved credit note before it is decided.
    $vr = vrSubmit($maker, $invoiceUuid);

    ActiveSchool::runFor($school->id, function () use ($invoiceUuid, $cnMaker, $cnChecker) {
        $invoice = Invoice::withoutGlobalScopes()->where('uuid', $invoiceUuid)->firstOrFail();
        $note = app(SubmitCreditNote::class)->handle($invoice, Money::fromKobo(3000), CreditNoteKind::CreditNote, null, $cnMaker);
        app(ApproveCreditNote::class)->handle($note, $cnChecker);
    });

    // Approval of the pending void now RE-CHECKS and refuses (approved credit note present).
    mcApi($checker)->postJson("/api/v1/finance/void-requests/{$vr}/approve")->assertStatus(422);

    // And a FRESH submit is also refused up front (advisory guard at submit).
    mcApi($maker)->postJson("/api/v1/finance/invoices/{$invoiceUuid}/void-requests", ['reason' => 'x'])
        ->assertStatus(422);

    expect(vrReversalCount($invoiceUuid))->toBe(0)
        ->and(DB::table('finance_invoices')->where('uuid', $invoiceUuid)->value('status'))->toBe('issued');
});

// PROOF 7 (of 9) — the episode frees ONLY after approval: a fresh bill succeeds post-void, not before.
it('VOID PROOF 7 — the F7 slot frees only after approval, letting the episode be billed fresh', function () {
    $school = School::factory()->create();
    [$maker, $checker] = voidTokens($school);
    $enrollment = acceptanceEnrollment($school);
    $invoiceUuid = mcInvoice($maker, $enrollment);
    $vr = vrSubmit($maker, $invoiceUuid);

    // Still locked while pending (proven in PROOF 1) — approve, then the same episode bills fresh.
    mcApi($checker)->postJson("/api/v1/finance/void-requests/{$vr}/approve")->assertOk();

    mcApi($maker)->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $enrollment,
        'lines' => [['description' => 'Re-bill', 'amount_minor' => 5000]],
    ])->assertCreated();

    // Two invoice rows for the episode (append-only), exactly one ACTIVE (non-void).
    $episodeId = DB::table('student_curricula')->where('uuid', $enrollment)->value('id');
    expect((int) DB::table('finance_invoices')->where('student_curriculum_id', $episodeId)->count())->toBe(2)
        ->and((int) DB::table('finance_invoices')->where('student_curriculum_id', $episodeId)->whereNotNull('active_enrollment_key')->count())->toBe(1);
});

// PROOF 8 (of 9) — ONE OPEN REQUEST per invoice: a second pending void is refused (app + DB UNIQUE).
it('VOID PROOF 8 — a second open void request for the same invoice is rejected (one-open-request)', function () {
    $school = School::factory()->create();
    [$maker] = voidTokens($school);
    $invoiceUuid = mcInvoice($maker, acceptanceEnrollment($school));

    vrSubmit($maker, $invoiceUuid); // first pending

    // App-level friendly refusal.
    mcApi($maker)->postJson("/api/v1/finance/invoices/{$invoiceUuid}/void-requests", ['reason' => 'second'])
        ->assertStatus(422);

    // DB-level backstop: a raw INSERT of a second SUBMITTED row for the same invoice hits the
    // generated-column UNIQUE (school_id, open_key). Proves the guarantee is structural, not app-only.
    $invoiceId = DB::table('finance_invoices')->where('uuid', $invoiceUuid)->value('id');
    $row = VoidRequest::withoutGlobalScopes()->where('invoice_id', $invoiceId)->firstOrFail();

    expect(fn () => DB::table('finance_void_requests')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $row->school_id,
        'invoice_id' => $row->invoice_id,
        'reason' => 'raw second',
        'status' => 'submitted',
        'submitted_by' => $row->submitted_by,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect((int) DB::table('finance_void_requests')->where('invoice_id', $row->invoice_id)->count())->toBe(1);
});

// BITE-PROOF — the update guard actually BITES: identity/reason are frozen and DELETE is denied
// on a raw write, but the decision columns still move (SchemaConventionsTest only proves the
// trigger EXISTS by name; this proves it does the right thing).
it('VOID BITE-PROOF — the request update guard freezes identity/reason and denies delete, but permits the decision', function () {
    $school = School::factory()->create();
    [$maker, $checker] = voidTokens($school);
    $invoiceUuid = mcInvoice($maker, acceptanceEnrollment($school));
    $row = vrRow(vrSubmit($maker, $invoiceUuid));

    // Frozen columns → a raw UPDATE is refused by the trigger; the row is un-deletable.
    expect(fn () => DB::table('finance_void_requests')->where('id', $row->id)->update(['reason' => 'tampered']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_void_requests')->where('id', $row->id)->update(['submitted_by' => null]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_void_requests')->where('id', $row->id)->delete())
        ->toThrow(QueryException::class);

    // …but the DECISION columns DO move — a legitimate approval through the Action succeeds, so
    // the guard is a money/identity freeze, not a blanket lock (that would break the whole flow).
    mcApi($checker)->postJson("/api/v1/finance/void-requests/{$row->uuid}/approve")->assertOk();
    expect(vrRow($row->uuid)->status->value)->toBe('approved');
});

// PROOF 9 (of 9) — the one-step cancel path is GONE: no route, no enum case, no live grant.
it('VOID PROOF 9 — the one-step invoice cancel is fully retired (route + permission both absent)', function () {
    // The route no longer exists → 405/404 (never a 2xx). We assert it is not routable.
    $registered = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn ($uri) => str_contains($uri, 'finance/invoices') && str_ends_with($uri, 'cancel'));
    expect($registered)->toBeEmpty();

    // No enum case named for a one-step cancel.
    $names = array_map(fn ($c) => $c->value, App\Enums\Permission::cases());
    expect($names)->not->toContain('finance.invoice.cancel');

    // The void maker/checker permissions DO exist and are wired by the convention.
    expect($names)->toContain('finance.invoice.void-request.submit')
        ->and($names)->toContain('finance.invoice.void-request.approve')
        ->and(ApprovalAbility::matchingMakerFor('finance.invoice.void-request.approve'))
        ->toBe('finance.invoice.void-request.submit');
});

// INHERITED — the MAKER cannot approve their OWN void (Policy 403), even holding approve.
it('VOID INHERITED — a maker who also holds approve is FORBIDDEN from approving their own void request', function () {
    $school = School::factory()->create();
    [, $both] = bursarWithToken($school, [
        'finance.access', 'finance.invoice.void-request.submit', 'finance.invoice.void-request.approve',
    ]);
    $invoiceUuid = mcInvoice($both, acceptanceEnrollment($school));
    $vr = vrSubmit($both, $invoiceUuid);

    mcApi($both)->postJson("/api/v1/finance/void-requests/{$vr}/approve")->assertForbidden();

    expect(vrReversalCount($invoiceUuid))->toBe(0)
        ->and(DB::table('finance_invoices')->where('uuid', $invoiceUuid)->value('status'))->toBe('issued');
});

// INHERITED — a super_admin cannot approve/reject (the checker bypass-exclusion holds for void too).
it('VOID INHERITED — a super_admin cannot approve a void request (bypass-exclusion holds)', function () {
    $school = School::factory()->create();
    [$maker] = voidTokens($school);
    $invoiceUuid = mcInvoice($maker, acceptanceEnrollment($school));
    $vr = vrSubmit($maker, $invoiceUuid);

    $super = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $superToken = $super->createToken('acceptance');
    $superToken->accessToken->forceFill(['school_id' => $school->id])->save();

    mcApi($superToken->plainTextToken)
        ->postJson("/api/v1/finance/void-requests/{$vr}/approve")
        ->assertForbidden();

    expect(vrReversalCount($invoiceUuid))->toBe(0);
});

// INHERITED — REJECT leaves the invoice standing, posts no money, and requires a reason.
it('VOID INHERITED — reject leaves the invoice issued with no money moved; empty reason is refused', function () {
    $school = School::factory()->create();
    [$maker, $checker] = voidTokens($school);
    $studentUuid = acceptanceStudentUuidFor($school, $enrollment = acceptanceEnrollment($school));
    $invoiceUuid = mcInvoice($maker, $enrollment);
    $vr = vrSubmit($maker, $invoiceUuid);

    // Empty reason → 422 (request validation).
    mcApi($checker)->postJson("/api/v1/finance/void-requests/{$vr}/reject", ['reason' => ''])
        ->assertStatus(422);

    mcApi($checker)->postJson("/api/v1/finance/void-requests/{$vr}/reject", ['reason' => 'Charge is valid'])
        ->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('rejection_reason', 'Charge is valid');

    expect(vrReversalCount($invoiceUuid))->toBe(0)
        ->and(mcBalance($school, $studentUuid))->toBe(10000)  // fully owed still
        ->and(DB::table('finance_invoices')->where('uuid', $invoiceUuid)->value('status'))->toBe('issued');
});

/** The student uuid behind an enrollment uuid — for balance assertions. */
function acceptanceStudentUuidFor(School $school, string $enrollmentUuid): string
{
    $studentId = DB::table('student_curricula')->where('uuid', $enrollmentUuid)->value('student_id');

    return (string) Student::query()->where('school_id', $school->id)->where('id', $studentId)->value('uuid');
}
