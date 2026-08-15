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
 * U8 commit 3 — the reduction guard's refusals become FIELD errors.
 *
 * WHAT THIS SUITE IS ABOUT IS THE `errors` KEY, NOT THE STATUS CODE. Measured on the commit's base
 * (833ba97) before a line was written: every edge-reachable arm of
 * finance_invoice_lines_reduction_guard already answered 422, never 500 — GenerateInvoice's 1644
 * catch converts all five of the trigger's messages, because each contains the substring "discount
 * policy" that isReductionGuardViolation matches on. What it answered WITH was
 * `{"message": "<the trigger's own sentence>"}` and no `errors` key, which a form cannot attach to a
 * field. So every arm below asserts the KEY. A bare assertStatus(422) would have passed on the base
 * commit and proves nothing here.
 *
 * The trigger is untouched and stays the authority: each arm's refusal is re-refused one layer down,
 * and ReductionEnforcementTest's proof 12 (DB) / proof 14 (DB) still reach it by raw insert.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: StudentCurriculum} — admin holds generate + reduction.apply. */
function rpcSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'rpc_admin', 'guard_name' => 'web']);
    foreach (['finance.access', 'finance.invoice.generate', 'finance.invoice.reduction.apply'] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $role->syncPermissions(['finance.access', 'finance.invoice.generate', 'finance.invoice.reduction.apply']);
    $admin->assignRole('rpc_admin');
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $student = Student::factory()->create(['school_id' => $school->id]);
    $enrollment = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));

    return [$school, $admin, $enrollment];
}

function rpcPolicy(School $school, bool $requiresApproval = false, string $status = 'active', string $name = 'Sibling'): DiscountPolicy
{
    return ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create([
        'school_id' => $school->id, 'name' => $name, 'basis' => 'percent', 'percent' => 10,
        'requires_approval' => $requiresApproval, 'status' => $status,
    ]));
}

function rpcPost($test, School $school, User $admin, StudentCurriculum $enrollment, array $lines)
{
    return $test->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => $lines]);
}

/**
 * The OTHER route, and the one that matters most — POST /v1/finance/students/{student:uuid}/invoices,
 * InvoiceController::generateForStudent.
 *
 * IT IS THE ONLY INVOICE-GENERATION ROUTE THE RUNNING UI USES. `new-invoice-modal.tsx:133` posts
 * `generateForStudent.url(student.uuid)`, and it is the sole hand-written import of an
 * invoice-generation action under resources/js — `statement.tsx` imports only `forStudent`, a read.
 * routes/endpoints/finance.php:222-225 says the same from the other side: the student POST is "the
 * bursar UI's path" and the enrollment-id POST "stays for the harness".
 *
 * Every arm below therefore exists because the pre-check landed on this route with NO test on it. The
 * four `FinanceApiAcceptanceTest` posts to it all carry payloads the pre-check accepts, so deleting
 * InvoiceController.php:83 left the whole suite green.
 */
function rpcPostForStudent($test, School $school, User $admin, StudentCurriculum $enrollment, array $lines)
{
    $student = ActiveSchool::runFor($school->id, fn () => Student::query()->findOrFail($enrollment->student_id));

    return $test->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/invoices", ['lines' => $lines]);
}

/**
 * NOTHING WAS WRITTEN — asserted as counts, not as "the status was 422". A refusal that 422s and
 * still leaves a row behind is the failure mode this is here to catch, and the status alone cannot
 * see it. Both tables, because the invoice and its lines are created in one transaction and a
 * half-written pair is exactly what a broken refusal would leave.
 */
function rpcAssertNothingWritten(): void
{
    expect(DB::table('finance_invoices')->count())->toBe(0)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(0);
}

// ── Arm 1 — a reduction line citing no policy ────────────────────────────────

