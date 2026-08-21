<?php

use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Jobs\MoveFromTermJob;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\GradingScheme;
use App\Models\MarkingComponent;
use App\Models\MarkingScheme;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

function mft_session(School $school): AcademicSession
{
    return AcademicSession::create([
        'school_id' => $school->id,
        'name' => 'Test Session',
        'slug' => 'session-'.Str::random(8),
        'is_current' => true,
    ]);
}

function mft_term(AcademicSession $session, int $order, string $status = 'upcoming'): Term
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

/**
 * @param  list<int>  $participatingSlots  term orders this class level runs
 * @param  list<int>  $ccmSlots  which of those are the CCM variant
 */
function mft_world(array $participatingSlots = [1, 2], array $ccmSlots = [], ?string $gradingScheme = null): array
{
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);

    $classLevel = ClassLevel::create([
        'school_id' => $school->id,
        'name' => 'JSS1',
        'order' => 1,
    ]);

    $arm = ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $classLevel->id,
        'arm_id' => Arm::create(['school_id' => $school->id, 'label' => 'Gold'])->id,
    ]);

    foreach ($participatingSlots as $slot) {
        ClassLevelTermParticipation::forceCreate([
            'school_id' => $school->id,
            'class_level_id' => $classLevel->id,
            'term_order' => $slot,
            'is_ccm' => in_array($slot, $ccmSlots, true),
        ]);
    }

    $session = mft_session($school);
    $sourceTerm = mft_term($session, 1, TermStatusEnum::ACTIVE->value);

    $examType = ExamType::create([
        'school_id' => $school->id,
        'name' => 'Internal Exam',
        'slug' => 'exam-'.Str::random(8),
    ]);

    $scheme = $gradingScheme === null ? null : GradingScheme::create([
        'school_id' => $school->id,
        'name' => $gradingScheme,
    ]);

    $source = Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $sourceTerm->id,
        'class_level_arm_id' => $arm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
        'grading_scheme_id' => $scheme?->id,
    ]);

    $compulsory = CurriculumSubject::create([
        'curriculum_id' => $source->id,
        'subject_id' => Subject::create(['school_id' => $school->id, 'name' => 'Mathematics'])->id,
        'is_compulsory' => true,
    ]);
    MarkingComponent::create([
        'curriculum_subject_id' => $compulsory->id,
        'school_id' => $school->id,
        'name' => 'Examination',
        'weight' => 0.7,
        'is_ccm' => false,
    ]);

    // An OPTIONAL subject, present so the carry-over can be asserted — the observer's own
    // carry-over is auth()-gated and no-ops in a job, so the job must clone this itself.
    $optional = CurriculumSubject::create([
        'curriculum_id' => $source->id,
        'subject_id' => Subject::create(['school_id' => $school->id, 'name' => 'Fine Art'])->id,
        'is_compulsory' => false,
    ]);

    return compact('school', 'admin', 'classLevel', 'arm', 'session', 'sourceTerm', 'examType', 'source', 'compulsory', 'optional');
}

function mft_enroll(array $w, string $status = 'active', bool $withOptional = true): array
{
    $student = Student::create([
        'school_id' => $w['school']->id,
        'first_name' => 'Student',
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $w['source']->id,
        'status' => $status,
    ]);

    if ($withOptional) {
        StudentSubject::firstOrCreate([
            'student_curriculum_id' => $enrollment->id,
            'curriculum_subject_id' => $w['optional']->id,
        ], ['status' => 'active']);
    }

    return [$student, $enrollment];
}

function mft_run(array $w): void
{
    (new MoveFromTermJob($w['source'], $w['admin']->id, (int) $w['school']->id))->handle();
}

