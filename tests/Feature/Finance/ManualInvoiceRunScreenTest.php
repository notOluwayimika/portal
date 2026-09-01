<?php

/*
 * THE MANUAL INVOICE RUN OVER HTTP — the bursar's selection in, and the RUN REPORT back.
 *
 * WHAT THIS FILE PROVES AND WHAT IT LEAVES TO ManualInvoiceRunTest. That file drives the JOB and
 * owns every rule about what a run DOES — claim-then-bill, the two unique indexes on the rows table,
 * the generated-column run key at the engine, the trigger-enforced domains, the equality under a
 * stuck claim. None of that is re-proved here and none of it should be: a second copy of that
 * coverage is a second thing to keep in step. What is proved here is the SURFACE.
 *
 * THE REPORT IS THE SUBJECT, NOT THE START. Brookstone ruled on 30 August 2026 that this feature
 * issues DIRECTLY — no maker-checker, no second signature. There is therefore no second human
 * between a selection and ninety real charges, and the report is the ONLY place a bursar can
 * discover that they ticked 96 and 90 were billed. Most of the arms below are about it.
 *
 * The claims, in the order they matter:
 *
 *   1. A TICKED STUDENT WHO CANNOT BE PLACED IS COUNTED, IS `unplaceable`, AND IS NAMED — admission
 *      number, on the report, not a tally. `billed + failed + unplaceable == target_count` holds
 *      with them in it, and `target_count` is read from the TARGETS table, which is the list the
 *      bursar submitted rather than what survived resolution.
 *   2. `claimed` IS SHOWN SEPARATELY AND IS NEVER A TERM. A run that finishes with a claim
 *      outstanding must report `balances: false` — that is the alarm, and it is the only thing
 *      standing where a second signature would otherwise be.
 *   3. ISOLATION IS REFUSED, NOT FILTERED. A cross-School student id in the payload is a 422 that
 *      names it. `super_admin` bypasses authorization and NEVER isolation, so the arm proves it on
 *      that seat too.
 *   4. A SECOND RUN IS A FRIENDLY 422 NAMING THE ONE IN FLIGHT. The guard is the database (1062 on
 *      the generated column); left alone it reaches a bursar as "Duplicate entry detected."
 *   5. A SPONSORED STUDENT IS BILLED. This feature exists partly to bill them.
 *   6. THE SELECTION IS RESOLVED IN ONE READ AND WRITTEN IN ONE INSERT. Neither the Action's reads
 *      nor its writes grow with the size of the selection — the shape a re-introduced per-student
 *      resolver or a re-introduced per-row create() breaks, asserted as a shape rather than as a
 *      query total. And because a batched insert fires no model events, the rows it writes are
 *      checked column by column against what the per-row write produced — see section 7.
 *
 * THE QUEUE IS `sync` IN TESTS (phpunit.xml), so a POST runs the job inline and the report an arm
 * reads back is the finished one. That is deliberate rather than convenient: it exercises the job's
 * own SchoolAware middleware and its School context, which a fake would skip while reporting green.
 *
 * Every guard here was PLANTED and watched red before it was believed; the plants and their verbatim
 * red text are in docs/handoff/reports/feat-manual-invoice-run-selection-and-report.md.
 */

use App\Enums\ScholarshipKind;
use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Finance\Actions\StartManualInvoiceRun;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\ManualInvoiceRunOutcome;
use App\Finance\Enums\ManualInvoiceRunStatus;
use App\Finance\Jobs\ProcessManualInvoiceRun;
use App\Finance\Models\Invoice;
use App\Finance\Models\ManualInvoiceRun;
use App\Finance\Models\ManualInvoiceRunLine;
use App\Finance\Models\ManualInvoiceRunRow;
use App\Finance\Models\ManualInvoiceRunTarget;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Scholarship;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

const MIRS_ACCESS = 'finance.access';

const MIRS_GENERATE = 'finance.invoice.generate';

/*
 * FIXTURE HELPERS, prefixed `mirs` throughout. ManualInvoiceRunTest already defines `mirSchool`,
 * `mirStudent` and friends, and Pest's helpers are ordinary GLOBAL functions — two files declaring
 * one name is a fatal redeclaration whose trigger is suite load order. The prefix is the same
 * discipline the `sr_` / `rc_` fixture namespacing exists for, one layer up.
 */

/** @return array{school: School, term: Term, level: ClassLevel, arm: ClassLevelArm} */
function mirsSchool(): array
{
    $school = School::factory()->create();

    return ActiveSchool::runFor($school->id, function () use ($school) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
            'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
        ]);
        $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);
        $arm = ClassLevelArm::create([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'arm_id' => Arm::create(['school_id' => $school->id, 'label' => strtoupper(Str::random(3))])->id,
        ]);

        return compact('school', 'term', 'level', 'arm');
    });
}

/** A student in $ctx's School, ACTIVELY enrolled unless $enrolled is false. */
function mirsStudent(array $ctx, bool $enrolled = true, ?Scholarship $scholarship = null): Student
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $enrolled, $scholarship) {
        $student = Student::factory()->create([
            'school_id' => $ctx['school']->id,
            'admission_number' => 'ADM-'.Str::random(8),
            'scholarship_id' => $scholarship?->id,
        ]);

        if ($enrolled) {
            mirsEnroll($ctx, $student);
        }

        return $student;
    });
}

/** One more ACTIVE episode for $student, at its own curriculum. Returns it. */
function mirsEnroll(array $ctx, Student $student): StudentCurriculum
{
    return ActiveSchool::runFor($ctx['school']->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'school_id' => $ctx['school']->id,
        'curriculum_id' => Curriculum::factory()->create([
            'school_id' => $ctx['school']->id,
            'class_level_arm_id' => $ctx['arm']->id,
            'term_id' => $ctx['term']->id,
        ])->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]));
}

function mirsSponsorship(array $ctx): Scholarship
{
    return ActiveSchool::runFor($ctx['school']->id, fn () => Scholarship::create([
        'school_id' => $ctx['school']->id,
        'name' => 'C2C '.Str::random(4),
        'kind' => ScholarshipKind::Sponsored,
    ]));
}

