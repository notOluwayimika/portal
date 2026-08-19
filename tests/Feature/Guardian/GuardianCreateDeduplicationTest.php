<?php

use App\Models\Curriculum;
use App\Models\Guardian;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\GuardianService;
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
// Arm 5c — a submitted email is NEVER silently dropped on the reuse path.
//
// The reuse branch takes its user from the matched guardian, and
// fillBlankGuardianFields walks Guardian's fillable — which has no `email`, because
// the address lives on `users`. So a phone-matched reuse carrying a freshly typed
// address stored it nowhere and answered 201: the branch's own defect, on the
// branch's own new path. It is now REFUSED rather than written, because users.email
// is the authentication key and one users row backs a guardian in every school that
// person has a child in — an operator who can see one school must not set the
// reset-link address for an account reaching schools they cannot see.
// ---------------------------------------------------------------------------

it('refuses rather than silently drops an email submitted against a phone-matched guardian with no address', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    // The first submission creates the guardian with no email at all.
    $this->actingAs($admin)->postJson('/api/guardians', dedupePayload())->assertCreated();
    $guardian = Guardian::withoutGlobalScopes()->where('school_id', $school->id)->sole();
    expect($guardian->user->email)->toBeNull();

    // The second names the same phone and carries an address.
    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'email' => 'ada.parent@example.test',
            'student_links' => [
                ['admission_number' => $student->admission_number, 'relationship' => 'mother', 'is_primary' => true],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    // Refused whole: no address written, and no half-done attachment either.
    expect($guardian->user->fresh()->email)->toBeNull()
        ->and(Guardian::withoutGlobalScopes()->where('school_id', $school->id)->count())->toBe(1)
        ->and(DB::table('guardian_student')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Arm 5d — a DIFFERENT email refutes a phone-only match. The shared-landline case.
// ---------------------------------------------------------------------------

it('creates a second guardian when the phone matches but the email says it is someone else', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);

    // Father, on the household line, with his own address.
    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'first_name' => 'Chidi', 'email' => 'chidi@example.test',
            'confirm_existing_account' => false,
        ]))
        ->assertCreated();

    // Mother, SAME household line, HER address. Reusing here would have attached her
    // child to his record.
    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'first_name' => 'Ngozi', 'email' => 'ngozi@example.test',
        ]))
        ->assertCreated()
        ->assertJsonPath('reused_existing_guardian', false);

    expect(Guardian::withoutGlobalScopes()->where('school_id', $school->id)->count())->toBe(2)
        ->and(User::whereIn('email', ['chidi@example.test', 'ngozi@example.test'])->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Arm 5e — the email-vs-phone conflict raises a 422, not the 500 an uncaught
// RuntimeException would be. This was introduced by this change and left unproven in
// the first cut; it is the one arm the report named as an unproven arm of its own.
// ---------------------------------------------------------------------------

it('answers 422 when the submitted email and phone belong to two different existing guardians', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);

    $userA = User::factory()->create(['school_id' => $school->id, 'email' => 'first@example.test']);
    Guardian::withoutGlobalScopes()->create([
        'school_id' => $school->id, 'user_id' => $userA->id,
        'first_name' => 'First', 'last_name' => 'Match', 'phone' => '+2348039990001', 'status' => 'active',
    ]);

    $userB = User::factory()->create(['school_id' => $school->id, 'email' => 'second@example.test']);
    Guardian::withoutGlobalScopes()->create([
        'school_id' => $school->id, 'user_id' => $userB->id,
        'first_name' => 'Second', 'last_name' => 'Match', 'phone' => '+2348039990002', 'status' => 'active',
    ]);

    $before = Guardian::withoutGlobalScopes()->count();

    // Email points at guardian A, phone points at guardian B.
    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'email' => 'first@example.test',
            'phone' => '08039990002',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    expect(Guardian::withoutGlobalScopes()->count())->toBe($before);
});

