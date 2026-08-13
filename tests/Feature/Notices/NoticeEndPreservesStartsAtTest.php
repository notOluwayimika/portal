<?php

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ENDING A NOTICE MUST NOT MOVE ITS START TIME — under a schema that carries
 * `ON UPDATE CURRENT_TIMESTAMP` on `notices.starts_at`.
 *
 * WHAT THIS ARM PROVES AND WHAT IT DOES NOT. It proves the CODE PATH is safe when the attribute is
 * present: `NoticeController::end()` puts `starts_at` in the SET list, which is what suppresses the
 * clause. It does NOT prove the attribute is gone — that is the migration's job
 * (`2026_08_13_100000_timestamp_columns_drop_implicit_on_update.php`), and no test in this file can
 * stand in for it. Two separate guarantees. A reader who takes either as covering the other will
 * delete one of them.
 *
 * THIS ARM IS SCOPED TO `notices.starts_at`, AND DELIBERATELY NOT COPIED. The same migration cleans
 * `notification_actions.expires_at` and `finance_ledger_transactions.posted_at`. Neither is armed
 * here and neither should be armed by analogy: what this file proves is that ONE code path
 * (`end()`) keeps its column in the SET list under a hostile schema, which is a behavioural fact
 * about that route and generalises to nothing. The other two columns are fixed BY SCHEMA — the
 * migration's own `information_schema` check is their proof, and a test that imposed the clause on
 * `finance_ledger_transactions` would additionally be asserting against a table whose `no_update`
 * trigger makes the UPDATE it would need impossible.
 *
 * THE CONDITION IS IMPOSED, NOT INHERITED, and without that this file would be worthless here. A
 * fresh `migrate` on a host with `explicit_defaults_for_timestamp` ON — which this one is — produces
 * a CLEAN column, so the obvious arm (end a notice, assert `starts_at` did not move) passes because
 * the defect cannot occur, not because the code prevents it. Same failure shape as an arm that reads
 * the session zone instead of pinning it; see `tests/Feature/Finance/SubledgerClockFrameTest.php`.
 * So this ALTERs the clause ON, asserts it actually landed, and only then exercises the route.
 *
 * DDL COMMITS IMPLICITLY, so `RefreshDatabase`'s transaction is gone the moment the first ALTER
 * runs and cannot be relied on to undo anything after it. Two consequences, both handled below:
 * the column is restored in a `finally` (a leaked `ON UPDATE` would poison every later test that
 * writes a notice), and every row this test creates is deleted explicitly BEFORE that restore —
 * the second ALTER would otherwise commit them into `portal_testing` for the rest of the run.
 * Nothing here uses `RbacSeeder`, for the same reason: its ~100 catalog rows would be committed by
 * the first ALTER with no way left to roll them back.
 *
 * WHAT CANNOT GUARD THAT CLEANUP, and it was tried. A second test asserting "the table is as it was"
 * is VACUOUS here, and provably so: `RefreshDatabase` resets `RefreshDatabaseState::$migrated` when
 * it finds no open transaction at teardown (`vendor/laravel/framework/src/Illuminate/Foundation/
 * Testing/RefreshDatabase.php:158-159`), which is exactly the state this test's DDL leaves — so
 * Laravel runs a full `migrate:fresh` before the next RefreshDatabase test and any such assertion
 * reads a brand-new schema. It passed with the restore deleted from the `finally`. It was removed
 * rather than repaired. The guard that DOES bite is the expectation at the bottom of the `finally`,
 * inside this same test, before the framework can intervene.
 */
uses(RefreshDatabase::class);

/**
 * The shape PRODUCTION carries on this column, read 2026-08-13 — stated explicitly rather than
 * inherited. (The local copy carried it too; the copy is not the authority, and for the migration's
 * other two columns it disagreed with production. See the migration's docblock.)
 */
const NOTICES_STARTS_AT_DIRTY = 'ALTER TABLE `notices` MODIFY `starts_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

/**
 * Put the column back the way the migration leaves it: the sentinel default, and no on-update.
 *
 * BYTE-IDENTICAL to the migration's `notices` `ALTER` — the migration issues three, one per dirty
 * column, and this is the statement it issues for this one — and it must stay that way, including
 * the sentinel, which is what suppresses the implicit clause on a host where
 * `explicit_defaults_for_timestamp` is OFF. A bare `MODIFY … TIMESTAMP NOT NULL` here would re-add
 * BOTH attributes on such a host and leak the clause into every later test that writes a notice.
 *
 * IT SETS NO SESSION VARIABLE, deliberately. An earlier draft did, and carried the migration's
 * privilege dependency into the suite with none of its tolerance: the `SET SESSION` sat inside the
 * `try`, so a refusal skipped the restoring `ALTER` and then threw again from the `finally`, masking
 * the original error. `ALTER` alone has no such failure mode.
 */
