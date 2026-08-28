<?php

/*
 * THE WRITER FOR `scholarships.kind`.
 *
 * The column shipped with a READER and a HARD GUARD and no way to set it: `AwardStudentDiscount`
 * refuses a student whose scholarship is not `discount`, `ProcessBulkInvoiceRun` refuses a whole
 * cohort holding an unconfigured one, and `ScholarshipController` wrote `['name' => …]` and nothing
 * else. Every row on the production copy therefore sits at NULL, which is the state both guards
 * refuse. This file is what proves that is no longer true.
 *
 * THE ARM THAT MATTERS IS THE LAST ONE. Every other arm here could pass against a `kind` that is
 * persisted, echoed and audited and still *not connected to the guard that blocked BSS* — the
 * end-to-end arm is the one that makes the connection explicit: the same award, refused before the
 * endpoint is called and accepted after, with nothing between the two but a PUT.
 *
 * WHAT MAKES THE FIXTURES DISCRIMINATING, stated because a fixture whose degrees of freedom have
 * collapsed passes for the wrong reason while its name stays true:
 *
 *   - THE NAME-ONLY EDIT STARTS FROM A ROW THAT IS ALREADY `discount`, NOT FROM NULL. Starting from
 *     NULL, "kind was not nulled" and "kind was already null" are the same assertion passing for
 *     opposite reasons, which is exactly the regression the arm exists to catch — an unconditional
 *     `$request->only('name', 'kind')` write silently un-configuring a row somebody just classified.
 *   - THE TWO PERSISTENCE ARMS USE DIFFERENT VALUES — create writes `discount`, update writes
 *     `sponsored`. One value across both would pass against an implementation that ignored the input
 *     and hardcoded it.
 *   - THE UPDATE ARM ASSERTS THE VALUE ON A RE-READ FROM THE DATABASE, not on the in-memory model
 *     the controller returned, and separately on the RESOURCE, because a value that persists but is
 *     not on the wire is half the defect this commit exists to close.
 *   - THE AUDIT ARM ASSERTS BOTH SIDES OF THE CHANGE. `attributes.kind` alone would pass on an entry
 *     that recorded the new value with no idea what it replaced, and "what did this used to be" is
 *     the only question anyone asks of a classification audit.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\ScholarshipKind;
use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\AwardStudentDiscount;
use App\Finance\Enums\DiscountBase;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\StudentDiscountAward;
use App\Models\Permission as PermissionModel;
use App\Models\Scholarship;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/**
 * An admin in a fresh School, with the team context set so `academic_setup.manage` resolves.
 *
 * `sw` PREFIX, and the helpers are local rather than imported. Pest defines a test file's functions
 * when it loads that file, so calling another file's helper works only if that file happened to load
 * first — a load-order dependency that fails as a collision the day both are loaded in one process.
 */
function swAdmin(): User
{
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'admin');
    $user->flushSchoolAccessCache();
    setPermissionsTeamId($school->id);

    // `finance.discount-award.manage`, granted DIRECTLY and not through the `admin` role — which
    // does not hold it, by decision (RbacSeeder puts it on `accounts_officer` alone). Arm (vii)
    // below calls AwardStudentDiscount, which gates on that ability since the BSS import gave it its
    // first request-borne caller, and the arm's subject is the scholarship WRITER: without the grant
    // it would still refuse, for the wrong reason, and would go on reading as covered.
    $permission = PermissionModel::query()
        ->where('name', PermissionEnum::FINANCE_DISCOUNT_AWARD_MANAGE->value)
        ->where('guard_name', 'web')
        ->first()
        ?? PermissionModel::create([
            'name' => PermissionEnum::FINANCE_DISCOUNT_AWARD_MANAGE->value,
            'guard_name' => 'web',
        ]);

    $user->givePermissionTo($permission);

    return $user;
}

/** A scholarship in $user's School. `null` is the unconfigured backfill state every real row is in. */
function swScholarship(User $user, ?ScholarshipKind $kind = null): Scholarship
{
    return ActiveSchool::runFor($user->school_id, fn () => Scholarship::create([
        'school_id' => $user->school_id,
        'name' => 'Scheme '.Str::random(6),
        'kind' => $kind,
    ]));
}