it('rejects more student_links than the bounded maximum', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);

    $links = [];
    for ($i = 0; $i < 51; $i++) {
        $links[] = ['admission_number' => "ADM-BULK-{$i}", 'relationship' => 'mother', 'is_primary' => false];
    }

    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload(['student_links' => $links]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['student_links']);

    expect(Guardian::withoutGlobalScopes()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Arm 5f/5g — THE SAME STUDENT TWICE. The branch the reuse backstop made live.
//
// WHY THIS SURVIVED TWO ROUNDS: both reuse arms above submit the same person against
// two DIFFERENT students, so `attachToStudent`'s existing-pivot branch is never
// entered by either. Before this branch `store` always minted a fresh Guardian, so
// that branch was structurally unreachable from this path — reuse made it live and
// nothing was re-examined. An arm that varies the person but never the child cannot
// see it.
// ---------------------------------------------------------------------------

it('leaves an already-linked student untouched instead of rewriting the link from create-form defaults', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    // First submission: father, primary, portal login ON (a deliverable address, so
    // the login invariant is satisfied).
    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'email' => 'linked.parent@example.test',
            'can_login' => true,
            'student_links' => [
                ['admission_number' => $student->admission_number, 'relationship' => 'father', 'is_primary' => true],
            ],
        ]))
        ->assertCreated();

    $guardian = Guardian::withoutGlobalScopes()->where('school_id', $school->id)->sole();
    $before = DB::table('guardian_student')
        ->where('guardian_id', $guardian->id)->where('student_id', $student->id)->first();
    $passwordBefore = $guardian->user->fresh()->password;

    expect((bool) $before->can_login)->toBeTrue()
        ->and($before->relationship)->toBe('father')
        ->and((bool) $before->is_primary)->toBeTrue();

    // Second submission: SAME person, SAME child, and every create-form default that
    // would downgrade the link — can_login unticked (the modal's default), a
    // different relationship, is_primary off.
    $response = $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'email' => 'linked.parent@example.test',
            'can_login' => false,
            'student_links' => [
                ['admission_number' => $student->admission_number, 'relationship' => 'other', 'is_primary' => false],
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('reused_existing_guardian', true);

    $after = DB::table('guardian_student')
        ->where('guardian_id', $guardian->id)->where('student_id', $student->id)->first();

    // THE PIVOT STATE IS ASSERTED FIRST, deliberately: it is the defect, and
    // `already_linked` below is only the reporting of it. Ordered the other way the
    // arm goes red on the missing report and never reaches the damage, which is the
    // less useful failure message of the two.
    //
    // can_login matters most: a create submission whose checkbox merely defaulted
    // unticked must never REVOKE an existing portal login, and that revocation is
    // silent and — before this round — unlogged.
    expect((bool) $after->can_login)->toBeTrue()
        ->and($after->relationship)->toBe('father')
        ->and((bool) $after->is_primary)->toBeTrue()
        ->and(DB::table('guardian_student')->count())->toBe(1);

    // REPORTED, NOT REWRITTEN.
    expect($response->json('already_linked'))->toBe([$student->admission_number]);
});

it('does not rotate an existing account password when a create submission re-enters a linked student', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    // First: linked with portal login OFF.
    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'email' => 'rotate.me@example.test',
            'can_login' => false,
            'student_links' => [
                ['admission_number' => $student->admission_number, 'relationship' => 'mother', 'is_primary' => true],
            ],
        ]))
        ->assertCreated();

    $guardian = Guardian::withoutGlobalScopes()->where('school_id', $school->id)->sole();
    $passwordBefore = $guardian->user->fresh()->password;

    Notification::fake();

    // Second: same person, same child, login now TICKED. This is the false→true
    // direction, which reaches reissueCredentialsIfPossible — a password rotation and
    // an email, triggered from a form the operator opened to ADD somebody.
    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'email' => 'rotate.me@example.test',
            'can_login' => true,
            'student_links' => [
                ['admission_number' => $student->admission_number, 'relationship' => 'mother', 'is_primary' => true],
            ],
        ]))
        ->assertCreated();

    expect($guardian->user->fresh()->password)->toBe($passwordBefore);
    Notification::assertNothingSent();

    // …and the pivot is still exactly as it was: the link was reported, not edited.
    $after = DB::table('guardian_student')
        ->where('guardian_id', $guardian->id)->where('student_id', $student->id)->first();
    expect((bool) $after->can_login)->toBeFalse();
});

