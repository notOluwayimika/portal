<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Enums\ScholarshipKind;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    use AddUuid, BelongsToSchool;

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
}