it('arm 1 — a reduction line with NO discount policy is a field error on that line', function () {
    // THE LIKELIEST MISTAKE THE COMING FORM CAN MAKE. U8 commit 1 ruled that `""` is "no provenance"
    // (ConvertEmptyStringsToNull rewrites it before any rule can see it), and an unselected <select>
    // posts `""` — so a bursar who opens the policy dropdown and picks nothing sends exactly this.
    [$school, $admin, $enrollment] = rpcSetup();

    $response = rpcPost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Free-text discount', 'amount_minor' => -10000, 'kind' => 'discount'],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    // Operator wording, not the trigger's sentence about credit notes and reduction lines.
    expect((string) $response->json('errors')['lines.1.discount_policy_id'][0])
        ->toContain('Select the discount policy that authorises this reduction');

    rpcAssertNothingWritten();
});

it('arm 1 — the same refusal for an EMPTY-STRING policy, keyed to the same field', function () {
    // The `""` spelling asserted separately from the absent spelling, because U8 commit 1's ruling is
    // that they are the SAME thing and a single-value arm cannot say that. InvoiceWireIdsTest pins the
    // rewrite; this pins that the rewritten value lands on the same field error.
    [$school, $admin, $enrollment] = rpcSetup();

    rpcPost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Unselected select', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => ''],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    rpcAssertNothingWritten();
});

// ── Arm 2 — the cited policy is not active ───────────────────────────────────

it('arm 2 — a reduction citing a RETIRED policy is a field error on that line', function () {
    [$school, $admin, $enrollment] = rpcSetup();
    $policy = rpcPolicy($school, status: 'retired', name: 'Retired');

    $response = rpcPost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    expect((string) $response->json('errors')['lines.1.discount_policy_id'][0])
        ->toContain('no longer active');

    rpcAssertNothingWritten();
});

it('arm 2 — a SUPERSEDED policy is refused the same way (status is not a two-value column)', function () {
    // Retired and superseded are distinct states and the trigger tests `<> 'active'`, not `= 'retired'`.
    // Asserted separately so a pre-check that only ever checked for 'retired' would be visible here.
    [$school, $admin, $enrollment] = rpcSetup();
    $policy = rpcPolicy($school, status: 'superseded', name: 'Superseded');

    rpcPost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    rpcAssertNothingWritten();
});

// ── Arm 3 — the cited policy requires per-application approval ───────────────

it('arm 3 — a reduction citing a requires_approval policy is a field error on that line', function () {
    [$school, $admin, $enrollment] = rpcSetup();
    $policy = rpcPolicy($school, requiresApproval: true, name: 'NeedsApproval');

    $response = rpcPost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    expect((string) $response->json('errors')['lines.1.discount_policy_id'][0])
        ->toContain('credit note');

    rpcAssertNothingWritten();
});

// ── Arm 5 — a CHARGE line carrying a policy ──────────────────────────────────

it('arm 5 — a charge line carrying a discount policy is a field error on that line', function () {
    // Index 0, not 1 — the offending line here is the charge itself. The field is
    // discount_policy_id rather than kind because clearing the policy is the operator's fix.
    [$school, $admin, $enrollment] = rpcSetup();
    $policy = rpcPolicy($school);

    $response = rpcPost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'discount_policy_id' => $policy->uuid],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.0.discount_policy_id');

    expect((string) $response->json('errors')['lines.0.discount_policy_id'][0])
        ->toContain('A charge line cannot carry a discount policy');

    rpcAssertNothingWritten();
});

// ── Arm 4 — NOT pre-checked, and this arm records why ────────────────────────

