<?php

use App\Enums\TermStatusEnum;
use App\Exceptions\DutySeparationViolationException;
use App\Finance\Actions\ApproveFeeScheduleChange;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Models\FeeItem;
use App\Finance\Models\FeeSchedule;
use App\Finance\Models\FeeScheduleChange;
use App\Finance\Services\FeeScheduleLookup;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\DutySeparation;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Finder\Finder;

/**
 * S1 commit 4 — fee-schedule GOVERNANCE. A schedule reaches `active` ONLY when the ED approves a publish
 * change; the commit-2 direct-publish flip is gone (proof 31). Proofs 27, 28, 29, 29b, 29c, 30, 31, plus
 * 19 (this commit's table only), the §4.2 convention trio, and the single-writer arch test. maker ≠ checker
 * is proven at both the Policy (403) and DB CHECK layers. Mirrors DiscountPolicyTest one generation on.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array{0: School, 1: Term, 2: ClassLevel} */
function fscContext(): array
{
    $school = School::factory()->create();
    $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'slug' => 'sess-'.Str::random(8), 'is_current' => true]);
    $term = Term::create([
        'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
        'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
    ]);
    $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);

    return [$school, $term, $level];
}

/** A user holding the seeded head_of_school role (finance.fee-schedule.change.submit — the MAKER). */
function fscMaker(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'head_of_school');
    $user->flushSchoolAccessCache();

    return $user;
}

/** A user holding the seeded principal role (change.approve + change.reject — the CHECKER/ED). */
function fscChecker(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'principal');
    $user->flushSchoolAccessCache();

    return $user;
}

/**
 * A user holding BOTH change.submit and change.approve via a RAW model_has_roles insert — grant-time SoD
 * refuses this through the spatie API (it is a Finance pair), so the only way to construct a both-sides
 * user is outside it. Used to prove the RECORD-level Policy refusal in isolation.
 */
function fscBothHolder(School $school): User
{
    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'fs_both', 'guard_name' => 'web']);
    foreach (['finance.access', 'finance.fee-schedule.change.submit', 'finance.fee-schedule.change.approve'] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $role->syncPermissions(['finance.access', 'finance.fee-schedule.change.submit', 'finance.fee-schedule.change.approve']);
    setPermissionsTeamId(null);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->schools()->syncWithoutDetaching([$school->id]);
    DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => User::class, 'model_id' => $user->id, 'school_id' => $school->id]);
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** Author a DRAFT schedule (bypasses HTTP permission — draft authorship is finance.fee-schedule.manage, not under test here). */
function fscDraft(School $school, Term $term, ClassLevel $level, string $label = 'v1', int $amount = 10000000): FeeSchedule
{
    return ActiveSchool::runFor($school->id, fn () => app(CreateFeeSchedule::class)
        ->handle($term->id, $level->id, $label, [['description' => 'Tuition', 'amount_minor' => $amount]]));
}

/** POST a submit as $user; returns the TestResponse. */
function fscSubmit($test, User $user, School $school, FeeSchedule $target, string $kind = 'publish')
{
    return $test->actingAs($user)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/fee-schedule-changes', ['kind' => $kind, 'target' => $target->uuid, 'reason' => 'because']);
}

// ── Proof 27 — a submitted change does nothing ──────────────────────────────

it('proof 27 — a submitted publish leaves the target a DRAFT; nothing becomes billable', function () {
    [$school, $term, $level] = fscContext();
    $draft = fscDraft($school, $term, $level);

    fscSubmit($this, fscMaker($school), $school, $draft)->assertCreated()->assertJsonPath('status', 'submitted');

    // PLANT: move the status flip into Submit… → the target is active here → red.
    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::Draft);
    ActiveSchool::runFor($school->id, fn () => expect(app(FeeScheduleLookup::class)->activeFor($term->id, $level->id))->toBeNull());
});

// ── Proof 28 — the submitter cannot approve their own change (both layers) ───

it('proof 28 (Policy) — the submitter is refused approval of their own change (403)', function () {
    [$school, $term, $level] = fscContext();
    $both = fscBothHolder($school);
    $draft = fscDraft($school, $term, $level);

    $change = fscSubmit($this, $both, $school, $draft)->assertCreated()->json('id');
    // Holds change.approve (route passes) but IS the maker → FeeScheduleChangePolicy denies → 403.
    $this->actingAs($both)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/fee-schedule-changes/{$change}/approve")
        ->assertForbidden();
});

