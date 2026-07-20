<?php

namespace App\Http\Controllers\ActivityLog;

use App\Http\Controllers\Controller;
use App\Models\SavedActivityFilter;
use App\Services\ActivityLog\ActivityLogQueryService;
use App\Support\Authz;
use Illuminate\Http\Request;

class SavedActivityFilterController extends Controller
{
    public function __construct(private readonly ActivityLogQueryService $queries) {}

    /** Built-in presets surfaced to every user (not persisted). */
    private function quickPresets(int $userId): array
    {
        return [
            ['name' => "Today's logins", 'filters' => ['event' => ['login'], 'log_name' => ['auth'], 'date_from' => now()->toDateString()]],
            ['name' => "This week's deletions", 'filters' => ['event' => ['deleted'], 'date_from' => now()->startOfWeek()->toDateString()]],
            ['name' => 'Critical events', 'filters' => ['severity' => ['critical']]],
            ['name' => 'My activity', 'filters' => ['causer_id' => [$userId]]],
        ];
    }

    public function index(Request $request)
    {
        Authz::abilityCheck(request()->user(), 'activity_log.view', 'SavedActivityFilterController@index');

        return response()->json([
            'data' => [
                'saved' => SavedActivityFilter::where('user_id', $request->user()->id)
                    ->where('school_id', $this->queries->currentSchoolId($request->user()))
                    ->latest()->get(),
                'quick' => $this->quickPresets($request->user()->id),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Authz::abilityCheck(request()->user(), 'activity_log.view', 'SavedActivityFilterController@store');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'filters' => ['required', 'array'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $filter = SavedActivityFilter::create([
            'user_id' => $request->user()->id,
            'school_id' => $this->queries->currentSchoolId($request->user()),
            'name' => $data['name'],
            'filters' => $data['filters'],
            'is_default' => (bool) ($data['is_default'] ?? false),
        ]);

        return response()->json(['data' => $filter], 201);
    }

    public function destroy(Request $request, SavedActivityFilter $savedActivityFilter)
    {
        // abort_unless(
        //     $request->user()?->can('activity_log.view')
        //     && $savedActivityFilter->user_id === $request->user()->id,
        //     403
        // );

        $savedActivityFilter->delete();

        return response()->noContent();
    }
}
