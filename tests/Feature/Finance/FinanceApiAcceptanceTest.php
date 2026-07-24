<?php

use App\Finance\Models\Invoice;
use App\Finance\Models\StudentAccount;
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
    $this->withToken($token)
        ->getJson("/api/v1/finance/students/{$studentUuid}/billable-enrollment")
        ->assertOk()
        ->assertJsonPath('already_invoiced', false)
        ->assertJsonStructure(['academic_context', 'already_invoiced']);

    // Generate by STUDENT — NO enrollment_id on the wire. A charge + a discount reduction;
    // the total is DERIVED server-side (F6): 50000 − 5000 = 45000.
    $this->withToken($token)
        ->postJson("/api/v1/finance/students/{$studentUuid}/invoices", [
            'lines' => [
                ['description' => 'Tuition', 'amount_minor' => 50000, 'kind' => 'charge'],
                ['description' => 'Sibling discount', 'amount_minor' => -5000, 'kind' => 'discount'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('total.amount_minor', 45000);

    // It appears on the statement at its full derived total.
    $this->withToken($token)
        ->getJson("/api/v1/finance/students/{$studentUuid}/invoices")
        ->assertOk()
        ->assertJsonPath('invoices.0.total.amount_minor', 45000);

    // F7 preview now true; a second generate for the SAME episode is rejected (422).
    $this->withToken($token)
        ->getJson("/api/v1/finance/students/{$studentUuid}/billable-enrollment")
        ->assertJsonPath('already_invoiced', true);
    $this->withToken($token)
        ->postJson("/api/v1/finance/students/{$studentUuid}/invoices", [
            'lines' => [['description' => 'Tuition', 'amount_minor' => 10000]],
        ])
        ->assertStatus(422);
});

it('NO ENROLLMENT — a student with no active enrollment cannot be billed (422 on read and write)', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    $student = Student::factory()->create(['school_id' => $school->id]); // no enrollment

    $this->withToken($token)
        ->getJson("/api/v1/finance/students/{$student->uuid}/billable-enrollment")
        ->assertStatus(422);
    $this->withToken($token)
        ->postJson("/api/v1/finance/students/{$student->uuid}/invoices", [
            'lines' => [['description' => 'Tuition', 'amount_minor' => 1000]],
        ])
        ->assertStatus(422);
});

it('PAYMENTS — appear on the statement read as their own history (Piece B)', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    $studentUuid = acceptanceStudent($school);

    $invoiceUuid = $this->withToken($token)
        ->postJson("/api/v1/finance/students/{$studentUuid}/invoices", [
            'lines' => [['description' => 'Tuition', 'amount_minor' => 10000]],
        ])->assertCreated()->json('id');

    $this->withToken($token)
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments", [
            'amount_minor' => 4000,
            'payer_name' => 'A Parent',
        ])->assertCreated();

    $this->withToken($token)
        ->getJson("/api/v1/finance/students/{$studentUuid}/invoices")
        ->assertOk()
        ->assertJsonPath('payments.0.amount.amount_minor', 4000)
        ->assertJsonPath('payments.0.payer_name', 'A Parent')
        ->assertJsonStructure(['payments' => [['id', 'reference', 'method', 'amount', 'created_at']]]);
});

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

it('ACCOUNTS INDEX — lists the School\'s accounts with LIVE student display, and every row carries the statement link', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    $student = accountWithBalance($school, 45000, 'Ada', 'Lovelace'); // owes ₦450.00

    $body = $this->withToken($token)
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
    $this->withToken($token)->getJson('/api/v1/finance/accounts')
        ->assertJsonPath('data.0.student.name', 'Augusta Lovelace');
});

it('ACCOUNTS INDEX — paginates at 20 rows a page', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    foreach (range(1, 21) as $i) {
        accountWithBalance($school, $i * 1000);
    }

    $this->withToken($token)->getJson('/api/v1/finance/accounts')
        ->assertJsonPath('pagination.total', 21)
        ->assertJsonPath('pagination.per_page', 20)
        ->assertJsonPath('pagination.last_page', 2)
        ->assertJsonCount(20, 'data');

    $this->withToken($token)->getJson('/api/v1/finance/accounts?page=2')
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
    $this->withToken($token)->getJson('/api/v1/finance/accounts')
        ->assertJsonPath('kpis.total_receivables.amount_minor', 50000)
        ->assertJsonPath('kpis.total_credit.amount_minor', 5000);

    // A status filter narrows the LIST, but the KPIs are the School-wide denominator the
    // filtered view is read against — they must NOT move with the filter.
    $this->withToken($token)->getJson('/api/v1/finance/accounts?status=in_credit')
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

    $this->withToken($tokenA)->getJson('/api/v1/finance/accounts')
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

    $this->withToken($token)->getJson('/api/v1/finance/accounts?status=outstanding')
        ->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.balance.amount_minor', 15000);
    $this->withToken($token)->getJson('/api/v1/finance/accounts?status=in_credit')
        ->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.balance.amount_minor', -8000);
    $this->withToken($token)->getJson('/api/v1/finance/accounts?status=settled')
        ->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.balance.amount_minor', 0);
});

it('ACCOUNTS SEARCH — a name term resolves through the ACL port to filter the list; no match is an empty page', function () {
    $school = School::factory()->create();
    [, $token] = bursarWithToken($school, ['finance.access']);
    $ada = accountWithBalance($school, 11000, 'Ada', 'Lovelace');
    accountWithBalance($school, 22000, 'Grace', 'Hopper');

    $this->withToken($token)->getJson('/api/v1/finance/accounts?search=Lovelace')
        ->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('data.0.student.uuid', $ada->uuid);

    // Search matching nobody is an EMPTY page — never a silent fallback to "all".
    $this->withToken($token)->getJson('/api/v1/finance/accounts?search=Nonexistent')
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
    $this->withToken($tokenA)
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollmentB,
            'lines' => [['description' => 'Tuition', 'amount_minor' => 10000]],
        ])
        ->assertStatus(422);

    // And School B has no invoice as a result.
    expect(Invoice::withoutGlobalScopes()->where('school_id', $schoolB->id)->count())->toBe(0);
});
