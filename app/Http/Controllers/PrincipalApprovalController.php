<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatusEnum;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\StudentCurriculum;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PrincipalApprovalController extends Controller
{
    public function classLevel(Request $request, ClassLevel $classLevel)
    {
        abort_unless($classLevel->school_id === ActiveSchool::id(), 404);

        return $this->updateApproval(
            $request,
            fn (Builder $query) => $query->whereHas(
                'curriculum.classLevelArm',
                fn (Builder $armQuery) => $armQuery->where('class_level_id', $classLevel->id),
            ),
        );
    }

    public function classLevelArm(Request $request, ClassLevelArm $classLevelArm)
    {
        abort_unless($classLevelArm->classLevel?->school_id === ActiveSchool::id(), 404);

        return $this->updateApproval(
            $request,
            fn (Builder $query) => $query->whereHas(
                'curriculum',
                fn (Builder $curriculumQuery) => $curriculumQuery->where('class_level_arm_id', $classLevelArm->id),
            ),
        );
    }

    private function updateApproval(Request $request, callable $scope)
    {
        $data = $request->validate(['approved' => ['required', 'boolean']]);

        $query = StudentCurriculum::query()
            ->where('status', StudentStatusEnum::ACTIVE)
            ->whereHas('curriculum', fn (Builder $query) => $query->where('status', 'active'));

        $scope($query);
        $updated = $query->update(['principal_approval' => $data['approved']]);

        return response()->json([
            'approved' => $data['approved'],
            'updated' => $updated,
        ]);
    }
}
