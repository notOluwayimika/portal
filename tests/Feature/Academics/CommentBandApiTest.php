<?php

use App\Models\CommentBand;
use App\Models\CommentEntry;
use App\Models\ExamType;
use App\Models\User;
use App\Services\CommentBandService;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The HTTP surface for comment banks — the Score Comments tab in school setup.
 *
 * Everything here sits inside the `permission:academic_setup.manage` group in routes/api.php, so
 * the first test is the one that proves the group is actually doing its job; the rest would all
 * pass just as happily on an unguarded route.
 *
 * The cross-school test is the other one worth reading. `CommentEntry` carries no school_id by
 * design, so the entry routes are nested under `{commentBand:uuid}` and the SCOPED band binding is
 * what proves ownership. This exercises the pairing an attacker would actually try: a band uuid
 * they own, an entry uuid they do not.
 */
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function cba_admin(): User
{
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'admin');
    $user->flushSchoolAccessCache();
    setPermissionsTeamId($school->id);

    return $user;
}

/** Load the starter ladder into $user's school and return its bands. */
function cba_seedBands(User $user)
{
    return ActiveSchool::runFor(
        $user->school_id,
        fn () => (new CommentBandService)->loadDefaults(null)
    );
}

// ── Authorization ──────────────────────────────────────────────────────────

it('refuses an unauthenticated caller', function () {
    $this->getJson('/api/comment-bands')->assertUnauthorized();
});

it('refuses a user without academic_setup.manage', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    // A teacher enters scores and USES the suggestions, but does not author them.
    $user->grantSchoolAccess($school, 'teacher');
    $user->flushSchoolAccessCache();

    $this->actingAs($user)->getJson('/api/comment-bands')->assertForbidden();
});

// ── Reading ────────────────────────────────────────────────────────────────

it('returns the school default ladder highest band first, with its comments', function () {
    $admin = cba_admin();
    cba_seedBands($admin);

    $response = $this->actingAs($admin)->getJson('/api/comment-bands')->assertOk();

    $bands = $response->json('data');

    expect($bands)->toHaveCount(7)
        ->and($bands[0]['label'])->toBe('Outstanding')
        ->and((float) $bands[0]['min_score'])->toBe(91.0)
        ->and((float) $bands[0]['max_score'])->toBe(100.0)
        ->and($bands[0]['comments'])->toHaveCount(2);
});

