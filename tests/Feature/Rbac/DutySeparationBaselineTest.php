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

use App\Console\Commands\AuditDutySeparation;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\DutySeparation;
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

it('ARM 5 — a MISSING baseline file says NOT AUDITED and names the path', function () {
    // THIS IS A MESSAGE GUARD, NOT AN EXIT-CODE CONTROL, and the arm's first version overstated it.
    //
    // Measured: the guard never changes the exit code in any reachable state. With no findings the
    // command short-circuits to SUCCESS before the baseline is read, so the guard is not reached at
    // all. With findings present, a missing file treated as an EMPTY baseline would leave every
    // finding unaccepted and exit 1 through that branch anyway — the same 1. So asserting only the
    // code proves nothing about the guard.
    //
    // What the guard is actually for is the operator's ability to tell the two 1s apart: "these
    // findings are not accepted" and "I could not read the file you named" demand different actions,
    // and a control that cannot look must say so rather than looking like a clean refusal. Hence the
    // assertion is on the OUTPUT.
    dsbPlant('teacher', 'admin');

    $path = storage_path('framework/testing/no-such-baseline.txt');
    expect(File::exists($path))->toBeFalse();

    expect(dsbRun(['--baseline' => $path]))->toBe(1);

    $output = Artisan::output();
    expect($output)->toContain('NOT AUDITED')
        ->and($output)->toContain($path)
        // ...and it must NOT read as an ordinary unaccepted-findings failure, which is the confusion
        // the guard exists to prevent.
        ->and($output)->not->toContain('this is the regression');
});

it('ARM 6 — with no findings at all, the baseline path is still not required to exist for a clean DB… but it is', function () {
    // No plant: a fresh seed has no both-sides user, so the command short-circuits to SUCCESS before
    // the baseline is ever consulted. Recorded so the early return is deliberate and visible rather
    // than discovered: a clean database exits 0 whether or not --baseline points anywhere.
    expect(dsbRun())->toBe(0)
        ->and(dsbRun(['--baseline' => 'nowhere.txt']))->toBe(0);
});

// ── The committed baseline itself, and the constant it depends on ─────────────

it('ARM 7 — the COMMITTED baseline file is well-formed and contains no finance line', function () {
    // ADR 0041's rule — a baseline is only a control if a gate reads it — was true of the other five
    // *-baseline.txt files and not of this one: duty-separation-baseline.txt is read by the nightly
    // scheduled task and by nothing in bin/quality or CI. This arm is what makes it a sixth gated
    // baseline rather than a file nobody validates.
    //
    // Two properties, and the second is the one that matters. A malformed line silently accepts
    // nothing (it can never equal a real key), which is a hole that reads as a passing baseline. And
    // a finance line in the file would be inert — the command hard-codes the refusal ahead of the
    // read — but its PRESENCE would tell the next reader that finance findings are baselineable,
    // which is exactly the belief this design exists to prevent.
    $path = base_path('duty-separation-baseline.txt');

    expect(File::exists($path))->toBeTrue("the committed baseline [{$path}] is missing");

    $lines = collect(explode("\n", (string) File::get($path)))
        ->map(fn (string $l): string => trim($l))
        ->reject(fn (string $l): bool => $l === '' || str_starts_with($l, '#'))
        ->values();

    // THE DAY THIS FILE SHOULD BE EMPTY, DELETE IT — do not commit an empty one, and do not let this
    // assertion turn the correct act into a red gate. When the last result.* finding is resolved,
    // remove `duty-separation-baseline.txt` AND the `--baseline=` argument in routes/console.php
    // together, in one diff. That is safe while the database is clean: the command short-circuits to
    // SUCCESS before the baseline is ever read, so an unfiltered run on a clean database exits 0.
    // The next new finding then fails as an ordinary non-zero rather than as NOT AUDITED, which is
    // the right failure — nothing has been accepted, so there is nothing to compare against.
    expect($lines)->not->toBeEmpty('an empty baseline should be deleted along with the --baseline= argument, not committed empty');

    $malformed = $lines->reject(fn (string $l): bool => (bool) preg_match('/^\d+\|\d+\|[^|\s]+\|[^|\s]+$/', $l))->values();
    expect($malformed->all())->toBe([],
        'every line must be exactly school_id|user_id|checker|maker with integer ids and no extra field — '
        .'a five-field line is not a key and can never match a finding, so it accepts nothing while reading as accepted');

    $finance = $lines->filter(function (string $l): bool {
        [, , $checker, $maker] = explode('|', $l, 4);

        return str_starts_with($checker, AuditDutySeparation::NEVER_BASELINEABLE)
            || str_starts_with($maker, AuditDutySeparation::NEVER_BASELINEABLE);
    })->values();

    expect($finance->all())->toBe([], 'a finance finding can never be accepted here — it is inert in the command and misleading in the file');
});

it('ARM 8 — every ENFORCED pair is in the namespace the baseline can never amnesty', function () {
    // THE DUPLICATION, PINNED RATHER THAN REMOVED. `AuditDutySeparation::NEVER_BASELINEABLE` and the
    // literal inside `DutySeparation::enforcedPairs()` are the same string and are deliberately
    // independent: that method's docblock keeps its scope boundary as "one obvious, commented line so
    // widening the blast radius later is a one-line change and is visibly a one-line change". Deriving
    // one from the other would collapse two separate decisions into one.
    //
    // So the risk is not duplication, it is DRIFT: rename the namespace on the enforcement side and
    // the baseline's refusal quietly stops covering the pairs that are actually enforced — finance
    // findings become baselineable without anyone editing the baseline. This assertion makes that
    // rename red on the spot.
    $enforced = DutySeparation::enforcedPairs();

    expect($enforced)->not->toBeEmpty('no enforced pairs at all would make this arm vacuous');

    $outside = collect($enforced)
        ->reject(fn (array $p): bool => str_starts_with($p['checker'], AuditDutySeparation::NEVER_BASELINEABLE))
        ->map(fn (array $p): string => $p['checker'])
        ->values();

    expect($outside->all())->toBe([],
        'an enforced pair outside ['.AuditDutySeparation::NEVER_BASELINEABLE.'] would be silently baselineable');
});
