<?php
// app/Models/School.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class School extends Model
{
    protected $fillable = ['name', 'slug', 'address', 'phone', 'email', 'website', 'name_on_result', 'active'];

    protected $casts = ['active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
    public function sessions(): HasMany
    {
        return $this->hasMany(AcademicSession::class);
    }
    public function terms(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(Term::class, AcademicSession::class);
    }
    public function classLevels(): HasMany
    {
        return $this->hasMany(ClassLevel::class);
    }
    public function arms(): HasMany
    {
        return $this->hasMany(Arm::class);
    }
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }
    public function examTypes(): HasMany
    {
        return $this->hasMany(ExamType::class);
    }
    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }
    public function gradeBoundaries(): HasMany
    {
        return $this->hasMany(GradeBoundary::class);
    }
    public function currentSession()
    {
        return $this->hasOne(AcademicSession::class)->where('is_current', true);
    }

    public function classLevelArms()
    {
        return $this->hasMany(ClassLevelArm::class);
    }

    public function sportHouses(): HasMany
    {
        return $this->hasMany(SportHouse::class);
    }

    public function scholarships(): HasMany
    {
        return $this->hasMany(Scholarship::class);
    }
}
