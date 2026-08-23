<?php

namespace App\Finance\Services;

use App\Finance\DTOs\OpeningBalanceValidationResult;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Support\Money;

/**
 * WHAT THIS BATCH SAYS, in the words the sign convention was agreed in — the control the cutover
 * otherwise does not have.
 *
 * THE HOLE IT FILLS. §1's L2 compares the operator's typed control total against Σ of the same column
 * in the same file. If the operator reads that figure off the extract — which is the natural thing to
 * do — then an INVERTED sign convention matches perfectly. Every family in credit is filed as owing,
 * every family owing is filed as in credit, L1 and L2 are both green, the batch posts, and G1/G1b
 * make it permanent because a posted batch can never be un-posted or deleted. No arithmetic control
 * can catch an error that is consistent with itself.
 *
 * So the batch states its own reading in plain language before anyone approves it: how many students
 * are in CREDIT and for how much, how many are in ARREARS and for how much, and the school's net
 * position. That is a sentence a bursar either agrees with or does not — it converts an invisible
 * inversion into a claim someone has to sign.
 *
 * IT IS COMPUTED, NEVER STORED. Every figure comes from the staged rows that would actually post, so
 * it cannot disagree with them; a persisted copy would be a second representation of the same facts
 * and the two would eventually differ. It also means no schema change and no migration.
 *
 * PER STUDENT, NOT PER ROW, FOR THE POSITION — and that is NOT the same thing as what posts.
 *
 * What a bursar means by "in credit" is the NET position of the family, not that one of their lines
 * happens to be negative, so credit/arrears are classified on the net. That is a statement about the
 * ACCOUNT BALANCE the cutover produces, and it is true.
 *
 * IT IS NOT A STATEMENT ABOUT WHAT WILL POST, and an earlier version of this docblock said it was —
 * it claimed the classification "matches the posting" because {@see PostOpeningBalanceBatch} step 2
 * nets a student's credits. That justification is FALSE and it produced a false sentence.
 * PostOpeningBalanceBatch posts per ROW and GROSS: it skips only a row whose OWN balance is zero
 * (`:192`), charges every remaining positive row (`:201-215`), and nets only the NEGATIVE rows —
 * credits against credits, never credits against charges — into one migrated payment (`:241`).
 *
 * So a legal multi-row student with +5,000 Tuition and −5,000 Bus nets to zero AND posts both a
 * charge and a migrated payment. Calling them "square" is true of their position; saying they "will
 * post nothing" was false, and this class used to say exactly that. The two are now reported
 * separately: a student whose every row is zero posts nothing, a student whose rows CANCEL posts both
 * sides. A single-row file — which is the cutover's own shape — has no offsetting students at all,
 * which is why twelve arms and a drive all passed over it.
 *
 * PRIVACY. Counts and batch-level aggregates only — no student id, no admission number, no per-student
 * figure. This reaches an operator screen and a report that leaves the building, and the same rule
 * that governs {@see OpeningBalanceValidationResult} governs it: the object cannot
 * hold what a consumer must not print.
 */
