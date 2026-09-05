<?php

namespace App\Finance\Console;

use App\Finance\Actions\ApproveCreditNote;
use App\Finance\Actions\ApproveDiscountPolicyChange;
use App\Finance\Actions\ApproveFeeScheduleChange;
use App\Finance\Actions\ApproveInvoice;
use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\AwardStudentDiscount;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordAccountPayment;
use App\Finance\Actions\RecordPayment;
use App\Finance\Actions\ReturnInvoice;
use App\Finance\Actions\SubmitCreditNote;
use App\Finance\Actions\SubmitDiscountPolicyChange;
use App\Finance\Actions\SubmitFeeScheduleChange;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Enums\CreditNoteStatus;
use App\Finance\Enums\DiscountBase;
use App\Finance\Enums\DiscountBasis;
use App\Finance\Enums\DiscountPolicyChangeKind;
use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Enums\FeeScheduleChangeKind;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\VoidRequestStatus;
use App\Finance\Exports\DiscountAwardImportTemplateExport;
use App\Finance\Models\BankAccount;
use App\Finance\Models\CreditNote;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\FeeItem;
use App\Finance\Models\FeeSchedule;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Finance\Models\StudentDiscountAward;
use App\Finance\Models\VoidRequest;
use App\Finance\Services\DiscountAwardImporter;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Illuminate\Support\Carbon;

/**
 * The FINANCE half of the drive fixture: given ENROLLMENT UUIDs (handed in from outside — this
 * class never touches Academics, per the arch boundary), it produces every money state by
 * executing the REAL Actions. Lives in App\Finance because only App\Finance may use the Finance
 * Actions; it is driven by the cross-module `SeedDriveFixture` command, which creates the
 * enrollments and passes their UUIDs in — exactly as production bills a UUID resolved through the
 * ACL port, never an enrollment it reached into Academics for.
 *
 * All methods assume the active-School context is already set by the caller and run within it.
 */
final class DriveFinanceStates
{
    /** The seeded policy's name, in one place: both the create and the idempotence check read it. */
    private const DRIVE_POLICY_NAME = 'Sibling discount';

    /** The SECOND account's label — see ensureSecondBankAccount(). */
    private const DRIVE_SECOND_ACCOUNT_LABEL = 'Drive trips account';

    /**
     * THE TWO (percentage, base) PAIRS THE BSS AWARD IMPORT RESOLVES AGAINST, and the pair is the unit
     * rather than the percentage: a row of that sheet names a percentage AND what it comes off, and
     * those are two different amounts of money at the same figure.
     *
     * THE PERCENTAGES ARE THE TEMPLATE'S OWN SAMPLE VALUES (50 and 100,
     * {@see DiscountAwardImportTemplateExport::SAMPLE_ROWS}), so a bursar who
     * downloads the template and changes only the admission numbers gets two rows that RESOLVE. A
     * fixture whose percentages disagreed with the sample would make the natural first upload reject
     * every row, and the drive would be measuring the fixture.
     *
     * ONE PAIR ON EACH BASE, which is the minimum that can discriminate. Two policies on one base
     * would let a resolver that ignores the third column entirely land on a policy for every row and
     * look correct — the degenerate-fixture failure, in a drive rather than in a suite.
     *
     * 75% OF THE WHOLE BILL IS DELIBERATELY ABSENT and is not an omission: it is the fixture's
     * no-active-policy arm. Every other refusal this screen can show has a seeded subject; that one is
     * shown by a pair nobody approved, so it has to be a gap rather than a row.
     *
     * @var list<array{percent: int, base: DiscountBase, name: string}>
     */
    private const DRIVE_AWARD_POLICIES = [
        ['percent' => 50, 'base' => DiscountBase::Discountable, 'name' => 'BSS scholarship — half of discountable charges'],
        ['percent' => 100, 'base' => DiscountBase::Total, 'name' => 'BSS scholarship — the whole bill'],
    ];

    /** The seeded schedule's label, in one place: the create and the idempotence check read it. */
    private const DRIVE_SCHEDULE_LABEL = 'Drive term bill v1';

    public function __construct(
        private readonly User $maker,
        private readonly User $checker,
    ) {}

    /**
     * THE DRIVE FIXTURE'S BANK ACCOUNT FOR A SCHOOL — the one place it is defined, for both readers.
     *
     * Two things need it, and only one of them records a payment. `finance_payments.bank_account_id` is
     * required for portal-issued payments (the origin-keyed CHECK), so the drive cannot bill anything
     * without one; and `finance_fee_items.bank_account_id` is NOT NULL with a School-scoped, not-
     * deactivated `exists` rule in front of it, so the fee-schedules AUTHORING screen cannot create a
     * single line in a school that has no account either.
     *
     * The docblock here used to say the account is created in this class "because this class is the only
     * thing that records payments, and a fixture account that exists but is never used would be a row
     * nobody could explain". That reasoning is FALSE as of U1: School B records no payment and still
     * needs an account, because the isolation seat has `finance.fee-schedule.manage` and would open the
     * author screen onto an empty picker. The account is now seeded for both schools by
     * `SeedDriveFixture` and this method is the single source of its identity.
     *
     * `firstOrCreate` keyed on (school_id, account_number) is what makes calling it twice safe: the
     * payment paths below call it again and FIND the row rather than making a second one. The
     * account_number formula lives here and nowhere else — a second copy in the seeder, keyed to match,
     * is the drift this commit's trait extraction argues against.
     */
    public function ensureBankAccount(int $schoolId): int
    {
        return (int) BankAccount::query()->firstOrCreate(
            ['school_id' => $schoolId, 'account_number' => '90'.str_pad((string) $schoolId, 8, '0', STR_PAD_LEFT)],
            ['label' => 'Drive account', 'bank_name' => 'Drive Bank'],
        )->id;
    }

    /**
     * The account id for a payment about to be recorded. Kept as its own name because the three call
     * sites below are asking "which account did this money land in", not "make sure one exists" — and
     * because it must keep resolving to the SAME row the seeding call made.
     */
    private function bankAccountId(int $schoolId): int
    {
        return $this->ensureBankAccount($schoolId);
    }

    /**
     * How many accounts a school has, for the fixture's own report.
     *
     * It lives HERE rather than beside the academic counts in the command because the boundary lint
     * (Constitution 3) forbids a `finance_*` table literal outside app/Finance — the command counts
     * `terms` and `class_levels` with DB::table and cannot name this one. Read through the scoped model
     * rather than the table, so it must be called inside `ActiveSchool::runFor($schoolId, …)`; the
     * explicit `where` then agrees with SchoolScope instead of relying on it.
     */
    public function bankAccountCount(int $schoolId): int
    {
        return BankAccount::query()->where('school_id', $schoolId)->count();
    }

