<?php

use App\Http\Resources\CurriculumSubjectResource;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\MarkingComponent;
use App\Models\Score;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\SubjectResultStatus;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\ScoreUnit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Score entry: the unit, and clearing.
 *
 * `scores.score` stores a WEIGHTED value — the percentage the teacher typed times the marking
 * component's weight, so 100 on a 10%-weighted component is stored as 10.0. That conversion used
 * to exist only in score-entry-page.tsx, in both directions, with the server storing whatever
 * arrived. The meaning of a stored score therefore depended on which JS bundle the browser was
 * running, and a client applying one half of the pair showed a 100 as 10.0.
 *
 * These tests pin the unit at the API boundary, which is the only place it can be pinned once and
 * stay pinned.
 */
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/**
 * A subject with one marking component of the given weight, one enrolled student, and a teacher
 * who may enter scores.
 *
 * @return array{0: User, 1: CurriculumSubject, 2: Student, 3: MarkingComponent}
 */
function se_fixture(float $weight = 0.100, array $extraWeights = []): array
{
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'teacher');
    $user->flushSchoolAccessCache();
    setPermissionsTeamId($school->id);

    [$cs, $student, $mc] = ActiveSchool::runFor($school->id, function () use ($school, $weight, $extraWeights) {
        $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

        $subject = Subject::create([
            'school_id' => $school->id,
            'name' => 'Subject '.Str::random(5),
            'code' => strtoupper(Str::random(4)),
        ]);

        $cs = CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'is_compulsory' => true,
            'active' => true,
        ]);

        $mc = MarkingComponent::create([
            'curriculum_subject_id' => $cs->id,
            'school_id' => $school->id,
            'name' => 'CA '.Str::random(4),
            'weight' => $weight,
        ]);

        foreach ($extraWeights as $i => $w) {
            MarkingComponent::create([
                'curriculum_subject_id' => $cs->id,
                'school_id' => $school->id,
                'name' => 'Extra '.$i.' '.Str::random(3),
                'weight' => $w,
            ]);
        }

        $student = Student::factory()->create(['school_id' => $school->id]);

        $sc = StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $school->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        // firstOrCreate: creating a StudentCurriculum already enrols the student in the
        // curriculum's compulsory subjects, so creating this outright hits the
        // (student_curriculum, curriculum_subject) unique index.
        StudentSubject::firstOrCreate(
            [
                'student_curriculum_id' => $sc->id,
                'curriculum_subject_id' => $cs->id,
            ],
            ['status' => 'active'],
        );

        return [$cs, $student, $mc];
    });

    return [$user, $cs, $student, $mc];
}

function se_post($test, $user, $cs, $student, $mc, array $payload)
{
    return $test->actingAs($user)->postJson("/api/curriculum-subjects/{$cs->uuid}/scores", array_merge([
        'curriculum_subject_id' => $cs->uuid,
        'student_id' => $student->uuid,
        'marking_component_id' => $mc->uuid,
    ], $payload));
}

function se_stored($student, $mc): ?Score
{
    return Score::withoutGlobalScopes()
        ->where('student_id', $student->id)
        ->where('marking_component_id', $mc->id)
        ->first();
}

// ── The unit: 100 means 100 ───────────────────────────────────────────────

it('stores 100 on a 10%-weight component as the weighted 10.0 and reads it back as 100', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 100])->assertOk();

    // The storage contract is UNCHANGED — still weighted. This is the assertion that would fail if
    // someone "fixed" the bug by rescaling the column instead.
    expect((float) se_stored($student, $mc)->score)->toBe(10.0);

    // ...and the teacher gets their own number back. THIS is the reported bug: it used to be the
    // browser dividing, so a stale bundle showed 10.0 here.
    $percent = ScoreUnit::toPercent(se_stored($student, $mc)->score, $mc->fresh());
    expect($percent)->toBe(100.0);
});

it('exposes score_percent on the payload the entry grid reads', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 88])->assertOk();

    $payload = ActiveSchool::runFor($student->school_id, function () use ($cs) {
        $cs->load(['scores.markingComponent', 'scores.student']);

        return (new CurriculumSubjectResource($cs))->toArray(request());
    });

    $score = collect($payload['scores'])->first()->toArray(request());

    expect((float) $score['score'])->toBe(8.8)          // weighted, as stored
        ->and($score['score_percent'])->toBe(88.0);     // what the teacher typed
});

it('round-trips every whole percentage exactly for the weights in use', function () {
    // decimal(4,1) storage: a percentage survives only when percent x weight lands on a 0.1
    // boundary. This is why the input is restricted to whole numbers.
    foreach ([0.100, 0.500, 0.700] as $weight) {
        $mc = new MarkingComponent(['weight' => $weight]);

        foreach (range(0, 100) as $percent) {
            $weighted = ScoreUnit::toWeighted((float) $percent, $mc);

            expect(ScoreUnit::toPercent($weighted, $mc))
                ->toBe((float) $percent, "weight {$weight}, percent {$percent}");
        }
    }
});

it('refuses a percentage above 100', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 101])
        ->assertStatus(422)
        ->assertJsonValidationErrors('score_percent');

    expect(se_stored($student, $mc))->toBeNull();
});

// ── A stale bundle is refused, not silently stored ────────────────────────

it('refuses a request still sending the legacy weighted score field', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    // Exactly what a cached pre-fix bundle posts: the already-weighted number, under `score`.
    se_post($this, $user, $cs, $student, $mc, ['score' => 10])
        ->assertStatus(422)
        ->assertJsonValidationErrors('score');

    // The whole point: nothing was written. Silently accepting this is how a 100 became a 10.
    expect(se_stored($student, $mc))->toBeNull();
});

