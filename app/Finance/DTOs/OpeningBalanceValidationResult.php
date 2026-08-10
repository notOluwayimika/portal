<?php

namespace App\Finance\DTOs;

use App\Support\Money;

/**
 * Everything one validation pass observed about a WCBS opening-balance extract (§9 step 4a's report,
 * lifted out of the console command by step 5b-iii so a queued job can produce the same facts).
 *
 * IT CARRIES THE OPERATOR REPORT'S INPUTS, NOT ITS PROSE. The command renders these as a table and
 * a set of lists; the operator screen renders the same figures as HTML. Neither rendering is in here,
 * because the moment one of them lived in this object the other would start drifting from it.
 *
 * THE PRIVACY DISCIPLINE IS A PROPERTY OF WHAT IS IN THIS OBJECT, not of who reads it. Every list
 * below holds LINE NUMBERS, ADMISSION NUMBERS and FINDING CODES — never a student's name, never a
 * per-student figure. The two money fields are batch aggregates (§1 L2's two sides), which is why
 * they are allowed to be here at all. A field added to this DTO is a field that reaches every
 * surface, so the rule is enforced by what the object can hold rather than by each consumer
 * remembering it.
 *
 * @phpstan-type Finding array{code: string, message: string}
 */
final class OpeningBalanceValidationResult
{
    /**
     * @param  int  $rowCount  rows STAGED into finance_opening_balance_rows
     * @param  int  $fileRowCount  data lines READ that carried content — the ingest-completeness counterpart
     * @param  int  $blankLines  wholly blank physical lines the reader dropped, so content + blank reconciles to the file
     * @param  list<array{line: int, admission_number: string, codes: list<string>}>  $rejected
     * @param  list<array{admission_number: string, code: string}>  $l1Failures
     * @param  list<array{line: int, admission_number: string}>  $unresolved
     * @param  list<string>  $absent  admission numbers in the School and not in the file
     * @param  list<array{line: int, admission_number: string, fee_type_label: string, first: int}>  $duplicateInFile
     * @param  list<array{line: int, admission_number: string, columns: list<string>}>  $tooLong
     * @param  list<array{line: int, admission_number: string, code: string}>  $refusedAtWrite
     * @param  Money  $statedSum  Σ of the student totals — §1 L2's left-hand side
     * @param  int  $statedContributors  students counted into $statedSum
     * @param  int  $derivedContributors  how many of those were DERIVED from their own balances
     *                                    because the file states no student_total_balance for them.
     *                                    Carried so the console can say so on EVERY run: a figure
     *                                    labelled "stated" that is in fact computed is the kind of
     *                                    quiet mislabelling this feature's whole L2 exists to avoid,
     *                                    and until now only the MISMATCH path named the derivation —
     *                                    so a clean run never showed it.
     * @param  Money  $controlTotal  the operator's typed attestation — §1 L2's right-hand side
     * @param  list<Finding>  $batchFindings
     */
    public function __construct(
        public readonly int $rowCount,
        public readonly int $fileRowCount,
        public readonly int $blankLines,
        public readonly array $rejected,
        public readonly array $l1Failures,
        public readonly array $unresolved,
        public readonly array $absent,
        public readonly array $duplicateInFile,
        public readonly array $tooLong,
        public readonly array $refusedAtWrite,
        public readonly int $nullAdmissions,
        public readonly int $duplicateAfterTrim,
        public readonly Money $statedSum,
        public readonly int $statedContributors,
        public readonly int $derivedContributors,
        public readonly Money $controlTotal,
        public readonly array $batchFindings,
    ) {}

    /**
     * Clean means BOTH: no row was rejected and no finding was raised on the batch. It is the same
     * condition the validator uses to choose `validated` over `rejected`, expressed once so a caller
     * cannot re-derive it as "no rejected rows" and quietly drop the batch half.
     */
    public function isClean(): bool
    {
        return $this->rejected === [] && $this->batchFindings === [];
    }

    /** §1 L2's delta in kobo, signed. Printed whether or not the check failed — see the command. */
    public function controlTotalDeltaKobo(): int
    {
        return $this->statedSum->toKobo() - $this->controlTotal->toKobo();
    }
}