/**
 * A web-session user holding EXACTLY $permissions — the shape BulkInvoiceRunScreenTest and
 * OpeningBalanceOperatorScreenTest both use, for their reason: role membership is what a grants
 * commit changes, so a role-keyed actor would move with the thing under test.
 *
 * @param  list<string>  $permissions
 */
function mirsUser(School $school, array $permissions): User
{
    $roleName = 'mirs_'.substr(md5(implode(',', $permissions)), 0, 10);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $roleName);
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** The acting seat, always with an explicit School session — no route relies on ambient leakage. */
function mirsAs(User $actor, School $school)
{
    return test()->actingAs($actor)->withSession(['school_id' => $school->id]);
}

/**
 * The payload. `$studentUuids` is what the bursar ticked, addressed the way the client holds them.
 *
 * @param  list<string>  $studentUuids
 * @return array<string, mixed>
 */
function mirsPayload(array $studentUuids, int $schoolId, int $amountMinor = 250000): array
{
    return [
        'student_ids' => $studentUuids,
        'lines' => [[
            'description' => 'Replacement locker key',
            'amount_minor' => $amountMinor,
            'currency' => 'NGN',
            'bank_account_id' => testBankAccountUuid($schoolId),
        ]],
    ];
}

/**
 * A run built DIRECTLY — run row, one line, one target per member of $targets — for the arms whose
 * subject is the REPORT over a state the HTTP path cannot reach on its own (a stuck claim). Nothing
 * is dispatched; the arm dispatches when it has finished planting.
 *
 * @param  list<StudentCurriculum|Student>  $targets
 */
function mirsRunDirect(array $ctx, array $targets): ManualInvoiceRun
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $targets) {
        $run = ManualInvoiceRun::create([
            'school_id' => $ctx['school']->id,
            'status' => ManualInvoiceRunStatus::Pending,
        ]);

        ManualInvoiceRunLine::create([
            'school_id' => $ctx['school']->id,
            'run_id' => $run->id,
            'description' => 'Replacement locker key',
            'amount' => Money::fromKobo(250000, 'NGN'),
            'bank_account_id' => testBankAccountId($ctx['school']->id),
            'sort_order' => 0,
        ]);

        foreach ($targets as $target) {
            $placed = $target instanceof StudentCurriculum;

            ManualInvoiceRunTarget::create([
                'school_id' => $ctx['school']->id,
                'run_id' => $run->id,
                'student_id' => $placed ? $target->student_id : $target->id,
                'enrollment_id' => $placed ? $target->id : null,
                'enrollment_uuid' => $placed ? $target->uuid : null,
            ]);
        }

        return $run;
    });
}

/** Every admission number the report NAMES inside one outcome bucket. @return list<string> */
function mirsNamed(array $report, ManualInvoiceRunOutcome $outcome): array
{
    return array_values(array_map(
        static fn (array $row) => (string) ($row['student']['admission_number'] ?? ''),
        $report['buckets'][$outcome->value]['rows'],
    ));
}

/* ── 1 · The write: a selection becomes a run, its targets, its lines, and invoices ───────────── */

it('1a — creates the run, one target per ticked student, the shared lines, and bills them', function () {
    $ctx = mirsSchool();
    $one = mirsStudent($ctx);
    $two = mirsStudent($ctx);
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $response = mirsAs($actor, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([$one->uuid, $two->uuid], $ctx['school']->id),
    );

    $response->assertCreated();

    $run = ManualInvoiceRun::withoutGlobalScopes()->where('uuid', $response->json('uuid'))->sole();

    expect($run->school_id)->toBe($ctx['school']->id)
        ->and($run->started_by_user_id)->toBe($actor->id)
        ->and($run->status)->toBe(ManualInvoiceRunStatus::Completed);

    // ONE TARGET PER TICKED STUDENT, and the lines are shared across the whole run rather than
    // copied per student — one choice per LINE, which is what makes the destination account a single
    // decision (brief §5).
    expect(ManualInvoiceRunTarget::withoutGlobalScopes()->where('run_id', $run->id)->count())->toBe(2)
        ->and(ManualInvoiceRunLine::withoutGlobalScopes()->where('run_id', $run->id)->count())->toBe(1);

    // AND THE MONEY LANDED. Two supplementary invoices, one per student, at the full line amount.
    $invoices = Invoice::withoutGlobalScopes()
        ->whereIn('id', ManualInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->pluck('invoice_id'))
        ->get();

    expect($invoices)->toHaveCount(2);
});

it('1b — a SPONSORED student is BILLED, because this feature exists to bill them', function () {
    /*
     * The scheduled run EXCLUDES sponsored students, the predicate is shared between its preview and
     * its job, and it is pinned by a test — which makes it exactly the thing someone copies. This
     * feature is the mechanism that produces the C2C session bills
     * (`scholarship-and-cutover-decisions.md` §4), so reusing the cohort logic would drop the very
     * students it was built for. The arm is the discriminator: it goes red the moment that predicate
     * appears on this path.
     *
     * FULL PRICE, and there is nothing to build for that either. A scholarship covers termly fees and
     * does not touch a mid-term charge (Brookstone, 29 August); `GenerateInvoice` contains zero
     * references to `StudentDiscountAward`.
     */
    $ctx = mirsSchool();
    $sponsored = mirsStudent($ctx, scholarship: mirsSponsorship($ctx));
    $ordinary = mirsStudent($ctx);
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $uuid = mirsAs($actor, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([$sponsored->uuid, $ordinary->uuid], $ctx['school']->id, 400000),
    )->assertCreated()->json('uuid');

    $report = mirsAs($actor, $ctx['school'])
        ->getJson('/api/v1/finance/manual-invoice-runs/'.$uuid)->assertOk()->json();

    expect($report['counts'])->toBe(['billed' => 2, 'failed' => 0, 'unplaceable' => 0, 'claimed' => 0])
        ->and($report['target_count'])->toBe(2);

    // NAMED, not counted: the sponsored student is IN the billed bucket, so an exclusion cannot hide
    // behind a total that happens to match.
    expect(mirsNamed($report, ManualInvoiceRunOutcome::Billed))
        ->toContain($sponsored->admission_number)
        ->toContain($ordinary->admission_number);
});

/* ── 2 · Isolation — REFUSED, not filtered ────────────────────────────────────────────────────── */

