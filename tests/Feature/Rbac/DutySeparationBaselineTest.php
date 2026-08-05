<?php

// `finance:audit-duty-separation --baseline=<path>` — what makes the scheduled task's exit code mean
// something again.
//
// Without the option the command exits non-zero whenever ANY user holds both sides of any pair, and
// the production copy carries 10 pre-existing result.* findings — so the nightly run has failed every
// night since 43dfbe8 (2026-07-25) and "failed" carried no information. With a committed baseline,
// non-zero stops meaning "this database has findings" and starts meaning "something appeared that
// nobody has accepted".
//
// The baseline is keyed by TUPLE (school_id|user_id|checker|maker), never by count. A count ratchet
// passes on the day one result.* finding is resolved and one finance finding appears — precisely the
// event this control exists to catch, and the reason these arms plant one of each.

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** Run the command and return its exit code. Pest's `artisan()` helper is a TestCase method. */
function dsbRun(array $options = []): int
{
    return Artisan::call('finance:audit-duty-separation', $options);
}

/** A scratch baseline file, cleaned up by the caller. */
function dsbWrite(array $keys): string
{
    $path = storage_path('framework/testing/duty-separation-baseline-'.uniqid().'.txt');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "# scratch\n".implode("\n", $keys)."\n");

    return $path;
}

/**
 * Plant a both-sides user by RAW insert, which is the only way one can arise: grant-time enforcement
 * (DutySeparation::assertAssignmentAllowed, User.php) refuses the pairing through the spatie API.
 * That is exactly why a detector exists — the paths enforcement cannot reach.
 *
 * @return array{0: User, 1: School}
 */
function dsbPlant(string $makerRole, string $checkerRole): array
{
    $school = School::factory()->create();
    $user = User::factory()->create(['school_id' => $school->id]);

    foreach ([$makerRole, $checkerRole] as $roleName) {
        DB::table('model_has_roles')->insert([
            'role_id' => Role::where('name', $roleName)->where('guard_name', 'web')->whereNull('school_id')->firstOrFail()->id,
            'model_type' => User::class,
            'model_id' => $user->id,
            'school_id' => $school->id,
        ]);
    }
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$user, $school];
}

it('ARM 1 — a result.* finding ALREADY in the baseline exits ZERO', function () {
    // teacher holds result.submit; admin holds result.approve/reject (ADR 0044 checker side).
    [$user, $school] = dsbPlant('teacher', 'admin');

    $path = dsbWrite([
        "{$school->id}|{$user->id}|result.approve|result.submit",
        "{$school->id}|{$user->id}|result.reject|result.submit",
    ]);

    try {
        // Without the baseline this same database is a hard failure — assert that first, or ARM 1
        // could be passing because the plant did nothing.
        expect(dsbRun())->toBe(1);

        expect(dsbRun(['--baseline' => $path]))->toBe(0);
    } finally {
        File::delete($path);
    }
});

it('ARM 2 — a finding NOT in the baseline exits NON-ZERO', function () {
    [$user, $school] = dsbPlant('teacher', 'admin');

    // A baseline that accepts the same shape for a DIFFERENT user. The count is identical; the tuple
    // is not. A count ratchet would pass here.
    $path = dsbWrite([
        "{$school->id}|999999|result.approve|result.submit",
        "{$school->id}|999999|result.reject|result.submit",
    ]);

    try {
        expect(dsbRun(['--baseline' => $path]))->toBe(1);
    } finally {
        File::delete($path);
    }
});

it('ARM 3 — a FINANCE finding fails even when it IS in the baseline', function () {
    // THE HARD-CODED HALF. accounts_officer holds finance.credit-note.submit; accounts_supervisor
    // holds .approve/.reject. Both lines are written into the baseline, and the command must ignore
    // them: a control that can be silenced by editing a file is exactly as strong as whoever last
    // edited the file.
    [$user, $school] = dsbPlant('accounts_officer', 'accounts_supervisor');

    $path = dsbWrite([
        "{$school->id}|{$user->id}|finance.credit-note.approve|finance.credit-note.submit",
        "{$school->id}|{$user->id}|finance.credit-note.reject|finance.credit-note.submit",
        "{$school->id}|{$user->id}|finance.invoice.void-request.approve|finance.invoice.void-request.submit",
        "{$school->id}|{$user->id}|finance.invoice.void-request.reject|finance.invoice.void-request.submit",
    ]);

    try {
        expect(dsbRun(['--baseline' => $path]))->toBe(1);
    } finally {
        File::delete($path);
    }
});

it('ARM 4 — the resolved-one-appeared-one case a COUNT ratchet would pass', function () {
    // The event this whole control exists to catch, staged directly: the baseline accepts result.*
    // findings for a user who no longer has them, and finance findings appear instead. A count
    // comparison would exit zero here.
    //
    // MEASURED, because the first version of this comment claimed more than the arm proves: under a
    // count-ratchet mutant this arm stays GREEN, caught by the finance hard-code rather than by the
    // tuple test. Two independent guards both refuse it, which is the right outcome and the wrong
    // demonstration. ARM 2 is where the TUPLE keying is actually proven — same shape, different user,
    // equal counts, no finance involved — and a count-ratchet mutant turns ARM 2 red.
    //
    // What this arm therefore pins: the finance hard-code fires even when the counts line up, so the
    // two guards are independent and neither is load-bearing alone.
    [$user, $school] = dsbPlant('accounts_officer', 'accounts_supervisor');

    $path = dsbWrite([
        "{$school->id}|424242|result.approve|result.submit",
        "{$school->id}|424242|result.reject|result.submit",
    ]);

    try {
        expect(dsbRun(['--baseline' => $path]))->toBe(1);
    } finally {
        File::delete($path);
    }
});

it('ARM 5 — a MISSING baseline file does not pass; a control that cannot look is not green', function () {
    dsbPlant('teacher', 'admin');

    expect(dsbRun(['--baseline' => storage_path('framework/testing/no-such-baseline.txt')]))
        ->toBe(1);
});

it('ARM 6 — with no findings at all, the baseline path is still not required to exist for a clean DB… but it is', function () {
    // No plant: a fresh seed has no both-sides user, so the command short-circuits to SUCCESS before
    // the baseline is ever consulted. Recorded so the early return is deliberate and visible rather
    // than discovered: a clean database exits 0 whether or not --baseline points anywhere.
    expect(dsbRun())->toBe(0)
        ->and(dsbRun(['--baseline' => 'nowhere.txt']))->toBe(0);
});
