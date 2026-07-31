<?php

// app/Models/School.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class School extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'timezone', 'working_hours_start', 'working_hours_end', 'address', 'phone', 'email', 'website', 'name_on_result', 'fallback_signature_id', 'result_approver_name', 'active'];

    protected $casts = ['active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return BelongsTo<FileUpload, $this> */
    public function fallbackSignatureFile(): BelongsTo
    {
        return $this->belongsTo(FileUpload::class, 'fallback_signature_id');
    }

    /** @return HasMany<AcademicSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(AcademicSession::class);
    }

    /** @return HasManyThrough<Term, AcademicSession, $this> */
    public function terms(): HasManyThrough
    {
        return $this->hasManyThrough(Term::class, AcademicSession::class);
    }

    /** @return HasMany<ClassLevel, $this> */
    public function classLevels(): HasMany
    {
        return $this->hasMany(ClassLevel::class);
    }

    /** @return HasMany<Arm, $this> */
    public function arms(): HasMany
    {
        return $this->hasMany(Arm::class);
    }

    /** @return HasMany<Subject, $this> */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    /** @return HasMany<ExamType, $this> */
    public function examTypes(): HasMany
    {
        return $this->hasMany(ExamType::class);
    }

    /** @return HasMany<Curriculum, $this> */
    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }

    /** @return HasMany<Student, $this> */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /** @return HasMany<Teacher, $this> */
    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    /** @return HasMany<GradeBoundary, $this> */
    public function gradeBoundaries(): HasMany
    {
        return $this->hasMany(GradeBoundary::class);
    }

    /** @return HasMany<GradingScheme, $this> */
    public function gradingSchemes(): HasMany
    {
        return $this->hasMany(GradingScheme::class);
    }

    /** @return HasOne<AcademicSession, $this> */
    public function currentSession(): HasOne
    {
        return $this->hasOne(AcademicSession::class)->where('is_current', true);
    }

    /** @return HasMany<ClassLevelArm, $this> */
    public function classLevelArms(): HasMany
    {
        return $this->hasMany(ClassLevelArm::class);
    }

    /** @return HasMany<SportHouse, $this> */
    public function sportHouses(): HasMany
    {
        return $this->hasMany(SportHouse::class);
    }

    /** @return HasMany<Scholarship, $this> */
    public function scholarships(): HasMany
    {
        return $this->hasMany(Scholarship::class);
    }
}