it('writes an activity record when a pivot update does change something', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $user = User::factory()->create(['school_id' => $school->id, 'email' => 'audited@example.test']);
    $guardian = Guardian::withoutGlobalScopes()->create([
        'school_id' => $school->id, 'user_id' => $user->id,
        'first_name' => 'Audited', 'last_name' => 'Pivot', 'phone' => '+2348031112222', 'status' => 'active',
    ]);
    $student->guardians()->attach($guardian->id, ['relationship' => 'father', 'is_primary' => true, 'can_login' => false]);

    $before = DB::table('activity_log')->where('subject_type', Guardian::class)->where('subject_id', $guardian->id)->count();

    // attachToStudent's UPDATE branch — the only pivot mutator that wrote no trail.
    app(GuardianService::class)->attachToStudent(
        guardian: $guardian, student: $student,
        relationship: 'mother', isPrimary: true, canLogin: false,
    );

    $rows = DB::table('activity_log')
        ->where('subject_type', Guardian::class)->where('subject_id', $guardian->id)
        ->orderByDesc('id')->get();

    expect($rows->count())->toBe($before + 1)
        ->and($rows->first()->event)->toBe('pivot_updated');

    // …and an idempotent re-attach with NOTHING changed writes no noise.
    app(GuardianService::class)->attachToStudent(
        guardian: $guardian, student: $student,
        relationship: 'mother', isPrimary: true, canLogin: false,
    );

    expect(DB::table('activity_log')->where('subject_type', Guardian::class)->where('subject_id', $guardian->id)->count())
        ->toBe($before + 1);
});

// ---------------------------------------------------------------------------
// Arm 5h — THE SAME RULE ON THE OTHER SCREEN. `attach`, not `store`.
//
// The already-linked guard shipped in `store` and not in `attach`, so the per-child
// Add Guardian modal kept the whole defect for a round — on the screen whose own
// banner promises it cannot happen. The guard is now one method
// (GuardianService::attachUnlessAlreadyLinked) and both call sites use it; these arms
// are what stops the two drifting apart again.
// ---------------------------------------------------------------------------

it('leaves an already-linked student untouched when the SAME link is re-submitted through attach', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $user = User::factory()->create(['school_id' => $school->id, 'email' => 'attach.parent@example.test']);
    $guardian = Guardian::withoutGlobalScopes()->create([
        'school_id' => $school->id, 'user_id' => $user->id,
        'first_name' => 'Attach', 'last_name' => 'Parent', 'phone' => '+2348055550001', 'status' => 'active',
    ]);
    $student->guardians()->attach($guardian->id, [
        'relationship' => 'father', 'is_primary' => true, 'can_login' => true,
    ]);
    $passwordBefore = $user->fresh()->password;

    Notification::fake();

    // Same person, same child, through the per-child modal, with every default that
    // would downgrade the link: login unticked, a different relationship, not primary.
    $this->actingAs($admin)
        ->postJson("/api/students/{$student->uuid}/guardians", [
            'mode' => 'new',
            'relationship' => 'other',
            'is_primary' => false,
            'can_login' => false,
            'first_name' => 'Attach',
            'last_name' => 'Parent',
            'phone' => '08055550001',
        ])
        ->assertCreated()
        ->assertJsonPath('already_linked', true);

    $after = DB::table('guardian_student')
        ->where('guardian_id', $guardian->id)->where('student_id', $student->id)->first();

    expect((bool) $after->can_login)->toBeTrue()
        ->and($after->relationship)->toBe('father')
        ->and((bool) $after->is_primary)->toBeTrue()
        ->and($user->fresh()->password)->toBe($passwordBefore)
        ->and(DB::table('guardian_student')->count())->toBe(1);

    Notification::assertNothingSent();
});

