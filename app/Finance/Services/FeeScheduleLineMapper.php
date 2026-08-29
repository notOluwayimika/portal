<?php

namespace App\Finance\Services;

use App\Exceptions\BusinessRuleException;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use App\Finance\Models\FeeSchedule;
use App\Finance\Models\StudentDiscountAward;
use App\Support\ActiveSchool;

/**
 * A fee schedule in, the lines of one term bill out (U6 commit 2). This is the first NON-HUMAN source
 * of {@see InvoiceLineSpec} on a production path: before it, the only production construction sites
 * were GenerateInvoiceRequest::lineSpecs() (a body a human typed into the bursar's modal) and
 * DriveFinanceStates::__invoke (the local drive-fixture console command), with 27 more under tests/.
 * The docblock here originally said "exactly one place in the application", and the commit message and
 * report said the same; cold review counted. Corrected — the claim that matters is narrower and still
 * load-bearing: nothing could turn a fee SCHEDULE into lines, so a bulk run had nothing to bill from.
 *
 * PURE. No HTTP, no queue, no write, and no Money arithmetic of its own — each line carries the item's
 * own Money value unchanged, and the total is still DERIVED inside GenerateInvoice's transaction (F6).
 *
 * IT NAMES ITS SCHOOL. `linesFor()` takes the School as an ARGUMENT and refuses a schedule that is not
 * that School's, so it is correct with ambient context, without it, and under a context that disagrees
 * — see the two guards on the method. Cold review probed all three; the argument-scoped shape is what
 * app/Academics' ACL port adopted for exactly this reason (BillableEnrollmentAdapter::currentEnrollments).
 *
 * MANDATORY ITEMS ONLY, and this is a RULING, not an oversight to be tidied up later. Nothing in the
 * schema records which student takes the bus or eats lunch — `finance_fee_items.is_mandatory` is a
 * property of the PRICE LIST, not of a child — so a cohort run has no way to know who owes an optional
 * item. Guessing would bill transport to a child who walks. Optional items are therefore added per
 * child afterwards, singly through the generate modal or as a supplementary invoice
 * ({@see InvoiceKind::Supplementary}). Do not "fix" this into billing everything.
 *
 * CHARGE LINES ONLY. Every line is {@see InvoiceLineKind::Charge}; `discountPolicyId` and `percent` are
 * never set. THE ORIGINAL REASON HAS EXPIRED AND THE RULE HAS NOT: the discount AWARD (which student
 * gets which policy) now exists — {@see StudentDiscountAward} — so there IS a
 * fact from which a reduction line can be justified. It is just not a fact about a PRICE LIST. This
 * method is handed a schedule and a School and no student, deliberately (see the paragraph below on
 * why it takes a FeeSchedule), so it has nothing to resolve an award against and must not acquire a
 * student in order to. The bulk run appends the reduction per student, to a COPY of what this
 * returns; see {@see ProcessBulkInvoiceRun::reductionSpecFor()}.
 *
 * Keeping reductions out of THIS method still means the finance_invoice_lines_reduction_guard
 * trigger is never reached from the mapper's own output — a schedule cannot produce a policy
 * citation, so it cannot produce one that is absent, non-active or cross-School.
 *
 * IT SNAPSHOTS THE DESTINATION (S11). Each line carries the fee item's `bank_account_id` as it stands
 * at issue, so `finance_invoice_lines` states where the money was destined instead of the reader
 * re-joining to the catalog row later and getting today's answer to a question about the past. That is a SNAPSHOT and never a lookup, in the same sense
 * `description` and `amount` already were — and it is why this method is one of the two writers S11
 * commit 1 touches, the other being the bursar's generate modal.
 *
 * `isDiscountable` is read from the ITEM, never left to the DTO's `true` default — GenerateInvoice
 * re-resolves it server-side anyway (S1 3.6), so a wrong value here would be silently corrected there
 * and the bug would only ever show up in something that read these specs without the Action.
 *
 * DETERMINISTIC. Ordered by `sort_order` then `id`. `sort_order` alone is not a total order — the
 * column carries no uniqueness constraint and CreateFeeSchedule defaults it to the array index
 * (CreateFeeSchedule.php:70), so two items can share one — and MySQL guarantees nothing about the order
 * of equal-key rows. That is not theoretical: with 16,384 tied mandatory items at stock
 * sort_buffer_size, dropping the tiebreak produced 2 inversions on MySQL 8.0.43 (threshold ~8k stock,
 * ~1k at sort_buffer_size=32768). Two runs over one schedule must produce identical lines in identical
 * order, because a bulk run re-driven after a partial failure must not bill a differently-ordered
 * invoice to the students it reaches twice.
 *
 * TIE DETERMINISM IS LOCAL TO THIS MAPPER, and four other sites still order items by `sort_order`
 * alone — see docs/handoff/tickets/fee-item-tie-order-differs-across-read-paths.md.
 *
 * IT TAKES A FeeSchedule, NOT (term, class level). The caller pins ONE version for the whole batch.
 * Resolving the schedule in here would re-read it per student, so an approval or a supersession landing
 * mid-batch would split one cohort run silently across two price lists.
 */
