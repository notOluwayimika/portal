<?php

/*
 * EDITING A DRAFT FEE SCHEDULE — the operation that did not exist.
 *
 * Before this commit a draft could be neither edited nor deleted, and
 * finance_fee_schedules_pending_unique (S1 4a) let it occupy its own (school, term, class level) slot.
 * One typo therefore bricked that slot until someone ran SQL. Meanwhile RejectFeeScheduleChange has
 * returned a rejected publish to `draft` since 4a "so the Head can edit and resubmit — the items unfreeze
 * the moment the schedule is a draft again": the loop was built for a door that was never cut.
 *
 * THREE CHECKS refuse a non-draft, but only TWO are independently load-bearing, and that was MEASURED
 * rather than assumed — removing the pre-lock check alone leaves every arm below green:
 *
 *   Action, pre-lock   an EARLY refusal, not a control. The locked re-read says the same thing a few
 *                      microseconds later; what it buys is not opening a transaction to be told no.
 *   Action, under lock LOAD-BEARING. A row re-read with lockForUpdate — the window where an approval
 *                      lands between the check and the write. Pinned by its own arm, because the route
 *                      resolves a fresh model and can never reach this layer. Removing it turns the
 *                      refusal from a 422 into a QueryException from the trigger, i.e. a 500.
 *   Database           LOAD-BEARING. finance_fee_items_parent_state_guard_{ins,upd,del}, SQLSTATE 45000.
 *                      The backstop for a writer that never enters the Action at all.
 */

use App\Enums\TermStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Actions\EditFeeScheduleDraft;
use App\Finance\Actions\SubmitFeeScheduleChange;
use App\Finance\Enums\FeeScheduleChangeKind;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Models\BankAccount;
use App\Finance\Models\FeeItem;
use App\Finance\Models\FeeSchedule;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array{0: School, 1: Term, 2: ClassLevel} */
function efsdContext(): array
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

    return [$school, $term, $level];
}

/** A user holding the seeded `admin` role in $school (finance.fee-schedule.manage). */
function efsdAdmin(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'admin');
    $user->flushSchoolAccessCache();

    return $user;
}

/** An active enrollment to bill — StudentCurriculum has no factory, so it is created the long way. */
function efsdEnrollment(School $school): StudentCurriculum
{
    $student = Student::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));
}

/** @param list<array<string, mixed>>|null $items */
function efsdDraft(School $school, Term $term, ClassLevel $level, ?array $items = null): FeeSchedule
{
    return ActiveSchool::runFor($school->id, fn () => app(CreateFeeSchedule::class)->handle(
        $term->id, $level->id, 'v1',
        $items ?? [['description' => 'Tuition', 'amount_minor' => 100000, 'bank_account_id' => testBankAccountUuid($school->id)]],
    ));
}

/** @return array<string, mixed> */
function efsdBody(School $school, Term $term, ClassLevel $level, string $label = 'v2'): array
{
    return [
        'term_id' => $term->id, 'class_level_id' => $level->id, 'label' => $label,
        'items' => [
            ['description' => 'Tuition', 'amount_minor' => 250000, 'bank_account_id' => testBankAccountUuid($school->id), 'sort_order' => 0],
            ['description' => 'Transport', 'amount_minor' => 30000, 'bank_account_id' => testBankAccountUuid($school->id), 'sort_order' => 1],
        ],
    ];
}

