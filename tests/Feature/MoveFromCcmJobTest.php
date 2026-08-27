<?php

use App\Enums\TermStatusEnum;
use App\Jobs\MoveFromCcmJob;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\MarkingComponent;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Score;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

function mfc_classLevelArm(School $school): ClassLevelArm
{
    $classLevel = ClassLevel::create([
        'school_id' => $school->id,
        'name' => 'JSS1',
        'order' => 1,
    ]);

    $arm = Arm::create([
        'school_id' => $school->id,
        'label' => 'Gold',
    ]);

    return ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $classLevel->id,
        'arm_id' => $arm->id,
    ]);
}

function mfc_term(School $school): Term
{
    $session = AcademicSession::create([
        'school_id' => $school->id,
        'name' => 'Test Session',
        'slug' => 'session-'.Str::random(8),
        'is_current' => true,
    ]);

    return Term::create([
        'academic_session_id' => $session->id,
        'school_id' => $session->school_id,
        'name' => 'First Term',
        'slug' => 'term-'.Str::random(8),
        'order' => 1,
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2),
        'status' => TermStatusEnum::ACTIVE->value,
    ]);
}

function mfc_examType(School $school): ExamType
{
    return ExamType::create([
        'school_id' => $school->id,
        'name' => 'Internal Exam',
        'slug' => 'exam-'.Str::random(8),
    ]);
}

function mfc_curriculum(School $school, ClassLevelArm $classLevelArm, Term $term, ExamType $examType, bool $isCcm): Curriculum
{
    return Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $classLevelArm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => $isCcm,
        'min_subjects' => 1,
    ]);
}

function mfc_markingComponent(School $school, ?CurriculumSubject $curriculumSubject, string $name, float $weight, bool $isCcm): MarkingComponent
{
    return MarkingComponent::create([
        'curriculum_subject_id' => $curriculumSubject?->id,
        'school_id' => $school->id,
        'name' => $name,
        'weight' => $weight,
        'is_ccm' => $isCcm,
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('carries scores for overlapping marking components onto the new non-ccm subject', function () {
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);

    $classLevelArm = mfc_classLevelArm($school);
    $term = mfc_term($school);
    $examType = mfc_examType($school);

    // Global (school-wide) marking component templates for the target (non-CCM) curriculum.
    mfc_markingComponent($school, null, 'Continuous Assessment 1', 0.25, false);
    mfc_markingComponent($school, null, 'Half Term Exam', 0.25, false);
    mfc_markingComponent($school, null, 'Continuous Assessment 2', 0.25, false);
    mfc_markingComponent($school, null, 'Examination', 0.25, false);

    $ccmCurriculum = mfc_curriculum($school, $classLevelArm, $term, $examType, true);

    $subject = Subject::create([
        'school_id' => $school->id,
        'name' => 'Mathematics',
    ]);

    $ccmSubject = CurriculumSubject::create([
        'curriculum_id' => $ccmCurriculum->id,
        'subject_id' => $subject->id,
        'is_compulsory' => true,
    ]);

    $ca1 = mfc_markingComponent($school, $ccmSubject, 'Continuous Assessment 1', 0.5, true);
    $halfTerm = mfc_markingComponent($school, $ccmSubject, 'Half Term Exam', 0.5, true);

    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Student',
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    // Creating the enrollment auto-attaches the compulsory $ccmSubject as an
    // active StudentSubject via StudentCurriculumObserver.
    $studentCurriculum = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $ccmCurriculum->id,
        'status' => 'active',
    ]);

    Score::create([
        'student_id' => $student->id,
        'curriculum_subject_id' => $ccmSubject->id,
        'marking_component_id' => $ca1->id,
        'score' => 45.5,
        'created_by' => $admin->id,
    ]);

    Score::create([
        'student_id' => $student->id,
        'curriculum_subject_id' => $ccmSubject->id,
        'marking_component_id' => $halfTerm->id,
        'score' => 40,
        'created_by' => $admin->id,
    ]);

    (new MoveFromCcmJob($ccmCurriculum, $admin->id, (int) $ccmCurriculum->school_id))->handle();

    $targetCurriculum = Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('school_id', $school->id)
        ->where('term_id', $term->id)
        ->where('class_level_arm_id', $classLevelArm->id)
        ->where('exam_type_id', $examType->id)
        ->where('is_ccm', false)
        ->first();

    expect($targetCurriculum)->not->toBeNull();

    $newSubject = CurriculumSubject::where('curriculum_id', $targetCurriculum->id)
        ->where('subject_id', $subject->id)
        ->first();

    expect($newSubject)->not->toBeNull();

    $newComponents = $newSubject->markingComponents()->get()
        ->keyBy(fn (MarkingComponent $component) => Str::lower(trim($component->name)));

    expect($newComponents)->toHaveCount(4);

    $newCa1 = $newComponents->get('continuous assessment 1');
    $newHalfTerm = $newComponents->get('half term exam');
    $newCa2 = $newComponents->get('continuous assessment 2');
    $newExam = $newComponents->get('examination');

    $migratedCa1 = Score::where('student_id', $student->id)
        ->where('marking_component_id', $newCa1->id)
        ->first();

    expect($migratedCa1)->not->toBeNull();
    // Old CA1 was /50 (weight 0.5), new CA1 is /25 (weight 0.25): 45.5 * (0.25/0.5) = 22.75,
    // rounded to the scores table's 1-decimal-place precision -> 22.8.
    expect((float) $migratedCa1->score)->toBe(22.8);
    expect($migratedCa1->curriculum_subject_id)->toBe($newSubject->id);

    $migratedHalfTerm = Score::where('student_id', $student->id)
        ->where('marking_component_id', $newHalfTerm->id)
        ->first();

    expect($migratedHalfTerm)->not->toBeNull();
    // Old Half Term was /50 (weight 0.5), new Half Term is /25 (weight 0.25): 40 * (0.25/0.5) = 20.
    expect((float) $migratedHalfTerm->score)->toBe(20.0);

    // The two non-overlapping (non-CCM-only) components get no migrated score.
    expect(Score::where('marking_component_id', $newCa2->id)->exists())->toBeFalse();
    expect(Score::where('marking_component_id', $newExam->id)->exists())->toBeFalse();

    // Re-running the job is idempotent: no duplicates, no value changes.
    (new MoveFromCcmJob($ccmCurriculum, $admin->id, (int) $ccmCurriculum->school_id))->handle();

    expect(Score::where('marking_component_id', $newCa1->id)->count())->toBe(1);
    expect(Score::where('marking_component_id', $newHalfTerm->id)->count())->toBe(1);

    $migratedCa1->refresh();
    expect((float) $migratedCa1->score)->toBe(22.8);
});