it('2a — a cross-School student id in the payload is REFUSED, and no run is created', function () {
    /*
     * FILTERING WOULD BE THE WORSE FAILURE, and it is the one a `whereIn` under a scope produces by
     * default: the foreign id simply vanishes and the run bills the rest, balanced and complete and
     * about a different selection than the bursar made.
     *
     * MEASURED: with the isolation rule removed this arm answered **201**, not a database refusal.
     * The composite FK on the targets table cannot see a foreign id that was dropped before the
     * write, so this rule is the enforcement on this path and not a pre-check in front of one. That
     * is why the arm asserts the STATUS and the MESSAGE and the absence of a run — a status-only
     * assertion would stay green under a rule that refused for the wrong reason.
     */
    $ctx = mirsSchool();
    $other = mirsSchool();
    $mine = mirsStudent($ctx);
    $theirs = mirsStudent($other);
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    mirsAs($actor, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([$mine->uuid, $theirs->uuid], $ctx['school']->id),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors(['student_ids'])
        ->assertJsonFragment(['student_ids' => [
            'These students could not be found in this school, so they cannot be invoiced by it: '
            .$theirs->uuid
            .'. Nothing has been billed — remove them from the selection and start the run again.',
        ]]);

    expect(ManualInvoiceRun::withoutGlobalScopes()->count())->toBe(0)
        ->and(Invoice::withoutGlobalScopes()->count())->toBe(0);
});

it('2b — a SUPER ADMIN is refused the same cross-School id, because the bypass is authorization only', function () {
    /*
     * ADR 0036/0040: `super_admin` bypasses AUTHORIZATION, never ISOLATION. The bypass is switched on
     * so the seat actually reaches the controller — `finance.invoice.generate` is a maker ability, so
     * ADR 0040's checker exclusion does not apply to it and Gate::before answers the route's
     * middleware. Without it this arm would be measuring a 403 from the permission middleware and
     * would never reach the isolation check at all: green, and about nothing.
     */
    config(['auth.gate_before_superadmin' => true]);

    $ctx = mirsSchool();
    $other = mirsSchool();
    $theirs = mirsStudent($other);
    $mine = mirsStudent($ctx);

    $super = User::factory()->create(['school_id' => null]);
    setPermissionsTeamId(null);
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();

    // The `Referer` is load-bearing: /api/* carries Sanctum's statefulApi(), so without it the
    // session middleware never runs, ActiveSchool::id() skips the session branch, and a super admin
    // (excluded from the users.school_id fallback by design) would arrive with NO School — which is
    // a 409 and a different arm than this one.
    $acting = test()->actingAs($super)
        ->withHeader('Referer', config('app.url'))
        ->withSession(['school_id' => $ctx['school']->id]);

    $acting->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([$mine->uuid, $theirs->uuid], $ctx['school']->id),
    )->assertStatus(422)->assertJsonValidationErrors(['student_ids']);

    expect(ManualInvoiceRun::withoutGlobalScopes()->count())->toBe(0);

    // NOT VACUOUS. The same seat, the same School, WITHOUT the foreign id, is accepted — so the 422
    // above is the isolation check and not the seat being unable to start a run at all.
    $acting->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([$mine->uuid], $ctx['school']->id),
    )->assertCreated();

    expect(ManualInvoiceRun::withoutGlobalScopes()->count())->toBe(1);
});