final class FeeScheduleLineMapper
{
    /**
     * @param  int  $schoolId  the School the batch is running for. NOT derived from ambient context and
     *                         not taken from the schedule — it is the caller's declaration of which
     *                         School is being billed, which is the only thing the guards below can
     *                         check the schedule against.
     * @return list<InvoiceLineSpec>
     *
     * @throws BusinessRuleException when the schedule cannot produce a bill — refused ONCE for the
     *                               batch, naming the schedule, rather than N times naming students.
     */
    public function linesFor(FeeSchedule $schedule, int $schoolId): array
    {
        // ISOLATION, ASSERTED AGAINST THE ARGUMENT. Constitution rule 1: no cross-School financial
        // operation, ever. Cold review probed the ambient-only version both ways and both were wrong:
        // under School A's context a School-B schedule had its item read EMPTIED by FeeItem's
        // SchoolScope and was refused as "has no mandatory items" — a message that sends an operator
        // hunting a price list that is fine — and with NO ambient context the same read returned
        // another School's lines outright, because FeeItem is absent from `rbac.fail_closed_models`
        // and the scope is therefore fail-open (SchoolScope.php:48-50, :59-64).
        if ((int) $schedule->school_id !== $schoolId) {
            throw new BusinessRuleException(
                "Fee schedule [{$schedule->uuid}] belongs to another School; it cannot be billed for school#{$schoolId}."
            );
        }

        // AND THE AMBIENT CONTEXT, IF ANY, MUST AGREE. The guard above alone does NOT make this method
        // independent of ambient context, and saying so would repeat the mistake it fixes: the item
        // read below still carries FeeItem's SchoolScope, so a batch declared for School A running
        // under a stale School-B context would pass the guard, read zero items, and be refused as
        // "has no mandatory items" — the exact wrong-reason failure, one layer down.
        //
        // The ACL port closes this by stripping the scope and filtering explicitly. THIS FILE MAY NOT:
        // `withoutGlobalScope(` inside app/Finance is a HARD boundary-lint failure (§17.1 rule 4,
        // bin/ci-boundary-lint.php:159), and the port's six calls are baselined exceptions in
        // app/Academics, not a pattern Finance may copy. So the disagreement is REFUSED instead of
        // routed around, which leaves exactly two states the read below can be in: unscoped (no
        // context) or scoped to precisely $schoolId. Both give the same answer.
        $ambientSchoolId = ActiveSchool::id();

        if ($ambientSchoolId !== null && (int) $ambientSchoolId !== $schoolId) {
            throw new BusinessRuleException(
                "Fee schedule [{$schedule->uuid}] cannot be billed for school#{$schoolId} from another School's context."
            );
        }

        // (c) NOT BILLABLE. The set of states a bill may be raised from lives on the enum
        // ({@see FeeScheduleStatus::billable()}) and is read here AND by FeeScheduleLookup::activeFor().
        // ONE SYMBOL, NOT ONE COMMENT: this file previously tested `!== self::Active` in PHP while the
        // lookup tested `where('status', 'active')` in SQL, with a docblock on each asserting they were
        // "one rule, not two". They were two rules that happened to agree — cold review called it, and
        // the enum now carries the ruling and its reasons. Widening the set moves both sites.
        if (! $schedule->status->isBillable()) {
            throw new BusinessRuleException(
                "Fee schedule [{$schedule->uuid}] is {$schedule->status->value}; only an active schedule may be billed from."
            );
        }

        // The relation query, ordered here rather than trusting whatever the caller eager-loaded —
        // `->with('items')` anywhere upstream would otherwise decide this method's output order.
        $items = $schedule->items()
            ->where('is_mandatory', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // (a) NOTHING MANDATORY. A schedule of purely optional items is a real, authorable thing, and it
        // yields zero lines — which GenerateInvoice would refuse per student with "An invoice must have
        // at least one line", once for every child in the cohort. Refusing here reports the schedule
        // once and names the schedule, which is what an operator can actually act on. The two guards at
        // the top of this method exist so that this message means what it says: an empty read can no
        // longer be an isolation failure wearing a pricing failure's message.
        if ($items->isEmpty()) {
            throw new BusinessRuleException(
                "Fee schedule [{$schedule->uuid}] has no mandatory items, so it cannot produce a term bill."
            );
        }

        // (b) MIXED CURRENCY. finance_fee_items.amount_currency carries a SHAPE check only (three
        // uppercase letters — 2026_08_01_120000_add_currency_shape_checks.php), and nothing constrains
        // the items of one schedule to agree, so a mixed schedule is constructible. Left alone it
        // detonates inside GenerateInvoice's transaction, where Money::plus throws on a currency
        // mismatch while folding the total — naming a student, mid-batch, for a defect in the price
        // list. Caught here it names the schedule, before the first invoice is written.
        $currencies = $items->map(fn ($item) => $item->amount->currency)->unique()->values();

        if ($currencies->count() > 1) {
            throw new BusinessRuleException(
                "Fee schedule [{$schedule->uuid}] mixes currencies (".$currencies->implode(', ').'); its mandatory items must agree.'
            );
        }

        return array_values($items->map(fn ($item) => new InvoiceLineSpec(
            description: $item->description,
            amount: $item->amount,
            // The INTEGER id. uuids are the wire convention (U8 commit 1 made the generate endpoint
            // refuse integers on the way in); ids are what finance_invoice_lines stores, and this
            // mapper is server-side on both ends — it never crosses the wire.
            feeItemId: $item->id,
            kind: InvoiceLineKind::Charge,
            isDiscountable: $item->is_discountable,
            // THE DESTINATION, SNAPSHOTTED (S11) — the entire point of the field. What is written is
            // the item's account AS IT IS AT ISSUE; repointing the fee item afterwards moves nothing
            // on any line already billed, which is precisely what the live lookup through
            // `fee_item_id` could not promise.
            //
            // ALWAYS PRESENT: `finance_fee_items.bank_account_id` is NOT NULL with no default
            // (2026_08_10_120000:108-110), so there is no absent case to handle and no fallback to
            // invent. A schedule cannot produce a charge line with no destination, which is what
            // makes the S11 commit-2 trigger safe to require one.
            //
            // THE RAW ATTRIBUTE, NOT `$item->bankAccount`. The relation is nullable in PHP because
            // BankAccount carries SchoolScope; reading the integer column takes the fact off the row
            // this method already loaded, with no second query and no scope in the path.
            bankAccountId: (int) $item->bank_account_id,
        ))->all());
    }
}