it('does NOT fall back to the default when reading an exam type\'s own ladder', function () {
    $admin = cba_admin();
    cba_seedBands($admin);

    $examType = ActiveSchool::runFor(
        $admin->school_id,
        // ExamType has a SchoolScope but no school_id auto-fill, so it must be set explicitly or
        // the row is invisible to the very scope this test depends on.
        fn () => ExamType::create([
            'school_id' => $admin->school_id,
            'name' => 'Mock',
            'slug' => 'mock-'.uniqid(),
        ])
    );

    // The admin is about to CREATE an override. Showing them the school default here would let
    // them "edit" rows belonging to a different ladder — the read fallback is for score entry,
    // not for the editor.
    $this->actingAs($admin)
        ->getJson('/api/comment-bands?exam_type='.$examType->uuid)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ── Saving the ladder ──────────────────────────────────────────────────────

it('saves a ladder and derives each band\'s upper bound', function () {
    $admin = cba_admin();

    $this->actingAs($admin)->putJson('/api/comment-bands', [
        'bands' => [
            ['min_score' => 0, 'label' => 'Poor'],
            ['min_score' => 50, 'label' => 'Fair'],
            ['min_score' => 80, 'label' => 'Great'],
        ],
    ])->assertOk()
        ->assertJsonPath('data.0.label', 'Great')
        // JSON renders these as numbers, so compare numerically rather than by PHP type.
        ->assertJsonPath('data.0.max_score', fn ($v) => (float) $v === 100.0)
        ->assertJsonPath('data.1.max_score', fn ($v) => (float) $v === 80.0)
        ->assertJsonPath('data.2.max_score', fn ($v) => (float) $v === 50.0);
});

it('rejects a ladder that does not start at 0', function () {
    $admin = cba_admin();

    // Scores below 40 would resolve to no band and the teacher would silently get nothing.
    $this->actingAs($admin)->putJson('/api/comment-bands', [
        'bands' => [['min_score' => 40, 'label' => 'Pass']],
    ])->assertStatus(422)->assertJsonValidationErrors('bands');
});

it('rejects two bands starting at the same score', function () {
    $admin = cba_admin();

    $this->actingAs($admin)->putJson('/api/comment-bands', [
        'bands' => [
            ['min_score' => 0, 'label' => 'Poor'],
            ['min_score' => 0, 'label' => 'Also poor'],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('bands');
});

it('rejects an empty ladder', function () {
    $this->actingAs(cba_admin())
        ->putJson('/api/comment-bands', ['bands' => []])
        ->assertStatus(422);
});

// ── The starter set ────────────────────────────────────────────────────────

it('loads the starter set into an empty ladder', function () {
    $admin = cba_admin();

    $this->actingAs($admin)
        ->postJson('/api/comment-bands/load-defaults')
        ->assertCreated()
        ->assertJsonCount(7, 'data');
});

it('refuses to load the starter set over a configured ladder', function () {
    $admin = cba_admin();
    cba_seedBands($admin);

    // Merging would duplicate the school's own wording; overwriting would destroy it. Neither is
    // what "load the defaults" asks for once they have made editorial decisions.
    $this->actingAs($admin)
        ->postJson('/api/comment-bands/load-defaults')
        ->assertStatus(422);
});

// ── Comments ───────────────────────────────────────────────────────────────

it('adds a comment to a band', function () {
    $admin = cba_admin();
    $band = cba_seedBands($admin)->first();

    $this->actingAs($admin)
        ->postJson("/api/comment-bands/{$band->uuid}/entries", ['body' => 'Superb work this term'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Superb work this term');
});

it('refuses a comment longer than the column allows', function () {
    $admin = cba_admin();
    $band = cba_seedBands($admin)->first();

    // Refused at authoring time rather than discovered by a teacher mid-score-entry — the exact
    // failure that shipped when a 52-character default met a varchar(50) column.
    $this->actingAs($admin)
        ->postJson("/api/comment-bands/{$band->uuid}/entries", [
            'body' => str_repeat('x', CommentEntry::MAX_LENGTH + 1),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');
});

it('refuses a duplicate comment within the same band', function () {
    $admin = cba_admin();
    $band = cba_seedBands($admin)->first();
    $existing = $band->activeComments->first()->body;

    $this->actingAs($admin)
        ->postJson("/api/comment-bands/{$band->uuid}/entries", ['body' => $existing])
        ->assertStatus(422);
});

it('retires a comment without deleting the row', function () {
    $admin = cba_admin();
    $band = cba_seedBands($admin)->first();
    $entry = $band->activeComments->first();

    $this->actingAs($admin)
        ->putJson("/api/comment-bands/{$band->uuid}/entries/{$entry->uuid}", ['is_active' => false])
        ->assertOk();

    expect(CommentEntry::find($entry->id)->is_active)->toBeFalse();
});

// ── Cross-school ───────────────────────────────────────────────────────────

it('cannot reach another school\'s band', function () {
    $mine = cba_admin();
    $theirs = cba_admin();
    $theirBand = cba_seedBands($theirs)->first();

    setPermissionsTeamId($mine->school_id);

    $this->actingAs($mine)
        ->postJson("/api/comment-bands/{$theirBand->uuid}/entries", ['body' => 'Injected'])
        ->assertNotFound();
});

it('cannot drive another school\'s entry through a band it does own', function () {
    $mine = cba_admin();
    $theirs = cba_admin();

    $myBand = cba_seedBands($mine)->first();
    $theirEntry = cba_seedBands($theirs)->first()->activeComments->first();

    setPermissionsTeamId($mine->school_id);

    // The band binding is scoped and passes; the entry belongs to someone else. Without the
    // owning check this pairing would delete a stranger's comment.
    $this->actingAs($mine)
        ->deleteJson("/api/comment-bands/{$myBand->uuid}/entries/{$theirEntry->uuid}")
        ->assertNotFound();

    expect(CommentEntry::find($theirEntry->id))->not->toBeNull();
});

it('never deletes another school\'s bands when saving its own ladder', function () {
    $mine = cba_admin();
    $theirs = cba_admin();
    cba_seedBands($theirs);

    setPermissionsTeamId($mine->school_id);

    // saveSet() deletes whatever the payload omits. The SchoolScope is what keeps that delete
    // inside the caller's school rather than emptying the table.
    $this->actingAs($mine)->putJson('/api/comment-bands', [
        'bands' => [['min_score' => 0, 'label' => 'Everything']],
    ])->assertOk();

    ActiveSchool::runFor($theirs->school_id, function () {
        expect(CommentBand::count())->toBe(7);
    });
});
