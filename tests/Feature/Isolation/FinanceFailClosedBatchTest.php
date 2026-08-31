<?php

/*
 * THE FINANCE TRANSACTIONAL MODELS FAIL CLOSED, AND THE LIST IS THE REPOSITORY'S, NOT A DEPLOY'S.
 *
 * Two separate claims live here and they fail for different reasons, so they are asserted
 * separately:
 *
 *   1. BEHAVIOUR — an authenticated read of one of these models with no active School context
 *      throws MissingSchoolContextException rather than silently returning every School's rows.
 *      This is the protection itself.
 *   2. PROVENANCE — that protection arrives from config/rbac.php's versioned default, so it holds
 *      in an environment that has never heard of RBAC_FAIL_CLOSED_MODELS. A behaviour test alone
 *      cannot see the difference between "the default is right" and "this machine's .env happens
 *      to set it", and the second is not a protection, it is a coincidence that survived a deploy.
 *
 * WHY THESE TWELVE AND NOT THE OTHER SIX. The batch is the finance TRANSACTIONAL set — the models
 * that record what was owed, charged, paid and reversed. The catalog models (FeeSchedule, FeeItem,
 * DiscountPolicy, the two change tables, SchoolFinanceSettings) are a later batch on their own
 * evidence.
 *
 * TWO OF THE TWELVE ARRIVED LATE AND ON MEASUREMENT, WHICH IS THE INTERESTING PART. U6's
 * BulkInvoiceRun and BulkInvoiceRunRow shipped without an entry here, and a cold review found what
 * that cost: a super admin with no school selected read EIGHT runs across both drive schools from
 * GET /api/v1/finance/bulk-invoice-runs, and opened either school's run detail. They belong on this
 * list and not with the catalog: a run is the record of a billing ACT, and its rows name a student,
 * an enrollment and the invoice raised for them.
 *
 * THE LESSON GENERALISES AND IS TICKETED RATHER THAN FIXED HERE: this allowlist is OPT-IN, so every
 * new School-owned finance model is fail-OPEN until someone remembers it, and remembering is not a
 * control. See docs/handoff/tickets/fail-closed-allowlist-is-opt-in.md.
 *
 * The behaviour arms READ the list rather than restating it, so they cannot drift into testing
 * their own agreement with themselves. The twelve names appear literally exactly once, in the
 * provenance arm, because a list read from the thing it is checking cannot notice that thing
 * getting shorter.
 *
 * THE UNAUTHENTICATED ARM IS THE ONE THAT WOULD BREAK LOGIN. SchoolScope's throw is gated on
 * auth()->check(), so every pre-authentication path — the login lookup, public pages, the password
 * reset — must be untouched by this flip. If that gate ever inverts, the platform stops being able
 * to log anyone in, and it would do so with a stack trace pointing at a finance model.
 */

use App\Exceptions\MissingSchoolContextException;
use App\Finance\Models\BulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRunRow;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Models\InvoiceLine;
use App\Finance\Models\LedgerTransaction;
use App\Finance\Models\ManualInvoiceRun;
use App\Finance\Models\ManualInvoiceRunLine;
use App\Finance\Models\ManualInvoiceRunRow;
use App\Finance\Models\ManualInvoiceRunTarget;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Finance\Models\StudentAccount;
use App\Finance\Models\VoidRequest;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The batch as the REPOSITORY ships it — read by requiring config/rbac.php directly, with the env
 * var forced absent.
 *
 * It cannot come from config() here: Pest resolves a dataset at collection time, before the
 * application boots, so config() is empty and the dataset would silently be empty too — which Pest
 * reports as "no dataset provided" rather than as a passing test, but only because it happens to
 * refuse empty ones. Requiring the file is deterministic and needs no boot.
 *
 * @return list<class-string<Model>>
 */
