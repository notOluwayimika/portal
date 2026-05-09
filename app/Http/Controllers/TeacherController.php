<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeacherCurriculumSubjectResource;
use App\Http\Resources\TeacherResource;
use App\Models\Teacher;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    public function index()
    {
        $school = auth()->user()->school;
        $teachers = $school->teachers;
        return TeacherResource::collection($teachers);

    }

    public function getSubjects(Teacher $teacher)
    {
        $subjects = $teacher->assignedCurriculumSubjects()->with(['curriculumSubject.studentAssignments'])->get();
        return response()->json(TeacherCurriculumSubjectResource::collection($subjects));
    }
}
