<?php

/*
 * §9 step 5b-ii — THE DECISION SURFACE for opening-balance batches, over HTTP.
 *
 * WHAT THIS FILE IS FOR, AND WHAT IT DELIBERATELY DOES NOT RE-PROVE. OpeningBalanceApprovalGateTest
 * proves the GATE at the Action layer: who may decide, from which state, and what each decision does
 * to the money. This file proves that the gate is REACHABLE and CORRECTLY WRAPPED — the route's
 * ability, the record-level Policy, the FormRequest, the 422 an Action's refusal becomes, and the
 * fact that approving through the endpoint really posts rather than returning a cheerful 200. It
 * asserts the posting MECHANICS once (PROOF B) and no further; those are OpeningBalancePostingTest's.
 *
 * THREE LAYERS STAND BETWEEN A MAKER AND THEIR OWN CUTOVER, and each arm below is pinned to ONE of
 * them. The earlier version of this paragraph claimed the Policy was "the only thing left to refuse
 * them", and that was wrong in a way the watched red exposed — so it is written out properly here,
 * because a header that overclaims is a dead assertion one level up.
 *
 *   1. THE ROUTE'S ABILITY (`permission:finance.opening-balance.{approve,reject}`). Remove it and a
 *      permissionless request reaches the controller, where the Policy still refuses — so no HTTP
 *      arm here can see its removal. PROOF H therefore reads the REGISTERED ROUTES and asserts the
 *      abilities at the router itself. It is also the only arm that catches the reject route
 *      re-gated on the APPROVE ability, which PROOF D structurally cannot: an approve-only actor is
 *      then admitted by the middleware and refused by the Policy's own `…reject` check, so PROOF D
 *      stays green through exactly that mutation.
 *   2. THE POLICY's maker ≠ checker rule. Remove it and the maker is STILL refused — by layer 3,
 *      under the lock — but with **422 instead of 403**. That is what the watched red produced, and
 *      it is why PROOF C and C2 assert a STATUS CODE rather than merely "refused": the Policy is
 *      pinned by the code it produces, not by being the sole refusal. Those two arms give the maker
 *      the checker's full ability set on purpose, so layer 1 cannot be what refuses them.
 *   3. THE ACTION's locked re-read of `submitted_by_user_id`, and under that the
 *      `…_maker_ne_checker` CHECK. Neither is this file's subject —
 *      OpeningBalanceApprovalGateTest owns them — but layer 2's red is only readable if you know
 *      they are there.
 *
 * Every arm was watched RED before it was watched green; the mutations and both outputs are in the
 * implementation report.
 */

use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Finance\Policies\OpeningBalanceBatchPolicy;
use App\Models\AcademicSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** The finance API group's own gate. Every actor below needs it before their own ability matters. */
const OBDS_ACCESS = 'finance.access';

const OBDS_APPROVE = 'finance.opening-balance.approve';

const OBDS_REJECT = 'finance.opening-balance.reject';

/**
 * A web-session user in $school holding EXACTLY $permissions via a dedicated role — the shape
 * PaymentRecordGateTest and OpeningBalanceImportTemplateTest both use, and for their reason: role
 * membership is what a grants commit changes, so a role-keyed actor would move with the thing under
 * test.
 *
 * @param  list<string>  $permissions
 */
function obdsUser(School $school, array $permissions): User
{
    $roleName = 'obds_'.substr(md5(implode(',', $permissions)), 0, 10);
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
 * A School and the term whose closing position a cutover file carries.
 *
 * @return array{school: School, term: Term}
 */
function obdsSchool(): array
{
    $school = School::factory()->create();

    return ActiveSchool::runFor($school->id, function () use ($school) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'Third Term',
            'slug' => 'term-'.Str::random(8), 'order' => 3, 'start_date' => now()->subMonths(4),
            'end_date' => now()->subMonth(), 'status' => 'active',
        ]);

        return ['school' => $school, 'term' => $term];
    });
}

