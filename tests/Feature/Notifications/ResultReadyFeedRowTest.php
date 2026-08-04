<?php

use App\Models\AcademicSession;
use App\Models\Curriculum;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Notifications\Enums\NotificationType;
use App\Notifications\Enums\RecipientReason;
use App\Notifications\Models\Notification;
use App\Notifications\Models\NotificationRecipient;
use App\Notifications\Services\PayloadHydrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A ResultReady feed row must NAME the child and NAVIGATE to their result.
 *
 * ⚠️ THESE ARE REQUEST-LEVEL ON PURPOSE, AND THAT IS THE WHOLE POINT.
 *
 * The naming bug shipped because a unit test cannot see it: hydrate an instance and
 * ask the SAME instance for a title and it passes green. The defect lived in the gap
 * between two objects — the controller hydrated its injected PayloadHydrator while the
 * resource resolved `app(PayloadHydrator::class)`, and with no container binding those
 * were different instances. Only a test that drives controller → resource crosses that
 * gap.
 */
/**
 * ONE curriculum per school, reused by every child.
 *
 * `academic_sessions` has a unique index on (is_current, school) — one current session
 * per school — so a per-child session violates it. Reusing is also the real shape:
 * siblings at one school sit in the same term, which is exactly the multi-child case
 * these tests exist to exercise.
 */
function rrf_curriculumFor(int $schoolId): Curriculum
{
    // CACHED IN THE CONTAINER, not in a function static. A static survives
    // RefreshDatabase's rollback, so it would hand a later test a Curriculum whose row
    // no longer exists — and it only avoids that today by accident, because MySQL's
    // auto-increment does not reset on rollback so school ids never repeat. The
    // container is rebuilt per test, which makes the lifetime correct instead of lucky.
    $key = "rrf.curriculum.{$schoolId}";

    if (app()->bound($key)) {
        return app($key);
    }

    $session = AcademicSession::create([
        'school_id' => $schoolId, 'name' => 'S', 'slug' => 'ses-'.Str::random(8), 'is_current' => true,
    ]);
    $term = Term::create([
        'academic_session_id' => $session->id, 'school_id' => $schoolId, 'name' => 'T',
        'slug' => 'term-'.Str::random(8), 'order' => 1,
        'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(), 'status' => 'active',
    ]);

    $curriculum = Curriculum::create([
        'school_id' => $schoolId, 'term_id' => $term->id, 'status' => 'active',
        'is_ccm' => false, 'min_subjects' => 1,
    ]);

    app()->instance($key, $curriculum);

    return $curriculum;
}

function rrf_enrolment(int $schoolId, string $firstName): StudentCurriculum
{
    $student = Student::create([
        'school_id' => $schoolId,
        'first_name' => $firstName,
        'last_name' => 'Pupil',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
        'status' => 'active',
    ]);

    return StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => rrf_curriculumFor($schoolId)->id,
        'status' => 'active',
    ]);
}

function rrf_resultReadyRow(int $schoolId, User $recipient, StudentCurriculum $enrolment): NotificationRecipient
{
    $notification = Notification::withoutEvents(fn () => Notification::forceCreate([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $schoolId,
        'type' => NotificationType::RESULT_READY->value,
        'subject_type' => $enrolment->getMorphClass(),
        'subject_id' => $enrolment->id,
        // IDS ONLY — no pupil name in a column that lands in every backup and log
        // line. The name is resolved at read time, which is what the hydrator is for.
        'payload' => ['student_id' => (int) $enrolment->student_id, 'student_curriculum_id' => (int) $enrolment->id],
        'created_at' => now(),
    ]));

    return NotificationRecipient::withoutEvents(fn () => NotificationRecipient::forceCreate([
        'uuid' => (string) Str::orderedUuid(),
        'notification_id' => $notification->id,
        'school_id' => $schoolId,
        'notifiable_type' => User::class,
        'notifiable_id' => $recipient->id,
        'reason' => RecipientReason::RELATIONSHIP->value,
    ]));
}

function rrf_feed(User $user, int $schoolId)
{
    return test()
        ->actingAs($user)
        // statefulApi() only applies the session middleware to a request from a
        // stateful domain, and getJson sends no Origin/Referer — without this the
        // request has no session and ActiveSchool falls through to users.school_id.
        ->withHeader('Referer', config('app.url'))
        ->withSession(['school_id' => $schoolId])
        ->getJson('/api/notifications');
}

/**
 * ⚠️ THE TEST WHOSE ABSENCE LET THE BUG SHIP.
 */
it('names the child in the feed row, through the real controller-to-resource path', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $enrolment = rrf_enrolment($school->id, 'Adaeze');

    rrf_resultReadyRow($school->id, $user, $enrolment);

    rrf_feed($user, $school->id)
        ->assertOk()
        ->assertJsonPath('data.0.title', "Adaeze Pupil's result is ready");
});

