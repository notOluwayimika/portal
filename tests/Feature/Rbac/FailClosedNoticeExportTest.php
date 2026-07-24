<?php

use App\Exceptions\MissingSchoolContextException;
use App\Jobs\ExportActivityLogJob;
use App\Models\Export;
use App\Models\Notice;
use App\Models\User;
use App\Notifications\ActivityLogExportReadyNotification;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * B-wave 1 — fail-closed for Notice and Export (low-fan-in, non-Finance).
 * Slice-(ii) audit found ZERO unscoped paths for both (targeted greps for
 * exists:/joins/DB::table + jobs/commands/observers — absence by grep, not
 * read-through). Both directions watched, per model: listed => a context-less
 * read THROWS; unlisted => it reads. The env-list entry is what changes
 * behavior, proven by flipping it.
 */
function fc_actorWithoutSchool(): User
{
    // The fail-closed throw is auth-gated (Debt 7, open): it fires for an
    // AUTHENTICATED principal with no resolvable School. That is the real
    // gap: a logged-in user whose context resolution failed.
    return User::factory()->create(['school_id' => null]);
}

it('Notice: listed => context-less read throws; unlisted => it reads (both directions watched)', function () {
    $this->actingAs(fc_actorWithoutSchool());

    config(['rbac.fail_closed_models' => [Notice::class]]);
    expect(fn () => Notice::all())->toThrow(MissingSchoolContextException::class);

    config(['rbac.fail_closed_models' => []]);
    expect(Notice::all())->toBeEmpty(); // reads (unscoped legacy fail-open), no throw
});

it('Export: listed => context-less read throws; unlisted => it reads', function () {
    $this->actingAs(fc_actorWithoutSchool());

    config(['rbac.fail_closed_models' => [Export::class]]);
    expect(fn () => Export::first())->toThrow(MissingSchoolContextException::class);

    config(['rbac.fail_closed_models' => []]);
    expect(Export::first())->toBeNull();
});

it('Export: the REAL job lifecycle completes green with the model fail-closed (the conclusion, not the mechanism)', function () {
    // The "no pre-flip fix needed" conclusion rests on reasoning about
    // Eloquent internals (create runs inside SchoolAware/runFor; instance
    // update() builds its key query via newModelQuery, which skips global
    // scopes). This WATCHES the create→update→notify lifecycle survive the
    // flip instead of trusting that reasoning — the exact spot a confident
    // but wrong framework read would hide.
    config(['rbac.fail_closed_models' => [Export::class]]);
    Notification::fake();
    Storage::fake('local');

    $school = al_makeSchool();
    $user = al_makeUser($school->id);

    // dispatchSync runs through the bus, so the SchoolAware job middleware
    // actually wraps handle() — the REAL lifecycle, not a bare method call.
    ExportActivityLogJob::dispatchSync(userId: $user->id, schoolId: $school->id, filters: []);

    $export = ActiveSchool::runFor($school->id, fn () => Export::first());
    expect($export)->not->toBeNull()
        ->and($export->file_path)->not->toBeNull(); // update() landed through the flip

    Notification::assertSentTo($user, ActivityLogExportReadyNotification::class);
});