function ffcShipped(): array
{
    $before = getenv('RBAC_FAIL_CLOSED_MODELS');
    putenv('RBAC_FAIL_CLOSED_MODELS');
    unset($_ENV['RBAC_FAIL_CLOSED_MODELS'], $_SERVER['RBAC_FAIL_CLOSED_MODELS']);

    try {
        $config = require dirname(__DIR__, 3).'/config/rbac.php';
    } finally {
        if ($before !== false) {
            putenv('RBAC_FAIL_CLOSED_MODELS='.$before);
            $_ENV['RBAC_FAIL_CLOSED_MODELS'] = $before;
            $_SERVER['RBAC_FAIL_CLOSED_MODELS'] = $before;
        }
    }

    /** @var list<class-string<Model>> $models */
    $models = array_values((array) $config['fail_closed_models']);

    return $models;
}

/**
 * The batch the RUNNING application is actually using — which is the shipped one unless this
 * environment overrides it.
 *
 * @return list<class-string<Model>>
 */
function ffcConfigured(): array
{
    /** @var list<class-string<Model>> $models */
    $models = array_values((array) config('rbac.fail_closed_models', []));

    return $models;
}

/**
 * A principal with no home School — the shape every arm here needs.
 *
 * Built through the factory rather than forceCreate(). The sibling file
 * tests/Feature/Isolation/SchoolScopeFailsClosedTest.php uses forceCreate for the same thing, but
 * this file's name matches `tests/.*Finance` and so trips the boundary lint's
 * force-create-finance-tests rule, which exists because forceCreate bypasses MoneyCast. Nothing here
 * creates money, so the rule is blunt about this file — and it is right to be: a name-scoped hard
 * rule that a test can talk its way out of is not a rule. The factory costs nothing.
 */
function ffcUserWithoutSchool(): User
{
    return User::factory()->create(['school_id' => null]);
}

it('ships the finance transactional batch as the default, with no env var set', function () {
    // PROVENANCE, and it is asserted against a FRESHLY LOADED config file rather than the runtime
    // one, because the runtime value could have come from this machine's .env. Re-requiring the
    // file with the env var explicitly absent is the only way to see what a new environment gets.
    $before = getenv('RBAC_FAIL_CLOSED_MODELS');
    putenv('RBAC_FAIL_CLOSED_MODELS');
    unset($_ENV['RBAC_FAIL_CLOSED_MODELS'], $_SERVER['RBAC_FAIL_CLOSED_MODELS']);

    try {
        $fresh = require dirname(__DIR__, 3).'/config/rbac.php';
    } finally {
        if ($before !== false) {
            putenv('RBAC_FAIL_CLOSED_MODELS='.$before);
            $_ENV['RBAC_FAIL_CLOSED_MODELS'] = $before;
            $_SERVER['RBAC_FAIL_CLOSED_MODELS'] = $before;
        }
    }

    expect($fresh['fail_closed_models'])->toBe([
        LedgerTransaction::class,
        Payment::class,
        PaymentAllocation::class,
        Invoice::class,
        InvoiceLine::class,
        CreditNote::class,
        StudentAccount::class,
        OpeningBalanceBatch::class,
        OpeningBalanceRow::class,
        VoidRequest::class,
        BulkInvoiceRun::class,
        BulkInvoiceRunRow::class,
        ManualInvoiceRun::class,
        ManualInvoiceRunLine::class,
        ManualInvoiceRunRow::class,
        ManualInvoiceRunTarget::class,
    ], 'config/rbac.php no longer ships the finance transactional batch as its DEFAULT. If this '
        .'list moved into an environment variable, the protection became something a deploy can '
        .'forget: an environment that sets nothing would read every School\'s money rows unscoped.');
});

