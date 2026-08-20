<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Which term SLOTS a class level runs, and whether each is the CCM variant.
 *
 * Presence of a row is participation — there is no `participates` flag, because an absent row and a
 * row saying "no" would be two spellings of one fact. A class doing terms 1-2 only has no row for
 * slot 3, and the end-of-term job no-ops when it finds none.
 *
 * KEYED ON `term_order`, NEVER A term_id. `terms` is UNIQUE on (academic_session_id, order), so the
 * slot is a stable coordinate that survives the session rollover — which is exactly when the
 * migration jobs read this table. A term_id here would have to be re-entered every year.
 *
 * BelongsToSchool ALREADY REGISTERS SchoolScope (see the trait) and fills school_id from the active
 * context, including off-request under SchoolAware. Do not also add the scope by hand — ClassLevel
 * does that only because it predates the trait.
 */
class ClassLevelTermParticipation extends Model
{
    use AddUuid, BelongsToSchool, LogsActivity;

    protected $table = 'class_level_term_participation';

    protected $fillable = [
        'school_id',
        'class_level_id',
        'term_order',
        'is_ccm',
    ];

    protected $casts = [
        'term_order' => 'integer',
        'is_ccm' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<ClassLevel, $this> */
    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    protected static $logName = 'academics';

    /**
     * Logged in full rather than logOnlyDirty on a subset: this table decides where pupils are moved
     * at rollover, so "who changed the CCM flag on Year 3 term 2, and when" is the question an audit
     * will actually be asked.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['class_level_id', 'term_order', 'is_ccm'])
            ->logOnlyDirty();
    }
}
