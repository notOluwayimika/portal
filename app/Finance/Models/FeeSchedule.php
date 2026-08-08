<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\FeeScheduleStatus;
use App\Models\ClassLevel;
use App\Models\Term;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    /**
     * The (term × class level) pair this schedule IS. Added by §9 step 5a for the approvals queue,
     * which has to name which schedule a publish request is about — `label` alone is author-supplied
     * free text and two schedules may carry the same one. Read-side only: both targets are
     * School-scoped models, so an out-of-School row resolves to null rather than leaking.
     *
     * The docblock above says a schedule is "never joined for display" — that was about BILLING,
     * where prices are copied onto invoice lines as snapshots and a join would read today's catalog
     * for yesterday's invoice. Naming a pending change's target is not that, and no price is read
     * through either of these.
     *
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<ClassLevel, $this>
     */
    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