it('arm 4 — a foreign School’s policy is refused EARLIER than the pre-check, by SchoolScope', function () {
    // NO PRE-CHECK ARM EXISTS FOR THE TRIGGER'S `v_school <> NEW.school_id` BRANCH, because it cannot
    // fire from this edge. SchoolScope hides School B's policy under School A's context, so the uuid
    // fails the EXISTENCE rule in GenerateInvoiceRequest and never reaches assertDiscountPoliciesUsable.
    // This arm pins that ordering: if the pre-check ever started answering this case, the message here
    // would change and the isolation story in the method's docblock would need rewriting.
    [$schoolA, $adminA, $enrollmentA] = rpcSetup();
    $schoolB = School::factory()->create();
    $policyB = rpcPolicy($schoolB, name: 'B-only'); // active, no approval — only its School differs

    $response = rpcPost($this, $schoolA, $adminA, $enrollmentA, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Foreign discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policyB->uuid],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    // The EXISTENCE rule's message, not one of the pre-check's. This is the byte-identical property
    // U8 commit 1 holds (a foreign uuid and a nonexistent one are indistinguishable): the pre-check
    // must not have added a way to tell them apart, and a distinct message here would be exactly that.
    expect((string) $response->json('errors')['lines.1.discount_policy_id'][0])
        ->toBe('The selected lines.1.discount_policy_id is invalid.');

    rpcAssertNothingWritten();
});

it('arm 4 — a super_admin with NO School context: an ACCEPTABLE policy leaves the context refusal first', function () {
    // The other path to the trigger's cross-School branch, closed one layer further in.
    // FeeItem and DiscountPolicy are not in config/rbac.php `fail_closed_models`, so with no active
    // School SchoolScope adds no predicate and the uuid resolves unscoped — the pre-check therefore
    // sees a real, active, no-approval policy and passes it, and GenerateInvoice:100 refuses the
    // request before the transaction opens.
    //
    // THE TITLE NOW SAYS "AN ACCEPTABLE POLICY", because that qualifier is load-bearing and this arm
    // used to hide it. It plants an active, no-approval policy, so it exercises only the case where
    // the pre-check has nothing to say. The case where it DOES is the arm directly below, and the two
    // together are what the docblock's second bullet is allowed to claim.
    [$schoolA, , $enrollmentA] = rpcSetup();
    $policy = rpcPolicy($schoolA, name: 'A-only');

    setPermissionsTeamId(null);
    $super = User::factory()->create();
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();

    $response = $this->actingAs($super)->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $enrollmentA->uuid,
        'lines' => [
            ['description' => 'Tuition', 'amount_minor' => 100000],
            ['description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
        ],
    ])->assertStatus(422);

    expect((string) $response->json('message'))->toBe('No active School context: an invoice cannot be raised.');
    expect($response->json('errors'))->toBeNull(
        'The pre-check refused this request. It must not: the policy is active, needs no approval, and '
        .'belongs to the School whose enrollment is being billed. If this becomes true the pre-check has '
        .'started answering a case the docblock says it cannot see.');

    rpcAssertNothingWritten();
});