/** A student holding $scholarship. `scholarshipIdsFor()` needs nothing but `students.scholarship_id`. */
function swStudent(User $user, Scholarship $scholarship): Student
{
    return ActiveSchool::runFor($user->school_id, fn () => Student::factory()->create([
        'school_id' => $user->school_id,
        'admission_number' => 'ADM-'.Str::random(8),
        'scholarship_id' => $scholarship->id,
    ]));
}

/** An active percentage policy — the only shape `AwardStudentDiscount` will accept. */
function swPolicy(User $user): DiscountPolicy
{
    return ActiveSchool::runFor($user->school_id, fn () => DiscountPolicy::create([
        'school_id' => $user->school_id,
        'name' => 'BSS 50% '.Str::random(4),
        'basis' => 'percent',
        'percent' => 50,
        'base' => DiscountBase::Discountable,
        'requires_approval' => false,
        'status' => 'active',
    ]));
}

// ── The gate ───────────────────────────────────────────────────────────────────────────────────
//
// First, because every arm below would pass just as happily on an unguarded route.

it('refuses an unauthenticated caller', function () {
    $this->postJson('/api/scholarships', ['name' => 'C2C', 'kind' => 'discount'])
        ->assertUnauthorized();
});

it('refuses a user without academic_setup.manage', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    // A teacher never configures who the school bills.
    $user->grantSchoolAccess($school, 'teacher');
    $user->flushSchoolAccessCache();

    $this->actingAs($user)
        ->postJson('/api/scholarships', ['name' => 'C2C', 'kind' => 'discount'])
        ->assertForbidden();
});

// ── (i) Create ─────────────────────────────────────────────────────────────────────────────────

it('creates a scholarship with a kind, persists it, and carries it on the resource', function () {
    $admin = swAdmin();

    $response = $this->actingAs($admin)
        ->postJson('/api/scholarships', ['name' => 'BSS', 'kind' => 'discount'])
        ->assertCreated();

    expect($response->json('kind'))->toBe('discount');

    $row = DB::table('scholarships')->where('uuid', $response->json('uuid'))->first();

    expect($row->kind)->toBe('discount')
        ->and($row->school_id)->toBe($admin->school_id);

    // And the read endpoint carries it too — the create response is not the only wire shape.
    $index = $this->actingAs($admin)->getJson('/api/scholarships')->assertOk();

    expect(collect($index->json('data'))->firstWhere('name', 'BSS')['kind'])->toBe('discount');
});

// ── (ii) Create requires a kind ────────────────────────────────────────────────────────────────