// ── Proof 7b (S1 commit 5) — MoveFromCcm records the promotion LINK, not just the status ──

it('proof 7b — MoveFromCcm sets the source episode promoted_to_id to the new episode, not just status=promoted', function () {
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    $arm = mfc_classLevelArm($school);
    $term = mfc_term($school);
    $examType = mfc_examType($school);
    $ccm = mfc_curriculum($school, $arm, $term, $examType, true);

    $student = Student::create([
        'school_id' => $school->id, 'first_name' => 'Ccm', 'last_name' => Str::random(6),
        'gender' => 'male', 'admission_number' => 'ADM-'.Str::random(8),
    ]);
    $old = StudentCurriculum::create(['student_id' => $student->id, 'curriculum_id' => $ccm->id, 'status' => 'active']);

    (new MoveFromCcmJob($ccm, $admin->id, (int) $ccm->school_id))->handle();

    $target = Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('school_id', $school->id)->where('is_ccm', false)->first();
    $new = StudentCurriculum::withoutGlobalScopes()
        ->where('student_id', $student->id)->where('curriculum_id', $target->id)->first();

    // PLANT: revert 5c (drop promoted_to_id from the :304 update) → the source row is status='promoted' with a
    // NULL link → this reds. Assert the LINK value, not just the status (the status passes before AND after).
    expect($old->fresh()->status->value)->toBe('promoted')
        ->and($old->fresh()->promoted_to_id)->toBe($new->id);
});

// ── Proof 1 (S1 promotion-link closure) — MoveFromCcm never BIRTHS a promoted row (2a) ──

