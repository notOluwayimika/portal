<?php

namespace App\Services;

use App\DTOs\StudentDto;
use App\Enums\GenderTypeEnum;
use App\Enums\StudentStatusEnum;
use App\Models\Curriculum;
use App\Models\Student;
use App\Models\StudentCurriculum;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StudentService
{
    public function __construct(
        private CurriculumEnrollmentService $enrollmentService
    ) {}

    public function paginate(Request $request): LengthAwarePaginator
    {
        return Student::query()
            ->when($request->search, function ($q) use ($request) {
                $searchTerm = '%'.$request->search.'%';
                $q->where('first_name', 'LIKE', $searchTerm)
                    ->orWhere('last_name', 'LIKE', $searchTerm)
                    ->orWhere('admission_number', 'LIKE', $searchTerm);
            })
            // Filter by the student's ACTIVE enrolment's class level and/or arm.
            // Applying both narrows to a single class-level-arm, because a student
            // has one active curriculum bound to one class_level_arm — the two
            // filters compose to "this class level AND this arm".
            ->when($request->filled('class_level'), function ($q) use ($request) {
                $q->whereHas('currentCurriculum.curriculum.classLevelArm.classLevel',
                    fn ($cl) => $cl->where('uuid', $request->string('class_level')));
            })
            ->when($request->filled('arm'), function ($q) use ($request) {
                $q->whereHas('currentCurriculum.curriculum.classLevelArm.arm',
                    fn ($a) => $a->where('uuid', $request->string('arm')));
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
                    // No promoted_to_id: a newly admitted student has not been promoted from anywhere (S1 c5).
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
            ], fn ($v) => ! is_null($v)) + ['photo_id' => $attributes['photo_id'] ?? null]);

            if (
                isset($attributes['curriculum_id']) &&
                $student->studentCurriculum?->curriculum_id != $attributes['curriculum_id']
            ) {
                // firstOrCreate, said plainly (S1 commit 5). With promoted_to_id gone (Part 2), the old
                // updateOrCreate's update-array was empty — a firstOrCreate wearing a disguise that ALSO
                // cleared any existing link on an unrelated match. school_id is derived by the model's
                // creating hook from student_id. NOTE: this leans on
                // student_curricula_student_id_curriculum_id_unique, which Option B will revisit — do not
                // build anything new on it. Editing a curriculum through the student form is a
                // promotion-shaped act outside promote(); that is Option B's problem, not this commit's.
                StudentCurriculum::firstOrCreate([
                    'student_id' => $student->id,
                    'curriculum_id' => $attributes['curriculum_id'],
                ]);
            }

            return $student;
        });
    }

    public function updateStatus(Student $student, string $status): void
    {
        $latestCurriculum = $student->studentCurricula()->latest('id')->first();
        if ($latestCurriculum instanceof StudentCurriculum) {
            // Clear the promotion link when the status leaves 'promoted' — parity with
            // StudentCurriculumController::updateStatus (:98, :118), which this route (PATCH
            // /students/{student}/status) bypassed. `status` is cast to StudentStatusEnum, so compare AFTER
            // assignment (as the controller does), not against the raw string parameter.
            $latestCurriculum->status = StudentStatusEnum::from($status);
            if ($latestCurriculum->status !== StudentStatusEnum::PROMOTED) {
                $latestCurriculum->promoted_to_id = null;
            }
            $latestCurriculum->save();
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

            if (! empty($rowErrors)) {
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
        if (! in_array($gender, ['male', 'female', 'other'], true)) {
            $errors[] = "Gender '{$row['gender']}' is not valid. Expected: male, female, or other.";
        }

        $dob = $row['date_of_birth'] ?? null;
        if ($dob !== null && $dob !== '') {
            if (! isValidDate($dob)) {
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
