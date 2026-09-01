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
        // Restored 2026-07-20 (observe mode, ADR 0043). index() and store() above were
        // restored by the S5 sweep; destroy() was missed, so anyone could delete
        // anyone else's saved filter.
        Authz::abilityCheck($request->user(), 'activity_log.view', 'SavedActivityFilterController@destroy');

        // ISOLATION, ENFORCED UNCONDITIONALLY. SavedActivityFilter carries school_id but
        // NOT BelongsToSchool, so there is no SchoolScope on it and the route-model binding
        // is a bare sequential integer id: a row from another school resolves normally.
        // index() and store() above each narrow by currentSchoolId explicitly; destroy()
        // was the one that did not, which left any holder of activity_log.view — in ANY
        // school — able to delete any row in the table by guessing an id.
        //
        // 404, not 403: the house convention for a row the caller has no business seeing
        // (StudentSubjectController@drop/@restore, StudentCurriculumController@unenroll,
        // GuardianImportController@authorizeSchool all pass 404 for exactly this shape).
        // A 403 would confirm the row exists.
        //
        // Fails closed on a missing context: a null currentSchoolId casts to 0 and matches
        // no school_id.
        abort_if(
            (int) $savedActivityFilter->school_id !== (int) $this->queries->currentSchoolId($request->user()),
            404,
        );

        // OWNERSHIP, ENFORCED UNCONDITIONALLY — a saved filter is the user's own, and
        // holding activity_log.view is not a licence to delete a colleague's. A bare abort
        // rather than Authz::ensure because it must hold whatever `authz.enforce` is set
        // to; observe mode records the would-be denial and performs the delete anyway.
        // 403 and not 404: by this line the row is in the caller's own school, so its
        // existence is not the secret — the authority to delete it is.
        abort_unless(
            (int) $savedActivityFilter->user_id === (int) $request->user()?->id,
            403,
            // Messaged for the same reason the submit guard above is: a bare abort()
            // renders {"message": ""} and a client reading `?? 'default'` shows nothing.
            // The 404 above stays bare — the house convention for a row whose existence
            // is not being confirmed, where any message is a message about a row the
            // caller must not learn exists.
            'This saved filter belongs to another user.',
        );

        $savedActivityFilter->delete();

        return response()->noContent();
    }
}
