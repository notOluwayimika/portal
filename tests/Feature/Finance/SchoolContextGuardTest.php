<?php

/*
 * CONSTITUTION 13, ENFORCED — the School-context guard on all fifteen finance maker-checker Actions.
 *
 * WHY THE ASSERTION IS THE EXCEPTION AND NOT "NOTHING CHANGED". "Nothing changed" is the SYMPTOM the
 * guard exists to distinguish from success. Before this commit, twelve of these actions run with a
 * mismatched context read an empty row set through SchoolScope, wrote nothing, and RETURNED NORMALLY
 * — a governance act reporting success having done nothing. An arm asserting "no rows changed" would
 * have passed against that behaviour and against the guard equally, which is to say it would have
 * asserted nothing. So every arm below demands a BusinessRuleException, and the message with it.
 *
 * TWO CASES PER ACTION, because they are different holes. A NULL context is the super-admin path
 * (SetSchoolContext:51 lets a super admin through with no school selected) and the off-request path
 * (a console run or a queued job that forgot ActiveSchool::runFor). A FOREIGN record is the one the
 * scope cannot catch at all: a model handed in directly was never filtered by anything.
 *
 * ONE ACTION TAKES THE WEAKER GUARD ON ONE PATH, and it is here rather than exempted:
 * SubmitDiscountPolicyChange's `create` kind names no policy, so it carries the null-context arm and
 * takes its foreign-record arm on the AMEND path, where a target exists.
 */

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveCreditNote;
use App\Finance\Actions\ApproveDiscountPolicyChange;
use App\Finance\Actions\ApproveFeeScheduleChange;
use App\Finance\Actions\ApproveOpeningBalanceBatch;
use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RejectCreditNote;
use App\Finance\Actions\RejectDiscountPolicyChange;
use App\Finance\Actions\RejectFeeScheduleChange;
use App\Finance\Actions\RejectOpeningBalanceBatch;
use App\Finance\Actions\RejectVoidRequest;
use App\Finance\Actions\SubmitCreditNote;
use App\Finance\Actions\SubmitDiscountPolicyChange;
use App\Finance\Actions\SubmitFeeScheduleChange;
use App\Finance\Actions\SubmitOpeningBalanceBatch;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Enums\DiscountBasis;
use App\Finance\Enums\DiscountPolicyChangeKind;
use App\Finance\Enums\FeeScheduleChangeKind;
use App\Finance\Enums\FeeScheduleChangeStatus;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\FeeScheduleChange;
use App\Finance\Models\OpeningBalanceBatch;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * One School with a complete governance fixture: an invoice, a credit note, a void request, a fee
 * schedule and its change, a discount policy and its change, and an opening-balance batch.
 *
 * Everything is built INSIDE that School's context, so the records are real rows owned by it. The
 * arms then act on them from somewhere else, which is the whole subject of this file.
 */
function scgFixture(): array
{
    $school = School::factory()->create();
    $maker = User::factory()->create(['school_id' => $school->id]);
    $checker = User::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, function () use ($school, $maker, $checker, $student) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
            'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2), 'status' => 'active',
        ]);
        $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);
        $arm = ClassLevelArm::create([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'arm_id' => Arm::create(['school_id' => $school->id, 'label' => strtoupper(Str::random(3))])->id,
        ]);
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $school->id,
            'curriculum_id' => Curriculum::factory()->create([
                'school_id' => $school->id, 'class_level_arm_id' => $arm->id, 'term_id' => $term->id,
            ])->id,
            'status' => 'active',
        ]);

        $invoice = app(GenerateInvoice::class)->handle($enrollment->uuid, [
            new InvoiceLineSpec('Tuition', Money::fromKobo(500000), bankAccountId: testBankAccountId()),
        ], InvoiceKind::Scheduled);

        // Built through their REAL submit actions rather than by hand: a credit note carries a
        // generated `number` and both rows carry the state the maker path sets. A hand-made row is a
        // shape these actions never actually meet.
        $creditNote = app(SubmitCreditNote::class)
            ->handle($invoice, Money::fromKobo(10000), CreditNoteKind::CreditNote, null, $maker);

        $voidRequest = app(SubmitVoidRequest::class)
            ->handle($invoice, 'entered in error', $maker);

        $schedule = app(CreateFeeSchedule::class)->handle(
            $term->id, $level->id, 'JSS 1 — First Term',
            [['bank_account_id' => testBankAccountUuid(), 'description' => 'Tuition', 'amount_minor' => 500000]],
        );

        $scheduleChange = FeeScheduleChange::create([
            'kind' => FeeScheduleChangeKind::Publish,
            'target_schedule_id' => $schedule->id,
            'reason' => 'publish for the term',
            'status' => FeeScheduleChangeStatus::Submitted,
            'submitted_by' => $maker->id,
        ]);

        $policy = DiscountPolicy::create([
            'name' => 'Sibling discount',
            'basis' => DiscountBasis::Percent,
            'percent' => 10,
            'requires_approval' => false,
        ]);

        // Through the real action too, for the same reason: `finance_discount_policy_changes` carries
        // a `terms_shape` CHECK that a hand-built row trips, and the action is what satisfies it.
        $policyChange = app(SubmitDiscountPolicyChange::class)->handle(
            DiscountPolicyChangeKind::Amend,
            $policy,
            ['name' => 'Sibling discount', 'basis' => 'percent', 'percent' => 15, 'requires_approval' => false],
            'board raised it',
            $maker,
        );

        $batch = OpeningBalanceBatch::create([
            'batch_reference' => 'WCBS-SCG-'.Str::random(6),
            'filename' => 'wcbs.csv',
            'status' => OpeningBalanceBatchStatus::Validated,
            'row_count' => 1,
            'file_row_count' => 1,
            'control_total' => Money::fromKobo(100000),
            'cutover_date' => now()->toDateString(),
            'term_id' => $term->id,
            'uploaded_by_user_id' => $maker->id,
        ]);

        return compact(
            'school', 'maker', 'checker', 'student', 'term', 'level',
            'invoice', 'creditNote', 'voidRequest', 'schedule', 'scheduleChange',
            'policy', 'policyChange', 'batch',
        );
    });
}

