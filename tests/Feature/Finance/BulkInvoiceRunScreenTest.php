<?php

/*
 * U6 commit 4 — THE OPERATOR SURFACE for a bulk invoice run, over HTTP.
 *
 * WHAT THIS FILE PROVES, AND WHAT IT LEAVES TO COMMIT 3's OWN 55KB. BulkInvoiceRunTest drives the
 * JOB and owns every rule about what a run DOES — the double-billing refusal, one student not taking
 * the run down, the five schedule-level refusals, the reconciliation over a fixture where all five
 * buckets are non-empty, the two trigger-enforced domains. None of that is re-proved here and none
 * of it should be: a second copy of that coverage is a second thing to keep in step.
 *
 * What is proved here is the SURFACE. Four claims, in the order they matter:
 *
 *   1. THE PREVIEW COMMITS NOTHING. It is the only control standing between a bursar and an act that
 *      is undone one two-signature void request per child, so "it creates no row and dispatches no
 *      job" has to be a measured fact rather than a property of the code as read.
 *
 *   2. THE START CREATES ONE RUN AND DISPATCHES ONCE — and a SECOND start at the same coordinates is
 *      PERMITTED. Re-running is the documented recovery path and the engine is what prevents a
 *      double bill, so the arm asserts what actually happens on the re-run (a second run, zero new
 *      invoices, everyone recorded `already_billed`) rather than asserting a guard that must not
 *      exist.
 *
 *   3. NULL IS NOT ZERO, ON THE WIRE. The §26 state-collapse defect has shipped five times in this
 *      project, and the payload is where it would be committed here: a count cast to `(int)` on the
 *      way out destroys the distinction the screen would need to render it. Both directions are
 *      pinned — a run with no figures exposes eight nulls, and a run WITH figures exposes a genuine
 *      zero as `0`.
 *
 *   4. AUTHORIZATION AND ISOLATION. One ability on all four routes, no new permission; and School
 *      A's list, detail and start cannot reach School B.
 *
 * THE QUEUE IS `sync` IN TESTS (phpunit.xml), so a dispatch runs inline and most arms below see the
 * finished run. That is deliberate rather than convenient — it exercises the job's own SchoolAware
 * middleware and its School context, which a Queue::fake() would skip while still reporting green.
 * The two arms that DO fake the queue say why at the point they do it.
 *
 * Every guard here was planted and watched red before it was believed; the plants are named in
 * docs/handoff/reports/feat-u6-bulk-run-screen.md.
 */

use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Enums\BulkInvoiceRunOutcome;
use App\Finance\Enums\BulkInvoiceRunStatus;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRunRow;
use App\Finance\Models\FeeSchedule;
use App\Finance\Models\Invoice;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

const BIRS_ACCESS = 'finance.access';

const BIRS_GENERATE = 'finance.invoice.generate';

/**
 * A web-session user holding EXACTLY $permissions — the shape OpeningBalanceOperatorScreenTest and
 * PaymentRecordGateTest both use, for their reason: role membership is what a grants commit changes,
 * so a role-keyed actor would move with the thing under test rather than with this test's subject.
 *
 * @param  list<string>  $permissions
 */
function birsUser(School $school, array $permissions): User
{
    $roleName = 'birs_'.substr(md5(implode(',', $permissions)), 0, 10);
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

/**
 * A School with the pricing coordinates a run names, plus a SECOND class level so "placeable at
 * coordinates nobody asked about" is constructible — the same fixture shape BulkInvoiceRunTest uses.
 *
 * @return array{school: School, term: Term, level: ClassLevel, arm: ClassLevelArm, level2: ClassLevel, arm2: ClassLevelArm}
 */
function birsSchool(): array
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

        $make = function (string $name, int $order) use ($school) {
            $level = ClassLevel::create(['school_id' => $school->id, 'name' => $name, 'order' => $order]);
            $arm = ClassLevelArm::create([
                'school_id' => $school->id,
                'class_level_id' => $level->id,
                'arm_id' => Arm::create(['school_id' => $school->id, 'label' => strtoupper(Str::random(3))])->id,
            ]);

            return [$level, $arm];
        };

        [$level, $arm] = $make('JSS 1', 1);
        [$level2, $arm2] = $make('JSS 2', 2);

        return compact('school', 'term', 'level', 'arm', 'level2', 'arm2');
    });
}

/**
 * An ACTIVE fee schedule at $ctx's coordinates. CreateFeeSchedule always authors a DRAFT (the
 * parent-state triggers only admit item inserts into one), so the activation is a raw status write —
 * the way the rest of the suite moves a lifecycle it is not the subject of.
 *
 * @param  list<array<string, mixed>>  $items
 */
function birsSchedule(array $ctx, ?array $items = null, ?ClassLevel $level = null): int
{
    $items ??= [[
        'description' => 'Tuition', 'amount_minor' => 1000000, 'currency' => 'NGN',
        'is_mandatory' => true, 'is_discountable' => true, 'sort_order' => 0,
    ]];

    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $items, $level) {
        $specs = array_map(fn (array $item) => $item + ['bank_account_id' => testBankAccountUuid($ctx['school']->id)], $items);

        $schedule = app(CreateFeeSchedule::class)->handle(
            $ctx['term']->id, ($level ?? $ctx['level'])->id, 'v1-'.Str::random(4), $specs
        );

        DB::table('finance_fee_schedules')->where('id', $schedule->id)
            ->update(['status' => FeeScheduleStatus::Active->value]);

        return $schedule->id;
    });
}

