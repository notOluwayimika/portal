<?php
// app/Models/Student.php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use App\Concerns\HasAdmissionNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Student extends Model
{
    use HasAdmissionNumber, SoftDeletes;

    protected $fillable = [
        'school_id',
        'user_id',
        'first_name',
        'last_name',
        'middle_name',
        'admission_number',
        'gender',
        'date_of_birth',
        'photo'
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope());
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
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
    public function studentCurricula(): HasMany
    {
        return $this->hasMany(StudentCurriculum::class);
    }
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }
    public function results(): HasMany
    {
        return $this->hasMany(StudentResult::class);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getNameAttribute()
    {
        return $this->full_name;
    }
}