/**
 * Every finance maker-checker Action, as [invoke, noun-in-the-ownership-message].
 *
 * DERIVED FROM THE FILESYSTEM IS NOT POSSIBLE HERE — each action has a different signature, so the
 * list is written out. The count is asserted against the glob in its own arm below, so a new
 * Submit/Approve/Reject action that nobody adds here fails rather than being silently uncovered.
 *
 * @return array<string, array{0: Closure, 1: string}>
 */
function scgActions(): array
{
    return [
        'SubmitCreditNote' => [fn (array $f) => app(SubmitCreditNote::class)
            ->handle($f['invoice'], Money::fromKobo(1000), CreditNoteKind::CreditNote, null, $f['maker']), 'invoice'],
        'SubmitVoidRequest' => [fn (array $f) => app(SubmitVoidRequest::class)
            ->handle($f['invoice'], 'entered in error', $f['maker']), 'invoice'],
        'SubmitFeeScheduleChange' => [fn (array $f) => app(SubmitFeeScheduleChange::class)
            ->handle(FeeScheduleChangeKind::Publish, $f['schedule'], 'publish', $f['maker']), 'fee-schedule change'],
        // The AMEND path: a create names no policy, so the foreign-record case only exists here.
        'SubmitDiscountPolicyChange' => [fn (array $f) => app(SubmitDiscountPolicyChange::class)
            ->handle(DiscountPolicyChangeKind::Amend, $f['policy'], ['percent' => 20, 'basis' => 'percent'], 'raise it', $f['maker']), 'discount policy'],
        'SubmitOpeningBalanceBatch' => [fn (array $f) => app(SubmitOpeningBalanceBatch::class)
            ->handle($f['batch'], $f['maker']), 'opening-balance batch'],

        'ApproveCreditNote' => [fn (array $f) => app(ApproveCreditNote::class)
            ->handle($f['creditNote'], $f['checker']), 'credit note'],
        'RejectCreditNote' => [fn (array $f) => app(RejectCreditNote::class)
            ->handle($f['creditNote'], $f['checker'], 'no'), 'credit note'],
        'ApproveVoidRequest' => [fn (array $f) => app(ApproveVoidRequest::class)
            ->handle($f['voidRequest'], $f['checker']), 'void request'],
        'RejectVoidRequest' => [fn (array $f) => app(RejectVoidRequest::class)
            ->handle($f['voidRequest'], $f['checker'], 'no'), 'void request'],
        'ApproveFeeScheduleChange' => [fn (array $f) => app(ApproveFeeScheduleChange::class)
            ->handle($f['scheduleChange'], $f['checker']), 'fee-schedule change'],
        'RejectFeeScheduleChange' => [fn (array $f) => app(RejectFeeScheduleChange::class)
            ->handle($f['scheduleChange'], 'no', $f['checker']), 'fee-schedule change'],
        'ApproveDiscountPolicyChange' => [fn (array $f) => app(ApproveDiscountPolicyChange::class)
            ->handle($f['policyChange'], $f['checker']), 'discount-policy change'],
        'RejectDiscountPolicyChange' => [fn (array $f) => app(RejectDiscountPolicyChange::class)
            ->handle($f['policyChange'], 'no', $f['checker']), 'discount-policy change'],
        'ApproveOpeningBalanceBatch' => [fn (array $f) => app(ApproveOpeningBalanceBatch::class)
            ->handle($f['batch'], $f['checker']), 'opening-balance batch'],
        'RejectOpeningBalanceBatch' => [fn (array $f) => app(RejectOpeningBalanceBatch::class)
            ->handle($f['batch'], 'no', $f['checker']), 'opening-balance batch'],
    ];
}

