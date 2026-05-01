<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClassLevelResource;
use App\Http\Resources\SessionResource;
use App\Services\ClassLevelService;
use App\Services\SessionService;
use Illuminate\Http\Request;

class SetupController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        // Return the setup data for the authenticated user
        $school = $user->school;
        // map over ans sort by created at
        $sessions = count($school->sessions);
        $class_levels = count($school->classLevels);
        $arms = count($school->arms);
        $exam_types = count($school->examTypes);
        $subjects = count($school->subjects);
        $grade_boundaries = count($school->gradeBoundaries);
        $students = count($school->students);
        $curricula = count($school->curricula);

        return response()->json([
            'school' => $school,
            'current_session' => $school->currentSession,
            'sessions' => $sessions,
            'class_levels' => $class_levels,
            'arms' => $arms,
            'exam_types' => $exam_types,
            'subjects' => $subjects,
            'grade_boundaries' => $grade_boundaries,
            'students' => $students,
            'curricula' => $curricula
        ]);

    }
}
