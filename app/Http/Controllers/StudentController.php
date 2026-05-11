<?php

namespace App\Http\Controllers;

use App\DTOs\StudentDto;
use App\Enums\CurriculaStatusEnum;
use App\Enums\GenderTypeEnum;
use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Exports\StudentsExport;
use App\Http\Requests\ImportStudentRequest;
use App\Http\Requests\StudentRequest;
use App\Http\Resources\ClassLevelArmOptionsResource;
use App\Http\Resources\CurriculumResource;
use App\Http\Resources\StudentResource;
use App\Models\Curriculum;
use App\Models\Student;
use App\Repositories\ClassLevelArmRepository;
use App\Services\StudentService;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class StudentController extends Controller
{
    public function __construct(
        protected StudentService $studentService,
        protected ClassLevelArmRepository $classLevelArmRepository,
    ) {
    }

    public function index(Request $request)
    {
        $students = $this->studentService->paginate($request);

        return response()->json([
            'data' => StudentResource::collection($students),
            'pagination' => [
                'total' => $students->total(),
                'per_page' => $students->perPage(),
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'prev_page_url' => $students->previousPageUrl(),
                'next_page_url' => $students->nextPageUrl(),
            ],
        ]);
    }

    public function store(StudentRequest $request)
    {
        $data = $request->validated();
        $data['school_id'] = $request->user()->school_id;

        $dto = StudentDto::fromArray($data);
        $this->studentService->store($dto->toArray());

        if ($request->wantsJson()) {
            return Response::created('Student created successfully.');
        }

        return redirect()->route('students.index');
    }

    public function show(Student $student)
    {
        return Response::json(StudentResource::make($this->studentService->show($student)));
    }

    public function update(StudentRequest $request, Student $student)
    {
        $data = $request->validated();
        $data['school_id'] = $request->user()->school_id;

        $dto = StudentDto::fromArray($data);
        $this->studentService->update($student, $dto->toArray());

        if ($request->wantsJson()) {
            return Response::success('Student updated successfully.');
        }

        return redirect()->route('students.index');
    }

    public function updateStatus(Request $request, Student $student)
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::enum(StudentStatusEnum::class)],
        ]);

        $this->studentService->updateStatus($student, $data['status']);

        if ($request->wantsJson()) {
            return Response::success('Student status updated successfully.');
        }

        return redirect()->route('students.index');
    }

    public function export(Request $request)
    {
        $filename = 'students-' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new StudentsExport($request), $filename);
    }

    public function import(ImportStudentRequest $request)
    {
        $data = $request->validated();
        $schoolId = $request->user()->school_id;

        $result = $this->studentService->import(
            $data['students'],
            (int) $data['curriculum_id'],
            $schoolId,
        );

        if (!empty($result['errors'])) {
            return response()->json([
                'message' => "{$result['saved']} student(s) imported. " . count($result['errors']) . " row(s) had errors and were skipped.",
                'saved' => $result['saved'],
                'errors' => $result['errors'],
            ], 422);
        }

        return Response::success("{$result['saved']} student(s) imported successfully.");
    }

    public function destroy(Student $student)
    {
        $this->studentService->delete($student);
        return response()->noContent();
    }

    public function resources()
    {
        $curricula = Curriculum::with([
            'term',
            'classLevelArm.classLevel',
            'classLevelArm.arm',
            'classLevelArm.stream'
        ])
            ->whereHas('term', fn($query) => $query->where('status', TermStatusEnum::ACTIVE))
            ->where('status', CurriculaStatusEnum::ACTIVE->value)->get();



        $genders = GenderTypeEnum::options();

        return Response::success([
            'curricula' => CurriculumResource::collection($curricula),
            'genders' => $genders,
        ]);
    }

    // public function resourcesForExcelImport()
    // {
    //     $classLevelArmOptions = $this->classLevelArmRepository->getAll()->map(function ($classLevelArm) {
    //         return [
    //             'id' => $classLevelArm->id,
    //             'uuid' => $classLevelArm->uuid,
    //             'class_level' => $classLevelArm->classLevel?->name,
    //             'arm' => $classLevelArm->arm?->label,
    //             'stream' => $classLevelArm->stream?->name,
    //         ];
    //     });

    //     return Response::success([
    //         'class_level_arms' => $classLevelArmOptions,
    //     ]);
    // }
}