it('proof 28 (DB CHECK) — a raw write setting decided_by = submitted_by is refused by the CHECK', function () {
    [$school, $term, $level] = fscContext();
    $draft = fscDraft($school, $term, $level);
    $change = FeeScheduleChange::where('uuid', fscSubmit($this, fscMaker($school), $school, $draft)->json('id'))->firstOrFail();

    expect(fn () => DB::table('finance_fee_schedule_changes')->where('id', $change->id)
        ->update(['status' => 'approved', 'decided_by' => $change->submitted_by, 'decided_at' => now()]))
        ->toThrow(QueryException::class);
});

// ── Proof 29 — approval is atomic and supersedes; zero-active invariant scoped to publish ──

it('proof 29 — a failing publish leaves NOTHING moved (prior active still active, draft still draft, change still submitted)', function () {
    [$school, $term, $level] = fscContext();
    $active = fscDraft($school, $term, $level, 'active-one');
    // Publish + approve the first so an ACTIVE exists to supersede.
    $maker = fscMaker($school);
    $checker = fscChecker($school);
    $c1 = fscSubmit($this, $maker, $school, $active)->json('id');
    $this->actingAs($checker)->withSession(['school_id' => $school->id])->postJson("/api/v1/finance/fee-schedule-changes/{$c1}/approve")->assertOk();

    $draft = fscDraft($school, $term, $level, 'the-new-draft');   // coexists with the active
    $change = FeeScheduleChange::where('uuid', fscSubmit($this, $maker, $school, $draft)->json('id'))->firstOrFail();

    // Inject a mid-transaction failure AT THE ACTIVATE step (after the supersede has already written) by
    // swapping in a bare event dispatcher that throws when a schedule is moved TO active. The supersede
    // (→superseded) is untouched; the draft-activate (→active) throws → the whole transaction rolls back.
    $original = FeeSchedule::getEventDispatcher();
    $probe = new Dispatcher;
    $probe->listen('eloquent.updating: '.FeeSchedule::class, function (FeeSchedule $s) {
        if ($s->status === FeeScheduleStatus::Active) {
            throw new RuntimeException('injected: fail the activate after the supersede');
        }
    });
    FeeSchedule::setEventDispatcher($probe);

    try {
        expect(fn () => ActiveSchool::runFor($school->id, fn () => app(ApproveFeeScheduleChange::class)->handle($change->fresh(), $checker)))
            ->toThrow(RuntimeException::class);
    } finally {
        FeeSchedule::setEventDispatcher($original);
    }

    // Nothing moved — and the slot still has exactly ONE active (the publish-scoped zero-active invariant:
    // a publish that fails must not silently drop the slot to zero active).
    expect($active->fresh()->status)->toBe(FeeScheduleStatus::Active)
        ->and($draft->fresh()->status)->toBe(FeeScheduleStatus::Draft)
        ->and($change->fresh()->status->value)->toBe('submitted');
    ActiveSchool::runFor($school->id, fn () => expect(
        FeeSchedule::where('term_id', $term->id)->where('class_level_id', $level->id)->where('status', 'active')->count()
    )->toBe(1));
});

it('proof 29 (retire) — approving a retire leaves the slot with ZERO active, on purpose', function () {
    [$school, $term, $level] = fscContext();
    $maker = fscMaker($school);
    $checker = fscChecker($school);

    $draft = fscDraft($school, $term, $level);
    $pub = fscSubmit($this, $maker, $school, $draft)->json('id');
    $this->actingAs($checker)->withSession(['school_id' => $school->id])->postJson("/api/v1/finance/fee-schedule-changes/{$pub}/approve")->assertOk();
    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::Active);

    // Retire it — zero active is the INTENDED outcome (asserting "never zero" flatly would fail this).
    $ret = fscSubmit($this, $maker, $school, $draft->fresh(), 'retire')->assertCreated()->json('id');
    $this->actingAs($checker)->withSession(['school_id' => $school->id])->postJson("/api/v1/finance/fee-schedule-changes/{$ret}/approve")->assertOk();

    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::Retired);
    ActiveSchool::runFor($school->id, fn () => expect(
        FeeSchedule::where('term_id', $term->id)->where('class_level_id', $level->id)->where('status', 'active')->count()
    )->toBe(0));
});

// ── Proof 29b — the publish path is order-dependent; the wrong order passes on run one, fails on run two ──

