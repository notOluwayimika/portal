<?php

namespace App\Services;

use App\DTOs\TeacherDto;
use App\Enums\GenderTypeEnum;
use App\Models\Role;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\TeacherAccountCreatedNotification;
use App\Support\ActiveSchool;
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
                $term = '%'.$request->search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('first_name', 'LIKE', $term)
                        ->orWhere('last_name', 'LIKE', $term)
                        ->orWhere('staff_number', 'LIKE', $term);
                });
            })
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->with(['photoFile', 'school', 'user.schools'])
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
        // Only stamp the home school on create. On update this would re-home
        // a teacher being edited from a pivot-granted school (update() writes
        // non-null values through array_filter).
        $data['school_id'] = $request->isMethod('post') ? ActiveSchool::id() : null;
        $data['photo_id'] = $request->isMethod('post')
            ? $this->uploadPhoto($request)
            : $this->replacePhoto($request, $photoId);

        unset($data['photo']);

        return TeacherDto::fromArray($data);
    }

    public function processTeacherAccount(Request $request): void
    {
        $dto = $this->preparedDto($request);
        $plainPassword = $this->passwordGenerator->generate(12, 'teacher');

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
        if (! $request->hasFile('photo')) {
            return null;
        }

        return $this->fileUploadService->storeAndUploadFile($request, 'photo', 'teachers/photos');
    }

    private function replacePhoto(Request $request, ?int $existingPhotoId): ?int
    {
        if (! $request->hasFile('photo')) {
            return $existingPhotoId;
        }

        if ($existingPhotoId) {
            $old = $this->fileUploadService->getFileUpload($existingPhotoId);

            if ($old) {
                $this->fileUploadService->unlinkFileUpload($old->folder_path.'/'.$old->name, null);
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
            fn ($v) => ! is_null($v)
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

    /**
     * Import teacher rows into $schoolId. An email that already belongs to a
     * user is an existing identity to extend, not a duplicate — users span
     * schools (SchoolScope exempts User), so the four outcomes are:
     *
     *  - unknown email          → create user + teacher record, mail credentials
     *  - teacher of this school → skip, nothing written
     *  - teacher of another school → grant teacher access to this school only;
     *    the teacher row is shared (Teacher::applySchoolScope), so the CSV's
     *    per-teacher columns are deliberately ignored
     *  - any other existing user (e.g. an admin) → grant teacher access AND
     *    create the teacher record for this school
     *
     * @return array{saved: int, linked: int, skipped: int, errors: array<int, array<int, string>>}
     */
    public function import(array $rows, int $schoolId): array
    {
        $school = School::findOrFail($schoolId);

        $saved = 0;
        $linked = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowErrors = $this->validateImportRow($row);

            if (! empty($rowErrors)) {
                $errors[$index] = $rowErrors;

                continue;
            }

            $email = trim($row['email']);
            $existing = User::where('email', $email)->first();

            // Case 2/3: already a teacher somewhere. Resolved without SchoolScope
            // because applySchoolScope makes a visiting teacher look local.
            $teacher = $existing
                ? Teacher::withoutGlobalScope(SchoolScope::class)
                    ->where('user_id', $existing->id)
                    ->first()
                : null;

            if ($teacher) {
                if ((int) $teacher->school_id === $schoolId) {
                    $skipped++;

                    continue;
                }

                try {
                    $existing->grantSchoolAccess($school, 'teacher');
                    $linked++;
                } catch (\Throwable $e) {
                    $errors[$index] = [$e->getMessage()];
                }

                continue;
            }

            // Cases 1 and 4 both write a teacher record, so the staff number
            // has to be free in this school.
            $staffErrors = $this->validateImportStaffNumber($row, $schoolId);

            if (! empty($staffErrors)) {
                $errors[$index] = $staffErrors;

                continue;
            }

            $attrs = $this->prepareImportRow($row, $schoolId);

            try {
                if ($existing) {
                    // Case 4: an existing user (typically an admin elsewhere)
                    // becomes a teacher here. Never restamp users.school_id —
                    // that is their home school and feeds school access.
                    DB::transaction(function () use ($existing, $school, $attrs) {
                        $existing->grantSchoolAccess($school, 'teacher');
                        $this->store($existing, array_diff_key($attrs, ['email' => null]));
                    });

                    $linked++;

                    continue;
                }

                $plainPassword = $this->passwordGenerator->generate(12, 'teacher');

                $user = DB::transaction(function () use ($attrs, $schoolId, $plainPassword) {
                    $user = User::create([
                        'first_name' => $attrs['first_name'],
                        'last_name' => $attrs['last_name'],
                        'email' => $attrs['email'],
                        'school_id' => $schoolId,
                        'password' => $plainPassword,
                    ]);

                    // Assign within the target school's team explicitly rather than
                    // whatever team happens to be ambient — a school-scoped role with
                    // a null team grants access to no school at all (S7 invariant).
                    $this->withPermissionsTeam($schoolId, fn () => $user->assignRole('teacher'));
                    $this->store($user, array_diff_key($attrs, ['email' => null]));

                    return $user;
                });

                $saved++;
                $this->notifyTeacher($user, $plainPassword);
            } catch (\Throwable $e) {
                $errors[$index] = [$e->getMessage()];
            }
        }

        return ['saved' => $saved, 'linked' => $linked, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Run $callback with the spatie permissions team pinned to $schoolId, then
     * restore whatever team was active. Mirrors User::grantSchoolAccess.
     */
    private function withPermissionsTeam(int $schoolId, callable $callback): void
    {
        $previousTeam = getPermissionsTeamId();

        setPermissionsTeamId(null);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);

        setPermissionsTeamId($schoolId);

        try {
            $callback();
        } finally {
            setPermissionsTeamId($previousTeam);
        }
    }

    private function notifyTeacher(User $user, string $plainPassword): void
    {
        try {
            $schoolName = $user->school?->name ?? config('app.name');
            $user->notify(new TeacherAccountCreatedNotification(
                plainPassword: $plainPassword,
                schoolName: $schoolName,
                loginUrl: url('/login'),
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send teacher account notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function prepareImportRow(array $row, int $schoolId): array
    {
        return [
            'school_id' => $schoolId,
            'first_name' => trim($row['first_name']),
            'last_name' => trim($row['last_name']),
            'email' => isset($row['email']) ? trim($row['email']) : null,
            'staff_number' => isset($row['staff_number']) ? trim($row['staff_number']) : null,
            // normalizeGender() returns '' for a blank/unrecognised value, which
            // the enum column rejects. Gender is optional here, so store null.
            'gender' => GenderTypeEnum::normalizeGender($row['gender'] ?? null) ?: null,
            'date_of_birth' => normalizeDate($row['date_of_birth'] ?? null),
            'address' => isset($row['address']) ? trim($row['address']) : null,
            'qualification' => isset($row['qualification']) ? trim($row['qualification']) : null,
            'hire_date' => normalizeDate($row['hire_date'] ?? null),
            'status' => 'active',
            'photo_id' => null,
        ];
    }

    private function validateImportRow(array $row): array
    {
        $errors = [];

        if (empty(trim($row['first_name'] ?? ''))) {
            $errors[] = 'First name is required.';
        }

        if (empty(trim($row['last_name'] ?? ''))) {
            $errors[] = 'Last name is required.';
        }

        $email = isset($row['email']) ? trim($row['email']) : null;
        if (! $email) {
            $errors[] = 'Email is required.';
        } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email '{$email}' is not valid.";
        }

        if (! empty($row['gender'])) {
            $gender = GenderTypeEnum::normalizeGender($row['gender']);
            if (! in_array($gender, ['male', 'female', 'other'], true)) {
                $errors[] = "Gender '{$row['gender']}' is not valid. Expected: male, female, or other.";
            }
        }

        foreach (['date_of_birth' => 'Date of birth', 'hire_date' => 'Hire date'] as $field => $label) {
            $val = $row[$field] ?? null;
            if ($val !== null && $val !== '' && ! isValidDate($val)) {
                $errors[] = "{$label} '{$val}' could not be parsed into a valid date.";
            }
        }

        return $errors;
    }

    /**
     * Only meaningful for rows that will write a teacher record; rows that merely
     * link an existing teacher to a second school ignore the CSV's staff number.
     */
    private function validateImportStaffNumber(array $row, int $schoolId): array
    {
        $staffNumber = isset($row['staff_number']) ? trim($row['staff_number']) : null;

        if ($staffNumber && Teacher::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->where('staff_number', $staffNumber)
            ->exists()
        ) {
            return ["Staff number '{$staffNumber}' already exists."];
        }

        return [];
    }
}