it('proof 1 — migrating a source episode that is already promoted yields an ACTIVE new episode, not promoted', function () {
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    $arm = mfc_classLevelArm($school);
    $term = mfc_term($school);
    $examType = mfc_examType($school);
    $ccm = mfc_curriculum($school, $arm, $term, $examType, true);

    // A separate curriculum whose episode is the link target, so the CCM source can legitimately be
    // 'promoted' WITH a link (the CHECK requires one). Different arm → not the job's auto-resolved target.
    $otherArm = mfc_classLevelArm($school);
    $linkTarget = mfc_curriculum($school, $otherArm, $term, $examType, false);

    $student = Student::create([
        'school_id' => $school->id, 'first_name' => 'Promoted', 'last_name' => Str::random(6),
        'gender' => 'male', 'admission_number' => 'ADM-'.Str::random(8),
    ]);
    $target = StudentCurriculum::create(['student_id' => $student->id, 'curriculum_id' => $linkTarget->id, 'status' => 'active']);
    // The source episode inside the CCM curriculum is already promoted (with its link) before the job runs.
    $source = StudentCurriculum::create([
        'student_id' => $student->id, 'curriculum_id' => $ccm->id, 'status' => 'promoted', 'promoted_to_id' => $target->id,
    ]);

    (new MoveFromCcmJob($ccm, $admin->id, (int) $ccm->school_id))->handle();

    $jobTarget = Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('school_id', $school->id)->where('class_level_arm_id', $arm->id)->where('is_ccm', false)->first();
    $new = StudentCurriculum::withoutGlobalScopes()
        ->where('student_id', $student->id)->where('curriculum_id', $jobTarget->id)->first();

    // PLANT (2a): restore `'status' => $old->status` unconditionally → the job tries to create a
    // promoted-with-NULL row, the CHECK refuses it, handle() throws → this test reds (and, without the CHECK,
    // $new->status would be 'promoted'). Either way it is not the clean 'active' the fix produces.
    expect($new)->not->toBeNull()
        ->and($new->status->value)->toBe('active');
});

// ---------------------------------------------------------------------------
// THE SILENT DROP — a scored CCM component with no non-CCM counterpart
// ---------------------------------------------------------------------------

/**
 * A CCM world where one scored component has no counterpart on the non-CCM side.
 *
 * `mapOverlappingMarkingComponents` matches by NORMALISED NAME and used to return `[]` for a miss,
 * and `migrateScores` only ever queries the components that DID match — so an unmatched component's
 * marks were never even read. The pupil still promoted, the episode still linked, the job still
 * reported success. A silent drop and a clean fold are the same observation at every level above the
 * matcher, which is why the check lives at the miss site.
 *
 * MEASURED BEFORE THIS GUARD WAS WRITTEN: across all 17 folded CCM curricula in production — 310
 * subjects, 11,828 scored component-rows — ZERO were dropped. Not because the matcher is safe: those
 * folds all ran the LEGACY subject-local path, where cloneCurriculumSubjects had copied the
 * components so the names matched by construction. The matcher was handed pre-matched inputs and was
 * never actually exercised. It becomes exercised the moment CCM arrival is configured rather than
 * hand-made, because the CCM and non-CCM marking schemes are then two independently-editable objects.
 */
function mfc_ccm_world_with_unmatched_component(bool $giveTargetACounterpart): array
{
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    $classLevelArm = mfc_classLevelArm($school);
    $term = mfc_term($school);
    $examType = mfc_examType($school);

    // The TARGET (non-CCM) templates. "Project" is present only when the arm wants a match.
    mfc_markingComponent($school, null, 'Continuous Assessment 1', 0.5, false);
    if ($giveTargetACounterpart) {
        mfc_markingComponent($school, null, 'Project', 0.5, false);
    }

    $ccmCurriculum = mfc_curriculum($school, $classLevelArm, $term, $examType, true);
    $subject = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics']);
    $ccmSubject = CurriculumSubject::create([
        'curriculum_id' => $ccmCurriculum->id, 'subject_id' => $subject->id, 'is_compulsory' => true,
    ]);

    $ca1 = mfc_markingComponent($school, $ccmSubject, 'Continuous Assessment 1', 0.5, true);
    // CCM-ONLY. No non-CCM template of this name unless the arm asked for one.
    $project = mfc_markingComponent($school, $ccmSubject, 'Project', 0.5, true);

    $student = Student::create([
        'school_id' => $school->id, 'first_name' => 'Scored', 'last_name' => Str::random(6),
        'gender' => 'female', 'admission_number' => 'ADM-'.Str::random(8),
    ]);
    StudentCurriculum::create([
        'student_id' => $student->id, 'curriculum_id' => $ccmCurriculum->id, 'status' => 'active',
    ]);

    foreach ([[$ca1, 40], [$project, 30]] as [$component, $mark]) {
        Score::create([
            'student_id' => $student->id, 'curriculum_subject_id' => $ccmSubject->id,
            'marking_component_id' => $component->id, 'score' => $mark, 'created_by' => $admin->id,
        ]);
    }

    return compact('school', 'admin', 'ccmCurriculum', 'student', 'ccmSubject', 'project');
}

