<?php

use App\Models\Guardian;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * The bulk guardian-create path — the one a school used to add one mother twice and
 * ended up with three rows.
 *
 * NOTHING IN THE REPOSITORY TESTED `student_links` BEFORE THIS FILE
 * (`grep -rn student_links tests/` returned nothing), which is how a key that
 * appeared in no validation rule, was read straight off `input()`, and dropped every
 * unresolvable admission number with a 201 survived to production.
 */
beforeEach(function () {
    (new RbacSeeder)->run();
    Role::firstOrCreate(['name' => 'guardian', 'guard_name' => 'web']);
    Notification::fake();
});

function dedupeAdmin(School $school): User
{
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $admin->assignRole('admin');

    return $admin;
}

function dedupePayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Parent',
        'phone' => '08030000001',
        'can_login' => false,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Arm 1 — the happy path the bug report says did not work.
// ---------------------------------------------------------------------------

it('creates one guardian with two pivots when two valid admission numbers are supplied', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $a = Student::factory()->create(['school_id' => $school->id]);
    $b = Student::factory()->create(['school_id' => $school->id]);

    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'student_links' => [
                ['admission_number' => $a->admission_number, 'relationship' => 'mother', 'is_primary' => true],
                ['admission_number' => $b->admission_number, 'relationship' => 'mother', 'is_primary' => false],
            ],
        ]))
        ->assertCreated();

    $guardians = Guardian::withoutGlobalScopes()->where('school_id', $school->id)->get();
    expect($guardians)->toHaveCount(1);

    $pivots = DB::table('guardian_student')->where('guardian_id', $guardians->first()->id)->get();
    expect($pivots)->toHaveCount(2)
        ->and($pivots->pluck('relationship')->unique()->all())->toBe(['mother'])
        ->and($pivots->pluck('student_id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

// ---------------------------------------------------------------------------
// Arm 2 — atomicity. The COUNT is the proof; the status code is not.
// ---------------------------------------------------------------------------

it('rejects the whole submission when one admission number does not resolve, creating no guardian', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $a = Student::factory()->create(['school_id' => $school->id]);

    $before = Guardian::withoutGlobalScopes()->count();
    $usersBefore = User::count();

    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'student_links' => [
                ['admission_number' => $a->admission_number, 'relationship' => 'mother', 'is_primary' => true],
                ['admission_number' => 'GFA/9999/999', 'relationship' => 'father', 'is_primary' => false],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['student_links.1.admission_number']);

    // The atomicity assertions. Before this change the guardian was committed by
    // createGuardianWithUser's own transaction and the bad link was silently
    // dropped outside it — a guardian with no children and a 201 saying otherwise.
    expect(Guardian::withoutGlobalScopes()->count())->toBe($before)
        ->and(User::count())->toBe($usersBefore)
        ->and(DB::table('guardian_student')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Arm 2b — THE ARM THAT ACTUALLY EXERCISES THE TRANSACTION.
//
// Arm 2 above does NOT, and that was watched rather than assumed: with the
// DB::transaction wrapper deleted from store(), arm 2 still passed, because a typo'd
// admission number is now rejected by GuardianRequest BEFORE the controller runs and
// nothing is ever written. The transaction is only load-bearing for a failure that
// occurs AFTER validation passes, so this arm manufactures one.
//
// The reachable one is the login invariant: `@no-email.local` is a syntactically
// valid address, so `can_login=true` with a synthetic address clears every rule in
// GuardianRequest, the guardian and user are created, and then attachToStudent's
// assertLoginRequiresDeliverableEmail refuses at the pivot write. Without one
// transaction spanning both, that leaves a committed guardian with no children and a
// user account nobody asked for.
// ---------------------------------------------------------------------------

it('rolls the guardian back when an attachment fails after validation has passed', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $guardiansBefore = Guardian::withoutGlobalScopes()->count();
    $usersBefore = User::count();

    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'can_login' => true,
            'email' => '08030000009'.User::SYNTHETIC_EMAIL_DOMAIN,
            'student_links' => [
                ['admission_number' => $student->admission_number, 'relationship' => 'mother', 'is_primary' => true],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['can_login']);

    // THE COUNTS ARE THE ATOMICITY PROOF. The status code is not: a 422 is exactly
    // what the un-wrapped version returns too, while leaving both rows behind.
    expect(Guardian::withoutGlobalScopes()->count())->toBe($guardiansBefore)
        ->and(User::count())->toBe($usersBefore)
        ->and(DB::table('guardian_student')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Arm 3 — ISOLATION. This is the arm the watched red targets.
// ---------------------------------------------------------------------------

it('refuses an admission number belonging to another school and attaches nothing', function () {
    $school = School::factory()->create();
    $other = School::factory()->create();
    $admin = dedupeAdmin($school);

    $mine = Student::factory()->create(['school_id' => $school->id]);
    $theirs = Student::factory()->create(['school_id' => $other->id]);

    $before = Guardian::withoutGlobalScopes()->count();

    $res = $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'student_links' => [
                ['admission_number' => $mine->admission_number, 'relationship' => 'mother', 'is_primary' => true],
                ['admission_number' => $theirs->admission_number, 'relationship' => 'father', 'is_primary' => false],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['student_links.1.admission_number']);

    // WHICH LAYER ANSWERED IS ASSERTED, not just that something did. There are two
    // guards on this path — the Rule::exists school_id predicate in GuardianRequest
    // and the explicit school pin on the Student lookup in the controller — and both
    // produce a 422 on the same key, so the status and the key together cannot tell
    // them apart. Deleting the predicate and re-running this arm was watched: it
    // still passed on status and key, and the payload came back
    // "Student ADM… could not be found in this school. Nothing was saved." — the
    // CONTROLLER's message. That is defence in depth working and an assertion that
    // could not see the isolation rule it was written to protect. Pinning the
    // framework's own exists() message is what makes the predicate's removal red.
    expect($res->json('errors')['student_links.1.admission_number'][0])
        ->toBe('The selected student_links.1.admission_number is invalid.');

    // Not a silent skip, and no cross-school row of any kind.
    expect(Guardian::withoutGlobalScopes()->count())->toBe($before)
        ->and(DB::table('guardian_student')->count())->toBe(0)
        ->and(DB::table('guardian_student')->where('student_id', $theirs->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Arm 4 — the same person twice, WITH an email.
// ---------------------------------------------------------------------------

it('reuses one guardian row when the same person is submitted twice with an email', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $a = Student::factory()->create(['school_id' => $school->id]);
    $b = Student::factory()->create(['school_id' => $school->id]);

    $payload = fn (Student $student) => dedupePayload([
        'email' => 'ada.parent@example.test',
        'student_links' => [
            ['admission_number' => $student->admission_number, 'relationship' => 'mother', 'is_primary' => true],
        ],
    ]);

    $this->actingAs($admin)->postJson('/api/guardians', $payload($a))->assertCreated();
    $this->actingAs($admin)->postJson('/api/guardians', $payload($b))
        ->assertCreated()
        ->assertJsonPath('reused_existing_guardian', true);

    $guardians = Guardian::withoutGlobalScopes()->where('school_id', $school->id)->get();
    expect($guardians)->toHaveCount(1)
        ->and(DB::table('guardian_student')->where('guardian_id', $guardians->first()->id)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Arm 5 — the same person twice, WITHOUT an email. The reported case.
// ---------------------------------------------------------------------------

it('reuses one guardian and mints no second user when the same person is submitted twice without an email', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $a = Student::factory()->create(['school_id' => $school->id]);
    $b = Student::factory()->create(['school_id' => $school->id]);

    $usersBefore = User::count();

    $payload = fn (Student $student) => dedupePayload([
        'student_links' => [
            ['admission_number' => $student->admission_number, 'relationship' => 'mother', 'is_primary' => true],
        ],
    ]);

    $this->actingAs($admin)->postJson('/api/guardians', $payload($a))->assertCreated();
    $this->actingAs($admin)->postJson('/api/guardians', $payload($b))
        ->assertCreated()
        ->assertJsonPath('reused_existing_guardian', true);

    $guardians = Guardian::withoutGlobalScopes()->where('school_id', $school->id)->get();
    expect($guardians)->toHaveCount(1)
        ->and(User::count())->toBe($usersBefore + 1)
        ->and(DB::table('guardian_student')->where('guardian_id', $guardians->first()->id)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Arm 5b — THE DEFECT THE BRIEF DESCRIBED BACKWARDS, pinned in its own arm.
//
// The brief said `User::where('email', null)` "never matches under MySQL", so every
// email-less create minted a fresh User. It is the reverse: Laravel's query builder
// short-circuits a null value to `WHERE email IS NULL`, `users.email` has been
// nullable since 2026_08_04_160000, and `User` is exempt from SchoolScope — so an
// email-less create bound itself to whichever email-less account came back FIRST,
// from ANY school, and then granted that account access to this one. Two unrelated
// parents, one users row.
// ---------------------------------------------------------------------------

it('never binds an email-less guardian to an unrelated email-less account in another school', function () {
    $school = School::factory()->create();
    $other = School::factory()->create();
    $admin = dedupeAdmin($school);

    // A pre-existing email-less account in a DIFFERENT school. Under the old
    // lookup this is exactly the row `where('email', null)` returned.
    $stranger = User::factory()->create(['school_id' => $other->id, 'email' => null]);

    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload(['first_name' => 'Unrelated', 'phone' => '08039999999']))
        ->assertCreated();

    $guardian = Guardian::withoutGlobalScopes()->where('school_id', $school->id)->sole();

    expect((int) $guardian->user_id)->not->toBe((int) $stranger->id);

    // …and the stranger's account was not handed access to a school it has no
    // business in. grantSchoolAccess writes a school_user pivot AND a team role,
    // so counting guardians rows alone would not have seen this.
    expect($stranger->fresh()->accessibleSchoolIds()->contains($school->id))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Arm 6 — duplicate-check, including isolation asserted BY ID.
// ---------------------------------------------------------------------------

it('duplicate-check finds a known guardian, misses an unrelated one, and never crosses schools', function () {
    $school = School::factory()->create();
    $other = School::factory()->create();
    $admin = dedupeAdmin($school);

    $mineUser = User::factory()->create(['school_id' => $school->id, 'email' => 'known@example.test']);
    $mine = Guardian::withoutGlobalScopes()->create([
        'school_id' => $school->id, 'user_id' => $mineUser->id,
        'first_name' => 'Known', 'last_name' => 'Parent', 'phone' => '+2348030000001', 'status' => 'active',
    ]);

    $theirUser = User::factory()->create(['school_id' => $other->id, 'email' => 'elsewhere@example.test']);
    $theirs = Guardian::withoutGlobalScopes()->create([
        'school_id' => $other->id, 'user_id' => $theirUser->id,
        'first_name' => 'Other', 'last_name' => 'School', 'phone' => '+2348030000002', 'status' => 'active',
    ]);

    $hit = $this->actingAs($admin)
        ->getJson('/api/guardians/duplicate-check?email=KNOWN@example.test')
        ->assertOk()
        ->json('data.guardians');

    expect($hit)->toHaveCount(1)
        ->and($hit[0]['uuid'])->toBe($mine->uuid)
        // Masked, not echoed back.
        ->and($hit[0]['masked_email'])->not->toBe('known@example.test');

    $miss = $this->actingAs($admin)
        ->getJson('/api/guardians/duplicate-check?email=nobody@example.test')
        ->assertOk();
    expect($miss->json('data.guardians'))->toBeEmpty()
        ->and($miss->json('data.account'))->toBeNull();

    // ISOLATION, ASSERTED BY ID. The other school's guardian must not appear by
    // email OR by phone — Guardian's global scope has an OR branch that makes a
    // shared user's other-school rows visible, which is precisely why the matcher
    // drops global scopes and pins school_id itself.
    $crossEmail = $this->actingAs($admin)
        ->getJson('/api/guardians/duplicate-check?email=elsewhere@example.test')
        ->assertOk()->json('data.guardians');
    $crossPhone = $this->actingAs($admin)
        ->getJson('/api/guardians/duplicate-check?phone=08030000002')
        ->assertOk()->json('data.guardians');

    expect(collect($crossEmail)->pluck('uuid')->all())->not->toContain($theirs->uuid)
        ->and($crossEmail)->toBeEmpty()
        ->and(collect($crossPhone)->pluck('uuid')->all())->not->toContain($theirs->uuid)
        ->and($crossPhone)->toBeEmpty();
});

it('duplicate-check reports an existing NON-guardian account as its own case, not as a duplicate', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);

    User::factory()->create(['school_id' => $school->id, 'email' => 'staff.member@example.test']);

    $body = $this->actingAs($admin)
        ->getJson('/api/guardians/duplicate-check?email=staff.member@example.test')
        ->assertOk()
        ->json('data');

    expect($body['guardians'])->toBeEmpty()
        ->and($body['account']['exists'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Arm 7 — the closed door. Create no longer 422s on a registered email; update does.
// ---------------------------------------------------------------------------

it('allows creating a guardian for an email that already has a user, while update still rejects a collision', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);

    $existing = User::factory()->create(['school_id' => $school->id, 'email' => 'taken@example.test']);

    // CREATE: no longer blocked. The service is written to reuse the users row
    // ("One human = one User §6.2") and the rule that used to fight it is gone.
    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload(['email' => 'taken@example.test']))
        ->assertCreated();

    $guardian = Guardian::withoutGlobalScopes()->where('school_id', $school->id)->sole();
    expect((int) $guardian->user_id)->toBe((int) $existing->id);

    // UPDATE: still a genuine collision and still refused.
    $victimUser = User::factory()->create(['school_id' => $school->id, 'email' => 'victim@example.test']);
    $victim = Guardian::withoutGlobalScopes()->create([
        'school_id' => $school->id, 'user_id' => $victimUser->id,
        'first_name' => 'Victim', 'last_name' => 'Parent', 'phone' => '+2348030000003', 'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->putJson("/api/guardians/{$victim->uuid}", ['email' => 'taken@example.test'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Arm 8 — the empty-string relationship the `?? 'other'` fallback never caught.
// ---------------------------------------------------------------------------

it('rejects an empty relationship in student_links instead of writing an empty string to the pivot', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'student_links' => [
                // Exactly what the modal sends: the key is always present, as ''.
                ['admission_number' => $student->admission_number, 'relationship' => '', 'is_primary' => true],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['student_links.0.relationship']);

    expect(DB::table('guardian_student')->count())->toBe(0);
});

it('rejects the same admission number listed twice in one submission', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'student_links' => [
                ['admission_number' => $student->admission_number, 'relationship' => 'mother', 'is_primary' => true],
                ['admission_number' => $student->admission_number, 'relationship' => 'father', 'is_primary' => false],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['student_links.1.admission_number']);

    expect(DB::table('guardian_student')->count())->toBe(0);
});

it('trims whitespace around a pasted admission number', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'student_links' => [
                ['admission_number' => "  {$student->admission_number}\t", 'relationship' => 'mother', 'is_primary' => true],
            ],
        ]))
        ->assertCreated();

    expect(DB::table('guardian_student')->where('student_id', $student->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Arm 9 — the second "it was not saving": the silent credential strip.
//
// THE EXISTING TEST FOR THIS PASSES VACUOUSLY. GuardianManagementTest's
// "registrar cannot change email" arm acts as `registrar`, which holds NO route
// access at all (RbacSeeder.php:299-306), so its 403 comes from the route's
// permission:academic_setup.manage middleware and the assertion holds with or
// without any credential logic in the request class. This arm uses a role that
// actually reaches the controller.
// ---------------------------------------------------------------------------

it('refuses a credential edit by an actor who reaches the route but lacks update_credentials', function () {
    $school = School::factory()->create();

    foreach (['guardian.view', 'guardian.update', 'academic_setup.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    $role = Role::firstOrCreate(['name' => 'guardian_editor_no_creds', 'guard_name' => 'web']);
    // Deliberately NOT guardian.update_credentials — that is the whole arm.
    $role->givePermissionTo(['guardian.view', 'guardian.update', 'academic_setup.manage']);

    $actor = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $actor->assignRole($role);

    $guardianUser = User::factory()->create(['school_id' => $school->id, 'email' => 'login.parent@example.test']);
    $guardian = Guardian::withoutGlobalScopes()->create([
        'school_id' => $school->id, 'user_id' => $guardianUser->id,
        'first_name' => 'Login', 'last_name' => 'Parent', 'phone' => '+2348030000004',
        'occupation' => 'Teacher', 'status' => 'active',
    ]);

    // Sanity: this actor CAN reach the route and CAN make a non-credential edit.
    // Without this the arm would repeat the vacuous 403 it exists to replace.
    $this->actingAs($actor)
        ->putJson("/api/guardians/{$guardian->uuid}", ['occupation' => 'Nurse'])
        ->assertOk();
    expect($guardian->fresh()->occupation)->toBe('Nurse');

    // The credential edit: refused, not silently dropped with a 200.
    $this->actingAs($actor)
        ->putJson("/api/guardians/{$guardian->uuid}", ['email' => 'changed@example.test'])
        ->assertForbidden();

    expect($guardianUser->fresh()->email)->toBe('login.parent@example.test');
});