it('a BLANK RBAC_FAIL_CLOSED_MODELS falls back to the default instead of disabling everything', function () {
    // THE COPY-PASTE FAILURE MODE. env() distinguishes an absent key from a present-but-empty one,
    // so a bare `RBAC_FAIL_CLOSED_MODELS=` line — which is exactly what an .env copied from a
    // template carries — returns "" and would otherwise take the default's place and switch the
    // whole batch off, platform-wide, with nothing to notice it. Measured, not assumed: the
    // behaviour of env() on a blank value is the entire reason the config trims before falling back.
    putenv('RBAC_FAIL_CLOSED_MODELS=');
    $_ENV['RBAC_FAIL_CLOSED_MODELS'] = '';
    $_SERVER['RBAC_FAIL_CLOSED_MODELS'] = '';

    try {
        $fresh = require dirname(__DIR__, 3).'/config/rbac.php';
    } finally {
        putenv('RBAC_FAIL_CLOSED_MODELS');
        unset($_ENV['RBAC_FAIL_CLOSED_MODELS'], $_SERVER['RBAC_FAIL_CLOSED_MODELS']);
    }

    // Asserted as "blank behaves exactly like absent", not as "blank is non-empty" — and asserted
    // POSITIVELY, because Pest discards a custom failure message on every matcher under ->not->
    // (see tests/Feature/Quality/PestNegatedExpectationMessagesTest.php). A bare ->not->toBeEmpty()
    // here would fail with "Expecting [] not to be empty" and say nothing about what that costs.
    expect($fresh['fail_closed_models'])->toBe(ffcShipped(),
        'A blank RBAC_FAIL_CLOSED_MODELS no longer falls back to the shipped default. An .env '
        .'carrying the bare line from a template would disable School isolation on every finance '
        .'money model at once, platform-wide and silently.');
});

it('throws on an authenticated read with no active School context', function (string $model) {
    $this->actingAs(ffcUserWithoutSchool());

    // count() rather than get(): the throw comes from SchoolScope::apply, which runs on any query
    // the model builds, so the cheapest possible read is the strictest test of it.
    expect(fn () => $model::query()->count())
        ->toThrow(MissingSchoolContextException::class);
})->with(ffcShipped());

it('names the model it refused in the exception, so the stack trace says which read it was', function () {
    // A MissingSchoolContextException that does not say what was being read sends whoever hits it
    // to the scope rather than to their own query. There are ten models on this list and the
    // message is the only thing that distinguishes them at the point of failure.
    $this->actingAs(ffcUserWithoutSchool());

    $message = '';

    try {
        Invoice::query()->count();
    } catch (MissingSchoolContextException $e) {
        $message = $e->getMessage();
    }

    expect(str_contains($message, 'Invoice'))->toBeTrue(
        'MissingSchoolContextException does not name the model. Got: '.$message);
});

it('leaves UNAUTHENTICATED reads completely unchanged', function () {
    // The auth()->check() gate. Nobody is logged in, so there is no School to be missing and
    // nothing to fail closed over — every pre-authentication path must behave as it did before this
    // batch existed. Asserted as a real query returning a real count, not as "did not throw".
    expect(auth()->check())->toBeFalse();

    $count = Invoice::query()->count();

    expect($count)->toBeInt();
});

it('still scopes normally when a School context IS set — the batch changes nothing for a seat with a school', function () {
    // The whole population that matters in term one. Fail-closed only replaces the SILENT-UNSCOPED
    // branch; the scoped branch is untouched, and an arm that never proves it leaves open the
    // reading that this commit broke ordinary finance reads to fix an edge case.
    $school = School::factory()->create();
    $user = ffcUserWithoutSchool();
    $this->actingAs($user);

    ActiveSchool::runFor($school->id, function () {
        foreach (ffcConfigured() as $model) {
            expect($model::query()->count())->toBeInt();
        }
    });
});

it('does NOT exempt a super_admin — authority and isolation are separate axes', function () {
    // DELIBERATE, AND THE FINDING THIS COMMIT CARRIES. SetSchoolContext lets a super_admin through
    // with no school selected (app/Http/Middleware/SetSchoolContext.php:51), and until this batch
    // that meant their finance screens read every School's money rows at once. Opting these models
    // in ends that — for them the screens now throw instead. That is the intended consequence, not
    // collateral: SchoolScope's own docblock states there is no super-admin exemption by design.
    //
    // It is pinned here because it is the one behaviour change a reader is most likely to mistake
    // for a bug and "fix" by adding an exemption.
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $super = ffcUserWithoutSchool();
    $super->assignRole('super_admin');

    $this->actingAs($super);

    expect($super->isSuperAdmin())->toBeTrue();

    expect(fn () => Invoice::query()->count())
        ->toThrow(MissingSchoolContextException::class);
});