    /**
     * PAYMENTS BY PROVENANCE — the receipt screen's subject (U11), counted per `origin`.
     *
     * ADDED BECAUSE THE COUNT TABLE COULD NOT ANSWER THE QUESTION THE RECEIPT DRIVE ASKS. That
     * screen has exactly one subject, a payment, and its refusal arm has exactly one subject, a
     * MIGRATED payment — so "zero in any column means the screen cannot author anything" applies to
     * this axis and to no column the table already carried. The split is the point: a single
     * payments count would read as coverage while the refusal, the half of the screen that exists
     * because of the WCBS cutover, still had nothing to render.
     *
     * It answers honestly and the answer is a zero. Nothing in this fixture posts an opening-balance
     * batch, and PostOpeningBalanceBatch is the ONLY writer of `origin = 'migrated'` — so the
     * migrated column reads 0 until a drive walks the real import path itself. That is a fact about
     * the fixture worth printing rather than rediscovering in a browser.
     *
     * Same placement reasoning as bankAccountCount above: the boundary lint forbids the command
     * naming a `finance_` table, and this reads the scoped model, so call it inside
     * `ActiveSchool::runFor($schoolId, …)`.
     */
    public function paymentCount(int $schoolId, string $origin): int
    {
        return Payment::query()->where('school_id', $schoolId)->where('origin', $origin)->count();
    }

    /**
     * ONE ACTIVE DISCOUNT POLICY PER DRIVE SCHOOL — the U2 screen's amend and retire targets.
     *
     * VERIFIED ABSENT BEFORE IT WAS ADDED: neither this class nor DriveCastSeeder created a discount
     * policy, so `finance_discount_policies` was empty in every drive. Two consequences, and the second
     * is the one that matters here. `finance_invoice_lines_reduction_guard` refuses a reduction line
     * that cites no policy, so no invoice in the fixture could carry a discount at all; and the
     * discount-policies screen offers three acts — propose, amend, retire — of which only the first has
     * anything to work on when the catalog is empty. A drive that can exercise one of three paths is a
     * third of a drive, which is the same precondition-not-discovery argument the academic slot and the
     * bank account above were each written for.
     *
     * THROUGH THE REAL ACTIONS, never a row write: submit as the maker, approve as the checker, which
     * is the only path that writes this table ({@see ApproveDiscountPolicyChange}) and the one the
     * screen itself drives. `$maker` is a parameter because School B's bursar is a different user and
     * the maker ≠ checker CHECK is a database fact — the fixture must not fake its way around it.
     *
     * A PERCENTAGE, and `requires_approval` false: the standing sibling discount is the case the flag's
     * `false` arm is FOR, so the seeded row reads as the thing an operator would recognise. The drive
     * authors the other combinations live.
     *
     * Idempotent by name, like ensureBankAccount: a second call finds the row rather than proposing a
     * duplicate, which the active-name unique would refuse at approval anyway.
     */
    public function ensureDiscountPolicy(int $schoolId, ?User $maker = null): int
    {
        $existing = DiscountPolicy::query()
            ->where('school_id', $schoolId)
            ->where('name', self::DRIVE_POLICY_NAME)
            ->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $change = app(SubmitDiscountPolicyChange::class)->handle(
            DiscountPolicyChangeKind::Create,
            null,
            [
                'name' => self::DRIVE_POLICY_NAME,
                'description' => 'Second and subsequent children enrolled in the same term.',
                'basis' => DiscountBasis::Percent->value,
                'percent' => 10,
                'requires_approval' => false,
            ],
            'Standing arrangement — carried over from last session.',
            $maker ?? $this->maker,
        );

        app(ApproveDiscountPolicyChange::class)->handle($change, $this->checker);

        return (int) DiscountPolicy::query()
            ->where('school_id', $schoolId)
            ->where('name', self::DRIVE_POLICY_NAME)
            ->value('id');
    }