final class OpeningBalanceInterpretation
{
    /**
     * @return array{
     *     students: int,
     *     credit_students: int, credit_total: Money,
     *     arrears_students: int, arrears_total: Money,
     *     square_students: int, offsetting_students: int,
     *     net: Money,
     *     convention: string,
     *     sentence: string,
     * }
     */
    public function for(OpeningBalanceBatch $batch): array
    {
        // OK rows only — the same filter PostOpeningBalanceBatch posts through. A rejected row will
        // not become money, so summarising it would describe a batch nobody is going to post.
        $rows = OpeningBalanceRow::query()
            ->where('batch_id', $batch->id)
            ->where('status', OpeningBalanceRowStatus::Ok)
            ->get();

        /** @var array<int|string, Money> $netByStudent */
        $netByStudent = [];
        /** @var array<int|string, bool> $hasMovingRow whether ANY of the student's rows is non-zero */
        $hasMovingRow = [];

        foreach ($rows as $row) {
            $balance = $row->balance;
            if ($balance === null) {
                continue;
            }

            // Keyed on the resolved student where there is one, and on the admission number where
            // there is not, so an unresolved row is still counted as its own family rather than
            // silently merged with every other unresolved row under a null key.
            $key = $row->student_id ?? 'admission:'.(string) $row->admission_number;

            $netByStudent[$key] = isset($netByStudent[$key])
                ? $netByStudent[$key]->plus($balance)
                : $balance;

            // TRACKED SEPARATELY FROM THE NET, because the net cannot answer it. PostOpeningBalanceBatch
            // skips a row on ITS OWN balance being zero (`:192`), so "this student posts nothing" is a
            // fact about their ROWS, and a student whose rows cancel has a zero net and two postings.
            $hasMovingRow[$key] = ($hasMovingRow[$key] ?? false) || ! $balance->isZero();
        }

        $creditStudents = 0;
        $arrearsStudents = 0;
        $squareStudents = 0;
        $offsettingStudents = 0;
        $creditTotal = Money::fromKobo(0);
        $arrearsTotal = Money::fromKobo(0);
        $net = Money::fromKobo(0);

        foreach ($netByStudent as $key => $position) {
            $net = $net->plus($position);

            if ($position->isZero()) {
                // A net of zero is TWO different situations and they must not be reported as one.
                //
                //   every row zero      — the student is square and genuinely posts nothing.
                //   rows that CANCEL    — +5,000 Tuition against −5,000 Bus. Net zero, and the posting
                //                         writes a charge AND a migrated payment, because it works per
                //                         row and skips only a row that is itself zero.
                //
                // Collapsing them is how this class came to tell an operator that a student "will post
                // nothing" about a student for whom two ledger rows were about to be written.
                if ($hasMovingRow[$key] ?? false) {
                    $offsettingStudents++;
                } else {
                    $squareStudents++;
                }

                continue;
            }

            if ($position->isNegative()) {
                $creditStudents++;
                // Reported as a POSITIVE magnitude, because "in credit for ₦8,461.00" is what a
                // bursar says. The sign lives in `net`, once, where it means the school's position.
                $creditTotal = $creditTotal->plus($position->times(-1));

                continue;
            }

            $arrearsStudents++;
            $arrearsTotal = $arrearsTotal->plus($position);
        }

        return [
            'students' => count($netByStudent),
            'credit_students' => $creditStudents,
            'credit_total' => $creditTotal,
            'arrears_students' => $arrearsStudents,
            'arrears_total' => $arrearsTotal,
            'square_students' => $squareStudents,
            'offsetting_students' => $offsettingStudents,
            'net' => $net,
            'convention' => OpeningBalanceFileValidator::SIGN_CONVENTION,
            'sentence' => $this->sentence($creditStudents, $creditTotal, $arrearsStudents, $arrearsTotal, $squareStudents, $offsettingStudents, $net),
        ];
    }

    /**
     * The claim, as one paragraph an operator agrees with or does not.
     *
     * The NET is stated in the school's direction and in words, never as a signed figure alone: a
     * bursar reading "-3,476,400.00" has to remember which way the sign points, which is the very
     * confusion this control exists to catch. "Owes families" / "is owed by families" cannot be read
     * two ways.
     */
    private function sentence(int $creditStudents, Money $creditTotal, int $arrearsStudents, Money $arrearsTotal, int $squareStudents, int $offsettingStudents, Money $net): string
    {
        $parts = [
            sprintf('%d student(s) are in CREDIT — the school owes them %s in total.', $creditStudents, $creditTotal->format()),
            sprintf('%d student(s) are in ARREARS — they owe the school %s in total.', $arrearsStudents, $arrearsTotal->format()),
            sprintf('%d student(s) have a zero balance and will post nothing.', $squareStudents),
        ];

        // NAMED ONLY WHEN IT HAPPENS, and never folded into the line above it. These students net to
        // zero and still post BOTH a charge and a migrated payment, because the posting works per row.
        // A single-row-per-student file cannot produce one, so on the cutover's own shape this clause
        // is absent — which is exactly why its absence must not be mistaken for "cannot happen".
        if ($offsettingStudents > 0) {
            $parts[] = sprintf(
                '%d student(s) have lines that CANCEL to zero — they will still post both a charge and a credit.',
                $offsettingStudents,
            );
        }

        if ($net->isZero()) {
            $parts[] = 'Net: the two sides cancel exactly.';
        } elseif ($net->isNegative()) {
            $parts[] = sprintf('Net: the school OWES FAMILIES %s.', $net->times(-1)->format());
        } else {
            $parts[] = sprintf('Net: the school IS OWED %s by families.', $net->format());
        }

        $parts[] = 'If that is not what this file means, the sign convention is inverted — do not approve it.';

        return implode(' ', $parts);
    }
}
