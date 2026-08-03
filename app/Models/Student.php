<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Concerns\HasAdmissionNumber;
use App\Enums\StudentMembershipStatus;
use App\Enums\StudentStatusEnum;
use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $uuid
 */
class Student extends Model
{
    use AddUuid, BelongsToSchool, HasAdmissionNumber, HasFactory, LogsActivity, SoftDeletes;

    /**
     * Audited fields — a pupil's identity record.
     *
     * A student has no login of their own, so none of this is account-security in
     * the credential sense. It is the sensitive-relationship tier: legal name, date
     * of birth, address and admission number are the identity a school is
     * accountable for, and a third party changing one is the event that has to be
     * answerable later. The notification layer reads these same rows.
     *
     * ⚠️ VOLUME. This is the highest-cardinality model the audit trail has been
     * pointed at — student imports and bulk updates touch hundreds of rows at once.
     * `logOnlyDirty()` limits each row to fields that actually changed, and the
     * cosmetic columns (`photo_id`, `sport_house_id`, `scholarship_id`,
     * `previous_school`) are deliberately excluded, but the retention consequence
     * is real and is the reason this lands as an audit review rather than inside a
     * feature branch.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'first_name',
                'middle_name',
                'last_name',
                'gender',
                'date_of_birth',
                'address',
                'nationality',
                'state_of_origin',
                'admission_number',
                'status',
            ])
            ->logOnlyDirty()
            // Without this, changing a NON-audited column still writes a row with an
            // empty attribute set — pure volume with nothing recorded. On the two
            // highest-cardinality models in the app that is the difference between an
            // audit trail and a write amplifier.
            ->dontSubmitEmptyLogs()
            ->useLogName('student');
    }

    protected $fillable = [
        'school_id',
        'user_id',
        'first_name',
        'last_name',
        'middle_name',
        'admission_number',
        'status',
        'left_at',
        'leave_reason',
        'gender',
        'date_of_birth',
        'photo_id',
        'sport_house_id',
        'scholarship_id',
        'admission_date',
        'address',
        'nationality',
        'other_nationality',
        'state_of_origin',
        'religion',
        'previous_school',
    ];

    protected $casts = [
        'admission_date' => 'date',
        'status' => StudentMembershipStatus::class,
        'left_at' => 'datetime',
    ];

    /**
     * `school_id` is IMMUTABLE AFTER CREATE (slice (i), D2).
     *
     * A student moving School is a NEW ADMISSION, not an UPDATE (v10 §2.1) — their
     * record stays with the originating School. Nothing in the codebase updates
     * `students.school_id` today, and the composite FKs added by slice (i)
     * deliberately carry NO `ON UPDATE CASCADE`: if this value could change, the
     * cascade would silently rewrite the School attribution of every historical
     * billed and graded episode hanging off this student.
     *
     * Guarded on `updating` rather than by removing `school_id` from `$fillable`,
     * because removal would silently DROP it from `Student::create()` (the column is
     * NOT NULL) and break student creation. Creation sets it; nothing may change it.
     */
    protected static function booted(): void
    {
        static::updating(function (self $student): void {
            if ($student->isDirty('school_id')) {
                throw new BusinessRuleException(
                    'A student\'s School is immutable — moving School is a new admission, not an update.'
                );
            }
        });
    }

    /**
     * Billing eligibility (§12.6): active members of the current School. Answers
     * "active Students at School X" with a single indexed predicate — no join to
     * the enrollment (StudentCurriculum) table, so it holds between terms too.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StudentMembershipStatus::ACTIVE);
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<FileUpload, $this> */
    public function photoFile(): BelongsTo
    {
        return $this->belongsTo(FileUpload::class, 'photo_id');
    }

    /** @return HasMany<StudentCurriculum, $this> */
    public function studentCurricula(): HasMany
    {
        return $this->hasMany(StudentCurriculum::class);
    }

    /** @return HasOne<StudentCurriculum, $this> */
    public function currentCurriculum(): HasOne
    {
        return $this->hasOne(StudentCurriculum::class)->where('status', StudentStatusEnum::ACTIVE);
    }

    /** @return HasMany<Score, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    /** @return HasMany<StudentResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(StudentResult::class);
    }

    /** @return BelongsToMany<Guardian, $this> */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'guardian_student')
            ->withPivot(['relationship', 'is_primary', 'can_login'])
            ->withTimestamps();
    }

    public function primaryGuardian(): BelongsToMany
    {
        return $this->guardians()->wherePivot('is_primary', true);
    }

    /** @return BelongsTo<SportHouse, $this> */
    public function sportHouse(): BelongsTo
    {
        return $this->belongsTo(SportHouse::class);
    }

    /** @return BelongsTo<Scholarship, $this> */
    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function getPhotoAttribute(): ?string
    {
        return $this->photoFile?->url;
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getNameAttribute()
    {
        return $this->full_name;
    }

    public function getStudentClassAttribute()
    {
        $currentCurriculum = $this->currentCurriculum;

        $classLevelArm = $currentCurriculum?->curriculum?->classLevelArm;
        if (! $classLevelArm) {
            return null;
        }

        $className = $classLevelArm->classLevel?->name.' '.$classLevelArm->arm?->label;

        if ($classLevelArm->stream) {
            $className .= ' ('.$classLevelArm->stream->name.')';
        }

        return $className;
    }
}
