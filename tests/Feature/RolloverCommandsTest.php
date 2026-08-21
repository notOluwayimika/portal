<?php

use App\Jobs\MoveFromTermJob;
use App\Jobs\MoveToNextYearJob;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------

function rc_session(School $school, string $name): AcademicSession
{
    return AcademicSession::create([
        'school_id' => $school->id,
        'name' => $name,
        'slug' => 'sess-'.Str::random(8),
        'is_current' => false,
    ]);
}

function rc_term(AcademicSession $session, int $order): Term
{
    return Term::create([
        'academic_session_id' => $session->id,
        'school_id' => $session->school_id,
        'name' => "Term {$order}",
        'slug' => 'term-'.Str::random(8),
        'order' => $order,
        'start_date' => now()->addMonths($order * 3),
        'end_date' => now()->addMonths($order * 3 + 2),
        'status' => 'active',
    ]);
}

function rc_level(School $school, string $name, int $order, array $slots, array $attrs = []): array
{
    $level = ClassLevel::forceCreate(array_merge([
        'school_id' => $school->id, 'name' => $name, 'order' => $order,
    ], $attrs));

    foreach ($slots as $slot) {
        ClassLevelTermParticipation::forceCreate([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'term_order' => $slot,
            'is_ccm' => false,
        ]);
    }

    $arm = ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $level->id,
        'arm_id' => Arm::firstOrCreate(['school_id' => $school->id, 'label' => 'B'])->id,
    ]);

    return [$level, $arm];
}

function rc_curriculum(School $school, ClassLevelArm $arm, Term $term, ExamType $et, bool $isCcm = false): Curriculum
{
    return Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $arm->id,
        'exam_type_id' => $et->id,
        'status' => 'active',
        'is_ccm' => $isCcm,
        'min_subjects' => 1,
    ]);
}

function rc_world(): array
{
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    $examType = ExamType::create(['school_id' => $school->id, 'name' => 'Internal', 'slug' => 'et-'.Str::random(8)]);
    $source = rc_session($school, '2025/2026');
    $target = rc_session($school, '2026/2027');

    return compact('school', 'admin', 'examType', 'source', 'target');
}

// ---------------------------------------------------------------------------
// end-of-term
// ---------------------------------------------------------------------------

