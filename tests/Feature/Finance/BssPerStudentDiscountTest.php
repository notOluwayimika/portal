<?php

/*
 * THE PER-STUDENT BSS DISCOUNT: a percentage from a policy, a BASE saying what it is a percentage
 * OF, and a standing award saying which student is on which policy — applied by the bulk run.
 *
 * EVERY TOTAL BELOW IS A LITERAL, DERIVED BY HAND. Not one of them is computed the way the run
 * computes it. An expectation built from Money::percentage(), or from a sum over the fixture's own
 * items, would assert that the implementation equals itself and would survive the resolver reading
 * the wrong base, the wrong percentage, or no award at all.
 *
 *     Tuition    1,000,000 minor   is_discountable = TRUE
 *     Transport    400,000 minor   is_discountable = FALSE
 *     ─────────────────────────────────────────────────────
 *     discountable base = 1,000,000     total base = 1,400,000
 *
 *     50%  on discountable  →  −500,000    →  invoice   900,000
 *     50%  on total         →  −700,000    →  invoice   700,000
 *     100% on discountable  → −1,000,000   →  invoice   400,000     (the child still owes transport)
 *     100% on total         → −1,400,000   →  invoice         0
 *     25%  on discountable  →  −250,000    →  invoice 1,150,000
 *     no award              →       —      →  invoice 1,400,000
 *
 * WHAT MAKES THE FIXTURE DISCRIMINATING, stated because a fixture whose degrees of freedom have
 * collapsed passes for the wrong reason while its name stays true:
 *
 *   - THE TWO ITEMS DIFFER IN BOTH is_discountable AND AMOUNT. A schedule whose every item is
 *     discountable gives 900,000 either way, so it is STRUCTURALLY INCAPABLE of noticing a resolver
 *     that ignores the base. Every base arm below would pass against such a schedule.
 *   - THE TWO-STUDENT ARM USES DIFFERENT PERCENTAGES, on different policies. A cohort where everyone
 *     lands on one figure cannot tell "resolved per student" from "resolved once and reused" — which
 *     is the exact defect an award map keyed wrongly would produce.
 *   - THE NO-AWARD ARM SITS IN THE SAME COHORT as an awarded student wherever it can. Asserting only
 *     that an unawarded student pays 1,400,000 would also pass if awards were ignored entirely.
 *   - THE 100%-ON-DISCOUNTABLE ARM ASSERTS 400,000, NOT "less than full". This is the case
 *     Brookstone described in words — "the child still pays for the bus" — and a >0 assertion would
 *     hold for any reduction at all.
 */

use App\Enums\ScholarshipKind;
use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\AwardStudentDiscount;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\BulkInvoiceRunOutcome;
use App\Finance\Enums\BulkInvoiceRunStatus;
use App\Finance\Enums\DiscountBase;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRunRow;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\FeeSchedule;
use App\Finance\Models\Invoice;
use App\Finance\Models\InvoiceLine;
use App\Finance\Models\StudentDiscountAward;
use App\Finance\Services\FeeScheduleLineMapper;
use App\Models\AcademicSession;
use App\Models\Activity;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\Scholarship;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * `bd` PREFIX, and the world helpers are duplicated from ScholarshipKindAndRunExclusionTest rather
 * than imported — Pest defines a test file's functions when it loads that file, so calling another
 * file's helper works only if that file happened to load first. That is a load-order dependency and
 * it fails as a collision the day both files load in one process.
 *
 * @return array{school: School, term: Term, level: ClassLevel, arm: ClassLevelArm}
 */
function bdSchool(): array
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

/**
 * The two-item schedule the whole file rests on: ONE discountable item and ONE that is not, at
 * DIFFERENT amounts. Both mandatory, or the mapper would not bill them.
 */
function bdSchedule(array $ctx): void
{
    ActiveSchool::runFor($ctx['school']->id, function () use ($ctx) {
        $schedule = app(CreateFeeSchedule::class)->handle(
            $ctx['term']->id, $ctx['level']->id, 'v1-'.Str::random(4),
            [
                [
                    'description' => 'Tuition', 'amount_minor' => 1000000, 'currency' => 'NGN',
                    'is_mandatory' => true, 'is_discountable' => true, 'sort_order' => 0,
                    'bank_account_id' => testBankAccountUuid($ctx['school']->id),
                ],
                [
                    'description' => 'Transport', 'amount_minor' => 400000, 'currency' => 'NGN',
                    'is_mandatory' => true, 'is_discountable' => false, 'sort_order' => 1,
                    'bank_account_id' => testBankAccountUuid($ctx['school']->id),
                ],
            ],
        );

        DB::table('finance_fee_schedules')->where('id', $schedule->id)
            ->update(['status' => FeeScheduleStatus::Active->value]);
    });
}

/** A percentage discount policy in $ctx's School. */
function bdPolicy(array $ctx, int $percent, DiscountBase $base, array $overrides = []): DiscountPolicy
{
    return ActiveSchool::runFor($ctx['school']->id, fn () => DiscountPolicy::create(array_merge([
        'school_id' => $ctx['school']->id,
        'name' => 'BSS '.$percent.'% '.$base->value.' '.Str::random(4),
        'basis' => 'percent',
        'percent' => $percent,
        'base' => $base,
        'requires_approval' => false,
        'status' => 'active',
    ], $overrides)));
}

/** A scholarship in $ctx's School. `$kind` null is the unconfigured backfill state. */
function bdScholarship(array $ctx, ?ScholarshipKind $kind = ScholarshipKind::Discount): Scholarship
{
    return ActiveSchool::runFor($ctx['school']->id, fn () => Scholarship::create([
        'school_id' => $ctx['school']->id,
        'name' => 'Scheme '.Str::random(6),
        'kind' => $kind,
    ]));
}

/** A student with one ACTIVE enrollment at $ctx's coordinates, optionally holding a scholarship. */
function bdStudent(array $ctx, ?Scholarship $scholarship = null): Student
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $scholarship) {
        $student = Student::factory()->create([
            'school_id' => $ctx['school']->id,
            'admission_number' => 'ADM-'.Str::random(8),
            'scholarship_id' => $scholarship?->id,
        ]);

        StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $ctx['school']->id,
            'curriculum_id' => Curriculum::factory()->create([
                'school_id' => $ctx['school']->id,
                'class_level_arm_id' => $ctx['arm']->id,
                'term_id' => $ctx['term']->id,
            ])->id,
            'status' => StudentStatusEnum::ACTIVE,
        ]);

        return $student;
    });
}

