<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Dashboard\PiiDetectedException;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Dashboard\DashboardAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardAnalysisService $service) {}

    /**
     * GET /api/dashboard/analysis
     *
     * Returns the latest cached analysis for the authenticated school.
     * Regenerates automatically if the cache is older than 10 minutes.
     */
    public function analysis(Request $request): JsonResponse
    {
        $school = $this->school($request);

        if (!$school) {
            return response()->json(['message' => 'No school context for this token.'], 422);
        }

        $analysis = $this->service->load($school);

        return response()->json($analysis);
    }

    /**
     * POST /api/dashboard/analysis/refresh
     *
     * Forces a fresh analysis, bypassing the cache.
     * Rate-limited to 1 request per minute per user.
     */
    public function refresh(Request $request): JsonResponse
    {
        $school = $this->school($request);

        if (!$school) {
            return response()->json(['message' => 'No school context for this token.'], 422);
        }

        try {
            $analysis = $this->service->generate($school);

            return response()->json([
                'message' => 'Analysis refreshed successfully.',
                'analyzed_at' => $analysis['analyzed_at'],
                'active_modules_count' => $analysis['active_modules_count'],
                'is_onboarding_state' => $analysis['is_onboarding_state'],
            ]);
        } catch (PiiDetectedException $e) {
            return response()->json(['message' => 'Analysis aborted: potential PII detected in output.'], 500);
        }
    }

    /**
     * GET /api/dashboard/widgets
     *
     * Returns the ordered list of widgets selected for this school,
     * based on the current analysis. Each entry includes the widget ID,
     * component name, priority, and the data key within the analysis.
     */
    public function widgets(Request $request): JsonResponse
    {
        $school = $this->school($request);

        if (!$school) {
            return response()->json(['message' => 'No school context for this token.'], 422);
        }

        $analysis = $this->service->load($school);
        $widgets = $this->selectWidgets($analysis);

        return response()->json([
            'school_id' => $analysis['school_id'],
            'is_onboarding_state' => $analysis['is_onboarding_state'],
            'widgets' => $widgets,
        ]);
    }

    /**
     * GET /api/dashboard/widgets/{widget}
     *
     * Returns the data payload for a single named widget.
     * The payload is extracted from the analysis using the widget's data_key.
     *
     * Example widget IDs: students_kpi, population_distribution, recent_activity_feed
     */
    public function widget(Request $request, string $widgetId): JsonResponse
    {
        $school = $this->school($request);

        if (!$school) {
            return response()->json(['message' => 'No school context for this token.'], 422);
        }

        $registry = config('dashboard_widgets');

        if (!array_key_exists($widgetId, $registry)) {
            return response()->json(['message' => "Unknown widget: {$widgetId}"], 404);
        }

        $analysis = $this->service->load($school);
        $config = $registry[$widgetId];

        // Check whether this widget is eligible for the current school
        $selected = $this->selectWidgets($analysis);
        $isSelected = collect($selected)->contains('id', $widgetId);

        if (!$isSelected) {
            return response()->json([
                'widget_id' => $widgetId,
                'available' => false,
                'reason' => 'Required module is inactive or below minimum data threshold.',
            ]);
        }

        $data = $this->resolveWidgetData($widgetId, $config['data_key'], $analysis);

        return response()->json([
            'widget_id' => $widgetId,
            'available' => true,
            'component' => $config['component'],
            'data' => $data,
            'analyzed_at' => $analysis['analyzed_at'],
        ]);
    }

    /**
     * GET /api/dashboard/onboarding
     *
     * Returns the onboarding state: per-step completion status and overall progress.
     */
    public function onboarding(Request $request): JsonResponse
    {
        $school = $this->school($request);

        if (!$school) {
            return response()->json(['message' => 'No school context for this token.'], 422);
        }

        $analysis = $this->service->load($school);

        return response()->json($this->buildOnboardingState($analysis));
    }

    // -------------------------------------------------------------------------

    private function school(Request $request): ?School
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        $schoolId = \App\Support\ActiveSchool::id();
        if (!$schoolId) {
            return null;
        }

        return School::find($schoolId);
    }

    /**
     * @return array<array{id: string, component: string, priority: int, dataKey: string}>
     */
    private function selectWidgets(array $analysis): array
    {
        $registry = config('dashboard_widgets');
        $modules = $analysis['modules'] ?? [];
        $dataGaps = $analysis['data_gaps'] ?? [];
        $selected = [];

        foreach ($registry as $widgetId => $config) {
            if (($config['always_render_if_gaps_exist'] ?? false) && count($dataGaps) > 0) {
                $selected[] = ['id' => $widgetId, 'component' => $config['component'], 'priority' => $config['priority'], 'dataKey' => $config['data_key']];
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

            if (!in_array($moduleData['status'] ?? 'empty', $config['requires_status'] ?? [], true)) {
                continue;
            }

            if ($moduleData['primary_table_rows'] < ($config['min_data'] ?? 1)) {
                continue;
            }

            $selected[] = ['id' => $widgetId, 'component' => $config['component'], 'priority' => $config['priority'], 'dataKey' => $config['data_key']];
        }

        usort($selected, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $selected;
    }

    private function resolveWidgetData(string $widgetId, string $dataKey, array $analysis): mixed
    {
        // Navigate dotted key paths into the analysis array
        $parts = explode('.', $dataKey);
        $value = $analysis;

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    private function buildOnboardingState(array $analysis): array
    {
        $entities = $analysis['entities'] ?? [];
        $dormantThreshold = config('dashboard_thresholds.modules.students.dormant_threshold', 5);

        $steps = [
            [
                'key' => 'classes',
                'title' => 'Create your first classes',
                'description' => 'Define grade levels and sections for your school',
                'is_complete' => ($entities['curricula']['total'] ?? 0) >= 1,
                'action_href' => '/setup',
            ],
            [
                'key' => 'students',
                'title' => 'Add your students',
                'description' => 'Register students manually or via bulk import',
                'is_complete' => ($entities['students']['active'] ?? 0) >= $dormantThreshold,
                'action_href' => '/students',
            ],
            [
                'key' => 'curriculum',
                'title' => 'Configure your curriculum',
                'description' => 'Set up subjects and grade-level mappings',
                'is_complete' => ($entities['curriculum_subjects']['total'] ?? 0) >= 1,
                'action_href' => '/setup',
            ],
            [
                'key' => 'modules',
                'title' => 'Enable additional modules',
                'description' => 'Finance, attendance, and communication',
                'is_complete' => ($analysis['active_modules_count'] ?? 0) >= 2,
                'action_href' => '/setup',
            ],
        ];

        $completed = collect($steps)->filter(fn($s) => $s['is_complete'])->count();

        return [
            'school_id' => $analysis['school_id'],
            'is_onboarding' => $analysis['is_onboarding_state'],
            'steps' => $steps,
            'completed_count' => $completed,
            'total_count' => count($steps),
            'analyzed_at' => $analysis['analyzed_at'],
        ];
    }
}