it('end-of-term dispatches exactly one job per active non-CCM curriculum', function () {
    Bus::fake();
    $w = rc_world();
    $term = rc_term($w['source'], 1);
    [, $armA] = rc_level($w['school'], 'Year 7', 7, [1, 2]);
    [, $armB] = rc_level($w['school'], 'Year 8', 8, [1, 2]);
    $c1 = rc_curriculum($w['school'], $armA, $term, $w['examType']);
    $c2 = rc_curriculum($w['school'], $armB, $term, $w['examType']);

    $this->artisan('academics:run-end-of-term', [
        'term' => $term->uuid, '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(0);

    // The exact SET, not merely a count — a job for the wrong curriculum would pass a count check.
    Bus::assertBatched(function ($batch) use ($c1, $c2) {
        $ids = collect($batch->jobs)->map(fn ($j) => $j->curriculum->id)->sort()->values()->all();

        return $ids === collect([$c1->id, $c2->id])->sort()->values()->all()
            && collect($batch->jobs)->every(fn ($j) => $j instanceof MoveFromTermJob);
    });
});

it('end-of-term ABORTS and dispatches NOTHING while a CCM curriculum is still active', function () {
    // Asserts nothing dispatched — not merely that a warning was printed. Without this gate the run
    // is green while MoveFromTermJob silently declines the CCM cohort.
    Bus::fake();
    $w = rc_world();
    $term = rc_term($w['source'], 1);
    [, $arm] = rc_level($w['school'], 'Year 7', 7, [1, 2]);
    rc_curriculum($w['school'], $arm, $term, $w['examType'], isCcm: true);

    $this->artisan('academics:run-end-of-term', [
        'term' => $term->uuid, '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(1);

    Bus::assertNothingBatched();
});

it('end-of-term dry run dispatches nothing, and reports the same count --commit then queues', function () {
    Bus::fake();
    $w = rc_world();
    $term = rc_term($w['source'], 1);
    [, $arm] = rc_level($w['school'], 'Year 7', 7, [1, 2]);
    rc_curriculum($w['school'], $arm, $term, $w['examType']);

    $this->artisan('academics:run-end-of-term', ['term' => $term->uuid])
        ->expectsOutputToContain('1 curriculum/curricula')
        ->expectsOutputToContain('DRY RUN')
        ->assertExitCode(0);

    Bus::assertNothingBatched();

    // PARITY: the plan the dry run reported is what --commit actually queues.
    $this->artisan('academics:run-end-of-term', [
        'term' => $term->uuid, '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(0);

    Bus::assertBatched(fn ($batch) => count($batch->jobs) === 1);
});

it('end-of-term never selects another schools curricula', function () {
    Bus::fake();
    $w = rc_world();
    $term = rc_term($w['source'], 1);
    [, $arm] = rc_level($w['school'], 'Year 7', 7, [1, 2]);
    $mine = rc_curriculum($w['school'], $arm, $term, $w['examType']);

    // A second school, its own session/term/level, same term order.
    $other = rc_world();
    $otherTerm = rc_term($other['source'], 1);
    [, $otherArm] = rc_level($other['school'], 'Year 7', 7, [1, 2]);
    rc_curriculum($other['school'], $otherArm, $otherTerm, $other['examType']);

    $this->artisan('academics:run-end-of-term', [
        'term' => $term->uuid, '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(0);

    Bus::assertBatched(function ($batch) use ($mine) {
        return count($batch->jobs) === 1 && $batch->jobs[0]->curriculum->id === $mine->id;
    });
});

// ---------------------------------------------------------------------------
// end-of-year
// ---------------------------------------------------------------------------

it('end-of-year selects ONLY the levels final participating slot, on a non-contiguous level', function () {
    // THE SELECTION SUBTLETY, on a level that runs slots 1 and 3 — never 2.
    //   • MAX(term_order) = 3            <- correct
    //   • count(participation rows) = 2  <- would dispatch term 2's curriculum
    //   • session's last term = 3        <- agrees here, but not for the 1-2 level below
    // A contiguous fixture cannot tell these apart; this one can.
    Bus::fake();
    $w = rc_world();
    $t1 = rc_term($w['source'], 1);
    $t2 = rc_term($w['source'], 2);
    $t3 = rc_term($w['source'], 3);
    rc_term($w['target'], 1);

    [, $arm] = rc_level($w['school'], 'Year 11', 11, [1, 3]);
    $midSlot = rc_curriculum($w['school'], $arm, $t2, $w['examType']);   // term 2: NOT a slot it runs
    $notFinal = rc_curriculum($w['school'], $arm, $t1, $w['examType']);  // term 1: a slot, but not last
    $final = rc_curriculum($w['school'], $arm, $t3, $w['examType']);     // term 3: the final slot

    $this->artisan('academics:run-end-of-year', [
        'sourceSession' => $w['source']->uuid, 'targetSession' => $w['target']->uuid,
        '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(0);

    Bus::assertBatched(function ($batch) use ($final, $midSlot, $notFinal) {
        $ids = collect($batch->jobs)->map(fn ($j) => $j->curriculum->id);

        // The final one is present AND both others are absent — asserted separately, because
        // "the final one is there" alone would pass an implementation that dispatched all three.
        return $ids->contains($final->id)
            && ! $ids->contains($midSlot->id)
            && ! $ids->contains($notFinal->id)
            && $ids->count() === 1;
    });
});

it('end-of-year uses each levels OWN final slot, not the sessions last term', function () {
    // A level running 1-2 in a three-term session ends at 2. An implementation keyed on the session's
    // last term would find nothing for it (or dispatch the wrong term).
    Bus::fake();
    $w = rc_world();
    $t2 = rc_term($w['source'], 2);
    $t3 = rc_term($w['source'], 3);
    rc_term($w['target'], 1);

    [, $shortArm] = rc_level($w['school'], 'Year 6', 6, [1, 2]);   // ends at 2
    [, $longArm] = rc_level($w['school'], 'Year 11', 11, [1, 2, 3]); // ends at 3
    $shortFinal = rc_curriculum($w['school'], $shortArm, $t2, $w['examType']);
    $longFinal = rc_curriculum($w['school'], $longArm, $t3, $w['examType']);

    $this->artisan('academics:run-end-of-year', [
        'sourceSession' => $w['source']->uuid, 'targetSession' => $w['target']->uuid,
        '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(0);

    Bus::assertBatched(function ($batch) use ($shortFinal, $longFinal) {
        $ids = collect($batch->jobs)->map(fn ($j) => $j->curriculum->id)->sort()->values()->all();

        return $ids === collect([$shortFinal->id, $longFinal->id])->sort()->values()->all();
    });
});

it('end-of-year ABORTS on a cyclic progression graph and dispatches NOTHING', function () {
    Bus::fake();
    $w = rc_world();
    $t1 = rc_term($w['source'], 1);
    rc_term($w['target'], 1);

    [$a, $armA] = rc_level($w['school'], 'Year 7', 7, [1]);
    [$b] = rc_level($w['school'], 'Year 8', 8, [1]);
    // A -> B -> A. The trigger permits it (it only guards the self-loop); the gate must not.
    $a->update(['next_class_level_id' => $b->id]);
    $b->update(['next_class_level_id' => $a->id]);
    rc_curriculum($w['school'], $armA, $t1, $w['examType']);

    $this->artisan('academics:run-end-of-year', [
        'sourceSession' => $w['source']->uuid, 'targetSession' => $w['target']->uuid,
        '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(1);

    Bus::assertNothingBatched();
});

it('end-of-year ABORTS and dispatches NOTHING when a final slot is CCM', function () {
    Bus::fake();
    $w = rc_world();
    $t1 = rc_term($w['source'], 1);
    rc_term($w['target'], 1);
    [, $arm] = rc_level($w['school'], 'Year 7', 7, [1]);
    rc_curriculum($w['school'], $arm, $t1, $w['examType'], isCcm: true);

    $this->artisan('academics:run-end-of-year', [
        'sourceSession' => $w['source']->uuid, 'targetSession' => $w['target']->uuid,
        '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(1);

    Bus::assertNothingBatched();
});

it('end-of-year refuses a target session that is missing, foreign, or the source itself', function () {
    Bus::fake();
    $w = rc_world();
    rc_term($w['source'], 1);
    [, $arm] = rc_level($w['school'], 'Year 7', 7, [1]);
    rc_curriculum($w['school'], $arm, Term::withoutGlobalScope(SchoolScope::class)->first(), $w['examType']);

    $foreign = rc_session(al_makeSchool(), '2026/2027');

    $this->artisan('academics:run-end-of-year', [
        'sourceSession' => $w['source']->uuid, 'targetSession' => (string) Str::uuid(),
        '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(1);

    $this->artisan('academics:run-end-of-year', [
        'sourceSession' => $w['source']->uuid, 'targetSession' => $foreign->uuid,
        '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(1);

    $this->artisan('academics:run-end-of-year', [
        'sourceSession' => $w['source']->uuid, 'targetSession' => $w['source']->uuid,
        '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(1);

    Bus::assertNothingBatched();
});

it('end-of-year binds the TARGET session to every dispatched job', function () {
    // The session is a constructor argument precisely because it cannot be inferred; assert it
    // actually arrives on the job rather than trusting the wiring.
    Bus::fake();
    $w = rc_world();
    $t1 = rc_term($w['source'], 1);
    rc_term($w['target'], 1);
    [, $arm] = rc_level($w['school'], 'Year 7', 7, [1]);
    rc_curriculum($w['school'], $arm, $t1, $w['examType']);

    $this->artisan('academics:run-end-of-year', [
        'sourceSession' => $w['source']->uuid, 'targetSession' => $w['target']->uuid,
        '--user' => $w['admin']->id, '--commit' => true,
    ])->assertExitCode(0);

    Bus::assertBatched(function ($batch) use ($w) {
        return collect($batch->jobs)->every(
            fn ($j) => $j instanceof MoveToNextYearJob
                && (int) $j->targetSession->id === (int) $w['target']->id
                && (int) $j->schoolId === (int) $w['school']->id
        );
    });
});

it('--commit without --user refuses rather than attributing the rollover to nobody', function () {
    Bus::fake();
    $w = rc_world();
    $term = rc_term($w['source'], 1);
    [, $arm] = rc_level($w['school'], 'Year 7', 7, [1, 2]);
    rc_curriculum($w['school'], $arm, $term, $w['examType']);

    $this->artisan('academics:run-end-of-term', ['term' => $term->uuid, '--commit' => true])
        ->assertExitCode(1);

    Bus::assertNothingBatched();
});
