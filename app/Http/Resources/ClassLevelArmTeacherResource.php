<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassLevelArmTeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'role' => $this->role?->value,
            'gender' => $this->gender?->value,
            'teacher' => $this->whenLoaded('teacher', fn() => new TeacherResource($this->teacher)),
            'class_level_arm' => $this->whenLoaded('classLevelArm', fn() => new ClassLevelArmResource($this->classLevelArm)),
            'assigned_by' => $this->whenLoaded('assignedBy', fn() => $this->assignedBy ? new UserResource($this->assignedBy) : null),
            'created_at' => $this->created_at,
        ];
    }
}