    /**
     * AN ACTIVE FEE SCHEDULE AT ONE (term, class level) — the thing U6's bulk-run screen prices from,
     * and the thing this fixture had none of until that screen needed one.
     *
     * WITHOUT IT THE WHOLE SCREEN IS ONE SENTENCE. `FeeScheduleLookup::activeFor()` admits only an
     * `active` schedule, so with an empty catalog every preview answers "No active fee schedule exists
     * at these coordinates" and every run fails before writing a row. A drive would have rendered the
     * refusal path and nothing else — the same class of failure as the empty term select U1 commit 1
     * seeded the academic slot to prevent, one table over.
     *
     * IT GOES THROUGH THE REAL PUBLISH PATH, not a status write. `CreateFeeSchedule` always authors a
     * DRAFT — a draft is a proposal and never a price — and the ONLY thing that makes a schedule
     * `active` is an approved publish change (S1 commit 4, proof 31). Writing `active` directly would
     * put a state in this fixture that the application cannot reach, which is the one thing the drive
     * environment promises it does not do.
     *
     * THE CHECKER IS SHARED ACROSS BOTH SCHOOLS, exactly as {@see ensureDiscountPolicy()} shares it and
     * for the same reason: the ED holds every finance checker side, and what the maker ≠ checker rule
     * (Policy, and the database CHECK behind it) actually requires is two different users.
     *
     * ONE MANDATORY ITEM AND ONE OPTIONAL ONE, deliberately. The mapper bills MANDATORY items only, so
     * a schedule of one mandatory line proves an invoice can be raised and a schedule carrying an
     * optional line beside it proves the run leaves it out — a distinction a single-line schedule
     * cannot show. Both point at the school's own account: `finance_fee_items.bank_account_id` is NOT
     * NULL and School-scoped.
     *
     * Idempotent by label, like the two ensure* methods above: a second call finds the row rather than
     * proposing a duplicate, which `finance_fee_schedules_pending_unique` would refuse anyway.
     */
    public function ensureActiveFeeSchedule(int $schoolId, int $termId, int $classLevelId, ?User $maker = null): int
    {
        $existing = FeeSchedule::query()
            ->where('school_id', $schoolId)
            ->where('label', self::DRIVE_SCHEDULE_LABEL)
            ->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $accountUuid = (string) BankAccount::query()->whereKey($this->ensureBankAccount($schoolId))->value('uuid');

        $draft = app(CreateFeeSchedule::class)->handle($termId, $classLevelId, self::DRIVE_SCHEDULE_LABEL, [
            [
                'description' => 'Tuition',
                'amount_minor' => 25000000,
                'currency' => Money::DEFAULT_CURRENCY,
                'is_mandatory' => true,
                'is_discountable' => true,
                'sort_order' => 0,
                'bank_account_id' => $accountUuid,
            ],
            [
                // OPTIONAL, and it is the point of the second line: no bulk run may bill it, because
                // nothing in the schema records which child takes the bus.
                'description' => 'Bus (optional)',
                'amount_minor' => 4000000,
                'currency' => Money::DEFAULT_CURRENCY,
                'is_mandatory' => false,
                'is_discountable' => false,
                'sort_order' => 1,
                'bank_account_id' => $accountUuid,
            ],
            [
                /*
                 * MANDATORY **AND** NON-DISCOUNTABLE — the third line, and the ONLY thing on this
                 * schedule that can tell the two discount bases apart on a bulk-run bill.
                 *
                 * WHY THE BUS LINE ABOVE CANNOT DO IT, which is the trap this item was added to close.
                 * The bus is non-discountable, so a reader checking "is there a false `is_discountable`
                 * on this schedule?" answers yes and stops. But it is also NOT MANDATORY, and
                 * {@see FeeScheduleLineMapper::linesFor()} filters `is_mandatory = true` — so no bulk
                 * run ever puts it on a bill. Before this item, EVERY invoice a run produced held
                 * exactly one line, Tuition, and that line was discountable: `discountable` and `total`
                 * therefore denoted the SAME money, and a resolver that ignored the base axis entirely
                 * would have produced identical totals and passed. The fixture looked like it covered
                 * the axis and could not.
                 *
                 * That is the degenerate-fixture failure one level in from the one the bus line already
                 * guards: it is not enough for the SCHEDULE to be mixed, the BILLED SUBSET has to be.
                 *
                 * WITH IT, the arithmetic separates: a 50%-of-discountable award reduces 250,000 by
                 * 125,000 and leaves the 30,000 levy untouched, while a 100%-of-total award reduces the
                 * whole 280,000. Half of the total (140,000) and half of the discountable (125,000) are
                 * different numbers, so an implementation that read the wrong base is visible on the
                 * bill rather than invisible.
                 *
                 * A LEVY IS THE RIGHT SHAPE for this, not a contrivance: schools charge one, every
                 * child pays it, and no scholarship discounts it.
                 */
                'description' => 'Development levy',
                'amount_minor' => 3000000,
                'currency' => Money::DEFAULT_CURRENCY,
                'is_mandatory' => true,
                'is_discountable' => false,
                'sort_order' => 2,
                'bank_account_id' => $accountUuid,
            ],
        ]);

        $change = app(SubmitFeeScheduleChange::class)->handle(
            FeeScheduleChangeKind::Publish,
            $draft,
            'Term prices agreed for the drive fixture.',
            $maker ?? $this->maker,
        );

        app(ApproveFeeScheduleChange::class)->handle($change, $this->checker);

        return (int) $draft->id;
    }

    /**
     * How many ACTIVE fee schedules a school has, for the fixture's own report. Counted here for the
     * reason bankAccountCount() gives — the boundary lint forbids a `finance_` table literal outside
     * app/Finance — and filtered to `active` because that is the only status a run may bill from: a
     * count of drafts would report a catalog the bulk-run screen cannot use as though it could.
     */
    public function activeFeeScheduleCount(int $schoolId): int
    {
        return FeeSchedule::query()
            ->where('school_id', $schoolId)
            ->where('status', FeeScheduleStatus::Active->value)
            ->count();
    }

    /**
     * How many discount policies a school has, for the fixture's own report — here rather than in the
     * command for the reason bankAccountCount() gives: the boundary lint forbids a `finance_` table
     * literal outside app/Finance, so the Finance side counts its own table. Reads the scoped model, so
     * it must be called inside `ActiveSchool::runFor($schoolId, …)`.
     */
    public function discountPolicyCount(int $schoolId): int
    {
        return DiscountPolicy::query()->where('school_id', $schoolId)->count();
    }

    /**
     * THE PAIRS THE BSS AWARD IMPORT NEEDS — two active percentage policies per drive school, one on
     * each base ({@see self::DRIVE_AWARD_POLICIES}).
     *
     * VERIFIED ABSENT BEFORE IT WAS ADDED. The fixture seeded exactly ONE discount policy per school
     * ({@see self::ensureDiscountPolicy()}), so `/finance/discount-award-imports` would have opened on a
     * catalog holding a single pair. That is not "thin", it is unable to show the screen's subject:
     * the third column of that sheet is the base axis, and with one base seeded a resolver that ignored
     * the column entirely would award every row and read as correct. Same class as the empty term
     * select U1 commit 1 fixed, and invisible for the same reason — no test can run this seeder.
     *
     * THROUGH THE REAL SUBMIT-THEN-APPROVE PATH, exactly as {@see self::ensureDiscountPolicy()} is, and
     * the reason is sharper here than anywhere else in this class: the import's whole design rests on
     * {@see ApproveDiscountPolicyChange} being the catalog's single writer — a row of that sheet says
     * WHICH approved figure a child sits on and never invents one. A fixture that wrote these rows
     * directly would drive a screen whose central claim it had just falsified.
     *
     * `$maker` IS A PARAMETER for the same database fact as the two ensure* methods above: School B's
     * bursar is a different user and maker ≠ checker is a CHECK, not a convention.
     *
     * Idempotent by name, like every ensure* here — a second call finds the rows.
     *
     * @return list<int> the policy ids, in DRIVE_AWARD_POLICIES order
     */
    public function ensureAwardPolicies(int $schoolId, ?User $maker = null): array
    {
        $ids = [];

        foreach (self::DRIVE_AWARD_POLICIES as $spec) {
            $existing = DiscountPolicy::query()
                ->where('school_id', $schoolId)
                ->where('name', $spec['name'])
                ->first();

            if ($existing !== null) {
                $ids[] = (int) $existing->id;

                continue;
            }

            $change = app(SubmitDiscountPolicyChange::class)->handle(
                DiscountPolicyChangeKind::Create,
                null,
                [
                    'name' => $spec['name'],
                    'description' => 'Brookstone Scholarship Scheme — carried in from the accounts team\'s list.',
                    'basis' => DiscountBasis::Percent->value,
                    'percent' => $spec['percent'],
                    // THE AXIS THE IMPORT DISCRIMINATES ON. Passed explicitly rather than defaulted:
                    // `SubmitDiscountPolicyChange` reads `$terms['base'] ?? null`, and a null base on a
                    // percentage policy is exactly the drift its own docblock records having been found
                    // once — a fixture relying on the default would seed two policies on one base.
                    'base' => $spec['base']->value,
                    'requires_approval' => false,
                ],
                'BSS scheme for the coming session.',
                $maker ?? $this->maker,
            );

            app(ApproveDiscountPolicyChange::class)->handle($change, $this->checker);

            $ids[] = (int) DiscountPolicy::query()
                ->where('school_id', $schoolId)
                ->where('name', $spec['name'])
                ->value('id');
        }

        return $ids;
    }

