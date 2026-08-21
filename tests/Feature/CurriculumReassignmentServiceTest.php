<?php

use App\Enums\StudentStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Services\CurriculumReassignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------

function crs_arm(School $school, ClassLevel $level, string $label): ClassLevelArm
{
    return ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $level->id,
        'arm_id' => Arm::firstOrCreate(['school_id' => $school->id, 'label' => $label])->id,
    ]);
}

function crs_curriculum(School $school, ClassLevelArm $arm, ExamType $examType): Curriculum
{
    $curriculum = Curriculum::create([
        'school_id' => $school->id,
        'term_id' => null,
        'class_level_arm_id' => $arm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
    ]);

    CurriculumSubject::create([
        'curriculum_id' => $curriculum->id,
        'subject_id' => Subject::create(['school_id' => $school->id, 'name' => 'Subj '.Str::random(5)])->id,
        'is_compulsory' => true,
    ]);

    return $curriculum;
}

function crs_world(): array
{
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    $examType = ExamType::create([
        'school_id' => $school->id,
        'name' => 'Internal',
        'slug' => 'et-'.Str::random(8),
    ]);

    $level = ClassLevel::forceCreate(['school_id' => $school->id, 'name' => 'Year 8', 'order' => 8]);
    $armB = crs_arm($school, $level, 'B');
    $armS = crs_arm($school, $level, 'S');

    $curriculumB = crs_curriculum($school, $armB, $examType);
    $curriculumS = crs_curriculum($school, $armS, $examType);

    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Pupil',
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    return compact('school', 'admin', 'examType', 'level', 'armB', 'armS', 'curriculumB', 'curriculumS', 'student');
}

/** A promoted source pointing at a live episode — the shape the jobs leave behind. */
function crs_promotedInto(array $w, Curriculum $target): array
{
    $prevLevel = ClassLevel::forceCreate(['school_id' => $w['school']->id, 'name' => 'Year 7', 'order' => 7]);
    $prevCurriculum = crs_curriculum($w['school'], crs_arm($w['school'], $prevLevel, 'B'), $w['examType']);

    $episode = StudentCurriculum::create([
        'student_id' => $w['student']->id,
        'curriculum_id' => $target->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]);

    $source = StudentCurriculum::create([
        'student_id' => $w['student']->id,
        'curriculum_id' => $prevCurriculum->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]);
    // Link and status in ONE update — the shape every job writes.
    $source->update(['status' => StudentStatusEnum::PROMOTED, 'promoted_to_id' => $episode->id]);

    return [$source, $episode];
}

