<?php

use App\Enums\TermStatusEnum;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\Invoice;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * S1 commit 3b — axis B: line-level reduction enforcement (ADR 0049). The DB reduction_guard is the
 * authority: a reduction line must cite an ACTIVE, non-approval-requiring discount policy of the SAME
 * School, and a charge line may cite none. Proofs 7 (the 3b half), 11, 12, 13, 14, 16, 17. Proofs 15
 * (half-to-even rounding) and 18 (F6) are confirmed by the existing Percentage/FixedAmountReduction tests,
 * not duplicated here.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: StudentCurriculum} — admin holds generate + reduction.apply. */
function reSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 're_admin', 'guard_name' => 'web']);
    foreach (['finance.access', 'finance.invoice.generate', 'finance.invoice.reduction.apply'] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $role->syncPermissions(['finance.access', 'finance.invoice.generate', 'finance.invoice.reduction.apply']);
    $admin->assignRole('re_admin');
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

function rePolicy(School $school, bool $requiresApproval = false, string $name = 'Sibling', string $status = 'active'): DiscountPolicy
{
    return ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create([
        'school_id' => $school->id, 'name' => $name, 'basis' => 'percent', 'percent' => 10,
        'requires_approval' => $requiresApproval, 'status' => $status,
    ]));
}

/**
 * Author a schedule, PUBLISH it, and return its items keyed by description (for is_discountable
 * resolution).
 *
 * The publish is not decoration. This helper used to leave the schedule a draft, and proofs 16/17 then
 * billed from it — which no real path can do, because prefill resolves through FeeScheduleLookup::activeFor
 * and a draft never prices. GenerateInvoiceRequest now refuses a fee_item_id whose schedule is an
 * unpublished proposal, and that refusal caught this: the fixture was billing from a draft. Made ACTIVE by
 * a raw status write, the way the rest of this suite moves a lifecycle it is not testing.
 */
function reFeeItems(School $school, array $specs)
{
    $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'slug' => 'sess-'.Str::random(8), 'is_current' => true]);
    $term = Term::create([
        'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
        'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
    ]);
    $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);

    $schedule = ActiveSchool::runFor($school->id, fn () => app(CreateFeeSchedule::class)
        ->handle($term->id, $level->id, 'v1', $specs));

    DB::table('finance_fee_schedules')->where('id', $schedule->id)->update(['status' => 'active']);

    return $schedule->items->keyBy('description');
}

function rePost($test, School $school, User $admin, StudentCurriculum $enrollment, array $lines)
{
    return $test->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => $lines]);
}

// ── Proof 11 — the happy path: an active, no-approval policy backs a reduction line ──

