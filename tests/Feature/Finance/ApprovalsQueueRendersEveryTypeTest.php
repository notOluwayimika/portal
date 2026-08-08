<?php

use App\Enums\TermStatusEnum;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Enums\DiscountBasis;
use App\Finance\Enums\DiscountPolicyChangeKind;
use App\Finance\Enums\DiscountPolicyChangeStatus;
use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Enums\FeeScheduleChangeKind;
use App\Finance\Enums\FeeScheduleChangeStatus;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\DiscountPolicyChange;
use App\Finance\Models\FeeScheduleChange;
use App\Finance\Models\OpeningBalanceBatch;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * §9 STEP 5a — the approvals queue renders EVERY type, proven over the real HTTP surface.
 *
 * THE DEFECT THIS FILE IS THE FLOOR UNDER. Four pending feeds were live and ability-gated at the API
 * and the page imported two. `fee-schedule-changes/pending` and `discount-policy-changes/pending`
 * were reachable and rendered nowhere, so an approver holding finance.fee-schedule.change.approve had
 * no screen at all. 5a added the fifth feed (opening-balance batches) and made the page render every
 * type from one declared list.
 *
 * WHAT IS PROVEN HERE AND WHAT IS NOT, stated plainly because the split matters. These are HTTP
 * arms: which feeds a given ability set can read, what each returns, and the shape the page consumes.
 * The page's own rendering rules — the ALL-rejected error condition, the declared list's coverage —
 * are asserted in ApprovalsQueueFeedCoverageTest against the TypeScript source, because this
 * repository has no JavaScript test runner (vite/eslint/prettier/tsc, no vitest, no jest). Neither
 * half is a substitute for the other and neither is a browser.
 *
 * THE PENDING ROWS ARE PLANTED, not driven through each type's submit endpoint, for the two change
 * types and the opening-balance batch. Each of those write paths already carries its own dedicated
 * proof file (FeeScheduleChangeTest, DiscountPolicyTest, OpeningBalanceApprovalGateTest); what is
 * under test here is the READ, and re-driving four lifecycles to reach it would make this file fail
 * for reasons that have nothing to do with the queue. Credit notes and voids go through their real
 * submit endpoints because they hang off an invoice, which is genuinely easier to bill than to fake.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/**
 * A School with the academic scaffolding the fee-schedule side needs.
 *
 * @return array{school: School, term: Term, level: ClassLevel}
 */
function aqContext(): array
{
    $school = School::factory()->create();
    $session = AcademicSession::create([
        'school_id' => $school->id, 'name' => '2026/2027', 'slug' => 'sess-'.Str::random(8), 'is_current' => true,
    ]);
    $term = Term::create([
        'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
        'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
    ]);
    $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);

    return ['school' => $school, 'term' => $term, 'level' => $level];
}

/**
 * A user in $school holding EXACTLY $permissions — a dedicated role keyed to the permission set, so
 * two sets never bleed into one another, planted through a raw model_has_roles insert.
 *
 * The raw insert is deliberate and is the same choice FinanceApiAcceptanceTest makes: grant-time duty
 * separation refuses to CONSTRUCT a role holding both sides of a Finance pair through the spatie API,
 * and one arm below needs a maker who also submits. What is under test here is the ability SET a
 * request carries, not the grant path.
 *
 * @param  list<string>  $permissions
 */
function aqUser(School $school, array $permissions): User
{
    $roleName = 'aqtest_'.substr(md5(implode(',', $permissions)), 0, 12);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->schools()->syncWithoutDetaching([$school->id]);
    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => User::class,
        'model_id' => $user->id,
        'school_id' => $school->id,
    ]);
    $user->flushSchoolAccessCache();
    // A runtime grant must invalidate spatie's cache or the request-time PermissionMiddleware
    // resolves a stale role→permission map and 403s a genuinely granted ability.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** The five pending feeds, in the order the declared list carries them. */
