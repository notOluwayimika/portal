<?php

use App\Enums\TermStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DutySeparationViolationException;
use App\Finance\Actions\ApproveFeeScheduleChange;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Enums\FeeScheduleChangeStatus;
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

/** A user holding the seeded accounts_officer role (finance.fee-schedule.change.submit — the MAKER). */
function fscMaker(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'accounts_officer');
    $user->flushSchoolAccessCache();

    return $user;
}

/** A user holding the seeded head_of_school role (change.approve + change.reject — the CHECKER/ED). */
function fscChecker(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'head_of_school');
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

it('proof 27 — a submitted publish moves the target to PENDING_APPROVAL (frozen), never active; nothing becomes billable', function () {
    [$school, $term, $level] = fscContext();
    $draft = fscDraft($school, $term, $level);

    fscSubmit($this, fscMaker($school), $school, $draft)->assertCreated()->assertJsonPath('status', 'submitted');

    // The CHANGE is 'submitted'; the SCHEDULE is now pending_approval — frozen (4a) and still NOT billable.
    // PLANT: make Submit flip the target straight to active → the lookup below finds it → red.
    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::PendingApproval);
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

it('proof 29 — a failing publish leaves NOTHING moved (prior active still active, target still pending_approval, change still submitted)', function () {
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
        ->and($draft->fresh()->status)->toBe(FeeScheduleStatus::PendingApproval) // submit froze it; the failed approve rolled back, so it stays pending
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

    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::PendingApproval); // never activated; the refused approve left it pending
});

// ── Proof 30 — an active schedule's items are frozen; a draft's are free (state-scoped guard) ──

