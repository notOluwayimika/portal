<?php

namespace App\Services;

use App\DTOs\TeacherDto;
use App\Enums\GenderTypeEnum;
use App\Models\FileUpload;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\TeacherAccountCreatedNotification;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TeacherService
{
    public function __construct(
        private FileUploadService $fileUploadService,
        private PasswordGeneratorService $passwordGenerator,
    ) {}

    public function paginate(Request $request): LengthAwarePaginator
    {
        $limit = $request->input('limit', 25);
        return Teacher::query()
            ->when($request->search, function ($q) use ($request) {
                $term = '%' . $request->search . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('first_name', 'LIKE', $term)
                          ->orWhere('last_name', 'LIKE', $term)
                          ->orWhere('staff_number', 'LIKE', $term);
                });
            })
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->with(['photoFile'])
            ->latest()
            ->paginate($request->integer('per_page', $limit));
    }

    public function store(User $user, array $attributes): Teacher
    {
        return $user->teacher()->create($attributes);
    }

    public function preparedDto(Request $request, ?int $photoId = null): TeacherDto
    {
        $data = $request->validated();
        $data['school_id'] = session('school_id') ?? auth()->user()->school_id;
        $data['photo_id']  = $request->isMethod('post')
            ? $this->uploadPhoto($request)
            : $this->replacePhoto($request, $photoId);

        unset($data['photo']);

        return TeacherDto::fromArray($data);
    }

    public function processTeacherAccount(Request $request): void
    {
        $dto           = $this->preparedDto($request);
        $plainPassword = $this->passwordGenerator->generate();

        $user = DB::transaction(function () use ($dto, $plainPassword) {
            $user = User::create($dto->only(['first_name', 'last_name', 'email', 'school_id']) + [
                'password' => $plainPassword,
            ]);

            $user->assignRole('teacher');
            $this->store($user, $dto->toArray());

            return $user;
        });

        $this->notifyTeacher($user, $plainPassword);
    }

    private function uploadPhoto(Request $request): ?int
    {
        if (!$request->hasFile('photo')) {
            return null;
        }

        return $this->fileUploadService->storeAndUploadFile($request, 'photo', 'teachers/photos');
    }

    private function replacePhoto(Request $request, ?int $existingPhotoId): ?int
    {
        if (!$request->hasFile('photo')) {
            return $existingPhotoId;
        }

        if ($existingPhotoId) {
            $old = $this->fileUploadService->getFileUpload($existingPhotoId);

            if ($old) {
                $this->fileUploadService->unlinkFileUpload($old->folder_path . '/' . $old->name, null);
                $this->fileUploadService->deleteFileUpload($existingPhotoId);
            }
        }

        return $this->fileUploadService->storeAndUploadFile($request, 'photo', 'teachers/photos');
    }

    public function show(Teacher $teacher): Teacher
    {
        return $teacher->load(['photoFile', 'user']);
    }

    public function update(Teacher $teacher, array $attributes): Teacher
    {
        $teacher->update(array_filter(
            $attributes,
            fn($v) => !is_null($v)
        ) + ['photo_id' => $attributes['photo_id'] ?? $teacher->photo_id]);

        return $teacher;
    }

    public function updateStatus(Teacher $teacher, string $status): void
    {
        $teacher->update(['status' => $status]);
    }

    public function delete(Teacher $teacher): bool
    {
        return (bool) $teacher->delete();
    }

    public function import(array $rows, int $schoolId): array
    {
        $saved  = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowErrors = $this->validateImportRow($row, $schoolId);

            if (!empty($rowErrors)) {
                $errors[$index] = $rowErrors;
                continue;
            }

            try {
                $attrs         = $this->prepareImportRow($row, $schoolId);
                $plainPassword = $this->passwordGenerator->generate();

                $user = DB::transaction(function () use ($attrs, $schoolId, $plainPassword) {
                    $user = User::create([
                        'first_name' => $attrs['first_name'],
                        'last_name'  => $attrs['last_name'],
                        'email'      => $attrs['email'],
                        'school_id'  => $schoolId,
                        'password'   => $plainPassword,
                    ]);

                    $user->assignRole('teacher');
                    $this->store($user, array_diff_key($attrs, ['email' => null]));

                    return $user;
                });

                $saved++;
                $this->notifyTeacher($user, $plainPassword);
            } catch (\Throwable $e) {
                $errors[$index] = [$e->getMessage()];
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    private function notifyTeacher(User $user, string $plainPassword): void
    {
        try {
            $schoolName = $user->school?->name ?? config('app.name');
            $user->notify(new TeacherAccountCreatedNotification(
                plainPassword: $plainPassword,
                schoolName:    $schoolName,
                loginUrl:      url('/login'),
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send teacher account notification', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function prepareImportRow(array $row, int $schoolId): array
    {
        return [
            'school_id'     => $schoolId,
            'first_name'    => trim($row['first_name']),
            'last_name'     => trim($row['last_name']),
            'email'         => isset($row['email']) ? trim($row['email']) : null,
            'staff_number'  => isset($row['staff_number']) ? trim($row['staff_number']) : null,
            'gender'        => GenderTypeEnum::normalizeGender($row['gender'] ?? null),
            'date_of_birth' => normalizeDate($row['date_of_birth'] ?? null),
            'address'       => isset($row['address']) ? trim($row['address']) : null,
            'qualification' => isset($row['qualification']) ? trim($row['qualification']) : null,
            'hire_date'     => normalizeDate($row['hire_date'] ?? null),
            'status'        => 'active',
            'photo_id'      => null,
        ];
    }

    private function validateImportRow(array $row, int $schoolId): array
    {
        $errors = [];

        if (empty(trim($row['first_name'] ?? ''))) {
            $errors[] = 'First name is required.';
        }

        if (empty(trim($row['last_name'] ?? ''))) {
            $errors[] = 'Last name is required.';
        }

        $email = isset($row['email']) ? trim($row['email']) : null;
        if (!$email) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email '{$email}' is not valid.";
        } elseif (User::where('email', $email)->exists()) {
            $errors[] = "Email '{$email}' is already registered.";
        }

        if (!empty($row['gender'])) {
            $gender = GenderTypeEnum::normalizeGender($row['gender']);
            if (!in_array($gender, ['male', 'female', 'other'], true)) {
                $errors[] = "Gender '{$row['gender']}' is not valid. Expected: male, female, or other.";
            }
        }

        foreach (['date_of_birth' => 'Date of birth', 'hire_date' => 'Hire date'] as $field => $label) {
            $val = $row[$field] ?? null;
            if ($val !== null && $val !== '' && !isValidDate($val)) {
                $errors[] = "{$label} '{$val}' could not be parsed into a valid date.";
            }
        }

        $staffNumber = isset($row['staff_number']) ? trim($row['staff_number']) : null;
        if ($staffNumber && Teacher::where('school_id', $schoolId)->where('staff_number', $staffNumber)->exists()) {
            $errors[] = "Staff number '{$staffNumber}' already exists.";
        }

        return $errors;
    }
}
