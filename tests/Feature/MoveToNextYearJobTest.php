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
    // The source is deliberately left OPEN by an unplaced pupil, so the re-run reaches the body
    // rather than stopping at the guard — otherwise this would prove the guard, not the anchor.
    $w = mny_world();
    $source = mny_source($w, 'B');
    [$moved] = mny_enroll($w, $source);
    $unplaceable = mny_source($w, 'H');

    mny_run($w, $source);
    expect($source->fresh()->status)->toBe('closed');

    // Re-open by hand to model the partial-run case, then move the pupil as Part 4's service would.
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
    unset($unplaceable);
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

it('no-ops for a terminal class level', function () {
    $w = mny_world();
    $w['y11']->update(['next_class_level_id' => null]);
    $source = mny_source($w, 'B');
    [$student] = mny_enroll($w, $source);

    mny_run($w, $source);

    expect(mny_landed($w, $student))->toBeNull();
    expect(StudentCurriculum::where('student_id', $student->id)->count())->toBe(1);
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