it('edits a draft in place — same row, items replaced wholesale', function () {
    [$school, $term, $level] = efsdContext();
    $draft = efsdDraft($school, $term, $level);
    $originalId = $draft->id;
    $originalItemIds = $draft->items->pluck('id')->all();

    $response = $this->actingAs(efsdAdmin($school))
        ->withSession(['school_id' => $school->id])
        ->putJson('/api/v1/finance/fee-schedules/'.$draft->uuid.'/draft', efsdBody($school, $term, $level));

    $response->assertOk();

    // SAME ROW. The whole point of the commit: the schedule count does not grow, so the slot is not
    // consumed a second time and pending_unique is never tripped.
    expect(FeeSchedule::withoutGlobalScopes()->count())->toBe(1,
        'Editing a draft created a second schedule. That is the supersede path, not the edit path, and it '
        .'would collide with finance_fee_schedules_pending_unique on the very next attempt.');

    $fresh = FeeSchedule::withoutGlobalScopes()->findOrFail($originalId);
    expect($fresh->label)->toBe('v2')
        ->and($fresh->status)->toBe(FeeScheduleStatus::Draft);

    // WHOLESALE. The old items are gone, not merged with, and the new set is exactly what was submitted.
    $items = FeeItem::withoutGlobalScopes()->where('fee_schedule_id', $originalId)->orderBy('sort_order')->get();
    expect($items)->toHaveCount(2)
        ->and($items->pluck('description')->all())->toBe(['Tuition', 'Transport'])
        ->and($items->pluck('amount_minor')->all())->toBe([250000, 30000])
        ->and(FeeItem::withoutGlobalScopes()->whereIn('id', $originalItemIds)->count())->toBe(0,
            'The pre-edit items survived the edit. Wholesale replacement means they go; a survivor here '
            .'would be an item nobody submitted still priced on the schedule.');
});

it('refuses to edit a schedule in every non-draft state', function (string $status) {
    // ONE ARM PER STATE, keyed, so a failure names the state rather than an index. An unkeyed dataset of
    // bare strings has produced ZERO tests here before and reported a bare failure.
    [$school, $term, $level] = efsdContext();
    $draft = efsdDraft($school, $term, $level);

    // Raw status write — a test of the guard, not of any Action that moves the status.
    DB::table('finance_fee_schedules')->where('id', $draft->id)->update(['status' => $status]);

    $response = $this->actingAs(efsdAdmin($school))
        ->withSession(['school_id' => $school->id])
        ->putJson('/api/v1/finance/fee-schedules/'.$draft->uuid.'/draft', efsdBody($school, $term, $level));

    $response->assertStatus(422);
    expect((string) $response->json('message'))->toContain($status);

    // AND NOTHING WAS WRITTEN. A 422 with a half-applied edit behind it would be worse than a 500: the
    // label could move while the items did not, and the operator would be told it failed.
    $fresh = FeeSchedule::withoutGlobalScopes()->findOrFail($draft->id);
    expect($fresh->label)->toBe('v1');
    expect(FeeItem::withoutGlobalScopes()->where('fee_schedule_id', $draft->id)->count())->toBe(1);
})->with([
    'pending_approval' => 'pending_approval',
    'active' => 'active',
    'superseded' => 'superseded',
    'retired' => 'retired',
]);

it('refuses the CHECKER of the publish this draft feeds — the route is gated on manage, not on access', function () {
    /*
     * THE ROUTE'S PERMISSION MIDDLEWARE WAS PINNED BY NOTHING, and a cold review found it. Deleting
     * `->middleware('permission:finance.fee-schedule.manage')` left all twelve arms of this file,
     * RouteMiddlewareBaselineTest, RouteAccessParityTest and FinanceNavCoverageTest green — 37/37 —
     * and the live route table then showed the route falling back to the group's `finance.access`.
     *
     * That fallback is not a wider audience, it is a DUTY-SEPARATION HOLE. Six roles hold
     * finance.access and four of them do NOT hold finance.fee-schedule.manage — principal,
     * accounts_supervisor, finance_lead, and executive_director. The ED is the CHECKER who approves
     * the publish of this very draft (finance.fee-schedule.change.approve). An ED who can edit the
     * draft can approve numbers they wrote, which is the exact thing S1 4a closed by prevention.
     *
     * The ED is chosen deliberately over the other three: the others would merely be an authorization
     * mistake, this one is a governance one.
     */
    [$school, $term, $level] = efsdContext();
    $draft = efsdDraft($school, $term, $level);

    $checker = User::factory()->create(['school_id' => $school->id]);
    $checker->grantSchoolAccess($school, 'executive_director');
    $checker->flushSchoolAccessCache();

    // NOT VACUOUS: the ED really does reach finance, so a 403 here is the manage gate refusing and
    // not the module refusing. Asserted first, because without it this arm passes just as well
    // against a user who cannot see Finance at all.
    $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->getJson('/api/v1/finance/fee-schedules')
        ->assertOk();

    $this->actingAs($checker)->withSession(['school_id' => $school->id])
        ->putJson('/api/v1/finance/fee-schedules/'.$draft->uuid.'/draft', efsdBody($school, $term, $level))
        ->assertForbidden();

    // And nothing was written — a 403 that still edited would be the worst of both.
    $fresh = FeeSchedule::withoutGlobalScopes()->findOrFail($draft->id);
    expect($fresh->label)->toBe('v1');
    expect(FeeItem::withoutGlobalScopes()->where('fee_schedule_id', $draft->id)->count())->toBe(1);
});