it('arm 4 — a super_admin with NO School context: a RETIRED policy is answered by the PRE-CHECK first', function () {
    // THE ORDERING THIS COMMIT ACTUALLY CREATED, measured rather than assumed, and the arm the one
    // above could not be. The pre-check runs at InvoiceController:39, the context refusal inside the
    // Action at GenerateInvoice:100 — so once the pre-check has something to say, it says it first.
    //
    // A super_admin with no School selected is the only principal who reaches this (SetSchoolContext:46
    // redirects everyone else), the policy resolves unscoped for them, and the response is the field
    // error rather than "No active School context". Body, raw:
    //
    //   {"message":"There are validation errors","errors":{"lines.1.discount_policy_id":
    //    ["That discount policy is no longer active, so it cannot back a new reduction. Choose a current one."]}}
    //
    // This is written down because the pre-check's docblock previously claimed the context refusal
    // always wins, and it does not. Nothing is written either way, and arm 4 of the trigger is still
    // unreachable — what changed is which sentence the operator gets, and that a policy's lifecycle
    // state is now visible to a principal holding no School context. Pinned so the next reader finds
    // the ordering asserted rather than described.
    [$schoolA, , $enrollmentA] = rpcSetup();
    $policy = rpcPolicy($schoolA, status: 'retired', name: 'A-retired');

    setPermissionsTeamId(null);
    $super = User::factory()->create();
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();

    $response = $this->actingAs($super)->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $enrollmentA->uuid,
        'lines' => [
            ['description' => 'Tuition', 'amount_minor' => 100000],
            ['description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    expect((string) $response->json('errors')['lines.1.discount_policy_id'][0])
        ->toBe('That discount policy is no longer active, so it cannot back a new reduction. Choose a current one.');

    // NOT the Action's message — asserted explicitly, because "which layer answered" is the whole
    // subject of this arm and a bare 422 cannot distinguish them.
    expect((string) $response->json('message'))->not->toBe('No active School context: an invoice cannot be raised.');

    rpcAssertNothingWritten();
});

// ── The SAME arms over the route the running UI actually posts to ────────────
//
// Not a copy for symmetry. InvoiceController has TWO call sites of the pre-check (:39 and :83) and
// they are independent lines: everything above exercises :39 only, so :83 could be deleted with the
// whole suite staying green. These arms are what makes the watched red attributable per call site.

it('student route — arm 1: a reduction with NO policy is a field error on that line', function () {
    // THIS WAS THE CASE THE LIVE UI PRODUCED, AND AS OF U8 COMMIT 4 IT IS NOT. Written when
    // new-invoice-modal.tsx sent only description, amount_minor and kind while offering `waiver` and
    // `discount` in its kind select — so every reduction that screen could submit was this arm. The
    // modal now sends `discount_policy_id` on every reduction line (wireLine()), so the shape it
    // produces when nothing is picked is the EMPTY STRING, not an absent key. That is the arm
    // immediately below; this one keeps the absent-key case, which is still what a hand-built payload
    // or any non-browser caller sends. Line numbers deliberately not re-cited: the previous ones went
    // stale inside one commit.
    [$school, $admin, $enrollment] = rpcSetup();

    $response = rpcPostForStudent($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Free-text discount', 'amount_minor' => -10000, 'kind' => 'discount'],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    expect((string) $response->json('errors')['lines.1.discount_policy_id'][0])
        ->toContain('Select the discount policy that authorises this reduction');

    rpcAssertNothingWritten();
});

it('student route — arm 1: the EMPTY-STRING policy the modal posts when nothing is picked', function () {
    // THE EXACT PAYLOAD THE RUNNING UI NOW PRODUCES (U8 commit 4). new-invoice-modal.tsx's wireLine()
    // puts `discount_policy_id` on every reduction line and sends `line.discountPolicyId` AS IS, so an
    // untouched policy select posts `""` rather than omitting the key. The equivalent arm on the
    // enrollment-id route already existed; this route had only the absent-key one, and the two travel
    // through different code — `""` is rewritten to null by ConvertEmptyStringsToNull, which is a
    // GLOBAL MIDDLEWARE and therefore a dependency of this behaviour that no test on this route
    // pinned. Remove it from the stack and this arm is the one that notices.
    //
    // The modal deliberately does NOT block this client-side. The point of U8 commit 3 was that the
    // server names the offending line; a client that refuses first would hide whether it still does.
    [$school, $admin, $enrollment] = rpcSetup();

    $response = rpcPostForStudent($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'kind' => 'charge'],
        ['description' => 'Sibling discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => ''],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    expect((string) $response->json('errors')['lines.1.discount_policy_id'][0])
        ->toContain('Select the discount policy that authorises this reduction');

    // NOT the "invalid" message the existence rule would produce if `""` ever reached it as a literal
    // uuid candidate. Asserted because the two refusals are both 422s on the same field, and only the
    // text tells them apart — which is what says the empty string became "no provenance" rather than
    // "a malformed id".
    expect((string) $response->json('errors')['lines.1.discount_policy_id'][0])
        ->not->toContain('is invalid');

    rpcAssertNothingWritten();
});

it('student route — the ACCEPTED payload has the modal’s exact shape, key for key', function () {
    // The success direction of the same contract. Every other passing arm posts a payload a human
    // composed; this one posts what wireLine() emits — `discount_policy_id` present on the reduction
    // line and ABSENT on the charge line — and asserts the stored provenance on both.
    //
    // THE CHARGE LINE'S ABSENT KEY IS THE HALF THAT MATTERS. It is the observable end of the
    // charge → discount → pick a policy → back to charge transition: if patchForKind() stopped
    // clearing `discountPolicyId`, or wireLine() stopped omitting the key on a charge, this payload
    // would instead carry the policy on line 0 and the request would be refused by arm 5. Nothing in
    // JavaScript can be tested on this platform (docs/handoff/tickets/no-javascript-test-runner.md),
    // so this arm pins the payload SHAPE the modal is supposed to produce, from the server's side.
    // It cannot see whether the modal still produces it.
    [$school, $admin, $enrollment] = rpcSetup();
    $policy = rpcPolicy($school);

    rpcPostForStudent($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'kind' => 'charge'],
        ['description' => 'Sibling discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertCreated();

    $charge = DB::table('finance_invoice_lines')->where('kind', 'charge')->first();
    $reduction = DB::table('finance_invoice_lines')->where('kind', 'discount')->first();

    expect($charge->discount_policy_id)->toBeNull()
        ->and((int) $reduction->discount_policy_id)->toBe($policy->id);
});

it('student route — arm 2: a reduction citing a RETIRED policy is a field error on that line', function () {
    [$school, $admin, $enrollment] = rpcSetup();
    $policy = rpcPolicy($school, status: 'retired', name: 'Retired');

    $response = rpcPostForStudent($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    expect((string) $response->json('errors')['lines.1.discount_policy_id'][0])->toContain('no longer active');

    rpcAssertNothingWritten();
});

it('student route — arm 3: a reduction citing a requires_approval policy is a field error on that line', function () {
    [$school, $admin, $enrollment] = rpcSetup();
    $policy = rpcPolicy($school, requiresApproval: true, name: 'NeedsApproval');

    $response = rpcPostForStudent($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    expect((string) $response->json('errors')['lines.1.discount_policy_id'][0])->toContain('credit note');

    rpcAssertNothingWritten();
});

it('student route — arm 5: a charge line carrying a discount policy is a field error on that line', function () {
    [$school, $admin, $enrollment] = rpcSetup();
    $policy = rpcPolicy($school);

    $response = rpcPostForStudent($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'discount_policy_id' => $policy->uuid],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.0.discount_policy_id');

    expect((string) $response->json('errors')['lines.0.discount_policy_id'][0])
        ->toContain('A charge line cannot carry a discount policy');

    rpcAssertNothingWritten();
});

it('student route — a student with NO enrollment gets the ENROLLMENT refusal, not the policy one', function () {
    // THE ORDERING FIX. The pre-check used to run above the `$enrollment === null` check, so a student
    // who could not be billed at all was told a discount policy was retired — a message that sends the
    // bursar to fix the wrong thing on an invoice that cannot exist. Measured before the reorder:
    //
    //   {"message":"There are validation errors","errors":{"lines.1.discount_policy_id":
    //    ["That discount policy is no longer active, so it cannot back a new reduction. Choose a current one."]}}
    //
    // The payload is deliberately bad in BOTH ways at once — no billable episode AND a retired policy —
    // because that is the only shape that can tell the two orderings apart.
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'rpc_admin', 'guard_name' => 'web']);
    foreach (['finance.access', 'finance.invoice.generate', 'finance.invoice.reduction.apply'] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $role->syncPermissions(['finance.access', 'finance.invoice.generate', 'finance.invoice.reduction.apply']);
    $admin->assignRole('rpc_admin');
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // No StudentCurriculum at all — nothing to bill. rpcSetup() cannot be reused; it creates one.
    $student = Student::factory()->create(['school_id' => $school->id]);
    $policy = rpcPolicy($school, status: 'retired', name: 'Retired');

    $response = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/invoices", ['lines' => [
            ['description' => 'Tuition', 'amount_minor' => 100000],
            ['description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
        ]])->assertStatus(422);

    expect((string) $response->json('message'))->toBe('This student has no active enrollment to bill.');

    // AND NOT the pre-check's shape. Asserted explicitly: a bare 422 passes under either ordering, so
    // the absence of `errors` is what actually pins which refusal won.
    expect($response->json('errors'))->toBeNull(
        'The pre-check answered a request for a student with nothing to bill. The enrollment refusal '
        .'is the more fundamental one and must come first on this route.');

    rpcAssertNothingWritten();
});

it('student route — an ACTIVE, no-approval policy still generates the invoice', function () {
    // The other direction on this route too, for the same reason it exists on the other one: without
    // it, a pre-check that refused every reduction would turn all four arms above green.
    [$school, $admin, $enrollment] = rpcSetup();
    $policy = rpcPolicy($school);

    rpcPostForStudent($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Sibling discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertCreated();

    expect(DB::table('finance_invoices')->count())->toBe(1)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(2);

    $reduction = DB::table('finance_invoice_lines')->where('kind', 'discount')->first();
    expect((int) $reduction->discount_policy_id)->toBe($policy->id);
});

// ── The other direction — the pre-check is not refusing everything ───────────

it('a reduction citing an ACTIVE, no-approval policy of this School still generates the invoice', function () {
    // WITHOUT THIS ARM THE PRE-CHECK COULD REFUSE EVERY REDUCTION AND THE SUITE ABOVE WOULD NOT NOTICE:
    // every arm there asserts a refusal, so a `throw` at the top of assertDiscountPoliciesUsable() would
    // turn them all green. This is the arm that fails in that case.
    [$school, $admin, $enrollment] = rpcSetup();
    $policy = rpcPolicy($school);

    rpcPost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Sibling discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertCreated();

    expect(DB::table('finance_invoices')->count())->toBe(1)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(2);

    $reduction = DB::table('finance_invoice_lines')->where('kind', 'discount')->first();
    expect((int) $reduction->discount_policy_id)->toBe($policy->id);
});

it('a charge-only invoice with no policy anywhere still generates', function () {
    // The pre-check walks every line, including charges. A charge line that cites nothing must fall
    // through both branches untouched — the ordinary invoice, and the one this must never break.
    [$school, $admin, $enrollment] = rpcSetup();

    rpcPost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Transport', 'amount_minor' => 20000],
    ])->assertCreated();

    expect(DB::table('finance_invoices')->count())->toBe(1)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(2);
});

// ── Several bad lines at once — every one named, and still ONE query ─────────

it('names EVERY offending line, not just the first', function () {
    // A form highlights fields; it cannot highlight the one field the server happened to notice first.
    // Three distinct failures in one payload, three distinct keys.
    [$school, $admin, $enrollment] = rpcSetup();
    $retired = rpcPolicy($school, status: 'retired', name: 'Retired');
    $approval = rpcPolicy($school, requiresApproval: true, name: 'NeedsApproval');
    $good = rpcPolicy($school, name: 'Good');

    rpcPost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'discount_policy_id' => $good->uuid], // arm 5
        ['description' => 'Retired discount', 'amount_minor' => -1000, 'kind' => 'discount', 'discount_policy_id' => $retired->uuid], // arm 2
        ['description' => 'Approval discount', 'amount_minor' => -1000, 'kind' => 'discount', 'discount_policy_id' => $approval->uuid], // arm 3
        ['description' => 'Bare discount', 'amount_minor' => -1000, 'kind' => 'discount'], // arm 1
    ])->assertStatus(422)->assertJsonValidationErrors([
        'lines.0.discount_policy_id',
        'lines.1.discount_policy_id',
        'lines.2.discount_policy_id',
        'lines.3.discount_policy_id',
    ]);

    rpcAssertNothingWritten();
});