function restoreNoticesStartsAt(): void
{
    DB::statement("ALTER TABLE `notices` MODIFY `starts_at` TIMESTAMP NOT NULL DEFAULT '1970-01-02 00:00:01'");
}

function noticesStartsAtExtra(): string
{
    return (string) DB::selectOne(
        'SELECT EXTRA AS extra FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['notices', 'starts_at'],
    )?->extra;
}

it('does not move starts_at when ending a notice, with ON UPDATE CURRENT_TIMESTAMP imposed on the column', function () {
    $backDated = '2026-01-02 03:04:05';   // ~7 months before the row is written: a hand-typed schedule
    $ids = [];
    $createdPermission = false;

    DB::statement(NOTICES_STARTS_AT_DIRTY);

    try {
        // NOT VACUOUS: if the clause did not land, everything below is green for free.
        expect(strtolower(noticesStartsAtExtra()))->toContain('on update current_timestamp');

        $schoolId = DB::table('schools')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Notice arm school', 'slug' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids['schools'] = $schoolId;

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Notice', 'last_name' => 'Admin',
            'email' => Str::uuid().'@example.test', 'password' => bcrypt('password'),
            'school_id' => $schoolId, 'email_verified_at' => now(), 'two_factor_confirmed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids['users'] = $userId;

        $categoryId = DB::table('notice_categories')->insertGetId([
            'uuid' => (string) Str::uuid(), 'school_id' => $schoolId, 'name' => 'Arm', 'slug' => 'arm',
            'color' => 'gray', 'is_default' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids['notice_categories'] = $categoryId;

        $noticeUuid = (string) Str::uuid();
        $noticeId = DB::table('notices')->insertGetId([
            'uuid' => $noticeUuid, 'school_id' => $schoolId, 'notice_category_id' => $categoryId,
            'title' => 'Arm notice', 'body' => 'Arm body', 'starts_at' => $backDated, 'ends_at' => null,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids['notices'] = $noticeId;

        // The route sits behind `permission:admin_area.access`. Granted DIRECTLY to the user rather
        // than through a role, because a role row is one more thing to unpick by hand in the finally.
        $permission = Permission::where('name', 'admin_area.access')->where('guard_name', 'web')->first();
        if (! $permission) {
            $permission = Permission::create(['name' => 'admin_area.access', 'guard_name' => 'web']);
            $createdPermission = true;
        }

        $user = User::findOrFail($userId);
        setPermissionsTeamId($schoolId);
        $user->givePermissionTo($permission);

        // PRECONDITION READ, RAW. Eloquent's casts and the app timezone are not in the picture here:
        // whatever MySQL renders now is what it must render after the route runs.
        $before = DB::selectOne('SELECT starts_at, updated_at FROM notices WHERE id = ?', [$noticeId]);
        expect($before->starts_at)->toBe($backDated);

        // The clause fires on the SECOND granularity, so an UPDATE landing inside the same second as
        // the insert could hide a rewrite. Move the row's own clock away from now().
        $this->travel(90)->seconds();

        $this->actingAs($user)
            ->postJson("/api/notices/{$noticeUuid}/end")
            ->assertOk();

        $after = DB::selectOne('SELECT starts_at, ends_at, updated_at FROM notices WHERE id = ?', [$noticeId]);

        // THE ROUTE ACTUALLY WROTE. Without these two, a 200 that updated nothing — or an UPDATE
        // Eloquent skipped as clean — would satisfy the starts_at assertion for the wrong reason.
        expect($after->ends_at)->not->toBeNull()
            ->and($after->updated_at)->not->toBe($before->updated_at);

        // THE CLAIM. To the second, and byte-identical: the hand-typed schedule survived the edit.
        expect($after->starts_at)->toBe($backDated)
            ->and(strtotime($after->starts_at) - strtotime($before->starts_at))->toBe(0);
    } finally {
        $this->travelBack();

        foreach (['notices', 'notice_categories'] as $table) {
            if (isset($ids[$table])) {
                DB::table($table)->where('id', $ids[$table])->delete();
            }
        }

        if (isset($ids['users'])) {
            DB::table('model_has_permissions')
                ->where('model_type', User::class)->where('model_id', $ids['users'])->delete();
            DB::table('users')->where('id', $ids['users'])->delete();
        }

        if (isset($ids['schools'])) {
            DB::table('schools')->where('id', $ids['schools'])->delete();
        }

        if ($createdPermission) {
            DB::table('permissions')->where('name', 'admin_area.access')->where('guard_name', 'web')->delete();
        }

        restoreNoticesStartsAt();

        // THE ONE GUARD ON THE CLEANUP THAT ACTUALLY BITES, and the reason it is here rather than in
        // a following test: it runs inside this test, before `RefreshDatabase` can re-migrate the
        // database out from under an assertion. Bite-proved red by deleting the call above.
        expect(strtolower(noticesStartsAtExtra()))->not->toContain('on update');
    }
});
