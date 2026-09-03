<?php

use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Jobs\MoveToNextYearJob;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmProgression;
use App\Models\ClassLevelExamType;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\GradingScheme;
use App\Models\MarkingScheme;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\Term;
use App\Services\Rollover\RolloverPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// The Y11 -> Y12 fixture, which fires BOTH fallbacks in one transition.
//
// Y11 arms B, S, I, P, H   |   Y12 arms B, S, I, P  (no H)  -> 11H has no label match
// Y11 runs BSS + WAEC      |   Y12 runs WAEC alone          -> a BSS pupil hits the default
//
// Real shape, taken from school 1's live data rather than invented, so the fallback paths are
// exercised by the case that actually fires at the first real year-end run.
// ---------------------------------------------------------------------------

function mny_session(School $school, string $name, bool $current = false): AcademicSession
{
    return AcademicSession::create([
        'school_id' => $school->id,
        'name' => $name,
        'slug' => 'sess-'.Str::random(8),
        'is_current' => $current,
    ]);
}

function mny_term(AcademicSession $session, int $order, string $status = 'upcoming'): Term
{
    return Term::create([
        'academic_session_id' => $session->id,
        'school_id' => $session->school_id,
        'name' => "Term {$order}",
        'slug' => 'term-'.Str::random(8),
        'order' => $order,
        'start_date' => now()->addMonths($order * 3),
        'end_date' => now()->addMonths($order * 3 + 2),
        'status' => $status,
    ]);
}

function mny_level(School $school, string $name, int $order, array $attrs = []): ClassLevel
{
    return ClassLevel::forceCreate(array_merge([
        'school_id' => $school->id,
        'name' => $name,
        'order' => $order,
    ], $attrs));
}

/** @param list<string> $labels */
function mny_arms(School $school, ClassLevel $level, array $labels): array
{
    $arms = [];
    foreach ($labels as $label) {
        $arm = Arm::firstOrCreate(['school_id' => $school->id, 'label' => $label]);
        $arms[$label] = ClassLevelArm::forceCreate([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'arm_id' => $arm->id,
        ]);
    }

    return $arms;
}

function mny_participation(School $school, ClassLevel $level, array $slots, array $ccmSlots = []): void
{
    foreach ($slots as $slot) {
        ClassLevelTermParticipation::forceCreate([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'term_order' => $slot,
            'is_ccm' => in_array($slot, $ccmSlots, true),
        ]);
    }
}

function mny_examType(School $school, string $name): ExamType
{
    return ExamType::create([
        'school_id' => $school->id,
        'name' => $name,
        'slug' => 'et-'.Str::random(8),
    ]);
}

/**
 * @param  array<string, mixed>  $opts  y12Strategy, y12GradingScheme, targetSessionTerms
 */
function mny_world(array $opts = []): array
{
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);

    $bss = mny_examType($school, 'BSS Grading');
    $waec = mny_examType($school, 'WAEC Grading');

    $gradingScheme = ($opts['y12GradingScheme'] ?? false)
        ? GradingScheme::create(['school_id' => $school->id, 'name' => 'Y12 Ratings'])
        : null;

    $y12 = mny_level($school, 'Year 12', 12, [
        'default_exam_type_id' => $waec->id,
        'arm_distribution_strategy' => $opts['y12Strategy'] ?? 'round_robin',
        'grading_scheme_id' => $gradingScheme?->id,
    ]);
    $y11 = mny_level($school, 'Year 11', 11, ['next_class_level_id' => $y12->id]);

    $y11Arms = mny_arms($school, $y11, ['B', 'S', 'I', 'P', 'H']);
    $y12Arms = mny_arms($school, $y12, ['B', 'S', 'I', 'P']);

    mny_participation($school, $y11, [1, 2, 3]);
    mny_participation($school, $y12, [1, 2, 3], $opts['y12CcmSlots'] ?? []);

    // Y11 runs both; Y12 runs WAEC alone — the default-fallback case.
    ClassLevelExamType::forceCreate(['school_id' => $school->id, 'class_level_id' => $y11->id, 'exam_type_id' => $bss->id]);
    ClassLevelExamType::forceCreate(['school_id' => $school->id, 'class_level_id' => $y11->id, 'exam_type_id' => $waec->id]);
    ClassLevelExamType::forceCreate(['school_id' => $school->id, 'class_level_id' => $y12->id, 'exam_type_id' => $waec->id]);

    $sourceSession = mny_session($school, '2025/2026', true);
    $targetSession = mny_session($school, '2026/2027');
    $sourceTerm = mny_term($sourceSession, 3, TermStatusEnum::ACTIVE->value);

    if (($opts['targetSessionTerms'] ?? true) === true) {
        mny_term($targetSession, 1);
    }

    return compact('school', 'admin', 'bss', 'waec', 'y11', 'y12', 'y11Arms', 'y12Arms', 'sourceSession', 'targetSession', 'sourceTerm');
}

/** Build the Y11 source curriculum for one arm, with a compulsory subject. */
function mny_source(array $w, string $armLabel, ?ExamType $examType = null): Curriculum
{
    $curriculum = Curriculum::create([
        'school_id' => $w['school']->id,
        'term_id' => $w['sourceTerm']->id,
        'class_level_arm_id' => $w['y11Arms'][$armLabel]->id,
        'exam_type_id' => ($examType ?? $w['waec'])->id,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
    ]);

    CurriculumSubject::create([
        'curriculum_id' => $curriculum->id,
        'subject_id' => Subject::create(['school_id' => $w['school']->id, 'name' => 'Maths '.Str::random(4)])->id,
        'is_compulsory' => true,
    ]);

    return $curriculum;
}