/** Award $policy to $student through the Action under test. */
function bdAward(array $ctx, Student $student, DiscountPolicy $policy, ?int $actorId = null): StudentDiscountAward
{
    return ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => app(AwardStudentDiscount::class)->handle($student->id, $policy->id, $actorId),
    );
}

/** Insert the pending run row and dispatch. NO ambient context — SchoolAware must supply it. */
function bdRun(array $ctx): BulkInvoiceRun
{
    $run = ActiveSchool::runFor($ctx['school']->id, fn () => BulkInvoiceRun::create([
        'school_id' => $ctx['school']->id,
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
        'status' => BulkInvoiceRunStatus::Pending,
    ]));

    ProcessBulkInvoiceRun::dispatch($run->id, $ctx['school']->id);

    return $run->refresh();
}

/** The one invoice raised for $student, with its lines. Fails loudly if there is not exactly one. */
function bdInvoice(Student $student): Invoice
{
    $invoices = Invoice::withoutGlobalScopes()->where('student_id', $student->id)->with('lines')->get();

    expect($invoices)->toHaveCount(1, "student#{$student->id} should have exactly one invoice");

    return $invoices->first();
}

/**
 * THE BURSAR'S PATH: the same charge lines, plus one percentage reduction spec, straight into
 * GenerateInvoice — no award, no run.
 *
 * IT MAPS THE SCHEDULE rather than typing free text, and that is not a shortcut. A free-text charge
 * line carries no `feeItemId`, so `resolveDiscountability()` leaves it discountable and BOTH bases
 * collapse onto 1,400,000 — the fixture would stop being able to tell them apart, and every arm
 * built on it would pass for the wrong reason.
 *
 * $claimed is what the CALLER asserts the base to be. It is the plant: the Action must overwrite it
 * from the cited policy, whichever way it points.
 */
function bdGenerate(array $ctx, Student $student, DiscountPolicy $policy, ?DiscountBase $claimed): Invoice
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $student, $policy, $claimed) {
        $schedule = FeeSchedule::query()
            ->where('term_id', $ctx['term']->id)
            ->where('class_level_id', $ctx['level']->id)
            ->where('status', FeeScheduleStatus::Active->value)
            ->firstOrFail();

        $lines = app(FeeScheduleLineMapper::class)->linesFor($schedule, $ctx['school']->id);
        $lines[] = new InvoiceLineSpec(
            description: $policy->name,
            amount: null,
            feeItemId: null,
            kind: InvoiceLineKind::Discount,
            note: null,
            percent: $policy->percent,
            discountPolicyId: $policy->id,
            isDiscountable: true,
            percentBase: $claimed,
        );

        $enrollment = StudentCurriculum::withoutGlobalScopes()
            ->where('student_id', $student->id)->firstOrFail();

        return app(GenerateInvoice::class)->handle((string) $enrollment->uuid, $lines, InvoiceKind::Scheduled);
    });
}

/** The reduction lines on an invoice — everything that is not a charge. */
function bdReductions(Invoice $invoice): array
{
    return $invoice->lines
        ->filter(fn (InvoiceLine $line) => $line->kind !== InvoiceLineKind::Charge)
        ->values()
        ->all();
}

/*
|--------------------------------------------------------------------------
| 1 — the BASE, and the four figures it produces
|--------------------------------------------------------------------------
*/

it('50% on the DISCOUNTABLE base reduces only the discountable items, leaving transport whole', function () {
    $ctx = bdSchool();
    bdSchedule($ctx);

    $awarded = bdStudent($ctx, bdScholarship($ctx));
    bdAward($ctx, $awarded, bdPolicy($ctx, 50, DiscountBase::Discountable));

    // The plain student in the SAME cohort: without them, an "awards are ignored entirely"
    // implementation and a correct one are not distinguishable by this arm.
    $plain = bdStudent($ctx);

    bdRun($ctx);

    // 1,000,000 + 400,000 − 500,000. Hand-derived; NOT Money::percentage() of anything.
    expect(bdInvoice($awarded)->total->toKobo())->toBe(900000)
        ->and(bdInvoice($plain)->total->toKobo())->toBe(1400000);

    // And the transport charge itself is untouched — the reduction is a separate line, never an
    // edit to a charge.
    $charges = bdInvoice($awarded)->lines
        ->filter(fn (InvoiceLine $l) => $l->kind === InvoiceLineKind::Charge)
        ->mapWithKeys(fn (InvoiceLine $l) => [$l->description => $l->amount->toKobo()]);

    expect($charges['Tuition'])->toBe(1000000)->and($charges['Transport'])->toBe(400000);
});

it('50% on the TOTAL base reduces everything — a DIFFERENT figure from the same percentage', function () {
    $ctx = bdSchool();
    bdSchedule($ctx);

    $awarded = bdStudent($ctx, bdScholarship($ctx));
    bdAward($ctx, $awarded, bdPolicy($ctx, 50, DiscountBase::Total));

    bdRun($ctx);

    // 1,400,000 − 700,000. The same 50% that gave 900,000 above: this pair is the whole proof that
    // `base` is read at all, and it is why the fixture's two items differ in is_discountable.
    expect(bdInvoice($awarded)->total->toKobo())->toBe(700000);
    expect(bdReductions(bdInvoice($awarded))[0]->amount->toKobo())->toBe(-700000);
});

it('100% on the DISCOUNTABLE base still leaves the child owing the non-discountable item', function () {
    $ctx = bdSchool();
    bdSchedule($ctx);

    $awarded = bdStudent($ctx, bdScholarship($ctx));
    bdAward($ctx, $awarded, bdPolicy($ctx, 100, DiscountBase::Discountable));

    bdRun($ctx);

    // The case Brookstone described in words: tuition fully waived, the bus still billed.
    expect(bdInvoice($awarded)->total->toKobo())->toBe(400000);
    expect(bdReductions(bdInvoice($awarded))[0]->amount->toKobo())->toBe(-1000000);
});