/** A student in $ctx's School with one ACTIVE enrollment at the given coordinates. */
function birsStudent(array $ctx, ?int $armId, ?int $termId): Student
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $armId, $termId) {
        $student = Student::factory()->create([
            'school_id' => $ctx['school']->id,
            'admission_number' => 'ADM-'.Str::random(8),
        ]);

        StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $ctx['school']->id,
            'curriculum_id' => Curriculum::factory()->create([
                'school_id' => $ctx['school']->id,
                'class_level_arm_id' => $armId,
                'term_id' => $termId,
            ])->id,
            'status' => StudentStatusEnum::ACTIVE,
        ]);

        return $student;
    });
}

/** The acting seat, always with an explicit School session — no route relies on ambient leakage. */
function birsAs(User $actor, School $school)
{
    return test()->actingAs($actor)->withSession(['school_id' => $school->id]);
}

/* ── 1 · The preview commits nothing ───────────────────────────────────────────────────────── */

it('previews a run without creating a row or dispatching a job', function () {
    $ctx = birsSchool();
    birsSchedule($ctx);
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    // FAKED HERE, DELIBERATELY, AND ONLY HERE. The claim is "nothing was dispatched", and on the
    // sync queue an absent dispatch and a dispatch that ran to completion are told apart only by
    // their side effects — which is exactly the inference this arm exists to remove.
    Bus::fake();

    $response = birsAs($actor, $ctx['school'])
        ->getJson('/api/v1/finance/bulk-invoice-runs/preview?term_id='.$ctx['term']->id.'&class_level_id='.$ctx['level']->id);

    $response->assertOk()
        ->assertJsonPath('cohort_size', 2)
        ->assertJsonPath('already_billed', 0)
        ->assertJsonPath('refusal', null)
        ->assertJsonPath('schedule.status', FeeScheduleStatus::Active->value)
        ->assertJsonPath('schedule.mandatory_item_count', 1);

    // The two facts that make it a preview rather than a start.
    expect(BulkInvoiceRun::withoutGlobalScopes()->count())->toBe(0);
    Bus::assertNotDispatched(ProcessBulkInvoiceRun::class);

    // And nothing was billed, which is the same claim read from the other side.
    expect(Invoice::withoutGlobalScopes()->count())->toBe(0);
});

it('counts the cohort members that already carry a term bill, and does not offer to bill them again', function () {
    $ctx = birsSchool();
    birsSchedule($ctx);
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    // Bill the cohort once, through the real path.
    birsAs($actor, $ctx['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
    ])->assertCreated();

    $response = birsAs($actor, $ctx['school'])
        ->getJson('/api/v1/finance/bulk-invoice-runs/preview?term_id='.$ctx['term']->id.'&class_level_id='.$ctx['level']->id);

    // The preview is what tells an operator that a second run over this cohort would bill nobody
    // new. It is a fact about the episodes, not about the earlier run.
    $response->assertOk()
        ->assertJsonPath('cohort_size', 2)
        ->assertJsonPath('already_billed', 2);
});

it('reports the schedule-level refusal in the JOB’s own words, before anything is created', function () {
    $ctx = birsSchool();

    // A schedule of purely OPTIONAL items — one of the mapper's five refusals, and a real authorable
    // thing rather than a contrived one.
    birsSchedule($ctx, [[
        'description' => 'Bus', 'amount_minor' => 500000, 'currency' => 'NGN',
        'is_mandatory' => false, 'is_discountable' => true, 'sort_order' => 0,
    ]]);
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    $response = birsAs($actor, $ctx['school'])
        ->getJson('/api/v1/finance/bulk-invoice-runs/preview?term_id='.$ctx['term']->id.'&class_level_id='.$ctx['level']->id);

    $response->assertOk()
        // VERBATIM from FeeScheduleLineMapper. A second wording on this side is a second thing that
        // can disagree with the job about why a run cannot happen.
        ->assertJsonPath('refusal', 'Fee schedule ['.FeeSchedule::withoutGlobalScopes()->value('uuid').'] has no mandatory items, so it cannot produce a term bill.')
        // The cohort is still reported: who is in it is a fact about the roster and does not depend
        // on whether a price list exists.
        ->assertJsonPath('cohort_size', 1)
        // No line count for a schedule that cannot produce lines. A 0 here would read as "a schedule
        // with no items" on the four refusals that have nothing to do with items.
        ->assertJsonPath('schedule.mandatory_item_count', null);

    expect(BulkInvoiceRun::withoutGlobalScopes()->count())->toBe(0);
});