function mny_enroll(array $w, Curriculum $source, string $status = 'active'): array
{
    $student = Student::create([
        'school_id' => $w['school']->id,
        'first_name' => 'Pupil',
        'last_name' => Str::random(6),
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    $episode = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $source->id,
        'status' => $status,
    ]);

    return [$student, $episode];
}

function mny_run(array $w, Curriculum $source): void
{
    (new MoveToNextYearJob($source, $w['targetSession'], $w['admin']->id, (int) $w['school']->id))->handle();
}

/** The episode a pupil ended up in, in the TARGET session. */
function mny_landed(array $w, Student $student): ?StudentCurriculum
{
    return StudentCurriculum::whereHas('curriculum.term', fn ($q) => $q->where('academic_session_id', $w['targetSession']->id))
        ->where('student_id', $student->id)
        ->first();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('11B lands in 12B by stream-aware label match', function () {
    $w = mny_world();
    $source = mny_source($w, 'B');
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    $landed = mny_landed($w, $student);
    expect($landed)->not->toBeNull();
    expect($landed->curriculum->class_level_arm_id)->toBe($w['y12Arms']['B']->id);
    expect($landed->status)->toBe(StudentStatusEnum::ACTIVE);
});

it('11H has no 12H to match, so it distributes deterministically by student_id', function () {
    // THE LABEL-MATCH MISS, on the real shape: Y12 has B, S, I, P and no H.
    $w = mny_world();
    $source = mny_source($w, 'H');
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    // Exact arm, not "some arm": the placement contract is student_id % 4 over the target level's
    // arms ordered by id ascending.
    $arms = ClassLevelArm::withoutGlobalScope(SchoolScope::class)
        ->where('class_level_id', $w['y12']->id)->orderBy('id')->pluck('id')->values();
    $expected = $arms[$student->id % $arms->count()];

    expect(mny_landed($w, $student)->curriculum->class_level_arm_id)->toBe($expected);
});

it('a BSS pupil resolves to Y12s default exam type, a WAEC pupil carries theirs', function () {
    // Both halves of the set-plus-default design, in the transition it was designed for.
    $w = mny_world();

    $bssSource = mny_source($w, 'B', $w['bss']);
    [$bssPupil] = mny_enroll($w, $bssSource);
    mny_run($w, $bssSource);

    $waecSource = mny_source($w, 'S', $w['waec']);
    [$waecPupil] = mny_enroll($w, $waecSource);
    mny_run($w, $waecSource);

    // BSS is not in Y12's set -> the default (WAEC).
    expect(mny_landed($w, $bssPupil)->curriculum->exam_type_id)->toBe($w['waec']->id);
    // WAEC is in the set -> carried.
    expect(mny_landed($w, $waecPupil)->curriculum->exam_type_id)->toBe($w['waec']->id);
});

it('refuses an arm map that points outside the progression target level', function () {
    // OBLIGATION #1. The FKs guarantee same-school and nothing more — no constraint ties a mapped
    // target to next_class_level_id, so this must be refused in the job or a pupil is promoted into
    // the wrong year, silently, because the write succeeds.
    $w = mny_world();
    $y9 = mny_level($w['school'], 'Year 9', 9);
    $y9Arms = mny_arms($w['school'], $y9, ['B']);

    ClassLevelArmProgression::forceCreate([
        'school_id' => $w['school']->id,
        'source_class_level_arm_id' => $w['y11Arms']['H']->id,
        'target_class_level_arm_id' => $y9Arms['B']->id,
    ]);

    $source = mny_source($w, 'H');
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    // Not promoted at all — and emphatically not into Year 9.
    expect(mny_landed($w, $student))->toBeNull();
    expect(StudentCurriculum::where('student_id', $student->id)->count())->toBe(1);
    // Unresolved, so the source stays OPEN for a re-run after the map is fixed.
    expect($source->fresh()->status)->toBe('active');
});

it('follows an arm map that does point into the target level', function () {
    $w = mny_world();
    ClassLevelArmProgression::forceCreate([
        'school_id' => $w['school']->id,
        'source_class_level_arm_id' => $w['y11Arms']['H']->id,
        'target_class_level_arm_id' => $w['y12Arms']['P']->id,
    ]);

    $source = mny_source($w, 'H');
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    expect(mny_landed($w, $student)->curriculum->class_level_arm_id)->toBe($w['y12Arms']['P']->id);
});

it('places pupils by pupil, not per job — 11B and 11H do not both start at the same arm', function () {
    // CROSS-JOB STABILITY. A per-job round-robin counter would have every source curriculum start at
    // the first arm and skew the target level; a shared counter would race between concurrent jobs.
    $w = mny_world();
    $arms = ClassLevelArm::withoutGlobalScope(SchoolScope::class)
        ->where('class_level_id', $w['y12']->id)->orderBy('id')->pluck('id')->values();

    $sourceH = mny_source($w, 'H');
    $pupils = collect(range(1, 6))->map(fn () => mny_enroll($w, $sourceH)[0]);
    mny_run($w, $sourceH);

    // Every pupil sits exactly where their own id says, regardless of dispatch order or batch.
    foreach ($pupils as $pupil) {
        expect(mny_landed($w, $pupil)->curriculum->class_level_arm_id)
            ->toBe($arms[$pupil->id % $arms->count()]);
    }
});

it('leaves a pupil unplaced under explicit_only rather than guessing an arm', function () {
    $w = mny_world(['y12Strategy' => 'explicit_only']);
    $source = mny_source($w, 'H'); // no label match, no map
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    expect(mny_landed($w, $student))->toBeNull();
    expect($source->fresh()->status)->toBe('active'); // held open for a human
});

it('is idempotent across a manual reassignment — the promoted_to_id anchor, not the curriculum', function () {
    // THE ANCHOR PROOF. Placement is a pure function, so a plain re-run converges — this only bites
    // once a pupil has been moved off the arm the function computes. A firstOrCreate-by-curriculum
    // anchor mints a duplicate here; the link anchor does not.
    //
    // THE RE-OPEN BELOW IS A SIMULATION, AND SAYS SO. Everyone here is placeable, so run 1 closes the
    // source legitimately; the test then re-opens it BY HAND to model a partial run, purely so the
    // re-run reaches the body instead of stopping at the guard. That is honest scaffolding, not the
    // real recovery path — the genuinely-open case is driven end to end in the reopen-and-resolve
    // test below, which is where the conditional close earns its keep.
    $w = mny_world();
    $source = mny_source($w, 'B');
    [$moved] = mny_enroll($w, $source);

    mny_run($w, $source);
    expect($source->fresh()->status)->toBe('closed');

    // Re-open by hand — see the note above. Then move the pupil as Part 4's service would.
    $source->update(['status' => 'active']);
    $landed = mny_landed($w, $moved);
    $reassigned = Curriculum::withoutGlobalScope(SchoolScope::class)->firstOrCreate([
        'school_id' => $w['school']->id,
        'term_id' => $landed->curriculum->term_id,
        'class_level_arm_id' => $w['y12Arms']['S']->id,
        'exam_type_id' => $landed->curriculum->exam_type_id,
        'is_ccm' => false,
    ], ['min_subjects' => 1, 'status' => 'active']);
    $landed->update(['curriculum_id' => $reassigned->id]);

    mny_run($w, $source);

    // No duplicate back in 12B, and the pupil stayed where they were moved to.
    expect(StudentCurriculum::where('student_id', $moved->id)->count())->toBe(2); // Y11 source + the moved Y12 one
    expect(mny_landed($w, $moved)->curriculum->class_level_arm_id)->toBe($w['y12Arms']['S']->id);
});

/**
 * THE ARC THE CONDITIONAL CLOSE EXISTS FOR, driven end to end — and the one behaviour a refactor back
 * to an unconditional close would break with every other test still green.
 *
 * Real unresolved state holds the source open; the config is then fixed; the re-run advances the
 * laggard, skips the pupil who already succeeded (via the promoted_to_id anchor, on a re-run that
 * genuinely reaches the body rather than stopping at the guard), and the source finally closes.
 *
 * Both halves existed separately before this: a source left open, and an anchor that skips. Neither
 * proved they compose, which is the whole justification for diverging from the sibling jobs.
 */
it('reopens, resolves the laggard on a re-run, skips the already-promoted, then closes', function () {
    // explicit_only, so placement comes ONLY from the arm map — which makes "unplaceable" a real
    // configuration state a human fixes, not a contrivance.
    $w = mny_world(['y12Strategy' => 'explicit_only']);
    $source = mny_source($w, 'H'); // 11H: no 12H, so no label match either
    [$placeable] = mny_enroll($w, $source);
    [$laggard] = mny_enroll($w, $source);

    // Only the first pupil can be placed: a per-ARM map cannot distinguish two pupils in one arm, so
    // the map is added AFTER run 1 to model the fix. Run 1 therefore places nobody.
    mny_run($w, $source);

    expect(mny_landed($w, $placeable))->toBeNull();
    expect(mny_landed($w, $laggard))->toBeNull();
    // HELD OPEN by genuinely unresolved pupils — not re-opened by the test.
    expect($source->fresh()->status)->toBe('active');

    // A human fixes the configuration.
    ClassLevelArmProgression::forceCreate([
        'school_id' => $w['school']->id,
        'source_class_level_arm_id' => $w['y11Arms']['H']->id,
        'target_class_level_arm_id' => $w['y12Arms']['P']->id,
    ]);

    mny_run($w, $source);

    // Both advance now, and the source closes because nothing is left unresolved.
    expect(mny_landed($w, $placeable)->curriculum->class_level_arm_id)->toBe($w['y12Arms']['P']->id);
    expect(mny_landed($w, $laggard)->curriculum->class_level_arm_id)->toBe($w['y12Arms']['P']->id);
    expect($source->fresh()->status)->toBe('closed');

    // A THIRD run must be a no-op even though the guard now stops it — assert the data, so the
    // anchor's contribution is visible rather than inferred.
    $episodeCount = StudentCurriculum::count();
    mny_run($w, $source);
    expect(StudentCurriculum::count())->toBe($episodeCount);
});

/**
 * The same arc with the laggard as a REPEATER, so the held-repeater firstOrCreate anchor is exercised
 * on a re-run that reaches the body — the one path the advancer anchor cannot cover.
 */
it('reconverges a held repeater on a re-run without minting a second same-level episode', function () {
    $w = mny_world(['y12Strategy' => 'explicit_only']);
    $source = mny_source($w, 'H');
    [$repeater, $repeaterEpisode] = mny_enroll($w, $source, StudentStatusEnum::REPEATED->value);
    mny_enroll($w, $source); // an advancer who cannot be placed, holding the source open

    mny_run($w, $source);

    $held = mny_landed($w, $repeater);
    expect($held)->not->toBeNull();
    expect($source->fresh()->status)->toBe('active');

    mny_run($w, $source);

    // Same episode, not a second one — and the source episode still untouched.
    expect(StudentCurriculum::where('student_id', $repeater->id)->count())->toBe(2);
    expect(mny_landed($w, $repeater)->id)->toBe($held->id);
    expect($repeaterEpisode->fresh()->status)->toBe(StudentStatusEnum::REPEATED);
    expect($repeaterEpisode->fresh()->promoted_to_id)->toBeNull();
});

it('holds a repeater in their own level, same arm, with no promotion link', function () {
    // OPTION (b). Honouring the status if present is correct under both workflows: unset, the branch
    // never fires and everyone advances.
    $w = mny_world();
    $source = mny_source($w, 'B');
    [$repeater, $repeaterEpisode] = mny_enroll($w, $source, StudentStatusEnum::REPEATED->value);
    [$advancer] = mny_enroll($w, $source);

    mny_run($w, $source);

    // Held in Year 11, same arm.
    $held = mny_landed($w, $repeater);
    expect($held)->not->toBeNull();
    expect($held->curriculum->class_level_arm_id)->toBe($w['y11Arms']['B']->id);
    expect($held->status)->toBe(StudentStatusEnum::ACTIVE);

    // The repeat record survives: source untouched, and promoted_to_id keeps meaning "was promoted".
    expect($repeaterEpisode->fresh()->status)->toBe(StudentStatusEnum::REPEATED);
    expect($repeaterEpisode->fresh()->promoted_to_id)->toBeNull();

    // And no Year 12 episode was created for them.
    $y12ArmIds = collect($w['y12Arms'])->pluck('id');
    expect(
        StudentCurriculum::where('student_id', $repeater->id)
            ->whereHas('curriculum', fn ($q) => $q->whereIn('class_level_arm_id', $y12ArmIds))
            ->exists()
    )->toBeFalse();

    // The advancer beside them still advanced.
    expect(mny_landed($w, $advancer)->curriculum->class_level_arm_id)->toBe($w['y12Arms']['B']->id);
});

it('no-ops for a terminal class level, and leaves graduates untouched under a closed curriculum', function () {
    // GRADUATION IS OUT OF SCOPE, PINNED IN BOTH DIRECTIONS. Advancers at a terminal level are
    // skipped before any write, so `unresolved` stays 0 and the source closes — with their episodes
    // still reading `active`. That is deliberate: nothing here decides what "left school" means.
    // Asserted explicitly so neither half can drift silently, and so a reader cannot infer that
    // end-of-year closes leavers out. If graduates need marking, that is a separate operation.
    $w = mny_world();
    $w['y11']->update(['next_class_level_id' => null]);
    $source = mny_source($w, 'B');
    [$student, $episode] = mny_enroll($w, $source);

    mny_run($w, $source);

    expect(mny_landed($w, $student))->toBeNull();
    expect(StudentCurriculum::where('student_id', $student->id)->count())->toBe(1);

    // The graduate's episode: untouched, still active, no promotion link.
    expect($episode->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
    expect($episode->fresh()->promoted_to_id)->toBeNull();
    expect($episode->fresh()->ended_at)->toBeNull();

    // Nothing was left unresolved, so the curriculum closes over them.
    expect($source->fresh()->status)->toBe('closed');
});

it('no-ops and creates nothing when the target session has no Term row', function () {
    $w = mny_world(['targetSessionTerms' => false]);
    $source = mny_source($w, 'B');
    [$student] = mny_enroll($w, $source);

    $curriculaBefore = Curriculum::withoutGlobalScope(SchoolScope::class)->count();
    $termsBefore = Term::withoutGlobalScope(SchoolScope::class)->count();

    mny_run($w, $source);

    expect(Term::withoutGlobalScope(SchoolScope::class)->count())->toBe($termsBefore);
    expect(Curriculum::withoutGlobalScope(SchoolScope::class)->count())->toBe($curriculaBefore);
    expect(mny_landed($w, $student))->toBeNull();
    expect($source->fresh()->status)->toBe('active');
});

it('resolves schemes from the TARGET level, never copying the source curriculums', function () {
    // RESOLVE-DON'T-COPY at a level boundary. Scheme-backed on purpose: Part 2's lesson is that this
    // whole branch is invisible if every fixture leaves marking_scheme_id NULL.
    $w = mny_world(['y12GradingScheme' => true]);
    $sourceScheme = MarkingScheme::create(['school_id' => $w['school']->id, 'is_ccm' => false, 'version' => 1, 'status' => 'active']);
    $newerScheme = MarkingScheme::create(['school_id' => $w['school']->id, 'is_ccm' => false, 'version' => 2, 'status' => 'active']);

    $sourceGrading = GradingScheme::create(['school_id' => $w['school']->id, 'name' => 'Y11 Ratings']);
    $source = mny_source($w, 'B');
    $source->update(['marking_scheme_id' => $sourceScheme->id, 'grading_scheme_id' => $sourceGrading->id]);
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    $target = mny_landed($w, $student)->curriculum;
    // The TARGET LEVEL's grading scheme, not the source curriculum's.
    expect($target->grading_scheme_id)->toBe($w['y12']->grading_scheme_id);
    expect($target->grading_scheme_id)->not->toBe($sourceGrading->id);
    // The latest matching marking scheme, not the source's older one.
    expect($target->marking_scheme_id)->toBe($newerScheme->id);
    expect($target->marking_scheme_id)->not->toBe($sourceScheme->id);
});

it('gives a CCM first slot a CCM marking scheme', function () {
    $w = mny_world(['y12CcmSlots' => [1]]);
    MarkingScheme::create(['school_id' => $w['school']->id, 'is_ccm' => false, 'version' => 1, 'status' => 'active']);
    $ccmScheme = MarkingScheme::create(['school_id' => $w['school']->id, 'is_ccm' => true, 'version' => 1, 'status' => 'active']);

    $source = mny_source($w, 'B');
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    $target = mny_landed($w, $student)->curriculum;
    expect((bool) $target->is_ccm)->toBeTrue();
    expect($target->marking_scheme_id)->toBe($ccmScheme->id);
});

it('attaches the target levels compulsory subjects and carries none across the boundary', function () {
    $w = mny_world();
    $source = mny_source($w, 'B');

    // An OPTIONAL Y11 subject the pupil takes — it must NOT follow them across a level boundary.
    $optional = CurriculumSubject::create([
        'curriculum_id' => $source->id,
        'subject_id' => Subject::create(['school_id' => $w['school']->id, 'name' => 'Fine Art'])->id,
        'is_compulsory' => false,
    ]);
    [$student, $episode] = mny_enroll($w, $source);
    StudentSubject::firstOrCreate(
        ['student_curriculum_id' => $episode->id, 'curriculum_subject_id' => $optional->id],
        ['status' => 'active']
    );

    mny_run($w, $source);

    $landed = mny_landed($w, $student);
    $names = StudentSubject::where('student_curriculum_id', $landed->id)
        ->get()->map(fn ($s) => $s->curriculumSubject->subject->name)->all();

    expect($names)->not->toContain('Fine Art');
});

it('refuses a target session belonging to another school', function () {
    $w = mny_world();
    $otherSchool = al_makeSchool();
    $foreignSession = mny_session($otherSchool, '2026/2027');

    $source = mny_source($w, 'B');
    [$student] = mny_enroll($w, $source);

    (new MoveToNextYearJob($source, $foreignSession, $w['admin']->id, (int) $w['school']->id))->handle();

    expect(StudentCurriculum::where('student_id', $student->id)->count())->toBe(1);
    expect($source->fresh()->status)->toBe('active');
});

// ---------------------------------------------------------------------------
// SUBJECT INHERITANCE ACROSS THE YEAR BOUNDARY
//
// The destination is created bare by NextYearPlacementResolver, so before this fix every pupil
// promoted into a freshly-rolled session landed subject-less. The seeding source is the SAME LEVEL
// in the CLOSING session — 2025/26 Year 12 seeds 2026/27 Year 12 — which is why the lookup keys on
// the DESTINATION's class_level_arm_id, not the source curriculum's.
// ---------------------------------------------------------------------------

/**
 * Last year's instance of a Y12 arm: the row the destination must inherit from.
 *
 * Deliberately built with a MIXED compulsory/optional set. A set that is entirely compulsory cannot
 * tell "the destination inherited the subject list" from "the pupil was attached everything", and a
 * set of one cannot tell an inherited display_order from a default.
 *
 * @param  list<array{name: string, compulsory: bool, order: int}>  $subjects
 */
function mny_priorYear(array $w, string $armLabel, array $subjects, ?ExamType $examType = null, bool $isCcm = false): Curriculum
{
    $prior = Curriculum::create([
        'school_id' => $w['school']->id,
        // The CLOSING session's term — the same term the source curriculum sits in.
        'term_id' => $w['sourceTerm']->id,
        'class_level_arm_id' => $w['y12Arms'][$armLabel]->id,
        'exam_type_id' => ($examType ?? $w['waec'])->id,
        'status' => 'closed',
        'is_ccm' => $isCcm,
        'min_subjects' => 1,
    ]);

    foreach ($subjects as $spec) {
        CurriculumSubject::create([
            'curriculum_id' => $prior->id,
            'subject_id' => Subject::create(['school_id' => $w['school']->id, 'name' => $spec['name']])->id,
            'is_compulsory' => $spec['compulsory'],
            'display_order' => $spec['order'],
            'active' => $spec['active'] ?? true,
        ]);
    }

    return $prior;
}

/** The subject NAMES on a curriculum, sorted — an identity, not a count. */
function mny_subjectNames(Curriculum $curriculum): array
{
    return CurriculumSubject::where('curriculum_id', $curriculum->id)
        ->with('subject')
        ->get()
        ->map(fn (CurriculumSubject $cs) => $cs->subject->name)
        ->sort()
        ->values()
        ->all();
}

it('seeds the destination from the closing sessions curriculum for the SAME level', function () {
    // THE REPRODUCTION, in its fixed direction. Before the fix the destination held zero
    // curriculum_subjects and the pupil landed with zero student_subjects.
    $w = mny_world();
    $prior = mny_priorYear($w, 'B', [
        ['name' => 'Physics', 'compulsory' => true, 'order' => 1],
        ['name' => 'Chemistry', 'compulsory' => true, 'order' => 2],
        ['name' => 'Further Maths', 'compulsory' => false, 'order' => 3],
    ]);

    $source = mny_source($w, 'B');
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    $landed = mny_landed($w, $student);
    $destination = $landed->curriculum;

    // A DIFFERENT ROW from the prior year's — inherited, not reused.
    expect($destination->id)->not->toBe($prior->id);
    expect((int) $destination->term_id)->not->toBe((int) $prior->term_id);

    // EQUALS the prior set, not merely "non-empty".
    expect(mny_subjectNames($destination))->toBe(['Chemistry', 'Further Maths', 'Physics']);
    expect(mny_subjectNames($destination))->toBe(mny_subjectNames($prior));

    // The per-subject attributes carried, not just the subject ids.
    $chemistry = CurriculumSubject::where('curriculum_id', $destination->id)
        ->whereHas('subject', fn ($q) => $q->where('name', 'Chemistry'))->first();
    expect($chemistry->is_compulsory)->toBeTrue();
    expect($chemistry->display_order)->toBe(2);

    $further = CurriculumSubject::where('curriculum_id', $destination->id)
        ->whereHas('subject', fn ($q) => $q->where('name', 'Further Maths'))->first();
    expect($further->is_compulsory)->toBeFalse();

    // Each cloned subject gets its own draft result status, as the end-of-term clone does.
    expect($chemistry->resultStatus)->not->toBeNull();
    expect($chemistry->resultStatus->status)->toBe('draft');

    // THE PUPIL: the COMPULSORY ones only. The optional subject in the fixture is what makes this an
    // assertion about compulsory-attach rather than about attach-everything.
    $attached = StudentSubject::where('student_curriculum_id', $landed->id)
        ->get()->map(fn ($s) => $s->curriculumSubject->subject->name)->sort()->values()->all();
    expect($attached)->toBe(['Chemistry', 'Physics']);

    // And the schemes stayed RESOLVED from the target level — seeding carries subjects, never schemes.
    expect($destination->grading_scheme_id)->toBe($w['y12']->grading_scheme_id);
    expect($destination->marking_scheme_id)->toBe($prior->marking_scheme_id);
});

it('leaves the destination subject-less when the closing session has no curriculum for that level', function () {
    // THE NEGATIVE, UNCHANGED. First year of operation, or a genuinely new level: today's behaviour
    // must survive, and must not become an error.
    $w = mny_world();
    $source = mny_source($w, 'B');
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    $landed = mny_landed($w, $student);
    expect($landed)->not->toBeNull();
    expect(CurriculumSubject::where('curriculum_id', $landed->curriculum_id)->count())->toBe(0);
    expect(StudentSubject::where('student_curriculum_id', $landed->id)->count())->toBe(0);

    // The pupil WAS placed — a subject-less landing is not an unresolved one, so the source closes.
    expect($source->fresh()->status)->toBe('closed');

    // And the pre-flight warning that flags exactly this still fires.
    $plan = app(RolloverPlanner::class)
        ->planEndOfYear($w['sourceSession'], $w['targetSession']);
    expect($plan->placement->advancers->every(fn ($g) => $g->destinationIsUnconfigured()))->toBeTrue();
});

it('does not seed from a DIFFERENT level, exam type or is_ccm in the closing session', function () {
    // THE LOOKUP KEY, pinned on every axis it claims to match. Each decoy below sits in the closing
    // session and would be picked up by a lookup that dropped one key — so this fails in three
    // distinguishable ways rather than passing because the one axis under test happened to hold.
    $w = mny_world();

    // Decoy 1: a DIFFERENT class level entirely (Y11 arm S), same session. Wrong class_level_arm_id.
    // Not Y11 B: that is the source curriculum's own key, and colliding on it proves nothing.
    Curriculum::create([
        'school_id' => $w['school']->id, 'term_id' => $w['sourceTerm']->id,
        'class_level_arm_id' => $w['y11Arms']['S']->id, 'exam_type_id' => $w['waec']->id,
        'status' => 'closed', 'is_ccm' => false, 'min_subjects' => 1,
    ])->curriculumSubjects()->create([
        'subject_id' => Subject::create(['school_id' => $w['school']->id, 'name' => 'Y11 Only'])->id,
        'is_compulsory' => true,
    ]);

    // Decoy 2: the right level and session, the WRONG exam type.
    mny_priorYear($w, 'B', [['name' => 'BSS Only', 'compulsory' => true, 'order' => 1]], $w['bss']);

    // Decoy 3: the right level and session, but CCM.
    mny_priorYear($w, 'B', [['name' => 'CCM Only', 'compulsory' => true, 'order' => 1]], null, true);

    $source = mny_source($w, 'B');
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    $destination = mny_landed($w, $student)->curriculum;
    // The destination is non-CCM WAEC Y12B; none of the three decoys matches all four keys.
    expect(mny_subjectNames($destination))->toBe([]);
});

it('seeds from the LATEST term of the closing session, deterministically', function () {
    // Subjects are consistent across a session's terms for a level, but the rule must still be a
    // rule: two candidate terms, different sets, and the later one wins every run.
    $w = mny_world();
    $earlyTerm = mny_term($w['sourceSession'], 1, TermStatusEnum::COMPLETED->value);

    Curriculum::create([
        'school_id' => $w['school']->id, 'term_id' => $earlyTerm->id,
        'class_level_arm_id' => $w['y12Arms']['B']->id, 'exam_type_id' => $w['waec']->id,
        'status' => 'closed', 'is_ccm' => false, 'min_subjects' => 1,
    ])->curriculumSubjects()->create([
        'subject_id' => Subject::create(['school_id' => $w['school']->id, 'name' => 'Term One Subject'])->id,
        'is_compulsory' => true,
    ]);

    // sourceTerm is order 3 — the LATEST term of the closing session.
    mny_priorYear($w, 'B', [['name' => 'Term Three Subject', 'compulsory' => true, 'order' => 1]]);

    $source = mny_source($w, 'B');
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    expect(mny_subjectNames(mny_landed($w, $student)->curriculum))->toBe(['Term Three Subject']);
});

it('never clobbers a destination an operator has already configured', function () {
    // REPAIR ONLY WHILE UNUSED, the same discipline as MoveFromTermJob::canAdoptSourceSchemes. A
    // hand-edited destination is the state this fix must be safest around: the operator has already
    // said what next year teaches.
    $w = mny_world();
    mny_priorYear($w, 'B', [
        ['name' => 'Physics', 'compulsory' => true, 'order' => 1],
        ['name' => 'Chemistry', 'compulsory' => true, 'order' => 2],
    ]);

    $source = mny_source($w, 'B');
    [$student] = mny_enroll($w, $source);

    // The operator built next year's Y12B by hand first, with a DIFFERENT set.
    $targetTerm = Term::withoutGlobalScope(SchoolScope::class)
        ->where('academic_session_id', $w['targetSession']->id)->where('order', 1)->first();
    $destination = Curriculum::create([
        'school_id' => $w['school']->id, 'term_id' => $targetTerm->id,
        'class_level_arm_id' => $w['y12Arms']['B']->id, 'exam_type_id' => $w['waec']->id,
        'status' => 'active', 'is_ccm' => false, 'min_subjects' => 1,
    ]);
    $destination->curriculumSubjects()->create([
        'subject_id' => Subject::create(['school_id' => $w['school']->id, 'name' => 'Economics'])->id,
        'is_compulsory' => true,
        'display_order' => 1,
    ]);

    mny_run($w, $source);

    // Untouched: the operator's single subject, and NOT the prior year's two.
    expect(mny_landed($w, $student)->curriculum_id)->toBe($destination->id);
    expect(mny_subjectNames($destination))->toBe(['Economics']);
});

it('is idempotent — a re-run seeds one set, not two', function () {
    $w = mny_world(['y12Strategy' => 'explicit_only']);
    mny_priorYear($w, 'P', [
        ['name' => 'Physics', 'compulsory' => true, 'order' => 1],
        ['name' => 'Chemistry', 'compulsory' => true, 'order' => 2],
    ]);

    ClassLevelArmProgression::forceCreate([
        'school_id' => $w['school']->id,
        'source_class_level_arm_id' => $w['y11Arms']['H']->id,
        'target_class_level_arm_id' => $w['y12Arms']['P']->id,
    ]);

    $source = mny_source($w, 'H');
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);
    $destination = mny_landed($w, $student)->curriculum;
    expect(mny_subjectNames($destination))->toBe(['Chemistry', 'Physics']);

    // Re-open by hand to model a partial run, so the re-run reaches the body rather than stopping at
    // the guard — the same honest scaffolding the anchor test above uses, and says so.
    $source->update(['status' => 'active']);
    mny_run($w, $source);

    // ONE set, not two. The count is the assertion here because duplication is what it is about.
    expect(CurriculumSubject::where('curriculum_id', $destination->id)->count())->toBe(2);
    expect(mny_subjectNames($destination))->toBe(['Chemistry', 'Physics']);
});

it('seeds one set when two source arms are distributed onto one destination arm', function () {
    // THE CONCURRENCY SHAPE, run sequentially: distribution points several source curricula at one
    // destination, so two jobs reach the same empty destination. firstOrCreate per subject is what
    // makes that converge; a bulk insert would double the set.
    $w = mny_world(['y12Strategy' => 'explicit_only']);
    mny_priorYear($w, 'P', [
        ['name' => 'Physics', 'compulsory' => true, 'order' => 1],
        ['name' => 'Chemistry', 'compulsory' => true, 'order' => 2],
    ]);

    // Both 11H and 11I map onto 12P.
    foreach (['H', 'I'] as $label) {
        ClassLevelArmProgression::forceCreate([
            'school_id' => $w['school']->id,
            'source_class_level_arm_id' => $w['y11Arms'][$label]->id,
            'target_class_level_arm_id' => $w['y12Arms']['P']->id,
        ]);
    }

    $sourceH = mny_source($w, 'H');
    [$pupilH] = mny_enroll($w, $sourceH);
    $sourceI = mny_source($w, 'I');
    [$pupilI] = mny_enroll($w, $sourceI);

    mny_run($w, $sourceH);
    mny_run($w, $sourceI);

    $destination = mny_landed($w, $pupilH)->curriculum;
    // Same destination for both — otherwise this test is not about what it says it is.
    expect(mny_landed($w, $pupilI)->curriculum_id)->toBe($destination->id);

    expect(CurriculumSubject::where('curriculum_id', $destination->id)->count())->toBe(2);
    // Both pupils got the compulsory set, and each subject exactly once.
    foreach ([$pupilH, $pupilI] as $pupil) {
        $landed = mny_landed($w, $pupil);
        expect(StudentSubject::where('student_curriculum_id', $landed->id)->count())->toBe(2);
    }
});

it('the PREVIEW creates no curriculum_subjects — only the commit does', function () {
    // PREVIEW/COMMIT PARITY, asserted as a row count on the table the fix writes to. The resolver is
    // shared between planner and job, so a seeding call placed inside it would make a registrar
    // opening the screen build next year's curricula.
    $w = mny_world();
    mny_priorYear($w, 'B', [
        ['name' => 'Physics', 'compulsory' => true, 'order' => 1],
        ['name' => 'Chemistry', 'compulsory' => true, 'order' => 2],
    ]);

    $source = mny_source($w, 'B');
    mny_enroll($w, $source);

    $subjectsBefore = CurriculumSubject::count();
    $curriculaBefore = Curriculum::withoutGlobalScope(SchoolScope::class)->count();

    app(RolloverPlanner::class)
        ->planEndOfYear($w['sourceSession'], $w['targetSession']);

    // UNCHANGED by the preview — both the destination row and its subjects.
    expect(CurriculumSubject::count())->toBe($subjectsBefore);
    expect(Curriculum::withoutGlobalScope(SchoolScope::class)->count())->toBe($curriculaBefore);

    mny_run($w, $source);

    // CHANGED by the commit — so the assertion above is measuring something that can move.
    expect(CurriculumSubject::count())->toBe($subjectsBefore + 2);
});

it('clones marking components on the legacy path and NOT on the scheme-backed one', function () {
    // THE SCHEME SPLIT, mirroring MoveFromTermJob::cloneCurriculumSubjects exactly. Both arms in one
    // test because the split is the behaviour — either alone reads as "components are/aren't copied".

    // ── LEGACY: no marking scheme anywhere, no grading scheme on Y12 -> components copy.
    $legacy = mny_world();
    $priorLegacy = mny_priorYear($legacy, 'B', [['name' => 'Physics', 'compulsory' => true, 'order' => 1]]);
    $priorLegacy->curriculumSubjects()->first()->markingComponents()->create([
        'name' => 'Exam', 'weight' => 0.700, 'school_id' => $legacy['school']->id, 'is_ccm' => false,
    ]);

    $legacySource = mny_source($legacy, 'B');
    [$legacyPupil] = mny_enroll($legacy, $legacySource);
    mny_run($legacy, $legacySource);

    $legacyDestination = mny_landed($legacy, $legacyPupil)->curriculum;
    expect($legacyDestination->marking_scheme_id)->toBeNull();
    expect($legacyDestination->usesCategoricalGrading())->toBeFalse();
    $legacySubject = CurriculumSubject::where('curriculum_id', $legacyDestination->id)->first();
    expect($legacySubject->markingComponents()->count())->toBe(1);
    expect($legacySubject->markingComponents()->first()->name)->toBe('Exam');

    // ── SCHEME-BACKED: an active marking scheme exists, so the destination resolves one and the
    //    components come THROUGH the scheme rather than being copied per subject.
    $backed = mny_world();
    MarkingScheme::create(['school_id' => $backed['school']->id, 'is_ccm' => false, 'version' => 1, 'status' => 'active']);

    $priorBacked = mny_priorYear($backed, 'B', [['name' => 'Physics', 'compulsory' => true, 'order' => 1]]);
    $priorBacked->curriculumSubjects()->first()->markingComponents()->create([
        'name' => 'Exam', 'weight' => 0.700, 'school_id' => $backed['school']->id, 'is_ccm' => false,
    ]);

    $backedSource = mny_source($backed, 'B');
    [$backedPupil] = mny_enroll($backed, $backedSource);
    mny_run($backed, $backedSource);

    $backedDestination = mny_landed($backed, $backedPupil)->curriculum;
    expect($backedDestination->marking_scheme_id)->not->toBeNull();
    $backedSubject = CurriculumSubject::where('curriculum_id', $backedDestination->id)->first();
    // The subject ROW cloned; its components did NOT.
    expect($backedSubject->subject->name)->toBe('Physics');
    expect($backedSubject->markingComponents()->count())->toBe(0);
});

it('seeds a held repeaters destination from their own levels closing-session curriculum', function () {
    // Repeaters reach the destination through the identical path, so they land subject-less in
    // exactly the same way — the resolver's own docblock says so. The seeding must cover them.
    $w = mny_world();

    // The repeater is held in Y11 B, so the prior instance is the SOURCE curriculum itself.
    $source = mny_source($w, 'B');
    [$repeater] = mny_enroll($w, $source, StudentStatusEnum::REPEATED->value);

    mny_run($w, $source);

    $held = mny_landed($w, $repeater);
    expect($held->curriculum->class_level_arm_id)->toBe($w['y11Arms']['B']->id);
    // mny_source gives the source curriculum one compulsory subject; next year's Y11 B inherits it.
    expect(mny_subjectNames($held->curriculum))->toBe(mny_subjectNames($source));
    expect(StudentSubject::where('student_curriculum_id', $held->id)->count())->toBe(1);
});
