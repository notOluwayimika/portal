<?php

use App\Jobs\BackfillPastTermJob;
use App\Jobs\BulkEnableGuardianLoginJob;
use App\Jobs\BulkMessageGuardiansJob;
use App\Jobs\ExportActivityLogJob;
use App\Jobs\Middleware\SchoolAware;
use App\Jobs\MoveFromCcmJob;
use App\Jobs\ProcessGuardianImport;
use App\Jobs\ProcessStudentBulkUpdate;
use App\Models\Curriculum;
use App\Models\Export;
use App\Models\Guardian;
use App\Models\Import;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ActivityLogExportReadyNotification;
use App\Notifications\GuardianAnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\CauserResolver;

/**
 * 1.3b — SchoolAware retrofit. Every legacy queueable job carries its School
 * explicitly and executes inside ActiveSchool::runFor() via the SchoolAware
 * middleware; auth()->setUser($causer) impersonation is gone (§5.6). The
 * declared schoolId is the SOLE execution context — never auth(), session(),
 * users.school_id or the causer.
 */
uses(RefreshDatabase::class);

const RETROFITTED_JOBS = [
    BackfillPastTermJob::class,
    BulkEnableGuardianLoginJob::class,
    BulkMessageGuardiansJob::class,
    ExportActivityLogJob::class,
    MoveFromCcmJob::class,
    ProcessGuardianImport::class,
    ProcessStudentBulkUpdate::class,
];

function jobSuperAdmin(): User
{
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $super = User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'Job',
        'last_name' => 'Super',
        'email' => Str::uuid().'@example.test',
        'password' => bcrypt('password'),
        'school_id' => null, // no user-column fallback available, by design
    ]);
    $super->assignRole('super_admin');

    return $super;
}

/** Run a job's handle() through the real SchoolAware middleware. */
function runSchoolAware(object $job, ?callable $probe = null): void
{
    (new SchoolAware)->handle($job, function ($j) use ($probe) {
        if ($probe) {
            $probe();
        }
        app()->call([$j, 'handle']);
    });
}

// --- Structural: every job class carries the contract --------------------------

it('every queueable job declares readonly int schoolId and the SchoolAware middleware', function () {
    foreach (RETROFITTED_JOBS as $class) {
        $prop = new ReflectionProperty($class, 'schoolId');
        expect($prop->isPublic())->toBeTrue()
            ->and($prop->isReadOnly())->toBeTrue()
            ->and((string) $prop->getType())->toBe('int');

        $job = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $middleware = array_map(get_class(...), $job->middleware());
        expect($middleware)->toContain(SchoolAware::class);
    }
});

it('no queueable job impersonates a causer or reads ambient context', function () {
    $violations = [];

    foreach (glob(app_path('Jobs/*.php')) as $file) {
        foreach (file($file) as $n => $line) {
            $code = trim($line);
            if (str_starts_with($code, '//') || str_starts_with($code, '*') || str_starts_with($code, '/*')) {
                continue; // comments may (and do) mention the banned pattern
            }
            if (preg_match('/auth\(\)->|session\(|ActiveSchool::id\(\)/', $code)) {
                $violations[] = basename($file).':'.($n + 1).'  '.$code;
            }
        }
    }

    expect($violations)->toBe([]);
});

// --- PermissionRegistrar isolation: back-to-back jobs on one worker ------------

it('two SchoolAware jobs for different Schools run back-to-back without team leakage', function () {
    Notification::fake();

    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();
    $guardianA = al_makeGuardian($schoolA->id, al_makeUser($schoolA->id)->id);
    $guardianB = al_makeGuardian($schoolB->id, al_makeUser($schoolB->id)->id);

    // Simulate a leaked worker state from a previous job: the retrofit must
    // override it and then restore it.
    setPermissionsTeamId(999);

    $observedTeams = [];

    // Both jobs are given BOTH guardian ids — each must only touch its own School.
    $jobA = new BulkMessageGuardiansJob([$guardianA->id, $guardianB->id], $schoolA->id, 'Hello A', 'Body', ['mail']);
    $jobB = new BulkMessageGuardiansJob([$guardianA->id, $guardianB->id], $schoolB->id, 'Hello B', 'Body', ['mail']);

    runSchoolAware($jobA, function () use (&$observedTeams) {
        $observedTeams[] = getPermissionsTeamId();
    });
    runSchoolAware($jobB, function () use (&$observedTeams) {
        $observedTeams[] = getPermissionsTeamId();
    });

    // Job A observed School A; Job B observed School B; the pre-existing
    // (simulated stale) worker team was restored after each run.
    expect($observedTeams)->toBe([$schoolA->id, $schoolB->id])
        ->and(getPermissionsTeamId())->toBe(999);

    // Data selection matched the declared School, not the id list: each
    // guardian was notified exactly once (by its own School's run) even though
    // both jobs were handed both guardian ids.
    Notification::assertSentToTimes($guardianA->user, GuardianAnnouncementNotification::class, 1);
    Notification::assertSentToTimes($guardianB->user, GuardianAnnouncementNotification::class, 1);
    Notification::assertCount(2);

    setPermissionsTeamId(null);
});