it('refuses a create with no kind', function () {
    $admin = swAdmin();

    $this->actingAs($admin)
        ->postJson('/api/scholarships', ['name' => 'BSS'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('kind');

    expect(DB::table('scholarships')->where('name', 'BSS')->count())->toBe(0);
});

// ── (iii) Create refuses a value outside the enum ──────────────────────────────────────────────

it('refuses a create with a kind outside the enum', function () {
    $admin = swAdmin();

    $this->actingAs($admin)
        ->postJson('/api/scholarships', ['name' => 'BSS', 'kind' => 'nonsense'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('kind');

    expect(DB::table('scholarships')->where('name', 'BSS')->count())->toBe(0);
});

// ── (iv) An existing NULL row can be classified ────────────────────────────────────────────────

it('classifies an existing unconfigured scholarship', function () {
    $admin = swAdmin();
    $scholarship = swScholarship($admin, null);

    expect($scholarship->kind)->toBeNull();

    $response = $this->actingAs($admin)
        ->putJson("/api/scholarships/{$scholarship->uuid}", [
            'name' => $scholarship->name,
            'kind' => 'sponsored',
        ])
        ->assertOk();

    // `data.kind`, not `kind`. `update()` returns a bare resource, which Laravel wraps; `store()`
    // returns `response()->json($resource)`, which does not. That asymmetry is the controller's, it
    // predates this commit, and it is recorded in the ticket beside it rather than changed here —
    // but a test that asserted the wrong shape would have read as a missing field.
    expect($response->json('data.kind'))->toBe('sponsored')
        ->and(DB::table('scholarships')->where('id', $scholarship->id)->value('kind'))
        ->toBe('sponsored');
});

// ── (v) A name-only edit does not touch the kind ───────────────────────────────────────────────

it('leaves the kind alone when only the name is edited', function () {
    $admin = swAdmin();
    // ALREADY CLASSIFIED. From NULL this arm could not tell "not nulled" from "was already null".
    $scholarship = swScholarship($admin, ScholarshipKind::Discount);

    $this->actingAs($admin)
        ->putJson("/api/scholarships/{$scholarship->uuid}", ['name' => 'Renamed'])
        ->assertOk();

    $row = DB::table('scholarships')->where('id', $scholarship->id)->first();

    expect($row->name)->toBe('Renamed')
        ->and($row->kind)->toBe('discount');
});

// ── (vi) The change is audited ─────────────────────────────────────────────────────────────────

it('logs the classification with its causer and both sides of the change', function () {
    $admin = swAdmin();
    $scholarship = swScholarship($admin, null);

    $this->actingAs($admin)
        ->putJson("/api/scholarships/{$scholarship->uuid}", [
            'name' => $scholarship->name,
            'kind' => 'sponsored',
        ])
        ->assertOk();

    $entry = DB::table('activity_log')
        ->where('subject_type', Scholarship::class)
        ->where('subject_id', $scholarship->id)
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->log_name)->toBe('academics')
        ->and($entry->event)->toBe('updated')
        ->and((int) $entry->causer_id)->toBe($admin->id);

    $properties = json_decode($entry->properties, true);

    expect($properties['attributes']['kind'])->toBe('sponsored')
        // BOTH SIDES. The new value alone cannot answer "what was this before".
        ->and($properties['old']['kind'])->toBeNull();
});

it('writes no entry for a save that changes nothing', function () {
    $admin = swAdmin();
    $scholarship = swScholarship($admin, ScholarshipKind::Discount);

    $before = DB::table('activity_log')
        ->where('subject_type', Scholarship::class)
        ->where('subject_id', $scholarship->id)
        ->count();

    $this->actingAs($admin)
        ->putJson("/api/scholarships/{$scholarship->uuid}", [
            'name' => $scholarship->name,
            'kind' => 'discount',
        ])
        ->assertOk();

    expect(DB::table('activity_log')
        ->where('subject_type', Scholarship::class)
        ->where('subject_id', $scholarship->id)
        ->count())->toBe($before);
});

// ── (vii) End to end: the writer unblocks the reader ───────────────────────────────────────────

it('turns a refused discount award into an accepted one through the endpoint', function () {
    $admin = swAdmin();
    $scholarship = swScholarship($admin, null);
    $student = swStudent($admin, $scholarship);
    $policy = swPolicy($admin);

    $award = fn () => ActiveSchool::runFor(
        $admin->school_id,
        fn () => app(AwardStudentDiscount::class)->handle($student->id, $policy->id, $admin->id),
    );

    // BEFORE — refused, and by the unconfigured branch specifically, not by some other guard.
    expect($award)->toThrow(BusinessRuleException::class, 'not configured yet');

    expect(StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->count())
        ->toBe(0);

    // THE WRITER — nothing else changes between the two attempts.
    $this->actingAs($admin)
        ->putJson("/api/scholarships/{$scholarship->uuid}", [
            'name' => $scholarship->name,
            'kind' => 'discount',
        ])
        ->assertOk();

    // AFTER — the same call, on the same student and the same policy, now lands.
    $created = $award();

    expect($created->student_id)->toBe($student->id)
        ->and($created->discount_policy_id)->toBe($policy->id)
        ->and(StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->count())
        ->toBe(1);
});

/**
 * The School the row belongs to is not the School of the caller — `school_id` is the only isolation
 * boundary, and `{scholarship:uuid}` is a global lookup by uuid unless the binding is scoped.
 */
it('cannot classify another School\'s scholarship', function () {
    $admin = swAdmin();

    $otherSchool = School::create(['name' => 'Other '.Str::random(6), 'slug' => (string) Str::uuid()]);
    $theirs = ActiveSchool::runFor($otherSchool->id, fn () => Scholarship::create([
        'school_id' => $otherSchool->id,
        'name' => 'Theirs '.Str::random(6),
        'kind' => null,
    ]));

    $this->actingAs($admin)
        ->putJson("/api/scholarships/{$theirs->uuid}", [
            'name' => $theirs->name,
            'kind' => 'sponsored',
        ])
        ->assertNotFound();

    expect(DB::table('scholarships')->where('id', $theirs->id)->value('kind'))->toBeNull();
});
