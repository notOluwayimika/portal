<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\School;
use App\Models\Teacher;
use App\Models\Term;
use App\Support\ActiveSchool;
use App\Support\CurrentTerm;

class SetupController extends Controller
{
    public function index()
    {
        $school = School::find(ActiveSchool::id());

        abort_unless($school, 403, 'No active school selected.');

        $currentSession = $school->currentSession;

        // MOVED, NOT CHANGED. The active-then-last-by-order resolution that stood here is now
        // App\Support\CurrentTerm — the shared kernel — because U6's bulk-run screen defaults its
        // term from the same fact and a second copy of "which term is current" is the drift this
        // project has already paid for on other predicates. Same expression, same fallback.
        $currentTerm = CurrentTerm::forSchoolModel($school);

        $termsInSession = $currentSession
            ? Term::where('academic_session_id', $currentSession->id)->count()
            : 0;

        return response()->json([
            'school' => $school,
            'current_session' => $currentSession,
            'current_term' => $currentTerm ? [
                'name' => $currentTerm->name,
                'order' => $currentTerm->order,
                'status' => $currentTerm->status instanceof \BackedEnum
                                    ? $currentTerm->status->value
                                    : (string) $currentTerm->status,
                'start_date' => $currentTerm->start_date?->toDateString(),
                'end_date' => $currentTerm->end_date?->toDateString(),
            ] : null,
            'terms_in_session' => $termsInSession,
            'sessions' => $school->sessions()->count(),
            'class_levels' => $school->classLevels()->count(),
            'arms' => $school->arms()->count(),
            'class_level_arms' => $school->classLevelArms()->count(),
            'exam_types' => $school->examTypes()->count(),
            'subjects' => $school->subjects()->count(),
            'grade_boundaries' => $school->gradeBoundaries()->count(),
            'curricula' => $school->curricula()->count(),
            'students' => $school->students()->count(),
            'teachers' => Teacher::count(), // tenant-scoped; includes school_user pivot teachers
            'guardians' => Guardian::count(), // tenant-scoped by SchoolScope
        ]);
    }
}