function aqFeedUrls(): array
{
    return [
        'credit_note' => '/api/v1/finance/credit-notes/pending',
        'void' => '/api/v1/finance/void-requests/pending',
        'fee_schedule_change' => '/api/v1/finance/fee-schedule-changes/pending',
        'discount_policy_change' => '/api/v1/finance/discount-policy-changes/pending',
        'opening_balance' => '/api/v1/finance/opening-balance-batches/pending',
    ];
}

/** The approve ability gating each feed — the queue's five checker halves. */
function aqApproveAbilities(): array
{
    return [
        'credit_note' => 'finance.credit-note.approve',
        'void' => 'finance.invoice.void-request.approve',
        'fee_schedule_change' => 'finance.fee-schedule.change.approve',
        'discount_policy_change' => 'finance.discount-policy.change.approve',
        'opening_balance' => 'finance.opening-balance.approve',
    ];
}

/** An active enrollment episode for a fresh student in $school; returns its uuid. */
function aqEnrollment(School $school): string
{
    $student = Student::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]))->uuid;
}

/**
 * ONE PENDING ROW OF EVERY TYPE, all submitted by $maker so a distinct checker is never the maker.
 * Returns nothing — the arms below read the feeds, which is the point.
 */
function aqPlantOneOfEach(array $ctx, User $maker): void
{
    $school = $ctx['school'];

    // Credit note + void request: through their real submit endpoints, on two distinct invoices.
    $test = test();
    $creditInvoice = $test->actingAs($maker)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => aqEnrollment($school),
            'lines' => [['description' => 'Tuition', 'amount_minor' => 300000]],
        ])->assertCreated()->json('id');
    $voidInvoice = $test->actingAs($maker)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => aqEnrollment($school),
            'lines' => [['description' => 'Tuition', 'amount_minor' => 250000]],
        ])->assertCreated()->json('id');

    $test->actingAs($maker)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$creditInvoice}/credit-notes", ['amount_minor' => 30000])
        ->assertCreated();
    $test->actingAs($maker)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$voidInvoice}/void-requests", ['reason' => 'entered in error'])
        ->assertCreated();

    ActiveSchool::runFor($school->id, function () use ($ctx, $maker) {
        $schedule = app(CreateFeeSchedule::class)->handle(
            $ctx['term']->id,
            $ctx['level']->id,
            'JSS 1 — First Term',
            [['description' => 'Tuition', 'amount_minor' => 500000]],
        );

        FeeScheduleChange::create([
            'kind' => FeeScheduleChangeKind::Publish,
            'target_schedule_id' => $schedule->id,
            'reason' => 'publish for the term',
            'status' => FeeScheduleChangeStatus::Submitted,
            'submitted_by' => $maker->id,
        ]);

        DiscountPolicyChange::create([
            'kind' => DiscountPolicyChangeKind::Create,
            'name' => 'Sibling discount',
            'basis' => DiscountBasis::Percent,
            'percent' => 10,
            'requires_approval' => false,
            'reason' => 'agreed at the board',
            'status' => DiscountPolicyChangeStatus::Submitted,
            'submitted_by' => $maker->id,
        ]);

        OpeningBalanceBatch::create([
            'batch_reference' => 'WCBS-QUEUE-1',
            'filename' => 'WCBS-QUEUE-1.csv',
            'status' => OpeningBalanceBatchStatus::Submitted,
            'row_count' => 1,
            'file_row_count' => 1,
            'control_total' => Money::fromKobo(750000),
            'cutover_date' => now()->toDateString(),
            'term_id' => $ctx['term']->id,
            'uploaded_by_user_id' => $maker->id,
            'submitted_by_user_id' => $maker->id,
            'submitted_at' => now(),
        ]);
    });
}

/** A maker holding every submit ability the plant needs (plus billing). */
function aqMaker(School $school): User
{
    return aqUser($school, [
        'finance.access',
        'finance.invoice.generate',
        'finance.credit-note.submit',
        'finance.invoice.void-request.submit',
    ]);
}

