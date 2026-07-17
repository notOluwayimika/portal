<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use App\Enums\GenderTypeEnum;
use App\Enums\TeacherAssignmentRoleEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ClassLevelArm extends Model
{
    use BelongsToSchool;

    protected $fillable = ['school_id', 'stream_id', 'class_level_id', 'arm_id'];

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

    public function classLevel()
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function arm()
    {
        return $this->belongsTo(Arm::class);
    }

    public function stream()
    {
        return $this->belongsTo(Stream::class);
    }

    public function curricula()
    {
        return $this->hasMany(Curriculum::class);
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(ClassLevelArmTeacher::class);
    }

    public function formTeacher(): ?Teacher
    {
        $assignment = $this->teacherAssignments()
            ->where('role', TeacherAssignmentRoleEnum::FORM_TEACHER->value)
            ->first();

        return $assignment ? $assignment->teacher : null;
    }

    public function headOfSchool(): ?Teacher
    {
        $assignment = $this->teacherAssignments()
            ->where('role', TeacherAssignmentRoleEnum::HEAD_OF_SCHOOL->value)
            ->first();

        return $assignment ? $assignment->teacher : null;
    }

    public function maleBoardingParent(): ?Teacher
    {
        $assignment = $this->teacherAssignments()
            ->where('role', TeacherAssignmentRoleEnum::BOARDING_PARENT->value)
            ->where('gender', GenderTypeEnum::MALE->value)
            ->first();

        return $assignment ? $assignment->teacher : null;
    }

    public function femaleBoardingParent(): ?Teacher
    {
        $assignment = $this->teacherAssignments()
            ->where('role', TeacherAssignmentRoleEnum::BOARDING_PARENT->value)
            ->where('gender', GenderTypeEnum::FEMALE->value)
            ->first();

        return $assignment ? $assignment->teacher : null;
    }

    protected static $logName = 'results';
}
