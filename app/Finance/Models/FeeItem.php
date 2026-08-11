<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a fee schedule — "Tuition", "Transport", "Feeding". `amount` is positive Money.
 * `is_mandatory` pre-ticks tuition vs optional items in the bursar UI; `is_discountable` scopes the
 * percentage base (§3.6). Editable/deletable only while the parent schedule is a draft (DB triggers);
 * frozen once active.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $fee_schedule_id
 * @property string $description
 * @property Money $amount
 * @property bool $is_mandatory
 * @property bool $is_discountable
 * @property int $sort_order
 */
class FeeItem extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_fee_items';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
        'is_mandatory' => 'boolean',
        'is_discountable' => 'boolean',
    ];

    /**
     * Untyped until this commit, which is why nothing had ever read a property off it: Larastan resolves a
     * bare BelongsTo to Model, so `$item->schedule->status` is an undefined-property error. The generic is
     * the fix, not a cast at the call site.
     *
     * @return BelongsTo<FeeSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FeeSchedule::class, 'fee_schedule_id');
    }

    /**
     * WHERE THIS LINE'S MONEY LANDS. `finance_fee_items.bank_account_id` is NOT NULL, so this is never
     * absent on a row — but it is nullable HERE, because BankAccount carries SchoolScope and a read
     * from another School's context resolves to null rather than leaking the account.
     *
     * Added by U1 commit 1: `FeeScheduleResource` now serialises the account's uuid on every item, so
     * an operator editing a draft is shown the destination each line already points at instead of
     * re-picking it from nothing. Without a relation that is one query per item; `index()` eager-loads it.
     *
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