function crs_service(): CurriculumReassignmentService
{
    return app(CurriculumReassignmentService::class);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('moves the pupil to the new curriculum and ends the vacated episode as transferred', function () {
    $w = crs_world();
    [, $episode] = crs_promotedInto($w, $w['curriculumB']);

    $destination = crs_service()->reassign($episode, $w['curriculumS'], $w['admin'], 'wrong arm');

    expect($destination->curriculum_id)->toBe($w['curriculumS']->id);
    expect($destination->status)->toBe(StudentStatusEnum::ACTIVE);
    expect($destination->ended_at)->toBeNull();

    // The vacated episode: transferred, NOT withdrawn — the pupil did not leave the school.
    $vacated = $episode->fresh();
    expect($vacated->status)->toBe(StudentStatusEnum::TRANSFERRED);
    expect($vacated->ended_at)->not->toBeNull();
    expect($vacated->ended_by_user_id)->toBe($w['admin']->id);
    expect($vacated->end_reason)->toBe('wrong arm');
});

it('repoints the SOURCE episodes promotion link at the new destination', function () {
    // Asserts the SOURCE's pointer moved — not merely that a destination exists, which would pass
    // even if the chain were left dangling at the vacated episode.
    $w = crs_world();
    [$source, $episode] = crs_promotedInto($w, $w['curriculumB']);

    expect($source->fresh()->promoted_to_id)->toBe($episode->id); // precondition, stated

    $destination = crs_service()->reassign($episode, $w['curriculumS'], $w['admin']);

    expect($source->fresh()->promoted_to_id)->toBe($destination->id);
    expect($source->fresh()->promoted_to_id)->not->toBe($episode->id);
    // And the source is still a promotion — the repoint moved the link, it did not rewrite the status.
    expect($source->fresh()->status)->toBe(StudentStatusEnum::PROMOTED);
});

it('leaves a held repeater with NO incoming link — nothing is repointed', function () {
    // A held repeater's source carries a NULL link by design (MoveToNextYearJob keeps promoted_to_id
    // meaning "was promoted"). Asserts the ABSENCE of a repoint, which is the behaviour that would
    // break if the repoint were made unconditional or the link were derived rather than passed.
    $w = crs_world();
    $prevLevel = ClassLevel::forceCreate(['school_id' => $w['school']->id, 'name' => 'Year 7', 'order' => 7]);
    $prevCurriculum = crs_curriculum($w['school'], crs_arm($w['school'], $prevLevel, 'B'), $w['examType']);

    $repeatedSource = StudentCurriculum::create([
        'student_id' => $w['student']->id,
        'curriculum_id' => $prevCurriculum->id,
        'status' => StudentStatusEnum::REPEATED,
    ]);
    $held = StudentCurriculum::create([
        'student_id' => $w['student']->id,
        'curriculum_id' => $w['curriculumB']->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]);

    $destination = crs_service()->reassign($held, $w['curriculumS'], $w['admin']);

    expect($destination->curriculum_id)->toBe($w['curriculumS']->id);
    // Untouched: still repeated, still no link.
    expect($repeatedSource->fresh()->status)->toBe(StudentStatusEnum::REPEATED);
    expect($repeatedSource->fresh()->promoted_to_id)->toBeNull();
});

it('revives the pupils earlier episode when they move back, instead of colliding with the UNIQUE', function () {
    // REACHES THE UNIQUE. The pupil genuinely returns to a curriculum they were soft-ended out of, so
    // a blind create would violate (student_id, curriculum_id) — the trap enroll()'s
    // whereNull('ended_at') guard walks straight into. Asserts ONE episode, revived, not two.
    $w = crs_world();
    [, $episode] = crs_promotedInto($w, $w['curriculumB']);

    $inS = crs_service()->reassign($episode, $w['curriculumS'], $w['admin'], 'first move');
    $backInB = crs_service()->reassign($inS, $w['curriculumB'], $w['admin'], 'moved back');

    // The original B episode is the SAME ROW, revived — not a second one.
    expect($backInB->id)->toBe($episode->id);
    expect(StudentCurriculum::where('student_id', $w['student']->id)
        ->where('curriculum_id', $w['curriculumB']->id)->count())->toBe(1);

    // Revived means genuinely current again — every end-marker cleared, not just the status.
    expect($backInB->status)->toBe(StudentStatusEnum::ACTIVE);
    expect($backInB->ended_at)->toBeNull();
    expect($backInB->ended_by_user_id)->toBeNull();
    expect($backInB->end_reason)->toBeNull();

    // And S is now the vacated one.
    expect($inS->fresh()->status)->toBe(StudentStatusEnum::TRANSFERRED);
});

it('resurrects the earlier stints subjects on revive, which is why marks must not be in play', function () {
    // The documented consequence, asserted rather than left in prose: reviving reuses the SAME row,
    // so its student_subjects come back with it.
    $w = crs_world();
    [, $episode] = crs_promotedInto($w, $w['curriculumB']);

    $subjectIdsBefore = StudentSubject::where('student_curriculum_id', $episode->id)
        ->pluck('curriculum_subject_id')->sort()->values()->all();
    expect($subjectIdsBefore)->not->toBeEmpty();

    $inS = crs_service()->reassign($episode, $w['curriculumS'], $w['admin']);
    $backInB = crs_service()->reassign($inS, $w['curriculumB'], $w['admin']);

    $subjectIdsAfter = StudentSubject::where('student_curriculum_id', $backInB->id)
        ->pluck('curriculum_subject_id')->sort()->values()->all();

    expect($subjectIdsAfter)->toBe($subjectIdsBefore);
});

it('is a no-op when the pupil is already in the target curriculum', function () {
    // Asserts the MECHANISM, not "no error": nothing ended, nothing created, the link unmoved. The
    // naive implementation soft-ends then revives the same episode, churning ended_at.
    $w = crs_world();
    [$source, $episode] = crs_promotedInto($w, $w['curriculumB']);

    $episodeCount = StudentCurriculum::count();
    $linkBefore = $source->fresh()->promoted_to_id;

    $result = crs_service()->reassign($episode, $w['curriculumB'], $w['admin']);

    expect($result->id)->toBe($episode->id);
    expect(StudentCurriculum::count())->toBe($episodeCount);
    expect($episode->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
    expect($episode->fresh()->ended_at)->toBeNull();
    expect($source->fresh()->promoted_to_id)->toBe($linkBefore);
});

it('is a no-op on a re-run of a completed move, rather than throwing on the ended episode', function () {
    // softEnd() throws on an already-ended episode, so without the guard a repeated request would
    // 500 instead of no-opping. Asserts counts and the vacated episode's end-marker are untouched.
    $w = crs_world();
    [, $episode] = crs_promotedInto($w, $w['curriculumB']);

    $destination = crs_service()->reassign($episode, $w['curriculumS'], $w['admin']);
    $endedAt = $episode->fresh()->ended_at;
    $episodeCount = StudentCurriculum::count();

    // The same request again, with the now-stale episode the caller still holds.
    $again = crs_service()->reassign($episode->fresh(), $w['curriculumS'], $w['admin']);

    expect($again->id)->toBe($destination->id);
    expect(StudentCurriculum::count())->toBe($episodeCount);
    expect($episode->fresh()->ended_at->toDateTimeString())->toBe($endedAt->toDateTimeString());
    expect($episode->fresh()->status)->toBe(StudentStatusEnum::TRANSFERRED);
});

it('refuses a target curriculum in another school before writing anything', function () {
    $w = crs_world();
    [, $episode] = crs_promotedInto($w, $w['curriculumB']);

    $otherSchool = al_makeSchool();
    $otherLevel = ClassLevel::forceCreate(['school_id' => $otherSchool->id, 'name' => 'Year 8', 'order' => 8]);
    $otherCurriculum = crs_curriculum(
        $otherSchool,
        crs_arm($otherSchool, $otherLevel, 'B'),
        ExamType::create(['school_id' => $otherSchool->id, 'name' => 'Other', 'slug' => 'et-'.Str::random(8)])
    );

    $episodeCount = StudentCurriculum::count();

    expect(fn () => crs_service()->reassign($episode, $otherCurriculum, $w['admin']))
        ->toThrow(BusinessRuleException::class);

    // Nothing written — the refusal is before the transaction, not rolled back after a partial write.
    expect(StudentCurriculum::count())->toBe($episodeCount);
    expect($episode->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
    expect($episode->fresh()->ended_at)->toBeNull();
});

it('sends an over-promoted pupil back a level, the same mechanism as an arm move', function () {
    // SCOPE: target-curriculum-agnostic. The level correction is not a special case — same three
    // steps, different destination.
    $w = crs_world();
    [$source, $episode] = crs_promotedInto($w, $w['curriculumB']);
    $oldLevelCurriculum = Curriculum::withoutGlobalScope(SchoolScope::class)->find($source->curriculum_id);

    $destination = crs_service()->reassign($episode, $oldLevelCurriculum, $w['admin'], 'should have repeated');

    // The pupil is back in their old level's curriculum...
    expect($destination->curriculum_id)->toBe($oldLevelCurriculum->id);
    // ...which is the SAME row as the source episode, revived — the pupil was already enrolled there.
    expect($destination->id)->toBe($source->id);
    expect($destination->status)->toBe(StudentStatusEnum::ACTIVE);
    expect($episode->fresh()->status)->toBe(StudentStatusEnum::TRANSFERRED);

    // THE PROMOTION IS UNDONE, NOT REDIRECTED. The destination here IS the referrer, so a plain
    // repoint would have set this row's promoted_to_id to its OWN id — accepted by the composite FK
    // (same pupil, same school, id exists) and invisible to the trigger (the revive already made it
    // `active`). A row claiming to have been promoted into itself, that nothing would reject.
    expect($destination->promoted_to_id)->toBeNull();
    expect(
        StudentCurriculum::whereColumn('promoted_to_id', 'id')->count()
    )->toBe(0);
});

it('never leaves a promoted row without its link — the trigger is not tripped by a repoint', function () {
    // The repoint is a single-column update on a row that is `promoted` with a non-null link before
    // AND after, so it never passes through the state the trigger forbids. Asserted at the data
    // level, since that is what holds on production.
    $w = crs_world();
    [, $episode] = crs_promotedInto($w, $w['curriculumB']);

    crs_service()->reassign($episode, $w['curriculumS'], $w['admin']);

    expect(
        StudentCurriculum::where('status', StudentStatusEnum::PROMOTED->value)
            ->whereNull('promoted_to_id')->count()
    )->toBe(0);
});

it('the enum itself refuses a status outside the five — the column is the guard', function () {
    // 2026_08_21_100000 widened the enum; this pins that `transferred` is legal and that the column
    // still rejects anything else. Enforced on 5.7 too, because it is a column type and not a CHECK.
    $w = crs_world();
    [, $episode] = crs_promotedInto($w, $w['curriculumB']);

    DB::table('student_curricula')->where('id', $episode->id)->update(['status' => 'transferred']);
    expect(DB::table('student_curricula')->where('id', $episode->id)->value('status'))->toBe('transferred');

    expect(fn () => DB::table('student_curricula')->where('id', $episode->id)->update(['status' => 'graduated']))
        ->toThrow(QueryException::class);
});
