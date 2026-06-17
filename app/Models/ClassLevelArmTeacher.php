<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Enums\GenderTypeEnum;
use App\Enums\TeacherAssignmentRoleEnum;
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
