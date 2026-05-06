<?php
// app/Models/StudentCurriculum.php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Enums\StudentStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentCurriculum extends Model
{
    use AddUuid;

    protected $fillable = ['student_id', 'curriculum_id', 'status', 'promoted_to_id'];

    protected $casts = [
        'status' => StudentStatusEnum::class,
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->status ??= StudentStatusEnum::ACTIVE;
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }
    public function promotedTo(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class, 'promoted_to_id');
    }
    public function studentSubjects(): HasMany
    {
        return $this->hasMany(StudentSubject::class);
    }
}
