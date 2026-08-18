<?php

use App\Enums\TermStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Models\FeeSchedule;
use App\Finance\Services\FeeScheduleLineMapper;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Term;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * U6 commit 2 — the mapper that says what a term bill CONTAINS.
 *
 * Every arm here was planted red before it was believed: the mandatory filter removed, the empty
 * refusal removed, the currency refusal removed, the status refusal removed. A guard only ever seen
 * green proves nothing about whether it runs.
 *
 * ORDER IS ASSERTED, NOT THE SET. `toBe` on an ordered list of descriptions, with a fixture whose
 * insertion order deliberately DISAGREES with its sort_order, so a mapper that returned insertion
 * order (or MySQL's own order) cannot pass by accident.
 */
uses(RefreshDatabase::class);

/**
 * A School with one fee schedule of $items, left at $status.
 *
 * CreateFeeSchedule always authors a DRAFT (the parent-state triggers only admit item inserts into
 * one), so a non-draft fixture is a raw status write — the way the rest of the suite moves a
 * lifecycle it is not the subject of.
 *
 * @param  list<array<string, mixed>>  $items
 * @return array{0: School, 1: FeeSchedule}
 */
function fslmSchedule(array $items, FeeScheduleStatus $status = FeeScheduleStatus::Active): array
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

    $specs = array_map(fn (array $item) => $item + ['bank_account_id' => testBankAccountUuid($school->id)], $items);

    $schedule = ActiveSchool::runFor($school->id, fn () => app(CreateFeeSchedule::class)->handle($term->id, $level->id, 'v1', $specs));

    if ($status !== FeeScheduleStatus::Draft) {
        DB::table('finance_fee_schedules')->where('id', $schedule->id)->update(['status' => $status->value]);
        $schedule->refresh();
    }

    return [$school, $schedule];
}

/** Run the mapper in $school's context, the way a cohort run would. */
function fslmLines(School $school, FeeSchedule $schedule): array
{
    return ActiveSchool::runFor($school->id, fn () => app(FeeScheduleLineMapper::class)->linesFor($schedule));
}

it('maps ONLY the mandatory items, in sort_order then id, as charge lines citing their item', function () {
    // INSERTION ORDER DISAGREES WITH sort_order ON PURPOSE. Inserted Feeding, Transport, Tuition,
    // Uniform; sorted they are Tuition (0), Transport (1, optional), Feeding (2), Uniform (3,
    // optional). A mapper returning insertion order would produce [Feeding, Tuition]; the expected
    // list below is [Tuition, Feeding]. Two arms in one fixture — the filter and the order — and
    // neither can be satisfied by the other.
    [$school, $schedule] = fslmSchedule([
        ['description' => 'Feeding', 'amount_minor' => 300000, 'currency' => 'NGN', 'is_mandatory' => true, 'is_discountable' => false, 'sort_order' => 2],
        ['description' => 'Transport', 'amount_minor' => 200000, 'currency' => 'NGN', 'is_mandatory' => false, 'is_discountable' => true, 'sort_order' => 1],
        ['description' => 'Tuition', 'amount_minor' => 1000000, 'currency' => 'NGN', 'is_mandatory' => true, 'is_discountable' => true, 'sort_order' => 0],
        ['description' => 'Uniform', 'amount_minor' => 50000, 'currency' => 'NGN', 'is_mandatory' => false, 'is_discountable' => false, 'sort_order' => 3],
    ]);

    $lines = fslmLines($school, $schedule);

    expect(array_map(fn ($line) => $line->description, $lines))->toBe(['Tuition', 'Feeding'],
        'The mapper must return the MANDATORY items only, ordered by sort_order. Transport and Uniform '
        .'are optional: nothing in the schema records which child takes the bus, so a cohort run may not '
        .'guess. An order matching insertion rather than sort_order is the other failure this asserts.');

    // The amounts travel unchanged — the mapper performs no Money arithmetic of its own.
    expect(array_map(fn ($line) => $line->amount->toKobo(), $lines))->toBe([1000000, 300000]);

    // EVERY LINE IS A CHARGE, with no policy and no percentage. U8's discount AWARD does not exist,
    // so a bulk reduction line has no fact to rest on; asserting the negative keeps this path clear
    // of the finance_invoice_lines_reduction_guard entirely.
    foreach ($lines as $line) {
        expect($line->kind)->toBe(InvoiceLineKind::Charge)
            ->and($line->discountPolicyId)->toBeNull()
            ->and($line->percent)->toBeNull()
            ->and($line->note)->toBeNull();
    }

    // PROVENANCE: the INTEGER fee item id, matched against the items themselves rather than against
    // a remembered order — a null or a wrong id writes a perfectly valid invoice with the lookup
    // provenance gone, and finance_invoice_lines.fee_item_id carries no foreign key to notice.
    $ids = $schedule->items()->pluck('id', 'description');
    expect(array_map(fn ($line) => $line->feeItemId, $lines))
        ->toBe([(int) $ids['Tuition'], (int) $ids['Feeding']]);

    // isDiscountable comes from the ITEM, never from the DTO's `true` default. Tuition is
    // discountable and Feeding is not, so a default would show up here as [true, true].
    expect(array_map(fn ($line) => $line->isDiscountable, $lines))->toBe([true, false]);
});