// ── ARM 1 ────────────────────────────────────────────────────────────────────────────────────────
// The case that breaks the old rule. A checker holding ONE of the five approve abilities reads their
// own feed and is 403 on the other four — every time, on every load. That is the EXPECTED shape of a
// correct grant, not a failure, which is why the page's error condition had to become "ALL rejected".
// Under the rule it replaced ("both rejected", written when two feeds existed) this state is fine at
// N=2 and still fine at N=5 — but under any of the neighbouring rules a reader might reach for
// ("some rejected", "not all fulfilled") this checker is shown a broken queue for holding exactly
// what they were granted. Run once per ability so no single feed carries the claim.
it('a checker holding ONE approve ability reads that feed and is 403 on the other four', function () {
    $ctx = aqContext();
    $school = $ctx['school'];
    aqPlantOneOfEach($ctx, aqMaker($school));

    foreach (aqApproveAbilities() as $type => $ability) {
        $checker = aqUser($school, ['finance.access', $ability]);
        $forbidden = [];

        foreach (aqFeedUrls() as $feedType => $url) {
            $response = $this->actingAs($checker)->withSession(['school_id' => $school->id])->getJson($url);

            if ($feedType === $type) {
                $response->assertOk()->assertJsonPath('data.0.type', $type);

                continue;
            }

            $response->assertForbidden();
            $forbidden[] = $feedType;
        }

        // Exactly four rejections, and the holder still has rows: this is the state the queue must
        // render as a normal, populated screen.
        expect($forbidden)->toHaveCount(4, "ability {$ability} did not 403 on the four sibling feeds");
    }
});

// ── ARM 2 ────────────────────────────────────────────────────────────────────────────────────────
// The genuine total failure, and it is REACHABLE rather than hypothetical: the page is gated on
// holding ANY finance checker ability, while every pending feed is gated on an APPROVE ability. A
// reject-only checker therefore opens the queue and is 403 on all five — the one state that must
// show the error card rather than the "Nothing awaiting approval" card, because an empty success
// here is the page telling someone there is no work when it simply could not look.
it('a checker holding no approve ability opens the queue and is 403 on every feed', function () {
    $ctx = aqContext();
    $school = $ctx['school'];
    aqPlantOneOfEach($ctx, aqMaker($school));

    $rejectOnly = aqUser($school, ['finance.access', 'finance.credit-note.reject']);

    // They may open the screen — the page's visibility gate is any checker ability (routes/web.php).
    $this->actingAs($rejectOnly)->withSession(['school_id' => $school->id])
        ->get('/finance/approvals')->assertOk();

    foreach (aqFeedUrls() as $url) {
        $this->actingAs($rejectOnly)->withSession(['school_id' => $school->id])
            ->getJson($url)->assertForbidden();
    }
});

// ── ARM 3 ────────────────────────────────────────────────────────────────────────────────────────
// All five types reach a checker who holds all five, each in the shape the queue consumes: the `data`
// envelope, the `type` discriminator, the server-computed decision flags. Before 5a two of these
// answered a BARE ARRAY and carried no `type`, no `submitted_by_name` and no can_approve/can_reject —
// so even a page that fetched them could not have rendered them.
it('all five types reach a checker holding all five, in the one shape the queue consumes', function () {
    $ctx = aqContext();
    $school = $ctx['school'];
    aqPlantOneOfEach($ctx, aqMaker($school));

    $checker = aqUser($school, ['finance.access', ...array_values(aqApproveAbilities())]);
    $seen = [];

    foreach (aqFeedUrls() as $type => $url) {
        $row = $this->actingAs($checker)->withSession(['school_id' => $school->id])
            ->getJson($url)->assertOk()->json('data.0');

        expect($row)->not->toBeNull("{$url} returned no pending row")
            ->and($row['type'])->toBe($type)
            ->and($row)->toHaveKeys(['id', 'note', 'amount', 'status', 'submitted_by_name', 'can_approve', 'can_reject', 'created_at']);

        $seen[] = $row['type'];
    }

    expect($seen)->toBe(array_keys(aqFeedUrls()));
});

