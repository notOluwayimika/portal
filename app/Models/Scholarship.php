<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Enums\ScholarshipKind;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A named scholarship scheme a School runs. {@see ScholarshipKind} says what the scheme MEANS for
 * billing; until this branch the table held a name and nothing else.
 *
 * `kind` IS NULLABLE AND HAS NO DEFAULT, and that is load-bearing rather than lax. NULL means
 * "nobody has configured this scheme yet" — every row that existed before the column did carries it,
 * because nothing in the data said which scheme any of them was. It is not a value in the domain:
 * see the enum, and see {@see ProcessBulkInvoiceRun}, which refuses to bill a
 * cohort holding one rather than falling through to the standard fee schedule.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property string $name
 * @property ScholarshipKind|null $kind NULL = unconfigured, which is not a scheme
 */
class Scholarship extends Model
{
    use AddUuid, BelongsToSchool, LogsActivity;

    protected $fillable = [
        'school_id',
        'name',
        'kind',
    ];

    protected $casts = [
        'kind' => ScholarshipKind::class,
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

    /** @return HasMany<Student, $this> */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * WHY THIS TRAIT IS ON A TWO-ROW TABLE. `kind` decides whether a cohort is billed at all: a
     * scholarship flipped to `sponsored` is EXCLUDED from the bulk invoice run
     * ({@see ProcessBulkInvoiceRun}), so one edit on this screen can stop invoicing every family on
     * that scheme — and the run would report success while doing it. The blast radius is the holder
     * count of the row that was edited, which on this table is a whole cohort rather than a handful.
     * Without an entry the only trace of who did that is a bumped `updated_at`. `name` is logged
     * beside it because the audit entry has to be readable months later, and
     * `kind: discount -> sponsored` on scholarship #2 is not.
     *
     * `logOnlyDirty()` so a save that changes nothing writes nothing — matching Arm, ExamType and the
     * rest of the family.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // `useLogName()` AND NOT `protected static $logName`, which is what twenty-three
            // sibling models write and which DOES NOTHING. Spatie resolves the name in
            // `LogsActivity::getLogNameToUse()`, which reads `$this->activitylogOptions->logName`
            // and otherwise falls through to `config('activitylog.default_log_name')` — it never
            // looks at a static property. Measured on the production copy: the distinct `log_name`
            // values are auth, authentication, default, guardian, rbac. There is no `academics`, no
            // `results` and no `setup` row in the table, because every model-trait entry in this
            // codebase lands in `default`. Copying the sibling form would have produced a log entry
            // that passes a test asserting "an entry exists" and lands in the wrong bucket; see
            // `docs/handoff/tickets/model-log-name-is-declared-as-a-static-property-spatie-never-reads.md`.
            //
            // `academics` is the name the siblings INTEND — ExamType, Subject, Arm, Stream and the
            // rest of the School setup records this one sits beside, behind the same
            // `academic_setup.manage` gate. (`setup` exists too, on MarkingComponent alone;
            // `results` is the score-side family. Neither is the nearer sibling.)
            ->useLogName('academics')
            ->logOnly(['name', 'kind'])
            ->logOnlyDirty();
    }
}