it('proof 36 (was 30) — items are free on DRAFT, and FROZEN on both PENDING_APPROVAL and ACTIVE', function () {
    [$school, $term, $level] = fscContext();
    $maker = fscMaker($school);
    $checker = fscChecker($school);
    $draft = fscDraft($school, $term, $level); // carries a 'Tuition' item

    // While DRAFT — all three item mutations succeed (on a throwaway item, so Tuition survives to be frozen).
    ActiveSchool::runFor($school->id, function () use ($draft) {
        $extra = $draft->items()->create(['school_id' => $draft->school_id, 'description' => 'Transport', 'amount' => Money::fromKobo(500000)]);
        DB::table('finance_fee_items')->where('id', $extra->id)->update(['amount_minor' => 600000]);
        DB::table('finance_fee_items')->where('id', $extra->id)->delete();
    });
    $item = FeeItem::where('fee_schedule_id', $draft->id)->firstOrFail();

    // INSERT, UPDATE and DELETE against the items are all refused by the three parent-state triggers whenever
    // the parent is not a draft. (PLANT lives in the commit-2 migration: guard only UPDATE, leave INSERT open →
    // the INSERT line reds. A migration DDL plant fights RefreshDatabase's wrapping transaction — see the PR
    // body; the draft-vs-frozen contrast here is the standing bite that needs no DDL edit.)
    $frozen = function () use ($school, $draft, $item) {
        expect(fn () => DB::table('finance_fee_items')->insert([
            'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'fee_schedule_id' => $draft->id,
            'description' => 'Sneak', 'amount_minor' => 5000000, 'amount_currency' => 'NGN',
            'is_mandatory' => 1, 'is_discountable' => 1, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('finance_fee_items')->where('id', $item->id)->update(['amount_minor' => 999]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('finance_fee_items')->where('id', $item->id)->delete())->toThrow(QueryException::class);
    };

    // Submit → PENDING_APPROVAL: 4a freezes the items HERE, before the ED approves — the whole point of the
    // commit. ADR 0050 cited proof 30 for the "items are mutable" claim it is about to lose; this is that
    // claim, AMENDED in the same place a reader looks for the original, not replaced by a new test elsewhere.
    $c = fscSubmit($this, $maker, $school, $draft)->json('id');
    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::PendingApproval);
    $frozen();

    // Approve → ACTIVE: still frozen (the original proof-30 guarantee).
    $this->actingAs($checker)->withSession(['school_id' => $school->id])->postJson("/api/v1/finance/fee-schedule-changes/{$c}/approve")->assertOk();
    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::Active);
    $frozen();
});

// ── Proof 31 / arch — who writes a fee-schedule status, and who writes ACTIVE ───────

it('proof 31 / arch — every fee-schedule status write is one of the four Actions; ACTIVE only ApproveFeeScheduleChange', function () {
    // Broadened in 4a: three Actions now write a schedule status (Create→draft, Submit→pending_approval,
    // Reject→draft, Approve→active/superseded), so a regex that saw only `active` would miss a rogue write of
    // any OTHER state — e.g. a fifth class flipping something to pending_approval or back to draft. Assert the
    // full writer set, AND keep `active` narrowed to Approve alone. This is 3b's 0a lesson (a status regex too
    // narrow to see a write) generalised while the scope is already changing.
    //
    // A WRITE is `'status' => …` (mass-assign) or `->status = …` (property), enum OR raw string. READS
    // (`!== FeeScheduleStatus::X`, `=== FeeScheduleStatus::X`, `->where('status', …->value)`) fit neither and
    // are excluded. DELIBERATELY app/-only: a seeder/factory write is invisible — seeders are not production
    // write paths, so that is the right boundary, stated so the next reader does not over-read the scan.
    $touchesSchedules = '/FeeSchedule|finance_fee_schedules/';
    $states = '(draft|pending_approval|active|superseded|retired)';
    // The case names, NOT `::\w+` — that would match the model's cast declaration `'status' => FeeScheduleStatus::class`.
    $cases = '(Draft|PendingApproval|Active|Superseded|Retired)';
    $writesAny = "/(['\"]status['\"]\\s*=>\\s*(FeeScheduleStatus::{$cases}|['\"]{$states}['\"])|->status\\s*=\\s*(FeeScheduleStatus::{$cases}|['\"]{$states}['\"]))/";
    $writesActive = "/(['\"]status['\"]\\s*=>\\s*(FeeScheduleStatus::Active|['\"]active['\"])|->status\\s*=\\s*(FeeScheduleStatus::Active|['\"]active['\"]))/";

    $files = collect(Finder::create()->files()->in(app_path())->name('*.php'))
        ->filter(fn ($f) => preg_match($touchesSchedules, $f->getContents()));
    $anyWriters = $files->filter(fn ($f) => preg_match($writesAny, $f->getContents()))->map(fn ($f) => $f->getFilename())->sort()->values()->all();
    $activeWriters = $files->filter(fn ($f) => preg_match($writesActive, $f->getContents()))->map(fn ($f) => $f->getFilename())->values()->all();

    // PLANT (any): write a FeeScheduleStatus in a FIFTH class → $anyWriters grows → red.
    // PLANT (active): restore the commit-2 flip or any raw 'active' write → $activeWriters grows → red.
    expect($anyWriters)->toBe(['ApproveFeeScheduleChange.php', 'CreateFeeSchedule.php', 'RejectFeeScheduleChange.php', 'SubmitFeeScheduleChange.php'])
        ->and($activeWriters)->toBe(['ApproveFeeScheduleChange.php']);
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

// ══ S1 4a — the pending_approval window (proofs 32–35, 37, + the pending-approval invariant) ══

// ── Proof 32 — the window is shut: submitting a publish freezes the items ────

it('proof 32 — after a publish is submitted, its items cannot be INSERTed, UPDATEd or DELETEd', function () {
    [$school, $term, $level] = fscContext();
    $draft = fscDraft($school, $term, $level);
    $item = FeeItem::where('fee_schedule_id', $draft->id)->firstOrFail();

    fscSubmit($this, fscMaker($school), $school, $draft)->assertCreated();

    // PLANT: remove the status flip in SubmitFeeScheduleChange → the schedule stays draft → all three succeed → red.
    expect(fn () => DB::table('finance_fee_items')->insert([
        'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'fee_schedule_id' => $draft->id,
        'description' => 'Sneak', 'amount_minor' => 1, 'amount_currency' => 'NGN',
        'is_mandatory' => 1, 'is_discountable' => 1, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_fee_items')->where('id', $item->id)->update(['amount_minor' => 1]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('finance_fee_items')->where('id', $item->id)->delete())->toThrow(QueryException::class);
});

// ── Proof 33 — reject re-opens the window ────────────────────────────────────

it('proof 33 — rejecting a publish returns the schedule to draft and unfreezes its items', function () {
    [$school, $term, $level] = fscContext();
    $maker = fscMaker($school);
    $checker = fscChecker($school);
    $draft = fscDraft($school, $term, $level);
    $item = FeeItem::where('fee_schedule_id', $draft->id)->firstOrFail();

    $c = fscSubmit($this, $maker, $school, $draft)->json('id');
    $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/fee-schedule-changes/{$c}/reject", ['reason' => 'redo the numbers'])->assertOk();

    // PLANT: drop the pending_approval → draft restore in RejectFeeScheduleChange → it stays pending → these red.
    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::Draft);
    ActiveSchool::runFor($school->id, function () use ($item) {
        DB::table('finance_fee_items')->where('id', $item->id)->update(['amount_minor' => 777]); // no throw
    });
    expect((int) DB::table('finance_fee_items')->where('id', $item->id)->value('amount_minor'))->toBe(777);
});

// ── Proof 34 — a submitted retire does NOT stop billing ──────────────────────

it('proof 34 — an active schedule with a submitted retire is still active and still bills', function () {
    [$school, $term, $level] = fscContext();
    $maker = fscMaker($school);
    $checker = fscChecker($school);

    // Get an active schedule.
    $draft = fscDraft($school, $term, $level);
    $pub = fscSubmit($this, $maker, $school, $draft)->json('id');
    $this->actingAs($checker)->withSession(['school_id' => $school->id])->postJson("/api/v1/finance/fee-schedule-changes/{$pub}/approve")->assertOk();
    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::Active);

    // Submit a retire — the schedule must keep billing until it is APPROVED.
    fscSubmit($this, $maker, $school, $draft->fresh(), 'retire')->assertCreated();

    // PLANT: flip status on the retire path in Submit too → the lookup returns null → red.
    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::Active);
    ActiveSchool::runFor($school->id, fn () => expect(app(FeeScheduleLookup::class)->activeFor($term->id, $level->id))?->id)->toBe($draft->id);
});

// ── Proof 35 — the §3 dependency: pending_unique blocks a second open schedule for the slot ──

it('proof 35 — while a publish is pending for a slot, a second draft for that slot is refused', function () {
    [$school, $term, $level] = fscContext();
    $draft = fscDraft($school, $term, $level);
    fscSubmit($this, fscMaker($school), $school, $draft)->assertCreated(); // draft → pending_approval

    // A second draft for the SAME (school, term, class level) now collides on finance_fee_schedules_pending_unique
    // (which covers draft AND pending_approval). Without the widened index, the pending schedule frees its slot
    // and this would insert — re-opening the exact two-open-requests gap 4a closes.
    // PLANT (migration DDL): revert the generated columns to IF(status = 'draft', …) → this second draft inserts → red.
    expect(fn () => fscDraft($school, $term, $level, 'second'))->toThrow(BusinessRuleException::class);
});

// ── Proof 37 — a target reverted to draft cannot be approved ─────────────────

it('proof 37 — a schedule manually reverted to draft after submit cannot be approved', function () {
    [$school, $term, $level] = fscContext();
    $maker = fscMaker($school);
    $checker = fscChecker($school);
    $draft = fscDraft($school, $term, $level);
    $c = fscSubmit($this, $maker, $school, $draft)->json('id');

    // Force the target back to draft behind the change's back (a raw write, the kind the DB guards exist for).
    DB::table('finance_fee_schedules')->where('id', $draft->id)->update(['status' => 'draft']);

    // PLANT: leave ApproveFeeScheduleChange::publish comparing against Draft → this approves → red.
    $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/fee-schedule-changes/{$c}/approve")->assertStatus(422);
    expect($draft->fresh()->status)->toBe(FeeScheduleStatus::Draft); // never activated
});

// ── Invariant (test-level, NOT a DB rule) — pending_approval ⇒ exactly one submitted change ──

it('invariant — no schedule sits in pending_approval without exactly one submitted change targeting it', function () {
    // A cross-table property, so it is NOT expressible as a CHECK and is NOT enforced anywhere — it is
    // asserted here over a built fixture. Do not mistake this for a database guarantee; it is a consistency
    // check on the Actions, which is the only thing that ever moves a schedule into pending_approval.
    [$school, $term, $level] = fscContext();
    $maker = fscMaker($school);

    $a = fscDraft($school, $term, $level, 'A');
    fscSubmit($this, $maker, $school, $a); // A → pending_approval

    ActiveSchool::runFor($school->id, function () {
        FeeSchedule::where('status', FeeScheduleStatus::PendingApproval->value)->get()->each(function ($schedule) {
            $open = FeeScheduleChange::where('target_schedule_id', $schedule->id)
                ->where('status', FeeScheduleChangeStatus::Submitted->value)->count();
            expect($open)->toBe(1);
        });
    });
});
