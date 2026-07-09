<?php

namespace App\Http\Controllers;

use App\Enums\TeacherAssignmentRoleEnum;
use App\Http\Requests\TeacherAssignmentRequest;
use App\Http\Resources\ClassLevelArmTeacherResource;
use App\Http\Resources\TeacherResource;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmTeacher;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class TeacherAssignmentController extends Controller
{
    public function index()
    {
        $schoolId = auth()->user()->school_id;

        $assignments = ClassLevelArmTeacher::query()
            ->whereHas('teacher', fn($query) => $query->where('school_id', $schoolId))
            ->with([
                'teacher.user',
                'classLevelArm.classLevel',
                'classLevelArm.arm',
                'classLevelArm.stream',
                'assignedBy',
            ])
            ->get();

        return Response::success(ClassLevelArmTeacherResource::collection($assignments));
    }

    public function teachers(Request $request)
    {
        $schoolId = auth()->user()->school_id;
        $search = $request->query('search');

        $teachers = Teacher::with('user')
            ->where('school_id', $schoolId)
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('staff_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn($userQuery) => $userQuery->where('email', 'like', "%{$search}%"));
                });
            })
            ->limit(20)
            ->get();

        return Response::success(TeacherResource::collection($teachers));
    }

    public function store(TeacherAssignmentRequest $request)
    {
        $data = $request->validated();

        $teacher = Teacher::where('uuid', $data['teacher_id'])->firstOrFail();
        $role = TeacherAssignmentRoleEnum::from($data['role']);
        $gender = $data['gender'] ?? null;
        $isBoardingParent = $role === TeacherAssignmentRoleEnum::BOARDING_PARENT;

        $classLevelArmIds = ClassLevelArm::whereIn('uuid', $data['class_level_arm_ids'])->pluck('id', 'uuid');

        DB::transaction(function () use ($data, $teacher, $role, $gender, $isBoardingParent, $classLevelArmIds) {
            foreach ($data['class_level_arm_ids'] as $uuid) {
                $classLevelArmId = $classLevelArmIds[$uuid];

                $existing = ClassLevelArmTeacher::where('class_level_arm_id', $classLevelArmId)
                    ->where('role', $role->value)
                    ->when($isBoardingParent, fn($query) => $query->where('gender', $gender))
                    ->get();

                foreach ($existing as $previousAssignment) {
                    $previousTeacher = $previousAssignment->teacher;
                    $previousAssignment->delete();
                    $this->revokeRoleIfUnused($previousTeacher, $role);
                }

                ClassLevelArmTeacher::create([
                    'class_level_arm_id' => $classLevelArmId,
                    'teacher_id' => $teacher->id,
                    'role' => $role->value,
                    'gender' => $gender,
                    'assigned_by' => auth()->id(),
                ]);
            }

            if ($teacher->user) {
                $teacher->user->assignRole($role->value);
            }
        });

        return Response::created('Teacher assignment saved successfully.');
    }

    public function destroy(ClassLevelArmTeacher $classLevelArmTeacher)
    {
        $teacher = $classLevelArmTeacher->teacher;
        $role = $classLevelArmTeacher->role;

        $classLevelArmTeacher->delete();

        $this->revokeRoleIfUnused($teacher, $role);

        return response()->noContent();
    }

    private function revokeRoleIfUnused(?Teacher $teacher, TeacherAssignmentRoleEnum $role): void
    {
        if (!$teacher || !$teacher->user) {
            return;
        }

        $stillAssigned = ClassLevelArmTeacher::where('teacher_id', $teacher->id)
            ->where('role', $role->value)
            ->exists();

        if (!$stillAssigned) {
            $teacher->user->removeRole($role->value);
        }
    }
}
