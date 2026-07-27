<?php

use App\Enums\TermStatusEnum;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Models\FeeItem;
use App\Finance\Models\FeeSchedule;
use App\Finance\Services\FeeScheduleLookup;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S1 commit 2 — the fee-schedule catalog. Proofs 19, 22–26 (per Part 6/7). The lifecycle uniqueness
 * (24, 25) is a property of the generated-column indexes and is exercised with RAW status writes — a
 * test of the index, not of any Action. The read path (26) is exercised against FeeScheduleLookup's
 * single status filter. Plants data, not schema (RefreshDatabase is safe).
 */
uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array{0: School, 1: Term, 2: ClassLevel, 3: AcademicSession} */
function fsContext(): array
{
    $school = School::factory()->create();
    $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'slug' => 'sess-'.Str::random(8), 'is_current' => true]);
    $term = Term::create([
        'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
        'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
    ]);
    $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);

    return [$school, $term, $level, $session];
}

/** A user holding the seeded `admin` role in $school (finance.fee-schedule.manage + academic_setup.manage). */
function fsAdmin(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'admin');
    $user->flushSchoolAccessCache();

    return $user;
}

/** Raw-insert a schedule row with a given status — a test of the index, bypassing the Action. */
function fsRawSchedule(School $school, Term $term, ClassLevel $level, string $status): int
{
    return DB::table('finance_fee_schedules')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id, 'term_id' => $term->id, 'class_level_id' => $level->id,
        'label' => 'L', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ── Proof 24 — one active per (school, term, class level); supersession frees the slot ──

it('proof 24 — at most one ACTIVE schedule per slot; supersession lets a re-price insert', function () {
    [$school, $term, $level] = fsContext();

    $first = fsRawSchedule($school, $term, $level, 'active');
    // A second active for the same (school, term, class level) collides on finance_fee_schedules_active_unique.
    expect(fn () => fsRawSchedule($school, $term, $level, 'active'))->toThrow(QueryException::class);

    // Supersede the first — the state-scoped index now exempts it, so the re-price inserts. A PLAIN
    // composite unique would forbid this forever (that is the plant: swap the generated index for a
    // plain unique(school_id, term_id, class_level_id) and this half goes red).
    DB::table('finance_fee_schedules')->where('id', $first)->update(['status' => 'superseded']);
    expect(fsRawSchedule($school, $term, $level, 'active'))->toBeInt();
});

// ── Proof 24b — the uniqueness is STATE-SCOPED (index-shape guard; stands forever) ──
//
// Better than a one-off plant: this asserts the SHAPE of the two unique indexes directly from
// information_schema. Anyone who swaps in a plain composite unique(school_id, term_id, class_level_id)
// — or drops the generated columns — goes red in CI, which is the regression the plant would only ever
// have demonstrated once. (A migration ALTER inside a RefreshDatabase test causes an implicit COMMIT
// that destroys the wrapping transaction, so the plant fights the harness, not the index — see the PR
// body for the one-off manual confirmation.)

it('proof 24b — the lifecycle uniqueness indexes are state-scoped (school_id + the status-gated keys)', function () {
    $shape = fn (string $index): array => collect(DB::select(
        'SELECT COLUMN_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
         ORDER BY SEQ_IN_INDEX',
        ['finance_fee_schedules', $index],
    ))->pluck('COLUMN_NAME')->all();

    expect($shape('finance_fee_schedules_active_unique'))->toBe(['school_id', 'active_term_key', 'active_class_level_key'])
        ->and($shape('finance_fee_schedules_draft_unique'))->toBe(['school_id', 'draft_term_key', 'draft_class_level_key']);
});

// ── Proof 24c — a SECOND publish supersedes the first and links back (the REAL Action) ──
//
// This is proof 29b's substance, exercised in commit 2 against CreateFeeSchedule (in commit 4 it moves
// with steps 3+4 into ApproveFeeScheduleChange and becomes 29b). Watched red three ways, each failing
// differently: (a) activate-before-supersede → the SECOND publish reds on active_unique (the
// first-run-passes bug); (b) drop the supersedes_schedule_id assignment → only the LINK assertion reds
// (the commit-4 trap: a minimal "delete step 4" drops the link while every status stays green); (c)
// drop the supersede step → the second publish reds on the unique index.

it('proof 24c — a SECOND publish supersedes the first and links back', function () {
    [$school, $term, $level] = fsContext();

    ActiveSchool::runFor($school->id, function () use ($term, $level) {
        $first = app(CreateFeeSchedule::class)->handle($term->id, $level->id, 'v1',
            [['description' => 'Tuition', 'amount_minor' => 10000000]]);
        $second = app(CreateFeeSchedule::class)->handle($term->id, $level->id, 'v2',
            [['description' => 'Tuition', 'amount_minor' => 12000000]]);

        expect($first->fresh()->status->value)->toBe('superseded')
            ->and($second->fresh()->status->value)->toBe('active')
            ->and($second->fresh()->supersedes_schedule_id)->toBe($first->id);
    });
});

// ── Proof 25 — a draft and an active coexist; two drafts do not ──

it('proof 25 — a draft and an active schedule coexist for one slot; two drafts do not', function () {
    [$school, $term, $level] = fsContext();

    fsRawSchedule($school, $term, $level, 'active');
    expect(fsRawSchedule($school, $term, $level, 'draft'))->toBeInt();          // coexist — the point of a draft
    expect(fn () => fsRawSchedule($school, $term, $level, 'draft'))             // but only one draft
        ->toThrow(QueryException::class);
});

// ── Proof 26 — the lookup returns an active schedule, never a draft ──

it('proof 26 — the schedule lookup returns an ACTIVE schedule but NEVER a draft (a draft is not a price)', function () {
    [$school, $term, $level] = fsContext();

    ActiveSchool::runFor($school->id, function () use ($school, $term, $level) {
        fsRawSchedule($school, $term, $level, 'draft');
        // A draft-only slot must look exactly like no schedule at all.
        expect(app(FeeScheduleLookup::class)->activeFor($term->id, $level->id))->toBeNull();

        // Publish an active one — now the lookup finds it. PLANT: remove ->where('status','active')
        // from FeeScheduleLookup and the draft-only assertion above goes red.
        fsRawSchedule($school, $term, $level, 'active');
        $found = app(FeeScheduleLookup::class)->activeFor($term->id, $level->id);
        expect($found)->not->toBeNull()
            ->and($found->status->value)->toBe('active');
    });
});

// ── Proof 19 — School isolation (commit-2 tables only), super_admin included ──

it('proof 19 — fee schedules and items are School-scoped; a School A context sees none of School B', function () {
    [$schoolA, $termA, $levelA] = fsContext();
    [$schoolB, $termB, $levelB] = fsContext();

    $a = ActiveSchool::runFor($schoolA->id, fn () => app(CreateFeeSchedule::class)
        ->handle($termA->id, $levelA->id, 'A', [['description' => 'Tuition', 'amount_minor' => 100000]]));
    $b = ActiveSchool::runFor($schoolB->id, fn () => app(CreateFeeSchedule::class)
        ->handle($termB->id, $levelB->id, 'B', [['description' => 'Tuition', 'amount_minor' => 100000]]));

    // SchoolScope is context-based, not role-based, so it isolates a super_admin exactly as anyone else
    // (ADR 0036: super_admin bypasses authorization, never isolation).
    ActiveSchool::runFor($schoolA->id, function () use ($a) {
        expect(FeeSchedule::pluck('id')->all())->toBe([$a->id])
            ->and(FeeItem::pluck('fee_schedule_id')->unique()->values()->all())->toBe([$a->id]);
    });
    ActiveSchool::runFor($schoolB->id, function () use ($b) {
        expect(FeeSchedule::pluck('id')->all())->toBe([$b->id]);
    });
});

// ── Proof 22 — deleting a priced term is refused with a friendly message, not a 500 ──

it('proof 22 — deleting a term that has a fee schedule is refused (422), not a 500', function () {
    [$school, $term, $level, $session] = fsContext();
    fsRawSchedule($school, $term, $level, 'active'); // the RESTRICT FK now protects the term
    $admin = fsAdmin($school);

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->deleteJson("/api/sessions/{$session->uuid}/terms/{$term->uuid}")
        ->assertStatus(422);

    expect(Term::find($term->id))->not->toBeNull(); // survived
});

// ── Proof 23 — the CASCADE→RESTRICT path: deleting a session whose term is priced is refused ──

it('proof 23 — deleting an academic session whose term has a fee schedule is refused (CASCADE into RESTRICT)', function () {
    [$school, $term, $level, $session] = fsContext();
    fsRawSchedule($school, $term, $level, 'active');

    // academic_sessions ← terms is CASCADE; terms ← finance_fee_schedules is RESTRICT — so the cascade
    // delete of the term is refused and the whole session delete fails at the database.
    expect(fn () => AcademicSession::query()->whereKey($session->id)->firstOrFail()->delete())
        ->toThrow(QueryException::class);
    expect(AcademicSession::find($session->id))->not->toBeNull()
        ->and(Term::find($term->id))->not->toBeNull();
});

// ── Smoke — the direct-publish store + prefill round-trip ──

it('SMOKE — store publishes an active schedule with items; prefill returns them as charge lines', function () {
    [$school, $term, $level] = fsContext();
    $admin = fsAdmin($school);

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/fee-schedules', [
            'term_id' => $term->id, 'class_level_id' => $level->id, 'label' => 'JSS1 T1',
            'items' => [
                ['description' => 'Tuition', 'amount_minor' => 10000000, 'is_discountable' => true],
                ['description' => 'Transport', 'amount_minor' => 2000000, 'is_discountable' => false],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'active')
        ->assertJsonCount(2, 'items');

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/fee-schedules/prefill?term_id={$term->id}&class_level_id={$level->id}")
        ->assertOk()
        ->assertJsonCount(2, 'lines')
        ->assertJsonPath('lines.0.kind', 'charge');
});