dataset('finance governance actions', fn () => array_map(
    fn (string $name) => [$name],
    array_keys(scgActions()),
));

// ── CASE 1 — NO CONTEXT AT ALL ───────────────────────────────────────────────────────────────────

it('refuses to act with NO active School context', function (string $action) {
    $fixture = scgFixture();
    [$invoke] = scgActions()[$action];

    // The precondition, asserted: outside runFor and with nobody authenticated, ActiveSchool::id()
    // really is null. Without this the arm could be passing for some other reason entirely.
    expect(ActiveSchool::id())->toBeNull();

    expect(fn () => $invoke($fixture))
        ->toThrow(BusinessRuleException::class, 'No active School context');
})->with('finance governance actions');

// ── CASE 2 — A RECORD BELONGING TO ANOTHER SCHOOL ────────────────────────────────────────────────

it('refuses to act on a record belonging to ANOTHER School', function (string $action) {
    $theirs = scgFixture();
    $mine = School::factory()->create();

    [$invoke, $noun] = scgActions()[$action];

    // A VALID context that is the WRONG one. This is the case SchoolScope cannot catch: the record
    // was handed in directly, so nothing filtered it, and the reads the action makes afterwards
    // would come back empty and be reported as success.
    ActiveSchool::runFor($mine->id, function () use ($invoke, $theirs, $noun) {
        expect(fn () => $invoke($theirs))
            ->toThrow(BusinessRuleException::class, "That {$noun} belongs to another School.");
    });
})->with('finance governance actions');

// ── The list cannot silently fall behind the filesystem ──────────────────────────────────────────

it('covers EVERY Submit/Approve/Reject action in app/Finance/Actions', function () {
    // The signatures differ, so the invocations above are written out rather than derived. This is
    // what stops that list rotting: a new governance action nobody adds here fails on the day it
    // lands, not whenever someone next reads this file.
    $onDisk = collect(glob(dirname(__DIR__, 3).'/app/Finance/Actions/{Submit,Approve,Reject}*.php', GLOB_BRACE))
        ->map(fn (string $p) => basename($p, '.php'))
        ->sort()->values()->all();

    // Sorted on both sides: the map above is grouped by verb because that is how a reader thinks
    // about it, and the glob is alphabetical. The claim is COVERAGE, not ordering.
    $covered = collect(array_keys(scgActions()))->sort()->values()->all();

    expect($covered)->toBe($onDisk)
        ->and(count($onDisk))->toBe(15);
});

// ── The three pre-existing messages, byte for byte ───────────────────────────────────────────────

it('renders the three messages that already existed, unchanged', function () {
    // The brief's requirement, asserted rather than eyeballed in a diff: the guard moved into one
    // implementation and an operator must not be able to tell. A generic "That record belongs to
    // another School." would be a downgrade felt at exactly the wrong moment.
    $message = function (Closure $call): string {
        try {
            $call();
        } catch (BusinessRuleException $e) {
            return $e->getMessage();
        }

        return '(no exception)';
    };

    expect($message(fn () => SchoolContext::require('opening-balance batch', 'submitted')))
        ->toBe('No active School context: an opening-balance batch cannot be submitted.')
        ->and($message(fn () => SchoolContext::require('fee-schedule change', 'submitted')))
        ->toBe('No active School context: a fee-schedule change cannot be submitted.')
        ->and($message(fn () => SchoolContext::require('discount-policy change', 'submitted')))
        ->toBe('No active School context: a discount-policy change cannot be submitted.');

    $school = School::factory()->create();
    $other = School::factory()->create();
    $batch = ActiveSchool::runFor($school->id, fn () => OpeningBalanceBatch::create([
        'batch_reference' => 'WCBS-MSG-1', 'filename' => 'x.csv',
        'status' => OpeningBalanceBatchStatus::Validated, 'row_count' => 0, 'file_row_count' => 0,
        'control_total' => Money::fromKobo(0), 'cutover_date' => now()->toDateString(),
        'term_id' => Term::create([
            'academic_session_id' => AcademicSession::create([
                'school_id' => $school->id, 'name' => 'S', 'slug' => 'sess-'.Str::random(8), 'is_current' => true,
            ])->id,
            'school_id' => $school->id, 'name' => 'T', 'slug' => 'term-'.Str::random(8),
            'order' => 1, 'start_date' => now(), 'end_date' => now()->addMonth(), 'status' => 'active',
        ])->id,
    ]));

    ActiveSchool::runFor($other->id, function () use ($message, $batch) {
        expect($message(fn () => SchoolContext::assertOwns($batch, 'opening-balance batch', 'submitted')))
            ->toBe('That opening-balance batch belongs to another School.');
    });
});