/**
 * A batch in $status with its staged rows, written directly. The validator has 47KB of its own
 * coverage and what this file is about is what happens to a batch that has already reached the state
 * a checker sees it in.
 *
 * `submitted_by_user_id` is set HERE rather than through SubmitOpeningBalanceBatch because the maker
 * side has no HTTP path yet (that is the operator screen, the next commit) and this file must not
 * depend on one. The column is the whole input to the Policy, so writing it directly is writing the
 * thing under test — not a shortcut around it.
 *
 * @param  list<array{student: Student, label: string, kobo: int}>  $rows
 */
function obdsBatch(
    array $ctx,
    array $rows,
    ?User $maker = null,
    OpeningBalanceBatchStatus $status = OpeningBalanceBatchStatus::Submitted,
    string $reference = 'WCBS-DECIDE-1',
): OpeningBalanceBatch {
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $rows, $maker, $status, $reference) {
        $batch = OpeningBalanceBatch::create([
            'batch_reference' => $reference,
            'filename' => $reference.'.csv',
            'status' => $status,
            'row_count' => count($rows),
            'file_row_count' => count($rows),
            'control_total' => Money::fromKobo(array_sum(array_column($rows, 'kobo'))),
            'cutover_date' => now()->toDateString(),
            'term_id' => $ctx['term']->id,
            'uploaded_by_user_id' => $maker?->id,
            'submitted_by_user_id' => $status === OpeningBalanceBatchStatus::Submitted ? $maker?->id : null,
            'submitted_at' => $status === OpeningBalanceBatchStatus::Submitted ? now() : null,
        ]);

        foreach ($rows as $i => $row) {
            OpeningBalanceRow::create([
                'batch_id' => $batch->id,
                'line_number' => $i + 1,
                'admission_number' => $row['student']->admission_number,
                'wcbs_student_ref' => 'WCBS-'.$row['student']->id,
                'fee_type_label' => $row['label'],
                'balance' => Money::fromKobo($row['kobo']),
                'student_total_balance' => Money::fromKobo($row['kobo']),
                'wcbs_bill_reference' => null,
                'student_id' => $row['student']->id,
                'status' => OpeningBalanceRowStatus::Ok,
            ]);
        }

        return $batch->refresh();
    });
}

function obdsApprove(User $actor, School $school, OpeningBalanceBatch $batch)
{
    return test()->actingAs($actor)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/opening-balance-batches/{$batch->uuid}/approve");
}

function obdsReject(User $actor, School $school, OpeningBalanceBatch $batch, array $payload)
{
    return test()->actingAs($actor)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/opening-balance-batches/{$batch->uuid}/reject", $payload);
}

/** The subledger, counted across the whole database — a decision that wrote anywhere fails this. */
function obdsSubledgerCounts(): array
{
    return [
        (int) DB::scalar('SELECT COUNT(*) FROM finance_ledger_transactions'),
        (int) DB::scalar('SELECT COUNT(*) FROM finance_payments'),
    ];
}

// ── PROOF A — the Policy is resolved by convention, and the convention is not assumed ────────────

it('PROOF A — the Gate resolves OpeningBalanceBatchPolicy for an OpeningBalanceBatch, with no registration written anywhere', function () {
    // Nothing in a service provider names this class: Laravel's guesser maps App\Finance\Models\X to
    // App\Finance\Policies\XPolicy. A money guard standing on a naming convention is worth an
    // assertion rather than a comment — rename or move the file and this goes red, instead of every
    // can_approve on the queue quietly turning false and the queue looking merely empty.
    expect(Gate::getPolicyFor(OpeningBalanceBatch::class))->toBeInstanceOf(OpeningBalanceBatchPolicy::class);
});

// ── PROOF B — approving through the endpoint IS the post ─────────────────────────────────────────