it('still attaches through attach when the link does not yet exist', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $sibling = Student::factory()->create(['school_id' => $school->id]);

    $user = User::factory()->create(['school_id' => $school->id, 'email' => 'attach.new@example.test']);
    $guardian = Guardian::withoutGlobalScopes()->create([
        'school_id' => $school->id, 'user_id' => $user->id,
        'first_name' => 'Attach', 'last_name' => 'New', 'phone' => '+2348055550002', 'status' => 'active',
    ]);
    $student->guardians()->attach($guardian->id, ['relationship' => 'mother', 'is_primary' => true, 'can_login' => false]);

    // The SIBLING is a link that does not exist yet — the guard must not block it.
    $this->actingAs($admin)
        ->postJson("/api/students/{$sibling->uuid}/guardians", [
            'mode' => 'new',
            'relationship' => 'mother',
            'is_primary' => true,
            'can_login' => false,
            'first_name' => 'Attach',
            'last_name' => 'New',
            'phone' => '08055550002',
        ])
        ->assertCreated()
        ->assertJsonPath('already_linked', false);

    expect(DB::table('guardian_student')->where('guardian_id', $guardian->id)->count())->toBe(2)
        ->and(Guardian::withoutGlobalScopes()->where('school_id', $school->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Arm 5i/5j — AN AMBIGUOUS PHONE IS REFUSED, A SINGLE ONE IS REUSED.
//
// `email` is required only when portal login is on, so a phone-only submission is the
// ORDINARY one. The phone branch used to be an unordered `->first()`, so when several
// guardians in a school shared a number the reuse picked an arbitrary row and then
// discarded the typed name — 14 (school, phone) groups on the production copy already
// hold more than one row. A create form does not resolve ambiguity on the operator's
// behalf; it refuses and shows them the candidates.
// ---------------------------------------------------------------------------

it('refuses to guess when several guardians in the school share the submitted phone', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);

    foreach (['Chidi' => 'chidi@example.test', 'Ngozi' => 'ngozi@example.test'] as $first => $email) {
        $u = User::factory()->create(['school_id' => $school->id, 'email' => $email]);
        Guardian::withoutGlobalScopes()->create([
            'school_id' => $school->id, 'user_id' => $u->id,
            'first_name' => $first, 'last_name' => 'Household', 'phone' => '+2348066660001', 'status' => 'active',
        ]);
    }

    $before = Guardian::withoutGlobalScopes()->count();

    // A third person, no email (the ordinary submission), same household number.
    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'first_name' => 'Amaka', 'last_name' => 'Household', 'phone' => '08066660001',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone']);

    // Refused, not resolved: no arbitrary reuse and no new row either.
    expect(Guardian::withoutGlobalScopes()->count())->toBe($before);

    // …and the banner CAN name the candidates, which is what makes the refusal
    // actionable rather than a dead end.
    $candidates = $this->actingAs($admin)
        ->getJson('/api/guardians/duplicate-check?phone=08066660001')
        ->assertOk()
        ->json('data.guardians');

    expect($candidates)->toHaveCount(2);
});

it('reuses without ceremony when exactly one guardian in the school has the phone', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $this->actingAs($admin)->postJson('/api/guardians', dedupePayload(['phone' => '08066660009']))->assertCreated();

    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'phone' => '08066660009',
            'student_links' => [
                ['admission_number' => $student->admission_number, 'relationship' => 'mother', 'is_primary' => true],
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('reused_existing_guardian', true);

    expect(Guardian::withoutGlobalScopes()->where('school_id', $school->id)->count())->toBe(1);
});

it('lets a submitted email disambiguate a shared phone instead of refusing', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);

    foreach (['Chidi' => 'chidi.d@example.test', 'Ngozi' => 'ngozi.d@example.test'] as $first => $email) {
        $u = User::factory()->create(['school_id' => $school->id, 'email' => $email]);
        Guardian::withoutGlobalScopes()->create([
            'school_id' => $school->id, 'user_id' => $u->id,
            'first_name' => $first, 'last_name' => 'Shared', 'phone' => '+2348066660002', 'status' => 'active',
        ]);
    }

    // The email names one of the two candidates — the operator supplied the evidence
    // that singles a row out, so refusing would be refusing to read what they typed.
    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'first_name' => 'Ngozi', 'last_name' => 'Shared',
            'phone' => '08066660002', 'email' => 'ngozi.d@example.test',
        ]))
        ->assertCreated()
        ->assertJsonPath('reused_existing_guardian', true);

    expect(Guardian::withoutGlobalScopes()->where('school_id', $school->id)->count())->toBe(2);
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
// Arm 7 — the closed door, and the CONTROL that replaced it.
//
// THIS ARM ASSERTED THE WRONG THING IN THE FIRST CUT. It pinned "create binds to
// the existing account" as intended behaviour, which is exactly what the cold review
// objected to: removing Rule::unique('users','email') from the create path took away
// the only thing standing between an operator typing a colleague's address and that
// colleague's account acquiring the `guardian` role, a `school_user` pivot, and — the
// moment the guardian link is later wound down — an account-global `disabled_at`.
// The banner asked the operator to confirm; nothing made them, and a rule with no
// mechanism is a wish. The arm now asserts the control in BOTH directions.
// ---------------------------------------------------------------------------

