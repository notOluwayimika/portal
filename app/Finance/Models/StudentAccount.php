<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * The per-student account: a PROJECTION + LOCK ANCHOR over the signed ledger, one
 * row per (school, student). Its balance is `SUM(signed ledger amount_minor)` for
 * that student — a charge positive, a payment/reversal negative — so a negative
 * balance is credit the school owes the student.
 *
 * DELIBERATELY NOT AppendOnly. Every other finance model is append-only (immutable
 * facts); this one is the single mutable projection — the balance moves as the
 * ledger grows. It is not immutable-by-trigger; its integrity is (1) the atomic
 * upsert-increment in SubledgerPoster::post (`balance = balance + delta`, skew-free
 * without an app lock) and (2) finance:reconcile-accounts, which re-derives the
 * balance from the ledger and reports drift. The intentional mutable exception is
 * pinned in tests/Feature/Finance/SchemaConventionsTest.php.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $student_id
 * @property Money $balance
 */
class StudentAccount extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_student_accounts';

    protected $guarded = ['id'];

    protected $casts = [
        'balance' => MoneyCast::class.':balance_minor,balance_currency',
    ];

    /**
     * Available credit — DERIVED, never stored (fork 3). A negative balance means
     * the school owes the student; the credit is its magnitude. A zero-or-positive
     * balance (the student owes, or is square) has no credit.
     */
    public function availableCredit(): Money
    {
        return $this->balance->isNegative()
            ? $this->balance->times(-1)
            : Money::fromKobo(0, $this->balance->currency);
    }
}
