<?php

use App\Exceptions\MissingSchoolContextException;
use App\Models\Student;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * M1.3b pre-merge regression proof for the Shared Kernel change with observable
 * behaviour — SchoolScope (the load-bearing change). The gate moved from
 * `auth()->check()` to "ActiveSchool::id() resolves", so scoping now also applies
 * off-request via runFor(); this asserts the REQUEST path is unchanged. Evidence,
 * not reasoning.
 *
 * (BelongsToSchool's creating-fill is verified separately: it is byte-equivalent
 * to staging on the request path, and School-owned rows are created with an
 * explicit school_id by the services the jobs call — see the review report.)
 */
uses(RefreshDatabase::class);

function bindSessionWith(array $data): void
{
    $session = app('session')->driver();
    foreach ($data as $k => $v) {
        $session->put($k, $v);
    }
    request()->setLaravelSession($session);
}

function studentIn(int $schoolId): Student
{
    return Student::withoutGlobalScopes()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolId,
        'first_name' => 'S',
        'last_name' => (string) Str::uuid(),
        'admission_number' => 'ADM-'.Str::random(8),
        'status' => 'active',
    ]);
}

// ── §1 Authenticated request path — scoping unchanged ────────────────────────

it('§1 authenticated request: SchoolScope scopes reads to the active School', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    studentIn($a->id);
    studentIn($b->id);
    studentIn($b->id);

    $this->actingAs(al_makeUser($a->id));

    expect(ActiveSchool::id())->toBe($a->id)
        ->and(Student::count())->toBe(1); // only School A — unchanged
});

it('§1 authenticated request with fail-closed opt-in and context present: no throw', function () {
    config(['rbac.fail_closed_models' => [Student::class]]);
    $a = al_makeSchool();
    studentIn($a->id);
    $this->actingAs(al_makeUser($a->id));

    expect(fn () => Student::count())->not->toThrow(MissingSchoolContextException::class)
        ->and(Student::count())->toBe(1);
});

// ── §2 Unauthenticated request path (adversarial) — the critical case ─────────

it('§2 session school_id WITHOUT an authenticated user is NOT consulted by ActiveSchool::id()', function () {
    // Expired auth / post-selection-pre-auth: a valid session carries school_id
    // but auth()->check() is false. ActiveSchool::id() (unchanged by M1.3b —
    // ADR 0042) reads the session only AFTER resolving an authenticated user.
    bindSessionWith(['school_id' => 999]);

    expect(auth()->check())->toBeFalse()
        ->and(request()->hasSession())->toBeTrue()
        ->and(request()->session()->get('school_id'))->toBe(999)
        ->and(ActiveSchool::id())->toBeNull(); // load-bearing: session ignored w/o a user
});

it('§2 unauthenticated + session school_id: SchoolScope does NOT scope and does NOT throw', function () {
    // Maximise visibility of any regression: opt Student into fail-closed.
    config(['rbac.fail_closed_models' => [Student::class]]);
    $a = al_makeSchool();
    $b = al_makeSchool();
    studentIn($a->id);
    studentIn($b->id);

    bindSessionWith(['school_id' => $a->id]); // session set, but nobody authenticated

    expect(auth()->check())->toBeFalse();
    // id() null -> no scope applied -> read spans Schools (pre-M1.3b behaviour),
    // and the throw is auth-gated -> no MissingSchoolContextException.
    expect(fn () => Student::count())->not->toThrow(MissingSchoolContextException::class);
    expect(Student::count())->toBe(2); // unscoped, both Schools — identical to staging
});

// ── §3 Queued execution — scoping via the declared School (the new capability) ─

it('§3 queued: SchoolScope scopes reads to the declared School under runFor, with no auth/session', function () {
    config(['rbac.fail_closed_models' => [Student::class]]);
    $a = al_makeSchool();
    $b = al_makeSchool();
    studentIn($a->id);
    studentIn($b->id); // foreign-School row that must stay invisible

    expect(auth()->check())->toBeFalse(); // no principal at all

    ActiveSchool::runFor($a->id, function () use ($a) {
        // The declared School is the sole context: reads see only its rows, and
        // the fail-closed opt-in does not throw because context IS present.
        expect(ActiveSchool::id())->toBe($a->id)
            ->and(Student::count())->toBe(1);
    });

    // Fully restored afterwards — no leak into the next job.
    expect(ActiveSchool::id())->toBeNull();
});

it('§3 queued without a declared School still fails closed for an authenticated principal', function () {
    // Proves the throw path is intact off-request when a principal exists but no
    // School — the SchoolAwareJobsTest legacy-impersonation case, distilled.
    config(['rbac.fail_closed_models' => [Student::class]]);
    Auth::setUser(al_makeUser(al_makeSchool()->id)->forceFill(['school_id' => null]));

    expect(fn () => Student::count())->toThrow(MissingSchoolContextException::class);
    Auth::logout();
});
