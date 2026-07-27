<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\FeeScheduleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-School pricing catalog keyed to (term × class level). Consulted at billing time via
 * FeeScheduleLookup; never joined for display (its prices are copied onto invoice lines as
 * snapshots). Lifecycle draft → active → superseded|retired; only `status` mutates.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $term_id
 * @property int $class_level_id
 * @property string $label
 * @property FeeScheduleStatus $status
 * @property int|null $supersedes_schedule_id
 */
class FeeSchedule extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_fee_schedules';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => FeeScheduleStatus::class,
    ];

    /**
     * @return HasMany<FeeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FeeItem::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
