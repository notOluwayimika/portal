<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Notifications\Contracts\Notifier;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\NotificationType;
use App\Notifications\Jobs\FanOutNotificationJob;
use App\Notifications\Models\Notification;
use App\Notifications\Models\NotificationDelivery;
use App\Notifications\Models\NotificationPreference;
use App\Notifications\Models\NotificationRecipient;
use App\Notifications\Types\ApprovalRequested;
use App\Notifications\Types\ResultReady;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new RbacSeeder)->run();
    // The subsystem ships dark; every test here is about behaviour WITH it on.
    config(['notifications.enabled' => true]);
});

/** A guardian with $childCount children, all enrolled in one curriculum. */
function nd_family(int $schoolId, int $childCount): array
{
    $guardianUser = al_makeUser($schoolId);
    $guardian = al_makeGuardian($schoolId, $guardianUser->id);

    $session = App\Models\AcademicSession::create([
        'school_id' => $schoolId, 'name' => 'S', 'slug' => 'ses-'.Str::random(8), 'is_current' => true,
    ]);
    $term = App\Models\Term::create([
        'academic_session_id' => $session->id, 'school_id' => $schoolId, 'name' => 'T',
        'slug' => 'term-'.Str::random(8), 'order' => 1,
        'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(), 'status' => 'active',
    ]);
    $curriculum = App\Models\Curriculum::create([
        'school_id' => $schoolId, 'term_id' => $term->id, 'status' => 'active',
        'is_ccm' => false, 'min_subjects' => 1,
    ]);

    $enrolments = collect(range(1, $childCount))->map(function (int $i) use ($schoolId, $guardian, $curriculum) {
        $student = Student::create([
            'school_id' => $schoolId, 'first_name' => "Child{$i}", 'last_name' => 'Test',
            'gender' => 'female', 'admission_number' => 'ADM-'.Str::random(8),
        ]);
        $guardian->students()->attach($student->id, ['relationship' => 'parent']);

        return StudentCurriculum::create([
            'student_id' => $student->id, 'curriculum_id' => $curriculum->id, 'status' => 'active',
        ]);
    });

    return compact('guardianUser', 'guardian', 'enrolments');
}

/**
 * THE REGRESSION THIS DESIGN WAS REWORKED TO PREVENT.
 *
 * The first draft proposed `dedup_key = "result.approved:{termId}:{guardianId}"`
 * so that a guardian with three children got one message. Walk it through: the
 * loop dispatches three times, the second and third collide on
 * UNIQUE(school_id, type, dedup_key), and both short-circuit to child #1's row.
 * Children #2 and #3 end with no notification, no recipient row, no delivery row
 * — not even a skip. That is data loss wearing deduplication's clothes.
 *
 * The key is EVENT identity (per enrolment). Collapsing the outbound message is a
 * different mechanism on a different axis (bundling, v2).
 */
it('gives a guardian one notification per child, never collapsing siblings away', function () {
    $school = al_makeSchool();
    $family = nd_family($school->id, 3);

    ActiveSchool::runFor($school->id, function () use ($family, $school) {
        foreach ($family['enrolments'] as $enrolment) {
            app(Notifier::class)->send(new ResultReady($enrolment, $school->id));
        }
    });

    expect(Notification::query()->where('type', NotificationType::RESULT_READY->value)->count())
        ->toBe(3);

    // …and the guardian sees all three, each deep-linkable to its own child.
    $rows = NotificationRecipient::query()
        ->where('notifiable_id', $family['guardianUser']->id)->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('notification_id')->unique())->toHaveCount(3);
});

it('collapses a genuine repeat of the same event, which is what the key is for', function () {
    $school = al_makeSchool();
    $family = nd_family($school->id, 1);
    $enrolment = $family['enrolments']->first();

    ActiveSchool::runFor($school->id, function () use ($enrolment, $school) {
        // The same event twice — a double-click, a retry, a replayed listener.
        app(Notifier::class)->send(new ResultReady($enrolment, $school->id));
        app(Notifier::class)->send(new ResultReady($enrolment, $school->id));
    });

    expect(Notification::query()->count())->toBe(1)
        ->and(NotificationRecipient::query()->count())->toBe(1);
});

