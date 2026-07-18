<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Models\Concerns\AppendOnly;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment against the student ACCOUNT (school + student), not an invoice — the
 * allocation is the money→invoice link, so unallocated/advance payments are
 * expressible. Append-only.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $student_id
 * @property int $reference
 * @property Money $amount
 * @property string $payer_name
 * @property string $method
 * @property int|null $received_by_user_id
 * @property-read Collection<int, PaymentAllocation> $allocations
 */
class Payment extends Model
{
    use AddUuid, AppendOnly, BelongsToSchool;

    protected $table = 'fee_payments';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
