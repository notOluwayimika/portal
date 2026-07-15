<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\Term;
use Illuminate\Http\Request;

class SetupController extends Controller
{
    public function index()
    {
        $school = \App\Models\School::find(\App\Support\ActiveSchool::id());

        abort_unless($school, 403, 'No active school selected.');

        $currentSession = $school->currentSession;

        $currentTerm = $currentSession
            ? Term::where('academic_session_id', $currentSession->id)
                ->where('status', 'active')
                ->first()
              ?? Term::where('academic_session_id', $currentSession->id)
                ->orderBy('order', 'desc')
                ->first()
            : null;

        $termsInSession = $currentSession
            ? Term::where('academic_session_id', $currentSession->id)->count()
            : 0;

        return response()->json([
            'school'            => $school,
            'current_session'   => $currentSession,
            'current_term'      => $currentTerm ? [
                'name'       => $currentTerm->name,
                'order'      => $currentTerm->order,
                'status'     => $currentTerm->status instanceof \BackedEnum
                                    ? $currentTerm->status->value
                                    : (string) $currentTerm->status,
                'start_date' => $currentTerm->start_date?->toDateString(),
                'end_date'   => $currentTerm->end_date?->toDateString(),
            ] : null,
            'terms_in_session'  => $termsInSession,
            'sessions'          => $school->sessions()->count(),
            'class_levels'      => $school->classLevels()->count(),
            'arms'              => $school->arms()->count(),
            'class_level_arms'  => $school->classLevelArms()->count(),
            'exam_types'        => $school->examTypes()->count(),
            'subjects'          => $school->subjects()->count(),
            'grade_boundaries'  => $school->gradeBoundaries()->count(),
            'curricula'         => $school->curricula()->count(),
            'students'          => $school->students()->count(),
            'teachers'          => Teacher::count(), // tenant-scoped; includes school_user pivot teachers
            'guardians'         => Guardian::count(), // tenant-scoped by SchoolScope
        ]);
    }
}