it('reports the missing-schedule refusal in the job’s own words', function () {
    $ctx = birsSchool();
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    birsAs($actor, $ctx['school'])
        ->getJson('/api/v1/finance/bulk-invoice-runs/preview?term_id='.$ctx['term']->id.'&class_level_id='.$ctx['level']->id)
        ->assertOk()
        ->assertJsonPath('schedule', null)
        ->assertJsonPath('refusal', 'No active fee schedule exists at these coordinates, so there is no price list to bill from.');
});

/* ── 2 · The start ─────────────────────────────────────────────────────────────────────────── */

it('creates exactly one run and dispatches exactly one job', function () {
    $ctx = birsSchool();
    birsSchedule($ctx);
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    // FAKED, for the same reason as arm 1 and no other: "dispatched ONCE" is a claim about the
    // dispatch itself, which the sync queue makes unobservable by running it.
    Bus::fake();

    birsAs($actor, $ctx['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
    ])->assertCreated()
        ->assertJsonPath('status', BulkInvoiceRunStatus::Pending->value)
        // NO FIGURES ON A RUN THAT HAS NOT REPORTED. Under a real worker this is the state the
        // screen first renders, and it is the state the state-collapse defect would corrupt.
        ->assertJsonPath('has_figures', false)
        ->assertJsonPath('counts.cohort', null)
        ->assertJsonPath('counts.billed', null);

    $runs = BulkInvoiceRun::withoutGlobalScopes()->get();

    expect($runs)->toHaveCount(1)
        ->and((int) $runs[0]->school_id)->toBe($ctx['school']->id)
        ->and((int) $runs[0]->term_id)->toBe($ctx['term']->id)
        ->and((int) $runs[0]->class_level_id)->toBe($ctx['level']->id)
        // ATTRIBUTION, never an execution identity (Constitution 13).
        ->and((int) $runs[0]->started_by_user_id)->toBe($actor->id);

    Bus::assertDispatchedTimes(ProcessBulkInvoiceRun::class, 1);
});

it('permits a SECOND run at the same coordinates, and the re-run bills nobody twice', function () {
    /*
     * THE RE-RUN IS THE RECOVERY PATH, so this arm asserts what happens rather than asserting a
     * uniqueness guard that must NOT exist. `UNIQUE(school_id, active_enrollment_key)` on
     * finance_invoices is the authority; a second guard on this route would block the recovery path
     * to prevent something the engine already prevents.
     */
    $ctx = birsSchool();
    birsSchedule($ctx);
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    $payload = ['term_id' => $ctx['term']->id, 'class_level_id' => $ctx['level']->id];

    $first = birsAs($actor, $ctx['school'])->postJson('/api/v1/finance/bulk-invoice-runs', $payload);
    $first->assertCreated()->assertJsonPath('counts.billed', 2);

    $second = birsAs($actor, $ctx['school'])->postJson('/api/v1/finance/bulk-invoice-runs', $payload);

    // PERMITTED — 201, not 409 and not 422.
    $second->assertCreated()
        ->assertJsonPath('status', BulkInvoiceRunStatus::Completed->value)
        ->assertJsonPath('counts.cohort', 2)
        // A GENUINE ZERO STILL RENDERS AS 0. This is the other half of the null-is-not-zero rule:
        // a payload that dashed everything would pass every test written for the missing case.
        ->assertJsonPath('counts.billed', 0)
        ->assertJsonPath('counts.already_billed', 2)
        ->assertJsonPath('counts.failed', 0);

    expect(BulkInvoiceRun::withoutGlobalScopes()->count())->toBe(2)
        // Two runs, two students, still two invoices.
        ->and(Invoice::withoutGlobalScopes()->count())->toBe(2);
});

it('refuses coordinates belonging to another School', function () {
    // The run table's term_id and class_level_id are SINGLE-column foreign keys, so another School's
    // term is a valid reference at the engine. The request's school-scoped exists rule is the only
    // thing between School A and a run keyed to School B's term.
    $ctx = birsSchool();
    $other = birsSchool();
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    birsAs($actor, $ctx['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $other['term']->id,
        'class_level_id' => $ctx['level']->id,
    ])->assertStatus(422)->assertJsonValidationErrors('term_id');

    birsAs($actor, $ctx['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $ctx['term']->id,
        'class_level_id' => $other['level']->id,
    ])->assertStatus(422)->assertJsonValidationErrors('class_level_id');

    expect(BulkInvoiceRun::withoutGlobalScopes()->count())->toBe(0);
});

/* ── 3 · Null is not zero, in both directions ──────────────────────────────────────────────── */

