<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Teacher extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $fillable = [
        'school_id',
        'user_id',
        'staff_number',
        'first_name',
        'last_name',
        'gender',
        'date_of_birth',
        'phone',
        'address',
        'qualification',
        'hire_date',
        'status',
        'photo'
    ];

    public $appends = ['full_name', 'name'];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function assignedCurriculumSubjects()
    {
        return $this->hasMany(TeacherCurriculumSubject::class, 'teacher_id');
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
