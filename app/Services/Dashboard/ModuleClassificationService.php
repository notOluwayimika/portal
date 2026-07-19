<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ModuleClassificationService
{
    public function __construct(private readonly int $schoolId) {}

    /**
     * Classify all modules for the school.
     *
     * @return array<string, array{status: string, primary_table_rows: int, last_activity_at: string|null, threshold_used: array, daily_counts_30d: array}>
     */
    public function classifyAll(): array
    {
        return [
            'students' => $this->classifyStudents(),
            'guardians' => $this->classifyGuardians(),
            'academic' => $this->classifyAcademic(),
            'attendance' => $this->classifyAttendance(),
            'assessments' => $this->classifyAssessments(),
            'finance' => $this->classifyFinance(),
            'communication' => $this->classCommunication(),
            'files' => $this->classifyFiles(),
            'activity_log' => $this->classifyActivityLog(),
        ];
    }

    private function classifyStudents(): array
    {
        $thresholds = config('dashboard_thresholds.modules.students');

        try {
            $count = DB::table('students')
                ->where('school_id', $this->schoolId)
                ->whereNull('deleted_at')
                ->count();

            $lastActivity = DB::table('students')
                ->where('school_id', $this->schoolId)
                ->whereNull('deleted_at')
                ->max('created_at');

            $dailyCounts = $this->dailyCounts('students', 'school_id', $this->schoolId);

            return $this->buildModuleResult($count, $lastActivity, $thresholds, $dailyCounts);
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Module 'students' query failed: {$e->getMessage()}");

            return $this->emptyModuleResult($thresholds);
        }
    }

    private function classifyGuardians(): array
    {
        $thresholds = config('dashboard_thresholds.modules.guardians');

        try {
            $count = DB::table('guardians')
                ->where('school_id', $this->schoolId)
                ->count();

            $lastActivity = DB::table('guardians')
                ->where('school_id', $this->schoolId)
                ->max('created_at');

            $dailyCounts = $this->dailyCounts('guardians', 'school_id', $this->schoolId);

            return $this->buildModuleResult($count, $lastActivity, $thresholds, $dailyCounts);
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Module 'guardians' query failed: {$e->getMessage()}");

            return $this->emptyModuleResult($thresholds);
        }
    }

    private function classifyAcademic(): array
    {
        $thresholds = config('dashboard_thresholds.modules.academic');

        try {
            // Classify by curriculum subjects as the primary activity signal
            $count = DB::table('curriculum_subjects')
                ->join('curricula', 'curriculum_subjects.curriculum_id', '=', 'curricula.id')
                ->where('curricula.school_id', $this->schoolId)
                ->whereNull('curriculum_subjects.archived_at')
                ->count();

            $lastActivity = DB::table('curriculum_subjects')
                ->join('curricula', 'curriculum_subjects.curriculum_id', '=', 'curricula.id')
                ->where('curricula.school_id', $this->schoolId)
                ->max('curriculum_subjects.created_at');

            return $this->buildModuleResult($count, $lastActivity, $thresholds, []);
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Module 'academic' query failed: {$e->getMessage()}");

            return $this->emptyModuleResult($thresholds);
        }
    }

    private function classifyAttendance(): array
    {
        $thresholds = config('dashboard_thresholds.modules.attendance');

        // Attendance module not yet in schema — gracefully return empty
        if (! Schema::hasTable('attendance_records')) {
            return $this->emptyModuleResult($thresholds);
        }

        try {
            $count = DB::table('attendance_records')
                ->where('school_id', $this->schoolId)
                ->count();

            $lastActivity = DB::table('attendance_records')
                ->where('school_id', $this->schoolId)
                ->max('created_at');

            $dailyCounts = $this->dailyCounts('attendance_records', 'school_id', $this->schoolId);

            return $this->buildModuleResult($count, $lastActivity, $thresholds, $dailyCounts);
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Module 'attendance' query failed: {$e->getMessage()}");

            return $this->emptyModuleResult($thresholds);
        }
    }

    private function classifyAssessments(): array
    {
        $thresholds = config('dashboard_thresholds.modules.assessments');

        try {
            // scores has no school_id — join through students
            $count = DB::table('scores')
                ->join('students', 'scores.student_id', '=', 'students.id')
                ->where('students.school_id', $this->schoolId)
                ->whereNull('students.deleted_at')
                ->count('scores.id');

            $lastActivity = DB::table('scores')
                ->join('students', 'scores.student_id', '=', 'students.id')
                ->where('students.school_id', $this->schoolId)
                ->whereNull('students.deleted_at')
                ->max('scores.created_at');

            $dailyCounts = DB::table('scores')
                ->join('students', 'scores.student_id', '=', 'students.id')
                ->where('students.school_id', $this->schoolId)
                ->whereNull('students.deleted_at')
                ->where('scores.created_at', '>=', Carbon::now()->subDays(30))
                ->selectRaw('DATE(scores.created_at) as date, COUNT(scores.id) as count')
                ->groupByRaw('DATE(scores.created_at)')
                ->orderBy('date')
                ->get()
                ->map(fn ($r) => ['date' => $r->date, 'count' => (int) $r->count])
                ->toArray();

            return $this->buildModuleResult($count, $lastActivity, $thresholds, $dailyCounts);
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Module 'assessments' query failed: {$e->getMessage()}");

            return $this->emptyModuleResult($thresholds);
        }
    }

    private function classifyFinance(): array
    {
        $thresholds = config('dashboard_thresholds.modules.finance');

        // Finance module not yet in schema
        foreach (['finance_invoices', 'finance_payments', 'finance_fee_structures'] as $table) {
            if (Schema::hasTable($table)) {
                try {
                    $count = DB::table($table)->where('school_id', $this->schoolId)->count();
                    $lastActivity = DB::table($table)->where('school_id', $this->schoolId)->max('created_at');

                    return $this->buildModuleResult($count, $lastActivity, $thresholds, []);
                } catch (\Throwable $e) {
                    Log::channel('dashboard-analysis')->warning("Module 'finance' query failed: {$e->getMessage()}");
                }
            }
        }

        return $this->emptyModuleResult($thresholds);
    }

    private function classCommunication(): array
    {
        $thresholds = config('dashboard_thresholds.modules.communication');

        // Communication module not yet in schema
        foreach (['messages', 'notifications', 'announcements'] as $table) {
            if (Schema::hasTable($table)) {
                try {
                    $count = DB::table($table)->where('school_id', $this->schoolId)->count();
                    $lastActivity = DB::table($table)->where('school_id', $this->schoolId)->max('created_at');

                    return $this->buildModuleResult($count, $lastActivity, $thresholds, []);
                } catch (\Throwable $e) {
                    Log::channel('dashboard-analysis')->warning("Module 'communication' query failed: {$e->getMessage()}");
                }
            }
        }

        return $this->emptyModuleResult($thresholds);
    }

    private function classifyFiles(): array
    {
        $thresholds = config('dashboard_thresholds.modules.files');

        if (! Schema::hasTable('file_uploads')) {
            return $this->emptyModuleResult($thresholds);
        }

        try {
            // FileUpload has no school_id — count all (conservative)
            $count = DB::table('file_uploads')->count();
            $lastActivity = DB::table('file_uploads')->max('created_at');

            return $this->buildModuleResult($count, $lastActivity, $thresholds, []);
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Module 'files' query failed: {$e->getMessage()}");

            return $this->emptyModuleResult($thresholds);
        }
    }

    private function classifyActivityLog(): array
    {
        $thresholds = config('dashboard_thresholds.modules.activity_log');

        try {
            $count = DB::table('activity_log')
                ->where('school_id', $this->schoolId)
                ->count();

            $lastActivity = DB::table('activity_log')
                ->where('school_id', $this->schoolId)
                ->max('created_at');

            $dailyCounts = $this->dailyCounts('activity_log', 'school_id', $this->schoolId);

            return $this->buildModuleResult($count, $lastActivity, $thresholds, $dailyCounts);
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Module 'activity_log' query failed: {$e->getMessage()}");

            return $this->emptyModuleResult($thresholds);
        }
    }

    /**
     * Compute daily row counts for a table over the last 30 days.
     *
     * @return array<array{date: string, count: int}>
     */
    private function dailyCounts(string $table, string $scopeColumn, int $scopeValue): array
    {
        try {
            $rows = DB::table($table)
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->where($scopeColumn, $scopeValue)
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->groupByRaw('DATE(created_at)')
                ->orderBy('date')
                ->get();

            return $rows->map(fn ($r) => ['date' => $r->date, 'count' => (int) $r->count])->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function buildModuleResult(
        int $count,
        ?string $lastActivityAt,
        array $thresholds,
        array $dailyCounts
    ): array {
        $status = $this->classifyStatus($count, $lastActivityAt, $thresholds);

        return [
            'status' => $status,
            'primary_table_rows' => $count,
            'last_activity_at' => $lastActivityAt,
            'daily_counts_30d' => $dailyCounts,
            'threshold_used' => $thresholds,
        ];
    }

    private function classifyStatus(int $count, ?string $lastActivityAt, array $thresholds): string
    {
        $activeThreshold = $thresholds['active_threshold'];
        $dormantThreshold = $thresholds['dormant_threshold'];
        $windowDays = $thresholds['recency_window_days'];

        if ($count < $dormantThreshold) {
            return 'empty';
        }

        $isRecent = $lastActivityAt !== null
            && Carbon::parse($lastActivityAt)->gte(Carbon::now()->subDays($windowDays));

        if ($count >= $activeThreshold && $isRecent) {
            return 'active';
        }

        return 'dormant';
    }

    private function emptyModuleResult(array $thresholds): array
    {
        return [
            'status' => 'empty',
            'primary_table_rows' => 0,
            'last_activity_at' => null,
            'daily_counts_30d' => [],
            'threshold_used' => $thresholds,
        ];
    }
}
