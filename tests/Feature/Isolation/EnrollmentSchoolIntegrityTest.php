<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Requests\StudentRequest;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Support\ActiveSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Slice (i) — the enrollment episode now carries its own School, and
 * "episode.school == student.school == curriculum.school" is enforced by two
 * composite foreign keys rather than by three hand-rolled application checks.
 *
 * THE POINT OF THESE TESTS: every assertion below goes through RAW SQL, bypassing
 * the model, the Action and the FormRequest. If the invariant only held in
 * `enroll()` these would all pass happily. They pass because the DATABASE refuses.
 * That is the whole difference between this slice and the code it replaces.
 */
uses(RefreshDatabase::class);

/** @return array{0: School, 1: Student, 2: Curriculum} */
function episodeSetup(?School $school = null): array
{
    $school ??= School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

    return [$school, $student, $curriculum];
}

function rawEpisode(int $studentId, int $curriculumId, int $schoolId): void
{
    DB::table('student_curricula')->insert([
        'uuid' => (string) Str::uuid(),
        'student_id' => $studentId,
        'school_id' => $schoolId,
        'curriculum_id' => $curriculumId,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('STRUCTURAL — an episode whose school disagrees with its student is rejected by the DB', function () {
    [$schoolA, $student, $curriculum] = episodeSetup();
    $schoolB = School::factory()->create();

    // Raw insert: no model, no Action, no FormRequest. Only the composite FK stands
    // between this and a cross-School episode. Remove
    // student_curricula_student_school_foreign and this test goes green (i.e. red).
    expect(fn () => rawEpisode($student->id, $curriculum->id, $schoolB->id))
        ->toThrow(QueryException::class);

    expect(DB::table('student_curricula')->count())->toBe(0);
});

it('STRUCTURAL — a cross-School student/curriculum PAIR is unrepresentable, whichever school you pick', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $studentA = Student::factory()->create(['school_id' => $schoolA->id]);
    $curriculumB = Curriculum::factory()->create(['school_id' => $schoolB->id]);

    // This is the case the three hand-rolled checks existed to catch. There is now
    // NO value of school_id that satisfies both parents at once:
    //   - school A  -> the curriculum FK fails (curriculum belongs to B)
    //   - school B  -> the student FK fails (student belongs to A)
    expect(fn () => rawEpisode($studentA->id, $curriculumB->id, $schoolA->id))
        ->toThrow(QueryException::class);
    expect(fn () => rawEpisode($studentA->id, $curriculumB->id, $schoolB->id))
        ->toThrow(QueryException::class);

    expect(DB::table('student_curricula')->count())->toBe(0);
});

it('the model DERIVES school_id from the student, so no caller has to pass it', function () {
    [$school, $student, $curriculum] = episodeSetup();

    $episode = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]);

    expect($episode->school_id)->toBe($school->id);
});

it('an explicitly WRONG school_id is not silently corrected — the DB rejects it', function () {
    [$schoolA, $student, $curriculum] = episodeSetup();
    $schoolB = School::factory()->create();

    // The creating hook only fills a NULL; it must never mask a caller's mistake.
    expect(fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'school_id' => $schoolB->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]))->toThrow(QueryException::class);
});

it('closes the StudentService::update gap — the one creation path with no School check', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $studentA = Student::factory()->create(['school_id' => $schoolA->id]);
    $curriculumB = Curriculum::factory()->create(['school_id' => $schoolB->id]);

    // StudentService::update reaches StudentCurriculum::updateOrCreate directly,
    // bypassing enroll() and its cross-School check (and its own guard is dead —
    // $student->studentCurriculum is not a relation, so it is always null). The
    // schema now catches what that path never did.
    expect(fn () => StudentCurriculum::updateOrCreate(
        ['student_id' => $studentA->id, 'curriculum_id' => $curriculumB->id],
        ['status' => 'active'],
    ))->toThrow(QueryException::class);

    expect(DB::table('student_curricula')->count())->toBe(0);
});

it('SLICE-2 GUARD IS NOW STRUCTURAL — an invoice whose school disagrees with its episode is rejected', function () {
    [$school, $student, $curriculum] = episodeSetup();
    $schoolB = School::factory()->create();

    $episode = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]);

    // Slice 2's duplicate guard is UNIQUE(school_id, active_enrollment_key) where the
    // key is student_curriculum_id — so it already depended on the episode's School,
    // while deriving it from another table via a null->0 fallback. With the composite
    // FK, disagreement is a foreign-key violation instead of a silent divergence.
    expect(fn () => DB::table('finance_invoices')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolB->id,          // disagrees with the episode's school
        'student_id' => $student->id,
        'student_curriculum_id' => $episode->id,
        'number' => 1,
        'status' => 'issued',
        'billed_to_name' => 'Ada Obi',
        'academic_context' => 'ctx',
        'total_minor' => 1000,
        'total_currency' => 'NGN',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(DB::table('finance_invoices')->count())->toBe(0);
});

it('SCOPED EXISTS — a foreign-School curriculum is a validation error, never a QueryException', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $ownCurriculum = Curriculum::factory()->create(['school_id' => $schoolA->id]);
    $foreignCurriculum = Curriculum::factory()->create(['school_id' => $schoolB->id]);

    // Without the scoped rule, a foreign curriculum_id passes validation and only
    // fails later at the new composite FK — surfacing as a raw 500 QueryException
    // instead of a field-level validation error. This proves it is rejected at the
    // edge, so the FK is a backstop rather than the user-facing failure.
    $rules = ActiveSchool::runFor($schoolA->id, fn () => (new StudentRequest)->rules());

    $validateCurriculum = function (int $id) use ($rules, $schoolA) {
        return ActiveSchool::runFor($schoolA->id, fn () => Validator::make(
            ['curriculum_id' => $id],
            ['curriculum_id' => $rules['curriculum_id']],
        )->fails());
    };

    expect($validateCurriculum($foreignCurriculum->id))->toBeTrue()   // rejected at the edge
        ->and($validateCurriculum($ownCurriculum->id))->toBeFalse();  // own School still fine
});

it('D2 — a student\'s School is immutable after create', function () {
    [$schoolA, $student] = episodeSetup();
    $schoolB = School::factory()->create();

    // No code path updates students.school_id today, and the composite FKs carry no
    // ON UPDATE CASCADE — a cascade would silently rewrite the School attribution of
    // every historical billed/graded episode. The model refuses the change outright.
    expect(fn () => $student->update(['school_id' => $schoolB->id]))
        ->toThrow(BusinessRuleException::class);

    expect($student->fresh()->school_id)->toBe($schoolA->id);
});