it('PROOF B — APPROVE over HTTP posts the cutover: the charges and the netted migrated payment exist afterwards', function () {
    $ctx = obdsSchool();
    $maker = obdsUser($ctx['school'], [OBDS_ACCESS, OBDS_APPROVE, OBDS_REJECT]);
    $checker = obdsUser($ctx['school'], [OBDS_ACCESS, OBDS_APPROVE, OBDS_REJECT]);

    $owing = Student::factory()->create(['school_id' => $ctx['school']->id]);
    $inCredit = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obdsBatch($ctx, [
        ['student' => $owing, 'label' => 'Tuition', 'kobo' => 400000],
        ['student' => $owing, 'label' => 'Bus', 'kobo' => 100000],
        ['student' => $inCredit, 'label' => 'Tuition', 'kobo' => -150000],
    ], $maker);

    // The precondition, asserted: nothing is in the subledger BEFORE the request. Without it, a
    // proof that rows exist afterwards would pass against rows some earlier step wrote.
    expect(obdsSubledgerCounts())->toBe([0, 0]);

    $response = obdsApprove($checker, $ctx['school'], $batch);

    $response->assertOk()
        ->assertJsonPath('type', 'opening_balance')
        ->assertJsonPath('status', OpeningBalanceBatchStatus::Posted->value);

    ActiveSchool::runFor($ctx['school']->id, function () use ($batch, $checker) {
        $fresh = $batch->refresh();
        expect($fresh->status)->toBe(OpeningBalanceBatchStatus::Posted)
            ->and($fresh->decided_by_user_id)->toBe($checker->id)
            ->and($fresh->posted_by_user_id)->toBe($checker->id)
            ->and($fresh->posted_at)->not->toBeNull();
    });

    // A 200 whose money never moved is the exact false green this arm exists to refuse: two charges
    // for the student who owes, one netted migrated payment for the student in credit.
    [$ledger, $payments] = obdsSubledgerCounts();
    expect($ledger)->toBe(3)   // two charges + the credit's payment entry
        ->and($payments)->toBe(1);

    expect((int) DB::scalar(
        "SELECT COUNT(*) FROM finance_ledger_transactions WHERE source_type = 'opening_balance_row' AND student_id = ?",
        [$owing->id],
    ))->toBe(2)
        ->and((string) DB::scalar('SELECT origin FROM finance_payments WHERE student_id = ?', [$inCredit->id]))
        ->toBe('migrated');
});

// ── PROOF C — the maker cannot decide their own batch, and the refusal is the POLICY's ───────────

it('PROOF C — the MAKER is refused on approve with 403 while holding the checker ability, and NOTHING posts', function () {
    $ctx = obdsSchool();

    // The maker holds exactly what a checker holds. The route middleware therefore admits them, and
    // the only thing left that can refuse is the record-level maker ≠ checker rule.
    $maker = obdsUser($ctx['school'], [OBDS_ACCESS, OBDS_APPROVE, OBDS_REJECT]);
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obdsBatch($ctx, [['student' => $student, 'label' => 'Tuition', 'kobo' => 250000]], $maker);

    obdsApprove($maker, $ctx['school'], $batch)->assertForbidden();

    ActiveSchool::runFor($ctx['school']->id, function () use ($batch) {
        expect($batch->refresh()->status)->toBe(OpeningBalanceBatchStatus::Submitted)
            ->and($batch->refresh()->decided_by_user_id)->toBeNull()
            ->and($batch->refresh()->posted_at)->toBeNull();
    });

    expect(obdsSubledgerCounts())->toBe([0, 0]);
});

it('PROOF C2 — the MAKER is refused on reject too, with a valid reason in hand', function () {
    $ctx = obdsSchool();
    $maker = obdsUser($ctx['school'], [OBDS_ACCESS, OBDS_APPROVE, OBDS_REJECT]);
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obdsBatch($ctx, [['student' => $student, 'label' => 'Tuition', 'kobo' => 250000]], $maker);

    obdsReject($maker, $ctx['school'], $batch, ['reason' => 'I changed my mind.'])->assertForbidden();

    ActiveSchool::runFor($ctx['school']->id, function () use ($batch) {
        expect($batch->refresh()->status)->toBe(OpeningBalanceBatchStatus::Submitted)
            ->and($batch->refresh()->rejection_reason)->toBeNull();
    });
});

