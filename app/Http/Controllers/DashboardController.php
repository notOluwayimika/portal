<?php

namespace App\Http\Controllers;

use App\Exceptions\Dashboard\PiiDetectedException;
use App\Models\School;
use App\Services\Dashboard\DashboardAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardAnalysisService $analysisService)
    {
    }

    public function show(Request $request)
    {
        $school = $this->resolveSchool($request);

        $analysis = $school
            ? $this->analysisService->load($school)
            : $this->emptyAnalysis();

        $widgets = $school ? $this->selectWidgets($analysis) : [];
        $onboarding = $this->buildOnboardingState($analysis);

        if (auth()->user()->hasRole('guardian')) {
            return redirect('/parent/wards');
        } else if (auth()->user()->hasRole('teacher')) {
            return redirect('/setup/teacher/' . auth()->user()->teacher->uuid);
        }

        return Inertia::render('dashboard', [
            'analysis' => $analysis,
            'widgets' => $widgets,
            'onboarding' => $onboarding,
            'lastRefreshedAt' => $analysis['analyzed_at'] ?? null,
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);

        if (!$school) {
            return response()->json(['error' => 'No school context'], 422);
        }

        try {
            $analysis = $this->analysisService->generate($school);
            return response()->json([
                'success' => true,
                'analyzed_at' => $analysis['analyzed_at'],
            ]);
        } catch (PiiDetectedException $e) {
            return response()->json(['error' => 'Analysis aborted: PII detected'], 500);
        }
    }

    public function onboardingState(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);

        if (!$school) {
            return response()->json(['error' => 'No school context'], 422);
        }

        $analysis = $this->analysisService->load($school);
        return response()->json($this->buildOnboardingState($analysis));
    }

    /**
     * Select widgets to render based on the analysis and widget registry.
     *
     * @return array<array{id: string, component: string, priority: int, dataKey: string}>
     */
    private function selectWidgets(array $analysis): array
    {
        $registry = config('dashboard_widgets');
        $modules = $analysis['modules'] ?? [];
        $dataGaps = $analysis['data_gaps'] ?? [];
        $selected = [];

        foreach ($registry as $widgetId => $config) {
            // data_gaps_panel: always render if gaps exist
            if (($config['always_render_if_gaps_exist'] ?? false) && count($dataGaps) > 0) {
                $selected[] = [
                    'id' => $widgetId,
                    'component' => $config['component'],
                    'priority' => $config['priority'],
                    'dataKey' => $config['data_key'],
                ];
                continue;
            }

            $requiredModule = $config['requires_module'] ?? null;
            if (!$requiredModule) {
                continue;
            }

            $moduleData = $modules[$requiredModule] ?? null;
            if (!$moduleData) {
                continue;
            }

            $moduleStatus = $moduleData['status'] ?? 'empty';
            $requiredStatuses = $config['requires_status'] ?? [];

            if (!in_array($moduleStatus, $requiredStatuses, true)) {
                continue;
            }

            // Check min_data against entity count
            $minData = $config['min_data'] ?? 1;
            if ($moduleData['primary_table_rows'] < $minData) {
                continue;
            }

            $selected[] = [
                'id' => $widgetId,
                'component' => $config['component'],
                'priority' => $config['priority'],
                'dataKey' => $config['data_key'],
            ];
        }

        usort($selected, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $selected;
    }

    private function buildOnboardingState(array $analysis): array
    {
        $entities = $analysis['entities'] ?? [];
        $modules = $analysis['modules'] ?? [];

        $dormantThresholdStudents = config('dashboard_thresholds.modules.students.dormant_threshold', 5);

        $steps = [
            [
                'key' => 'classes',
                'title' => 'Create your first classes',
                'description' => 'Define grade levels and sections for your school',
                'is_complete' => ($entities['curricula']['total'] ?? 0) >= 1,
                'action_label' => 'Set up classes',
                'action_href' => '/setup',
            ],
            [
                'key' => 'students',
                'title' => 'Add your students',
                'description' => 'Register students manually or via bulk import',
                'is_complete' => ($entities['students']['active'] ?? 0) >= $dormantThresholdStudents,
                'action_label' => 'Add students',
                'action_href' => '/students',
            ],
            [
                'key' => 'curriculum',
                'title' => 'Configure your curriculum',
                'description' => 'Set up subjects and grade-level mappings',
                'is_complete' => ($entities['curriculum_subjects']['total'] ?? 0) >= 1,
                'action_label' => 'Configure curriculum',
                'action_href' => '/setup',
            ],
            [
                'key' => 'modules',
                'title' => 'Enable additional modules',
                'description' => 'Finance, attendance, and communication',
                'is_complete' => ($analysis['active_modules_count'] ?? 0) >= 2,
                'action_label' => 'Configure modules',
                'action_href' => '/setup',
            ],
        ];

        $completed = collect($steps)->filter(fn($s) => $s['is_complete'])->count();

        return [
            'is_onboarding' => $analysis['is_onboarding_state'] ?? true,
            'steps' => $steps,
            'completed_count' => $completed,
            'total_count' => count($steps),
        ];
    }

    private function resolveSchool(Request $request): ?School
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        $schoolId = session('school_id') ?? $user->school_id;
        if (!$schoolId) {
            return null;
        }

        return School::find($schoolId);
    }

    private function emptyAnalysis(): array
    {
        return [
            'school_id' => null,
            'school_name' => null,
            'analyzed_at' => now()->toIso8601String(),
            'active_modules_count' => 0,
            'is_onboarding_state' => true,
            'entities' => [],
            'modules' => [],
            'data_gaps' => [],
            'distributions' => [],
            'recent_activities' => [],
            'richness' => [],
        ];
    }
}