    /**
     * DISTINCT (percent, base) PAIRS among ACTIVE PERCENTAGE policies — what the award-import screen
     * actually reads, and a number `Discount policies` beside it cannot produce.
     *
     * A catalog of three could be three drafts, three fixed-amount policies, or three rows on ONE pair;
     * all three render the screen's empty state or its ambiguity refusal while the policies column reads
     * healthy. This counts the thing the screen groups by, through the same query shape the route uses.
     *
     * Reads the SCOPED model, so it must be called inside `ActiveSchool::runFor($schoolId, …)`.
     */
    public function awardPairCount(int $schoolId): int
    {
        return count($this->awardPairsFor($schoolId));
    }

    /**
     * The pairs themselves, in the phrasing the sheet expects — `50% of DISCOUNTABLE CHARGES`.
     *
     * SPOKEN THROUGH {@see DiscountAwardImporter::appliesToLabel()}, not through the enum value. A
     * driver types these into the third column of the file, and `discountable` is not a phrase that
     * column accepts; printing the enum would hand somebody a value the reader refuses and produce a
     * refusal that is the fixture's fault. This is the same function the screen's own prop and the
     * import's own refusal messages read, so all three say one thing.
     *
     * Reads the SCOPED model, so it must be called inside `ActiveSchool::runFor($schoolId, …)`.
     *
     * @return list<string>
     */
    public function awardPairsFor(int $schoolId): array
    {
        return DiscountPolicy::query()
            ->where('school_id', $schoolId)
            ->where('status', DiscountPolicyStatus::Active->value)
            ->where('basis', DiscountBasis::Percent->value)
            ->whereNotNull('percent')
            ->orderBy('base')
            ->orderBy('percent')
            ->get(['percent', 'base'])
            ->map(fn (DiscountPolicy $policy) => $policy->percent.'% of '.DiscountAwardImporter::appliesToLabel($policy->base))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Awards written so far. ZERO ON A FRESH FIXTURE AND PRINTED ANYWAY, exactly as `Guardians` is:
     * it is the denominator the re-upload check is measured against, so "no second award row after a
     * second upload" can be checked against where it started rather than asserted.
     */
    public function discountAwardCount(int $schoolId): int
    {
        return StudentDiscountAward::query()->where('school_id', $schoolId)->count();
    }

    /**
     * PUT ONE STUDENT ON ONE ALREADY-APPROVED POLICY — the state the money drive bills against.
     *
     * VERIFIED ABSENT BEFORE IT WAS ADDED. The award-import drive left `Discount awards` at zero by
     * design, and every award-bearing state in this fixture was made by a human uploading a sheet. That
     * is fine for driving the IMPORT and useless for driving the MONEY: a run has to find an award
     * already sitting on a placed student, from a bare seed, or the arithmetic drive begins with a
     * manual step and is not reproducible.
     *
     * THROUGH THE REAL ACTION, which here is not a formality either — {@see AwardStudentDiscount} is
     * where the gate lives. It re-checks `finance.discount-award.manage` against the actor on every
     * call, refuses a student whose scholarship is not a discount scheme, and refuses a second award.
     * A row write would skip all three and seed a state the application refuses to create.
     *
     * IT IS SEPARATE FROM THE IMPORT DRIVE'S HOLDERS ON PURPOSE, and that separation is the whole
     * reason these students exist. That drive needs holders with NO award (its first upload must be
     * able to report `awarded`); this one needs holders WITH one. The same student cannot be both, and
     * a fixture that reused them would make whichever drive ran second measure the first one's
     * leftovers. See DriveCastSeeder::seedCohortAwardHolders().
     *
     * Idempotent: a second call finds the existing award rather than meeting the Action's own refusal.
     */
    public function awardDiscount(int $studentId, int $policyId, User $actor): int
    {
        $existing = StudentDiscountAward::query()->where('student_id', $studentId)->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        return (int) app(AwardStudentDiscount::class)->handle($studentId, $policyId, (int) $actor->id)->id;
    }

    /**
     * How many of a COHORT hold a discount award — the count that decides whether the reduction arm of
     * a bulk run can be driven at all.
     *
     * NOT DERIVABLE FROM `Discount awards`, which is School-wide and counts holders nobody has placed:
     * the award-import drive's four holders are deliberately unenrolled, so a School could show four
     * awards and bill none of them. And not derivable from `Cohort at slot`, which counts everybody at
     * the coordinates regardless of what they hold. Zero here means every invoice the run raises is
     * full price and the whole arithmetic drive is reading one number with nothing to compare it to.
     *
     * @param  list<int>  $studentIds  the cohort's student ids, resolved by the caller through the port
     */
    public function awardedAmong(array $studentIds): int
    {
        return $studentIds === [] ? 0 : StudentDiscountAward::query()->whereIn('student_id', $studentIds)->count();
    }

    /**
     * The MANDATORY lines of the school's active drive schedule, as `description|amount|discountable`.
     *
     * Printed rather than counted, for the reason the admission numbers and the award pairs are: they
     * are the INPUTS to every total the money drive reports by hand, and a drive that pastes an
     * arithmetic check has to state what it computed from. Filtered to `is_mandatory` because that is
     * what {@see FeeScheduleLineMapper::linesFor()} bills — the optional bus line is on the schedule
     * and can never be on a run's invoice, and printing it here would invite it into the arithmetic.
     *
     * @return list<string>
     */
    public function billableScheduleLines(int $schoolId): array
    {
        return FeeItem::query()
            ->whereHas('schedule', fn ($q) => $q->where('school_id', $schoolId)->where('label', self::DRIVE_SCHEDULE_LABEL))
            ->where('is_mandatory', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (FeeItem $item) => sprintf(
                '%s %s (%s)',
                $item->description,
                $item->amount->format(),
                $item->is_discountable ? 'discountable' : 'NOT discountable',
            ))
            ->all();
    }

    /** UNPAID — a charge, nothing settled. */
    public function unpaid(string $enrollmentUuid): void
    {
        $this->invoice($enrollmentUuid, 300000);
    }

    /** PART-PAID. */
    public function partPaid(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(100000), 'Guardian', $this->maker, SchoolDay::today(), $this->bankAccountId($invoice->school_id));
    }

