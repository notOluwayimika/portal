<?php

use App\Http\Controllers\StudentController;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Services\StudentIndexFilters;
use App\Services\StudentService;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Item 4: the student index filters by the student's ACTIVE enrolment's class
 * level and arm. Applying both narrows to the single class-level-arm.
 */

/** Enrol a fresh student into a curriculum bound to ($classLevel, $arm). */
function al_enrolStudent(int $schoolId, ClassLevel $classLevel, Arm $arm): Student
{
    $cla = ClassLevelArm::create([
        'school_id' => $schoolId,
        'class_level_id' => $classLevel->id,
        'arm_id' => $arm->id,
    ]);
    $curriculum = Curriculum::factory()->create([
        'school_id' => $schoolId,
        'class_level_arm_id' => $cla->id,
    ]);
    $student = Student::factory()->create(['school_id' => $schoolId]);
    StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]);

    return $student;
}

it('filters students by class level, arm, and both combined', function () {
    $school = al_makeSchool();

    ActiveSchool::runFor($school->id, function () use ($school) {
        $jss1 = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS1', 'order' => 1]);
        $jss2 = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS2', 'order' => 2]);
        $armA = Arm::create(['school_id' => $school->id, 'label' => 'A']);
        $armB = Arm::create(['school_id' => $school->id, 'label' => 'B']);

        // JSS1-A, JSS1-B, JSS2-A
        al_enrolStudent($school->id, $jss1, $armA);
        al_enrolStudent($school->id, $jss1, $armB);
        al_enrolStudent($school->id, $jss2, $armA);

        $svc = app(StudentService::class);
        $total = fn (array $params) => $svc->paginate(new Request($params))->total();

        expect($total([]))->toBe(3)
            ->and($total(['class_level' => $jss1->uuid]))->toBe(2)   // JSS1-A + JSS1-B
            ->and($total(['arm' => $armA->uuid]))->toBe(2)           // JSS1-A + JSS2-A
            ->and($total(['class_level' => $jss1->uuid, 'arm' => $armA->uuid]))->toBe(1); // JSS1-A only
    });
});

it('exposes arms grouped by class level for the dependent filter', function () {
    $school = al_makeSchool();

    ActiveSchool::runFor($school->id, function () use ($school) {
        $jss1 = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS1', 'order' => 1]);
        $jss2 = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS2', 'order' => 2]);
        $armA = Arm::create(['school_id' => $school->id, 'label' => 'A']);
        $armB = Arm::create(['school_id' => $school->id, 'label' => 'B']);

        // JSS1 has A and B; JSS2 has only A.
        ClassLevelArm::create(['school_id' => $school->id, 'class_level_id' => $jss1->id, 'arm_id' => $armA->id]);
        ClassLevelArm::create(['school_id' => $school->id, 'class_level_id' => $jss1->id, 'arm_id' => $armB->id]);
        ClassLevelArm::create(['school_id' => $school->id, 'class_level_id' => $jss2->id, 'arm_id' => $armA->id]);

        $data = json_decode(app(StudentController::class)->resources()->getContent(), true)['data'];
        $map = collect($data['class_level_arms']);

        $armsFor = fn (ClassLevel $cl): array => $map
            ->where('class_level', $cl->uuid)
            ->pluck('label')->sort()->values()->all();

        expect($armsFor($jss1))->toBe(['A', 'B'])
            ->and($armsFor($jss2))->toBe(['A']);
    });
});

it('ignores empty filter values', function () {
    $school = al_makeSchool();

    ActiveSchool::runFor($school->id, function () use ($school) {
        $jss1 = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS1', 'order' => 1]);
        $armA = Arm::create(['school_id' => $school->id, 'label' => 'A']);
        al_enrolStudent($school->id, $jss1, $armA);

        $total = app(StudentService::class)->paginate(new Request(['class_level' => '', 'arm' => '']))->total();

        expect($total)->toBe(1);
    });
});

/**
 * The scholarship filter, added alongside class level and arm.
 *
 * THE FIXTURE MAKES THE SCHEME THE ONLY THING THAT CAN EXPLAIN THE PASS. A SECOND scheme carries a
 * second sponsored pupil, so a filter that admitted every sponsored pupil returns two and this
 * reds; a third pupil is on no scheme at all, so a filter that failed to narrow returns three.
 *
 * The scheme NAME is a rejected input, not an accepted synonym: names are unique per school today
 * (scholarships_school_id_name_unique), which is exactly why a filter keyed to the name would look
 * correct until somebody renamed a scheme. Passing the name must match nothing.
 */
