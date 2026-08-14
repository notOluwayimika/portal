<?php

use App\Enums\TermStatusEnum;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Models\DiscountPolicy;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * U8 commit 1 — the two ids POST /v1/finance/invoices accepts are uuids, and nothing else.
 *
 * `lines.*.fee_item_id` and `lines.*.discount_policy_id` were the last wire fields on this platform
 * carrying an integer PRIMARY KEY inbound from a client. Every Resource emits a uuid as `id`
 * (DiscountPolicyResource:15, FeeScheduleResource:69/:93, InvoiceResource:23, InvoiceLineResource:17)
 * and every finance route binds `{model:uuid}`, so a screen built from those Resources holds uuids and
 * could not have constructed a valid payload.
 *
 * What these arms pin is the four things that could each silently go wrong in the translation:
 *  1. an integer is REFUSED, not quietly accepted — there was no accept-either period, and this is the
 *     arm that would catch one being reintroduced;
 *  2. a foreign School's uuid and a nonexistent one produce the SAME BYTES, so the endpoint cannot be
 *     used to discover that an id exists somewhere the caller cannot see;
 *  3. a valid uuid resolves to the CORRECT integer row, not merely to some row;
 *  4. the unpublished-schedule refusal keeps its own distinct message rather than collapsing into (2).
 *
 * (3) is NOT covered by EditFeeScheduleDraftTest's superseded arm, which also asserts a stored integer
 * id against a model's — measured, not assumed. That arm's fixture holds exactly ONE fee item, so a
 * resolution that ignored the uuid entirely and returned the first row still satisfies it: planting
 * `$query->value('id')` (the `where('uuid', …)` removed) leaves it green and reds only the arm below,
 * which cites the SECOND of two items.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: StudentCurriculum} — admin holds generate + reduction.apply. */
function wireSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'wire_admin', 'guard_name' => 'web']);
    foreach (['finance.access', 'finance.invoice.generate', 'finance.invoice.reduction.apply'] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $role->syncPermissions(['finance.access', 'finance.invoice.generate', 'finance.invoice.reduction.apply']);
    $admin->assignRole('wire_admin');
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

/**
 * A schedule in the given School, left in `$status`, with one item. Returns that item.
 *
 * The status is moved by a raw write, the way the rest of this suite moves a lifecycle it is not
 * testing — CreateFeeSchedule always produces a draft.
 */
function wireFeeItem(School $school, string $status = 'active', string $description = 'Tuition')
{
    // The CURRENT session is reused when one exists: `academic_sessions_current_school_unique` permits one
    // per School, and these arms deliberately build TWO schedules in one School. Everything below it is
    // fresh per call — a distinct term and class level — so the two schedules never collide on
    // `finance_fee_schedules_pending_unique`, which is keyed on (school, term, class level).
    $session = AcademicSession::query()->where('school_id', $school->id)->where('is_current', true)->first()
        ?? AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'slug' => 'sess-'.Str::random(8), 'is_current' => true]);
    $term = Term::create([
        'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'Term '.Str::random(6),
        // `terms_academic_session_id_order_unique` — a reused session needs a fresh order each call.
        'slug' => 'term-'.Str::random(8), 'order' => Term::query()->where('academic_session_id', $session->id)->max('order') + 1,
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
    ]);
    $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS '.Str::random(6), 'order' => 1]);

    $schedule = ActiveSchool::runFor($school->id, fn () => app(CreateFeeSchedule::class)->handle(
        $term->id, $level->id, 'v1',
        [['bank_account_id' => testBankAccountUuid($school->id), 'description' => $description, 'amount_minor' => 100000, 'currency' => 'NGN']],
    ));

    DB::table('finance_fee_schedules')->where('id', $schedule->id)->update(['status' => $status]);

    return $schedule->items->first();
}

function wirePolicy(School $school): DiscountPolicy
{
    return ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create([
        'school_id' => $school->id, 'name' => 'Sibling', 'basis' => 'percent', 'percent' => 10,
        'requires_approval' => false, 'status' => 'active',
    ]));
}

function wirePost($test, School $school, User $admin, StudentCurriculum $enrollment, array $lines)
{
    return $test->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => $lines]);
}

// ── 1. An integer is refused ─────────────────────────────────────────────────

it('refuses an INTEGER fee_item_id — the id that used to be valid is now a 422', function () {
    // The integer sent is the REAL primary key of a REAL, active, in-School item, so the only thing
    // rejecting it is the wire shape. An accept-either transition rule would return 201 here.
    [$school, $admin, $enrollment] = wireSetup();
    $item = wireFeeItem($school);

    wirePost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'fee_item_id' => $item->id],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.0.fee_item_id');

    expect(DB::table('finance_invoices')->count())->toBe(0)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(0);
});

it('refuses an INTEGER discount_policy_id — the id that used to be valid is now a 422', function () {
    [$school, $admin, $enrollment] = wireSetup();
    $policy = wirePolicy($school);

    wirePost($this, $school, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Sibling discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->id],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.discount_policy_id');

    expect(DB::table('finance_invoices')->count())->toBe(0)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(0);
});

// ── 2. Cross-School and nonexistent are byte-identical ───────────────────────