it('exposes NO figures for a run that FAILED before it could count anything', function () {
    /*
     * The by-construction case: no active fee schedule at the coordinates, so the job fails the run
     * through writeFailure(), which names status, finished_at and failure_reason and NO count. Every
     * figure is genuinely absent, and the payload must say absent rather than zero.
     */
    $ctx = birsSchool();
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    $created = birsAs($actor, $ctx['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
    ])->assertCreated();

    $uuid = $created->json('uuid');

    $response = birsAs($actor, $ctx['school'])->getJson('/api/v1/finance/bulk-invoice-runs/'.$uuid);

    $response->assertOk()
        ->assertJsonPath('status', BulkInvoiceRunStatus::Failed->value)
        ->assertJsonPath('has_figures', false)
        ->assertJsonPath('failure_reason', 'No active fee schedule exists at these coordinates, so there is no price list to bill from.')
        // No reconciliation verdict either: there is nothing to reconcile.
        ->assertJsonPath('reconciliation', null);

    // EVERY ONE OF THE EIGHT, enumerated. A payload that nulled `cohort` and zeroed the rest would
    // satisfy a spot check and still render "0 billed" over a run that billed nothing knowingly.
    foreach (['cohort', 'billed', 'already_billed', 'failed', 'unplaceable_listed', 'unplaceable', 'billable', 'outside_coordinates'] as $key) {
        $response->assertJsonPath('counts.'.$key, null);
    }
});

it('exposes NO figures for a run that is still RUNNING', function () {
    /*
     * A run mid-cohort. Constructed by writing the state the job itself writes at the top of
     * process() — `running`, `started_at` set, every count still NULL — because the alternative is
     * pausing a synchronous job, which the queue driver does not permit.
     *
     * Rendering this as eight zeroes is the worst instance of the defect available on this feature:
     * "0 billed, 0 failed" beside a spinner is a confident false statement about a school's money.
     */
    $ctx = birsSchool();
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    $run = ActiveSchool::runFor($ctx['school']->id, fn () => BulkInvoiceRun::create([
        'school_id' => $ctx['school']->id,
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
        'status' => BulkInvoiceRunStatus::Running,
        'started_at' => now(),
    ]));

    $response = birsAs($actor, $ctx['school'])->getJson('/api/v1/finance/bulk-invoice-runs/'.$run->uuid);

    $response->assertOk()
        ->assertJsonPath('status', BulkInvoiceRunStatus::Running->value)
        ->assertJsonPath('has_figures', false)
        ->assertJsonPath('failure_reason', null)
        ->assertJsonPath('reconciliation', null);

    foreach (['cohort', 'billed', 'already_billed', 'failed', 'unplaceable_listed', 'unplaceable', 'billable', 'outside_coordinates'] as $key) {
        $response->assertJsonPath('counts.'.$key, null);
    }

    // And the same run on the LIST, because the list renders the same two columns and the defect
    // would be committed once per surface.
    birsAs($actor, $ctx['school'])->getJson('/api/v1/finance/bulk-invoice-runs')
        ->assertOk()
        ->assertJsonPath('data.0.has_figures', false)
        ->assertJsonPath('data.0.counts.billed', null);
});

it('DOES expose figures for the one FAILED run that has them — the nobody-billed rule', function () {
    /*
     * THE CORRECTION THIS FILE MOST HAS TO CARRY. "A failed run has no figures" is true of four of
     * the five routes into `failed` and FALSE of the fifth: reconcile() writes all eight counts and
     * THEN sets `failed` when a non-empty cohort billed nobody. BulkInvoiceRunStatus says so in its
     * own words — "a `failed` run must be READ, not assumed: check `cohort_count` and the row
     * counts" — and those counts are the entire diagnosis of that failure.
     *
     * So `has_figures` is keyed on `cohort_count`, not on the status. A screen keyed on the status
     * would blank the figures in the one failure case where they are what the operator needs.
     *
     * The fixture: an active schedule, a cohort of one, and GenerateInvoice made to throw for every
     * member by giving the episode a term the invoice path cannot honour. Rather than contrive that,
     * the state is written directly — this arm is about the PAYLOAD's rule, and commit 3's own suite
     * owns the rule that produces it.
     */
    $ctx = birsSchool();
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    $run = ActiveSchool::runFor($ctx['school']->id, fn () => BulkInvoiceRun::create([
        'school_id' => $ctx['school']->id,
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
        'status' => BulkInvoiceRunStatus::Failed,
        'started_at' => now(),
        'finished_at' => now(),
        'failure_reason' => 'Every one of the 3 students in this cohort failed and none was billed.',
        'cohort_count' => 3,
        'billed_count' => 0,
        'already_billed_count' => 0,
        'failed_count' => 3,
        'unplaceable_listed_count' => 0,
        'unplaceable_count' => 0,
        'billable_count' => 3,
        'outside_coordinates_count' => 0,
    ]));

    birsAs($actor, $ctx['school'])->getJson('/api/v1/finance/bulk-invoice-runs/'.$run->uuid)
        ->assertOk()
        ->assertJsonPath('status', BulkInvoiceRunStatus::Failed->value)
        // The whole point: FAILED, and it HAS figures.
        ->assertJsonPath('has_figures', true)
        ->assertJsonPath('counts.cohort', 3)
        ->assertJsonPath('counts.failed', 3)
        ->assertJsonPath('counts.billed', 0)
        // And the two equalities still balance — this run's record is complete, it just billed
        // nobody. A red reconciliation would be a different fact and must not be conflated.
        ->assertJsonPath('reconciliation.cohort_balances', true)
        ->assertJsonPath('reconciliation.unplaceable_balances', true);
});