it('proof 29b — publishing TWICE against one slot: supersede-before-activate; run one green, run two would red on the wrong order', function () {
    [$school, $term, $level] = fscContext();
    $maker = fscMaker($school);
    $checker = fscChecker($school);
    $approve = fn (string $uuid) => $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/fee-schedule-changes/{$uuid}/approve");

    // Run 1 — no prior active; supersede finds nothing, so activate-before-supersede would ALSO pass here.
    $draft1 = fscDraft($school, $term, $level, 'v1');
    $approve(fscSubmit($this, $maker, $school, $draft1)->json('id'))->assertOk();
    expect($draft1->fresh()->status)->toBe(FeeScheduleStatus::Active);

    // Run 2 — a second draft over the first. The activate now COLLIDES with draft1's active row unless the
    // supersede runs first. PLANT: write activate before supersede in ApproveFeeScheduleChange → this run reds
    // on finance_fee_schedules_active_unique while run 1 stays green.
    $draft2 = fscDraft($school, $term, $level, 'v2', 12000000); // draft coexists with the active
    $approve(fscSubmit($this, $maker, $school, $draft2)->json('id'))->assertOk();

    expect($draft1->fresh()->status)->toBe(FeeScheduleStatus::Superseded)
        ->and($draft2->fresh()->status)->toBe(FeeScheduleStatus::Active)
        ->and($draft2->fresh()->supersedes_schedule_id)->toBe($draft1->id);
    ActiveSchool::runFor($school->id, fn () => expect(
        FeeSchedule::where('term_id', $term->id)->where('class_level_id', $level->id)->where('status', 'active')->count()
    )->toBe(1));
});

// ── Proof 29c — an approved schedule with zero items is refused ──────────────

