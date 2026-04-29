<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Teacher extends Model
{
    use HasUuids;
    public function assignedCurriculumSubjects()
    {
        return $this->hasMany(TeacherCurriculumSubject::class, 'teacher_id');
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
