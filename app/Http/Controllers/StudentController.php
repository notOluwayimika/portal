<?php

namespace App\Http\Controllers;

use App\DTOs\StudentDto;
use App\Http\Requests\StudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Services\StudentService;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(protected StudentService $studentService) {}

    public function index(Request $request)
    {
        $students = $this->studentService->paginate($request);
        return response()->json(StudentResource::collection($students));
    }

    public function store(StudentRequest $request)
    {
        $data = $request->validated();
        $data['school_id'] = $request->user()->school_id;

        $dto = StudentDto::fromArray($data);
        $this->studentService->store($dto->toArray());

        return Response::created('Student created successfully.');
    }

    public function show(Student $student)
    {
        return Response::json(StudentResource::make($student));
    }

    public function update(StudentRequest $request, Student $student)
    {
        $this->studentService->update($student, $request->validated());
        return Response::success('Student updated successfully.');
    }

    public function destroy(Student $student)
    {
        $this->studentService->delete($student);
        return response()->noContent();
    }
}