it('refuses a legacy request even when it also sends score_percent', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 50, 'score' => 5])
        ->assertStatus(422);

    expect(se_stored($student, $mc))->toBeNull();
});

// ── Zero is a score, not a deletion ───────────────────────────────────────

it('stores a zero instead of deleting the row', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 0])->assertOk();

    // `if ($score->score == 0) { $score->delete(); }` used to run here and answer 200.
    expect(se_stored($student, $mc))->not->toBeNull()
        ->and((float) se_stored($student, $mc)->score)->toBe(0.0);
});

it('keeps a low score instead of rounding it to zero', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    // 4% of a 10%-weighted component weighs 0.4, which the old `abs(...) < 0.5` rule silently
    // zeroed — so on this component EVERY percentage below 5 became 0 with a 200 response.
    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 4])->assertOk();

    expect((float) se_stored($student, $mc)->score)->toBe(0.4)
        ->and(ScoreUnit::toPercent(se_stored($student, $mc)->score, $mc->fresh()))->toBe(4.0);
});

it('lets a zero complete a subject rather than holding it back', function () {
    // Row absence is the publish gate, so deleting a zero meant a student who scored 0 could never
    // have a complete set of scores.
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 0])->assertOk();

    $count = Score::withoutGlobalScopes()->where('curriculum_subject_id', $cs->id)->count();

    expect($count)->toBe(1);
});

// ── Clearing ──────────────────────────────────────────────────────────────

function se_clear($test, $user, $cs, $student, $mc)
{
    return $test->actingAs($user)->deleteJson("/api/curriculum-subjects/{$cs->uuid}/scores", [
        'student_id' => $student->uuid,
        'marking_component_id' => $mc->uuid,
    ]);
}

it('clears a score through the delete endpoint', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 70])->assertOk();
    expect(se_stored($student, $mc))->not->toBeNull();

    se_clear($this, $user, $cs, $student, $mc)->assertNoContent();

    expect(se_stored($student, $mc))->toBeNull();
});

it('is idempotent when the cell was already empty', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 70])->assertOk();

    se_clear($this, $user, $cs, $student, $mc)->assertNoContent();
    // "The cell is empty" is already true; a 404 here would show the teacher an error for a state
    // they asked for and already have.
    se_clear($this, $user, $cs, $student, $mc)->assertNoContent();
});

it('records who cleared a score and what it was', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 70])->assertOk();
    $scoreId = se_stored($student, $mc)->id;

    se_clear($this, $user, $cs, $student, $mc)->assertNoContent();

    // The recovery path the old silent zero-delete never had — and the reason clearing needs no
    // confirmation dialog.
    $row = DB::table('activity_log')
        ->where('subject_type', Score::class)
        ->where('subject_id', $scoreId)
        ->where('event', 'deleted')
        ->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->causer_id)->toBe((int) $user->id);
});

it('refuses to clear a score on an approved subject', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 70])->assertOk();

    SubjectResultStatus::updateOrCreate(
        ['curriculum_subject_id' => $cs->id],
        ['status' => 'approved'],
    );

    se_clear($this, $user, $cs, $student, $mc)->assertStatus(422);

    expect(se_stored($student, $mc))->not->toBeNull();
});

it('refuses a model-level delete on an approved subject', function () {
    // SEPARATE from the endpoint test on purpose. Only `saving` was guarded, so `delete()`
    // bypassed the service-layer protection entirely and relied on every caller remembering to
    // check — which the old clear-by-posting-zero path did not, because it deleted from inside the
    // write handler. Remove the `deleting` hook and this goes red while the endpoint test above
    // stays green: that is exactly the gap that existed.
    [$user, $cs, $student, $mc] = se_fixture(0.100);

    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 70])->assertOk();
    $score = se_stored($student, $mc);

    SubjectResultStatus::updateOrCreate(
        ['curriculum_subject_id' => $cs->id],
        ['status' => 'approved'],
    );

    expect(fn () => $score->delete())->toThrow(DomainException::class);
});

it('does not let one school clear another school\'s score', function () {
    [$user, $cs, $student, $mc] = se_fixture(0.100);
    se_post($this, $user, $cs, $student, $mc, ['score_percent' => 70])->assertOk();

    // A second school's teacher, aiming at the first school's curriculum subject. `curriculum_subjects`
    // carries no school_id and no SchoolScope, so route-model binding resolves it happily — the
    // guard has to be explicit.
    $other = al_makeSchool();
    $intruder = al_makeUser($other->id);
    $intruder->grantSchoolAccess($other, 'teacher');
    $intruder->flushSchoolAccessCache();
    setPermissionsTeamId($other->id);

    $this->actingAs($intruder)->deleteJson("/api/curriculum-subjects/{$cs->uuid}/scores", [
        'student_id' => $student->uuid,
        'marking_component_id' => $mc->uuid,
    ])->assertNotFound();

    expect(se_stored($student, $mc))->not->toBeNull();
});

// ── Totals still weighted ─────────────────────────────────────────────────

it('still totals a full set of perfect scores to 100', function () {
    // Three 10% components and one 70%. Pins that moving the conversion server-side did not
    // rescale what `sum('score')` produces for published results.
    [$user, $cs, $student, $mc] = se_fixture(0.100, [0.100, 0.100, 0.700]);

    $components = ActiveSchool::runFor($student->school_id, fn () => $cs->markingComponents()->get());

    foreach ($components as $component) {
        se_post($this, $user, $cs, $student, $component, ['score_percent' => 100])->assertOk();
    }

    $total = Score::withoutGlobalScopes()
        ->where('curriculum_subject_id', $cs->id)
        ->sum('score');

    expect((float) $total)->toBe(100.0);
});
