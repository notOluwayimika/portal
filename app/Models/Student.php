<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Concerns\HasAdmissionNumber;
use App\Enums\StudentMembershipStatus;
use App\Enums\StudentStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use AddUuid, BelongsToSchool, HasAdmissionNumber, HasFactory, SoftDeletes;

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

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photoFile(): BelongsTo
    {
        return $this->belongsTo(FileUpload::class, 'photo_id');
    }

    public function studentCurricula(): HasMany
    {
        return $this->hasMany(StudentCurriculum::class);
    }

    public function currentCurriculum(): HasOne
    {
        return $this->hasOne(StudentCurriculum::class)->where('status', StudentStatusEnum::ACTIVE);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(StudentResult::class);
    }

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

    public function sportHouse(): BelongsTo
    {
        return $this->belongsTo(SportHouse::class);
    }

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
