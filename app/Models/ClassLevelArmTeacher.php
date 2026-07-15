<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Enums\GenderTypeEnum;
use App\Enums\TeacherAssignmentRoleEnum;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassLevelArmTeacher extends Model
{
    use AddUuid;

    protected $table = 'class_level_arm_teacher';

    protected $fillable = [
        'class_level_arm_id',
        'teacher_id',
        'role',
        'gender',
        'assigned_by',
    ];

    protected $casts = [
        'role' => TeacherAssignmentRoleEnum::class,
        'gender' => GenderTypeEnum::class,
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Limit assignments to the active school via the arm's school_id.
     * This table has no school column and ClassLevelArm carries no tenant
     * scope, so teachers visible in multiple schools (school_user pivot)
     * would otherwise leak their assignments across schools.
     */
    public function scopeInActiveSchool(Builder $query): Builder
    {
        return $query->whereHas(
            'classLevelArm',
            fn ($q) => $q->where('school_id', ActiveSchool::id())
        );
    }

    public function classLevelArm(): BelongsTo
    {
        return $this->belongsTo(ClassLevelArm::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