it('2c — the report of another School\'s run is a 404, and with no School at all a 409', function () {
    $ctx = mirsSchool();
    $other = mirsSchool();
    $actorA = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);
    $actorB = mirsUser($other['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    /*
     * THE RUN BILLS NOBODY — its one target is a student with no enrollment, so every row is
     * `unplaceable` and no `finance_invoices` row is ever touched. That is not scenery: `Invoice` is
     * ALREADY in `rbac.fail_closed_models`, so a run with a billed row 409s on the eager-load of the
     * invoice whatever the manual-run models are set to. MEASURED — with a billed run this arm
     * passed with the four new entries REMOVED, proving nothing about them. An all-unplaceable run
     * never loads an Invoice, so the refusal here can only come from the entries this commit adds.
     */
    $uuidA = mirsAs($actorA, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([mirsStudent($ctx, enrolled: false)->uuid], $ctx['school']->id),
    )->assertCreated()->json('uuid');

    // School B cannot read School A's run…
    mirsAs($actorB, $other['school'])->getJson('/api/v1/finance/manual-invoice-runs/'.$uuidA)
        ->assertNotFound();

    // …and School A can, so the 404 is isolation rather than a broken route.
    mirsAs($actorA, $ctx['school'])->getJson('/api/v1/finance/manual-invoice-runs/'.$uuidA)
        ->assertOk();

    /*
     * AND WITH NO SCHOOL SELECTED THE READ IS REFUSED RATHER THAN ANSWERED UNSCOPED. This is what the
     * four `rbac.fail_closed_models` entries added by this commit buy: without them SchoolScope falls
     * to its SILENT-UNSCOPED branch, which is how the bulk run once answered a super admin with eight
     * runs spanning two Schools.
     */
    config(['auth.gate_before_superadmin' => true]);
    $super = User::factory()->create(['school_id' => null]);
    setPermissionsTeamId(null);
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();

    test()->actingAs($super)->getJson('/api/v1/finance/manual-invoice-runs/'.$uuidA)
        ->assertStatus(409)
        ->assertJsonPath('message', 'No active school selected.');
});

it('2d — a SOFT-DELETED student is refused too, and the message does not claim they are foreign', function () {
    /*
     * A deleted student is not a cross-School student, and the refusal must not say they are. Both
     * are "could not be found in this school", which is exactly what the lookup knows.
     *
     * REFUSING IS THE SAFE DIRECTION and the reason `withTrashed()` was dropped from the lookup (see
     * the request's docblock): every roster a bursar picks from already excludes trashed students, so
     * a trashed uuid can only come from a stale client, and declining to raise a charge against a
     * deleted student is better than raising one.
     */
    $ctx = mirsSchool();
    $gone = mirsStudent($ctx);
    $here = mirsStudent($ctx);
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    ActiveSchool::runFor($ctx['school']->id, fn () => $gone->delete());

    mirsAs($actor, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([$here->uuid, $gone->uuid], $ctx['school']->id),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors(['student_ids'])
        ->assertJsonFragment(['student_ids' => [
            'These students could not be found in this school, so they cannot be invoiced by it: '
            .$gone->uuid
            .'. Nothing has been billed — remove them from the selection and start the run again.',
        ]]);

    expect(ManualInvoiceRun::withoutGlobalScopes()->count())->toBe(0);

    // NOT VACUOUS: the same selection without the deleted student is accepted.
    mirsAs($actor, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([$here->uuid], $ctx['school']->id),
    )->assertCreated();
});

/* ── 3 · The one-active-run refusal, made legible ─────────────────────────────────────────────── */

it('3a — a second run while one is in flight is a 422 NAMING it, not a bare duplicate-entry conflict', function () {
    /*
     * The guard is the database: `active_run_key` is a STORED generated column
     * `IF(status IN ('pending','running'), school_id, NULL)` under a UNIQUE index, and the second
     * insert is 1062. `bootstrap/app.php` maps 1062 to a 409 reading "Duplicate entry detected." —
     * which names nothing, suggests nothing, and is what this arm exists to refuse.
     *
     * The run is left `running` by hand because on the `sync` queue a POST completes its run inline,
     * and a completed run releases the key — which is correct behaviour and the wrong fixture for
     * this claim.
     */
    $ctx = mirsSchool();
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $inFlight = mirsRunDirect($ctx, [mirsEnroll($ctx, mirsStudent($ctx, enrolled: false))]);

    DB::table('finance_manual_invoice_runs')->where('id', $inFlight->id)
        ->update(['status' => ManualInvoiceRunStatus::Running->value]);

    $response = mirsAs($actor, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([mirsStudent($ctx)->uuid], $ctx['school']->id),
    );

    $response->assertStatus(422)->assertJsonValidationErrors(['run']);

    // IT NAMES THE RUN. A refusal that does not is a dead end: the operator cannot go and read what
    // is already happening, which is the only thing that tells them whether to wait or to intervene.
    expect($response->json('errors.run.0'))->toContain($inFlight->uuid);

    // And nothing was written — no second run, no targets, no invoices.
    expect(ManualInvoiceRun::withoutGlobalScopes()->count())->toBe(1)
        ->and(Invoice::withoutGlobalScopes()->count())->toBe(0);

    // THE KEY RELEASES, so this is a refusal and not an outage: with the first run terminal, a
    // second start is accepted. Without this half a permanently-held key would pass the arm above
    // perfectly while breaking every School's second run forever.
    DB::table('finance_manual_invoice_runs')->where('id', $inFlight->id)
        ->update(['status' => ManualInvoiceRunStatus::Completed->value]);

    mirsAs($actor, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([mirsStudent($ctx)->uuid], $ctx['school']->id),
    )->assertCreated();
});

/* ── 4 · THE REPORT ───────────────────────────────────────────────────────────────────────────── */

it('4a — names the UNPLACEABLE by admission number, counts them, and the equality still balances', function () {
    /*
     * THE ARM THIS WHOLE SHAPE EXISTS FOR. A report that says "90 of 90" while six students never
     * became targets is the failure mode; `target_count` is read from the TARGETS table — the list
     * the bursar submitted — so a student the resolver cannot place is still in the denominator.
     *
     * AND A NUMBER ALONE IS NOT ENOUGH (brief §2): a bursar told "one could not be placed" cannot act
     * on it. The admission number is the deliverable.
     */
    $ctx = mirsSchool();
    $placed = mirsStudent($ctx);
    $orphanOne = mirsStudent($ctx, enrolled: false);
    $orphanTwo = mirsStudent($ctx, enrolled: false);
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $uuid = mirsAs($actor, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([$placed->uuid, $orphanOne->uuid, $orphanTwo->uuid], $ctx['school']->id),
    )->assertCreated()->json('uuid');

    $report = mirsAs($actor, $ctx['school'])
        ->getJson('/api/v1/finance/manual-invoice-runs/'.$uuid)->assertOk()->json();

    expect($report['target_count'])->toBe(3)
        ->and($report['counts'])->toBe(['billed' => 1, 'failed' => 0, 'unplaceable' => 2, 'claimed' => 0])
        ->and($report['reconciliation']['accounted_for'])->toBe(3)
        ->and($report['reconciliation']['balances'])->toBeTrue()
        ->and($report['reconciliation']['recorded_matches_rows'])->toBeTrue();

    // WHICH TWO. Sorted so the assertion is about the SET and not the order rows happened to be
    // written in.
    $named = mirsNamed($report, ManualInvoiceRunOutcome::Unplaceable);
    sort($named);
    $expected = [$orphanOne->admission_number, $orphanTwo->admission_number];
    sort($expected);

    expect($named)->toBe($expected)
        ->and($report['buckets']['unplaceable']['total'])->toBe(2)
        ->and($report['buckets']['unplaceable']['truncated'])->toBeFalse();

    // An unplaceable row carries no episode and no invoice — the report must not imply one exists.
    expect($report['buckets']['unplaceable']['rows'][0]['enrollment_uuid'])->toBeNull()
        ->and($report['buckets']['unplaceable']['rows'][0]['invoice_uuid'])->toBeNull();

    // And the billed one IS named, so the arm above is not passing because every bucket is empty.
    expect(mirsNamed($report, ManualInvoiceRunOutcome::Billed))->toBe([$placed->admission_number]);
});

it('4b — a run finishing with a CLAIM outstanding reports it separately and does NOT balance', function () {
    /*
     * `claimed` IS A DIAGNOSIS, NEVER A TERM. The line between it and `unplaceable` is whether
     * anything is UNKNOWN: an unplaceable student is a finished, correct, reported outcome, while a
     * claimed row is a run that does not know what happened — and it is exactly the shortfall.
     * Folding it into the left-hand side would balance the sum on precisely the runs the sum exists
     * to catch.
     *
     * The state is RECONSTRUCTED rather than simulated, the way ManualInvoiceRunTest's arm 3a does
     * it: a `claimed` row planted before the walk makes the job's own claim for that target collide
     * (1062), `attempt()` logs it, and the row stays `claimed` with no invoice behind it — which is
     * precisely what a death between the claim and the bill leaves behind.
     */
    $ctx = mirsSchool();
    $stuck = mirsEnroll($ctx, mirsStudent($ctx, enrolled: false));
    $ok = mirsEnroll($ctx, mirsStudent($ctx, enrolled: false));
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $run = mirsRunDirect($ctx, [$stuck, $ok]);

    DB::table('finance_manual_invoice_run_rows')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $ctx['school']->id,
        'run_id' => $run->id,
        'student_id' => $stuck->student_id,
        'enrollment_id' => $stuck->id,
        'enrollment_uuid' => $stuck->uuid,
        'outcome' => ManualInvoiceRunOutcome::Claimed->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ProcessManualInvoiceRun::dispatch($run->id, $ctx['school']->id);

    $report = mirsAs($actor, $ctx['school'])
        ->getJson('/api/v1/finance/manual-invoice-runs/'.$run->uuid)->assertOk()->json();

    expect($report['target_count'])->toBe(2)
        ->and($report['counts']['billed'])->toBe(1)
        ->and($report['counts']['claimed'])->toBe(1)
        // THE ALARM. Two ticked, one accounted for, and the report says so rather than reporting
        // "1 of 1" over a run that walked two.
        ->and($report['reconciliation']['accounted_for'])->toBe(1)
        ->and($report['reconciliation']['balances'])->toBeFalse();

    // The claimed student is NAMED and carries no invoice — a bursar can go and find out what
    // happened to exactly this child.
    expect(mirsNamed($report, ManualInvoiceRunOutcome::Claimed))->toHaveCount(1)
        ->and($report['buckets']['claimed']['rows'][0]['invoice_uuid'])->toBeNull();
});

it('4c — a FAILED student is named WITH the reason, and carries no invoice', function () {
    /*
     * "Ninety of ninety" is the failure this report exists to prevent; "eighty-nine billed, one
     * failed" with no name attached is the same failure wearing a smaller number. A bursar has to be
     * able to see WHICH child was not charged and WHY, without leaving the page.
     *
     * The failure is reconstructed the way ManualInvoiceRunTest's arm 3b reconstructs it — the
     * episode's uuid is rotated between the target being written and the invoice being raised, which
     * is what a withdrawal landing mid-run does — so `GenerateInvoice` refuses this ONE target with
     * its own sentence while the rest of the list bills normally.
     */
    $ctx = mirsSchool();
    $ok = mirsEnroll($ctx, mirsStudent($ctx, enrolled: false));
    $doomed = mirsEnroll($ctx, mirsStudent($ctx, enrolled: false));
    $doomedStudent = Student::withoutGlobalScopes()->findOrFail($doomed->student_id);
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $run = mirsRunDirect($ctx, [$ok, $doomed]);

    DB::table('student_curricula')->where('id', $doomed->id)->update(['uuid' => (string) Str::uuid()]);

    ProcessManualInvoiceRun::dispatch($run->id, $ctx['school']->id);

    $report = mirsAs($actor, $ctx['school'])
        ->getJson('/api/v1/finance/manual-invoice-runs/'.$run->uuid)->assertOk()->json();

    expect($report['target_count'])->toBe(2)
        ->and($report['counts'])->toBe(['billed' => 1, 'failed' => 1, 'unplaceable' => 0, 'claimed' => 0])
        // A FAILED student IS a term: the run is complete and accounted for, it just did not bill
        // everybody — which is a different statement from "something is unknown".
        ->and($report['reconciliation']['balances'])->toBeTrue()
        ->and($report['status'])->toBe(ManualInvoiceRunStatus::Completed->value);

    $failed = $report['buckets']['failed']['rows'][0];

    expect(mirsNamed($report, ManualInvoiceRunOutcome::Failed))->toBe([$doomedStudent->admission_number])
        ->and($failed['reason'])->toBe('No billable enrollment found for the given reference.')
        ->and($failed['invoice_uuid'])->toBeNull();

    // And the billed one carries the invoice it raised, so `invoice_uuid` is a real column and not
    // null for everybody.
    expect($report['buckets']['billed']['rows'][0]['invoice_uuid'])->not->toBeNull();
});

it('4c2 — a run with NO lines fails WHOLE, with the reason on the run rather than on a student', function () {
    /*
     * The sibling case, and it belongs beside 4c because the two are told apart by WHERE the reason
     * lands. A per-target refusal produces a `failed` row that names a child; a run-level refusal
     * produces no rows at all, and a report that put that reason in a bucket would be naming a child
     * for something that was never about them.
     */
    $ctx = mirsSchool();
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $run = mirsRunDirect($ctx, [mirsEnroll($ctx, mirsStudent($ctx, enrolled: false))]);
    ManualInvoiceRunLine::withoutGlobalScopes()->where('run_id', $run->id)->delete();

    ProcessManualInvoiceRun::dispatch($run->id, $ctx['school']->id);

    $report = mirsAs($actor, $ctx['school'])
        ->getJson('/api/v1/finance/manual-invoice-runs/'.$run->uuid)->assertOk()->json();

    expect($report['status'])->toBe(ManualInvoiceRunStatus::Failed->value)
        ->and($report['failure_reason'])->toContain('no lines')
        ->and($report['counts'])->toBe(['billed' => 0, 'failed' => 0, 'unplaceable' => 0, 'claimed' => 0])
        // The run is terminal, so the equality IS answerable — and it is false, because one student
        // was ticked and nobody was accounted for. A failed run must not read as balanced.
        ->and($report['target_count'])->toBe(1)
        ->and($report['reconciliation']['balances'])->toBeFalse();
});

it('4d — a run still in flight reports the bursar\'s number, NULL figures, and an unanswered equality', function () {
    /*
     * NULL IS NOT ZERO, ON THE WIRE — the §26 state-collapse defect, which has shipped five times in
     * this project. A counter cast to `(int)` on the way out turns "this run has not reconciled yet"
     * into "this run billed nobody".
     *
     * AND `balances` IS NULL RATHER THAN FALSE while the run can still write rows. Mid-run a
     * shortfall is the NORMAL state, so reporting `false` there would fire the alarm on every healthy
     * run and teach a bursar to ignore the one signal standing where a second signature would be.
     *
     * `target_count` is the exception and that is the point of reading it from the targets table: the
     * bursar's own number exists from the moment the run does.
     */
    $ctx = mirsSchool();
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $run = mirsRunDirect($ctx, [
        mirsEnroll($ctx, mirsStudent($ctx, enrolled: false)),
        mirsStudent($ctx, enrolled: false),
    ]);

    $report = mirsAs($actor, $ctx['school'])
        ->getJson('/api/v1/finance/manual-invoice-runs/'.$run->uuid)->assertOk()->json();

    expect($report['status'])->toBe(ManualInvoiceRunStatus::Pending->value)
        ->and($report['is_terminal'])->toBeFalse()
        ->and($report['has_figures'])->toBeFalse()
        ->and($report['recorded'])->toBe([
            'target' => null, 'billed' => null, 'failed' => null,
            'unplaceable' => null, 'claimed' => null,
        ])
        ->and($report['reconciliation']['balances'])->toBeNull()
        ->and($report['reconciliation']['recorded_matches_rows'])->toBeNull()
        // THE BURSAR'S NUMBER, available immediately and genuinely 2.
        ->and($report['target_count'])->toBe(2)
        ->and($report['counts'])->toBe(['billed' => 0, 'failed' => 0, 'unplaceable' => 0, 'claimed' => 0]);
});

it('4e — the report carries the lines everyone was charged, with the destination account', function () {
    $ctx = mirsSchool();
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $uuid = mirsAs($actor, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([mirsStudent($ctx)->uuid], $ctx['school']->id, 175000),
    )->assertCreated()->json('uuid');

    $report = mirsAs($actor, $ctx['school'])
        ->getJson('/api/v1/finance/manual-invoice-runs/'.$uuid)->assertOk()->json();

    expect($report['lines'])->toHaveCount(1)
        ->and($report['lines'][0]['description'])->toBe('Replacement locker key')
        // The wire shape Money owns — never a float, never a bare integer.
        ->and($report['lines'][0]['amount'])->toBe(['amount_minor' => 175000, 'currency' => 'NGN'])
        ->and($report['lines'][0]['bank_account']['uuid'])->toBe(testBankAccountUuid($ctx['school']->id));
});

/* ── 5 · The payload's own rules ──────────────────────────────────────────────────────────────── */

it('5a — a charge line with NO destination account is refused (S11), and no run is created', function () {
    /*
     * S11 (`d3227c0`) made a destination account required on every charge line, and
     * `finance_invoice_lines_destination_guard` is the authority behind it. Here it is a `required`
     * rule rather than a separate assertion pass because a manual run has no reduction line to make
     * room for — there is no shape of this payload for which the field is optional.
     */
    $ctx = mirsSchool();
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $payload = mirsPayload([mirsStudent($ctx)->uuid], $ctx['school']->id);
    unset($payload['lines'][0]['bank_account_id']);

    mirsAs($actor, $ctx['school'])->postJson('/api/v1/finance/manual-invoice-runs', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.bank_account_id']);

    // ANOTHER SCHOOL'S ACCOUNT is refused too — the pre-check for a guard that is the composite FK
    // (bank_account_id, school_id) -> finance_bank_accounts (id, school_id).
    $payload = mirsPayload([mirsStudent($ctx)->uuid], $ctx['school']->id);
    $payload['lines'][0]['bank_account_id'] = testBankAccountUuid(mirsSchool()['school']->id);

    mirsAs($actor, $ctx['school'])->postJson('/api/v1/finance/manual-invoice-runs', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.bank_account_id']);

    expect(ManualInvoiceRun::withoutGlobalScopes()->count())->toBe(0);
});

it('5b — an empty selection, an empty line list and a repeated student are all refused', function () {
    $ctx = mirsSchool();
    $student = mirsStudent($ctx);
    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $payload = mirsPayload([], $ctx['school']->id);
    mirsAs($actor, $ctx['school'])->postJson('/api/v1/finance/manual-invoice-runs', $payload)
        ->assertStatus(422)->assertJsonValidationErrors(['student_ids']);

    $payload = mirsPayload([$student->uuid], $ctx['school']->id);
    $payload['lines'] = [];
    mirsAs($actor, $ctx['school'])->postJson('/api/v1/finance/manual-invoice-runs', $payload)
        ->assertStatus(422)->assertJsonValidationErrors(['lines']);

    /*
     * A REPEATED ID IS REFUSED RATHER THAN DEDUPED. The targets table's
     * UNIQUE(school_id, run_id, student_id) would answer 1062 to the second insert, which reaches a
     * bursar as "Duplicate entry detected." — and silently collapsing it instead would make
     * `target_count` disagree with the number they ticked, which is the one figure on the report
     * they can check against their own list.
     */
    mirsAs($actor, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([$student->uuid, $student->uuid], $ctx['school']->id),
    )->assertStatus(422)->assertJsonValidationErrors(['student_ids.1']);

    // A NEGATIVE line amount is a reduction with no policy to authorise it — a credit note's job.
    $payload = mirsPayload([$student->uuid], $ctx['school']->id);
    $payload['lines'][0]['amount_minor'] = -1000;
    mirsAs($actor, $ctx['school'])->postJson('/api/v1/finance/manual-invoice-runs', $payload)
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.amount_minor']);

    expect(ManualInvoiceRun::withoutGlobalScopes()->count())->toBe(0);
});

it('5c — both routes are gated on finance.invoice.generate and nothing new is coined', function () {
    $ctx = mirsSchool();
    $student = mirsStudent($ctx);
    $allowed = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);
    $refused = mirsUser($ctx['school'], [MIRS_ACCESS]);

    $uuid = mirsAs($allowed, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([$student->uuid], $ctx['school']->id),
    )->assertCreated()->json('uuid');

    mirsAs($refused, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([mirsStudent($ctx)->uuid], $ctx['school']->id),
    )->assertForbidden();

    mirsAs($refused, $ctx['school'])->getJson('/api/v1/finance/manual-invoice-runs/'.$uuid)
        ->assertForbidden();
});

/* ── 6 · The ACL port's determinacy claim, MEASURED ───────────────────────────────────────────── */

it('6a — two ACTIVE episodes for one student are ADMITTED by the schema, and resolve to exactly one', function () {
    /*
     * THE CLAIM UNDER TEST is `BillableEnrollmentAdapter::currentForStudent()`'s: "student_id pinned,
     * the result set has at most one member and first() is determinate."
     *
     * IT IS ENFORCED, BUT NOT BY THE SCHEMA — and the difference matters, because the schema is where
     * a reader would look for it. `student_curricula` carries
     * UNIQUE(student_id, curriculum_id) and nothing else on this axis, so TWO ACTIVE episodes in two
     * curricula are perfectly representable; the first half of this arm proves that by writing them.
     *
     * What enforces the claim is `billableEpisodes()`: `whereIn(id, SELECT MAX(id) … GROUP BY
     * student_id)` admits at most one row per student by construction, so with `student_id` pinned
     * the outer query has at most one member whatever the table holds. The tie-break is the MAX, and
     * this arm pins WHICH episode wins — the later one — so a change from MAX to MIN, or a drop of
     * the subquery, is visible here rather than only at a call site that happens to have one episode.
     *
     * A FIXTURE WITH ONE EPISODE CANNOT SEE ANY OF THIS. That is the degenerate case: the claim would
     * read as covered by every existing arm while the axis it names was never crossed.
     */
    $ctx = mirsSchool();
    $student = mirsStudent($ctx, enrolled: false);

    $first = mirsEnroll($ctx, $student);
    $second = mirsEnroll($ctx, $student);

    // MEASURED: the schema ADMITS both. Nothing refused the second ACTIVE episode.
    expect(StudentCurriculum::withoutGlobalScopes()
        ->where('student_id', $student->id)
        ->where('status', StudentStatusEnum::ACTIVE)
        ->count())->toBe(2)
        ->and($second->id)->toBeGreaterThan($first->id);

    $actor = mirsUser($ctx['school'], [MIRS_ACCESS, MIRS_GENERATE]);

    $uuid = mirsAs($actor, $ctx['school'])->postJson(
        '/api/v1/finance/manual-invoice-runs',
        mirsPayload([$student->uuid], $ctx['school']->id),
    )->assertCreated()->json('uuid');

    $run = ManualInvoiceRun::withoutGlobalScopes()->where('uuid', $uuid)->sole();

    // ONE target, ONE row, ONE invoice — the student is billed once, not once per episode.
    $target = ManualInvoiceRunTarget::withoutGlobalScopes()->where('run_id', $run->id)->sole();
    $row = ManualInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->sole();

    // AND FOR THE LATER EPISODE. Derived from the fixture's own ids rather than from the resolver's
    // ordering — an expectation computed the way the code computes it would assert only that the
    // implementation equals itself.
    expect((int) $target->enrollment_id)->toBe($second->id)
        ->and($row->outcome)->toBe(ManualInvoiceRunOutcome::Billed)
        ->and(Invoice::withoutGlobalScopes()->where('student_curriculum_id', $second->id)->count())->toBe(1)
        ->and(Invoice::withoutGlobalScopes()->where('student_curriculum_id', $first->id)->count())->toBe(0);
});

/*
 * ── 7 · THE SELECTION IS RESOLVED IN ONE READ AND WRITTEN IN ONE INSERT ───────────────────────
 *
 * Two regressions live here, both of them the same edit in spirit: somebody restoring a per-student
 * call inside the loop. `currentForStudent()` for the read, `ManualInvoiceRunTarget::create()` for
 * the write. Either reads as obviously correct — both are what this Action did until recently, and
 * its own docblock once defended the first — so nothing in the diff would look wrong and the only
 * symptom would be a bursar waiting.
 *
 * WHY NOT A LITERAL QUERY COUNT, which is the obvious shape. A total drifts every time an eager load
 * is added to the adapter's snapshot relations, and the fix for a drifted total is to raise the
 * number — which is indistinguishable from raising it to accommodate a re-introduced N+1. The
 * property that does not drift is the SHAPE: **neither the reads nor the writes grow with the size
 * of the selection**. Both halves are now flat, which they were not before the batched insert: the
 * write side used to be one statement per target and the arm pinned that growth deliberately.
 *
 * THE NON-VACUITY GUARD HAD TO MOVE WITH IT. While writes grew one per target, "writes grew by 27"
 * was the proof that the two measured windows really differed by 27 students. Now that nothing in
 * the query counts moves with N, that proof has to come from the ROWS: the two runs are asserted to
 * have produced 3 and 30 targets. Without it, two runs that both did nothing would pass.
 *
 * NO READ CLASS IS EXCLUDED ANY MORE, and the exclusion that used to be here is worth recording
 * rather than deleting. `BelongsToSchool` calls `Schema::hasColumn()` in its `creating` hook —
 * bootBelongsToSchool (app/Concerns/BelongsToSchool.php:21) — an uncached `information_schema` query
 * on every model insert in this codebase, so while the targets went through `create()` those reads
 * grew one per target and had to be filtered out by FROM-clause for this arm to be about the Action
 * rather than about the trait. The batched insert fires no model events, so they are gone from this
 * path: MEASURED at 611 targets, 613 of them before and 2 after — the two that remain belong to the
 * run row and the line row, which are still written through `create()`. Nothing is filtered now, and
 * the arm is simpler for it.
 *
 * 7b IS THE OTHER HALF AND IT IS NOT OPTIONAL. A batched insert can be perfectly flat and quietly
 * wrong, because it skips the model events — see the Action's docblock for which two traits do their
 * work there. 7a would stay green on rows with no uuid and no timestamps.
 */

/**
 * $count students in $ctx's School, every third of them UN-ENROLLED, as `students.id`.
 *
 * The un-enrolled are load-bearing: they are the ids the batch map has no key for, so the Action's
 * `?? null` runs in every window rather than only in the large one.
 *
 * REVERSED, so the payload order is NOT the students' own id order. Both arms below are about order
 * surviving — 7a incidentally, 7b as its subject — and with the payload in creation order a target
 * table that ignored the payload entirely and sorted by `student_id` would satisfy them both.
 *
 * @return list<int>
 */
function mirsSelection(array $ctx, int $count): array
{
    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $ids[] = (int) mirsStudent($ctx, enrolled: $i % 3 !== 2)->id;
    }

    return array_reverse($ids);
}

/**
 * One call to the Action, with its reads and its writes counted separately, and the run it produced.
 *
 * The line spec and the destination account are resolved BEFORE the log is enabled, and the run key
 * is released AFTER it is taken, so neither lands in a measurement. Releasing it is not optional:
 * `finance_manual_invoice_runs` admits one non-terminal run per School at the engine, so a second
 * measurement against the same School is refused 1062 without it.
 *
 * @param  list<int>  $studentIds
 * @return array{0: int, 1: int, 2: ManualInvoiceRun} [reads, writes, run]
 */
function mirsQueryShape(array $ctx, array $studentIds): array
{
    $lines = [[
        'description' => 'Replacement locker key',
        'amount' => Money::fromKobo(250000, 'NGN'),
        'bank_account_id' => testBankAccountId($ctx['school']->id),
        'sort_order' => 0,
    ]];

    $action = app(StartManualInvoiceRun::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $run = ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => $action->handle($ctx['school']->id, $studentIds, $lines, null),
    );

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => $run->forceFill(['status' => ManualInvoiceRunStatus::Completed])->save(),
    );

    $statements = array_map(fn (array $entry) => strtolower(ltrim((string) $entry['query'])), $log);
    $reads = array_filter($statements, fn (string $sql) => str_starts_with($sql, 'select'));

    return [count($reads), count($statements) - count($reads), $run];
}

/** The run's targets, in id order — which is the order the job walks them and the report prints them. */
function mirsTargetsInIdOrder(ManualInvoiceRun $run): Collection
{
    return ManualInvoiceRunTarget::withoutGlobalScopes()
        ->where('run_id', $run->id)
        ->orderBy('id')
        ->get();
}

it('7a — neither the Action\'s reads NOR its writes grow with the size of the selection', function () {
    $ctx = mirsSchool();

    /*
     * A WARM-UP RUN, DISCARDED. Laravel caches a table's column metadata per connection and the
     * Money cast pays that introspection on FIRST use, so the first Action call in a process issues
     * reads the second does not. Counted into one window and not the other, that alone makes a flat
     * read count look like a growing one — noise on the exact axis under test.
     */
    mirsQueryShape($ctx, mirsSelection($ctx, 2));

    [$smallReads, $smallWrites, $smallRun] = mirsQueryShape($ctx, mirsSelection($ctx, 3));
    [$largeReads, $largeWrites, $largeRun] = mirsQueryShape($ctx, mirsSelection($ctx, 30));

    /*
     * THE ROWS ARE THE NON-VACUITY GUARD, and they are asserted first for that reason. Nothing in
     * the query counts moves with N any more, so "the counts are identical" is also satisfied by two
     * runs that both did nothing. These two numbers are what prove the windows differed by 27
     * students while the statement count did not.
     */
    expect(mirsTargetsInIdOrder($smallRun))->toHaveCount(3)
        ->and(mirsTargetsInIdOrder($largeRun))->toHaveCount(30);

    /*
     * READS FLAT. A `currentForStudent()` restored inside the loop makes this 27 × the adapter's
     * per-student cost instead of zero.
     */
    expect($largeReads - $smallReads)->toBe(0);

    /*
     * WRITES FLAT. A `ManualInvoiceRunTarget::create()` restored inside the loop makes this 27
     * instead of zero. Ten times that selection would make it 270; the chunk size is far above any
     * of these, so the honest property is "does not grow", not "is one".
     */
    expect($largeWrites - $smallWrites)->toBe(0);
});

it('7b — the batched write produces exactly the rows the per-row write did: order, ids, NULLs, uuids and stamps', function () {
    /*
     * THE ARM THE BATCH EARNED. `ManualInvoiceRunTarget::query()->insert()` fires no model events, so
     * `AddUuid` does not mint a uuid and `BelongsToSchool` does not fill `school_id`; `insert()` also
     * does not stamp `created_at` / `updated_at`. Every one of those is a column that goes quietly
     * empty rather than loudly wrong, on a table whose count IS what the bursar ticked.
     *
     * EVERY EXPECTATION BELOW IS DERIVED BY THE OTHER CODE PATH OR FROM THE PAYLOAD — never from the
     * batch's own rule. The enrollment each row should name comes from calling
     * `currentForStudent()` per student, which is the single-student resolver the batch replaced.
     */
    $ctx = mirsSchool();
    $studentIds = mirsSelection($ctx, 12);   // 8 enrolled, 4 un-enrolled, in reverse creation order

    [, , $run] = mirsQueryShape($ctx, $studentIds);

    $targets = mirsTargetsInIdOrder($run);

    // ── the count is the selection, and the ORDER is the payload's, not the students' own id order
    expect($targets)->toHaveCount(count($studentIds))
        ->and($targets->pluck('student_id')->map(fn ($id) => (int) $id)->all())->toBe($studentIds)
        ->and($studentIds)->not->toBe(collect($studentIds)->sort()->values()->all());

    // ── the episode each row names, decided by the OTHER resolver, one student at a time
    $port = app(BillableEnrollmentProvider::class);
    $placed = 0;

    foreach ($targets as $target) {
        $expected = ActiveSchool::runFor(
            $ctx['school']->id,
            fn () => $port->currentForStudent((int) $target->student_id),
        );

        if ($expected === null) {
            expect($target->enrollment_id)->toBeNull()
                ->and($target->enrollment_uuid)->toBeNull();

            continue;
        }

        $placed++;

        expect((int) $target->enrollment_id)->toBe($expected->enrollmentId)
            ->and($target->enrollment_uuid)->toBe($expected->enrollmentUuid);
    }

    // NOT VACUOUS: the fixture really did contain both halves, so neither branch above was skipped.
    expect($placed)->toBe(8)
        ->and($targets->whereNull('enrollment_id'))->toHaveCount(4);

    // ── every column the skipped model events would have filled
    foreach ($targets as $target) {
        expect($target->uuid)->toBeString()
            ->and(Str::isUuid((string) $target->uuid))->toBeTrue()
            ->and((int) $target->school_id)->toBe($ctx['school']->id)
            ->and($target->created_at)->not->toBeNull()
            ->and($target->updated_at)->not->toBeNull();
    }

    // A SHARED uuid would satisfy every per-row check above and break the route key for eleven rows.
    expect($targets->pluck('uuid')->unique())->toHaveCount(count($studentIds));
});