it('proof 29c — approving a publish of an EMPTY draft is refused (an empty schedule bills nothing)', function () {
    [$school, $term, $level] = fscContext();
    $maker = fscMaker($school);
    $checker = fscChecker($school);

    // A draft with NO items (raw insert — CreateFeeSchedule refuses empties up front, so an empty draft can
    // only be constructed outside it, mirroring a draft stripped of its items after authoring).
    $draftId = DB::table('finance_fee_schedules')->insertGetId([
        'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'term_id' => $term->id,
        'class_level_id' => $level->id, 'label' => 'empty', 'status' => 'draft',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $draft = FeeSchedule::findOrFail($draftId);

    $change = fscSubmit($this, $maker, $school, $draft)->assertCreated()->json('id');
    // PLANT: remove the zero-items guard from ApproveFeeScheduleChange → this approval succeeds → red.
    $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/fee-schedule-changes/{$change}/approve")->assertStatus(422);

    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::Draft); // never activated
});

// ── Proof 30 — an active schedule's items are frozen; a draft's are free (state-scoped guard) ──

it('proof 30 — items are freely editable on a DRAFT and frozen on an ACTIVE schedule (INSERT, UPDATE, DELETE)', function () {
    [$school, $term, $level] = fscContext();
    $maker = fscMaker($school);
    $checker = fscChecker($school);
    $draft = fscDraft($school, $term, $level);

    // While DRAFT — all three item mutations succeed.
    ActiveSchool::runFor($school->id, function () use ($draft) {
        $extra = $draft->items()->create(['school_id' => $draft->school_id, 'description' => 'Transport', 'amount' => Money::fromKobo(500000)]);
        DB::table('finance_fee_items')->where('id', $extra->id)->update(['amount_minor' => 600000]);
        DB::table('finance_fee_items')->where('id', $extra->id)->delete();
    });

    // Publish it.
    $c = fscSubmit($this, $maker, $school, $draft)->json('id');
    $this->actingAs($checker)->withSession(['school_id' => $school->id])->postJson("/api/v1/finance/fee-schedule-changes/{$c}/approve")->assertOk();
    $item = FeeItem::where('fee_schedule_id', $draft->id)->firstOrFail();

    // Now ACTIVE — INSERT, UPDATE and DELETE against its items are all refused by the parent-state trigger.
    // (PLANT lives in the commit-2 migration: guard only UPDATE, leave INSERT open → the INSERT line reds.
    // A migration DDL plant fights RefreshDatabase's wrapping transaction — see the PR body; the draft/active
    // contrast above is the standing bite that needs no DDL edit.)
    expect(fn () => DB::table('finance_fee_items')->insert([
        'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'fee_schedule_id' => $draft->id,
        'description' => 'Sneak', 'amount_minor' => 5000000, 'amount_currency' => 'NGN',
        'is_mandatory' => 1, 'is_discountable' => 1, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_fee_items')->where('id', $item->id)->update(['amount_minor' => 999]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_fee_items')->where('id', $item->id)->delete())->toThrow(QueryException::class);
});

// ── Proof 31 / arch — a schedule reaches `active` in exactly one place ───────

it('proof 31 / arch — finance_fee_schedules is set ACTIVE ONLY by ApproveFeeScheduleChange', function () {
    // Match only the two WRITE idioms — a mass-assign array `'status' => FeeScheduleStatus::Active` and a
    // property assignment `->status = FeeScheduleStatus::Active`. READS (`!== FeeScheduleStatus::Active` in
    // SubmitFeeScheduleChange's retire guard, `FeeScheduleStatus::Active->value` in FeeScheduleLookup's where)
    // are deliberately excluded, so the set is writers of `active` and nothing else.
    $writesActive = "/(['\"]status['\"]\\s*=>\\s*FeeScheduleStatus::Active|->status\\s*=\\s*FeeScheduleStatus::Active)/";
    $writers = collect(Finder::create()->files()->in(app_path())->name('*.php'))
        ->filter(fn ($f) => preg_match($writesActive, $f->getContents()))
        ->map(fn ($f) => $f->getFilename())
        ->values()->all();

    // PLANT: restore the commit-2 draft→active flip in CreateFeeSchedule → CreateFeeSchedule.php joins this set → red.
    expect($writers)->toBe(['ApproveFeeScheduleChange.php']);
});

// ── Proof 19 — School isolation (this commit's table only), super_admin included ──

it('proof 19 — fee-schedule changes are School-scoped; a School A context sees none of School B', function () {
    [$schoolA, $termA, $levelA] = fscContext();
    [$schoolB, $termB, $levelB] = fscContext();
    $draftA = fscDraft($schoolA, $termA, $levelA);
    $draftB = fscDraft($schoolB, $termB, $levelB);
    fscSubmit($this, fscMaker($schoolA), $schoolA, $draftA);
    fscSubmit($this, fscMaker($schoolB), $schoolB, $draftB);

    // SchoolScope is context-based, not role-based, so it isolates a super_admin exactly as anyone else
    // (ADR 0036: super_admin bypasses authorization, never isolation).
    ActiveSchool::runFor($schoolA->id, fn () => expect(FeeScheduleChange::pluck('school_id')->unique()->values()->all())->toBe([$schoolA->id]));
    ActiveSchool::runFor($schoolB->id, fn () => expect(FeeScheduleChange::pluck('school_id')->unique()->values()->all())->toBe([$schoolB->id]));
});

// ── Convention (§4.2): the permission names wire into the shared machinery ──

it('convention (a) — DutySeparation::pairs() derives the new fee-schedule change.submit/approve + submit/reject pairs', function () {
    $checkers = collect(DutySeparation::pairs())->pluck('checker')->all();
    expect($checkers)->toContain('finance.fee-schedule.change.approve')
        ->and($checkers)->toContain('finance.fee-schedule.change.reject');
    expect(collect(DutySeparation::pairs())->firstWhere('checker', 'finance.fee-schedule.change.approve')['maker'])
        ->toBe('finance.fee-schedule.change.submit');
});

it('convention (b) — grant-time SoD refuses a role granted both sides of the fee-schedule change pair', function () {
    $school = School::factory()->create();
    $user = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'fs_both_sides', 'guard_name' => 'web'])
        ->syncPermissions(['finance.fee-schedule.change.submit', 'finance.fee-schedule.change.approve']);

    expect(fn () => $user->assignRole('fs_both_sides'))
        ->toThrow(DutySeparationViolationException::class);
    setPermissionsTeamId(null);
});

it('convention (c) — a user holding only fee-schedule.change.approve reaches the /finance/approvals page', function () {
    $school = School::factory()->create();
    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'fs_approver_only', 'guard_name' => 'web']);
    foreach (['finance.access', 'finance.fee-schedule.change.approve'] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $role->syncPermissions(['finance.access', 'finance.fee-schedule.change.approve']);
    setPermissionsTeamId(null);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'fs_approver_only');
    $user->flushSchoolAccessCache();

    $this->actingAs($user)->withSession(['school_id' => $school->id])->get('/finance/approvals')->assertOk();
});