it('gives a foreign School’s fee item uuid BYTE-IDENTICAL bytes to a nonexistent one', function () {
    // NOT "both are 422" — both being failures is not the property. The property is that the two
    // responses cannot be told apart, because a caller who can distinguish them has been told that an
    // id they may not see nevertheless exists. Compared as raw response bodies, not as decoded arrays,
    // so an ordering or key difference counts as a difference.
    [$mine, $admin, $enrollment] = wireSetup();
    $theirs = School::factory()->create();
    $theirItem = wireFeeItem($theirs);

    $foreign = wirePost($this, $mine, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'fee_item_id' => $theirItem->uuid],
    ]);
    $absent = wirePost($this, $mine, $admin, $enrollment, [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'fee_item_id' => (string) Str::uuid()],
    ]);

    expect($foreign->status())->toBe(422)
        ->and($absent->status())->toBe(422)
        ->and($foreign->getContent())->toBe($absent->getContent(),
            'A caller can tell a foreign School’s fee item uuid apart from a nonexistent one. That '
            .'difference IS the disclosure: it answers "does this id exist?" for rows the caller may '
            .'not see.');

    expect(DB::table('finance_invoices')->count())->toBe(0);
});

it('gives a foreign School’s discount policy uuid BYTE-IDENTICAL bytes to a nonexistent one', function () {
    [$mine, $admin, $enrollment] = wireSetup();
    $theirs = School::factory()->create();
    $theirPolicy = wirePolicy($theirs);

    $lines = fn (string $uuid) => [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        ['description' => 'Discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $uuid],
    ];

    $foreign = wirePost($this, $mine, $admin, $enrollment, $lines($theirPolicy->uuid));
    $absent = wirePost($this, $mine, $admin, $enrollment, $lines((string) Str::uuid()));

    expect($foreign->status())->toBe(422)
        ->and($absent->status())->toBe(422)
        ->and($foreign->getContent())->toBe($absent->getContent(),
            'A caller can tell a foreign School’s discount policy uuid apart from a nonexistent one.');

    expect(DB::table('finance_invoices')->count())->toBe(0);
});

// ── 3. A valid uuid resolves to the CORRECT integer row ──────────────────────

it('resolves each uuid to the correct integer id on the stored line', function () {
    // Two items and a policy exist, and the request cites the SECOND item. Asserting against
    // `$second->id` rather than "not null" is what makes a resolution that returns the first row — or
    // any row — fail. `discount_policy_id` is asserted the same way on the reduction line.
    [$school, $admin, $enrollment] = wireSetup();
    $first = wireFeeItem($school, description: 'Tuition');
    $second = wireFeeItem($school, description: 'Transport');
    $policy = wirePolicy($school);

    expect($second->id)->not->toBe($first->id); // the fixture is only a probe if the ids differ

    wirePost($this, $school, $admin, $enrollment, [
        ['description' => 'Transport', 'amount_minor' => 100000, 'fee_item_id' => $second->uuid],
        ['description' => 'Sibling discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertCreated();

    $charge = DB::table('finance_invoice_lines')->where('kind', 'charge')->first();
    $reduction = DB::table('finance_invoice_lines')->where('kind', 'discount')->first();

    expect((int) $charge->fee_item_id)->toBe($second->id,
        'The fee item uuid on the wire resolved to the wrong integer id. The stored provenance then '
        .'names a price the invoice was not built from.')
        ->and((int) $reduction->discount_policy_id)->toBe($policy->id);
});

// ── 4. The unpublished-schedule refusal keeps its own message ────────────────

it('keeps a DISTINCT message for an item whose schedule is Draft or PendingApproval', function () {
    // This message is deliberately NOT the indistinguishable one: the caller can see this item (it is
    // theirs), so there is nothing to conceal, and collapsing it into "invalid" would tell a Head
    // nothing about why their own draft's item was refused. The arm asserts the two messages DIFFER,
    // which is the half that a lazy "just use the same message everywhere" edit would break.
    [$school, $admin, $enrollment] = wireSetup();
    $absentMessage = null;

    foreach (['draft', 'pending_approval'] as $status) {
        $item = wireFeeItem($school, status: $status, description: 'Tuition '.$status);

        $response = wirePost($this, $school, $admin, $enrollment, [
            ['description' => 'Tuition', 'amount_minor' => 100000, 'fee_item_id' => $item->uuid],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.fee_item_id');

        $message = $response->json('errors')['lines.0.fee_item_id'][0];

        $absentMessage ??= wirePost($this, $school, $admin, $enrollment, [
            ['description' => 'Tuition', 'amount_minor' => 100000, 'fee_item_id' => (string) Str::uuid()],
        ])->json('errors')['lines.0.fee_item_id'][0];

        // BOTH HALVES ARE POSITIVE ASSERTIONS, and the second one deliberately so: a custom message
        // written under `->not->` is silently discarded by Pest's OppositeExpectation proxy, which
        // tests/Feature/Quality/PestNegatedExpectationMessagesTest.php fails the build over. Asking
        // `toBeFalse` of the equality keeps the diagnostic.
        expect(str_contains((string) $message, 'has not been published'))->toBeTrue(
            "A {$status} schedule's item was refused with: {$message}")
            ->and($message === $absentMessage)->toBeFalse(
                "A {$status} schedule's item now gets the same message as an id that does not exist. "
                .'The two refusals are different facts and the operator can act on only one of them.');
    }

    expect(DB::table('finance_invoices')->count())->toBe(0);
});
