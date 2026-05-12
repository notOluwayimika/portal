<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertScoreRequest;
use App\Http\Resources\MarkingComponentResource;
use App\Models\CurriculumSubject;
use App\Models\Score;
use App\Models\Student;
use App\Models\StudentSubject;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

    public function assignMarkingComponent(Request $request, CurriculumSubject $curriculumSubject)
    {
        try {
            $request->validate([
                "name" => 'required|string',
                'weight' => 'required|numeric|min:0'
            ]);
            $markingComponent = $curriculumSubject->markingComponents()->create($request->all());
            return response()->json(['message' => 'Marking component created successfully', 'data' => new MarkingComponentResource($markingComponent)], 200);

        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to create marking component'], 500);

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

    public function assignScore(UpsertScoreRequest $upsertScoreRequest)
    {
        try {
            $data = $upsertScoreRequest->validated();

            // Authorize: the TCS must belong to the authenticated teacher, AND
            // the marking_component must belong to the same curriculum_subject.
            $cs = CurriculumSubject::with('markingComponents')
                ->where('uuid', $data['curriculum_subject_id'])
                ->first();

            $curriculumSubjectId = $cs->id;

            abort_unless(
                $cs->markingComponents
                    ->contains(fn($mc) => $mc->uuid === $data['marking_component_id']),
                422,
                'Marking component does not belong to this subject.'
            );

            // Ensure the student is actually enrolled in this curriculum subject.
            $isEnrolled = Student::where('uuid', $data['student_id'])
                ->whereHas('studentCurricula.studentSubjects', function ($q) use ($curriculumSubjectId) {
                    $q->where('curriculum_subject_id', $curriculumSubjectId);
                })
                ->exists();

            abort_unless($isEnrolled, 422, 'Student is not enrolled in this subject.');
            $student = Student::where('uuid', $data['student_id'])->first();

            $markingComponent = $cs->markingComponents->first(fn($mc) => $mc->uuid === $data['marking_component_id']);
            $score = Score::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'marking_component_id' => $markingComponent->id,
                ],
                [
                    'curriculum_subject_id' => $curriculumSubjectId,
                    'score' => $data['score'],
                    'created_by' => Auth::id(),
                ]
            );

            return response()->json([
                'id' => $score->id,
                'score' => (float) $score->score,
            ]);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to assign score'], 500);
        }

    }
}
