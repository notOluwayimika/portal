<?php
// app/Models/StudentCurriculum.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentCurriculum extends Model
{
    use HasUuids;

    protected $fillable = ['student_id', 'curriculum_id'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }
    public function studentSubjects(): HasMany
    {
        return $this->hasMany(StudentSubject::class);
    }
}