it('refuses the fold when a scored component would be dropped, and names it', function () {
    $w = mfc_ccm_world_with_unmatched_component(giveTargetACounterpart: false);

    expect(fn () => (new MoveFromCcmJob($w['ccmCurriculum'], $w['admin']->id, (int) $w['school']->id))->handle())
        ->toThrow(RuntimeException::class, 'Project');

    // NOTHING PARTIALLY DONE. The job runs inside DB::transaction, so a throw must leave the source
    // OPEN — a closed source with marks not carried is the unrecoverable half of this defect.
    expect($w['ccmCurriculum']->fresh()->status)->toBe('active')
        ->and($w['ccmCurriculum']->fresh()->is_ccm)->toBeTrue();

    // And the pupil has not been promoted out of a fold that did not happen.
    expect(StudentCurriculum::withoutGlobalScopes()
        ->where('student_id', $w['student']->id)->where('status', 'promoted')->count())->toBe(0);
});

it('names the CLASS and the SUBJECT in the refusal, with the ids trailing', function () {
    $w = mfc_ccm_world_with_unmatched_component(giveTargetACounterpart: false);

    // ── THE ARM THAT DID NOT EXIST ─────────────────────────────────────────────────────────────
    // The message changed from `curriculum#N` / `subject#N` to the operator's vocabulary and the
    // WHOLE SUITE STAYED GREEN. CcmFoldSurfaceTest's exact-string arm hand-writes its own message
    // into failed_jobs — it pins the panel's READ path, never the job's output — and the arms here
    // matched on the component name, which survived the change. So the sentence an operator acts on
    // had no test at its source, which is how it shipped naming ids in the first place.
    $message = null;

    try {
        (new MoveFromCcmJob($w['ccmCurriculum'], $w['admin']->id, (int) $w['school']->id))->handle();
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBeNull()
        // LEADS with the class as the gate names it — mfc_classLevelArm builds JSS1 + Gold.
        ->and($message)->toStartWith('Refusing to fold JSS1 Gold:')
        // and names the SUBJECT, which is the half nothing on the screen could otherwise supply.
        ->and($message)->toContain('on Mathematics ')
        // The ids are kept for whoever reads failed_jobs, but they TRAIL — they do not lead.
        ->and($message)->toEndWith('(curriculum#'.$w['ccmCurriculum']->id.', subject#'.$w['ccmSubject']->subject_id.')')
        // And the diagnosable parts survive: the component and its score count are what make the
        // refusal actionable, so a change that "humanised" the message by dropping them would red.
        ->and($message)->toContain('"Project" (1 score(s))');
});

it('folds normally once the non-CCM side has a matching component', function () {
    $w = mfc_ccm_world_with_unmatched_component(giveTargetACounterpart: true);

    (new MoveFromCcmJob($w['ccmCurriculum'], $w['admin']->id, (int) $w['school']->id))->handle();

    // THE POSITIVE ARM, and it is what stops the guard from being a blanket refusal: the same
    // fixture, one component added to the target, folds cleanly. Without this a guard that always
    // threw would pass the arm above.
    expect($w['ccmCurriculum']->fresh()->status)->toBe('closed')
        ->and(StudentCurriculum::withoutGlobalScopes()
            ->where('student_id', $w['student']->id)->where('status', 'promoted')->count())->toBe(1);

    // The previously-droppable mark actually arrived, rescaled 0.5 -> 0.5, i.e. unchanged.
    $target = Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('school_id', $w['school']->id)->where('is_ccm', false)->first();
    $newSubject = CurriculumSubject::where('curriculum_id', $target->id)->first();
    $carried = Score::where('curriculum_subject_id', $newSubject->id)->pluck('score')->map(fn ($s) => (float) $s);

    expect($carried)->toHaveCount(2)->and($carried->sort()->values()->all())->toBe([30.0, 40.0]);
});

it('ignores an unmatched component that carries no marks', function () {
    $w = mfc_ccm_world_with_unmatched_component(giveTargetACounterpart: false);

    // The SAME unmatched component, with its scores removed. An unmatched component is only a
    // problem when it carries data — two schemes that merely differ are ordinary, and a guard that
    // refused on shape rather than on loss would block every legitimate fold.
    Score::where('marking_component_id', $w['project']->id)->delete();

    (new MoveFromCcmJob($w['ccmCurriculum'], $w['admin']->id, (int) $w['school']->id))->handle();

    expect($w['ccmCurriculum']->fresh()->status)->toBe('closed');
});