it('reports the run’s own alarm when the persisted rows do not match the list it walked', function () {
    // The imbalance is the ONLY signal that a student the run saw has no row saying what became of
    // them — there is deliberately no flag column. A screen rendering eight numbers without stating
    // whether they add up renders the alarm as decoration, so the verdict is on the wire.
    $ctx = birsSchool();
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    $run = ActiveSchool::runFor($ctx['school']->id, fn () => BulkInvoiceRun::create([
        'school_id' => $ctx['school']->id,
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
        'status' => BulkInvoiceRunStatus::Completed,
        'started_at' => now(), 'finished_at' => now(),
        // One member of the cohort produced no row at all — the shape attempt() rules is a
        // per-student fault the run survives.
        'cohort_count' => 4, 'billed_count' => 3, 'already_billed_count' => 0, 'failed_count' => 0,
        'unplaceable_listed_count' => 2, 'unplaceable_count' => 1,
        'billable_count' => 6, 'outside_coordinates_count' => 0,
    ]));

    birsAs($actor, $ctx['school'])->getJson('/api/v1/finance/bulk-invoice-runs/'.$run->uuid)
        ->assertOk()
        ->assertJsonPath('reconciliation.cohort_balances', false)
        ->assertJsonPath('reconciliation.unplaceable_balances', false);
});

/* ── 4 · The detail's four buckets ─────────────────────────────────────────────────────────── */

it('buckets the run’s rows by outcome, carries each row’s reason, and names the student for the U7 link', function () {
    $ctx = birsSchool();
    birsSchedule($ctx);
    $billed = birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    // Unplaceable: an active enrollment with no term, so no schedule can ever be keyed to it.
    $unplaceable = birsStudent($ctx, $ctx['arm']->id, null);
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    $uuid = birsAs($actor, $ctx['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
    ])->assertCreated()->json('uuid');

    $response = birsAs($actor, $ctx['school'])->getJson('/api/v1/finance/bulk-invoice-runs/'.$uuid);

    $response->assertOk()
        ->assertJsonPath('buckets.billed.total', 1)
        ->assertJsonPath('buckets.billed.truncated', false)
        ->assertJsonPath('buckets.unplaceable.total', 1)
        ->assertJsonPath('buckets.already_billed.total', 0)
        ->assertJsonPath('buckets.failed.total', 0)
        // THE U7 SLICE. The student's uuid is what the billed row links to — the statement screen,
        // which already lists their invoices. Finance owns no student uuid; the ACL port resolves it.
        ->assertJsonPath('buckets.billed.rows.0.student.uuid', $billed->uuid)
        ->assertJsonPath('buckets.billed.rows.0.outcome', BulkInvoiceRunOutcome::Billed->value)
        // `reason` is non-null ONLY on `failed`; it is never the run's own failure_reason.
        ->assertJsonPath('buckets.billed.rows.0.reason', null)
        ->assertJsonPath('buckets.unplaceable.rows.0.student.uuid', $unplaceable->uuid)
        ->assertJsonPath('buckets.unplaceable.rows.0.outcome', BulkInvoiceRunOutcome::Unplaceable->value);

    // Not vacuous: the rows really are in the table under those outcomes.
    expect(BulkInvoiceRunRow::withoutGlobalScopes()->where('outcome', BulkInvoiceRunOutcome::Billed->value)->count())->toBe(1)
        ->and(BulkInvoiceRunRow::withoutGlobalScopes()->where('outcome', BulkInvoiceRunOutcome::Unplaceable->value)->count())->toBe(1);
});

/* ── 5 · Authorization: one ability, and nothing new coined ────────────────────────────────── */

it('refuses every route to a seat that can read finance but cannot generate an invoice', function () {
    $ctx = birsSchool();
    birsSchedule($ctx);
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    $generator = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);
    $uuid = birsAs($generator, $ctx['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
    ])->assertCreated()->json('uuid');

    // finance.access ONLY — a seat that can read a statement and nothing more.
    $reader = birsUser($ctx['school'], [BIRS_ACCESS]);

    birsAs($reader, $ctx['school'])
        ->getJson('/api/v1/finance/bulk-invoice-runs/preview?term_id='.$ctx['term']->id.'&class_level_id='.$ctx['level']->id)
        ->assertForbidden();
    birsAs($reader, $ctx['school'])->getJson('/api/v1/finance/bulk-invoice-runs')->assertForbidden();
    birsAs($reader, $ctx['school'])->getJson('/api/v1/finance/bulk-invoice-runs/'.$uuid)->assertForbidden();
    birsAs($reader, $ctx['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
    ])->assertForbidden();

    // The refusal was a refusal, not a silent no-op: still exactly the one run.
    expect(BulkInvoiceRun::withoutGlobalScopes()->count())->toBe(1);

    // And the PAGES, which carry the same ability so a visible nav item can never 403 on click.
    birsAs($reader, $ctx['school'])->get('/finance/bulk-invoice-runs')->assertForbidden();
    birsAs($reader, $ctx['school'])->get('/finance/bulk-invoice-runs/'.$uuid)->assertForbidden();

    birsAs($generator, $ctx['school'])->get('/finance/bulk-invoice-runs')->assertOk();
    birsAs($generator, $ctx['school'])->get('/finance/bulk-invoice-runs/'.$uuid)->assertOk();
});