it('refuses when the schedule stops being a draft between the check and the write', function () {
    // THE LOCKED RE-CHECK, ON ITS OWN. The arms above cannot reach it: the route resolves a fresh model, so
    // the pre-lock check answers first and this layer never speaks. Here the model in hand still says
    // `draft` while the ROW has moved on — precisely the ADR 0050 window 4a closed by prevention, an
    // approval landing between a Head opening the editor and saving it. Only the re-read under
    // lockForUpdate sees it.
    [$school, $term, $level] = efsdContext();
    $draft = efsdDraft($school, $term, $level);

    DB::table('finance_fee_schedules')->where('id', $draft->id)->update(['status' => 'active']);

    expect($draft->status)->toBe(FeeScheduleStatus::Draft,
        'The in-memory model refreshed itself, so the pre-lock check would catch this and the arm would '
        .'pass without the locked re-read ever running — a green proving the wrong layer.');

    $edit = fn () => ActiveSchool::runFor($school->id, fn () => app(EditFeeScheduleDraft::class)->handle(
        $draft, 'v2', [['description' => 'Tuition', 'amount_minor' => 250000, 'bank_account_id' => testBankAccountUuid($school->id)]],
    ));

    expect($edit)->toThrow(BusinessRuleException::class);

    $fresh = FeeSchedule::withoutGlobalScopes()->findOrFail($draft->id);
    expect($fresh->label)->toBe('v1');
    expect(FeeItem::withoutGlobalScopes()->where('fee_schedule_id', $draft->id)->count())->toBe(1);
});

it('refuses to edit another School’s draft', function () {
    // Isolation. `$feeSchedule` arrives as a route-bound model; SchoolScope already refuses to resolve a
    // foreign uuid, so this asserts the 404 the binding produces AND that the foreign draft is untouched —
    // the second half is what would still hold if the binding ever stopped going through the scope.
    [$mine, $myTerm, $myLevel] = efsdContext();
    [$theirs, $theirTerm, $theirLevel] = efsdContext();

    $theirDraft = efsdDraft($theirs, $theirTerm, $theirLevel);

    $this->actingAs(efsdAdmin($mine))
        ->withSession(['school_id' => $mine->id])
        ->putJson('/api/v1/finance/fee-schedules/'.$theirDraft->uuid.'/draft', efsdBody($mine, $myTerm, $myLevel))
        ->assertNotFound();

    $fresh = FeeSchedule::withoutGlobalScopes()->findOrFail($theirDraft->id);
    expect($fresh->label)->toBe('v1');
    expect(FeeItem::withoutGlobalScopes()->where('fee_schedule_id', $theirDraft->id)->count())->toBe(1);
});

