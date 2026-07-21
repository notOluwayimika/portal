<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\Invoice;
use App\Finance\Services\SubledgerPoster;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\Sequences\Sequences;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Raise one MULTI-LINE invoice for one enrollment and post its charge to the
 * subledger — the whole use case in one transaction (the invoice, all its lines,
 * and the ledger charge commit together or not at all).
 *
 * The enrollment is read through the ACL port ({@see BillableEnrollmentProvider}),
 * never StudentCurriculum — so this file has no academic import, which is the
 * boundary the arch test guards.
 *
 * F6 — total = SUM(lines). The caller supplies LINES and never a total: the total
 * is derived here by exact integer addition (Money::plus) and snapshotted onto the
 * invoice inside this transaction. There is no code path by which a caller can
 * assert a total, and the `finance_invoices_total_immutable` DB trigger denies any
 * later edit of the money columns — so the snapshot cannot drift afterwards.
 * Money::plus() also throws on a currency mismatch, which makes a mixed-currency
 * invoice impossible by construction rather than by validation.
 *
 * DUPLICATE PREVENTION — "at most one ACTIVE invoice per enrollment episode" is a
 * SET-based invariant: it constrains the set of invoices for an enrollment, which
 * no single Invoice aggregate can see. The authoritative guard is therefore the
 * DB's UNIQUE(school_id, active_enrollment_key) over the generated column; the
 * pre-check below exists only to turn the common case into a friendly 422 instead
 * of a duplicate-key error. Under concurrency the pre-check CANNOT hold (both
 * racers read a snapshot in which no invoice exists) — the unique index is what
 * actually holds, which is why the duplicate-key error is translated rather than
 * treated as an impossible case. Proven in InvoiceConcurrencyTest.
 */
final class GenerateInvoice
{
    /** MySQL duplicate-entry error code. */
    private const DUPLICATE_ENTRY = 1062;

    public function __construct(
        private readonly BillableEnrollmentProvider $enrollments,
        private readonly SubledgerPoster $ledger,
    ) {}

    /**
     * @param  list<InvoiceLineSpec>  $lines
     */
    public function handle(string $enrollmentUuid, array $lines): Invoice
    {
        $enrollment = $this->enrollments->findByUuid($enrollmentUuid);

        if ($enrollment === null) {
            throw new BusinessRuleException('No billable enrollment found for the given reference.');
        }

        // CROSS-SCHOOL GUARD. `student_curricula` has no school_id and
        // StudentCurriculum is deliberately unscoped (v10 §14), so the enrollment
        // LOOKUP is not School-constrained: an enrollment uuid belonging to another
        // School resolves perfectly well. Isolation is therefore asserted here, by
        // comparing the episode's own School — resolved from `students.school_id`
        // (falling back to `curricula.school_id`) in BillableEnrollmentAdapter —
        // against the Active School.
        //
        // VERIFIED BEHAVIOUR, which is subtler than "compare A to B": Student and
        // Curriculum are BOTH School-scoped, so under School A's context a School-B
        // episode resolves its relations to null and the adapter reports school 0,
        // not 2. The guard then rejects on 0 ≠ A. It is doubly fail-closed — it
        // refuses both a known-foreign School and an undeterminable one — and the
        // two cases are reported separately so a real failure is diagnosable rather
        // than mislabelled as a cross-School attempt.
        //
        // Constitution rule 1: no cross-School financial operation, ever. Rule 13:
        // context is explicit or absent, never inferred — so a financial write with
        // no context fails closed rather than adopting whatever the row says.
        $activeSchoolId = ActiveSchool::id();

        if ($activeSchoolId === null) {
            throw new BusinessRuleException('No active School context: an invoice cannot be raised.');
        }

        if ($enrollment->schoolId === 0) {
            throw new BusinessRuleException(
                'The School owning this enrollment could not be determined; it cannot be billed.'
            );
        }

        if ($enrollment->schoolId !== $activeSchoolId) {
            throw new BusinessRuleException('That enrollment belongs to another School.');
        }

        if ($lines === []) {
            throw new BusinessRuleException('An invoice must have at least one line.');
        }

        // Resolve percentage reductions into concrete amounts FIRST, so everything below
        // — the throw checks, the fold, the persisted rows — operates on a single,
        // uniform shape: concrete signed lines. A stored line is never "10%"; it is the
        // exact naira reduction that percentage produced, which is what §5 snapshot
        // integrity requires (a historical statement must not recompute a percentage
        // against numbers that may have moved).
        $lines = $this->resolvePercentages($lines);

        // The positivity rule is now SCOPED BY KIND, and each half is stricter than the
        // single rule it replaces — a charge must still be strictly positive, and a
        // reduction must be strictly negative. Neither may be zero: a zero line carries
        // no arithmetic and no information, and silently accepting one would let a
        // "waiver" that waives nothing look applied.
        foreach ($lines as $line) {
            if ($line->resolvedAmount()->isZero()) {
                throw new BusinessRuleException('An invoice line amount may not be zero.');
            }

            if ($line->isReduction()) {
                if (! $line->resolvedAmount()->isNegative()) {
                    throw new BusinessRuleException('A waiver or discount line must be negative.');
                }

                continue;
            }

            if ($line->resolvedAmount()->isNegative()) {
                throw new BusinessRuleException('Every invoice charge line must be positive.');
            }
        }

        // F6: the total is DERIVED, never supplied. Exact integer addition, and a
        // LITERAL SIGNED SUM — it does not branch on kind. Reductions carry a negative
        // amount, so `plus` nets them without any special case: sign carries the
        // arithmetic, kind carries the meaning. This is why F6's trigger needs no change
        // — the equality is still established here and frozen there.
        $total = array_reduce(
            $lines,
            static fn (?Money $carry, InvoiceLineSpec $line) => $carry === null
                ? $line->resolvedAmount()
                : $carry->plus($line->resolvedAmount()),
        );

        // Reductions may bring a total to zero, but never below it. A negative invoice
        // would mean the School owes the student, which is a credit note or refund
        // (§10, later) — never an invoice. Ratified in accounting-policy.md §5.
        if ($total->isNegative()) {
            throw new BusinessRuleException(
                'Reductions may not exceed the charges on an invoice: the total would be negative.'
            );
        }

        try {
            return DB::transaction(function () use ($enrollment, $lines, $total) {
                $this->assertNoActiveInvoice($enrollment->schoolId, $enrollment->enrollmentId);

                $number = Sequences::next('finance_invoice', (string) $enrollment->schoolId);

                $invoice = Invoice::create([
                    'school_id' => $enrollment->schoolId,
                    'student_id' => $enrollment->studentId,
                    'student_curriculum_id' => $enrollment->enrollmentId,
                    'number' => $number,
                    'status' => InvoiceStatus::Issued,
                    'billed_to_name' => $enrollment->studentName,
                    'academic_context' => $enrollment->academicContext,
                    'total' => $total,
                ]);

                foreach ($lines as $line) {
                    $invoice->lines()->create([
                        'school_id' => $enrollment->schoolId,
                        'description' => $line->description,
                        'kind' => $line->kind,
                        'note' => $line->note,
                        'amount' => $line->resolvedAmount(),
                        'fee_item_id' => $line->feeItemId,
                    ]);
                }

                $this->ledger->post(
                    $enrollment->schoolId,
                    $enrollment->studentId,
                    LedgerEntryType::Charge,
                    $total,
                    'invoice',
                    $invoice->id,
                    "Invoice #{$number} — ".count($lines).' line(s)',
                );

                return $invoice->load('lines');
            });
        } catch (QueryException $e) {
            // The set-based invariant, enforced by the DB, surfacing as a domain error.
            if ($this->isActiveEnrollmentCollision($e)) {
                throw new BusinessRuleException(
                    'This enrollment already has an active invoice. Void it before billing again.'
                );
            }

            throw $e;
        }
    }

