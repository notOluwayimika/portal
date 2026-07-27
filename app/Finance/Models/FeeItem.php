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

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FeeSchedule::class, 'fee_schedule_id');
    }
}