it('refuses to bind a new guardian to an existing non-guardian account without an explicit confirmation', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);

    // The account belongs to ANOTHER school on purpose. A colleague at this school
    // already reaches it, so "did the refused write widen anything" would be
    // unanswerable there — accessibleSchoolIds includes a user's own school_id.
    $other = School::factory()->create();
    $staff = User::factory()->create(['school_id' => $other->id, 'email' => 'taken@example.test']);
    $before = Guardian::withoutGlobalScopes()->count();

    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload(['email' => 'taken@example.test']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    // Nothing was written, and — the part a guardian count cannot see — the account
    // did not acquire access to this school. grantSchoolAccess writes a school_user
    // pivot AND a team role, so the reach of the refused write is wider than the
    // table the operator was looking at, and counting guardians alone would miss it.
    expect(Guardian::withoutGlobalScopes()->count())->toBe($before)
        ->and($staff->fresh()->accessibleSchoolIds()->contains($school->id))->toBeFalse()
        ->and(DB::table('school_user')->where('user_id', $staff->id)->where('school_id', $school->id)->exists())->toBeFalse();
});

it('allows creating a guardian for an email that already has a user WHEN confirmed, while update still rejects a collision', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);

    $existing = User::factory()->create(['school_id' => $school->id, 'email' => 'taken@example.test']);

    // CREATE with the operator's explicit answer: the multi-school parent this
    // reuse exists for ("One human = one User §6.2") still gets through.
    $this->actingAs($admin)
        ->postJson('/api/guardians', dedupePayload([
            'email' => 'taken@example.test',
            'confirm_existing_account' => true,
        ]))
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
// Arm 10 — THE REPORTED DEFECT, REINTRODUCED BY ITS OWN FIX.
//
// The two refusals this branch added to createGuardianWithUser key on the flat field
// `email`, and they fire for ALL FOUR callers of that method. The student
// registration form resolves errors NESTED ONLY (`guardians.N.field`) — its sibling
// modal has a flat fallback and it did not — so registering a student whose parent is
// an already-known email-less guardian returned 422, rolled the student back
// correctly, and displayed NOTHING. A silent block on the highest-traffic write on
// the platform, shipped inside the change commissioned to delete silent blocks.
//
// ASSERTED ON THE RESOLVED KEY, NOT THE STATUS. A 422 is exactly what the broken
// version returned; the status is what made this invisible. What matters is that the
// error arrives under a key the form actually reads.
// ---------------------------------------------------------------------------