    /**
     * Turn every percentage-reduction spec into a concrete-amount spec.
     *
     * SEMANTIC (stated because the brief's "10% off the tuition line" implies per-line
     * targeting and this does NOT do that): a percentage reduction is computed against
     * the invoice's GROSS CHARGES — the signed sum of every charge-kind line — not
     * against one named line. "10% waiver" means 10% off the bill. Per-line targeting is
     * a later refinement with its own design; it is deliberately not invented here on a
     * fragile description/index reference.
     *
     * The magnitude is `grossCharges->percentage($p)` — the banker's-rounded op — and
     * the resulting line stores that concrete negative naira figure, never the percent.
     *
     * @param  list<InvoiceLineSpec>  $lines
     * @return list<InvoiceLineSpec>
     */
    private function resolvePercentages(array $lines): array
    {
        $hasPercentage = false;
        foreach ($lines as $line) {
            if ($line->isPercentage()) {
                $hasPercentage = true;
                if (! $line->isReduction()) {
                    throw new BusinessRuleException('A percentage may only be applied to a waiver or discount line.');
                }
            }
        }

        if (! $hasPercentage) {
            return $lines;
        }

        // Gross = the signed sum of the CHARGE lines only. Percentage reductions reduce
        // the charges; folding other reductions into the base would let two reductions
        // compound in an order-dependent way.
        $grossCharges = null;
        foreach ($lines as $line) {
            if (! $line->isReduction() && ! $line->isPercentage()) {
                $grossCharges = $grossCharges === null
                    ? $line->resolvedAmount()
                    : $grossCharges->plus($line->resolvedAmount());
            }
        }

        if ($grossCharges === null) {
            throw new BusinessRuleException('A percentage reduction needs at least one charge line to reduce.');
        }

        return array_map(
            fn (InvoiceLineSpec $line) => $line->isPercentage()
                // percentage() returns a positive magnitude; a reduction stores it negated.
                ? $line->withAmount($grossCharges->percentage($line->percent)->times(-1))
                : $line,
            $lines,
        );
    }

    /**
     * Friendly-path pre-check only — NOT the guarantee. See the class docblock.
     */
    private function assertNoActiveInvoice(int $schoolId, int $enrollmentId): void
    {
        $exists = Invoice::query()
            ->where('school_id', $schoolId)
            ->where('student_curriculum_id', $enrollmentId)
            ->excludingVoid()
            ->exists();

        if ($exists) {
            throw new BusinessRuleException(
                'This enrollment already has an active invoice. Void it before billing again.'
            );
        }
    }

    private function isActiveEnrollmentCollision(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === self::DUPLICATE_ENTRY
            && str_contains($e->getMessage(), 'finance_invoices_active_enrollment_unique');
    }
}