it('refuses a super_admin finance API read with no school as a clean 409, not a 500', function () {
    // WHAT THE BROWSER DRIVE WOULD HAVE SHOWN, asserted at the HTTP layer instead — see the report:
    // the copy has exactly one super_admin (user#3197) and driving them needs a credential change on
    // a production copy, which is the project lead's to authorise, not mine to take.
    //
    // This is the behaviour change that matters. SetSchoolContext lets a super_admin through with no
    // school selected (app/Http/Middleware/SetSchoolContext.php:51), so before this batch
    // /api/v1/finance/accounts answered them with EVERY School's student accounts in one list.
    // It now refuses. The status code is the point: 409 from
    // MissingSchoolContextException::render() means a deliberate context refusal reached the client,
    // not an unhandled 500 — an isolation fix that shows a stack trace is a different, worse bug.
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $super = ffcUserWithoutSchool();
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();

    $response = $this->actingAs($super)->getJson('/api/v1/finance/accounts');

    expect($response->status())->toBe(409,
        'A super_admin with no school selected got '.$response->status().' from the finance accounts '
        .'endpoint. 409 is the fail-closed refusal; 200 means the read went through unscoped and '
        .'returned every School\'s accounts, and 500 means the exception escaped its own renderer.');

    expect($response->json('message'))->toBe('No active school selected.');
});

it('leaves the finance web SHELL rendering — the refusal lands in its data fetch, not the page', function () {
    // MEASURED, AND IT CORRECTS AN ASSUMPTION WORTH WRITING DOWN. MissingSchoolContextException
    // renders as a redirect to school.select on the web transport, so the obvious expectation is
    // that a super_admin with no school gets bounced to the school picker. They do not: every
    // finance web route is an Inertia SHELL that reads no finance model server-side
    // (routes/web.php:155-156 renders the statement page from the Student alone), so the page
    // returns 200 and the fail-closed refusal surfaces only in the XHR the page then issues — the
    // 409 asserted in the arm above.
    //
    // The consequence for the operator is therefore an error state INSIDE a rendered finance page,
    // not a redirect to "select a school", and this arm exists so that stays a known, chosen
    // behaviour rather than a surprise. It also pins the shell against a future edit that moves a
    // finance read server-side, which would silently convert this 200 into a redirect.
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $super = ffcUserWithoutSchool();
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();

    $response = $this->actingAs($super)->get('/finance/students/'.Student::factory()->create()->uuid.'/statement');

    expect($response->status())->toBe(200,
        'The finance statement shell no longer renders for a super_admin with no school. If a '
        .'finance read moved server-side it will now be a 302 to the school picker — which is '
        .'arguably better UX, but it is a different behaviour from the one this commit shipped and '
        .'the report describes.');
});

it('runs against the batch the repository ships, not one this environment substituted', function () {
    // THE DATASET IS ONLY AS HONEST AS ITS SOURCE. The behaviour arms iterate ffcShipped() — the
    // config FILE — so if this machine's .env sets RBAC_FAIL_CLOSED_MODELS to something else, every
    // one of those arms would pass while the running application protected a different set of
    // models, or none. Nothing else in the file can see that gap, because both sides of it would be
    // internally consistent.
    //
    // Shrinkage is caught elsewhere and deliberately so: the ten names are written out literally in
    // the "ships … as the default" arm above, which is the one place the duplication earns its
    // keep. A model removed from config takes its own dataset arm with it silently, so an arm that
    // compared config to config could never notice.
    expect(ffcConfigured())->toBe(ffcShipped(),
        'The running configuration and the shipped config/rbac.php disagree about which models fail '
        .'closed. RBAC_FAIL_CLOSED_MODELS is set in this environment, so the arms in this file are '
        .'proving a batch the application is not actually using.');
});