// ── ARM 3b ───────────────────────────────────────────────────────────────────────────────────────
// The decision flags are the SERVER's, and viewer-relative. The checker below is not the maker, so
// every type says true.
//
// OPENING BALANCE SAID FALSE HERE UNTIL §9 STEP 5b-ii, and the arm is kept rather than deleted
// because the fact it pins has changed rather than gone away. It read false for every viewer while
// the type had no policy for the Gate to consult and no approve/reject endpoint to press — the queue
// rendered where its decision was taken instead of a button that could not work. 5b-ii added
// OpeningBalanceBatchPolicy and the two routes, and the Resource itself was never edited: it computed
// these flags through the Gate from the start, which is what let the surface land without touching
// it. The false-for-the-MAKER half of the same statement — the half that shows the Policy is being
// consulted rather than blanket-allowing — is OpeningBalanceDecisionSurfaceTest's PROOF G.
it('decision flags are server-computed per type, and every type is decidable by a non-maker checker', function () {
    $ctx = aqContext();
    $school = $ctx['school'];
    aqPlantOneOfEach($ctx, aqMaker($school));

    $checker = aqUser($school, ['finance.access', ...array_values(aqApproveAbilities())]);
    $flags = [];

    foreach (aqFeedUrls() as $type => $url) {
        $flags[$type] = $this->actingAs($checker)->withSession(['school_id' => $school->id])
            ->getJson($url)->assertOk()->json('data.0.can_approve');
    }

    expect($flags)->toBe([
        'credit_note' => true,
        'void' => true,
        'fee_schedule_change' => true,
        'discount_policy_change' => true,
        'opening_balance' => true,
    ]);
});

// ── ARM 4 ────────────────────────────────────────────────────────────────────────────────────────
// School isolation, on the feed 5a added. It is inherited (BelongsToSchool + SchoolScope) rather than
// written, which is exactly why it is asserted: an inherited guarantee that nobody checks is the kind
// that a later `withoutGlobalScopes()` quietly removes.
// ── ARM 5 ────────────────────────────────────────────────────────────────────────────────────────
// THE SUBJECT REACHES THE WIRE, and two pending changes against different targets are TELLABLE
// APART. This is the arm that matters most on this screen: `open_key` forbids two open requests
// against the SAME schedule and permits any number against different ones, so a checker really can
// face several publishes at once. Before the `target_*` fields the only per-row content was
// `kind`, so every one of them read "Fee schedule · publish" and the second signature was given to
// a row that did not say what it was about — maker-checker with the checking removed.
//
// Asserted on the WIRE rather than on the rendered string: the subject renderer lives in
// TypeScript, and a renderer composing from fields the Resource does not emit is the same green-
// that-means-nothing this branch has now hit three times. What is checkable from here is that the
// parts it composes from are present, populated, and DIFFERENT per target.
it('two pending fee-schedule changes against different targets carry different subjects', function () {
    $ctx = aqContext();
    $school = $ctx['school'];
    $maker = aqMaker($school);

    $second = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 2', 'order' => 2]);

    ActiveSchool::runFor($school->id, function () use ($ctx, $second, $maker) {
        foreach ([[$ctx['level'], 'JSS 1 — First Term'], [$second, 'JSS 2 — First Term']] as [$level, $label]) {
            $schedule = app(CreateFeeSchedule::class)->handle(
                $ctx['term']->id,
                $level->id,
                $label,
                [['description' => 'Tuition', 'amount_minor' => 500000]],
            );

            FeeScheduleChange::create([
                'kind' => FeeScheduleChangeKind::Publish,
                'target_schedule_id' => $schedule->id,
                'reason' => 'publish for the term',
                'status' => FeeScheduleChangeStatus::Submitted,
                'submitted_by' => $maker->id,
            ]);
        }
    });

    $checker = aqUser($school, ['finance.access', 'finance.fee-schedule.change.approve']);
    $rows = $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->getJson('/api/v1/finance/fee-schedule-changes/pending')->assertOk()->json('data');

    expect($rows)->toHaveCount(2);

    // Every part the subject is composed from is on the wire and populated — not merely present.
    foreach ($rows as $row) {
        // `?? null` deliberately: these are whenLoaded() fields, so dropping the eager load OMITS
        // the keys rather than nulling them. Reading them defensively makes the failure "null is
        // not a string" instead of an undefined-key warning — the same red, legible.
        expect($row['target_label'] ?? null)->toBeString()->not->toBe('')
            ->and($row['target_class_level'] ?? null)->toBeString()->not->toBe('')
            ->and($row['target_term'] ?? null)->toBeString()->not->toBe('');
    }

    // And the two rows differ where it counts. Comparing the composed TRIPLE, because that is what
    // the checker reads: two rows agreeing on kind and term but differing on class level are still
    // tellable apart, and this must not pass just because some unrelated field differs.
    $subjects = array_map(
        fn (array $row) => $row['kind'].'|'.($row['target_class_level'] ?? '').'|'.($row['target_term'] ?? '').'|'.($row['target_label'] ?? ''),
        $rows
    );

    expect(array_unique($subjects))->toHaveCount(2, 'two pending fee-schedule changes render identically: '.$subjects[0]);
});

