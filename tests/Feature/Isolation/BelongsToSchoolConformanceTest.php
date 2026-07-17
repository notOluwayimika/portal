<?php

use App\Concerns\BelongsToSchool;
use App\Models\Guardian;
use App\Models\Notice;
use App\Models\Student;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Permanent conformance suite for §5.2 enforcement point 2 —
 * "Write | BelongsToSchool::creating auto-fills school_id".
 *
 * The halting-event defect depended on trait ORDER (AddUuid, an arrow fn that
 * returned the uuid, halted the creating chain before BelongsToSchool). A
 * model-specific test would only protect one model, so this suite is
 * reflection-driven: it discovers EVERY model using BelongsToSchool and proves
 * the auto-fill fires for each, so a re-introduction of a halting listener on
 * any model fails here — and any NEW School-owned model is covered automatically.
 */
uses(RefreshDatabase::class);

/** @return array<class-string> every app model using the BelongsToSchool trait */
function belongsToSchoolModels(): array
{
    $models = [];
    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (! class_exists($class)) {
            continue;
        }
        if (in_array(BelongsToSchool::class, class_uses_recursive($class), true)) {
            $models[] = $class;
        }
    }
    sort($models);

    return $models;
}

/** Fire the real (halting) `creating` chain on an unsaved model, no DB insert. */
function fireCreating(object $model): void
{
    $m = new ReflectionMethod($model, 'fireModelEvent');
    $m->setAccessible(true);
    $m->invoke($model, 'creating', true);
}

it('discovers a meaningful set of BelongsToSchool models (guard against empty coverage)', function () {
    // If discovery silently returns nothing, every conformance assertion below
    // would vacuously pass — assert we actually found the known models.
    $models = belongsToSchoolModels();

    expect(count($models))->toBeGreaterThanOrEqual(10)
        ->and($models)->toContain(Guardian::class, Student::class, Notice::class);
});

it('auto-fills school_id for EVERY BelongsToSchool model when created under runFor', function () {
    $school = al_makeSchool();
    $failures = [];

    foreach (belongsToSchoolModels() as $class) {
        $model = new $class;
        ActiveSchool::runFor($school->id, fn () => fireCreating($model));

        if ((int) $model->school_id !== $school->id) {
            $failures[] = $class.' => '.var_export($model->school_id, true);
        }
    }

    // A non-empty list names exactly which model's creating chain is halted.
    expect($failures)->toBe([]);
});

it('does NOT auto-fill school_id for any BelongsToSchool model outside a School context', function () {
    $filled = [];

    foreach (belongsToSchoolModels() as $class) {
        $model = new $class;
        fireCreating($model); // no runFor, no auth, no session

        if ($model->school_id !== null) {
            $filled[] = $class.' => '.var_export($model->school_id, true);
        }
    }

    expect($filled)->toBe([]);
});

it('runs the FULL creating chain — AddUuid, BelongsToSchool AND HasAdmissionNumber all execute (Student, real insert)', function () {
    $school = al_makeSchool();

    $student = ActiveSchool::runFor($school->id, function () {
        return Student::create([
            'first_name' => 'Conformance',
            'last_name' => 'Student',
            // no school_id, no uuid, no admission_number — all hook-driven
        ]);
    });

    // Creation succeeded and all three creating hooks ran:
    expect($student->exists)->toBeTrue()
        ->and((int) $student->school_id)->toBe($school->id)   // BelongsToSchool
        ->and($student->uuid)->not->toBeNull()                 // AddUuid
        ->and($student->admission_number)->not->toBeNull();    // HasAdmissionNumber (created hook)

    // And HasAdmissionNumber's *creating* duplicate-guard now runs (it was
    // halted before the fix): a duplicate manual number is rejected.
    expect(fn () => ActiveSchool::runFor($school->id, fn () => Student::create([
        'first_name' => 'Dup',
        'last_name' => 'Student',
        'admission_number' => $student->admission_number,
    ])))->toThrow(InvalidArgumentException::class);
});
