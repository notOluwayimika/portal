<?php

namespace App\Services\Dashboard;

use App\Models\School;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardAnalysisService
{
    private const CACHE_TTL_MINUTES = 10;

    private const STORAGE_DIR = 'dashboard-analysis';

    public function __construct(
        private readonly PiiSanitizationService $pii,
    ) {}

    /**
     * Load analysis for a school from cache or disk. Regenerates if stale.
     */
    public function load(School $school, bool $force = false): array
    {
        $cacheKey = "dashboard_analysis_{$school->id}";

        if (! $force && Cache::has($cacheKey)) {
            $path = Cache::get($cacheKey);
            if ($path && file_exists($path)) {
                $json = file_get_contents($path);
                $data = json_decode($json, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        return $this->generate($school);
    }

    /**
     * Run a fresh analysis for the given school and persist it.
     */
    public function generate(School $school): array
    {
        $schoolId = (int) $school->id;
        $now = Carbon::now();

        $classifier = new ModuleClassificationService($schoolId);
        $gapService = new DataGapsService($schoolId);

        $modules = $classifier->classifyAll();
        $entities = $this->collectEntityVolumes($schoolId);
        $dataGaps = $gapService->detect();
        $distributions = $this->collectDistributions($schoolId);
        $recentActivities = $this->collectRecentActivities($schoolId);

        $activeModulesCount = collect($modules)
            ->filter(fn ($m) => $m['status'] === 'active')
            ->count();

        // Onboarding ends when the school has completed basic setup:
        // classes exist, enough students are enrolled, and curriculum subjects are configured.
        // This is independent of whether modules have crossed their "active" thresholds,
        // which can take time even for a fully set-up school.
        $dormantStudentThreshold = config('dashboard_thresholds.modules.students.dormant_threshold', 5);
        $hasClasses = ($entities['curricula']['total'] ?? 0) >= 1;
        $hasStudents = ($entities['students']['active'] ?? 0) >= $dormantStudentThreshold;
        $hasCurriculum = ($entities['curriculum_subjects']['total'] ?? 0) >= 1;
        $isOnboardingState = ! ($hasClasses && $hasStudents && $hasCurriculum);

        $analysis = [
            'school_id' => $school->uuid ?? (string) $school->id,
            'school_name' => $school->name,
            'analyzed_at' => $now->toIso8601String(),
            'active_modules_count' => $activeModulesCount,
            'is_onboarding_state' => $isOnboardingState,
            'entities' => $entities,
            'modules' => $modules,
            'data_gaps' => $dataGaps,
            'distributions' => $distributions,
            'recent_activities' => $recentActivities,
            'richness' => [],
        ];

        $this->pii->scan($analysis);

        $this->persist($school, $analysis);

        return $analysis;
    }

    /**
     * Collect entity volumes (counts + recency) for all major tables.
     */
    private function collectEntityVolumes(int $schoolId): array
    {
        $entities = [];

        $scoped = [
            'students' => ['table' => 'students', 'col' => 'school_id', 'soft_delete' => true],
            'guardians' => ['table' => 'guardians', 'col' => 'school_id', 'soft_delete' => false],
            'teachers' => ['table' => 'teachers', 'col' => 'school_id', 'soft_delete' => true],
        ];

        foreach ($scoped as $key => $spec) {
            try {
                $q = DB::table($spec['table'])->where($spec['col'], $schoolId);
                $total = (clone $q)->count();
                $softDeleted = $spec['soft_delete'] ? (clone $q)->whereNotNull('deleted_at')->count() : 0;
                $active = $spec['soft_delete'] ? (clone $q)->whereNull('deleted_at')->count() : $total;

                $entities[$key] = [
                    'total' => $total,
                    'active' => $active,
                    'soft_deleted' => $softDeleted,
                    'earliest_created_at' => (clone $q)->whereNull($spec['soft_delete'] ? 'deleted_at' : null)->min('created_at'),
                    'latest_created_at' => (clone $q)->whereNull($spec['soft_delete'] ? 'deleted_at' : null)->max('created_at'),
                    'created_last_7d' => (clone $q)->where('created_at', '>=', now()->subDays(7))->count(),
                    'created_last_30d' => (clone $q)->where('created_at', '>=', now()->subDays(30))->count(),
                    'created_last_90d' => (clone $q)->where('created_at', '>=', now()->subDays(90))->count(),
                ];
            } catch (\Throwable $e) {
                Log::channel('dashboard-analysis')->warning("Entity volume for '{$key}' failed: {$e->getMessage()}");
                $entities[$key] = $this->emptyEntityVolume();
            }
        }

        // Joined tables (no direct school_id)
        $joinedEntities = [
            'curricula' => ['table' => 'curricula', 'col' => 'school_id'],
            'curriculum_subjects' => [
                'table' => 'curriculum_subjects',
                'join' => ['curricula', 'curriculum_subjects.curriculum_id', '=', 'curricula.id'],
                'col' => 'curricula.school_id',
            ],
            'student_curricula' => [
                'table' => 'student_curricula',
                'join' => ['students', 'student_curricula.student_id', '=', 'students.id'],
                'col' => 'students.school_id',
            ],
            'scores' => [
                'table' => 'scores',
                'join' => ['students', 'scores.student_id', '=', 'students.id'],
                'col' => 'students.school_id',
            ],
            'student_results' => [
                'table' => 'student_results',
                'join' => ['curriculum_subjects', 'student_results.curriculum_subject_id', '=', 'curriculum_subjects.id'],
                'join2' => ['curricula', 'curriculum_subjects.curriculum_id', '=', 'curricula.id'],
                'col' => 'curricula.school_id',
            ],
        ];

        foreach ($joinedEntities as $key => $spec) {
            try {
                $q = DB::table($spec['table']);
                if (isset($spec['join'])) {
                    $q->join($spec['join'][0], $spec['join'][1], $spec['join'][2], $spec['join'][3]);
                }
                if (isset($spec['join2'])) {
                    $q->join($spec['join2'][0], $spec['join2'][1], $spec['join2'][2], $spec['join2'][3]);
                }
                $q->where($spec['col'], $schoolId);

                $entities[$key] = [
                    'total' => (clone $q)->count(),
                    'active' => (clone $q)->count(),
                    'soft_deleted' => 0,
                    'earliest_created_at' => (clone $q)->min("{$spec['table']}.created_at"),
                    'latest_created_at' => (clone $q)->max("{$spec['table']}.created_at"),
                    'created_last_7d' => (clone $q)->where("{$spec['table']}.created_at", '>=', now()->subDays(7))->count(),
                    'created_last_30d' => (clone $q)->where("{$spec['table']}.created_at", '>=', now()->subDays(30))->count(),
                    'created_last_90d' => (clone $q)->where("{$spec['table']}.created_at", '>=', now()->subDays(90))->count(),
                ];
            } catch (\Throwable $e) {
                Log::channel('dashboard-analysis')->warning("Entity volume for '{$key}' failed: {$e->getMessage()}");
                $entities[$key] = $this->emptyEntityVolume();
            }
        }

        return $entities;
    }

    /**
     * Collect distribution breakdowns (students by class level, score entry progress).
     */
    private function collectDistributions(int $schoolId): array
    {
        $distributions = [];

        try {
            $byClassLevel = DB::table('students')
                ->join('student_curricula', 'students.id', '=', 'student_curricula.student_id')
                ->join('curricula', 'student_curricula.curriculum_id', '=', 'curricula.id')
                ->join('class_level_arms', 'curricula.class_level_arm_id', '=', 'class_level_arms.id')
                ->join('class_levels', 'class_level_arms.class_level_id', '=', 'class_levels.id')
                ->where('students.school_id', $schoolId)
                ->whereNull('students.deleted_at')
                ->where('student_curricula.status', 'ACTIVE')
                ->selectRaw('class_levels.name as label, COUNT(DISTINCT students.id) as count')
                ->groupBy('class_levels.id', 'class_levels.name')
                ->orderByDesc('count')
                ->get();

            $distributions['students_by_class_level'] = $byClassLevel
                ->map(fn ($r) => ['name' => $r->label, 'count' => (int) $r->count])
                ->toArray();
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Distribution 'students_by_class_level' failed: {$e->getMessage()}");
            $distributions['students_by_class_level'] = [];
        }

        try {
            $scoreEntry = DB::table('class_levels')
                ->where('class_levels.school_id', $schoolId)

                ->join('class_level_arms', 'class_level_arms.class_level_id', '=', 'class_levels.id')
                ->join('curricula', 'curricula.class_level_arm_id', '=', 'class_level_arms.id')
                ->where('curricula.status', 'active')
                ->join('curriculum_subjects', 'curriculum_subjects.curriculum_id', '=', 'curricula.id')

                ->leftJoin('marking_components as local_components', function ($join) {
                    $join->on('local_components.curriculum_subject_id', '=', 'curriculum_subjects.id')
                        ->whereNull('curricula.marking_scheme_id');
                })
                ->leftJoin('marking_components as scheme_components', function ($join) {
                    $join->on('scheme_components.marking_scheme_id', '=', 'curricula.marking_scheme_id')
                        ->whereNull('scheme_components.curriculum_subject_id');
                })

                // subject enrollment
                ->leftJoin('student_subjects', 'student_subjects.curriculum_subject_id', '=', 'curriculum_subjects.id')

                // get actual student
                ->leftJoin('student_curricula', 'student_curricula.id', '=', 'student_subjects.student_curriculum_id')

                ->leftJoin('scores', function ($join) {
                    $join->on('scores.student_id', '=', 'student_curricula.student_id')
                        ->on('scores.curriculum_subject_id', '=', 'curriculum_subjects.id')
                        ->whereRaw('scores.marking_component_id = COALESCE(scheme_components.id, local_components.id)');
                })

                ->where('curricula.school_id', $schoolId)
                ->where('curriculum_subjects.active', true)
                ->whereNull('curriculum_subjects.archived_at')

                ->selectRaw('
                    class_levels.name as label,

                    COUNT(DISTINCT CONCAT(
                        student_curricula.student_id,
                        "-", curriculum_subjects.id,
                        "-", COALESCE(scheme_components.id, local_components.id)
                    )) as total_slots,

                    COUNT(DISTINCT scores.id) as filled_slots
                ')

                ->groupBy('class_levels.id', 'class_levels.name')
                ->get();

            $distributions['score_entry_by_section'] = $scoreEntry->map(function ($r) {
                $pct = $r->total_slots > 0
                    ? (int) round(($r->filled_slots / $r->total_slots) * 100)
                    : 0;

                return ['label' => $r->label, 'pct' => $pct, 'filled' => (int) $r->filled_slots, 'total' => (int) $r->total_slots];
            })->toArray();
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Distribution 'score_entry_by_section' failed: {$e->getMessage()}");
            $distributions['score_entry_by_section'] = [];
        }

        return $distributions;
    }

    /**
     * Collect the last 10 activity log entries for the school (description + timestamp only).
     */
    private function collectRecentActivities(int $schoolId): array
    {
        try {
            return DB::table('activity_log')
                ->where('school_id', $schoolId)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['description', 'created_at', 'log_name'])
                ->map(fn ($r) => [
                    'description' => $r->description,
                    'log_name' => $r->log_name,
                    'created_at' => $r->created_at,
                ])
                ->toArray();
        } catch (\Throwable $e) {
            Log::channel('dashboard-analysis')->warning("Recent activities query failed: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Write analysis JSON to disk and update cache.
     */
    private function persist(School $school, array $analysis): void
    {
        $filename = self::STORAGE_DIR."/{$school->id}-".Carbon::now()->format('Ymd-His').'.json';
        $fullPath = storage_path("app/{$filename}");

        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        file_put_contents($fullPath, json_encode($analysis, JSON_PRETTY_PRINT));

        Cache::put(
            "dashboard_analysis_{$school->id}",
            $fullPath,
            now()->addMinutes(self::CACHE_TTL_MINUTES)
        );

        Log::channel('dashboard-analysis')->info("Analysis written for school {$school->id}: {$fullPath}");
    }

    private function emptyEntityVolume(): array
    {
        return [
            'total' => 0,
            'active' => 0,
            'soft_deleted' => 0,
            'earliest_created_at' => null,
            'latest_created_at' => null,
            'created_last_7d' => 0,
            'created_last_30d' => 0,
            'created_last_90d' => 0,
        ];
    }
}
