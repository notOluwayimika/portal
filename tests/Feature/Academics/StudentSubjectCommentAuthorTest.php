<?php

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\StudentSubjectService;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Who a subject comment is attributed to.
 *
 * `student_subjects.commented_by` is a foreign key to **teachers.id**, but the service wrote a
 * **users.id**. The two id spaces overlap by accident, which gave the bug two faces:
 *
 *   - no overlap  → foreign key violation, surfaced to the teacher as "Database error"
 *   - overlap     → row saves, comment attributed to a teacher who never wrote it
 *
 * The second is the one worth a permanent test: it is silent, and it is the shape that survives
 * in the data. Both are covered below, along with the migration that repairs already-written rows.
 */
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/**
 * A teacher whose user id is deliberately pushed FAR beyond any teachers.id, reproducing the
 * no-overlap half of the bug — this is the account that got "Database error".
 */
function ssc_teacher(int $schoolId, ?int $forceUserId = null): array
{
    return ActiveSchool::runFor($schoolId, function () use ($schoolId, $forceUserId) {
        $user = al_makeUser($schoolId);

        if ($forceUserId !== null) {
            DB::table('users')->where('id', $user->id)->update(['id' => $forceUserId]);
            $user = User::withoutGlobalScopes()->findOrFail($forceUserId);
        }

        $teacher = Teacher::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'first_name' => 'Tee',
            'last_name' => 'Cher '.Str::random(4),
            'status' => 'active',
        ]);

        return [$user, $teacher];
    });
}

function ssc_studentSubject(int $schoolId): StudentSubject
{
    return ActiveSchool::runFor($schoolId, function () use ($schoolId) {
        $curriculum = Curriculum::factory()->create(['school_id' => $schoolId]);
        $subject = Subject::create([
            'school_id' => $schoolId,
            'name' => 'Subject '.Str::random(5),
            'code' => strtoupper(Str::random(4)),
        ]);
        $curriculumSubject = CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'is_compulsory' => true,
            'active' => true,
        ]);
        $student = Student::factory()->create(['school_id' => $schoolId]);
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $schoolId,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        // StudentCurriculumObserver auto-attaches compulsory subjects on enrolment, so the row
        // already exists — creating it again trips student_subject_unique.
        return StudentSubject::where('student_curriculum_id', $enrollment->id)
            ->where('curriculum_subject_id', $curriculumSubject->id)
            ->firstOrFail();
    });
}

it('attributes the comment to the teacher who wrote it, not to whatever teacher shares their user id', function () {
    $school = al_makeSchool();
    [$user, $teacher] = ssc_teacher($school->id);
    $studentSubject = ssc_studentSubject($school->id);

    ActiveSchool::runFor($school->id, fn () => (new StudentSubjectService)
        ->storeComment($studentSubject, $user, 'Good result. Aim higher'));

    expect($studentSubject->fresh()->commented_by)->toBe($teacher->id);

    // The heart of it: the stored value must be the TEACHER id. Storing the user id was not just
    // a crash — where the numbers happened to line up it silently credited someone else.
    expect($studentSubject->fresh()->commented_by)->not->toBe($user->id);
});

it('saves the comment for a teacher whose user id has no matching teachers.id', function () {
    $school = al_makeSchool();
    // 900000 is far beyond any teachers.id, so the old code's FK write failed outright here —
    // the "Database error" the bug was reported as.
    [$user, $teacher] = ssc_teacher($school->id, forceUserId: 900000);
    $studentSubject = ssc_studentSubject($school->id);

    ActiveSchool::runFor($school->id, fn () => (new StudentSubjectService)
        ->storeComment($studentSubject, $user, 'Very good result. Aim higher'));

    expect($studentSubject->fresh())
        ->comment->toBe('Very good result. Aim higher')
        ->commented_by->toBe($teacher->id);
});

it('resolves the comment author back to that teacher\'s name', function () {
    $school = al_makeSchool();
    [$user, $teacher] = ssc_teacher($school->id);
    $studentSubject = ssc_studentSubject($school->id);

    ActiveSchool::runFor($school->id, function () use ($studentSubject, $user, $teacher) {
        (new StudentSubjectService)->storeComment($studentSubject, $user, 'Keep it up');

        // This is what StudentSubjectResource renders. Under the bug it named a stranger.
        expect($studentSubject->fresh()->commentedBy?->full_name)->toBe($teacher->full_name);
    });
});

it('stores no author when the commenter has no teacher record, rather than failing', function () {
    $school = al_makeSchool();
    $studentSubject = ssc_studentSubject($school->id);

    // An admin or head of school. The column is a teachers foreign key and simply cannot express
    // them, so the comment is saved with the author left absent — losing attribution is better
    // than losing the comment, and better than crediting the wrong person.
    $admin = ActiveSchool::runFor($school->id, fn () => al_makeUser($school->id));

    ActiveSchool::runFor($school->id, fn () => (new StudentSubjectService)
        ->storeComment($studentSubject, $admin, 'Approved'));

    expect($studentSubject->fresh())
        ->comment->toBe('Approved')
        ->commented_by->toBeNull();
});

it('repairs rows the old code already wrote', function () {
    $school = al_makeSchool();
    [$user, $teacher] = ssc_teacher($school->id);
    $studentSubject = ssc_studentSubject($school->id);

    // Plant the damage exactly as the old code produced it: the USER id in a teachers column.
    // Written raw, because the fixed service can no longer create this state.
    $decoy = ActiveSchool::runFor($school->id, fn () => Teacher::create([
        'school_id' => $school->id,
        'user_id' => null,
        'first_name' => 'Wrong',
        'last_name' => 'Teacher',
        'status' => 'active',
    ]));
    DB::table('teachers')->where('id', $decoy->id)->update(['id' => $user->id]);

    DB::table('student_subjects')->where('id', $studentSubject->id)
        ->update(['comment' => 'Good result', 'commented_by' => $user->id]);

    // Before: the comment is credited to the decoy, because its id equals the author's user id.
    expect($studentSubject->fresh()->commented_by)->toBe($user->id);

    DB::statement('
        UPDATE student_subjects AS ss
        LEFT JOIN teachers AS t ON t.user_id = ss.commented_by
        SET ss.commented_by = t.id
        WHERE ss.commented_by IS NOT NULL
    ');

    expect($studentSubject->fresh()->commented_by)->toBe($teacher->id);
});

it('nulls a repaired row whose author has no teacher record at all', function () {
    $school = al_makeSchool();
    [, $teacher] = ssc_teacher($school->id);
    $studentSubject = ssc_studentSubject($school->id);

    // A stored value that IS a valid teachers.id (the foreign key guaranteed that much) but that
    // no teacher claims as their user_id — an admin's comment. Unrecoverable, so it must become
    // NULL rather than keep pointing at an unrelated teacher.
    DB::table('student_subjects')->where('id', $studentSubject->id)
        ->update(['comment' => 'Admin note', 'commented_by' => $teacher->id]);

    DB::table('teachers')->where('id', $teacher->id)->update(['user_id' => null]);

    DB::statement('
        UPDATE student_subjects AS ss
        LEFT JOIN teachers AS t ON t.user_id = ss.commented_by
        SET ss.commented_by = t.id
        WHERE ss.commented_by IS NOT NULL
    ');

    expect($studentSubject->fresh())
        ->comment->toBe('Admin note')   // the comment itself is never collateral
        ->commented_by->toBeNull();
});