/**
 * ⚠️ THE ADVERSARIAL CASE THE PER-CHILD DESIGN EXISTS FOR.
 *
 * A guardian with three children gets three rows. Each must name ITS OWN child — a
 * hydrator that keyed its map wrongly, or a resource that reused the first row's
 * resolution, would put one name on every row and look entirely plausible.
 *
 * This is also the payoff of refusing a recipient-keyed dedup key back in the
 * dispatch design: collapse the siblings and there is only ever one row to name.
 */
it('names the right child on each row of a multi-child feed', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);

    foreach (['Adaeze', 'Chidi', 'Ngozi'] as $name) {
        rrf_resultReadyRow($school->id, $user, rrf_enrolment($school->id, $name));
    }

    $titles = collect(rrf_feed($user, $school->id)->assertOk()->json('data'))
        ->pluck('title');

    expect($titles)->toHaveCount(3)
        ->and($titles->unique())->toHaveCount(3)
        ->and($titles)->toContain("Adaeze Pupil's result is ready")
        ->and($titles)->toContain("Chidi Pupil's result is ready")
        ->and($titles)->toContain("Ngozi Pupil's result is ready");
});

it('exposes the subject and student uuids needed to build the deep link', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $enrolment = rrf_enrolment($school->id, 'Adaeze');

    rrf_resultReadyRow($school->id, $user, $enrolment);

    $row = rrf_feed($user, $school->id)->assertOk()->json('data.0');

    // The result page is keyed on (student, enrolment) — BOTH are required, and both
    // are uuids, never the raw table/id pair.
    expect($row['subject_type'])->toBe($enrolment->getMorphClass())
        ->and($row['subject_uuid'])->toBe($enrolment->uuid)
        ->and($row['student_uuid'])->toBe($enrolment->student->uuid);
});

/**
 * ⚠️ GRACEFUL DEGRADATION. Payload ids are NOT foreign keys.
 *
 * A student withdrawn after the notification was raised leaves a row whose subject no
 * longer resolves. It must render as READABLE HISTORY and navigate NOWHERE — a null
 * target, never a link to a 404.
 */
it('renders a withdrawn student as history with no navigation target', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $enrolment = rrf_enrolment($school->id, 'Adaeze');

    rrf_resultReadyRow($school->id, $user, $enrolment);

    $enrolment->student->delete();

    $row = rrf_feed($user, $school->id)->assertOk()->json('data.0');

    expect($row['student_uuid'])->toBeNull()
        // Still a row, still readable — the generic title is the honest fallback when
        // the name genuinely cannot be resolved.
        ->and($row['title'])->toBe("A student's result is ready");
});

/**
 * The binding is `scoped`, and that distinction is a privacy property rather than a
 * staleness one: a singleton hydrator would survive into the next request and answer
 * another user's rows from the previous user's name map.
 */
it('does not carry one user\'s resolved names into another user\'s request', function () {
    $school = al_makeSchool();
    $first = al_makeUser($school->id);
    $second = al_makeUser($school->id);

    rrf_resultReadyRow($school->id, $first, rrf_enrolment($school->id, 'Adaeze'));

    rrf_feed($first, $school->id)->assertOk()->assertJsonPath('data.0.title', "Adaeze Pupil's result is ready");

    // A different user, whose own row is about a different child.
    Auth::forgetGuards();
    test()->flushSession();

    rrf_resultReadyRow($school->id, $second, rrf_enrolment($school->id, 'Chidi'));

    rrf_feed($second, $school->id)
        ->assertOk()
        ->assertJsonPath('data.0.title', "Chidi Pupil's result is ready");
});

/**
 * A hydration with NO student ids must CLEAR the previous one, not leave it standing.
 *
 * Found while trying to bite-prove `scoped` vs `singleton`: the substitution passed,
 * which said the privacy claim was overstated — hydrate() replaces the map whenever a
 * page has ids, so no cross-user leak was reachable. But it only assigned INSIDE that
 * guard, so a page with no ids left the previous page's names in place, and a later row
 * carrying one of those ids resolved to a name this hydration never looked up.
 *
 * Stale rather than absent — the failure mode that reads as correct.
 */
it('clears resolved names when a later hydration has none', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $enrolment = rrf_enrolment($school->id, 'Adaeze');
    $row = rrf_resultReadyRow($school->id, $user, $enrolment);

    $hydrator = app(PayloadHydrator::class);
    $loaded = NotificationRecipient::with('notification')->findOrFail($row->id);

    $hydrator->hydrate(collect([$loaded]));
    expect($hydrator->title($loaded))->toBe("Adaeze Pupil's result is ready");

    // A page with nothing to resolve.
    $hydrator->hydrate(collect());

    expect($hydrator->title($loaded))->toBe("A student's result is ready")
        ->and($hydrator->navigationStudentUuid($loaded))->toBeNull();
});