it('keys the error by the CALLER’s key, not by position, for a keyed-object payload', function () {
    // ROUND 1 LISTED THIS AS UNVERIFIABLE-IN-PRACTICE AND IT IS VERIFIABLE IN ONE REQUEST. The
    // pre-check reads array_keys($this->input('lines')) rather than the 0-based index of the
    // array_values()'d spec list, so a payload posting `lines` as a keyed OBJECT keys the error by the
    // caller's own key. Posted at key 7 with no line 0..6 in sight; the error comes back on
    // `lines.7.discount_policy_id`, not `lines.0.discount_policy_id`.
    //
    // Nothing this platform ships sends that shape — the modal builds a JS array — so this is not a
    // path anyone travels. It is asserted because the alternative (indexing by position) would put the
    // error on a line number the caller never used, and an error a form cannot find is exactly the
    // defect this commit exists to remove.
    [$school, $admin, $enrollment] = rpcSetup();
    $policy = rpcPolicy($school, status: 'retired', name: 'Retired');

    $response = $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment->uuid,
            'lines' => [
                3 => ['description' => 'Tuition', 'amount_minor' => 100000],
                7 => ['description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.7.discount_policy_id');

    expect(array_keys((array) $response->json('errors')))->toBe(['lines.7.discount_policy_id']);

    rpcAssertNothingWritten();
});

it('loads every cited policy in ONE query, however many reduction lines there are', function () {
    // The count is the whole claim, so it is measured rather than asserted from the shape of the code:
    // the listener counts statements against finance_discount_policies only, over a request carrying
    // FOUR reduction lines citing three distinct policies.
    //
    // WHAT THE NUMBER IS COMPOSED OF, because "one query" is true of the pre-check and not of the
    // request. Measured on this payload — 4 reduction lines citing 3 distinct policies — by dumping
    // every statement the listener saw, with the pre-check disabled and then enabled: 8 without, 9
    // with. The 8 are pre-existing and unchanged by this commit: four `select exists(… where uuid = ?)`
    // from the validation rule (one per LINE) and four `select id … where uuid = ?` from lineSpecs().
    // That there are four of the latter and not eight is lineSpecs()' memoization holding — the
    // controller resolves twice, via assertMayReduce and then for the Action.
    //
    // The ninth is the pre-check's, and it is the only figure this commit is responsible for:
    //   select * from `finance_discount_policies` where `id` in (?, ?, ?) and `school_id` = ?
    // THREE placeholders for FOUR lines (the ids are deduped), and one statement whatever the line
    // count. The `school_id` term is SchoolScope on the model query, not a hand-rolled predicate.
    [$school, $admin, $enrollment] = rpcSetup();
    $a = rpcPolicy($school, name: 'A');
    $b = rpcPolicy($school, name: 'B');
    $c = rpcPolicy($school, name: 'C');

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        if (str_contains($q->sql, 'finance_discount_policies')) {
            $queries[] = $q->sql;
        }
    });

    rpcPost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 400000],
        ['description' => 'D1', 'amount_minor' => -1000, 'kind' => 'discount', 'discount_policy_id' => $a->uuid],
        ['description' => 'D2', 'amount_minor' => -1000, 'kind' => 'discount', 'discount_policy_id' => $b->uuid],
        ['description' => 'D3', 'amount_minor' => -1000, 'kind' => 'discount', 'discount_policy_id' => $c->uuid],
        ['description' => 'D4', 'amount_minor' => -1000, 'kind' => 'discount', 'discount_policy_id' => $a->uuid],
    ])->assertCreated();    // Exactly one `in (` statement — the pre-check's batch load. Every other statement against this
    // table is a single-uuid lookup from the validation rule or from lineSpecs().
    $batched = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, ' in (')));

    expect($batched)->toHaveCount(1, 'The pre-check must batch-load the cited policies, not resolve one per line. '
        .'Statements seen against finance_discount_policies: '.count($queries));
});