it('breaks an equal sort_order by id, so two runs over one schedule are identical', function () {
    // sort_order carries NO uniqueness constraint and CreateFeeSchedule defaults it to the array
    // index, so ties are authorable. With a tie and no second key MySQL may return either order,
    // and a re-driven bulk run could bill the same child a differently-ordered invoice.
    [$school, $schedule] = fslmSchedule([
        ['description' => 'Tuition', 'amount_minor' => 1000000, 'currency' => 'NGN', 'is_mandatory' => true, 'sort_order' => 5],
        ['description' => 'Levy', 'amount_minor' => 400000, 'currency' => 'NGN', 'is_mandatory' => true, 'sort_order' => 5],
        ['description' => 'Books', 'amount_minor' => 250000, 'currency' => 'NGN', 'is_mandatory' => true, 'sort_order' => 5],
    ]);

    $expected = ['Tuition', 'Levy', 'Books']; // insertion order === id order, which IS the tiebreak

    $first = array_map(fn ($line) => $line->description, fslmLines($school, $schedule));
    $second = array_map(fn ($line) => $line->description, fslmLines($school, $schedule));

    expect($first)->toBe($expected)->and($second)->toBe($expected);
});

it('refuses a schedule whose mandatory items are absent, naming the schedule', function () {
    // A purely optional schedule is authorable and yields zero lines. Left to GenerateInvoice it
    // would be refused per student — N identical errors naming N children for one defect in the
    // price list — and a zero-line invoice must never be reachable at all.
    [$school, $schedule] = fslmSchedule([
        ['description' => 'Transport', 'amount_minor' => 200000, 'currency' => 'NGN', 'is_mandatory' => false],
        ['description' => 'Uniform', 'amount_minor' => 50000, 'currency' => 'NGN', 'is_mandatory' => false],
    ]);

    expect(fn () => fslmLines($school, $schedule))
        ->toThrow(BusinessRuleException::class, "Fee schedule [{$schedule->uuid}] has no mandatory items");
});

it('refuses a schedule whose mandatory items mix currencies, naming the schedule', function () {
    // finance_fee_items.amount_currency carries a SHAPE check only, and nothing constrains one
    // schedule's items to agree — so this is constructible. Unrefused it detonates inside
    // GenerateInvoice's transaction on Money::plus, naming a student for a fault in the price list.
    [$school, $schedule] = fslmSchedule([
        ['description' => 'Tuition', 'amount_minor' => 1000000, 'currency' => 'NGN', 'is_mandatory' => true],
        ['description' => 'Exchange levy', 'amount_minor' => 5000, 'currency' => 'USD', 'is_mandatory' => true],
    ]);

    expect(fn () => fslmLines($school, $schedule))
        ->toThrow(BusinessRuleException::class, "Fee schedule [{$schedule->uuid}] mixes currencies");
});

it('bills from an ACTIVE schedule and refuses every other lifecycle state', function (FeeScheduleStatus $status) {
    [$school, $schedule] = fslmSchedule([
        ['description' => 'Tuition', 'amount_minor' => 1000000, 'currency' => 'NGN', 'is_mandatory' => true],
    ], $status);

    if ($status === FeeScheduleStatus::Active) {
        expect(fslmLines($school, $schedule))->toHaveCount(1);

        return;
    }

    expect(fn () => fslmLines($school, $schedule))->toThrow(
        BusinessRuleException::class,
        "Fee schedule [{$schedule->uuid}] is {$status->value}; only an active schedule may be billed from.",
    );
})->with([
    // ADMITTED. The one approved, current price list. Same single filter FeeScheduleLookup::activeFor()
    // applies, so "billable" is one rule rather than two that can drift.
    'active' => [FeeScheduleStatus::Active],
    // REFUSED — never approved. A draft is a proposal; billing one lets a Head price a term without
    // the ED ever seeing it, which is the failure the S1 approval path exists to prevent.
    'draft' => [FeeScheduleStatus::Draft],
    // REFUSED — undecided. Items are frozen, but a rejected publish returns the schedule to draft, so
    // pending_approval is not "nearly active"; it is a question nobody has answered.
    'pending_approval' => [FeeScheduleStatus::PendingApproval],
    // REFUSED — approved once, since replaced. Billing a cohort from it prices a whole year group off
    // a list the school has re-priced, silently, N invoices wide.
    'superseded' => [FeeScheduleStatus::Superseded],
    // REFUSED — approved once, since withdrawn. Same shape as superseded, and withdrawal is the
    // school saying explicitly that this list is not to be charged.
    'retired' => [FeeScheduleStatus::Retired],
]);