// ── PROOF D — the two abilities are separate gates ───────────────────────────────────────────────

it('PROOF D — a holder of approve but NOT reject is refused on the reject route, and still admitted on approve', function () {
    // The two routes carry two different permissions because they are two abilities, and a seat may
    // hold one without the other. Asserted in BOTH directions on ONE actor: a refusal alone would
    // also be produced by an actor the group gate never admitted.
    $ctx = obdsSchool();
    $maker = obdsUser($ctx['school'], [OBDS_ACCESS]);
    $approveOnly = obdsUser($ctx['school'], [OBDS_ACCESS, OBDS_APPROVE]);
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obdsBatch($ctx, [['student' => $student, 'label' => 'Tuition', 'kobo' => 250000]], $maker);

    obdsReject($approveOnly, $ctx['school'], $batch, ['reason' => 'Control total is wrong.'])
        ->assertForbidden();

    ActiveSchool::runFor($ctx['school']->id, fn () => expect($batch->refresh()->status)
        ->toBe(OpeningBalanceBatchStatus::Submitted));

    obdsApprove($approveOnly, $ctx['school'], $batch)->assertOk();
});

// ── PROOF E — rejecting through the endpoint moves no money ──────────────────────────────────────

it('PROOF E — REJECT over HTTP records the reason and the checker, and writes nothing to the subledger', function () {
    $ctx = obdsSchool();
    $maker = obdsUser($ctx['school'], [OBDS_ACCESS]);
    $checker = obdsUser($ctx['school'], [OBDS_ACCESS, OBDS_REJECT]);
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obdsBatch($ctx, [['student' => $student, 'label' => 'Tuition', 'kobo' => 250000]], $maker);

    obdsReject($checker, $ctx['school'], $batch, ['reason' => 'The control total does not match WCBS.'])
        ->assertOk()
        ->assertJsonPath('status', OpeningBalanceBatchStatus::Rejected->value)
        ->assertJsonPath('rejection_reason', 'The control total does not match WCBS.');

    ActiveSchool::runFor($ctx['school']->id, function () use ($batch, $checker) {
        $fresh = $batch->refresh();
        expect($fresh->status)->toBe(OpeningBalanceBatchStatus::Rejected)
            ->and($fresh->decided_by_user_id)->toBe($checker->id)
            ->and($fresh->posted_at)->toBeNull();

        // The staged rows are RETAINED — a refused cutover must stay readable.
        expect(OpeningBalanceRow::query()->where('batch_id', $fresh->id)->count())->toBe(1);
    });

    expect(obdsSubledgerCounts())->toBe([0, 0]);
});