    /** SETTLED BY PAYMENT. */
    public function settledByPayment(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(300000), 'Guardian', $this->maker, SchoolDay::today(), $this->bankAccountId($invoice->school_id));
    }

    /** SETTLED ENTIRELY BY AN APPROVED CREDIT NOTE (settled, never paid). */
    public function settledByCreditNote(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        $note = app(SubmitCreditNote::class)->handle($invoice, Money::fromKobo(300000), CreditNoteKind::CreditNote, 'Full bursary', $this->maker);
        app(ApproveCreditNote::class)->handle($note, $this->checker);
    }

    /** SETTLED THEN CREDIT-NOTED → the account sits in credit. */
    public function settledThenCredited(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(300000), 'Guardian', $this->maker, SchoolDay::today(), $this->bankAccountId($invoice->school_id));
        $note = app(SubmitCreditNote::class)->handle($invoice, Money::fromKobo(50000), CreditNoteKind::CreditNote, 'Post-payment adjustment', $this->maker);
        app(ApproveCreditNote::class)->handle($note, $this->checker);
    }

    /** A PENDING (unapproved) credit note against a fresh invoice. */
    public function pendingCreditNote(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        app(SubmitCreditNote::class)->handle($invoice, Money::fromKobo(50000), CreditNoteKind::CreditNote, 'Awaiting sign-off', $this->maker);
    }

    /** A PENDING void request against a fresh invoice (the invoice stays active). */
    public function pendingVoid(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 200000);
        app(SubmitVoidRequest::class)->handle($invoice, 'Billed in error — awaiting approval', $this->maker);
    }

    /** An APPROVED void — the invoice is reversed and voided through the real approval path. */
    public function approvedVoid(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        $request = app(SubmitVoidRequest::class)->handle($invoice, 'Duplicate enrolment', $this->maker);
        app(ApproveVoidRequest::class)->handle($request, $this->checker);
    }

    /** A plain issued invoice for the second School (isolation). */
    public function plainInvoice(string $enrollmentUuid, int $kobo): void
    {
        $this->invoice($enrollmentUuid, $kobo);
    }

    /**
     * A SECOND bank account for a school — the one U10's drive needs and no earlier screen did.
     *
     * WHY THE FIXTURE NEEDED A SECOND ONE AT ALL. The MVP cut brief (§9 item 6) says money received
     * into account A settling lines destined for account B "is now an ordinary occurrence, not a
     * hypothetical", and that the allocation screen has to SHOW it. With one account per school that
     * state is unreachable: every payment and every fee item point at the same row, so the screen's
     * mismatch branch could only ever be proven by test and never rendered. This is the fixture
     * precondition the finance-drive skill puts in scope — U1 commit 1's precedent, where the
     * academic slot and the first bank account were added so commit 2's drive would not open onto
     * empty selects.
     *
     * SEEDED FOR SCHOOL A ONLY, by its caller. School B's seat exists to prove isolation, and a
     * second account there would add a row nothing renders.
     *
     * `firstOrCreate` on (school_id, account_number) exactly as {@see ensureBankAccount()} does, so
     * calling it twice finds the row. The account_number formula differs in its prefix — `91` against
     * `90` — which is what keeps the two rows distinct under that key.
     */
    public function ensureSecondBankAccount(int $schoolId): int
    {
        return (int) BankAccount::query()->firstOrCreate(
            ['school_id' => $schoolId, 'account_number' => '91'.str_pad((string) $schoolId, 8, '0', STR_PAD_LEFT)],
            ['label' => self::DRIVE_SECOND_ACCOUNT_LABEL, 'bank_name' => 'Drive Bank'],
        )->id;
    }

    /**
     * U10 — MONEY AT THE WINDOW WITH NOTHING NAMED, AND OPEN INVOICES TO DIRECT IT AT.
     *
     * No earlier state produces this. Every payment in this fixture is recorded AGAINST an invoice
     * and capped at that invoice's outstanding, so its remainder is zero and the allocation screen
     * would have had nothing to open on. `settledThenCredited` leaves the ACCOUNT in credit, which is
     * a different thing: the credit is on the balance, not on an unallocated payment.
     *
     * THE ORDER OF THE THREE STEPS IS LOAD-BEARING. The invoices are raised FIRST and the payment
     * second, because `GenerateInvoice::applyCreditForward` draws every earlier payment's unallocated
     * remainder into each new invoice as it is raised. Record the payment first and the invoices eat
     * it on the way in, leaving exactly the zero remainder this method exists to avoid.
     *
     * TWO INVOICES, ONE OF EACH KIND, and both are needed for what the screen must show:
     *
     *   · the TERM BILL is raised from the ACTIVE fee schedule's mandatory item, so its line carries a
     *     `fee_item_id` and therefore a readable destination account — the only way the screen's
     *     bank-account comparison has a left-hand side at all;
     *   · the SUPPLEMENTARY is free text with no fee item, which is what EVERY line the "New invoice"
     *     modal writes looks like today. It renders the `unrecorded` destination state, and having it
     *     beside a readable one is the point: a fixture with only readable destinations would make the
     *     screen look more certain than it is.
     *
     * `unrecorded` SURVIVES S11 COMMIT 2, and the reason is worth stating because it looks as though
     * it should not. That commit's trigger requires `finance_invoice_lines.bank_account_id` on every
     * charge line, so the supplementary line below now carries one — but `unrecorded` is not a claim
     * about that column. {@see AllocationProposal} still resolves an
     * invoice's destination through `fee_item_id` (commit 1 left it there on purpose: switching the
     * read would report every pre-column invoice `unrecorded`), and a free-text line has no fee item.
     * So the state is still produced by executing a real action, and this fixture writes no row
     * behind the writers' backs to get it.
     *
     * `$intoSecondAccount` chooses which account the money lands in, and that is the whole mismatch
     * axis: false → the payment's account equals the fee item's, so the term bill reads `matches`;
     * true → they differ, and the cut brief's ordinary term-one occurrence is on screen. Both are
     * seeded, on two different students, so one drive sees all three destination states.
     *
     * The payment is deliberately SMALLER than the two invoices together, so the proposal fills the
     * older invoice, part-fills the newer one, and the operator has something to move.
     */
    public function unallocatedRemainder(string $enrollmentUuid, int $schoolId, bool $intoSecondAccount): void
    {
        // The FIRST mandatory item on the school's active drive schedule — the one with a destination.
        //
        // `orderBy('sort_order')` IS LOAD-BEARING AS OF THE THIRD FEE ITEM. This read was written when
        // the schedule had exactly one mandatory line and `value('id')` was therefore unambiguous;
        // adding the mandatory Development levy made it a bare "whichever row the engine returns
        // first", which is not a decision anybody made. Ordered, it deterministically takes Tuition,
        // which is what the line below is describing.
        $feeItem = FeeItem::query()
            ->whereHas('schedule', fn ($q) => $q->where('school_id', $schoolId)->where('label', self::DRIVE_SCHEDULE_LABEL))
            ->where('is_mandatory', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->firstOrFail();

        $feeItemId = (int) $feeItem->id;

        $termBill = app(GenerateInvoice::class)->handle(
            $enrollmentUuid,
            // THE ITEM'S OWN ACCOUNT, read off the row rather than named here — exactly what
            // {@see \App\Finance\Services\FeeScheduleLineMapper::linesFor()} writes for a
            // schedule-derived line, so this hand-built spec stands for the same thing the bulk run
            // would have produced. `finance_fee_items.bank_account_id` is NOT NULL, so there is no
            // absent case. Naming the school's default account here instead would have made the
            // mismatch axis below a coincidence rather than a property of the catalog.
            [new InvoiceLineSpec('Tuition', Money::fromKobo(150000), $feeItemId, bankAccountId: (int) $feeItem->bank_account_id)],
            InvoiceKind::Scheduled,
        );

        app(GenerateInvoice::class)->handle(
            $enrollmentUuid,
            // THE DESTINATION IS RECORDED AND THE LINE STILL RENDERS `unrecorded`, which is the
            // whole reason this line survives S11 commit 2 unchanged in purpose. The two facts are
            // about different columns: the trigger requires `bank_account_id` on a charge line, and
            // {@see \App\Finance\Services\AllocationProposal} resolves an invoice's destination
            // through `fee_item_id` — which this free-text line does not have and will not get.
            // Commit 1 left that read on `fee_item_id` deliberately ("switching it now would report
            // every pre-column invoice `unrecorded`"), so `unrecorded` stays reachable through a
            // real action and this fixture does not have to plant a row behind the writers' backs.
            [new InvoiceLineSpec(
                'Field trip — coach and entry',
                Money::fromKobo(100000),
                bankAccountId: $this->ensureBankAccount($schoolId),
            )],
            InvoiceKind::Supplementary,
        );

        // THE STUDENT COMES OFF THE INVOICE, not from a query against `student_curricula`. This class
        // is inside App\Finance and the arch boundary forbids it naming an Academics table at all —
        // the enrollment arrives as a UUID and is resolved through the ACL port by GenerateInvoice,
        // which is exactly why the Action hands back an Invoice that already knows the student.
        $studentId = (int) $termBill->student_id;

        app(RecordAccountPayment::class)->handle(
            $studentId,
            Money::fromKobo(200000),
            'Guardian at the window',
            $this->maker,
            SchoolDay::today(),
            $intoSecondAccount ? $this->ensureSecondBankAccount($schoolId) : $this->ensureBankAccount($schoolId),
        );
    }

    /**
     * HOW MANY PAYMENTS STILL CARRY AN UNALLOCATED REMAINDER — U10's subject, and a column the count
     * table did not have.
     *
     * The finance-drive skill's rule is that a screen depending on something the table does not count
     * needs the column before the drive needs a browser, and this is that column: the allocation
     * screen's entire subject is a payment with something left on it. A plain payments count reads as
     * coverage while every one of them is fully allocated and the screen can open on none.
     *
     * Counted through the scoped models rather than a raw join, so it must be called inside
     * `ActiveSchool::runFor($schoolId, …)` — the same placement reasoning as bankAccountCount().
     */
    public function paymentsWithRemainderCount(int $schoolId): int
    {
        return Payment::query()
            ->where('school_id', $schoolId)
            // Eager-loaded so unallocatedAmount() sums the loaded relation rather than issuing a
            // query per row — the count table is printed on every seed and should not be an N+1.
            ->with('allocations')
            ->get()
            ->filter(fn (Payment $payment) => ! $payment->unallocatedAmount()->isZero()
                && ! $payment->unallocatedAmount()->isNegative())
            ->count();
    }

    /**
     * HOW MANY INVOICES ARE STILL OPEN — issued, not void, and still owing something.
     *
     * The other half of U10's precondition, and it is a separate question from the one above. A
     * payment with a remainder and NO open invoice is a real state — the money simply banks as credit
     * — but it is a screen with an empty table, so a drive that saw only the payments column could
     * still open onto nothing to direct.
     *
     * Σ(allocations) and Σ(approved credit notes) are the same two aggregates InvoiceSettlement reads,
     * so this counts open by the same definition the screen displays.
     */
    /**
     * ONE BILL SENT BACK TO FINANCE, through the real Action.
     *
     * The review queue's second stat card is the only surface a returned bill has anywhere until
     * Phase B, and a fixture that cannot produce one renders it as a bare `0` — which is
     * indistinguishable from a card wired to the wrong number. A drive that saw `0` there would
     * have proved nothing about the card.
     *
     * THROUGH `ReturnInvoice`, NEVER A WRITE, and here that is not merely the fixture's rule: the
     * three return columns are guarded by `finance_invoices_return_pairing_bi`/`_bu`, which SIGNAL
     * 45000 unless `returned_at` arrives with BOTH companions in one statement. A seeder setting
     * them piecemeal would be refused as errno 1644 by the database itself.
     *
     * THE ACTOR MUST BE THE AUDITOR. `ReturnInvoice` asserts `finance.invoice.reject` against the
     * actor IN THE ACTIVE SCHOOL, and `internal_auditor` is the only role holding it — passing the
     * bursar would meet the gate rather than the fixture.
     *
     * @return int the number of the bill that was returned, so the drive can find it on screen
     */
    public function returnedToFinance(User $auditor): int
    {
        // AN EXISTING BILL, NEVER A NEW ONE. `invoice()` generates, and `GenerateInvoice` refuses a
        // second active scheduled invoice for an episode — the slice-2 duplicate guard, which is
        // exactly the invariant a fixture must not route around.
        //
        // AND IT TAKES NO ENROLLMENT UUID, WHICH IS ARCHITECTURE RATHER THAN CONVENIENCE. The first
        // version resolved an episode through `App\Models\StudentCurriculum` and was refused by
        // ArchitectureBoundaryTest's "Finance does not reach into Academics models" — a rule
        // `bin/ci-boundary-lint.php` does NOT enforce, so the two instruments are not redundant and
        // a green lint is not a green boundary.
        //
        // The bill is chosen the way the QUEUE chooses it — oldest unreleased, unreturned and
        // non-void in the active school, ordered `created_at, id`, which is
        // `InvoiceReviewController::pending()`'s own ordering. So the fixture returns the bill an
        // auditor would see at the top of the page, and the whole read stays inside Finance.
        $invoice = Invoice::query()
            ->where('school_id', ActiveSchool::getOrFail()->id)
            ->whereNull(Invoice::RELEASE_STAMP_COLUMN)
            ->whereNull('returned_at')
            ->excludingVoid()
            ->orderBy('created_at')
            ->orderBy('id')
            ->firstOrFail();

        app(ReturnInvoice::class)->handle(
            $invoice,
            $auditor,
            'The tuition line is charged at last term\'s rate.',
        );

        return $invoice->number;
    }

    /**
     * SEVERAL bills returned to Finance, at DIFFERENT AGES — the Phase B queue's precondition.
     *
     * ONE RETURNED BILL CANNOT DRIVE THAT SCREEN, and this is the fixture-degeneracy rule rather
     * than a preference. With a single row, "oldest returned first" is satisfied by EVERY ordering
     * — insertion, id, newest-first, random — so the drive could not tell a correct sort from an
     * absent one. And with all rows returned in the same second, the age card reads `today`
     * whatever it is wired to, so the one number that distinguishes a worked queue from an
     * abandoned one would be untestable by eye.
     *
     * THREE, AT THREE DISTINCT AGES, AND RETURNED OUT OF ORDER. The oldest is returned LAST, so
     * insertion order, id order and `returned_at` order all disagree — an implementation that
     * ordered by anything but `returned_at` puts a different bill at the top.
     *
     * ONE OF THE AGES IS PAST A WEEK, deliberately: the screen changes the age card's TONE past
     * `STALLED_AFTER_DAYS`, and a fixture whose oldest bill is two days old renders only the calm
     * branch. Both branches have to be reachable or the drive proves one of them.
     *
     * ─── THE CLOCK IS MOVED, AND THAT IS THE ONE THING HERE THAT IS NOT THE REAL PATH ───────────
     *
     * Every return still goes THROUGH `ReturnInvoice`, so the pairing trigger, the ability check,
     * the released-bill guard and the compare-and-swap all run exactly as they do in production.
     * What the fixture supplies is the WHEN: `Carbon::setTestNow` moves the clock the action reads
     * through `now()`, and is cleared in a `finally` so nothing downstream inherits it.
     *
     * IT IS ADMISSIBLE FOR THE REASON THE `scholarships.kind` EXEMPTION IS, and the argument is
     * made here at the line rather than borrowed: a bill returned nine days ago is a state that
     * EXISTS IN PRODUCTION and that no current code path can create on demand — the only writer
     * stamps `now()`, so the sole alternative is to wait nine days. Nothing about the ROW is
     * fabricated; only the moment of the act is chosen, and the act itself is real.
     *
     * @param  list<int>  $daysAgo  how long ago each bill was returned, oldest first
     * @return list<array{number: int, days: int}> the bills returned, in the order they will appear
     */
    public function returnedToFinanceAged(User $auditor, array $daysAgo): array
    {
        $staged = [];

        // RETURNED YOUNGEST-FIRST so the oldest is written LAST and therefore carries the HIGHEST
        // id and the latest `updated_at`. Any ordering that is not `returned_at ASC` then puts the
        // wrong bill on top, which is what makes the drive's first row an assertion.
        foreach (array_reverse($daysAgo) as $days) {
            try {
                Carbon::setTestNow(Carbon::now()->subDays($days));
                $staged[] = ['number' => $this->returnedToFinance($auditor), 'days' => $days];
            } finally {
                // IN A `finally`, NOT AFTER THE CALL. A refusal mid-loop would otherwise leave the
                // whole process running on a clock nine days in the past, and every later fixture
                // state — payments, runs, activity rows — would be stamped there too.
                Carbon::setTestNow();
            }
        }

        // Back into the order the queue will render them.
        return array_reverse($staged);
    }

    /**
     * Bills the Internal Audit review queue can act on — UNRELEASED and NOT out with Finance.
     *
     * ADDED FOR THE REVIEW-QUEUE DRIVE, and it is a different question from `openInvoiceCount`
     * above rather than the same one twice. That one asks whether money is still owed on a bill;
     * this one asks whether a HUMAN has signed it off. The two are independent: a fully-paid bill
     * can be unreleased, and a released bill can be wide open. A fixture healthy on the first
     * column can open the review queue onto nothing.
     *
     * `excludingVoid()` because the queue does — a void bill is not awaiting anybody.
     */
    /**
     * A bill RELEASED TO ITS PAYER, through the real action — the state the parent Fees screen needs.
     *
     * ── RELEASED BY THE ACTION, NEVER BY STAMPING THE COLUMN ──
     *
     * `ApproveInvoice` is the only writer of the release stamp and its write is a compare-and-swap
     * (`WHERE reviewed_at IS NULL`). A fixture that set the column directly would produce a row the
     * system can reach, so the NULL-`kind` exemption does not cover it — and it would skip the very
     * path a drive of the payer screen is there to exercise end to end.
     *
     * ── TWO ACTORS, BECAUSE THE FIXTURE HAS NO CHOICE ──
     *
     * Grant-time segregation of duties refuses both sides of a Finance pair to one user, so the bill
     * is raised by the school's bursar and released by `auditor@drive.test`. That is not a nicety of
     * this method; `User::assignRole` would have thrown before a single-actor fixture existed.
     */
    public function releasedInvoice(string $enrollmentUuid, int $kobo, User $auditor): void
    {
        $this->invoice($enrollmentUuid, $kobo);

        $invoice = Invoice::query()
            ->where('school_id', ActiveSchool::getOrFail()->id)
            ->whereNull(Invoice::RELEASE_STAMP_COLUMN)
            ->whereNull('returned_at')
            ->excludingVoid()
            ->orderByDesc('id')
            ->firstOrFail();

        app(ApproveInvoice::class)->handle($invoice, $auditor);
    }

    /**
     * Bills a PAYER can actually see — released, not void.
     *
     * ── WHY THIS COLUMN EXISTS, AND IT IS THE SIXTH TIME ──
     *
     * `Awaiting review` counts the queue's input; this counts its OUTPUT, and a fixture can be
     * healthy on the first and empty on the second. Every existing column answers a question about
     * a STAFF screen — a cohort to bill, a policy to amend, a payment with a remainder — and the
     * parent Fees screen reads none of them. Zero here and every ward renders "Nothing outstanding",
     * which is the page working and a drive that proves nothing.
     *
     * That is the failure this fixture's own history records five times over: a drive opening onto
     * emptiness because the count table measured everything except the thing that screen needed.
     */
    public function releasedInvoiceCount(int $schoolId): int
    {
        return Invoice::query()
            ->where('school_id', $schoolId)
            ->whereNotNull(Invoice::RELEASE_STAMP_COLUMN)
            ->excludingVoid()
            ->count();
    }

    public function awaitingReviewCount(int $schoolId): int
    {
        return Invoice::query()
            ->where('school_id', $schoolId)
            ->whereNull(Invoice::RELEASE_STAMP_COLUMN)
            ->whereNull('returned_at')
            ->excludingVoid()
            ->count();
    }

    /**
     * Bills sent back to Finance — unreleased AND returned.
     *
     * COUNTED SEPARATELY FROM THE ABOVE, NOT SUMMED, for the reason the decided-documents pair
     * gives: the screen renders these as their own stat card, and a fixture with a healthy queue
     * and zero returns renders that card as a bare `0` — which is indistinguishable from a card
     * reading the wrong number. Until Phase B builds Finance's own queue this card is the ONLY
     * surface a returned bill has anywhere in the system, so a zero here means the drive cannot
     * see the thing the card exists for.
     */
    public function returnedToFinanceCount(int $schoolId): int
    {
        return Invoice::query()
            ->where('school_id', $schoolId)
            ->whereNull(Invoice::RELEASE_STAMP_COLUMN)
            ->whereNotNull('returned_at')
            ->excludingVoid()
            ->count();
    }

    public function openInvoiceCount(int $schoolId): int
    {
        return Invoice::query()
            ->where('school_id', $schoolId)
            ->excludingVoid()
            ->withSum('allocations as allocated_minor', 'amount_minor')
            ->withSum(['creditNotes as approved_credit_minor' => fn ($q) => $q->where('status', CreditNoteStatus::Approved->value)], 'amount_minor')
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->total->toKobo()
                - (int) ($invoice->getAttribute('allocated_minor') ?? 0)
                - (int) ($invoice->getAttribute('approved_credit_minor') ?? 0) > 0)
            ->count();
    }

    /**
     * HOW MANY CREDIT NOTES HAVE ALREADY BEEN DECIDED — U13's precondition, and the column the
     * decisions surface opens onto.
     *
     * The count table's rule is that a zero means the screen cannot show anything, and this screen's
     * whole subject is documents that have LEFT the pending queue. Every other column beside it
     * counts something a screen authors; this one counts something a screen reads back, and the
     * fixture's pending credit note — which the queue shows — is deliberately NOT in it.
     *
     * Counted by the same predicate the read model uses ({@see InvoiceReadModel::decidedCreditNotes})
     * rather than by "not submitted", so a status added later does not silently join the count.
     */
    public function decidedCreditNoteCount(int $schoolId): int
    {
        return CreditNote::query()
            ->where('school_id', $schoolId)
            ->whereIn('status', [CreditNoteStatus::Approved->value, CreditNoteStatus::Rejected->value])
            ->count();
    }

    /**
     * HOW MANY VOID REQUESTS HAVE ALREADY BEEN DECIDED — U14's half, and a SEPARATE question from the
     * one above rather than the same one twice.
     *
     * The decisions surface merges two feeds. A fixture carrying decided credit notes and no decided
     * voids renders a full-looking table in which one of the two types is missing entirely, and the
     * type badge is the only thing that would say so — which is exactly the reading a single combined
     * column would hide.
     */
    public function decidedVoidRequestCount(int $schoolId): int
    {
        return VoidRequest::query()
            ->where('school_id', $schoolId)
            ->whereIn('status', [VoidRequestStatus::Approved->value, VoidRequestStatus::Rejected->value])
            ->count();
    }

    /**
     * The workhorse behind almost every settlement state below — one scheduled invoice, one
     * free-text tuition charge.
     *
     * IT NAMES A DESTINATION AS OF S11 COMMIT 2, and it has to: `finance_invoice_lines_destination_guard`
     * refuses a charge line with a NULL `bank_account_id`, so a fixture line without one no longer
     * reaches the table at all. The account is the school's own drive account — the SAME row
     * {@see ensureBankAccount()} makes and {@see bankAccountId()} resolves for the payments recorded
     * against these invoices — because "the tuition money lands in the account the tuition money
     * lands in" is the ordinary case, and a fixture whose charge and whose payment disagreed would
     * render every settled invoice under the allocation screen's mismatch banner.
     *
     * THE SCHOOL COMES FROM THE AMBIENT CONTEXT, not from the invoice, and it cannot come from the
     * invoice: the destination must be chosen BEFORE the line is written. Every caller runs inside
     * `ActiveSchool::runFor($schoolId, …)` (SeedDriveFixture:155, :181), which is the same
     * precondition {@see bankAccountCount()} already documents for its scoped reads — and
     * GenerateInvoice refuses outright without a context, so there is no path here that has none.
     * `getOrFail()` returns the School MODEL, hence `->id`.
     */
    private function invoice(string $enrollmentUuid, int $kobo): Invoice
    {
        return app(GenerateInvoice::class)->handle(
            $enrollmentUuid,
            [new InvoiceLineSpec(
                'Tuition',
                Money::fromKobo($kobo),
                bankAccountId: $this->ensureBankAccount(ActiveSchool::getOrFail()->id),
            )],
            InvoiceKind::Scheduled,
        );
    }
}
