<?php

namespace App\Services;

use App\Enums\GenderTypeEnum;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

class TeacherService
{
    public function paginate(Request $request): LengthAwarePaginator
    {
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
            ->paginate($request->integer('per_page', 25));
    }

    public function store(User $user, array $attributes): Teacher
    {
        return $user->teacher()->create($attributes);
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
                $attrs = $this->prepareImportRow($row, $schoolId);

                $user = User::create([
                    'first_name' => $attrs['first_name'],
                    'last_name'  => $attrs['last_name'],
                    'email'      => $attrs['email'],
                    'school_id'  => $schoolId,
                    'password'   => Hash::make('password'),
                ]);

                $this->store($user, array_diff_key($attrs, ['email' => null]));
                $saved++;
            } catch (\Throwable $e) {
                $errors[$index] = [$e->getMessage()];
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
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
