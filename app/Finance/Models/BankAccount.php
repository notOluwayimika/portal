<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A school's bank account — where money actually lands, and the key a bursar reconciles against.
 *
 * NOT append-only, and deliberately so: unlike a payment or a ledger row, a bank account is a
 * DESCRIPTION rather than an event. A mistyped account number should be corrected in place, because
 * the alternative — retire the row and create a corrected twin — leaves two rows describing one
 * account and makes a statement line ambiguous, which is the exact problem the table exists to
 * remove.
 *
 * BUT IT IS NEVER DELETED. Retirement is `deactivated_at`; see the migration's docblock. A payment
 * reconciled against this account in March must still name something in September.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property string $label
 * @property string $bank_name
 * @property string $account_number
 * @property string|null $account_name
 * @property Carbon|null $deactivated_at
 */
class BankAccount extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_bank_accounts';

    protected $guarded = ['id'];

    protected $casts = [
        'deactivated_at' => 'datetime',
    ];

    /** Active means offerable. A deactivated account stays readable — it is only withdrawn from choice. */
    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /** @param  Builder<BankAccount>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('deactivated_at');
    }

    /**
     * Active first, then by label — the order the list screen shows and the order an account picker
     * would want. Defined here rather than in the controller so both read the same sequence.
     *
     * @param  Builder<BankAccount>  $query
     */
    public function scopeInDisplayOrder(Builder $query): void
    {
        $query->orderByRaw('deactivated_at IS NOT NULL')->orderBy('label');
    }
}
