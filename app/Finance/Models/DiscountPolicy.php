<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\DiscountBase;
use App\Finance\Enums\DiscountBasis;
use App\Finance\Enums\DiscountPolicyStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A school-authored discount policy (axis A). Written ONLY by ApproveDiscountPolicyChange; its terms
 * are immutable (DB update-guard), only `status` moves. `requires_approval` is axis B: false = a bursar
 * may apply it as an invoice line, true = each application needs a credit-note approval.
 *
 * `base` is axis C ({@see DiscountBase}) — WHAT a percentage is taken of, the discountable charge
 * lines or all of them. It is a TERM, so it is immutable too, held by its own
 * `finance_discount_policies_base_shape_bu` trigger rather than by widening the update guard above.
 * It is inert on an `amount`-basis policy, which has no percentage to take of anything.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property string $name
 * @property string|null $description
 * @property DiscountBasis $basis
 * @property int|null $value_minor
 * @property string|null $value_currency
 * @property int|null $percent
 * @property DiscountBase $base
 * @property bool $requires_approval
 * @property DiscountPolicyStatus $status
 * @property int|null $supersedes_policy_id
 */
class DiscountPolicy extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_discount_policies';

    protected $guarded = ['id'];

    protected $casts = [
        'basis' => DiscountBasis::class,
        'base' => DiscountBase::class,
        'status' => DiscountPolicyStatus::class,
        'requires_approval' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
