<?php

namespace App\Finance\Services;

use App\Finance\Models\StudentAccount;
use App\Support\Money;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The accounts read side — the bursar's front door. A paginated view over
 * finance_student_accounts (one row per (school, student) that has ledger activity),
 * plus the two School-wide KPI totals.
 *
 * School isolation is automatic: StudentAccount uses BelongsToSchool, so every query
 * below — the page AND the KPI sums — is already constrained to the Active School. The
 * KPIs are therefore School-wide by construction, never a global figure.
 *
 * NO student join lives here. A row's balance and dates are Finance's own; the student's
 * name/admission number are Academics facts resolved by the ACL port at the controller
 * edge (arch rule 3 — Finance never re-joins the students table). Search is the same
 * boundary in reverse: the controller resolves matching ids via the port and passes them
 * in as $studentIds, so this model filters on its own student_id column and nothing else.
 */
final class AccountReadModel
{
    /** Balance-sign partitions — the status filter. A row is in exactly one. */
    public const STATUS_OUTSTANDING = 'outstanding'; // balance > 0 — the student owes

    public const STATUS_IN_CREDIT = 'in_credit';     // balance < 0 — the school owes the student

    public const STATUS_SETTLED = 'settled';         // balance == 0 — square

    /**
     * One page of accounts for the active School, most-owed first by default.
     *
     * @param  list<int>|null  $studentIds  restrict to these student ids (search result); null = no search.
     *                                      An empty list means "search matched nobody" → an empty page (not "all").
     * @param  string|null  $status  one of the STATUS_* partitions, or null for all.
     * @param  string  $sortDir  'asc' | 'desc' on the signed balance.
     * @return LengthAwarePaginator<StudentAccount>
     */
    public function paginate(?array $studentIds, ?string $status, string $sortDir, int $perPage): LengthAwarePaginator
    {
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        return StudentAccount::query()
            ->when($studentIds !== null, fn ($q) => $q->whereIn('student_id', $studentIds))
            ->when($status === self::STATUS_OUTSTANDING, fn ($q) => $q->where('balance_minor', '>', 0))
            ->when($status === self::STATUS_IN_CREDIT, fn ($q) => $q->where('balance_minor', '<', 0))
            ->when($status === self::STATUS_SETTLED, fn ($q) => $q->where('balance_minor', '=', 0))
            ->orderBy('balance_minor', $sortDir)
            ->orderBy('id') // stable tie-break so pages don't reshuffle equal balances
            ->paginate($perPage);
    }

    /**
     * The two School-wide KPI totals, computed in SQL over ALL of the School's accounts —
     * never the current page, and UNAFFECTED by search or the status filter (they are the
     * denominator the filtered view is read against).
     *
     *   total_receivables = Σ balance_minor WHERE balance_minor > 0   (money owed TO the school)
     *   total_credit      = Σ |balance_minor| WHERE balance_minor < 0  (money the school owes)
     *
     * Float-safe because it is an INTEGER SQL SUM over a bigint column — the money never
     * becomes a JS/PHP float. A partition with no rows sums to null → coerced to 0. Currency
     * follows the rest of Finance (NGN default, single-currency); a mixed-currency ledger is
     * out of scope until the accounting policy introduces it.
     *
     * @return array{total_receivables: Money, total_credit: Money}
     */
    public function kpis(): array
    {
        $receivables = (int) StudentAccount::query()->where('balance_minor', '>', 0)->sum('balance_minor');
        $creditSigned = (int) StudentAccount::query()->where('balance_minor', '<', 0)->sum('balance_minor'); // ≤ 0

        return [
            'total_receivables' => Money::fromKobo($receivables),
            'total_credit' => Money::fromKobo(-$creditSigned), // magnitude of the negative sum
        ];
    }
}
