<?php

/*
 * At most ONE live Guardian row per (user, school) — proven against the DATABASE.
 *
 * The application already has two layers that say this: GuardianService::forUserInActiveSchool
 * documents the gap and works around it with `orderBy('id')`, and
 * resolveOrCreateGuardianForUserInSchool looks before it creates. Both are conventions — they
 * hold only for callers that go through them, and a school still ended up with a parent
 * appearing three times.
 *
 * So every CONSTRAINT arm here writes with `DB::table('guardians')` directly: no model, no global
 * scope, no service, no observer, no validation. If one of those arms goes green it is because
 * `guardians_live_identity_unique` refused, and it cannot be because some PHP guard did. A test
 * that passes because GuardianService refused would prove nothing about the schema, which is the
 * only layer that survives a future writer that reads none of that code.
 *
 * The five constraint arms map to the five things the generated-column idiom has to get right:
 *
 *   1  two live rows for one pair          → REFUSED   (the constraint itself)
 *   2  soft-delete, then re-create         → allowed   (a parent can be removed and re-added)
 *   3  two soft-deleted rows for one pair  → allowed   (what a plain UNIQUE(user_id, school_id)
 *                                                       would have broken)
 *   4  one user, two schools               → allowed   (the multi-school parent, §6.2)
 *   5  restore while a live row exists     → REFUSED   (the retroactive collision)
 *
 * Three further arms cover the column rather than the index: that the index exists and is
 * actually UNIQUE, that a direct write to the generated column is refused rather than silently
 * accepted, and that `replicate()` — a live production path — still works. The last of those is
 * the one that turned out to need a code change; see Guardian::replicate().
 */

use App\Models\Guardian;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Insert a guardians row with the DB facade — deliberately below every application layer.
 *
 * Returns the new id. `$deletedAt` non-null makes the row soft-deleted at insert time, which is
 * the state the index is supposed to exempt.
 */
function guRawInsert(int $schoolId, int $userId, ?string $deletedAt = null): int
{
    return DB::table('guardians')->insertGetId([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $schoolId,
        'user_id' => $userId,
        'first_name' => 'Raw',
        'last_name' => 'Guardian',
        'phone' => '+2348000000000',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => $deletedAt,
    ]);
}

/**
 * Assert the closure trips a UNIQUE violation raised by THIS index.
 *
 * Driver code 1062 is MySQL's duplicate-entry error. Asserting the index NAME as well matters:
 * `guardians` already carries `guardians_uuid_unique`, so a bare "something was 1062" would pass
 * on a duplicate uuid — a bug in the test's own helper masquerading as the guarantee under test.
 */
function guExpectDuplicate(Closure $fn): void
{
    try {
        $fn();
        throw new RuntimeException('expected a duplicate-key QueryException, none thrown');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1062)
            ->and($e->errorInfo[2] ?? '')->toContain('guardians_live_identity_unique');
    }
}

/** @return array{0: int, 1: int} school id, user id */
function guPair(): array
{
    $school = School::factory()->create();
    $user = User::factory()->create(['school_id' => $school->id]);

    return [$school->id, $user->id];
}

it('has the live-identity column and its unique index', function () {
    $columns = DB::select(
        "SHOW COLUMNS FROM guardians WHERE Field = 'live_identity'"
    );

    expect($columns)->toHaveCount(1);

    $index = DB::select(
        "SHOW INDEX FROM guardians WHERE Key_name = 'guardians_live_identity_unique'"
    );

    // Non_unique = 0 is the whole point; a non-unique index of the same name enforces nothing.
    expect($index)->toHaveCount(1)
        ->and((int) $index[0]->Non_unique)->toBe(0)
        ->and($index[0]->Column_name)->toBe('live_identity');
});

it('refuses a second LIVE guardian for the same user and school', function () {
    [$schoolId, $userId] = guPair();

    $first = guRawInsert($schoolId, $userId);
    expect($first)->toBeGreaterThan(0);

    guExpectDuplicate(fn () => guRawInsert($schoolId, $userId));

    expect(DB::table('guardians')->whereNull('deleted_at')->where('user_id', $userId)->count())->toBe(1);
});

it('allows re-creating a guardian after the first is soft-deleted', function () {
    [$schoolId, $userId] = guPair();

    $first = guRawInsert($schoolId, $userId);
    DB::table('guardians')->where('id', $first)->update(['deleted_at' => now()]);

    $second = guRawInsert($schoolId, $userId);

    expect($second)->not->toBe($first)
        ->and(DB::table('guardians')->where('user_id', $userId)->count())->toBe(2)
        ->and(DB::table('guardians')->whereNull('deleted_at')->where('user_id', $userId)->count())->toBe(1);
});

it('allows any number of soft-deleted rows for the same user and school', function () {
    [$schoolId, $userId] = guPair();

    guRawInsert($schoolId, $userId, (string) now());
    guRawInsert($schoolId, $userId, (string) now());
    guRawInsert($schoolId, $userId, (string) now());

    expect(DB::table('guardians')->where('user_id', $userId)->count())->toBe(3)
        ->and(DB::table('guardians')->whereNull('deleted_at')->where('user_id', $userId)->count())->toBe(0);

    // ...and one live row on top of them is still fine.
    guRawInsert($schoolId, $userId);

    expect(DB::table('guardians')->whereNull('deleted_at')->where('user_id', $userId)->count())->toBe(1);
});