it('hands the page the school’s OWN terms and class levels, populated', function () {
    // The empty-select defect: a props query that binds the School MODEL where an int is wanted
    // matches nothing and renders a form that looks fine and cannot be submitted, with every test
    // still passing. It has shipped twice on this feature. The arm is the prop being non-empty.
    $ctx = birsSchool();
    $other = birsSchool();
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    $response = birsAs($actor, $ctx['school'])->get('/finance/bulk-invoice-runs');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/finance/bulk-invoice-runs/index')
            // `collect()` because assertInertia hands nested props over as a Collection, not an
            // array — a bare count()/array_column() reads as though it had an array and fatals.
            ->where('terms', fn ($terms) => collect($terms)->count() === 1
                && collect($terms)->first()['id'] === $ctx['term']->id)
            // Two class levels in the fixture School, and NEITHER of the other School's.
            ->where('class_levels', fn ($levels) => collect($levels)->count() === 2
                && ! collect($levels)->pluck('id')->contains($other['level']->id))
        );
});

it('defaults the term to the school’s CURRENT one, resolved from the current session', function () {
    /*
     * THE OPERATOR PICKS A CLASS LEVEL; the term arrives pre-filled. The default is
     * App\Support\CurrentTerm — the school's `is_current` session, then the `active` term inside it,
     * falling back to the last term by `order` — which is the expression SetupController carried and
     * now reads from the same place rather than a copy.
     *
     * THE FIXTURE MAKES THE DEFAULT NON-TRIVIAL. A second session is seeded as the current one with
     * its own active term, and `birsSchool()`'s term is left in a session that is NOT current. So a
     * screen defaulting to "the first term in the list", or to "any active term", picks the WRONG one
     * — the arm would pass on either of those bugs if the school had only one term.
     *
     * `terms.id` — the ROW — throughout. The `curricula.term` ordinal (1|2|3) that this could once be
     * confused with was dropped in 2026_05_06 and replaced by a `term_id` FK; `SHOW COLUMNS FROM
     * curricula` on the live database returns `term_id` and no `term`.
     */
    $ctx = birsSchool();
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    // THE DECOY IS NEWER, HIGHER-ORDERED AND ALSO ACTIVE, AND IS NOT THE CURRENT SESSION'S. That
    // shape is what makes the arm discriminating: it reds on "the newest term", on "the term with the
    // highest order", on "the first term in the props list" (which is ordered newest-first) and on
    // "any active term" — every plausible wrong resolution — and it can only be satisfied by going
    // through the school's `is_current` session.
    $decoy = ActiveSchool::runFor($ctx['school']->id, function () use ($ctx) {
        $session = AcademicSession::create([
            'school_id' => $ctx['school']->id, 'name' => '2027/2028-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => false,
        ]);

        return Term::create([
            'academic_session_id' => $session->id, 'school_id' => $ctx['school']->id, 'name' => 'Third Term',
            'slug' => 'term-'.Str::random(8), 'order' => 3, 'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
        ]);
    });

    birsAs($actor, $ctx['school'])->get('/finance/bulk-invoice-runs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/finance/bulk-invoice-runs/index')
            // The fixture's own term — the one inside the CURRENT session.
            ->where('default_term_id', $ctx['term']->id)
            // Not vacuous: the decoy is on the wire too, so the default is a choice among two rather
            // than the only thing available.
            ->where('terms', fn ($terms) => collect($terms)->pluck('id')->contains($decoy->id)),
        );

    expect($decoy->id)->toBeGreaterThan($ctx['term']->id);
});

