<?php

namespace App\Http\Controllers;

use App\Models\CurriculumSubject;
use App\Models\Teacher;
use Illuminate\Http\Request;

class CurriculumSubjectController extends Controller
{
    public function update(Request $request, CurriculumSubject $curriculumSubject)
    {
        try {
            $request->validate([
                'is_compulsory' => 'sometimes|boolean',
                'display_order' => 'sometimes|numeric|max:255',
            ]);

            $curriculumSubject->update($request->all());
            return response()->json($curriculumSubject, 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to update curriculum subject'], 500);
        }

    }
    public function assignTeacher(Request $request, CurriculumSubject $curriculumSubject)
    {
        try {
            $request->validate([
                'teacher_id' => 'required|exists:teachers,uuid',
            ]);
            $teacher = Teacher::where('uuid', $request->teacher_id)->first();

            $curriculumSubject->teacherAssignments()->create(['teacher_id' => $teacher->id]);
            return response()->json(['message' => 'Teacher assigned successfully'], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to assign teacher'], 500);
        }
    }

    public function unassignTeacher(CurriculumSubject $curriculumSubject, Teacher $teacher)
    {
        try {
            $curriculumSubject->teacherAssignments()->where('teacher_id', $teacher->id)->delete();
            return response()->json(['message' => 'Teacher unassigned successfully'], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to unassign teacher'], 500);
        }
    }

    public function destroy(CurriculumSubject $curriculumSubject)
    {
        try {
            $curriculumSubject->delete();
            return response()->json(['message' => 'Curriculum subject deleted successfully'], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to delete curriculum subject'], 500);
        }
    }
}
