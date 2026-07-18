<?php

use App\Exceptions\AuditLogImmutableException;
use App\Models\Activity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * 1.4c — the audit log (activity_log) is append-only and permanent (§15C). MySQL.
 * Three layers: the model updating/deleting guards, the BEFORE UPDATE/DELETE
 * database triggers (for raw/mass writes), and a disabled activitylog:clean.
 */
uses(RefreshDatabase::class);

function makeActivityRow(): Activity
{
    activity('audit-test')->log('a recorded event');

    return Activity::query()->latest('id')->firstOrFail();
}

// ── Layer 1: model guard ───────────────────────────────────────────────────────

it('model ->update() on an audit row throws AuditLogImmutableException', function () {
    $row = makeActivityRow();

    expect(fn () => $row->update(['description' => 'tampered']))
        ->toThrow(AuditLogImmutableException::class);
});

it('model ->delete() on an audit row throws AuditLogImmutableException', function () {
    $row = makeActivityRow();

    expect(fn () => $row->delete())->toThrow(AuditLogImmutableException::class);
});

// ── Layer 2: database backstop (raw / mass writes that bypass the model) ────────

it('a raw DB::table update is denied at the database (trigger)', function () {
    $row = makeActivityRow();

    expect(fn () => DB::table('activity_log')->where('id', $row->id)->update(['description' => 'tampered']))
        ->toThrow(QueryException::class); // SQLSTATE 45000 from activity_log_no_update
});

it('a raw DB::table delete is denied at the database (trigger)', function () {
    $row = makeActivityRow();

    expect(fn () => DB::table('activity_log')->where('id', $row->id)->delete())
        ->toThrow(QueryException::class); // activity_log_no_delete
    expect(Activity::query()->whereKey($row->id)->exists())->toBeTrue(); // still there
});

it('a mass query-builder delete (the activitylog:clean path) is denied at the database', function () {
    makeActivityRow();

    // Activity::query()->delete() bypasses model events — only the DB trigger stops it.
    expect(fn () => Activity::query()->where('created_at', '<', now()->addDay())->delete())
        ->toThrow(QueryException::class);
    expect(Activity::query()->count())->toBeGreaterThan(0);
});

// ── Layer 3: activitylog:clean neutralised ──────────────────────────────────────

it('activitylog:clean is disabled and deletes nothing', function () {
    makeActivityRow();
    $before = Activity::query()->count();

    $this->artisan('activitylog:clean', ['--force' => true])->assertExitCode(1);

    expect(Activity::query()->count())->toBe($before); // nothing pruned
});

// ── Logging still works (a guard that breaks logging is worse than a mutable log)

it('a normal audited action still writes its row successfully', function () {
    $before = Activity::query()->count();

    activity('audit-test')->log('a legitimate new event');

    expect(Activity::query()->count())->toBe($before + 1)
        ->and(Activity::query()->latest('id')->first()->description)->toBe('a legitimate new event');
});