// ── ARM 6 ────────────────────────────────────────────────────────────────────────────────────────
// The discount twin, on the case its own row cannot answer — and the case is a RETIRE, not an amend.
// The `…_terms_shape` CHECK (2026_07_26_140001:76-84) forces `name`, `basis` and `requires_approval`
// to be NULL on a retire and NOT NULL on everything else, so a retire carries no name, no basis and
// no value at all: its only content is `target_policy_id`, an internal integer nobody reads. Two
// pending retires are then two rows saying "Discount policy" and nothing else. `target_policy_name`
// is the only thing that tells them apart.
//
// I asserted the opposite first — that `name` is null on an amend — and the CHECK rejected the
// insert with SQLSTATE 3819. Recorded rather than quietly corrected: the database was the thing that
// knew, and a docblock claiming the wrong one shipped in three files before it spoke up.
it('a retire names the policy it retires — the row itself carries no name at all', function () {
    $ctx = aqContext();
    $school = $ctx['school'];
    $maker = aqMaker($school);

    ActiveSchool::runFor($school->id, function () use ($maker) {
        $policy = DiscountPolicy::create([
            'name' => 'Staff ward discount',
            'basis' => DiscountBasis::Percent,
            'percent' => 25,
            'requires_approval' => false,
            'status' => DiscountPolicyStatus::Active,
        ]);

        DiscountPolicyChange::create([
            'kind' => DiscountPolicyChangeKind::Retire,
            'target_policy_id' => $policy->id,
            // name / basis / requires_approval MUST be null here — the CHECK enforces it.
            'reason' => 'withdrawn at the board',
            'status' => DiscountPolicyChangeStatus::Submitted,
            'submitted_by' => $maker->id,
        ]);
    });

    $checker = aqUser($school, ['finance.access', 'finance.discount-policy.change.approve']);
    $row = $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->getJson('/api/v1/finance/discount-policy-changes/pending')->assertOk()->json('data.0');

    // The row states nothing about itself…
    expect($row['name'])->toBeNull()
        ->and($row['basis'])->toBeNull()
        // …so the ONLY identifying content is the target's name, and it is present and populated.
        ->and($row['target_policy_name'] ?? null)->toBeString()->not->toBe('');
});

// ── ARM 7 ────────────────────────────────────────────────────────────────────────────────────────
it('the opening-balance feed is School-scoped — a checker in School A sees none of School B', function () {
    $a = aqContext();
    $b = aqContext();
    aqPlantOneOfEach($a, aqMaker($a['school']));
    aqPlantOneOfEach($b, aqMaker($b['school']));

    $checkerA = aqUser($a['school'], ['finance.access', 'finance.opening-balance.approve']);

    $rows = $this->actingAs($checkerA)->withSession(['school_id' => $a['school']->id])
        ->getJson('/api/v1/finance/opening-balance-batches/pending')->assertOk()->json('data');

    expect($rows)->toHaveCount(1);
});