it('filters students by scholarship, matching the scheme by uuid and not by name', function () {
    $school = al_makeSchool();

    ActiveSchool::runFor($school->id, function () use ($school) {
        $mine = Scholarship::create(['school_id' => $school->id, 'name' => 'C2C']);
        $other = Scholarship::create(['school_id' => $school->id, 'name' => 'Founders']);

        $sponsored = Student::factory()->create([
            'school_id' => $school->id,
            'scholarship_id' => $mine->id,
        ]);
        Student::factory()->create([
            'school_id' => $school->id,
            'scholarship_id' => $other->id,
        ]);
        Student::factory()->create(['school_id' => $school->id]);

        $svc = app(StudentService::class);
        $ids = fn (array $params) => $svc->paginate(new Request($params))->pluck('id')->all();

        // By ID, never by name.
        expect($ids([]))->toHaveCount(3)
            ->and($ids(['scholarship' => $mine->uuid]))->toBe([$sponsored->id])
            ->and($ids(['scholarship' => $mine->name]))->toBe([])
            ->and($ids(['scholarship' => '']))->toHaveCount(3);
    });
});

/**
 * The scholarship filter COMPOSES with the class-level filter rather than replacing it — the same
 * property the grouped search clause exists to protect.
 */
it('composes the scholarship filter with the class-level filter', function () {
    $school = al_makeSchool();

    ActiveSchool::runFor($school->id, function () use ($school) {
        $jss1 = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS1', 'order' => 1]);
        $jss2 = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS2', 'order' => 2]);
        $armA = Arm::create(['school_id' => $school->id, 'label' => 'A']);

        $scheme = Scholarship::create(['school_id' => $school->id, 'name' => 'Founders']);

        // On the scheme AND in JSS1 — the only pupil both filters admit.
        $both = al_enrolStudent($school->id, $jss1, $armA);
        $both->update(['scholarship_id' => $scheme->id]);

        // On the scheme, wrong level. In the level, no scheme. Each is excluded by one filter
        // alone, so dropping either filter reds this.
        al_enrolStudent($school->id, $jss2, $armA)->update(['scholarship_id' => $scheme->id]);
        al_enrolStudent($school->id, $jss1, $armA);

        $ids = app(StudentService::class)
            ->paginate(new Request(['class_level' => $jss1->uuid, 'scholarship' => $scheme->uuid]))
            ->pluck('id')->all();

        expect($ids)->toBe([$both->id]);
    });
});

/**
 * THE THREE STATES OF THE SCHOLARSHIP FILTER ARE DISTINCT, and the fixture can tell them apart:
 * empty means "do not filter" (sponsored AND unsponsored), a uuid means one scheme, and the
 * NO_SCHOLARSHIP sentinel means the pupils on no scheme at all. Two schemes and two unsponsored
 * pupils, so no two of the three answers coincide by accident — 'all' is 4, 'none' is 2, one
 * scheme is 1.
 */
it('separates all, one scheme, and no scholarship at all', function () {
    $school = al_makeSchool();

    ActiveSchool::runFor($school->id, function () use ($school) {
        $c2c = Scholarship::create(['school_id' => $school->id, 'name' => 'C2C']);
        $founders = Scholarship::create(['school_id' => $school->id, 'name' => 'Founders']);

        $onC2c = Student::factory()->create(['school_id' => $school->id, 'scholarship_id' => $c2c->id]);
        $onFounders = Student::factory()->create(['school_id' => $school->id, 'scholarship_id' => $founders->id]);
        $unsponsoredA = Student::factory()->create(['school_id' => $school->id]);
        $unsponsoredB = Student::factory()->create(['school_id' => $school->id]);

        $svc = app(StudentService::class);
        $ids = fn (array $params) => $svc->paginate(new Request($params))->pluck('id')->sort()->values()->all();

        $sorted = fn (array $students) => collect($students)->pluck('id')->sort()->values()->all();

        // ALL — the unfiltered list carries sponsored and unsponsored pupils alike. Asserted as the
        // SET, not the count: a filter that dropped the sponsored pupils and gained two others
        // would keep the count.
        expect($ids([]))->toBe($sorted([$onC2c, $onFounders, $unsponsoredA, $unsponsoredB]))
            ->and($ids(['scholarship' => '']))->toBe($sorted([$onC2c, $onFounders, $unsponsoredA, $unsponsoredB]))
            // ONE SCHEME — not "any scheme".
            ->and($ids(['scholarship' => $c2c->uuid]))->toBe([$onC2c->id])
            // NONE — the sentinel, which is not a scheme and must not be read as one.
            ->and($ids(['scholarship' => StudentIndexFilters::NO_SCHOLARSHIP]))
            ->toBe($sorted([$unsponsoredA, $unsponsoredB]));
    });
});

/**
 * The index renders a Scholarship column, so StudentResource reads the relation once per row.
 * Without the eager load that is one query per pupil on the page — invisible on a page of 3 and
 * expensive on a page of 100, which is why it is asserted rather than eyeballed.
 */
it('eager-loads the scholarship the index column reads', function () {
    $school = al_makeSchool();

    ActiveSchool::runFor($school->id, function () use ($school) {
        $scheme = Scholarship::create(['school_id' => $school->id, 'name' => 'C2C']);
        foreach (range(1, 3) as $i) {
            Student::factory()->create(['school_id' => $school->id, 'scholarship_id' => $scheme->id]);
        }

        $students = app(StudentService::class)->paginate(new Request)->items();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        foreach ($students as $student) {
            expect($student->scholarship?->name)->toBe('C2C');
        }

        expect($queries)->toBe(0);
    });
});
