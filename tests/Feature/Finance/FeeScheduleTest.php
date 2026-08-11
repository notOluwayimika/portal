<?php

use App\Enums\TermStatusEnum;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Actions\SubmitFeeScheduleChange;
use App\Finance\Enums\FeeScheduleChangeKind;
use App\Finance\Models\FeeItem;
use App\Finance\Models\FeeSchedule;
use App\Finance\Services\FeeScheduleLookup;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

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

    // S1 4a widened the draft key to cover pending_approval too, renaming it _pending_unique on
    // pending_{term,class_level}_key — a draft OR a schedule awaiting approval occupies the slot.
    expect($shape('finance_fee_schedules_active_unique'))->toBe(['school_id', 'active_term_key', 'active_class_level_key'])
        ->and($shape('finance_fee_schedules_pending_unique'))->toBe(['school_id', 'pending_term_key', 'pending_class_level_key']);
});

// Proof 24c (the SECOND-publish supersession, exercised in commit 2 against CreateFeeSchedule) MOVED in
// commit 4: with the direct-publish flip removed, CreateFeeSchedule now authors a DRAFT and never
// supersedes/activates. Its substance — supersede-before-activate, the link-back, and the twice-published
// slot — is now proof 29b in FeeScheduleChangeTest, exercised against ApproveFeeScheduleChange (the only
// code that may set `active`). Removed here rather than left asserting behaviour this Action no longer has.

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
        ->handle($termA->id, $levelA->id, 'A', [['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000]]));
    $b = ActiveSchool::runFor($schoolB->id, fn () => app(CreateFeeSchedule::class)
        ->handle($termB->id, $levelB->id, 'B', [['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 100000]]));

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

// ── Smoke — commit 4: store authors a DRAFT (not active); a draft does not prefill ──

it('SMOKE — store authors a DRAFT with items; the draft does NOT prefill (a draft is not a price)', function () {
    [$school, $term, $level] = fsContext();
    $admin = fsAdmin($school);

    // Commit 4 removed the direct-publish flip: store now creates a DRAFT (publishing is an approved change).
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/fee-schedules', [
            'term_id' => $term->id, 'class_level_id' => $level->id, 'label' => 'JSS1 T1',
            'items' => [
                ['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 10000000, 'is_discountable' => true],
                ['bank_account_id' => testBankAccountUuid(), 'description' => 'Transport', 'amount_minor' => 2000000, 'is_discountable' => false],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonCount(2, 'items');

    // No active schedule exists yet, so prefill returns nothing — the draft is not billable until the ED approves.
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/fee-schedules/prefill?term_id={$term->id}&class_level_id={$level->id}")
        ->assertOk()
        ->assertJsonPath('schedule', null)
        ->assertJsonCount(0, 'lines');
});

// ── U1 commit 1 — the data surface an author-and-edit page needs ──

/**
 * A web-session user holding EXACTLY $permissions, through a role named after them.
 *
 * Permission-keyed rather than role-keyed on purpose, and the same shape OpeningBalanceOperatorScreenTest
 * uses: the three screens in the label arm below are gated on three abilities that no single seeded role
 * holds together, and a role-keyed actor would move underneath the test the next time the grants map
 * changes.
 *
 * @param  list<string>  $permissions
 */
function fsUser(School $school, array $permissions): User
{
    $roleName = 'fs_'.substr(md5(implode(',', $permissions)), 0, 10);
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

/** A DRAFT authored through the real Action, in $school's context. */
function fsDraft(School $school, Term $term, ClassLevel $level, string $label, string $accountUuid): FeeSchedule
{
    return ActiveSchool::runFor($school->id, fn () => app(CreateFeeSchedule::class)->handle(
        $term->id, $level->id, $label,
        [['description' => 'Tuition', 'amount_minor' => 100000, 'bank_account_id' => $accountUuid]],
    ));
}

it('index serialises each item’s destination account as a uuid, and names the term and class level for a human', function () {
    /*
     * WATCHED RED, twice. Removing `bank_account_id` from FeeScheduleResource's item map fails with
     * "Failed asserting that null is identical to '<the account uuid>'"; removing `term.academicSession`
     * and `classLevel` from index()'s eager loads fails the next assertion with "Failed asserting that
     * null is identical to '2026/2027 — First Term'", because whenLoaded emits nothing for a relation
     * nobody loaded and assertJsonPath reads an absent path as null.
     *
     * WHY THE UUID AND NOT THE INTEGER id: the uuid is the wire form everywhere else — the exists rule on
     * items.*.bank_account_id keys on uuid and EditFeeScheduleDraft resolves uuid → id. An integer here
     * would be a field the edit request cannot accept back.
     */
    [$school, $term, $level, $session] = fsContext();
    $accountUuid = testBankAccountUuid($school->id);
    fsDraft($school, $term, $level, 'JSS1 T1', $accountUuid);

    $this->actingAs(fsAdmin($school))->withSession(['school_id' => $school->id])
        ->getJson('/api/v1/finance/fee-schedules')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.items.0.bank_account_id', $accountUuid)
        // The SAME string routes/web.php:216 builds for the opening-balance term select. Asserted against
        // the seeded names rather than a literal, so a fixture rename cannot make this pass vacuously.
        ->assertJsonPath('0.term_label', trim($session->name.' — '.$term->name))
        ->assertJsonPath('0.class_level_label', $level->name);
});

it('prefill’s payload keeps its shape — the two labels are ABSENT there, not present-and-null', function () {
    /*
     * prefill is the BILLING read path and builds this resource with only `items` loaded. Both labels go
     * through whenLoaded precisely so they do not lazy-load a term and a session here — and the absence
     * has to be an ABSENCE: `term_label: null` would be a claim that the schedule has no term.
     *
     * WATCHED RED by making either label an unconditional accessor: the key-list assertion below fails
     * with the label present in `actual`.
     */
    [$school, $term, $level] = fsContext();
    $accountUuid = testBankAccountUuid($school->id);
    $draft = fsDraft($school, $term, $level, 'JSS1 T1', $accountUuid);
    DB::table('finance_fee_schedules')->where('id', $draft->id)->update(['status' => 'active']);

    $response = $this->actingAs(fsAdmin($school))->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/fee-schedules/prefill?term_id={$term->id}&class_level_id={$level->id}")
        ->assertOk()
        // The destination DOES travel here — that half is intended everywhere the resource is rendered.
        ->assertJsonPath('schedule.items.0.bank_account_id', $accountUuid);

    expect(array_keys((array) $response->json()))->toBe(['schedule', 'lines'])
        ->and(array_keys((array) $response->json('schedule')))
        ->toBe(['id', 'term_id', 'class_level_id', 'label', 'status', 'items'],
            'prefill grew or lost a top-level key on the schedule. term_label/class_level_label must be '
            .'ABSENT on this path, not present-and-null, and nothing else about this payload moves.');
});

it('index filters by term and by status, and REFUSES a term_id belonging to another School', function () {
    /*
     * WATCHED RED on the isolation half: removing `->where('school_id', ActiveSchool::id())` from the
     * term_id rule turns the last assertion's 422 into a 200.
     *
     * WHAT THAT 200 DISCLOSES IS NOT THE ROWS. `FeeSchedule` uses BelongsToSchool, so the body is `[]`
     * whether the other School's term has zero schedules or fifty — nothing about that School travels
     * either way. What travels is the STATUS CODE: unscoped, 422 means "this term id exists nowhere on
     * the platform" and 200 means "it exists in some school", which turns the endpoint into a term-id
     * existence oracle across every school on the installation. Scoped, both are 422.
     *
     * So the rule is the control, and the control is about the code, not the collection.
     */
    [$schoolA, $termOne, $level, $session] = fsContext();
    [$schoolB, $termB] = fsContext();

    // A second term in the SAME school and session — the filter has to discriminate between two of the
    // caller's own terms, not merely between a term and nothing.
    $termTwo = Term::create([
        'academic_session_id' => $session->id, 'school_id' => $schoolA->id, 'name' => 'Second Term',
        'slug' => 'term-'.Str::random(8), 'order' => 2, 'start_date' => now()->addMonths(3),
        'end_date' => now()->addMonths(5), 'status' => TermStatusEnum::UPCOMING->value,
    ]);

    $accountUuid = testBankAccountUuid($schoolA->id);
    $onTermOne = fsDraft($schoolA, $termOne, $level, 'T1', $accountUuid);
    $onTermTwo = fsDraft($schoolA, $termTwo, $level, 'T2', $accountUuid);
    DB::table('finance_fee_schedules')->where('id', $onTermTwo->id)->update(['status' => 'active']);

    $as = fn () => $this->actingAs(fsAdmin($schoolA))->withSession(['school_id' => $schoolA->id]);

    // ABSENT MEANS UNFILTERED — the pre-U1 behaviour, unchanged.
    $as()->getJson('/api/v1/finance/fee-schedules')->assertOk()->assertJsonCount(2);

    $as()->getJson('/api/v1/finance/fee-schedules?term_id='.$termOne->id)
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $onTermOne->uuid);

    $as()->getJson('/api/v1/finance/fee-schedules?status=active')
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $onTermTwo->uuid);

    // A status outside the enum is a 422, not a silent empty list.
    $as()->getJson('/api/v1/finance/fee-schedules?status=publishedish')
        ->assertStatus(422)->assertJsonValidationErrors(['status']);

    // THE ISOLATION ARM. School B's term is a real terms.id, so an unscoped `exists` accepts it.
    $as()->getJson('/api/v1/finance/fee-schedules?term_id='.$termB->id)
        ->assertStatus(422)->assertJsonValidationErrors(['term_id']);
});

it('an EMPTY filter means unfiltered — the one behaviour the `nullable` choice exists for', function () {
    /*
     * `?term_id=` reaches the rules as NULL (ConvertEmptyStringsToNull is in the framework's default
     * global middleware stack and is not excluded in bootstrap/app.php), and `nullable` is what lets it
     * through to mean "all". A screen that has not chosen a term yet sends exactly this.
     *
     * WATCHED RED by swapping `nullable` for `required` on the term_id rule: this arm fails with
     * "Expected response status code [200] but received 422" and the errors bag names term_id.
     */
    [$school, $term, $level] = fsContext();
    $accountUuid = testBankAccountUuid($school->id);
    fsDraft($school, $term, $level, 'T1', $accountUuid);

    $as = fn () => $this->actingAs(fsAdmin($school))->withSession(['school_id' => $school->id]);

    $unfiltered = $as()->getJson('/api/v1/finance/fee-schedules')->assertOk()->json();
    $empty = $as()->getJson('/api/v1/finance/fee-schedules?term_id=&status=')->assertOk()->json();

    expect($empty)->toBe($unfiltered,
        'An empty term_id/status is not the same as no query string at all. `nullable` exists so a '
        .'screen that has not chosen a term yet gets everything rather than a 422 naming a field its '
        .'operator has not touched.');
});

it('the three WRITE routes return the same schedule shape as index — labels included', function () {
    /*
     * A page renders a row from the list and then re-renders it from the write response. index() carries
     * term_label and class_level_label; store, editDraft and supersede carried neither until the three
     * loadMissing calls gained `term.academicSession` and `classLevel`, so the two columns would have gone
     * blank the moment an operator saved. Same failure the prefill key-list arm exists to prevent, one
     * route over — and this is the response a page is most likely to re-render from.
     *
     * KEY LIST, the same shape as the prefill arm, plus the term_label VALUE: a dropped eager load makes
     * whenLoaded emit MissingValue and the key vanishes, which the key list catches; asserting the value
     * as well means a label that is present-and-null cannot pass either.
     *
     * WATCHED RED by dropping the two relations from editDraft's loadMissing only: this arm fails naming
     * that route, and the other two stay green.
     */
    [$school, $term, $level] = fsContext();
    $accountUuid = testBankAccountUuid($school->id);
    $expectedKeys = ['id', 'term_id', 'class_level_id', 'term_label', 'class_level_label', 'label', 'status', 'items'];
    $expectedLabel = '2026/2027 — First Term';

    $as = fn () => $this->actingAs(fsAdmin($school))->withSession(['school_id' => $school->id]);
    $items = [['description' => 'Tuition', 'amount_minor' => 100000, 'bank_account_id' => $accountUuid]];
    $full = fn (string $label) => ['term_id' => $term->id, 'class_level_id' => $level->id, 'label' => $label, 'items' => $items];

    $shape = function (string $route, array $payload) use ($expectedKeys, $expectedLabel) {
        expect(array_keys($payload))->toBe($expectedKeys,
            $route.' does not return the same schedule shape as index(). A page re-rendering a row from '
            .'this response loses whichever key is missing.');
        expect($payload['term_label'])->toBe($expectedLabel, $route.' returned a term_label that is not the term.');
    };

    // store — 201.
    $stored = $as()->postJson('/api/v1/finance/fee-schedules', $full('v1'))->assertCreated();
    $shape('store (POST /v1/finance/fee-schedules, 201)', (array) $stored->json());
    $uuid = (string) $stored->json('id');

    // editDraft — 200. Its request carries no term_id/class_level_id, by design.
    $edited = $as()->putJson("/api/v1/finance/fee-schedules/{$uuid}/draft", ['label' => 'v2', 'items' => $items])->assertOk();
    $shape('editDraft (PUT /v1/finance/fee-schedules/{uuid}/draft, 200)', (array) $edited->json());

    // supersede — 200. finance_fee_schedules_pending_unique permits one draft-or-pending per slot, so the
    // existing draft has to leave that state before a re-price can be authored beside it.
    DB::table('finance_fee_schedules')->where('uuid', $uuid)->update(['status' => 'active']);
    $superseded = $as()->putJson("/api/v1/finance/fee-schedules/{$uuid}", $full('v3'))->assertOk();
    $shape('supersede (PUT /v1/finance/fee-schedules/{uuid}, 200)', (array) $superseded->json());
});

it('one term is named the same string by all three screens that name it', function () {
    /*
     * Term::displayLabel() exists because there were three copies of this concat and one of them —
     * the approvals queue, the screen where the ED decides whether a schedule becomes billable —
     * printed the bare term name. Every session has a "First Term".
     *
     * THE EXPECTED VALUE IS BUILT LITERALLY, not by calling displayLabel(). An arm that calls the
     * thing it tests asserts nothing: point all three sites at a method that returns '' and it would
     * still pass.
     */
    [$school, $term, $level] = fsContext();
    $expected = '2026/2027 — First Term'; // fsContext() seeds session '2026/2027' and term 'First Term'
    expect($term->displayLabel())->toBe($expected);

    $accountUuid = testBankAccountUuid($school->id);
    $draft = fsDraft($school, $term, $level, 'T1', $accountUuid);

    // Three abilities, three seats — no role holds all three, and that is the point: these are three
    // different screens read by three different people, which is how the copies drifted.
    $maker = fsUser($school, ['finance.access', 'finance.opening-balance.submit']);
    $checker = fsUser($school, ['finance.access', 'finance.fee-schedule.change.approve']);

    // SITE 1 — the fee-schedules list.
    $this->actingAs($maker)->withSession(['school_id' => $school->id])
        ->getJson('/api/v1/finance/fee-schedules')
        ->assertOk()
        ->assertJsonPath('0.term_label', $expected);

    // SITE 2 — the approvals queue's pending feed. This is the screen that printed the BARE term name
    // before the remediation, and it is where the ED decides whether the schedule becomes billable.
    ActiveSchool::runFor($school->id, fn () => app(SubmitFeeScheduleChange::class)
        ->handle(FeeScheduleChangeKind::Publish, $draft, 'Ready to price', $maker));

    $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->getJson('/api/v1/finance/fee-schedule-changes/pending')
        ->assertOk()
        ->assertJsonPath('data.0.target_term', $expected);

    // SITE 3 — the opening-balance operator screen's term select, whose props routes/web.php builds.
    $this->actingAs($maker)->withSession(['school_id' => $school->id])
        ->get('/finance/opening-balances/import')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('terms.0.label', $expected));
});
