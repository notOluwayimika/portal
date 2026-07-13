<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeacherResource;
use App\Models\Teacher;
use Illuminate\Http\Request;

class HeadOfSchoolController extends Controller
{
    public function index()
    {
        // Teacher is tenant-scoped (SchoolScope) — no explicit filter needed.
        $teachers = Teacher::with('user')->get();
        return response()->json(TeacherResource::collection($teachers));
    }

    public function store(Request $request)
    {
        $request->validate([
            'teacher' => 'required|exists:teachers,uuid',
            'role' => 'required|in:head_of_school'
        ]);

        $teacher = Teacher::where('uuid', $request->teacher)->first();
        $user = $teacher->user;
        $user->assignRole($request->role);
        return back();
    }

    public function update(Request $request, $headOfSchool)
    {
        // Implementation for updating a head of school
    }

    public function destroy(Teacher $teacher)
    {
        $user = $teacher->user;
        $user->removeRole('head_of_school');
        return back();
    }
}
