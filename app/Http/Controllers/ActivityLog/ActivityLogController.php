<?php

namespace App\Http\Controllers\ActivityLog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityDetailResource;
use App\Http\Resources\ActivityResource;
use App\Jobs\ExportActivityLogJob;
use App\Models\Export;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogQueryService;
use App\Services\ActivityLog\ActivitySeverityService;
use App\Support\ActiveSchool;
use App\Support\Authz;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only activity log API. This module never emits activities itself
 * (it only reads Activity), so there is no logging loop to disable.
 */
class ActivityLogController extends Controller
{
    public function __construct(private readonly ActivityLogQueryService $queries) {}

    private function filterRules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'causer_id' => ['nullable'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable'],
            'event' => ['nullable'],
            'log_name' => ['nullable'],
            'batch_uuid' => ['nullable', 'string', 'max:64'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'severity' => ['nullable'],
            'include_system' => ['nullable', 'boolean'],
        ];
    }

    /** GET /api/activity-logs */
    public function index(Request $request)
    {
        Authz::abilityCheck(request()->user(), 'activity_log.view', 'ActivityLogController@index');
        $data = $request->validate($this->filterRules());

        $includeSystem = (bool) ($data['include_system'] ?? false);
        $perPage = (int) ($data['per_page'] ?? 25);

        $query = $this->queries->baseQuery($request->user(), $includeSystem);
        $this->queries->applyFilters($query, $data);

        $paginated = $query->orderByDesc('activity_log.created_at')
            ->orderByDesc('activity_log.id')
            ->paginate($perPage);

        return response()->json([
            'data' => ActivityResource::collection($paginated->items()),
            'pagination' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'prev_page_url' => $paginated->previousPageUrl(),
                'next_page_url' => $paginated->nextPageUrl(),
            ],
        ]);
    }

    /** GET /api/activity-logs/{id} */
    public function show(Request $request, int $id)
    {
        Authz::abilityCheck(request()->user(), 'activity_log.view', 'ActivityLogController@show');

        $activity = $this->queries->baseQuery($request->user(), true)
            ->where('activity_log.id', $id)
            ->firstOrFail();

        $payload = (new ActivityDetailResource($activity))->toArray($request);

        if (! empty($activity->batch_uuid)) {
            $related = $this->queries->baseQuery($request->user(), true)
                ->where('batch_uuid', $activity->batch_uuid)
                ->where('activity_log.id', '!=', $activity->id)
                ->orderBy('activity_log.id')
                ->limit(5)
                ->get();

            $payload['batch'] = [
                'uuid' => $activity->batch_uuid,
                'count' => $this->queries->baseQuery($request->user(), true)
                    ->where('batch_uuid', $activity->batch_uuid)->count(),
                'related' => ActivityResource::collection($related),
            ];
        }

        return response()->json(['data' => $payload]);
    }

    /** GET /api/activity-logs/filters/options — cached 5 min per school. */
    public function filterOptions(Request $request)
    {
        Authz::abilityCheck(request()->user(), 'activity_log.view', 'ActivityLogController@filterOptions');
        $user = $request->user();
        $schoolId = $this->queries->currentSchoolId($user);

        return response()->json([
            'data' => Cache::remember(
                "activity-log:options:{$schoolId}:{$user->id}",
                now()->addMinutes(5),
                function () use ($user) {
                    $base = fn () => $this->queries->baseQuery($user, true);

                    $causers = $base()
                        ->where('causer_type', User::class)
                        ->where('activity_log.created_at', '>=', now()->subDays(90))
                        ->whereNotNull('causer_id')
                        ->with('causer:id,first_name,last_name,avatar')
                        ->get()
                        ->pluck('causer')
                        ->filter()
                        ->unique('id')
                        ->map(fn ($c) => [
                            'id' => $c->getKey(),
                            'name' => $c->full_name ?? $c->name,
                            'avatar' => $c->avatar ?? null,
                        ])
                        ->values();

                    return [
                        'causers' => $causers,
                        'subject_types' => $base()->whereNotNull('subject_type')
                            ->distinct()->pluck('subject_type')
                            ->map(fn ($t) => ['value' => $t, 'label' => class_basename($t)])
                            ->values(),
                        'events' => $base()->whereNotNull('event')
                            ->distinct()->orderBy('event')->pluck('event')->values(),
                        'log_names' => $base()->whereNotNull('log_name')
                            ->distinct()->orderBy('log_name')->pluck('log_name')->values(),
                    ];
                }
            ),
        ]);
    }

    /** GET /api/activity-logs/stats — cached 1 min per school per user. */
    public function stats(Request $request)
    {
        Authz::abilityCheck(request()->user(), 'activity_log.view', 'ActivityLogController@stats');
        $user = $request->user();
        $schoolId = $this->queries->currentSchoolId($user);

        return response()->json([
            'data' => Cache::remember(
                "activity-log:stats:{$schoolId}:{$user->id}",
                now()->addMinute(),
                function () use ($user) {
                    $severity = ActivitySeverityService::make();

                    $count = fn ($from) => $this->queries->baseQuery($user, true)
                        ->where('activity_log.created_at', '>=', $from)->count();

                    $topCausers = $this->queries->baseQuery($user, true)
                        ->where('causer_type', User::class)
                        ->where('activity_log.created_at', '>=', now()->subDays(7))
                        ->whereNotNull('causer_id')
                        ->with('causer:id,first_name,last_name,avatar')
                        ->get()
                        ->groupBy('causer_id')
                        ->map(fn ($rows) => [
                            'id' => $rows->first()->causer?->getKey(),
                            'name' => $rows->first()->causer?->full_name,
                            'avatar' => $rows->first()->causer?->avatar,
                            'count' => $rows->count(),
                        ])
                        ->sortByDesc('count')->take(5)->values();

                    $recent = $this->queries->baseQuery($user, true)
                        ->where('activity_log.created_at', '>=', now()->subDays(7))
                        ->get(['log_name', 'event', 'created_at']);

                    $bySeverity = $recent
                        ->groupBy(fn ($a) => $severity->for($a->log_name, $a->event))
                        ->map->count();

                    return [
                        'events_today' => $count(now()->startOfDay()),
                        'events_this_week' => $count(now()->startOfWeek()),
                        'events_this_month' => $count(now()->startOfMonth()),
                        'active_users_24h' => $this->queries->baseQuery($user, true)
                            ->where('causer_type', User::class)
                            ->where('activity_log.created_at', '>=', now()->subDay())
                            ->distinct()->count('causer_id'),
                        'critical_7d' => $bySeverity['critical'] ?? 0,
                        'failed_logins_24h' => $this->queries->baseQuery($user, true)
                            ->where('log_name', 'auth')
                            ->where('event', 'like', '%login_failed%')
                            ->where('activity_log.created_at', '>=', now()->subDay())->count(),
                        'top_causers' => $topCausers,
                        'by_event' => $recent->groupBy('event')->map->count(),
                        'by_severity' => $bySeverity,
                        'heatmap' => $recent
                            ->groupBy(fn ($a) => $a->created_at->format('Y-m-d H'))
                            ->map->count(),
                    ];
                }
            ),
        ]);
    }

    /** GET /api/activity-logs/export — sync ≤1000 rows, queued otherwise. */
    public function export(Request $request)
    {
        Authz::abilityCheck(request()->user(), 'activity_log.export', 'ActivityLogController@export');
        $data = $request->validate($this->filterRules());

        $query = $this->queries->baseQuery($request->user(), (bool) ($data['include_system'] ?? false));
        $this->queries->applyFilters($query, $data);
        $total = (clone $query)->count();

        if ($total > 1000) {
            $schoolId = ActiveSchool::id();
            abort_unless($schoolId, 403, 'No active school selected.');
            ExportActivityLogJob::dispatch($request->user()->id, $schoolId, $data);

            return response()->json([
                'queued' => true,
                'message' => "Export of {$total} rows queued. You'll be notified when it's ready.",
            ], 202);
        }

        $rows = $query->orderByDesc('activity_log.created_at')->get();

        $filename = 'activity-log-'.now()->format('Ymd_His').'.csv';

        return new StreamedResponse(function () use ($rows, $request, $data, $total) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['# Activity Log Export']);
            fputcsv($out, ['# Exported by', $request->user()->full_name]);
            fputcsv($out, ['# Date range', ($data['date_from'] ?? 'any').' to '.($data['date_to'] ?? 'any')]);
            fputcsv($out, ['# Total rows', $total]);
            fputcsv($out, []);
            fputcsv($out, ['ID', 'Date', 'Log', 'Event', 'Severity', 'Causer', 'Subject', 'Description']);

            $severity = ActivitySeverityService::make();
            foreach ($rows as $a) {
                fputcsv($out, [
                    $a->id,
                    $a->created_at?->toIso8601String(),
                    $a->log_name,
                    $a->event,
                    $severity->for($a->log_name, $a->event),
                    $a->causer?->full_name ?? ($a->causer_id ? "Deleted #{$a->causer_id}" : 'System'),
                    $a->subject_type ? class_basename($a->subject_type)." #{$a->subject_id}" : '',
                    $a->description,
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * GET /api/activity-logs/exports/{export} — async export download.
     *
     * Served by DB id (the export uuid), never by a client-supplied filename.
     * The Export is School-scoped (BelongsToSchool), so a cross-School id does
     * not resolve (404); ownership and permission are enforced explicitly.
     */
    public function downloadExport(Request $request, Export $export)
    {
        // Record-level authorization lives in ExportPolicy (permission + owner);
        // super_admin is granted by Gate::before. Expiry/existence are state
        // checks, not authorization.
        $this->authorize('download', $export);

        abort_if($export->isExpired(), 410, 'Export expired');
        abort_unless(Storage::disk($export->disk)->exists($export->file_path), 404);

        return Storage::disk($export->disk)->download($export->file_path, $export->file_name);
    }
}
