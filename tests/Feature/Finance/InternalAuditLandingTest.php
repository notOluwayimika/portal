<?php

/*
 * THE AUDIT SEAT'S PAGE GATE, AND WHERE EVERY SEAT LANDS AT LOGIN.
 *
 * The second half is the one that matters most: a login-landing branch is code every role in the
 * system runs through, so the arm that says NOBODY ELSE MOVED is what stands between this change
 * and every other seat's morning.
 */

use App\Http\Responses\SchoolAwareLoginResponse;
use App\Models\School;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

function ial_seat(School $school, string $role): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $role);
    $user->flushSchoolAccessCache();

    return $user;
}

/**
 * Where this user is sent by a COLD login — no intended URL.
 *
 * THE RESPONSE CLASS IS INVOKED DIRECTLY: it is the seam the branch lives in, and the one Fortify
 * binds for BOTH password and 2FA logins (FortifyServiceProvider:30-31).
 *
 * The first version of this helper GETted `/` instead, and it was worthless — that route is the
 * guest login view and never reaches this class, so it returned `/dashboard` for every seat
 * INCLUDING super_admin. It would have reported the branch working while testing nothing of it.
 */
function ial_landing($test, User $user): string
{
    $test->actingAs($user);

    // A REQUEST WITH A SESSION, because the response writes `school_id` into it — the same thing
    // Fortify hands it. `request()` in a test has no session store and throws before the branch is
    // reached, which would look like the branch failing rather than the fixture being wrong.
    $request = Request::create('/login', 'POST');
    $request->setLaravelSession(app('session.store'));

    $response = app(SchoolAwareLoginResponse::class)->toResponse($request);

    return (string) $response->headers->get('Location');
}

// ── (e) THE PAGE GATE ────────────────────────────────────────────────────────

it('e — a seat holding other finance abilities but not the approve one is refused the page', function () {
    $school = School::factory()->create();
    $officer = ial_seat($school, 'accounts_officer');

    // The discriminating precondition: this seat holds thirteen finance abilities, so a 403 cannot
    // be "the user holds nothing".
    [$generate, $approve] = ActiveSchool::runFor($school->id, fn () => [
        $officer->can('finance.invoice.generate'),
        $officer->can('finance.invoice.approve'),
    ]);
    expect($generate)->toBeTrue()->and($approve)->toBeFalse();

    $this->actingAs($officer)->withSession(['school_id' => $school->id])
        ->get('/internal-audit/review-queue')
        ->assertForbidden();
});

it('e — the internal auditor reaches the page', function () {
    $school = School::factory()->create();
    $auditor = ial_seat($school, 'internal_auditor');

    $this->actingAs($auditor)->withSession(['school_id' => $school->id])
        ->get('/internal-audit/review-queue')
        ->assertOk();
});

// ── (f) THE LOGIN LANDING ────────────────────────────────────────────────────

it('f — a cold login sends internal_auditor to the review queue', function () {
    $school = School::factory()->create();
    $auditor = ial_seat($school, 'internal_auditor');

    // Asserted BOTH ways: it is the queue, and it is NOT the dashboard the seat cannot open.
    // Without the second, a change that sent everyone to the queue would pass this arm.
    expect(ial_landing($this, $auditor))->toContain('/internal-audit/review-queue')
        ->and(ial_landing($this, $auditor))->not->toContain('/dashboard');
});

it('f — NO existing seat lands anywhere different', function (string $role) {
    // THE ARM THIS BRANCH IS ON TRIAL FOR. SchoolAwareLoginResponse runs for every login in the
    // system, so the risk of this change is not that the auditor lands wrong — it is that
    // everybody else does. Each of these holds dashboard.view, so the branch's second condition
    // (`! can('dashboard.view')`) must leave them exactly where they were.
    $school = School::factory()->create();
    $user = ial_seat($school, $role);

    expect(ial_landing($this, $user))->toContain('/dashboard');
})->with(['admin', 'head_of_school', 'principal', 'teacher', 'guardian']);

it('f — a seat holding BOTH the approve ability and dashboard.view keeps the dashboard', function () {
    // The precedence rule, asserted rather than left in a docblock: the branch only RESCUES a seat
    // that would otherwise 403. It never redirects one that already had somewhere to go.
    $school = School::factory()->create();
    $dual = ial_seat($school, 'internal_auditor');
    $dual->grantSchoolAccess($school, 'head_of_school');
    $dual->flushSchoolAccessCache();

    [$approve, $dashboard] = ActiveSchool::runFor($school->id, fn () => [
        $dual->can('finance.invoice.approve'),
        $dual->can('dashboard.view'),
    ]);
    expect($approve)->toBeTrue()->and($dashboard)->toBeTrue();

    expect(ial_landing($this, $dual))->toContain('/dashboard');
});

it('f — super_admin who ALSO holds the audit role still goes to the super-admin area', function () {
    // PRECEDENCE 1, AND THIS IS THE ARM THAT CAN ACTUALLY TEST IT. A plain super_admin does NOT
    // hold `finance.invoice.approve` — the ability terminates in `approve`, so ApprovalAbility
    // excludes it from the Gate::before bypass (ADR 0040) — so the arm below it cannot distinguish
    // "super_admin is checked first" from "super_admin never matches the auditor branch anyway". A
    // bite-proof making the super_admin branch conditional on the ability passed against that
    // fixture, which is how the gap surfaced.
    //
    // This user holds the ability for real, through the internal_auditor role in a school. If the
    // ordering ever inverts, they are sent to one school's queue — stranding the account that
    // exists to work across schools.
    $school = School::factory()->create();
    $user = ial_seat($school, 'internal_auditor');
    setPermissionsTeamId(null);
    $user->assignRole('super_admin');
    $user->flushSchoolAccessCache();

    expect(ActiveSchool::runFor($school->id, fn () => $user->can('finance.invoice.approve')))->toBeTrue();

    expect(ial_landing($this, $user))->toContain('/super-admin')
        ->and(ial_landing($this, $user))->not->toContain('/internal-audit');
});

it('f — super_admin still goes to the super-admin area, ahead of everything', function () {
    // Precedence 1, unchanged. A platform seat has no school context and must not be sent to one
    // school's queue.
    $user = User::factory()->create();
    setPermissionsTeamId(null);
    $user->assignRole('super_admin');

    expect(ial_landing($this, $user))->toContain('/super-admin');
});
