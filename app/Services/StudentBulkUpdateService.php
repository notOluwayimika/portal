<?php

namespace App\Services;

use App\Models\Scholarship;
use App\Models\SportHouse;
use App\Models\Student;
use App\Services\Validators\StudentBulkUpdateRowValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StudentBulkUpdateService
{
    private array $sportHouseCache = [];
    private array $scholarshipCache = [];

    public function __construct(
        private StudentBulkUpdateRowValidator $validator,
    ) {}

    public function reset(): void
    {
        $this->sportHouseCache = [];
        $this->scholarshipCache = [];
    }

    /**
     * @return array{status: 'success'|'failed'|'skipped', message: string}
     */
    public function processRow(array $row, int $schoolId): array
    {
        $result = $this->validator->validate($row);
        if (!empty($result['errors'])) {
            return $this->failed(implode(' ', $result['errors']));
        }

        $normalized = $result['normalized'];
        $updatable  = $result['updatable'];

        $student = Student::query()
            ->where('school_id', $schoolId)
            ->where('admission_number', (string) $normalized['code'])
            ->first();

        if (!$student) {
            return $this->failed("Student with code \"{$normalized['code']}\" not found.");
        }

        if ($normalized['sport_house'] !== null) {
            $sportHouse = $this->resolveSportHouse((string) $normalized['sport_house'], $schoolId);
            if ($sportHouse === null) {
                return $this->failed("Sport house \"{$normalized['sport_house']}\" not found in this school.");
            }
            $updatable['sport_house_id'] = $sportHouse->id;
        }

        if ($normalized['scholarship'] !== null) {
            $scholarship = $this->resolveScholarship((string) $normalized['scholarship'], $schoolId);
            if ($scholarship === null) {
                return $this->failed("Scholarship \"{$normalized['scholarship']}\" not found in this school.");
            }
            $updatable['scholarship_id'] = $scholarship->id;
        }

        if (empty($updatable)) {
            return $this->skipped('No fields to update (all columns are empty besides code).');
        }

        try {
            DB::transaction(function () use ($student, $updatable) {
                $student->update($updatable);
            });
        } catch (\Throwable $e) {
            Log::error('Student bulk update: update failed', [
                'student_id' => $student->id,
                'error'      => $e->getMessage(),
            ]);
            return $this->failed('Failed to update student: ' . $e->getMessage());
        }

        $fields = array_keys($updatable);
        return $this->ok('Updated: ' . implode(', ', $fields) . '.');
    }

    private function resolveSportHouse(string $name, int $schoolId): ?SportHouse
    {
        $key = strtolower($name);
        if (isset($this->sportHouseCache[$key])) {
            return $this->sportHouseCache[$key];
        }

        $sportHouse = SportHouse::query()
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(name) = ?', [$key])
            ->first();

        if ($sportHouse) {
            $this->sportHouseCache[$key] = $sportHouse;
        }

        return $sportHouse;
    }

    private function resolveScholarship(string $name, int $schoolId): ?Scholarship
    {
        $key = strtolower($name);
        if (isset($this->scholarshipCache[$key])) {
            return $this->scholarshipCache[$key];
        }

        $scholarship = Scholarship::query()
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(name) = ?', [$key])
            ->first();

        if ($scholarship) {
            $this->scholarshipCache[$key] = $scholarship;
        }

        return $scholarship;
    }

    private function ok(string $message): array
    {
        return ['status' => 'success', 'message' => $message];
    }

    private function skipped(string $message): array
    {
        return ['status' => 'skipped', 'message' => $message];
    }

    private function failed(string $message): array
    {
        return ['status' => 'failed', 'message' => $message];
    }
}
