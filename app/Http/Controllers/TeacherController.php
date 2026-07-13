<?php

namespace App\Http\Controllers;

use App\DTOs\TeacherDto;
use App\Exports\TeachersExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Enums\CurriculaStatusEnum;
use App\Enums\GenderTypeEnum;
use App\Enums\TeacherStatusEnum;
use App\Enums\TermStatusEnum;
use App\Http\Requests\ImportTeacherRequest;
use App\Http\Requests\TeacherRequest;
use App\Http\Resources\TeacherCurriculumSubjectResource;
use App\Http\Resources\TeacherResource;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\FileUpload;
use App\Models\Teacher;
use App\Models\TeacherCurriculumSubject;
use App\Models\User;
use App\Services\FileUploadService;
use App\Services\TeacherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public function __construct(
        protected TeacherService $teacherService,
        protected FileUploadService $fileUploadService,
    ) {
    }

    public function index(Request $request)
    {
        $teachers = $this->teacherService->paginate($request);

        return response()->json([
            'data' => TeacherResource::collection($teachers),
            'pagination' => [
                'total' => $teachers->total(),
                'per_page' => $teachers->perPage(),
                'current_page' => $teachers->currentPage(),
                'last_page' => $teachers->lastPage(),
                'prev_page_url' => $teachers->previousPageUrl(),
                'next_page_url' => $teachers->nextPageUrl(),
            ],
        ]);
    }

    public function store(TeacherRequest $request)
    {
        $this->teacherService->processTeacherAccount($request);
        return Response::created('Teacher created successfully.');
    }

    public function export(Request $request)
    {
        $filename = 'teachers-' . now()->format('Y-m-d') . '.xlsx';
        return Excel::download(new TeachersExport($request), $filename);
    }

    public function import(ImportTeacherRequest $request)
    {
        $data = $request->validated();
        $schoolId = \App\Support\ActiveSchool::id();
        $result = $this->teacherService->import($data['teachers'], $schoolId);

        if (!empty($result['errors'])) {
            return response()->json([
                'message' => "{$result['saved']} teacher(s) imported. " . count($result['errors']) . ' row(s) had errors and were skipped.',
                'saved' => $result['saved'],
                'errors' => $result['errors'],
            ], 422);
        }

        return Response::success("{$result['saved']} teacher(s) imported successfully.");
    }

    public function show(Teacher $teacher)
    {
        return response()->json(TeacherResource::make($this->teacherService->show($teacher)));
    }

    public function update(TeacherRequest $request, Teacher $teacher)
    {
        $dto = $this->teacherService->preparedDto($request, $teacher->photo_id);
        $this->teacherService->update($teacher, $dto->toArray());

        return Response::success('Teacher updated successfully.');
    }

    public function updateStatus(Request $request, Teacher $teacher)
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::enum(TeacherStatusEnum::class)],
        ]);

        $this->teacherService->updateStatus($teacher, $data['status']);

        return Response::success('Teacher status updated successfully.');
    }

    public function destroy(Teacher $teacher)
    {
        $this->teacherService->delete($teacher);
        return response()->noContent();
    }

    public function resources()
    {
        $curricula = Curriculum::with([
            'term',
            'classLevelArm.classLevel',
            'classLevelArm.arm',
            'classLevelArm.stream',
        ])
            ->whereHas('term', fn($q) => $q->where('status', TermStatusEnum::ACTIVE))
            ->where('status', CurriculaStatusEnum::ACTIVE->value)
            ->get();

        $curriculaOptions = $curricula->map(fn($curriculum) => [
            'id' => $curriculum->id,
            'uuid' => $curriculum->uuid,
            'term' => $curriculum->term?->order,
            'term_name' => $curriculum->term?->name,
            'class_level' => $curriculum->classLevelArm?->classLevel?->name,
            'arm' => $curriculum->classLevelArm?->arm?->label,
            'stream' => $curriculum->classLevelArm?->stream?->name,
            'full_name' => $curriculum->full_name,
        ]);

        return Response::success([
            'curricula' => $curriculaOptions,
            'genders' => GenderTypeEnum::options(),
            'statuses' => TeacherStatusEnum::options(),
        ]);
    }

    public function subjects(Teacher $teacher)
    {
        $assignments = $teacher->assignedCurriculumSubjects()
            ->with([
                'curriculumSubject.subject',
                'curriculumSubject.curriculum.classLevelArm.classLevel',
                'curriculumSubject.curriculum.classLevelArm.arm',
                'curriculumSubject.curriculum.classLevelArm.stream',
                'curriculumSubject.curriculum.term',
                'curriculumSubject.studentAssignments',
                'curriculumSubject.markingComponents'
            ])
            ->get();

        return response()->json(TeacherCurriculumSubjectResource::collection($assignments));
    }

    public function assignSubject(Request $request, Teacher $teacher)
    {
        $request->validate([
            'curriculum_subject_id' => ['required', 'string', 'exists:curriculum_subjects,uuid'],
        ]);

        $curriculumSubject = CurriculumSubject::where('uuid', $request->curriculum_subject_id)->firstOrFail();

        $alreadyAssigned = TeacherCurriculumSubject::where('teacher_id', $teacher->id)
            ->where('curriculum_subject_id', $curriculumSubject->id)
            ->exists();

        if ($alreadyAssigned) {
            return response()->json([
                'message' => 'This subject is already assigned to this teacher.',
            ], 422);
        }

        $assignment = TeacherCurriculumSubject::create([
            'teacher_id' => $teacher->id,
            'curriculum_subject_id' => $curriculumSubject->id,
        ]);

        $assignment->load([
            'curriculumSubject.subject',
            'curriculumSubject.curriculum.classLevelArm.classLevel',
            'curriculumSubject.curriculum.classLevelArm.arm',
        ]);

        return Response::created(TeacherCurriculumSubjectResource::make($assignment));
    }

    public function removeSubject(Teacher $teacher, TeacherCurriculumSubject $assignment)
    {
        abort_if($assignment->teacher_id !== $teacher->id, 403, 'Assignment does not belong to this teacher.');
        $assignment->delete();
        return response()->noContent();
    }

    public function curriculumSubjects(Curriculum $curriculum)
    {
        $subjects = $curriculum->curriculumSubjects()
            ->with(['subject', 'markingComponents'])
            ->orderBy('display_order')
            ->get();

        return Response::success($subjects->map(fn($cs) => [
            'id' => $cs->uuid,
            'subject_name' => $cs->subject?->name,
            'subject_code' => $cs->subject?->code,
            'is_compulsory' => $cs->is_compulsory,
            'display_order' => $cs->display_order,
        ]));
    }
}