it('100% on the TOTAL base produces a ZERO-total invoice, and everything downstream accepts it', function () {
    $ctx = bdSchool();
    bdSchedule($ctx);

    $awarded = bdStudent($ctx, bdScholarship($ctx));
    bdAward($ctx, $awarded, bdPolicy($ctx, 100, DiscountBase::Total));

    $run = bdRun($ctx);

    $invoice = bdInvoice($awarded);

    // The invoice EXISTS and is zero — not refused, not negative. GenerateInvoice refuses only a
    // NEGATIVE total ("Reductions may bring a total to zero, but never below it").
    expect($invoice->total->toKobo())->toBe(0)
        ->and($invoice->lines)->toHaveCount(3);

    // The run counts it as BILLED, not failed. A zero invoice is a real bill for a fully-sponsored
    // term, and a run that quietly reclassified it would hide the child from every arrears screen.
    expect(BulkInvoiceRunRow::withoutGlobalScopes()
        ->where('run_id', $run->id)->where('student_id', $awarded->id)->value('outcome'))
        ->toBe(BulkInvoiceRunOutcome::Billed);

    // AND THE LEDGER TOOK A ZERO CHARGE. Asserted rather than assumed: nothing in
    // finance_ledger_transactions refuses a zero amount, so the row is written and the student's
    // balance is 0. Recorded here so a future positivity constraint on the ledger has to confront
    // this arm rather than discovering it in production on a 100% scholarship.
    $ledger = DB::table('finance_ledger_transactions')
        ->where('source_type', 'invoice')->where('source_id', $invoice->id)->get();

    expect($ledger)->toHaveCount(1)
        ->and((int) $ledger->first()->amount_minor)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| 2 — per STUDENT, not per run
|--------------------------------------------------------------------------
*/

it('two students in one cohort on DIFFERENT percentages each get their own figure', function () {
    $ctx = bdSchool();
    bdSchedule($ctx);

    $half = bdStudent($ctx, bdScholarship($ctx));
    $quarter = bdStudent($ctx, bdScholarship($ctx));
    $plain = bdStudent($ctx);

    bdAward($ctx, $half, bdPolicy($ctx, 50, DiscountBase::Discountable));
    bdAward($ctx, $quarter, bdPolicy($ctx, 25, DiscountBase::Discountable));

    bdRun($ctx);

    // Three different literals from one run. An award resolved once and reused would give all three
    // students the same total, whichever one it happened to read first.
    expect(bdInvoice($half)->total->toKobo())->toBe(900000)
        ->and(bdInvoice($quarter)->total->toKobo())->toBe(1150000)
        ->and(bdInvoice($plain)->total->toKobo())->toBe(1400000);
});

it('a student with no award is billed EXACTLY as before this commit — no reduction line at all', function () {
    $ctx = bdSchool();
    bdSchedule($ctx);

    $plain = bdStudent($ctx);
    $onDiscountScholarshipButUnawarded = bdStudent($ctx, bdScholarship($ctx));

    bdRun($ctx);

    foreach ([$plain, $onDiscountScholarshipButUnawarded] as $student) {
        $invoice = bdInvoice($student);

        expect($invoice->total->toKobo())->toBe(1400000)
            ->and($invoice->lines)->toHaveCount(2)
            ->and(bdReductions($invoice))->toBe([]);
    }
});

it('an awarded invoice carries EXACTLY ONE reduction line, citing its policy', function () {
    $ctx = bdSchool();
    bdSchedule($ctx);

    $policy = bdPolicy($ctx, 50, DiscountBase::Discountable);
    $awarded = bdStudent($ctx, bdScholarship($ctx));
    bdAward($ctx, $awarded, $policy);

    bdRun($ctx);

    $invoice = bdInvoice($awarded);
    $reductions = bdReductions($invoice);

    expect($reductions)->toHaveCount(1)
        ->and($reductions[0]->kind)->toBe(InvoiceLineKind::Discount)
        ->and((int) $reductions[0]->discount_policy_id)->toBe($policy->id)
        ->and($reductions[0]->description)->toBe($policy->name)
        // The STORED line is the resolved naira figure, never "50%" — snapshot integrity.
        ->and($reductions[0]->amount->toKobo())->toBe(-500000);

    // And no charge line acquired a policy on the way through (the reduction guard's other half).
    expect($invoice->lines->filter(fn (InvoiceLine $l) => $l->kind === InvoiceLineKind::Charge)
        ->pluck('discount_policy_id')->unique()->all())->toBe([null]);
});

/*
|--------------------------------------------------------------------------
| 3 — the award is refused AT AWARD TIME, not at bill time
|--------------------------------------------------------------------------
*/

it('an award naming an INACTIVE policy is refused when the award is made', function () {
    $ctx = bdSchool();
    $student = bdStudent($ctx, bdScholarship($ctx));

    $policy = bdPolicy($ctx, 50, DiscountBase::Discountable);
    // `status` is the ONE column the policy update guard permits to move.
    ActiveSchool::runFor($ctx['school']->id, fn () => DB::table('finance_discount_policies')
        ->where('id', $policy->id)->update(['status' => 'retired']));

    expect(fn () => bdAward($ctx, $student, $policy->fresh()))
        ->toThrow(BusinessRuleException::class, 'only an active policy may be awarded');

    // Nothing was written: a refusal that leaves a row behind is not a refusal.
    expect(StudentDiscountAward::withoutGlobalScopes()->count())->toBe(0);
});

it('an award naming a REQUIRES_APPROVAL policy is refused when the award is made', function () {
    $ctx = bdSchool();
    $student = bdStudent($ctx, bdScholarship($ctx));
    $policy = bdPolicy($ctx, 50, DiscountBase::Discountable, ['requires_approval' => true]);

    expect(fn () => bdAward($ctx, $student, $policy))
        ->toThrow(BusinessRuleException::class, 'requires per-application approval');

    expect(StudentDiscountAward::withoutGlobalScopes()->count())->toBe(0);
});

it('an award naming ANOTHER SCHOOL policy is refused, and the composite FK refuses it below that', function () {
    $ctx = bdSchool();
    $other = bdSchool();

    $student = bdStudent($ctx, bdScholarship($ctx));
    $foreign = bdPolicy($other, 50, DiscountBase::Discountable);

    expect(fn () => bdAward($ctx, $student, $foreign))
        ->toThrow(BusinessRuleException::class, 'belongs to another School');

    // THE ACTION IS NOT THE GUARANTEE. A raw insert bypassing it hits
    // finance_student_discount_awards_policy_school_foreign — the isolation is at the engine.
    expect(fn () => DB::table('finance_student_discount_awards')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $ctx['school']->id,
        'student_id' => $student->id,
        'discount_policy_id' => $foreign->id,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('an award on a SPONSORED student is refused — it could never be applied', function () {
    $ctx = bdSchool();
    $student = bdStudent($ctx, bdScholarship($ctx, ScholarshipKind::Sponsored));

    expect(fn () => bdAward($ctx, $student, bdPolicy($ctx, 50, DiscountBase::Discountable)))
        ->toThrow(BusinessRuleException::class, 'may only be made against a discount scholarship');

    expect(StudentDiscountAward::withoutGlobalScopes()->count())->toBe(0);
});

it('an award on an UNCONFIGURED scholarship, and on NO scholarship, are both refused', function () {
    $ctx = bdSchool();
    $policy = bdPolicy($ctx, 50, DiscountBase::Discountable);

    $unconfigured = bdStudent($ctx, bdScholarship($ctx, null));
    $none = bdStudent($ctx);

    expect(fn () => bdAward($ctx, $unconfigured, $policy))
        ->toThrow(BusinessRuleException::class, 'not configured yet');

    expect(fn () => bdAward($ctx, $none, $policy))
        ->toThrow(BusinessRuleException::class, 'holds no scholarship in this School');

    expect(StudentDiscountAward::withoutGlobalScopes()->count())->toBe(0);
});

it('an award naming a FIXED-AMOUNT policy is refused — it has no percentage to apply', function () {
    $ctx = bdSchool();
    $student = bdStudent($ctx, bdScholarship($ctx));

    $amountPolicy = ActiveSchool::runFor($ctx['school']->id, fn () => DiscountPolicy::create([
        'school_id' => $ctx['school']->id, 'name' => 'Flat '.Str::random(4),
        'basis' => 'amount', 'value_minor' => 50000, 'value_currency' => 'NGN',
        'requires_approval' => false, 'status' => 'active',
    ]));

    // Without this refusal the award stores fine and detonates INSIDE the run: the spec would carry
    // neither an amount nor a percentage and resolvedAmount() would raise a LogicException.
    expect(fn () => bdAward($ctx, $student, $amountPolicy))
        ->toThrow(BusinessRuleException::class, 'fixed-amount policy');
});

it('one award per student — the second is refused, and the UNIQUE holds under a raw write', function () {
    $ctx = bdSchool();
    $student = bdStudent($ctx, bdScholarship($ctx));

    bdAward($ctx, $student, bdPolicy($ctx, 50, DiscountBase::Discountable));

    expect(fn () => bdAward($ctx, $student, bdPolicy($ctx, 25, DiscountBase::Discountable)))
        ->toThrow(BusinessRuleException::class, 'already has a discount award');

    expect(fn () => DB::table('finance_student_discount_awards')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $ctx['school']->id,
        'student_id' => $student->id,
        'discount_policy_id' => bdPolicy($ctx, 10, DiscountBase::Discountable)->id,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| 4 — the DTO invariant and the column domain
|--------------------------------------------------------------------------
*/

it('an InvoiceLineSpec refuses a base with no percentage, in the caller stack frame', function () {
    expect(fn () => new InvoiceLineSpec(
        description: 'Tuition',
        amount: Money::fromKobo(1000, 'NGN'),
        percentBase: DiscountBase::Total,
    ))->toThrow(LogicException::class, 'discount base with no percentage');
});

it('a percentage with NO base behaves exactly as it did before the axis existed', function () {
    $spec = new InvoiceLineSpec(
        description: 'Waiver', amount: null, kind: InvoiceLineKind::Waiver, percent: 50,
    );

    expect($spec->percentBase)->toBeNull()
        ->and($spec->percentBase())->toBe(DiscountBase::Discountable);
});

it('finance_discount_policies.base rejects a value outside the domain, case-sensitively', function () {
    $ctx = bdSchool();

    foreach (['whole', 'Total', 'TOTAL', ''] as $bad) {
        expect(fn () => DB::table('finance_discount_policies')->insert([
            'uuid' => (string) Str::uuid(), 'school_id' => $ctx['school']->id,
            'name' => 'P'.Str::random(6), 'basis' => 'percent', 'percent' => 10,
            'base' => $bad, 'requires_approval' => false, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class, 'base must be discountable or total', "[{$bad}] was accepted");
    }
});

it('base is a policy TERM and is immutable, while status still moves', function () {
    $ctx = bdSchool();
    $policy = bdPolicy($ctx, 50, DiscountBase::Discountable);

    expect(fn () => DB::table('finance_discount_policies')->where('id', $policy->id)
        ->update(['base' => 'total']))
        ->toThrow(QueryException::class, 'base is a policy term and is immutable');

    // The one permitted movement is untouched by the new trigger.
    DB::table('finance_discount_policies')->where('id', $policy->id)->update(['status' => 'retired']);
    expect(DB::table('finance_discount_policies')->where('id', $policy->id)->value('status'))->toBe('retired');
});

it('the migration BACKFILL statement does not trip the pre-existing immutable-terms guard', function () {
    $ctx = bdSchool();
    $policy = bdPolicy($ctx, 50, DiscountBase::Discountable);

    // THE EXACT SHAPE THE MIGRATION RUNS — a base-only SET — replayed against a live row.
    DB::update("UPDATE finance_discount_policies SET base = 'discountable' WHERE id = ?", [$policy->id]);

    expect(DB::table('finance_discount_policies')->where('id', $policy->id)->value('base'))
        ->toBe('discountable');

    // AND HERE IS WHY THAT SILENCE MEANS SOMETHING. A statement that no trigger ever saw is silent
    // too, and this table's BEFORE UPDATE triggers are exactly what the arm claims to have
    // exercised. So the SAME statement shape, with a DIFFERENT value, must be REFUSED — which it
    // can only be if a BEFORE UPDATE trigger read a base-only SET. Without this half the arm above
    // is a fixture whose degrees of freedom have collapsed: it would pass just as happily against a
    // table with no triggers at all.
    expect(fn () => DB::update(
        "UPDATE finance_discount_policies SET base = 'total' WHERE id = ?", [$policy->id],
    ))->toThrow(QueryException::class);

    // Named, so the arm says WHICH trigger refused it: the new base one. The pre-existing
    // finance_discount_policies_update_guard never mentions `base` and is not being widened.
    try {
        DB::update("UPDATE finance_discount_policies SET base = 'total' WHERE id = ?", [$policy->id]);
        expect(false)->toBeTrue('a base-only UPDATE that CHANGES the value must be refused');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('base is a policy term and is immutable');
    }
});

/*
|--------------------------------------------------------------------------
| 5 — what happens when a policy moves AFTER the award
|--------------------------------------------------------------------------
*/

it('a policy retired AFTER the award fails that ONE student loudly, and never bills them full price', function () {
    $ctx = bdSchool();
    bdSchedule($ctx);

    $policy = bdPolicy($ctx, 50, DiscountBase::Discountable);
    $awarded = bdStudent($ctx, bdScholarship($ctx));
    bdAward($ctx, $awarded, $policy);

    $plain = bdStudent($ctx);

    ActiveSchool::runFor($ctx['school']->id, fn () => DB::table('finance_discount_policies')
        ->where('id', $policy->id)->update(['status' => 'retired']));

    $run = bdRun($ctx);

    // THE SILENT-OVERCHARGE SHAPE IS THE ONE THING THIS MUST NOT DO. Filtering retired policies out
    // of the award map would have billed this child 1,400,000 on a run reporting success.
    expect(Invoice::withoutGlobalScopes()->where('student_id', $awarded->id)->count())->toBe(0);

    $row = BulkInvoiceRunRow::withoutGlobalScopes()
        ->where('run_id', $run->id)->where('student_id', $awarded->id)->first();

    expect($row->outcome)->toBe(BulkInvoiceRunOutcome::Failed)
        ->and($row->reason)->toContain('not active');

    // And ONE bad award does not abort the run: the rest of the cohort is billed.
    expect(bdInvoice($plain)->total->toKobo())->toBe(1400000);
});

/*
|--------------------------------------------------------------------------
| 6 — the governance path carries the base (cold review, finding 1)
|--------------------------------------------------------------------------
|
| ApproveDiscountPolicyChange is the ONLY sanctioned writer of the catalog. Before the amend below
| worked, `base` was absent from BOTH whitelists between the wire and that row — SubmitDiscountPolicy
| Change's `$proposed` and insertPolicy()'s create array — so a school could not author a `total`
| policy at all, and amending one superseded it into a replacement that had fallen back to
| `discountable`. 50% of the whole bill became 50% of tuition: the family billed MORE, through the
| flow whose purpose is that terms cannot move without a checker.
*/

/**
 * A maker and a checker in $ctx's School, on the SEEDED roles rather than ad-hoc ones — the same
 * `accounts_officer` / `executive_director` split DiscountPolicyTest uses, so these arms exercise the
 * real duty separation rather than a permission pair invented for the test. The seed is per-arm
 * because the rest of this file needs no RBAC at all and paying for it everywhere would be waste.
 *
 * @return array{0: User, 1: User}
 */
function bdGovernance(array $ctx): array
{
    test()->seed(DatabaseSeeder::class);

    $make = function (string $role) use ($ctx): User {
        $user = User::factory()->create(['school_id' => $ctx['school']->id]);
        $user->grantSchoolAccess($ctx['school'], $role);
        $user->flushSchoolAccessCache();

        return $user;
    };

    return [$make('accounts_officer'), $make('executive_director')];
}

/** Submit a change and approve it, both over HTTP — the real governance path, not the Actions. */
function bdGovern(array $ctx, User $maker, User $checker, array $payload): DiscountPolicy
{
    $id = test()->actingAs($maker)->withSession(['school_id' => $ctx['school']->id])
        ->postJson('/api/v1/finance/discount-policy-changes', $payload)
        ->assertCreated()->json('id');

    test()->actingAs($checker)->withSession(['school_id' => $ctx['school']->id])
        ->postJson("/api/v1/finance/discount-policy-changes/{$id}/approve")
        ->assertOk();

    return DiscountPolicy::withoutGlobalScopes()
        ->where('school_id', $ctx['school']->id)
        ->where('status', 'active')
        ->latest('id')->firstOrFail();
}

it('a school can AUTHOR a `total` policy through the governance path at all', function () {
    $ctx = bdSchool();
    [$maker, $checker] = bdGovernance($ctx);

    $policy = bdGovern($ctx, $maker, $checker, [
        'kind' => 'create', 'name' => 'Whole bill', 'basis' => 'percent', 'percent' => 50,
        'base' => 'total', 'reason' => 'BSS scheme',
    ]);

    // Before `base` reached the change table and both whitelists, this was `discountable` — the axis
    // had a reader and no writer, so the feature was not deliverable by any route a school has.
    expect($policy->base)->toBe(DiscountBase::Total);
});

it('AMENDING a `total` policy PRESERVES total — stated or not', function () {
    $ctx = bdSchool();
    [$maker, $checker] = bdGovernance($ctx);

    $v1 = bdGovern($ctx, $maker, $checker, [
        'kind' => 'create', 'name' => 'Whole bill', 'basis' => 'percent', 'percent' => 50,
        'base' => 'total', 'reason' => 'BSS scheme',
    ]);
    expect($v1->base)->toBe(DiscountBase::Total);

    // THE MAKER DOES NOT MENTION THE BASE. This is the realistic shape of the mistake — they are
    // raising a rate, not changing what it applies to — and it is the arm that would have gone red:
    // the superseding row fell to the column DEFAULT and the family's bill went UP.
    $v2 = bdGovern($ctx, $maker, $checker, [
        'kind' => 'amend', 'target' => $v1->uuid, 'name' => 'Whole bill', 'basis' => 'percent',
        'percent' => 55, 'reason' => 'raise the rate',
    ]);

    expect($v2->base)->toBe(DiscountBase::Total)
        ->and($v2->percent)->toBe(55)
        ->and((int) $v2->supersedes_policy_id)->toBe($v1->id)
        ->and($v1->fresh()->status->value)->toBe('superseded');

    // And a maker who DOES want to narrow it says so, and is obeyed — inheritance is a default, not
    // a lock. Without this half the arm above would also pass against an implementation that ignored
    // the maker entirely and always copied the target.
    $v3 = bdGovern($ctx, $maker, $checker, [
        'kind' => 'amend', 'target' => $v2->uuid, 'name' => 'Whole bill', 'basis' => 'percent',
        'percent' => 55, 'base' => 'discountable', 'reason' => 'narrow to tuition',
    ]);

    expect($v3->base)->toBe(DiscountBase::Discountable);
});

it('the change table refuses a base outside the domain, and freezes it once submitted', function () {
    $ctx = bdSchool();
    [$maker, $checker] = bdGovernance($ctx);

    foreach (['whole', 'Total', 'TOTAL'] as $bad) {
        expect(fn () => DB::table('finance_discount_policy_changes')->insert([
            'uuid' => (string) Str::uuid(), 'school_id' => $ctx['school']->id, 'kind' => 'create',
            'name' => 'P'.Str::random(6), 'basis' => 'percent', 'percent' => 10, 'base' => $bad,
            'requires_approval' => false, 'reason' => 'r', 'status' => 'submitted',
            'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class, 'base must be discountable or total', "[{$bad}] was accepted");
    }

    $id = $this->actingAs($maker)->withSession(['school_id' => $ctx['school']->id])
        ->postJson('/api/v1/finance/discount-policy-changes', [
            'kind' => 'create', 'name' => 'Frozen', 'basis' => 'percent', 'percent' => 50,
            'base' => 'total', 'reason' => 'r',
        ])->assertCreated()->json('id');

    // A proposed term a maker could rewrite after approval is the defect the sibling update guard
    // was written for; `base` arrived after that guard and must not escape through the new column.
    expect(fn () => DB::table('finance_discount_policy_changes')->where('uuid', $id)
        ->update(['base' => 'discountable']))
        ->toThrow(QueryException::class, 'base is a proposed term and is frozen once submitted');
});

/*
 * CROSSING THE BASIS AXIS — the arms the percent→percent arm above is structurally incapable of
 * seeing. That arm pins ONE hop between two policies of the same basis, so an implementation that
 * inherits unconditionally and one that inherits only within a basis are indistinguishable to it.
 * The distinguishing fixture is a ROUND TRIP through `amount`, and it is not hypothetical: rule 58
 * of SubmitDiscountPolicyChangeRequest is `prohibited_if:basis,amount`, so on the outbound hop the
 * maker CANNOT state a base and on the return hop they need not — two changes, no base typed on
 * either, no base shown on either screen, and a live percentage at the end of it.
 */
it('a percent→AMOUNT amend does NOT carry the base across, and the round trip back to percent lands on the default', function () {
    $ctx = bdSchool();
    [$maker, $checker] = bdGovernance($ctx);

    $percent = bdGovern($ctx, $maker, $checker, [
        'kind' => 'create', 'name' => 'Round trip', 'basis' => 'percent', 'percent' => 50,
        'base' => 'total', 'reason' => 'BSS scheme',
    ]);
    expect($percent->base)->toBe(DiscountBase::Total);

    // (i) THE OUTBOUND HOP. `base` is not merely unstated — rule 58 REFUSES it on an amount basis,
    // so there is no payload that states it. Inheriting here stamps the amount policy with a value
    // that is inert, that no maker typed and that no checker was shown.
    $amount = bdGovern($ctx, $maker, $checker, [
        'kind' => 'amend', 'target' => $percent->uuid, 'name' => 'Round trip', 'basis' => 'amount',
        'value_minor' => 500000, 'value_currency' => 'NGN', 'reason' => 'switch to a flat sum',
    ]);

    expect($amount->basis->value)->toBe('amount')
        ->and($amount->base)->toBe(DiscountBase::Discountable)
        ->and((int) $amount->supersedes_policy_id)->toBe($percent->id);

    // (ii) THE RETURN HOP, AND THE ONE THAT SPENDS MONEY. Under an unconditional inherit the
    // `total` set on the FIRST policy reaches this one through a row where it meant nothing, and
    // `base` is immutable on the catalog, so 25% of the whole bill cannot be put back to 25% of
    // tuition except by another amend. The maker typed nothing on either hop.
    $back = bdGovern($ctx, $maker, $checker, [
        'kind' => 'amend', 'target' => $amount->uuid, 'name' => 'Round trip', 'basis' => 'percent',
        'percent' => 25, 'reason' => 'back to a rate',
    ]);

    expect($back->basis->value)->toBe('percent')
        ->and($back->percent)->toBe(25)
        ->and($back->base)->toBe(DiscountBase::Discountable)
        ->and((int) $back->supersedes_policy_id)->toBe($amount->id);
});

/*
 * (iii) THE MUTATION GUARD ON THE ARM ABOVE. Without this, the round-trip arm also passes against
 * an implementation that ignores the maker entirely and hardcodes `discountable` on any cross-basis
 * amend — the same shape of hole the percent→percent arm closes with its third hop. The default is a
 * DEFAULT: a maker reshaping a policy gets it, and may say otherwise on the same change.
 */
it('a maker converting a policy BACK to percent may state the base, and is obeyed', function () {
    $ctx = bdSchool();
    [$maker, $checker] = bdGovernance($ctx);

    $percent = bdGovern($ctx, $maker, $checker, [
        'kind' => 'create', 'name' => 'Stated on return', 'basis' => 'percent', 'percent' => 50,
        'base' => 'total', 'reason' => 'BSS scheme',
    ]);

    $amount = bdGovern($ctx, $maker, $checker, [
        'kind' => 'amend', 'target' => $percent->uuid, 'name' => 'Stated on return',
        'basis' => 'amount', 'value_minor' => 500000, 'value_currency' => 'NGN',
        'reason' => 'switch to a flat sum',
    ]);
    expect($amount->base)->toBe(DiscountBase::Discountable);

    $back = bdGovern($ctx, $maker, $checker, [
        'kind' => 'amend', 'target' => $amount->uuid, 'name' => 'Stated on return',
        'basis' => 'percent', 'percent' => 25, 'base' => 'total', 'reason' => 'whole bill again',
    ]);

    expect($back->base)->toBe(DiscountBase::Total);
});

/*
 * (v) THE CHECKER'S VIEW, ASSERTED ON THE HTTP RESPONSE rather than on the Resource in isolation.
 * The Resource is only half the claim: a key it emits that no route reaches, or that the queue
 * query cannot populate, is a term still decided unseen. This goes through the pending queue as the
 * ED, which is the screen where the decision is actually taken.
 *
 * THE FIXTURE IS THE ORDINARY AMEND — a rate raised, the base unmentioned — because that is exactly
 * where the raw `base` column is NULL and where an unlabelled "55%" could mean either of two
 * different amounts of money. The raw key is asserted NULL in the same breath: without that half
 * this arm would also pass against a Resource that had simply started requiring the maker to state
 * a base, which is a different (and weaker) design.
 */
it('the checker’s pending queue shows the EFFECTIVE base of an amend that states none', function () {
    $ctx = bdSchool();
    [$maker, $checker] = bdGovernance($ctx);

    $v1 = bdGovern($ctx, $maker, $checker, [
        'kind' => 'create', 'name' => 'Seen by the checker', 'basis' => 'percent', 'percent' => 50,
        'base' => 'total', 'reason' => 'BSS scheme',
    ]);
    expect($v1->base)->toBe(DiscountBase::Total);

    $changeId = $this->actingAs($maker)->withSession(['school_id' => $ctx['school']->id])
        ->postJson('/api/v1/finance/discount-policy-changes', [
            'kind' => 'amend', 'target' => $v1->uuid, 'name' => 'Seen by the checker',
            'basis' => 'percent', 'percent' => 55, 'reason' => 'raise the rate',
        ])->assertCreated()->json('id');

    $queue = collect(
        $this->actingAs($checker)->withSession(['school_id' => $ctx['school']->id])
            ->getJson('/api/v1/finance/discount-policy-changes/pending')
            ->assertOk()->json('data')
    );

    // THE PRESENCE CHECK IS POSITIVE, AND IT PRINTS WHAT THE QUEUE ACTUALLY HELD.
    //
    // `expect($row)->not->toBeNull($message)` is the obvious form and is the one
    // PestNegatedExpectationMessagesTest exists to refuse: `->not->` runs the POSITIVE assertion,
    // and when that succeeds it discards the exception and composes its own sentence out of
    // shortened-exported arguments — so the sentence written there is never the failure description
    // and is truncated mid-string. On a POSITIVE matcher the same `$message` IS the description and
    // prints whole, which is the whole of the fix.
    //
    // WHY NOT `->toContain($changeId)`, WHICH IS THE TIDIER LINE. MEASURED, not assumed: PHPUnit's
    // TraversableContains exports only the NEEDLE, so its failure reads `Failed asserting that an
    // array contains '<uuid>'.` and the haystack never appears. That is strictly less than the
    // discarded sentence carried, and it cannot answer the question a reader hitting this line has
    // — was the change ABSENT, or present under a DIFFERENT id? The ids go in the message because
    // that is the only channel that prints them.
    //
    // Not `toBe([$changeId])` either, which would print a full array diff: it would assert the queue
    // holds nothing ELSE, a stronger claim than this arm is about and one that reds on any future
    // fixture that submits a second change.
    $queuedIds = $queue->pluck('id')->all();

    expect(in_array($changeId, $queuedIds, true))->toBeTrue(
        'the submitted change was absent from the checker’s queue; it held: '
        .(count($queuedIds) === 0 ? '(nothing)' : implode(', ', $queuedIds))
    );

    $row = $queue->firstWhere('id', $changeId);

    // The proposed term IS absent — this is the case the inheritance exists for, and the case a
    // screen rendering only `base` shows nothing at all in.
    expect($row)->toHaveKey('base')
        ->and($row['base'])->toBeNull();

    // And the value the catalog will actually be stamped with is on the wire under its own key.
    expect($row)->toHaveKey('effective_base')
        ->and($row['effective_base'])->toBe('total');

    // The rate it qualifies arrives beside it, so the queue can render one phrase rather than two
    // fields a checker has to combine themselves.
    expect($row['percent'])->toBe(55)
        ->and($row['basis'])->toBe('percent');

    // NOT VACUOUS ON THE VALUE: approve it and the catalog receives the same base the checker was
    // shown. Without this the arm pins a key that agrees with nothing.
    $this->actingAs($checker)->withSession(['school_id' => $ctx['school']->id])
        ->postJson("/api/v1/finance/discount-policy-changes/{$changeId}/approve")
        ->assertOk();

    $v2 = DiscountPolicy::withoutGlobalScopes()
        ->where('school_id', $ctx['school']->id)->where('status', 'active')
        ->latest('id')->firstOrFail();

    expect($v2->base->value)->toBe($row['effective_base']);
});

/*
|--------------------------------------------------------------------------
| 7 — the base is resolved from the POLICY, so the two apply paths agree
|--------------------------------------------------------------------------
*/

it('a caller-supplied base is IGNORED — the cited policy decides', function () {
    $ctx = bdSchool();
    bdSchedule($ctx);
    $student = bdStudent($ctx);

    // A `total` policy, and a spec that LIES about it, claiming the narrower base.
    $policy = bdPolicy($ctx, 50, DiscountBase::Total);

    $invoice = bdGenerate($ctx, $student, $policy, DiscountBase::Discountable);

    // 1,400,000 − 700,000. If the caller's claim were honoured this would be 900,000.
    expect($invoice->total->toKobo())->toBe(700000);

    // And the same lie in the other direction is equally ignored: a `discountable` policy claimed as
    // `total`. Without this half the arm passes against an implementation that simply always uses
    // the total base — the exact fixture collapse the file's header warns about.
    $narrow = bdPolicy($ctx, 50, DiscountBase::Discountable);
    $other = bdStudent($ctx);

    expect(bdGenerate($ctx, $other, $narrow, DiscountBase::Total)->total->toKobo())->toBe(900000);
});

it('the bursar path and the bulk run produce the SAME reduction for one policy', function () {
    $ctx = bdSchool();
    bdSchedule($ctx);

    $policy = bdPolicy($ctx, 50, DiscountBase::Total);

    // The run's path: an award, billed by the cohort loop.
    $viaRun = bdStudent($ctx, bdScholarship($ctx));
    bdAward($ctx, $viaRun, $policy);
    bdRun($ctx);

    // The bursar's path: the same policy, applied by hand to a student the run did not bill.
    $viaHand = bdStudent($ctx);
    $handInvoice = bdGenerate($ctx, $viaHand, $policy, null);

    // THE POINT IS THE EQUALITY, not either figure. Before the base was resolved server-side these
    // two disagreed: the run read `base` off the policy and the manual path never looked, so one
    // policy applied two ways gave two different bills.
    expect($handInvoice->total->toKobo())->toBe(bdInvoice($viaRun)->total->toKobo())
        ->and($handInvoice->total->toKobo())->toBe(700000);
});

/*
|--------------------------------------------------------------------------
| 8 — an unbuildable reduction is RECORDED, not swallowed (cold review, finding 2)
|--------------------------------------------------------------------------
*/

it('a student whose reduction cannot be built gets a failed row, and the cohort equality holds', function () {
    $ctx = bdSchool();
    bdSchedule($ctx);

    $broken = bdStudent($ctx, bdScholarship($ctx));
    $healthy = bdStudent($ctx, bdScholarship($ctx));
    $plain = bdStudent($ctx);

    bdAward($ctx, $healthy, bdPolicy($ctx, 50, DiscountBase::Discountable));

    // A RAW award onto an AMOUNT-basis policy — the state AwardStudentDiscount refuses and the
    // database does not, which is exactly the writer the guard here has to survive. Its `percent` is
    // NULL, so reductionSpecFor() cannot build a spec and throws.
    $amountPolicy = ActiveSchool::runFor($ctx['school']->id, fn () => DiscountPolicy::create([
        'school_id' => $ctx['school']->id, 'name' => 'Flat '.Str::random(4),
        'basis' => 'amount', 'value_minor' => 50000, 'value_currency' => 'NGN',
        'requires_approval' => false, 'status' => 'active',
    ]));
    DB::table('finance_student_discount_awards')->insert([
        'uuid' => (string) Str::uuid(), 'school_id' => $ctx['school']->id,
        'student_id' => $broken->id, 'discount_policy_id' => $amountPolicy->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $run = bdRun($ctx);

    // THE ROW EXISTS. Built one line above the `try`, this throw reached attempt(), which only logs
    // — the student got NO row at all and simply vanished from the run's accounting.
    $row = BulkInvoiceRunRow::withoutGlobalScopes()
        ->where('run_id', $run->id)->where('student_id', $broken->id)->first();

    // POSITIVE, so the message survives — Pest discards a custom message on a negated expectation
    // and prints its own exported value instead (tests/Feature/Quality/PestNegatedExpectationMessagesTest).
    // This arm is precisely the one whose failure sentence has to be readable: a null row IS the
    // defect, and "expected null not to be null" does not say that.
    expect($row)->toBeInstanceOf(BulkInvoiceRunRow::class, 'the student must be recorded, not silently dropped')
        ->and($row->outcome)->toBe(BulkInvoiceRunOutcome::Failed)
        ->and($row->reason)->toContain('could not be read as a percentage');

    // NOT billed, and not billed at full price either — the whole invoice rolled back.
    expect(Invoice::withoutGlobalScopes()->where('student_id', $broken->id)->count())->toBe(0);

    // THE EQUALITY, NOT JUST THE ROW. This is the run's own alarm, and it is what a missing row
    // breaks — asserting only the row would leave the alarm itself untested.
    $run->refresh();
    expect($run->billed_count + $run->already_billed_count + $run->failed_count + $run->sponsored_count)
        ->toBe($run->cohort_count)
        ->and($run->cohort_count)->toBe(3)
        ->and($run->failed_count)->toBe(1)
        ->and($run->billed_count)->toBe(2);

    // And a run that billed most of its cohort is not turned into a failure by one bad award.
    expect(bdInvoice($healthy)->total->toKobo())->toBe(900000)
        ->and(bdInvoice($plain)->total->toKobo())->toBe(1400000);
});

/*
|--------------------------------------------------------------------------
| 9 — the award is audited (cold review, finding 3)
|--------------------------------------------------------------------------
*/

it('awarding writes an activity entry naming causer, subject and the terms', function () {
    $ctx = bdSchool();
    $actor = User::factory()->create(['school_id' => $ctx['school']->id]);
    $student = bdStudent($ctx, bdScholarship($ctx));
    $policy = bdPolicy($ctx, 50, DiscountBase::Total);

    $award = bdAward($ctx, $student, $policy, $actor->id);

    $entry = Activity::query()
        ->where('event', AwardStudentDiscount::AWARDED)
        ->where('subject_type', StudentDiscountAward::class)
        ->where('subject_id', $award->id)
        ->firstOrFail();

    expect((int) $entry->causer_id)->toBe($actor->id)
        ->and($entry->causer_type)->toBe(User::class)
        // THE RESOLVED TERMS, snapshotted. `discount_policy_id` alone cannot answer "did this
        // child's discount go up or down", which is the only question anyone asks of this trail.
        ->and($entry->properties['to']['percent'])->toBe(50)
        ->and($entry->properties['to']['base'])->toBe('total')
        ->and($entry->properties['to']['policy_name'])->toBe($policy->name)
        ->and($entry->properties['to']['discount_policy_id'])->toBe($policy->id)
        // Explicitly null rather than an absent key a reader has to interpret.
        ->and($entry->properties->has('from'))->toBeTrue()
        ->and($entry->properties['from'])->toBeNull();
});

it('changing an award writes the terms EITHER SIDE, even when the writer is not the Action', function () {
    $ctx = bdSchool();
    $actor = User::factory()->create(['school_id' => $ctx['school']->id]);
    $student = bdStudent($ctx, bdScholarship($ctx));

    $first = bdPolicy($ctx, 50, DiscountBase::Discountable);
    $second = bdPolicy($ctx, 10, DiscountBase::Discountable);

    $award = bdAward($ctx, $student, $first, $actor->id);

    // 50% -> 10%, written WITHOUT going through AwardStudentDiscount — the next commit's import is
    // exactly this writer, and it is the case the migration's append-only exemption is argued on.
    $this->actingAs($actor);
    ActiveSchool::runFor($ctx['school']->id, fn () => $award->update(['discount_policy_id' => $second->id]));

    $entry = Activity::query()
        ->where('subject_type', StudentDiscountAward::class)
        ->where('subject_id', $award->id)
        ->where('event', 'updated')
        ->firstOrFail();

    // BOTH SIDES. Before the trait, this change left nothing but a bumped `updated_at` on the row
    // that decides what a family pays.
    expect((int) $entry->properties['old']['discount_policy_id'])->toBe($first->id)
        ->and((int) $entry->properties['attributes']['discount_policy_id'])->toBe($second->id)
        ->and((int) $entry->causer_id)->toBe($actor->id);
});
