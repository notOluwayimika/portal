<?php

use App\Models\Guardian;
use App\Models\Notice;
use App\Models\NoticeCategory;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\GuardianService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * Build the shape that broke the portal: ONE user, a Guardian record in TWO
 * schools (the per-School Guardian record — GuardianService.php §6.2), each
 * with its own ward. The OTHER school's Guardian row is created FIRST so it
 * has the lower id, which is what an unordered `hasOne` returns.
 *
 * The user's own school_id is the ACTIVE school (ActiveSchool::id() falls back
 * to users.school_id with no session), so "active school" here is $home.
 *
 * @return array{0: User, 1: Student, 2: Student}
 */
function parentWithWardsInTwoSchools(School $home, School $other): array
{
    $user = User::factory()->create(['school_id' => $home->id]);

    foreach ([$other, $home] as $school) {
        setPermissionsTeamId($school->id);
        $user->assignRole('guardian');
        $user->schools()->syncWithoutDetaching([$school->id]);
    }

    // Lower id, and NOT the active school.
    $guardianElsewhere = Guardian::factory()->create([
        'school_id' => $other->id,
        'user_id' => $user->id,
    ]);
    $guardianHome = Guardian::factory()->create([
        'school_id' => $home->id,
        'user_id' => $user->id,
    ]);

    $wardElsewhere = Student::factory()->create(['school_id' => $other->id]);
    $wardHome = Student::factory()->create(['school_id' => $home->id]);

    $guardianElsewhere->students()->attach($wardElsewhere->id, [
        'relationship' => 'father', 'is_primary' => true, 'can_login' => true,
    ]);
    $guardianHome->students()->attach($wardHome->id, [
        'relationship' => 'father', 'is_primary' => true, 'can_login' => true,
    ]);

    expect($guardianElsewhere->id)->toBeLessThan($guardianHome->id);

    setPermissionsTeamId($home->id);

    return [$user, $wardHome, $wardElsewhere];
}

it('returns the active school ward for a parent who also has a ward in another school', function () {
    $home = School::factory()->create();
    $other = School::factory()->create();
    [$user, $wardHome, $wardElsewhere] = parentWithWardsInTwoSchools($home, $other);

    $res = $this->actingAs($user)->getJson('/api/parent/wards')->assertOk();

    // The defect returned an EMPTY list here: the hasOne handed back the other
    // school's Guardian, and Student's SchoolScope then filtered its ward out.
    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0.id'))->toBe($wardHome->uuid);
    expect(collect($res->json('data'))->pluck('id'))
        ->not->toContain($wardElsewhere->uuid);
});

// This asserts the RESOLVER, not the list. The list-level "does not leak another
// school's ward" property is held by Student's SchoolScope, so a test phrased that
// way stays green with the resolver fully reverted — it cannot fail for the reason
// its name claims. Assert the guardian row that was chosen instead.
it('resolves the active school guardian row, not another school row belonging to the same user', function () {
    $home = School::factory()->create();
    $other = School::factory()->create();
    [$user] = parentWithWardsInTwoSchools($home, $other);

    // ActiveSchool::id() reads auth()->user(), so the resolver must be called
    // inside an authenticated context to have a school at all.
    $this->actingAs($user);
    $resolved = app(GuardianService::class)->forUserInActiveSchool($user);

    expect($resolved)->not->toBeNull();
    expect($resolved->school_id)->toBe($home->id);

    // And it is genuinely a choice between rows: the same user does own a
    // guardian row in the other school, with a lower id.
    expect(
        Guardian::withoutGlobalScopes()->where('user_id', $user->id)->count()
    )->toBe(2);
});

it('returns an empty list, not an error, when the parent has no guardian record in the active school', function () {
    $home = School::factory()->create();
    $user = User::factory()->create(['school_id' => $home->id]);
    setPermissionsTeamId($home->id);
    $user->assignRole('guardian');
    $user->schools()->syncWithoutDetaching([$home->id]);

    $this->actingAs($user)
        ->getJson('/api/parent/wards')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('is gated on the same ability as the parent wards page', function () {
    $home = School::factory()->create();
    $user = User::factory()->create(['school_id' => $home->id]);
    setPermissionsTeamId($home->id);
    $user->assignRole('teacher');
    $user->schools()->syncWithoutDetaching([$home->id]);

    // The feed is now gated on the same ability as the page that consumes it
    // (parent/wards, routes/web.php), instead of student_status.view. NOTE this
    // does not make the portal free of student_status.view: the page still calls
    // /api/students/{uuid}/result-status, which remains under that ability.
    $this->actingAs($user)->getJson('/api/parent/wards')->assertForbidden();
});

// Finding 1 from review: the notices feed on this same page had the identical
// `$user->guardian` defect and would have silently emptied for exactly the
// parents this change was written for, in the same request cycle.
it('resolves guardian notices from the active school guardian row too', function () {
    $home = School::factory()->create();
    $other = School::factory()->create();
    [$user, $wardHome] = parentWithWardsInTwoSchools($home, $other);

    // Targeted at the home ward SPECIFICALLY. That is what makes this test
    // discriminate: resolving the wrong guardian row yields an empty $studentIds,
    // the student-targeted branch of the candidates query then matches nothing,
    // and the notice vanishes from the feed.
    $category = NoticeCategory::create([
        'school_id' => $home->id,
        'name' => 'General',
        'slug' => 'general-'.$home->id,
        'color' => 'gray',
        'is_default' => true,
    ]);

    $notice = Notice::create([
        'school_id' => $home->id,
        'notice_category_id' => $category->id,
        'title' => 'Ward-targeted notice',
        'body' => 'Body',
        'starts_at' => now()->subDay(),
        'created_by' => $user->id,
    ]);
    $notice->students()->attach($wardHome->id);

    $titles = collect(
        $this->actingAs($user)->getJson('/api/guardian/notices')->assertOk()->json('data')
    )->pluck('title');

    expect($titles)->toContain('Ward-targeted notice');
});
