<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\DiscountBase;
use App\Finance\Enums\DiscountBasis;
use App\Finance\Enums\DiscountPolicyChangeKind;
use App\Finance\Enums\DiscountPolicyChangeStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed change to the discount catalog (create | amend | retire) — the finance_void_requests shape.
 * Money-immutable / status-mutable by DB trigger; maker≠checker by DB CHECK. The catalog changes only
 * when ApproveDiscountPolicyChange approves this.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property DiscountPolicyChangeKind $kind
 * @property int|null $target_policy_id
 * @property string|null $name
 * @property string|null $description
 * @property DiscountBasis|null $basis
 * @property int|null $value_minor
 * @property string|null $value_currency
 * @property int|null $percent
 * @property DiscountBase|null $base
 * @property bool|null $requires_approval
 * @property string $reason
 * @property DiscountPolicyChangeStatus $status
 * @property int|null $submitted_by
 * @property int|null $decided_by
 */
class DiscountPolicyChange extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_discount_policy_changes';

    protected $guarded = ['id'];

    protected $casts = [
        'kind' => DiscountPolicyChangeKind::class,
        'basis' => DiscountBasis::class,
        // NULLABLE here, unlike the catalog's: a `retire` proposes no terms and an `amount` basis has
        // no percentage to take of anything. ApproveDiscountPolicyChange coalesces NULL to
        // discountable, which is the value the catalog column defaults to.
        'base' => DiscountBase::class,
        'status' => DiscountPolicyChangeStatus::class,
        'requires_approval' => 'boolean',
        'decided_at' => 'datetime',
    ];

    /**
     * The policy being amended or retired — null on a `create`, which has no target yet.
     *
     * The generic is not decoration: without it Larastan reads `$this->target` as a bare Model and
     * fails any property read on it (level 5, `property.notFound`), which is what happens the moment
     * anything actually uses this relation. FeeScheduleChange::target() has carried the annotation
     * since it shipped; this one was written without a caller and never had to.
     *
     * @return BelongsTo<DiscountPolicy, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(DiscountPolicy::class, 'target_policy_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