it('excludes the actor, who cannot decide their own request', function () {
    $school = al_makeSchool();
    $ability = PermissionEnum::FINANCE_INVOICE_VOID_REQUEST_APPROVE->value;

    $submitter = al_makeUser($school->id);
    $otherChecker = al_makeUser($school->id);

    setPermissionsTeamId(null);
    $role = Role::firstOrCreate(['name' => 'void_checker', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    setPermissionsTeamId($school->id);
    // The submitter ALSO holds the checker grant — the duty-separation case that
    // actually occurs (a senior who submits and approves). The database refuses
    // `submitted_by = decided_by`, so notifying them would invite a refused action.
    $submitter->assignRole($role);
    $otherChecker->assignRole($role);

    ActiveSchool::runFor($school->id, function () use ($school, $submitter) {
        app(Notifier::class)->send(new ApprovalRequested(
            checkerAbility: PermissionEnum::FINANCE_INVOICE_VOID_REQUEST_APPROVE->value,
            subject: $submitter,
            schoolId: $school->id,
            submittedBy: (int) $submitter->id,
            summary: 'A void request is awaiting approval',
        ));
    });

    $recipients = NotificationRecipient::query()->pluck('notifiable_id');

    expect($recipients)->toContain($otherChecker->id)
        ->and($recipients)->not->toContain($submitter->id);
});

it('records the event before the queue is touched, so a dead worker loses nothing', function () {
    Queue::fake();
    $school = al_makeSchool();
    $family = nd_family($school->id, 1);

    ActiveSchool::runFor($school->id, fn () => app(Notifier::class)->send(
        new ResultReady($family['enrolments']->first(), $school->id)
    ));

    // The worker here is a cron-invoked `queue:work`, not a supervised daemon, so
    // "the queue did not run" is a real steady state — and the feed must still work.
    Queue::assertPushed(FanOutNotificationJob::class);
    expect(Notification::query()->count())->toBe(1)
        ->and(NotificationRecipient::query()->count())->toBe(1);
});

it('is idempotent when the fan-out job runs twice', function () {
    $school = al_makeSchool();
    $family = nd_family($school->id, 1);

    $record = ActiveSchool::runFor($school->id, fn () => app(Notifier::class)->send(
        new ResultReady($family['enrolments']->first(), $school->id)
    ));

    // Re-running is exactly what happens when shared hosting kills the worker
    // mid-chunk and cron picks the job up again a minute later.
    (new FanOutNotificationJob($record->id, $school->id))->handle(
        app(App\Notifications\Services\NotificationRegistry::class),
        app(App\Notifications\Services\ChannelRegistry::class),
        app(App\Notifications\Services\PreferenceGate::class),
    );

    expect(NotificationDelivery::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->first()->status)->toBe(DeliveryStatus::DELIVERED);
});

it('writes a skipped delivery row with a reason rather than dropping a refused send', function () {
    $school = al_makeSchool();
    $family = nd_family($school->id, 1);

    NotificationPreference::create([
        'user_id' => $family['guardianUser']->id,
        'school_id' => $school->id,
        'type' => NotificationType::RESULT_READY->value,
        'channel' => 'in_app',
        'enabled' => false,
    ]);

    ActiveSchool::runFor($school->id, fn () => app(Notifier::class)->send(
        new ResultReady($family['enrolments']->first(), $school->id)
    ));

    $delivery = NotificationDelivery::query()->firstOrFail();

    // "Why did this parent not get it?" answerable from ONE row — the property the
    // whole delivery table exists for.
    expect($delivery->status)->toBe(DeliveryStatus::SKIPPED)
        ->and($delivery->skip_reason)->toBe('preference_off')
        // …and the FEED ROW still exists. A preference silences a channel, it does
        // not erase the fact.
        ->and(NotificationRecipient::query()->count())->toBe(1);
});

it('ignores a preference against a transactional type, which cannot be opted out of', function () {
    $school = al_makeSchool();
    $ability = PermissionEnum::FINANCE_CREDIT_NOTE_APPROVE->value;
    $checker = al_makeUser($school->id);

    setPermissionsTeamId(null);
    $role = Role::firstOrCreate(['name' => 'cn_checker', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    setPermissionsTeamId($school->id);
    $checker->assignRole($role);

    NotificationPreference::create([
        'user_id' => $checker->id, 'school_id' => $school->id,
        'type' => NotificationPreference::ALL_TYPES, 'channel' => 'in_app', 'enabled' => false,
    ]);

    ActiveSchool::runFor($school->id, fn () => app(Notifier::class)->send(new ApprovalRequested(
        checkerAbility: $ability,
        subject: $checker,
        schoolId: $school->id,
        submittedBy: null,
        summary: 'A credit note is awaiting approval',
    )));

    // APPROVAL_REQUESTED is userConfigurable: false — an obligation of the role,
    // not a subscription.
    expect(NotificationDelivery::query()->firstOrFail()->status)->toBe(DeliveryStatus::DELIVERED);
});

it('sends nothing at all while the subsystem is dark', function () {
    config(['notifications.enabled' => false]);
    $school = al_makeSchool();
    $family = nd_family($school->id, 1);

    $result = ActiveSchool::runFor($school->id, fn () => app(Notifier::class)->send(
        new ResultReady($family['enrolments']->first(), $school->id)
    ));

    expect($result)->toBeNull()
        ->and(Notification::query()->count())->toBe(0);
});
