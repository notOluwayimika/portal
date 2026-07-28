<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\FeeScheduleChangeKind;
use App\Finance\Enums\FeeScheduleChangeStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed change to a fee schedule's publication (publish | retire) — the finance_discount_policy_changes
 * shape. Status-mutable / everything-else-immutable by DB trigger; maker≠checker by DB CHECK. The schedule
 * reaches `active` only when ApproveFeeScheduleChange approves this.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property FeeScheduleChangeKind $kind
 * @property int $target_schedule_id
 * @property string $reason
 * @property FeeScheduleChangeStatus $status
 * @property int|null $submitted_by
 * @property int|null $decided_by
 * @property string|null $rejection_reason
 * @property-read FeeSchedule|null $target
 */
class FeeScheduleChange extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_fee_schedule_changes';

    protected $guarded = ['id'];

    protected $casts = [
        'kind' => FeeScheduleChangeKind::class,
        'status' => FeeScheduleChangeStatus::class,
        'decided_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<FeeSchedule, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(FeeSchedule::class, 'target_schedule_id');
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
