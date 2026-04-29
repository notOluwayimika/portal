<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SetupController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        // Return the setup data for the authenticated user
        $school = $user->school;
        // map over ans sort by created at
        $sessions = $school->sessions->sortBy('created_at');
        $class_levels = $school->classLevels->load('arms');
        $arms = $school->arms;
        $exam_types = $school->examTypes;
        $subjects = $school->subjects;
        $grade_boundaries = $school->gradeBoundaries;
        $students = $school->students;
        $curricula = $school->curricula;

        return response()->json([
            'school' => $school,
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
