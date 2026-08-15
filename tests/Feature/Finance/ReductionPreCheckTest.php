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

it('arm 4 — a super_admin with NO School context is refused for want of a context, not by the pre-check', function () {
    // The other path to the trigger's cross-School branch, and it is closed one layer further in.
    // FeeItem and DiscountPolicy are not in config/rbac.php `fail_closed_models`, so with no active
    // School SchoolScope adds no predicate and the uuid resolves unscoped — the pre-check therefore
    // sees a real, active, no-approval policy and passes it. GenerateInvoice:100 refuses the request
    // before the transaction opens. Asserted so the docblock's second bullet has a test behind it.
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
