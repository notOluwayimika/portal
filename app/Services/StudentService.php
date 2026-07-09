<?php

namespace App\Services;

use App\DTOs\StudentDto;
use App\Enums\GenderTypeEnum;
use App\Models\Curriculum;
use App\Models\Student;
use App\Models\StudentCurriculum;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StudentService
{
    public function __construct(
        private CurriculumEnrollmentService $enrollmentService
    ) {
    }

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
                'photoFile',
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
                'photo_id' => $attributes['photo_id'] ?? null,
                'admission_date' => $attributes['admission_date'] ?? null,
                'address' => $attributes['address'] ?? null,
                'nationality' => $attributes['nationality'] ?? null,
                'other_nationality' => $attributes['other_nationality'] ?? null,
                'state_of_origin' => $attributes['state_of_origin'] ?? null,
                'religion' => $attributes['religion'] ?? null,
                'previous_school' => $attributes['previous_school'] ?? null,
                'sport_house_id' => $attributes['sport_house_id'] ?? null,
                'scholarship_id' => $attributes['scholarship_id'] ?? null,
            ]);

            $curriculum = Curriculum::findOrFail($attributes['curriculum_id']);

            $this->enrollmentService->enroll(
                $student,
                $curriculum,
                auth()->user(),
                [
                    'status' => $attributes['status'] ?? 'active',
                    'promoted_to_id' => $attributes['promoted_to_id'] ?? null,
                ]
            );

            return $student;
        });
    }

    public function show(Student $student): Student
    {
        return $student->load([
            'photoFile',
            'currentCurriculum.curriculum.classLevelArm.classLevel',
            'currentCurriculum.curriculum.classLevelArm.arm',
            'currentCurriculum.curriculum.classLevelArm.stream',
            'guardians.user',
            'guardians.photoFile',
            'sportHouse',
            'scholarship',
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
                'admission_date' => $attributes['admission_date'] ?? null,
                'address' => $attributes['address'] ?? null,
                'nationality' => $attributes['nationality'] ?? null,
                'other_nationality' => $attributes['other_nationality'] ?? null,
                'state_of_origin' => $attributes['state_of_origin'] ?? null,
                'religion' => $attributes['religion'] ?? null,
                'previous_school' => $attributes['previous_school'] ?? null,
                'sport_house_id' => $attributes['sport_house_id'] ?? null,
                'scholarship_id' => $attributes['scholarship_id'] ?? null,
            ], fn($v) => !is_null($v)) + ['photo_id' => $attributes['photo_id'] ?? null]);

            if (
                isset($attributes['curriculum_id']) &&
                $student->studentCurriculum?->curriculum_id != $attributes['curriculum_id']
            ) {
                StudentCurriculum::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'curriculum_id' => $attributes['curriculum_id'],
                    ],
                    [
                        'promoted_to_id' => $attributes['promoted_to_id'] ?? null,
                    ]
                );
            }

            return $student;
        });
    }

    public function updateStatus(Student $student, string $status): void
    {
        $latestCurriculum = $student->studentCurricula()->latest('id')->first();
        if ($latestCurriculum instanceof StudentCurriculum) {
            $latestCurriculum->update(['status' => $status]);
        }
    }

    public function delete(Student $student): bool
    {
        return $student->delete();
    }

    private function preparedDto(array $row, int $curriculumId, int $schoolId): array
    {
        $row['school_id'] = $schoolId;
        $row['first_name'] = trim($row['first_name']);
        $row['last_name'] = trim($row['last_name']);
        $row['middle_name'] = isset($row['middle_name']) ? trim($row['middle_name']) : null;
        $row['gender'] = GenderTypeEnum::normalizeGender($row['gender'] ?? null);
        $row['date_of_birth'] = normalizeDate($row['date_of_birth'] ?? null);
        $row['admission_number'] = isset($row['admission_number']) ? trim($row['admission_number']) : null;
        $row['photo_id'] = null;
        $row['curriculum_id'] = $curriculumId;

        return StudentDto::fromArray($row)->toArray();
    }

    /**
     * Bulk-import students from parsed Excel rows.
     *
     * Each row is processed independently: valid rows are persisted, invalid
     * rows are skipped and their errors are collected.  Returns the count of
     * saved records and a map of { rowIndex => string[] } for failed rows.
     */
    public function import(array $rows, int $curriculumId, int $schoolId): array
    {
        $saved = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowErrors = $this->validateImportRow($row, $index, $schoolId);

            if (!empty($rowErrors)) {
                $errors[$index] = $rowErrors;
                continue;
            }

            try {
                $this->store($this->preparedDto($row, $curriculumId, $schoolId));
                $saved++;
            } catch (\Throwable $e) {
                $errors[$index] = [$e->getMessage()];
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * Validate a single import row and return any error messages.
     */
    private function validateImportRow(array $row, int $index, int $schoolId): array
    {
        $errors = [];

        if (empty(trim($row['first_name'] ?? ''))) {
            $errors[] = 'First name is required.';
        }

        if (empty(trim($row['last_name'] ?? ''))) {
            $errors[] = 'Last name is required.';
        }

        $gender = GenderTypeEnum::normalizeGender($row['gender'] ?? null);
        if (!in_array($gender, ['male', 'female', 'other'], true)) {
            $errors[] = "Gender '{$row['gender']}' is not valid. Expected: male, female, or other.";
        }

        $dob = $row['date_of_birth'] ?? null;
        if ($dob !== null && $dob !== '') {
            if (!isValidDate($dob)) {
                $errors[] = "Date of birth '{$dob}' could not be parsed into a valid date.";
            }
        }

        $admissionNumber = isset($row['admission_number']) ? trim($row['admission_number']) : null;
        if ($admissionNumber) {
            $exists = Student::where('school_id', $schoolId)
                ->where('admission_number', $admissionNumber)
                ->exists();

            if ($exists) {
                $errors[] = "Admission number '{$admissionNumber}' already exists.";
            }
        }

        return $errors;
    }
}