it('lets an explicit term OVERRIDE the default, because billing a past term is a real act', function () {
    /*
     * THE DEFAULT IS A DEFAULT AND NEVER A CONSTRAINT. A child who enrols late is billed for the term
     * they enrolled in, so a screen that pinned "current" would make that case unreachable — and a
     * server that re-resolved the term at write time would make the override unrepresentable on the
     * wire. The term is therefore named EXPLICITLY on both the preview and the start, and what the
     * caller names is what runs.
     */
    $ctx = birsSchool();
    $actor = birsUser($ctx['school'], [BIRS_ACCESS, BIRS_GENERATE]);
    birsSchedule($ctx);
    birsStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    // A NEWER current session, so the fixture's term — the one carrying the schedule and the cohort —
    // is now a PAST one. That is exactly the late-enrolment case: the child is billed for the term
    // they enrolled in, which is not the term the school is in today.
    $current = ActiveSchool::runFor($ctx['school']->id, function () use ($ctx) {
        AcademicSession::query()->where('school_id', $ctx['school']->id)->update(['is_current' => false]);

        $session = AcademicSession::create([
            'school_id' => $ctx['school']->id, 'name' => '2027/2028-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);

        return Term::create([
            'academic_session_id' => $session->id, 'school_id' => $ctx['school']->id, 'name' => 'Second Term',
            'slug' => 'term-'.Str::random(8), 'order' => 2, 'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
        ]);
    });

    // The page's default is the CURRENT term…
    birsAs($actor, $ctx['school'])->get('/finance/bulk-invoice-runs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('default_term_id', $current->id));

    // …and a start naming the PAST one runs against the past one, not the default.
    $uuid = birsAs($actor, $ctx['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
    ])->assertCreated()->json('uuid');

    $run = BulkInvoiceRun::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

    expect((int) $run->term_id)->toBe($ctx['term']->id)
        ->and((int) $run->term_id)->not->toBe($current->id);

    // And it BILLED — the past term's schedule and cohort are the ones that were used, so the
    // override reached the domain rather than merely being recorded on the row.
    birsAs($actor, $ctx['school'])->getJson('/api/v1/finance/bulk-invoice-runs/'.$uuid)
        ->assertOk()
        ->assertJsonPath('counts.billed', 1)
        ->assertJsonPath('term_id', $ctx['term']->id);
});

/* ── 6 · Isolation ─────────────────────────────────────────────────────────────────────────── */

it('shows School A no run of School B, and answers 404 rather than 403 for a foreign run', function () {
    $a = birsSchool();
    $b = birsSchool();
    birsSchedule($a);
    birsSchedule($b);
    birsStudent($a, $a['arm']->id, $a['term']->id);
    birsStudent($b, $b['arm']->id, $b['term']->id);

    $actorA = birsUser($a['school'], [BIRS_ACCESS, BIRS_GENERATE]);
    $actorB = birsUser($b['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    $runA = birsAs($actorA, $a['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $a['term']->id, 'class_level_id' => $a['level']->id,
    ])->assertCreated()->json('uuid');

    $runB = birsAs($actorB, $b['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $b['term']->id, 'class_level_id' => $b['level']->id,
    ])->assertCreated()->json('uuid');

    // BY UUID, NOT BY COUNT AND NOT BY LABEL. Two Schools in this fixture carry a term called
    // "First Term" and a class level called "JSS 1"; a label comparison would pass on the wrong row.
    $listA = birsAs($actorA, $a['school'])->getJson('/api/v1/finance/bulk-invoice-runs')->assertOk();

    expect(array_column($listA->json('data'), 'uuid'))->toBe([$runA]);

    // 404, established rather than assumed: BulkInvoiceRun carries BelongsToSchool, so SchoolScope
    // filters the implicit binding and B's uuid resolves to no model at all. 403 would confirm that
    // a run with that uuid exists somewhere.
    birsAs($actorA, $a['school'])->getJson('/api/v1/finance/bulk-invoice-runs/'.$runB)->assertNotFound();
    birsAs($actorB, $b['school'])->getJson('/api/v1/finance/bulk-invoice-runs/'.$runA)->assertNotFound();

    // Both runs really exist — the 404 is isolation, not an empty database.
    expect(BulkInvoiceRun::withoutGlobalScopes()->count())->toBe(2);
});

/* ── 7 · Fail-closed: a read with no School context is refused, never answered unscoped ────── */

/**
 * A platform super admin with NO school selected — the exact seat a cold review used to read both
 * Schools' runs out of this endpoint.
 *
 * `auth.gate_before_superadmin` is switched ON because that bypass is what gets this seat PAST the
 * route's `permission:finance.invoice.generate`: it is a maker ability, not a checker one, so ADR
 * 0040's exclusion does not apply to it. With the bypass off the arm would be measuring a 403 from
 * the permission middleware and would never reach the scope at all — green, and about nothing.
 */
function birsSuperAdminWithoutSchool(): User
{
    config(['auth.gate_before_superadmin' => true]);

    $super = User::factory()->create(['school_id' => null]);
    setPermissionsTeamId(null);
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();

    return $super;
}

/**
 * A super admin acting INSIDE a chosen School — and the `Referer` is load-bearing, not decoration.
 *
 * `/api/*` carries Sanctum's `statefulApi()`, so the session middleware runs ONLY when the request
 * looks like it came from the SPA (Referer/Origin on a stateful domain). Without the header
 * `$request->hasSession()` is false, `ActiveSchool::id()` skips the session branch entirely, and a
 * `withSession(['school_id' => …])` in a test is silently inert.
 *
 * Every other arm in this file gets away with that because its actor is an ordinary user whose
 * `users.school_id` is set, and `ActiveSchool::id()` falls back to it. A super admin has no
 * `school_id` and is EXCLUDED from that fallback by design — "without an explicit selection they act
 * globally" (ActiveSchool.php:25-26) — so the session is the only route in, and this is what makes it
 * actually arrive.
 */
function birsAsSuperIn(User $super, School $school)
{
    return test()->actingAs($super)
        ->withHeader('Referer', config('app.url'))
        ->withSession(['school_id' => $school->id]);
}

it('REFUSES a super admin with no school selected, instead of answering with every School\'s runs', function () {
    /*
     * ADR 0036: super_admin bypasses AUTHORIZATION, never ISOLATION. The bypass is what lets this
     * seat past the route's ability — and isolation here is SchoolScope, which without an entry in
     * `rbac.fail_closed_models` falls to its SILENT-UNSCOPED branch rather than to a refusal.
     *
     * MEASURED IN BOTH DIRECTIONS, by planting the two entries away:
     *
     *   without them → 200, `{"data":[{"uuid":"a28bc463-…","term_id":2,…}` — both Schools' runs;
     *   with them    → 409, `{"message":"No active school selected."}`.
     *
     * The 409 is MissingSchoolContextException's own render (MissingSchoolContextException.php:31),
     * the same answer the other ten finance transactional models already give.
     */
    $a = birsSchool();
    $b = birsSchool();
    birsSchedule($a);
    birsSchedule($b);
    birsStudent($a, $a['arm']->id, $a['term']->id);
    birsStudent($b, $b['arm']->id, $b['term']->id);

    $actorA = birsUser($a['school'], [BIRS_ACCESS, BIRS_GENERATE]);
    $actorB = birsUser($b['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    $runA = birsAs($actorA, $a['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $a['term']->id, 'class_level_id' => $a['level']->id,
    ])->assertCreated()->json('uuid');

    $runB = birsAs($actorB, $b['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $b['term']->id, 'class_level_id' => $b['level']->id,
    ])->assertCreated()->json('uuid');

    $super = birsSuperAdminWithoutSchool();

    // THE LIST, and the two run tables are separate models so both are asserted: `index` reads
    // BulkInvoiceRun, `show` reads BulkInvoiceRunRow as well.
    test()->actingAs($super)->getJson('/api/v1/finance/bulk-invoice-runs')
        ->assertStatus(409)
        ->assertJsonPath('message', 'No active school selected.');

    test()->actingAs($super)->getJson('/api/v1/finance/bulk-invoice-runs/'.$runA)
        ->assertStatus(409);
    test()->actingAs($super)->getJson('/api/v1/finance/bulk-invoice-runs/'.$runB)
        ->assertStatus(409);

    // NOT VACUOUS. Both runs exist and are readable by a seat that has a School — the arm below is
    // the other half of that, and this line keeps the refusal above from being an empty database.
    expect(BulkInvoiceRun::withoutGlobalScopes()->count())->toBe(2);
});

it('still answers a super admin who HAS selected a school, with that school\'s runs and no other', function () {
    /*
     * The other half, and the one that keeps the fix from reading as "we broke the endpoint to close
     * an edge case". Fail-closed replaces the silent-unscoped branch ONLY; the scoped branch is
     * untouched, and a platform admin who has picked a school is an ordinary scoped reader.
     */
    $a = birsSchool();
    $b = birsSchool();
    birsSchedule($a);
    birsSchedule($b);
    birsStudent($a, $a['arm']->id, $a['term']->id);
    birsStudent($b, $b['arm']->id, $b['term']->id);

    $actorA = birsUser($a['school'], [BIRS_ACCESS, BIRS_GENERATE]);
    $actorB = birsUser($b['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    $runA = birsAs($actorA, $a['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $a['term']->id, 'class_level_id' => $a['level']->id,
    ])->assertCreated()->json('uuid');

    $runB = birsAs($actorB, $b['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $b['term']->id, 'class_level_id' => $b['level']->id,
    ])->assertCreated()->json('uuid');

    $super = birsSuperAdminWithoutSchool();

    $list = birsAsSuperIn($super, $a['school'])
        ->getJson('/api/v1/finance/bulk-invoice-runs')->assertOk();

    // BY UUID, not by count: both Schools name their term "First Term" and their level "JSS 1".
    expect(array_column($list->json('data'), 'uuid'))->toBe([$runA]);

    birsAsSuperIn($super, $a['school'])
        ->getJson('/api/v1/finance/bulk-invoice-runs/'.$runA)->assertOk();

    // And the school they picked is the boundary, not their platform role: B's run is a 404 to them
    // exactly as it is to School A's bursar.
    birsAsSuperIn($super, $a['school'])
        ->getJson('/api/v1/finance/bulk-invoice-runs/'.$runB)->assertNotFound();
});

it('bills only School A when School A runs, even though both Schools have the same coordinates by name', function () {
    // The job's own isolation is BulkInvoiceRunTest's subject; what is asserted here is that the
    // SURFACE hands it the right School — the run's school_id comes from the model's own
    // BelongsToSchool fill, and the job's argument comes from that column.
    $a = birsSchool();
    $b = birsSchool();
    birsSchedule($a);
    birsSchedule($b);
    birsStudent($a, $a['arm']->id, $a['term']->id);
    $bStudent = birsStudent($b, $b['arm']->id, $b['term']->id);

    $actorA = birsUser($a['school'], [BIRS_ACCESS, BIRS_GENERATE]);

    birsAs($actorA, $a['school'])->postJson('/api/v1/finance/bulk-invoice-runs', [
        'term_id' => $a['term']->id, 'class_level_id' => $a['level']->id,
    ])->assertCreated()->assertJsonPath('counts.billed', 1);

    expect(Invoice::withoutGlobalScopes()->where('school_id', $b['school']->id)->count())->toBe(0)
        ->and(BulkInvoiceRunRow::withoutGlobalScopes()->where('student_id', $bStudent->id)->count())->toBe(0);
});
