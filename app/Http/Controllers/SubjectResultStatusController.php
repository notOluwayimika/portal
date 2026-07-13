<?php

namespace App\Http\Controllers;

use App\Http\Resources\SubjectResultStatusResource;
use App\Models\SubjectResultStatus;
use Illuminate\Http\Request;

class SubjectResultStatusController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = \App\Support\ActiveSchool::id();
        $classLevelArms = $request->class_level_arms;

        $subjectResults = SubjectResultStatus::with([
            'curriculumSubject.teacherAssignments.teacher',
            'curriculumSubject.subject',
            'curriculumSubject.curriculum.academicSession',
            'curriculumSubject.curriculum.examType',
            'curriculumSubject.curriculum.term',
            'curriculumSubject.curriculum.classLevelArm',
            'updatedBy'
        ])
            ->where('status', 'submitted')

            ->whereHas('updatedBy', function ($query) use ($schoolId) {
                $query->where('school_id', $schoolId);
            })

            ->when(!empty($classLevelArms), function ($query) use ($classLevelArms) {
                $query->whereHas('curriculumSubject.curriculum.classLevelArm', function ($q) use ($classLevelArms) {
                    $q->whereIn('uuid', $classLevelArms);
                });
            })

            ->get();

        return response()->json(
            SubjectResultStatusResource::collection($subjectResults)
        );
    }
}