// --- Super-admin execution: identity vs declared School ------------------------

it('ExportActivityLogJob under a super-admin causer: identity is the super admin, School is the declared one', function () {
    Notification::fake();
    Storage::fake('local');

    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();
    $super = jobSuperAdmin();

    DB::table('activity_log')->insert([
        ['log_name' => 'test', 'description' => 'row-A', 'school_id' => $schoolA->id, 'created_at' => now(), 'updated_at' => now()],
        ['log_name' => 'test', 'description' => 'row-B', 'school_id' => $schoolB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    runSchoolAware(new ExportActivityLogJob($super->id, $schoolA->id, []));

    // School isolation comes solely from the declared schoolId: the Export row
    // and its artifact are partitioned to School A.
    $export = Export::withoutGlobalScopes()->firstOrFail();
    expect($export->school_id)->toBe($schoolA->id)
        ->and($export->file_path)->toStartWith("exports/{$schoolA->id}/{$super->id}/");

    // Authorization identity remained the super admin: Gate::before grants
    // activity_log.view_cross_school, so the CSV legitimately spans Schools.
    $csv = Storage::disk('local')->get($export->file_path);
    expect($csv)->toContain('row-A')->toContain('row-B');

    // And no ambient auth context was ever established.
    expect(auth()->check())->toBeFalse();

    Notification::assertSentTo($super, ActivityLogExportReadyNotification::class);
});

it('BulkEnableGuardianLoginJob under a super-admin causer attributes activity without impersonation', function () {
    Notification::fake();

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'guardian', 'guard_name' => 'web']);

    $school = al_makeSchool();
    $super = jobSuperAdmin();

    $guardianUser = al_makeUser($school->id);
    $guardianUser->forceFill(['disabled_at' => now()])->save(); // scenario: disabled -> re-enable
    $guardian = al_makeGuardian($school->id, $guardianUser->id);

    runSchoolAware(new BulkEnableGuardianLoginJob([$guardian->id], $school->id, $super->id));

    // The work happened, scoped to the declared School.
    expect($guardianUser->fresh()->disabled_at)->toBeNull();

    // Audit attribution is the super admin — via CauserResolver, not auth().
    $row = DB::table('activity_log')->where('event', 'bulk_login_enabled')->first();
    expect($row)->not->toBeNull()
        ->and((int) $row->causer_id)->toBe($super->id);

    // No impersonation ever occurred, and the causer override was cleared.
    expect(auth()->check())->toBeFalse()
        ->and(app(CauserResolver::class)->resolve())->toBeNull();
});

it('import jobs resolve their Import strictly inside the declared School (no cross-School pickup)', function () {
    // An Import belonging to School B must be INVISIBLE to a job declared for
    // School A: the declared schoolId is the sole context, so the scoped
    // Import::find() misses and the job exits without touching the row.
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();
    $owner = al_makeUser($schoolB->id);

    $import = Import::forceCreate([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolB->id,
        'user_id' => $owner->id,
        'type' => 'guardian',
        'file_name' => 'none.xlsx',
        'file_path' => 'imports/none.xlsx',
        'status' => 'queued',
    ]);

    runSchoolAware(new ProcessGuardianImport($import->id, $schoolA->id));
    expect($import->fresh()->status)->toBe('queued');

    runSchoolAware(new ProcessStudentBulkUpdate($import->id, $schoolA->id));
    expect($import->fresh()->status)->toBe('queued');

    // Declared correctly, the same row IS visible (status moves off 'queued'
    // even though the file itself is absent -> failure path is fine here; the
    // point is visibility under the declared context).
    runSchoolAware(new ProcessGuardianImport($import->id, $schoolB->id));
    expect($import->fresh()->status)->not->toBe('queued');
});

it('clone jobs abort when the declared schoolId does not match their curriculum', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();
    $super = jobSuperAdmin();

    $curriculum = new Curriculum;
    $curriculum->school_id = $schoolA->id;
    $curriculum->is_ccm = true;

    // Declared School B, curriculum belongs to School A -> hard abort; no
    // ambient source is consulted to "repair" the mismatch.
    $job = new MoveFromCcmJob($curriculum, $super->id, $schoolB->id);
    runSchoolAware($job);

    expect(Curriculum::withoutGlobalScopes()->count())->toBe(0);
});
