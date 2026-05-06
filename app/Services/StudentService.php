<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentCurriculum;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StudentService
{
    public function paginate(Request $request): LengthAwarePaginator
    {
        return Student::query()
            ->when($request->search, function ($q) use ($request) {
                $searchTerm = '%' . $request->search . '%';
                $q->where('first_name', 'LIKE', $searchTerm)
                    ->orWhere('last_name', 'LIKE', $searchTerm)
                    ->orWhere('admission_number', 'LIKE', $searchTerm);
            })
            ->with([
                'currentCurriculum.curriculum.classLevelArm.classLevel',
                'currentCurriculum.curriculum.classLevelArm.arm',
                'currentCurriculum.curriculum.classLevelArm.stream',
            ])
            ->latest()
            ->paginate($request->integer('per_page', 25));
    }

    public function store(array $attributes): Student
    {
        return DB::transaction(function () use ($attributes) {
            $student = Student::create([
                'school_id' => $attributes['school_id'],
                'user_id' => $attributes['user_id'] ?? null,
                'first_name' => $attributes['first_name'],
                'last_name' => $attributes['last_name'],
                'middle_name' => $attributes['middle_name'] ?? null,
                'gender' => $attributes['gender'],
                'date_of_birth' => $attributes['date_of_birth'] ?? null,
                'admission_number' => $attributes['admission_number'] ?? null,
                'photo' => $attributes['photo'] ?? null,
            ]);

            StudentCurriculum::create([
                'student_id' => $student->id,
                'curriculum_id' => $attributes['curriculum_id'],
                'status' => $attributes['status'] ?? \App\Enums\StudentStatusEnum::ACTIVE->value,
                'promoted_to_id' => $attributes['promoted_to_id'] ?? null,
            ]);

            return $student;
        });
    }

    public function show(Student $student): Student
    {
        return $student->load([
            'currentCurriculum.curriculum.classLevelArm.classLevel',
            'currentCurriculum.curriculum.classLevelArm.arm',
            'currentCurriculum.curriculum.classLevelArm.stream',
        ]);
    }

    public function update(Student $student, array $attributes): Student
    {
        return DB::transaction(function () use ($student, $attributes) {
            $student->update(array_filter([
                'first_name' => $attributes['first_name'],
                'last_name' => $attributes['last_name'],
                'middle_name' => $attributes['middle_name'] ?? null,
                'gender' => $attributes['gender'],
                'date_of_birth' => $attributes['date_of_birth'] ?? null,
                'admission_number' => $attributes['admission_number'] ?? $student->admission_number,
                'photo' => $attributes['photo'] ?? null,
            ], fn($v) => !is_null($v)));

            if (isset($attributes['curriculum_id'])) {
                StudentCurriculum::updateOrCreate(
                    ['student_id' => $student->id],
                    [
                        'curriculum_id' => $attributes['curriculum_id'],
                        'promoted_to_id' => $attributes['promoted_to_id'] ?? null,
                    ]
                );
            }

            return $student;
        });
    }

    public function delete(Student $student): bool
    {
        return $student->delete();
    }
}
