<?php

use App\Http\Controllers\StudentController;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Services\StudentService;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

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
