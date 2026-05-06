<?php

namespace App\Http\Controllers;

use App\DTOs\StudentDto;
use App\Enums\CurriculaStatusEnum;
use App\Enums\GenderTypeEnum;
use App\Enums\TermStatusEnum;
use App\Http\Requests\StudentRequest;
use App\Http\Resources\ClassLevelArmOptionsResource;
use App\Http\Resources\StudentResource;
use App\Models\Curriculum;
use App\Models\Student;
use App\Repositories\ClassLevelArmRepository;
use App\Services\StudentService;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(
        protected StudentService $studentService,
        protected ClassLevelArmRepository $classLevelArmRepository,
    ) {}

    public function index(Request $request)
    {
        $students = $this->studentService->paginate($request);
        logger('Student', collect($students)->toArray());
        
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

        $curriculaOptions = $curricula->map(function ($curriculum) {
            return [
                'id' => $curriculum->id,
                'uuid' => $curriculum->uuid,
                'term' => $curriculum->term?->order,
                'term_name' => $curriculum->term?->name,
                'class_level' => $curriculum->classLevelArm?->classLevel?->name,
                'arm' => $curriculum->classLevelArm?->arm?->label,
                'stream' => $curriculum->classLevelArm?->stream?->name,
            ];
        });

        $genders = GenderTypeEnum::options();

        return Response::success([
            'curricula' => $curriculaOptions,
            'genders' => $genders,
        ]);
    }
}