it('refuses an edit whose item names no bank account, and one naming a deactivated account', function () {
    // FeeScheduleRequest IS REUSED, not re-implemented — so the rule #233 put on create bites on edit for
    // free. Both halves asserted: the missing field and the deactivated account, because `required` alone
    // would pass the second one.
    [$school, $term, $level] = efsdContext();
    $draft = efsdDraft($school, $term, $level);

    $this->actingAs(efsdAdmin($school))->withSession(['school_id' => $school->id]);

    $body = efsdBody($school, $term, $level);
    unset($body['items'][0]['bank_account_id']);
    $this->putJson('/api/v1/finance/fee-schedules/'.$draft->uuid.'/draft', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.bank_account_id');

    $account = ActiveSchool::runFor($school->id, fn () => BankAccount::query()->firstOrFail());
    ActiveSchool::runFor($school->id, fn () => $account->update(['deactivated_at' => now()]));

    $this->putJson('/api/v1/finance/fee-schedules/'.$draft->uuid.'/draft', efsdBody($school, $term, $level))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.bank_account_id');
});

it('the database refuses an item write against a non-draft parent, with the Action out of the picture', function () {
    // THE BACKSTOP, on its own. Not reachable through the route once the Action's two checks hold, which
    // is exactly why it is exercised directly: it is the layer that still stands if both of them rot.
    [$school, $term, $level] = efsdContext();
    $draft = efsdDraft($school, $term, $level);
    $itemId = $draft->items->first()->id;

    DB::table('finance_fee_schedules')->where('id', $draft->id)->update(['status' => 'active']);

    $codes = [];
    foreach ([
        'delete' => fn () => DB::table('finance_fee_items')->where('id', $itemId)->delete(),
        'update' => fn () => DB::table('finance_fee_items')->where('id', $itemId)->update(['description' => 'Tampered']),
        'insert' => fn () => DB::table('finance_fee_items')->insert([
            'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'fee_schedule_id' => $draft->id,
            'description' => 'Smuggled', 'amount_minor' => 1, 'amount_currency' => 'NGN',
            'is_mandatory' => 1, 'is_discountable' => 1, 'sort_order' => 9,
            'bank_account_id' => testBankAccountId($school->id),
            'created_at' => now(), 'updated_at' => now(),
        ]),
    ] as $event => $write) {
        try {
            $write();
            $codes[$event] = 0;
        } catch (QueryException $e) {
            $codes[$event] = (int) ($e->errorInfo[1] ?? 0);
        }
    }

    expect($codes)->toBe(['delete' => 1644, 'update' => 1644, 'insert' => 1644],
        'A fee item was written while its parent schedule was active. The three '
        .'finance_fee_items_parent_state_guard triggers are the last thing standing between an approved '
        .'price and a silent change to it.');
});

/*
 * ── The rule that keeps the wholesale ruling true ────────────────────────────
 *
 * These arms are about GenerateInvoiceRequest, and they live HERE because they exist for this commit. The
 * safety argument for replacing a draft's items wholesale is "a draft's items cannot be cited by any
 * invoice" — true of every path in the tree, and false of a hand-crafted request, because `fee_item_id`
 * was validated as `['sometimes','nullable','integer']` and nothing more. GenerateInvoice:280-282 already
 * states the principle for the field beside it — is_discountable "is a property of the fee ITEM", resolved
 * server-side, "never from the wire", because a client does not get to decide it. The rule was written;
 * this field is the one that escaped it.
 */

it('refuses an invoice line citing ANOTHER School’s fee item', function () {
    // THE ISOLATION HALF, and this comment describes the rule that SHIPPED. It briefly described an
    // abandoned one — a `Rule::exists` with a hand-rolled `school_id` term — which bin/ci-boundary-lint
    // killed before the commit landed, and a reviewer following that description to mutate the
    // money-side isolation would have found nothing to mutate.
    //
    // The shipped rule is a closure over `FeeItem::query()`, so SchoolScope IS the isolation: a
    // foreign item does not resolve and the closure fails the attribute. To red this arm, change that
    // to `FeeItem::withoutGlobalScopes()` — which is what was actually done, and it returned 201.
    [$mine] = efsdContext();
    [$theirs, $theirTerm, $theirLevel] = efsdContext();

    $theirSchedule = efsdDraft($theirs, $theirTerm, $theirLevel);
    DB::table('finance_fee_schedules')->where('id', $theirSchedule->id)->update(['status' => 'active']);
    $theirItemId = $theirSchedule->items->first()->id;

    $enrollment = efsdEnrollment($mine);

    $this->actingAs(efsdAdmin($mine))
        ->withSession(['school_id' => $mine->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment->uuid,
            'lines' => [['description' => 'Tuition', 'amount_minor' => 100000, 'fee_item_id' => $theirItemId]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.fee_item_id');

    expect(DB::table('finance_invoices')->count())->toBe(0,
        'An invoice was raised citing another School’s fee item as the provenance of its price. '
        .'finance_invoice_lines.fee_item_id carries no foreign key by policy, so the wire is the only '
        .'place this can be refused.');
});

it('refuses an invoice line citing a DRAFT’s fee item, and accepts a SUPERSEDED one', function () {
    // BOTH DIRECTIONS IN ONE ARM, because the rule is a boundary and either half alone would pass a rule
    // that got the boundary wrong. Draft is refused: it is an unpublished proposal, no path emits it, and
    // it is the state EditFeeScheduleDraft replaces wholesale. Superseded is ACCEPTED: a void-and-rebill,
    // or a form prefilled before an approval landed, legitimately cites a schedule that has since been
    // superseded by ApproveFeeScheduleChange:87 — refusing there 422s an operator for a change they could
    // not see.
    [$school, $term, $level] = efsdContext();
    $draft = efsdDraft($school, $term, $level);
    $itemId = $draft->items->first()->id;

    $enrollment = efsdEnrollment($school);

    $this->actingAs(efsdAdmin($school))->withSession(['school_id' => $school->id]);

    $post = fn () => $this->postJson('/api/v1/finance/invoices', [
        'enrollment_id' => $enrollment->uuid,
        'lines' => [['description' => 'Tuition', 'amount_minor' => 100000, 'fee_item_id' => $itemId]],
    ]);

    $post()->assertStatus(422)->assertJsonValidationErrors('lines.0.fee_item_id');

    DB::table('finance_fee_schedules')->where('id', $draft->id)->update(['status' => 'superseded']);

    $post()->assertCreated();
    expect((int) DB::table('finance_invoice_lines')->value('fee_item_id'))->toBe($itemId,
        'A superseded schedule’s item was refused. Void-and-rebill and the prefill/approval race both bill '
        .'from one, and GenerateInvoice’s own discountability resolution already tolerates it.');
});

it('an edited draft still submits for approval, and the approver sees the edited numbers', function () {
    // THE SEAM. An edit that produced a schedule the governance surface could not carry would be a page
    // sitting beside the approval flow rather than inside it.
    [$school, $term, $level] = efsdContext();
    $draft = efsdDraft($school, $term, $level);

    $edited = ActiveSchool::runFor($school->id, fn () => app(EditFeeScheduleDraft::class)->handle(
        $draft, 'v2 corrected',
        [['description' => 'Tuition', 'amount_minor' => 250000, 'bank_account_id' => testBankAccountUuid($school->id)]],
    ));

    $submitted = ActiveSchool::runFor($school->id, fn () => app(SubmitFeeScheduleChange::class)->handle(
        FeeScheduleChangeKind::Publish, $edited, 'Corrected the tuition figure.', efsdAdmin($school),
    ));

    expect($submitted->target_schedule_id)->toBe($edited->id);

    $target = FeeSchedule::withoutGlobalScopes()->with('items')->findOrFail($edited->id);
    expect($target->status)->toBe(FeeScheduleStatus::PendingApproval)
        ->and($target->items->pluck('amount_minor')->all())->toBe([250000],
            'The submitted schedule carries pre-edit numbers. The ED would approve figures nobody wrote.');
});