it('proof 11 — a reduction citing an active requires_approval=false policy is stored with policy + actor', function () {
    [$school, $admin, $enrollment] = reSetup();
    $policy = rePolicy($school);

    rePost($this, $school, $admin, $enrollment, [
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000],
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Sibling discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertCreated();

    $reduction = DB::table('finance_invoice_lines')->where('kind', 'discount')->first();
    expect((int) $reduction->discount_policy_id)->toBe($policy->id)
        ->and((int) $reduction->created_by_user_id)->toBe($admin->id);
});

// ── Proof 12 — requires_approval=true is refused, at BOTH layers ─────────────

it('proof 12 (HTTP) — a reduction citing a requires_approval=true policy is refused (422)', function () {
    [$school, $admin, $enrollment] = reSetup();
    $policy = rePolicy($school, requiresApproval: true, name: 'NeedsApproval');

    rePost($this, $school, $admin, $enrollment, [
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000],
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertStatus(422);

    expect(DB::table('finance_invoices')->count())->toBe(0); // nothing partial
});

it('proof 12 (DB) — a RAW reduction-line insert citing a requires_approval=true policy trips the trigger', function () {
    [$school, $admin, $enrollment] = reSetup();
    $policy = rePolicy($school, requiresApproval: true, name: 'NeedsApproval');

    // A charge-only invoice exists to hang the line off; the direct insert bypasses GenerateInvoice, so it
    // is the DATABASE refusing, not the service.
    rePost($this, $school, $admin, $enrollment, [['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000]])->assertCreated();
    $invoiceId = DB::table('finance_invoices')->value('id');

    expect(fn () => DB::table('finance_invoice_lines')->insert([
        'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'invoice_id' => $invoiceId,
        'bank_account_id' => testBankAccountId(), 'description' => 'Sneak', 'kind' => 'discount', 'note' => null, 'amount_minor' => -1000, 'amount_currency' => 'NGN',
        'fee_item_id' => null, 'discount_policy_id' => $policy->id, 'created_by_user_id' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ── Proof 13 — a policy-less reduction line is refused (Part 0's hole, closed) ──

it('proof 13 — a reduction line with discount_policy_id = null is refused, and NOTHING is written', function () {
    [$school, $admin, $enrollment] = reSetup();

    rePost($this, $school, $admin, $enrollment, [
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000],
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Free-text discount', 'amount_minor' => -10000, 'kind' => 'discount'], // no policy
    ])->assertStatus(422);

    // PLANT: neuter the whole reduction-enforcement branch → this 201s → red. (Dropping ONLY the explicit
    // null-check does NOT red: a null discount_policy_id makes the next arm's SELECT return no row, so
    // v_status is NULL and the "not active" check refuses it anyway — the null-check and not-active arms are
    // two faces of one guarantee. Watched red by removing the branch; see the PR body.)
    expect(DB::table('finance_invoices')->count())->toBe(0)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(0);
});

// ── Proof 14 — a cross-School policy is refused under the other School's context ──

it('proof 14 (HTTP) — a School B policy cannot back a reduction under School A', function () {
    [$schoolA, $adminA, $enrollmentA] = reSetup();
    $schoolB = School::factory()->create();
    $policyB = rePolicy($schoolB, name: 'B-only'); // active, but belongs to School B

    // WHICH LAYER REFUSES THIS MOVED IN U8 COMMIT 1, and the arm was split rather than left saying the old
    // thing. While the wire carried the INTEGER id, validation checked shape only, the id reached the
    // INSERT, and the trigger's `v_school <> NEW.school_id` arm was what produced this 422. The wire now
    // carries a uuid, GenerateInvoiceRequest resolves it through DiscountPolicy::query(), and SchoolScope
    // hides School B's row under School A's context — so the refusal is a validation error and the INSERT
    // is never reached. That is a strictly earlier refusal, but it is a DIFFERENT one, and an arm named
    // "DB-enforced" that no longer reaches the DB is the kind of false green this suite exists to avoid.
    // The trigger arm it used to cover is now proof 14 (DB), below, which reaches the INSERT directly.
    rePost($this, $schoolA, $adminA, $enrollmentA, [
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000],
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Foreign discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policyB->uuid],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    expect(DB::table('finance_invoices')->count())->toBe(0);
});

it('proof 14 (DB) — a RAW reduction-line insert citing another School’s policy trips the trigger', function () {
    // The half proof 14 (HTTP) stopped covering. A charge-only invoice exists to hang the line off, and
    // the direct insert bypasses both GenerateInvoiceRequest and GenerateInvoice, so it is the DATABASE
    // refusing. This is the arm that stays red if finance_invoice_lines_reduction_guard's
    // `v_school <> NEW.school_id` branch is removed.
    //
    // TWO THINGS HERE ARE NOT COPIED FROM PROOF 12 (DB) ABOVE, AND MUST NOT BE "MADE CONSISTENT" WITH IT.
    // First, no `bank_account_id` key: finance_invoice_lines HAS NO SUCH COLUMN (SHOW COLUMNS: id, uuid,
    // school_id, invoice_id, description, kind, note, amount_minor, amount_currency, fee_item_id,
    // discount_policy_id, created_by_user_id, timestamps). Second, the assertion reads errorInfo[1]
    // rather than accepting any QueryException. Both exist because the loose form is a FALSE GREEN: with
    // the stray column the insert dies at 1054 "Unknown column 'bank_account_id' in 'field list'" before
    // MySQL ever fires a trigger, and `toThrow(QueryException::class)` cannot tell that apart from the
    // guard doing its job. Measured, not reasoned: dumping errorInfo from this arm printed 1054, and the
    // arm passed just the same when the policy was swapped to School A's own — which is the condition it
    // claims to be testing. 1644 is the SIGNAL SQLSTATE '45000' the guard raises.
    [$schoolA, $adminA, $enrollmentA] = reSetup();
    $schoolB = School::factory()->create();
    $policyB = rePolicy($schoolB, name: 'B-only'); // active, but belongs to School B

    rePost($this, $schoolA, $adminA, $enrollmentA, [['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000]])->assertCreated();
    $invoiceId = DB::table('finance_invoices')->value('id');

    $code = null;
    $message = null;

    try {
        DB::table('finance_invoice_lines')->insert([
            'uuid' => (string) Str::uuid(), 'school_id' => $schoolA->id, 'invoice_id' => $invoiceId,
            'description' => 'Foreign discount', 'kind' => 'discount', 'note' => null, 'amount_minor' => -1000, 'amount_currency' => 'NGN',
            'fee_item_id' => null, 'discount_policy_id' => $policyB->id, 'created_by_user_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $message = (string) ($e->errorInfo[2] ?? '');
    }

    expect($code)->toBe(1644,
        'The insert was not refused by finance_invoice_lines_reduction_guard. A different error code '
        .'means the row died before the trigger ran and this arm proves nothing about isolation.')
        ->and($message)->toContain('another School');
});

// ── Proof 16 — is_discountable narrows the percentage base ───────────────────

it('proof 16 — a non-discountable charge is excluded from the percentage base (server-side resolved)', function () {
    [$school, $admin, $enrollment] = reSetup();
    $policy = rePolicy($school);
    $items = reFeeItems($school, [
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 10000000, 'is_discountable' => true],
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Transport', 'amount_minor' => 2000000, 'is_discountable' => false],
    ]);

    // Tuition ₦100,000 (discountable) + Transport ₦20,000 (NOT) + a 10% discount. Base = tuition only, so
    // the reduction is −₦10,000, NOT −₦12,000. is_discountable comes from the fee ITEM, resolved server-side.
    rePost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'fee_item_id' => $items['Tuition']->uuid],
        ['description' => 'Transport', 'amount_minor' => 20000, 'fee_item_id' => $items['Transport']->uuid],
        ['description' => 'Sibling discount', 'kind' => 'discount', 'percent' => 10, 'discount_policy_id' => $policy->uuid],
    ])->assertCreated();

    // PLANT: remove `&& $line->isDiscountable` from resolvePercentages' fold → base 120000 → −12000 → red.
    expect((int) DB::table('finance_invoice_lines')->where('kind', 'discount')->value('amount_minor'))->toBe(-10000)
        ->and(Invoice::query()->firstOrFail()->total->toKobo())->toBe(110000); // 100000 + 20000 − 10000
});

// ── Proof 17 — all items non-discountable + a percentage → the existing 422 ──

it('proof 17 — a percentage with NO discountable charge left is the existing 422, not a zero line or /0', function () {
    [$school, $admin, $enrollment] = reSetup();
    $policy = rePolicy($school);
    $items = reFeeItems($school, [
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Transport', 'amount_minor' => 2000000, 'is_discountable' => false],
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Feeding', 'amount_minor' => 1500000, 'is_discountable' => false],
    ]);

    $response = rePost($this, $school, $admin, $enrollment, [
        ['description' => 'Transport', 'amount_minor' => 20000, 'fee_item_id' => $items['Transport']->uuid],
        ['description' => 'Feeding', 'amount_minor' => 15000, 'fee_item_id' => $items['Feeding']->uuid],
        ['description' => 'Discount', 'kind' => 'discount', 'percent' => 10, 'discount_policy_id' => $policy->uuid],
    ]);

    // THE MESSAGE, not just the status. This arm asserted a bare 422 and therefore kept passing when the
    // new fee_item_id rule started rejecting the request for an entirely different reason — the fixture was
    // billing from a draft. A 422 is the expected outcome of half a dozen distinct refusals on this route;
    // pinning only the code makes the arm indifferent to which one it got.
    // str_contains + toBeTrue, NOT toContain — Pest's toContain treats a second argument as ANOTHER
    // NEEDLE, so a failure message passed there is silently asserted as substring and always fails.
    $response->assertStatus(422);
    expect(str_contains((string) $response->json('message'), 'at least one charge line'))->toBeTrue(
        'Proof 17 got a 422 that is not its own. The arm is about a percentage with no discountable base '
        .'left — no division by zero and no zero line — and it must fail for that reason or not at all.');

    expect(DB::table('finance_invoices')->count())->toBe(0);
});

// ── Proof 7 (the 3b half) — retirement is not retroactive ────────────────────

it('proof 7 — a RETIRED policy cannot back a NEW reduction, and lines written before retirement are untouched', function () {
    [$school, $admin, $enrollment] = reSetup();
    $policy = rePolicy($school);

    // A reduction citing the policy while ACTIVE — succeeds, total 90000.
    rePost($this, $school, $admin, $enrollment, [
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000],
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertCreated();
    $before = Invoice::query()->firstOrFail();
    expect($before->total->toKobo())->toBe(90000);

    // Retire the policy (status may move; money/identity is frozen by the update guard).
    ActiveSchool::runFor($school->id, fn () => $policy->update(['status' => DiscountPolicyStatus::Retired]));

    // A NEW reduction citing the now-retired policy is refused (a second enrollment to avoid the F7 collision).
    $student2 = Student::factory()->create(['school_id' => $school->id]);
    $enrollment2 = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student2->id, 'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id, 'status' => 'active',
    ]));
    rePost($this, $school, $admin, $enrollment2, [
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000],
        ['bank_account_id' => testBankAccountUuid(), 'description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertStatus(422);

    // The line written BEFORE retirement is untouched, still cites the policy, and its total has not moved —
    // retirement is not retroactive (BEFORE INSERT only; append-only lines; a snapshot amount).
    $line = DB::table('finance_invoice_lines')->where('invoice_id', $before->id)->where('kind', 'discount')->first();
    expect((int) $line->discount_policy_id)->toBe($policy->id)
        ->and($before->fresh()->total->toKobo())->toBe(90000);
});