it('PROOF E2 — a reject with no reason is 422 from the FormRequest, and the batch stays submitted', function () {
    $ctx = obdsSchool();
    $maker = obdsUser($ctx['school'], [OBDS_ACCESS]);
    $checker = obdsUser($ctx['school'], [OBDS_ACCESS, OBDS_REJECT]);
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obdsBatch($ctx, [['student' => $student, 'label' => 'Tuition', 'kobo' => 250000]], $maker);

    obdsReject($checker, $ctx['school'], $batch, [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    // …and one longer than the column can hold, which would otherwise abort the write at 1406 after
    // the checker had typed it.
    obdsReject($checker, $ctx['school'], $batch, ['reason' => str_repeat('a', 256)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    ActiveSchool::runFor($ctx['school']->id, fn () => expect($batch->refresh()->status)
        ->toBe(OpeningBalanceBatchStatus::Submitted));
});

// ── PROOF F — a batch that is not `submitted` cannot be decided through the endpoint ─────────────

it('PROOF F — a VALIDATED batch is refused with 422 on both decisions, and nothing posts', function () {
    // The state rule lives in the Actions, under the lock. This proves the controller SURFACES it as
    // a 422 a client can read, rather than letting a BusinessRuleException reach the handler as a 500.
    $ctx = obdsSchool();
    $maker = obdsUser($ctx['school'], [OBDS_ACCESS]);
    $checker = obdsUser($ctx['school'], [OBDS_ACCESS, OBDS_APPROVE, OBDS_REJECT]);
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    $batch = obdsBatch(
        $ctx,
        [['student' => $student, 'label' => 'Tuition', 'kobo' => 250000]],
        $maker,
        OpeningBalanceBatchStatus::Validated,
    );

    obdsApprove($checker, $ctx['school'], $batch)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only a submitted opening-balance batch can be approved; this one is validated.');

    obdsReject($checker, $ctx['school'], $batch, ['reason' => 'Too early.'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only a submitted opening-balance batch can be rejected; this one is validated.');

    expect(obdsSubledgerCounts())->toBe([0, 0]);
});

// ── PROOF H — the route's own ability, read off the ROUTER ───────────────────────────────────────

/**
 * The ABILITIES each opening-balance route requires, keyed by `METHOD uri`.
 *
 * Read from `Route::getRoutes()` — the registered stack — and never from routes/endpoints/finance.php
 * as text. The precedent is ApprovalsQueueFeedCoverageTest::aqfPendingRoutes(), and the reason is the
 * same: a source grep agrees with the file it just read, while the router is what actually runs. It
 * would also miss middleware applied from a GROUP, which is exactly where `finance.access` comes
 * from (routes/api.php) — so a source-level check could not tell a route inside the finance group
 * from one outside it.
 *
 * TWO THINGS THE ROUTER DOES NOT DO THAT `route:list` DOES, both found by watching this fail:
 * `gatherMiddleware()` returns the UNRESOLVED alias (`permission:finance.access`) where
 * `route:list --json` prints the resolved class, and `uri()` returns `{batch}` where the route was
 * declared `{batch:uuid}` — Laravel strips the field binding off the compiled uri. The keys below
 * are therefore the router's spelling, not the routes file's, which is the whole point of reading
 * the router.
 *
 * @return array<string, list<string>>
 */
function obdsRouteAbilities(): array
{
    $found = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/v1/finance/opening-balance-batches/')) {
            continue;
        }

        $abilities = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                continue;
            }

            $abilities[] = substr($middleware, strlen('permission:'));
        }

        sort($abilities);
        $found[$route->methods()[0].' '.$uri] = $abilities;
    }

    ksort($found);

    return $found;
}

it('PROOF H — each decision route carries its OWN ability at the router, and only that one', function () {
    // THE LAYER NO HTTP ARM IN THIS FILE CAN SEE. Strip `permission:…approve` off the approve route
    // and every other arm here stays green: a permissionless request simply travels one layer
    // further and is refused by the Policy, with the same 403. Two gates, and until this arm neither
    // was load-bearing on its own.
    //
    // It is asserted as an EXACT SET per route, not as "contains". A `contains` check passes the
    // mutation that matters most — the reject route re-gated on the approve ability — because the
    // Policy's own `…reject` clause then produces the identical 403 an approve-only actor already
    // expects, and PROOF D cannot tell the two apart. The set says which ability, not merely that
    // there is one.
    // The two read routes are included rather than filtered out: this arm is then also the pin that
    // says the template stays on the MAKER ability and `pending` on the checker's, so a later edit
    // cannot quietly hand the format to every approver.
    expect(obdsRouteAbilities())->toBe([
        'GET api/v1/finance/opening-balance-batches/import/template' => [
            OBDS_ACCESS,
            'finance.opening-balance.submit',
        ],
        'GET api/v1/finance/opening-balance-batches/pending' => [
            OBDS_ACCESS,
            OBDS_APPROVE,
        ],
        'POST api/v1/finance/opening-balance-batches/{batch}/approve' => [
            OBDS_ACCESS,
            OBDS_APPROVE,
        ],
        'POST api/v1/finance/opening-balance-batches/{batch}/reject' => [
            OBDS_ACCESS,
            OBDS_REJECT,
        ],
    ], 'A decision route no longer requires its own ability at the router. Either a permission '
        .'middleware was dropped — in which case the Policy is the only gate left and no other arm '
        .'here can tell — or one route now carries another\'s ability, which PROOF D cannot see.');
});

// ── PROOF G — the queue's flags are live, and the Policy is what made them live ──────────────────

it('PROOF G — the pending feed now reports can_approve TRUE for a checker and FALSE for the maker', function () {
    // 5a shipped these flags computed through the Gate and false for everybody, because no policy
    // existed to consult. This is the assertion that they turned on, and that they turned on
    // ASYMMETRICALLY — a viewer-relative true for one user and false for another proves the Policy
    // is being consulted rather than a blanket allow.
    $ctx = obdsSchool();
    $maker = obdsUser($ctx['school'], [OBDS_ACCESS, OBDS_APPROVE, OBDS_REJECT]);
    $checker = obdsUser($ctx['school'], [OBDS_ACCESS, OBDS_APPROVE, OBDS_REJECT]);
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    obdsBatch($ctx, [['student' => $student, 'label' => 'Tuition', 'kobo' => 250000]], $maker);

    $asChecker = test()->actingAs($checker)->withSession(['school_id' => $ctx['school']->id])
        ->getJson('/api/v1/finance/opening-balance-batches/pending');

    $asChecker->assertOk()
        ->assertJsonPath('data.0.can_approve', true)
        ->assertJsonPath('data.0.can_reject', true);

    $asMaker = test()->actingAs($maker)->withSession(['school_id' => $ctx['school']->id])
        ->getJson('/api/v1/finance/opening-balance-batches/pending');

    $asMaker->assertOk()
        ->assertJsonPath('data.0.can_approve', false)
        ->assertJsonPath('data.0.can_reject', false);
});

it('PROOF G2 — an approve-but-not-reject checker is served the row with can_reject FALSE', function () {
    // THE DEAD-BUTTON DEFECT, FOUND INSIDE THE MODULE WRITTEN TO PREVENT IT. The feed list's own
    // docblock (resources/js/lib/finance/approval-feeds.ts) rules that a row an approver can see,
    // press and fail on is worse than an absent one — "present-and-dead is dishonestly broken". This
    // type reaches that state by a route nobody would look at: `pending` is gated on `…approve`
    // ALONE, so a checker holding approve and not reject is served the row, and the Resource's
    // `can_reject` is the only thing standing between them and a Reject button that 403s at the
    // route. Nothing pinned it: PROOF G's two actors both hold the full triple, and ARM 3b of
    // ApprovalsQueueRendersEveryTypeTest reads `can_approve` only.
    //
    // Both flags are asserted on ONE actor, because the pair is the claim: `true` alone would also
    // be produced by a blanket allow, and `false` alone by a viewer the Gate refuses entirely.
    $ctx = obdsSchool();
    $maker = obdsUser($ctx['school'], [OBDS_ACCESS]);
    $approveOnly = obdsUser($ctx['school'], [OBDS_ACCESS, OBDS_APPROVE]);
    $student = Student::factory()->create(['school_id' => $ctx['school']->id]);

    obdsBatch($ctx, [['student' => $student, 'label' => 'Tuition', 'kobo' => 250000]], $maker);

    test()->actingAs($approveOnly)->withSession(['school_id' => $ctx['school']->id])
        ->getJson('/api/v1/finance/opening-balance-batches/pending')
        ->assertOk()
        ->assertJsonPath('data.0.can_approve', true)
        ->assertJsonPath('data.0.can_reject', false);
});