it('surfaces a guardian refusal on the student-registration form under a key that form renders', function () {
    $school = School::factory()->create();
    $admin = dedupeAdmin($school);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

    // An existing guardian in this school with NO stored address.
    $existingUser = User::factory()->create(['school_id' => $school->id, 'email' => null]);
    Guardian::withoutGlobalScopes()->create([
        'school_id' => $school->id, 'user_id' => $existingUser->id,
        'first_name' => 'Known', 'last_name' => 'NoAddress', 'phone' => '+2348044440001', 'status' => 'active',
    ]);

    $studentsBefore = Student::withoutGlobalScopes()->count();

    $response = $this->actingAs($admin)->postJson('/api/students', [
        'first_name' => 'New',
        'last_name' => 'Registrant',
        'gender' => 'male',
        'curriculum_id' => $curriculum->id,
        'guardians' => [[
            'mode' => 'new',
            'relationship' => 'mother',
            'is_primary' => true,
            'can_login' => false,
            'first_name' => 'Known',
            'last_name' => 'NoAddress',
            // Same phone — so the matcher reuses — and an address now typed in.
            'phone' => '08044440001',
            'email' => 'known.now.has.one@example.test',
        ]],
    ])->assertStatus(422);

    // THE KEY THE FORM READS. guardian-sub-form.tsx resolves
    // `guardians.{index}.{field}` first; StudentController re-keys the service's flat
    // `email` onto the row that caused it.
    $response->assertJsonValidationErrors(['guardians.0.email']);

    // …and the whole registration rolled back: no orphan student.
    expect(Student::withoutGlobalScopes()->count())->toBe($studentsBefore);
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

// ---------------------------------------------------------------------------
// Arm 9b — the refusal is on an ATTEMPTED CHANGE, not on field presence.
//
// THE FIRST CUT REFUSED ON PRESENCE AND THAT WAS A LOCKOUT, not a stricter rule.
// `edit-guardian-modal.tsx` prefills from the record and posts every non-empty key,
// and `phone` is required and therefore ALWAYS present — so this actor could not
// save ANYTHING, and the 403 told them to remove a field the modal gives them no way
// to omit. Item 20's intent was to replace a false success with an honest refusal,
// not with an unconditional one. This arm is what makes the difference visible: it
// submits exactly what that modal submits.
// ---------------------------------------------------------------------------

it('lets an actor without update_credentials save an ordinary edit that round-trips the unchanged credential fields', function () {
    $school = School::factory()->create();

    foreach (['guardian.view', 'guardian.update', 'academic_setup.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    $role = Role::firstOrCreate(['name' => 'guardian_editor_no_creds_b', 'guard_name' => 'web']);
    $role->givePermissionTo(['guardian.view', 'guardian.update', 'academic_setup.manage']);

    $actor = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $actor->assignRole($role);

    $guardianUser = User::factory()->create(['school_id' => $school->id, 'email' => 'roundtrip@example.test']);
    $guardian = Guardian::withoutGlobalScopes()->create([
        'school_id' => $school->id, 'user_id' => $guardianUser->id,
        'first_name' => 'Round', 'last_name' => 'Trip', 'phone' => '+2348030000005',
        'occupation' => 'Teacher', 'status' => 'active',
    ]);

    // EXACTLY what edit-guardian-modal.tsx sends: every non-empty key, prefilled from
    // the record, with only `occupation` actually altered.
    $this->actingAs($actor)
        ->putJson("/api/guardians/{$guardian->uuid}", [
            'first_name' => 'Round',
            'last_name' => 'Trip',
            'phone' => '+2348030000005',
            'email' => 'roundtrip@example.test',
            'occupation' => 'Nurse',
        ])
        ->assertOk();

    expect($guardian->fresh()->occupation)->toBe('Nurse')
        ->and($guardianUser->fresh()->email)->toBe('roundtrip@example.test');

    // A phone the operator retyped in LOCAL format is the same number, not a change:
    // the stored value is E.164 and comparing the raw strings would 403 on formatting.
    $this->actingAs($actor)
        ->putJson("/api/guardians/{$guardian->uuid}", [
            'phone' => '08030000005',
            'email' => 'roundtrip@example.test',
            'occupation' => 'Doctor',
        ])
        ->assertOk();

    expect($guardian->fresh()->occupation)->toBe('Doctor');

    // CHARACTERISING A KNOWN DEFECT, not endorsing it. That save also REWROTE the
    // stored number in local format, because GuardianService::update writes phones
    // with no PhoneNormalizer pass — filed at
    // docs/handoff/tickets/guardian-update-writes-phones-and-cannot-clear-a-field.md
    // and measured at zero affected rows today. Asserted here so that closing the
    // ticket turns this line red rather than leaving the regression invisible.
    expect($guardian->fresh()->phone)->toBe('08030000005');

    // …and a real phone change is still refused.
    $this->actingAs($actor)
        ->putJson("/api/guardians/{$guardian->uuid}", [
            'phone' => '08030009999',
            'email' => 'roundtrip@example.test',
        ])
        ->assertForbidden();

    expect($guardian->fresh()->phone)->toBe('08030000005');
});
