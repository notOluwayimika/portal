<?php

use App\Exceptions\MissingSchoolContextException;
use App\Jobs\Middleware\SchoolAware;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Fail-closed must fire across transports, not just HTTP: the scope's job is to
 * make "no active School context" an exception wherever a School-owned model is
 * read (§5.5, 1.3c Done-signal: "throws from console+worker"). These tests drive
 * the two off-request transports directly:
 *   - an Artisan command acting as a context-less principal,
 *   - a queued job's handle() with no context (legacy impersonation path),
 *   - the same job wrapped in the approved SchoolAware/runFor() context.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // Opt Student in; the mechanism is per-model (see SchoolScopeFailsClosedTest).
    config(['rbac.fail_closed_models' => [Student::class]]);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
});

function probeSuperAdmin(): User
{
    // A super admin is team-less and holds no School (users.school_id null), so
    // ActiveSchool::id() has nothing to fall back to — the pure no-context case.
    $super = User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'Probe',
        'last_name' => 'Super',
        'email' => Str::uuid().'@example.test',
        'password' => bcrypt('password'),
        'school_id' => null,
    ]);

    setPermissionsTeamId(null);
    $super->assignRole('super_admin');

    return $super;
}

// --- Console ---------------------------------------------------------------

it('throws from an Artisan command that reads a School-owned model with no context', function () {
    // A command acting as a context-less principal (auth set, no active School).
    Artisan::command('probe:fail-closed-read', function () {
        try {
            Student::count();
            $this->info('READ_OK');

            return 0;
        } catch (MissingSchoolContextException $e) {
            $this->error('FAIL_CLOSED');

            return 1;
        }
    });

    $this->actingAs(probeSuperAdmin());

    // Exit code 1 == the scoped read threw inside the command.
    $this->artisan('probe:fail-closed-read')->assertExitCode(1);
});

it('does not throw from an Artisan command when the model is not opted in', function () {
    config(['rbac.fail_closed_models' => []]); // legacy fail-open for every model

    Artisan::command('probe:fail-open-read', function () {
        try {
            Student::count();
            $this->info('READ_OK');

            return 0;
        } catch (MissingSchoolContextException $e) {
            $this->error('FAIL_CLOSED');

            return 1;
        }
    });

    $this->actingAs(probeSuperAdmin());

    $this->artisan('probe:fail-open-read')->assertExitCode(0);
});

// --- Worker ----------------------------------------------------------------

it('throws inside a queued job that runs without School context', function () {
    // Mirrors a legacy job that impersonates a context-less causer and never
    // establishes an active School — exactly the risk #14 worker case.
    $job = new LegacyProbeJob(probeSuperAdmin());

    expect(fn () => $job->handle())->toThrow(MissingSchoolContextException::class);
});

it('succeeds inside a queued job wrapped in the approved SchoolAware/runFor context', function () {
    $school = al_makeSchool();
    $job = new SchoolAwareProbeJob($school->id, probeSuperAdmin());

    // Run handle() through the real SchoolAware middleware (which delegates to
    // ActiveSchool::runFor($job->schoolId, ...)). The override supplies context,
    // so the same read that threw above now resolves — scoped to $school.
    $run = fn () => (new SchoolAware)->handle($job, fn ($j) => $j->handle());

    expect($run)->not->toThrow(MissingSchoolContextException::class);
    expect(SchoolAwareProbeJob::$count)->toBeInt(); // read ran, scoped, no throw
});

// --- Probe jobs ------------------------------------------------------------

class LegacyProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public User $causer) {}

    public function handle(): void
    {
        auth()->setUser($this->causer); // legacy impersonation; no active School
        Student::count();
    }
}

class SchoolAwareProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static ?int $count = null;

    public function __construct(public readonly int $schoolId, public User $causer) {}

    public function handle(): void
    {
        auth()->setUser($this->causer);
        self::$count = Student::count();
    }
}