it('allows the same user a live guardian in each of two schools', function () {
    [$schoolAId, $userId] = guPair();
    $schoolBId = School::factory()->create()->id;

    guRawInsert($schoolAId, $userId);
    guRawInsert($schoolBId, $userId);

    expect(DB::table('guardians')->whereNull('deleted_at')->where('user_id', $userId)->count())->toBe(2);
});

/*
 * Restoring a soft-deleted row while a live one exists for the same pair is the retroactive
 * collision: nothing is being inserted, so a creation-path guard cannot see it. The UPDATE
 * recomputes `live_identity` from NULL back to `user:school` and collides.
 *
 * WHAT THE APPLICATION DOES WITH THIS TODAY: nothing, because it cannot reach it. There is no
 * guardian restore path — `git grep -n 'withTrashed\|onlyTrashed\|->restore('` over app/ and
 * routes/ returns two hits, both in StudentCurriculum. So this arm guards a route that does not
 * exist yet, deliberately: the day someone adds "undelete this parent", the schema will refuse
 * the bad case, and `Guardian` uses SoftDeletes so `restore()` issues exactly this UPDATE.
 *
 * What that future caller would see is a raw 409 — bootstrap/app.php:197 maps driver code 1062 to
 * `conflict('Duplicate entry detected.')` — rather than a validation message naming the live row.
 * That is a UX gap in a path nobody has written, so it is ticketed rather than fixed here:
 * docs/handoff/tickets/guardian-restore-into-occupied-pair.md.
 */
it('refuses restoring a soft-deleted guardian while a live one holds the pair', function () {
    [$schoolId, $userId] = guPair();

    $softDeleted = guRawInsert($schoolId, $userId, (string) now());
    guRawInsert($schoolId, $userId);

    guExpectDuplicate(
        fn () => DB::table('guardians')->where('id', $softDeleted)->update(['deleted_at' => null])
    );

    expect(DB::table('guardians')->whereNull('deleted_at')->where('user_id', $userId)->count())->toBe(1);
});

/*
 * The column is generated, so it is not merely absent from $fillable — it is unwritable. This
 * arm records WHICH failure mode an accidental write produces, because the two are very
 * different: a mass-assignment attempt is silently dropped by the $fillable whitelist (no error,
 * no write), while a direct write reaches MySQL and raises error 3105. Neither can corrupt the
 * column; only the second is visible.
 */
it('rejects a direct write to the generated column rather than corrupting it', function () {
    [$schoolId, $userId] = guPair();

    try {
        DB::table('guardians')->insert([
            'uuid' => (string) Str::orderedUuid(),
            'school_id' => $schoolId,
            'user_id' => $userId,
            'first_name' => 'Raw',
            'last_name' => 'Guardian',
            'phone' => '+2348000000000',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'live_identity' => 'forged',
        ]);
        throw new RuntimeException('expected MySQL to refuse a write to a generated column');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(3105);
    }

    expect(DB::table('guardians')->where('user_id', $userId)->count())->toBe(0);
});

/*
 * The one path the generated column actually broke, and the reason Guardian::replicate() is
 * overridden.
 *
 * `replicate()` copies getAttributes() verbatim, and a hydrated Guardian carries `live_identity`
 * like any other selected column — so the clone's INSERT names a generated column and MySQL
 * refuses it with 3105. GuardianService::resolveOrCreateGuardianForUserInSchool clones a Guardian
 * into a second School with exactly `$template->replicate(['uuid'])`, so without the override the
 * multi-school parent (§6.2) stops working the moment this migration runs.
 *
 * This arm exercises the model method rather than the private service method, because the model
 * is where the exclusion lives and the parked guardian-merge work replicates through the same
 * door. Delete the override and this goes red with driver code 3105 — verified.
 */
it('can still clone a guardian into a second school with replicate()', function () {
    [$schoolAId, $userId] = guPair();
    $schoolBId = School::factory()->create()->id;

    $created = Guardian::factory()->create(['school_id' => $schoolAId, 'user_id' => $userId]);

    // RE-FETCH, do not reuse the factory's return value. A freshly-created model carries only the
    // attributes that were INSERTed, so `live_identity` is absent from it and replicate() has
    // nothing to copy — the arm passes with the override deleted. Caught by watching it fail to go
    // red. The real path hydrates from a SELECT (resolveOrCreateGuardianForUserInSchool receives a
    // Guardian resolved by query), and only a hydrated model reproduces the bug.
    $template = Guardian::withoutGlobalScopes()->findOrFail($created->id);
    expect($template->getAttributes())->toHaveKey('live_identity');

    $clone = $template->replicate(['uuid']);

    expect($clone->getAttributes())->not->toHaveKey('live_identity');

    $clone->uuid = (string) Str::orderedUuid();
    $clone->school_id = $schoolBId;
    $clone->save();

    expect(DB::table('guardians')->whereNull('deleted_at')->where('user_id', $userId)->count())->toBe(2)
        ->and(DB::table('guardians')->where('id', $clone->id)->value('live_identity'))
        ->toBe($userId.':'.$schoolBId);
});