function mft_target(array $w, int $termOrder): ?Curriculum
{
    $term = Term::withoutGlobalScope(SchoolScope::class)
        ->where('academic_session_id', $w['session']->id)
        ->where('order', $termOrder)
        ->first();

    if ($term === null) {
        return null;
    }

    return Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('school_id', $w['school']->id)
        ->where('term_id', $term->id)
        ->where('class_level_arm_id', $w['arm']->id)
        ->first();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('carries the roster into the next participating slot, opens the target active and closes the source', function () {
    $w = mft_world([1, 2]);
    mft_term($w['session'], 2);
    [$student, $sourceEnrollment] = mft_enroll($w);

    mft_run($w);

    $target = mft_target($w, 2);
    expect($target)->not->toBeNull();
    // Forward move: 'active', NOT BackfillPastTermJob's hardcoded 'closed'.
    expect($target->status)->toBe('active');
    expect((bool) $target->is_ccm)->toBeFalse();
    expect($target->exam_type_id)->toBe($w['source']->exam_type_id);

    // Source closed, so the orchestrator will not re-select it.
    expect($w['source']->fresh()->status)->toBe('closed');

    // Link AND status together. NOT because "the CHECK rejects a NULL link" — that CHECK is inert on
    // production's MySQL 5.7 — but because this job writing both in one update is the only thing
    // holding the invariant there. See the dedicated test below.
    $carried = StudentCurriculum::where('student_id', $student->id)
        ->where('curriculum_id', $target->id)
        ->first();
    expect($carried)->not->toBeNull();
    expect($carried->status)->toBe(StudentStatusEnum::ACTIVE);
    expect($sourceEnrollment->fresh()->status)->toBe(StudentStatusEnum::PROMOTED);
    expect($sourceEnrollment->fresh()->promoted_to_id)->toBe($carried->id);
});

it('skips a non-participating slot rather than stopping at it', function () {
    // The class runs slots 1 and 3 — never 2. `order + 1` would stop dead here.
    $w = mft_world([1, 3]);
    mft_term($w['session'], 2);
    $termThree = mft_term($w['session'], 3);
    mft_enroll($w);

    mft_run($w);

    expect(mft_target($w, 2))->toBeNull();
    $target = mft_target($w, 3);
    expect($target)->not->toBeNull();
    expect($target->term_id)->toBe($termThree->id);
});

it('no-ops when the class level has no later participating slot', function () {
    $w = mft_world([1]);
    mft_term($w['session'], 2);
    mft_enroll($w);

    mft_run($w);

    expect(mft_target($w, 2))->toBeNull();
    // A no-op must not close the source — nothing was carried anywhere.
    expect($w['source']->fresh()->status)->toBe('active');
});

it('no-ops and never creates a Term when the next slot has no Term row yet', function () {
    $w = mft_world([1, 2]);
    // Deliberately NO term 2 row — next year's calendar is not entered yet.
    mft_enroll($w);

    $termsBefore = Term::withoutGlobalScope(SchoolScope::class)->count();

    mft_run($w);

    expect(Term::withoutGlobalScope(SchoolScope::class)->count())->toBe($termsBefore);
    expect($w['source']->fresh()->status)->toBe('active');
});

it('takes the target is_ccm from config, not from the source', function () {
    // Source is non-CCM; the class enters the CCM variant of slot 2.
    $w = mft_world([1, 2], ccmSlots: [2]);
    mft_term($w['session'], 2);
    mft_enroll($w);

    mft_run($w);

    $target = mft_target($w, 2);
    expect($target)->not->toBeNull();
    expect((bool) $target->is_ccm)->toBeTrue();
});

it('refuses a CCM source, so the CCM move cannot be skipped', function () {
    $w = mft_world([1, 2]);
    mft_term($w['session'], 2);
    $w['source']->update(['is_ccm' => true]);
    mft_enroll($w);

    mft_run($w);

    expect(mft_target($w, 2))->toBeNull();
    expect($w['source']->fresh()->status)->toBe('active');
});

it('carries the grading scheme, so a categorical class does not silently become numeric', function () {
    // The regression that matters most: grading_mode derives from grading_scheme_id, so dropping it
    // does not error — it changes what the results ARE. MoveFromCcmJob's resolver would drop it.
    $w = mft_world([1, 2], gradingScheme: 'Early Years Ratings');
    mft_term($w['session'], 2);
    mft_enroll($w);

    mft_run($w);

    $target = mft_target($w, 2);
    expect($target->grading_scheme_id)->toBe($w['source']->grading_scheme_id);
    expect($target->grading_scheme_id)->not->toBeNull();
    expect($target->usesCategoricalGrading())->toBeTrue();
});

it('clones optional subject selections, which the observer cannot do in a queued job', function () {
    $w = mft_world([1, 2]);
    mft_term($w['session'], 2);
    [$student] = mft_enroll($w, withOptional: true);

    mft_run($w);

    $target = mft_target($w, 2);
    $carried = StudentCurriculum::where('student_id', $student->id)
        ->where('curriculum_id', $target->id)
        ->first();

    $carriedSubjectNames = StudentSubject::where('student_curriculum_id', $carried->id)
        ->get()
        ->map(fn ($s) => $s->curriculumSubject->subject->name)
        ->sort()
        ->values()
        ->all();

    expect($carriedSubjectNames)->toBe(['Fine Art', 'Mathematics']);
});

it('excludes withdrawn pupils and lands promoted and repeated as active', function () {
    $w = mft_world([1, 2]);
    mft_term($w['session'], 2);

    [$withdrawn, $withdrawnEnrollment] = mft_enroll($w, StudentStatusEnum::WITHDRAWN->value);
    [$repeated] = mft_enroll($w, StudentStatusEnum::REPEATED->value);
    [$active] = mft_enroll($w, StudentStatusEnum::ACTIVE->value);

    mft_run($w);

    $target = mft_target($w, 2);

    // Withdrawn: not carried, and its source episode is NOT marked promoted.
    expect(StudentCurriculum::where('student_id', $withdrawn->id)->where('curriculum_id', $target->id)->exists())->toBeFalse();
    expect($withdrawnEnrollment->fresh()->status)->toBe(StudentStatusEnum::WITHDRAWN);

    // repeated -> active. Inheriting 'repeated' would mint a row softEnd() treats as terminal while
    // ended_at is NULL — "ended but reads as current".
    $repeatedCarried = StudentCurriculum::where('student_id', $repeated->id)->where('curriculum_id', $target->id)->first();
    expect($repeatedCarried->status)->toBe(StudentStatusEnum::ACTIVE);
    expect($repeatedCarried->ended_at)->toBeNull();

    $activeCarried = StudentCurriculum::where('student_id', $active->id)->where('curriculum_id', $target->id)->first();
    expect($activeCarried->status)->toBe(StudentStatusEnum::ACTIVE);
});

/**
 * This proves the GUARD, not the firstOrCreate convergence. The first run closes the source inside
 * the same transaction, so the re-run aborts at passesGuards() before reaching any write — which is
 * genuinely idempotent, but by a different mechanism than "firstOrCreate found the existing rows".
 * Named explicitly so the green is not read as evidence for the anchor it does not exercise.
 */
it('is idempotent — a second run creates no duplicate curriculum, episodes or subjects', function () {
    $w = mft_world([1, 2]);
    mft_term($w['session'], 2);
    [$student] = mft_enroll($w);

    mft_run($w);

    $target = mft_target($w, 2);
    $curriculaAfterFirst = Curriculum::withoutGlobalScope(SchoolScope::class)->count();
    $episodesAfterFirst = StudentCurriculum::count();
    $subjectsAfterFirst = StudentSubject::where('student_curriculum_id',
        StudentCurriculum::where('student_id', $student->id)->where('curriculum_id', $target->id)->value('id')
    )->count();

    // Re-run the ORIGINAL instance, exactly as a retried queue message would.
    mft_run($w);

    expect(Curriculum::withoutGlobalScope(SchoolScope::class)->count())->toBe($curriculaAfterFirst);
    expect(StudentCurriculum::count())->toBe($episodesAfterFirst);
    expect(StudentSubject::where('student_curriculum_id',
        StudentCurriculum::where('student_id', $student->id)->where('curriculum_id', $target->id)->value('id')
    )->count())->toBe($subjectsAfterFirst);
});

/**
 * SCHEME-BACKED FIXTURES. Every other fixture in this file leaves `marking_scheme_id` NULL and uses
 * subject-local MarkingComponents — the LEGACY path. That left the entire scheme-backed branch of
 * resolveTargetCurriculum unasserted, which is how a CCM mis-scheme survived review: the `is_ccm`
 * test above checks the flag, not the scheme behind it.
 */
function mft_markingScheme(array $w, bool $isCcm, int $version = 1): MarkingScheme
{
    return MarkingScheme::create([
        'school_id' => $w['school']->id,
        'is_ccm' => $isCcm,
        'version' => $version,
        'status' => 'active',
    ]);
}

it('carries the marking scheme across when the target is NON-CCM', function () {
    $w = mft_world([1, 2]);
    mft_term($w['session'], 2);
    $scheme = mft_markingScheme($w, isCcm: false);
    $w['source']->update(['marking_scheme_id' => $scheme->id]);
    mft_enroll($w);

    mft_run($w);

    $target = mft_target($w, 2);
    // The SOURCE's scheme, not the latest — a class keeps the exact version it has been marked
    // against rather than jumping to a newer one mid-session.
    expect($target->marking_scheme_id)->toBe($scheme->id);
});

it('gives a CCM target a CCM marking scheme, never the non-CCM one it came from', function () {
    // THE BUG THIS TURNS RED. The source is always non-CCM (guarded), so its marking scheme is the
    // FULL-TERM one. Copying it onto a CCM target stamps half-term work with full-term weights — and
    // silently, because cloneCurriculumSubjects skips the subject-local component copy whenever
    // marking_scheme_id is set, so every component then resolves through the wrong scheme.
    $w = mft_world([1, 2], ccmSlots: [2]);
    mft_term($w['session'], 2);
    $nonCcmScheme = mft_markingScheme($w, isCcm: false);
    $ccmScheme = mft_markingScheme($w, isCcm: true);
    $w['source']->update(['marking_scheme_id' => $nonCcmScheme->id]);
    mft_enroll($w);

    mft_run($w);

    $target = mft_target($w, 2);
    expect((bool) $target->is_ccm)->toBeTrue();
    expect($target->marking_scheme_id)->toBe($ccmScheme->id);
    expect($target->marking_scheme_id)->not->toBe($nonCcmScheme->id);
});

it('drops a CCM target onto the legacy component path when the school has no CCM scheme', function () {
    // NULL is a legitimate answer, not a failure: cloneCurriculumSubjects then copies subject-local
    // components and stamps them is_ccm = the target's, which is already correct for CCM.
    $w = mft_world([1, 2], ccmSlots: [2]);
    mft_term($w['session'], 2);
    $nonCcmScheme = mft_markingScheme($w, isCcm: false);
    $w['source']->update(['marking_scheme_id' => $nonCcmScheme->id]);
    mft_enroll($w);

    mft_run($w);

    $target = mft_target($w, 2);
    expect($target->marking_scheme_id)->toBeNull();

    $newSubject = CurriculumSubject::where('curriculum_id', $target->id)
        ->where('subject_id', $w['compulsory']->subject_id)
        ->first();
    $components = $newSubject->markingComponents()->get();
    expect($components)->toHaveCount(1);
    expect((bool) $components[0]->is_ccm)->toBeTrue();
});

it('refuses a completed target term rather than reaching backward into backfill territory', function () {
    $w = mft_world([1, 2]);
    mft_term($w['session'], 2, TermStatusEnum::COMPLETED->value);
    mft_enroll($w);

    mft_run($w);

    expect(mft_target($w, 2))->toBeNull();
    expect($w['source']->fresh()->status)->toBe('active');
});

/**
 * THE PRODUCTION-MEANINGFUL PROMOTION-LINK ASSERTION.
 *
 * student_curricula_promoted_requires_link is a CHECK, and production is MySQL 5.7.23 where CHECK is
 * parsed and ignored — so asserting "the database rejects a promoted row with a NULL link" would
 * prove something true only on a developer's 8.0 machine. What actually holds the invariant on
 * production is this job writing status and promoted_to_id in ONE update. That is an application
 * fact, so it is asserted at the application level, here.
 */
it('leaves no promoted episode without its link — the invariant the CHECK cannot carry on 5.7', function () {
    $w = mft_world([1, 2]);
    mft_term($w['session'], 2);
    mft_enroll($w);
    mft_enroll($w);
    mft_enroll($w, StudentStatusEnum::REPEATED->value);

    mft_run($w);

    $danglingPromoted = StudentCurriculum::where('status', StudentStatusEnum::PROMOTED->value)
        ->whereNull('promoted_to_id')
        ->count();

    expect($danglingPromoted)->toBe(0);

    // And every link resolves to a LIVE episode of the SAME pupil — the thing the composite FK
    // guarantees structurally and which therefore must hold in the data too.
    $promoted = StudentCurriculum::where('status', StudentStatusEnum::PROMOTED->value)->get();
    expect($promoted)->toHaveCount(3);

    foreach ($promoted as $episode) {
        $targetEpisode = StudentCurriculum::find($episode->promoted_to_id);
        expect($targetEpisode)->not->toBeNull();
        expect($targetEpisode->student_id)->toBe($episode->student_id);
    }
});

/**
 * The composite FK (promoted_to_id, student_id, school_id) DOES enforce on 5.7, unlike the CHECK
 * beside it — so it is worth bite-proving that it bites, rather than assuming.
 */
it('cannot link a promotion to another pupil episode — composite FK, enforced on 5.7 too', function () {
    $w = mft_world([1, 2]);
    mft_term($w['session'], 2);
    [, $enrollmentA] = mft_enroll($w);
    [, $enrollmentB] = mft_enroll($w);

    mft_run($w);

    // B's carried episode belongs to pupil B; pointing A's episode at it must be refused.
    $bCarried = StudentCurriculum::find($enrollmentB->fresh()->promoted_to_id);

    expect(fn () => DB::table('student_curricula')
        ->where('id', $enrollmentA->id)
        ->update(['promoted_to_id' => $bCarried->id])
    )->toThrow(QueryException::class);
});

it('refuses a curriculum whose school does not match the declared schoolId', function () {
    $w = mft_world([1, 2]);
    mft_term($w['session'], 2);
    mft_enroll($w);

    (new MoveFromTermJob($w['source'], $w['admin']->id, (int) $w['school']->id + 999))->handle();

    expect(mft_target($w, 2))->toBeNull();
    expect($w['source']->fresh()->status)->toBe('active');
});
