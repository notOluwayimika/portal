<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DataGapsService
{
    public function __construct(private readonly int $schoolId) {}

    /**
     * @return array<array{type: string, count: int, severity: string, resolution_path: string}>
     */
    public function detect(): array
    {
        $gaps = [];

        $gaps = array_merge($gaps, $this->studentsWithoutGuardian());
        $gaps = array_merge($gaps, $this->studentsWithoutEnrollment());
        $gaps = array_merge($gaps, $this->enrollmentsWithoutSubjects());
        $gaps = array_merge($gaps, $this->teachersWithoutAssignment());

        return array_values(array_filter($gaps, fn($g) => $g['count'] > 0));
    }

    private function studentsWithoutGuardian(): array
    {
        try {
            $count = DB::table('students')
                ->where('students.school_id', $this->schoolId)
                ->whereNull('students.deleted_at')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('guardian_student')
                        ->whereColumn('guardian_student.student_id', 'students.id');
                })
                ->count();

            return [[
                'type' => 'students_without_guardian',
                'count' => $count,
                'severity' => $count > 10 ? 'warning' : 'info',
                'resolution_path' => '/guardians',
            ]];
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Data gap 'students_without_guardian' failed: {$e->getMessage()}");
            return [];
        }
    }

    private function studentsWithoutEnrollment(): array
    {
        try {
            $count = DB::table('students')
                ->where('students.school_id', $this->schoolId)
                ->whereNull('students.deleted_at')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('student_curricula')
                        ->whereColumn('student_curricula.student_id', 'students.id')
                        ->where('student_curricula.status', 'ACTIVE');
                })
                ->count();

            return [[
                'type' => 'students_without_enrollment',
                'count' => $count,
                'severity' => $count > 5 ? 'warning' : 'info',
                'resolution_path' => '/students',
            ]];
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Data gap 'students_without_enrollment' failed: {$e->getMessage()}");
            return [];
        }
    }

    private function enrollmentsWithoutSubjects(): array
    {
        try {
            $count = DB::table('student_curricula')
                ->join('students', 'student_curricula.student_id', '=', 'students.id')
                ->where('students.school_id', $this->schoolId)
                ->where('student_curricula.status', 'ACTIVE')
                ->whereNull('student_curricula.ended_at')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('student_subjects')
                        ->whereColumn('student_subjects.student_curriculum_id', 'student_curricula.id')
                        ->where('student_subjects.status', 'Active');
                })
                ->count();

            return [[
                'type' => 'enrollments_without_subjects',
                'count' => $count,
                'severity' => $count > 3 ? 'critical' : 'warning',
                'resolution_path' => '/setup',
            ]];
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Data gap 'enrollments_without_subjects' failed: {$e->getMessage()}");
            return [];
        }
    }

    private function teachersWithoutAssignment(): array
    {
        try {
            $count = DB::table('teachers')
                ->where('teachers.school_id', $this->schoolId)
                ->whereNull('teachers.deleted_at')
                ->where('teachers.status', 'active')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('teacher_curriculum_subjects')
                        ->whereColumn('teacher_curriculum_subjects.teacher_id', 'teachers.id');
                })
                ->count();

            return [[
                'type' => 'teachers_without_assignment',
                'count' => $count,
                'severity' => 'info',
                'resolution_path' => '/teachers',
            ]];
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Data gap 'teachers_without_assignment' failed: {$e->getMessage()}");
            return [];
        }
    }
}
