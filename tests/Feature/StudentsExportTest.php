<?php

use App\Enums\StudentStatusEnum;
use App\Exports\StudentsExport;
use App\Models\Scholarship;
use App\Models\SportHouse;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function se_student(int $schoolId, array $attrs = []): Student
{
    return ActiveSchool::runFor($schoolId, fn () => Student::create(array_merge([
        'school_id' => $schoolId,
        'first_name' => 'Ada',
        'last_name' => Str::random(6),
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ], $attrs)));
}

it('exports every detail column, with headings and row cells aligned', function () {
    $school = al_makeSchool();

    $house = ActiveSchool::runFor($school->id, fn () => SportHouse::create([
        'school_id' => $school->id, 'name' => 'Sapphire',
    ]));
    $scholarship = ActiveSchool::runFor($school->id, fn () => Scholarship::create([
        'school_id' => $school->id, 'name' => 'C2C',
    ]));

    se_student($school->id, [
        'admission_date' => '2020-09-05',
        'sport_house_id' => $house->id,
        'scholarship_id' => $scholarship->id,
        'nationality' => 'Nigerian',
        'other_nationality' => 'British',
        'state_of_origin' => 'Bayelsa State',
        'religion' => 'Christianity',
        'previous_school' => 'C P S Biogbolo',
        'address' => 'Tunuama Community, Yenagoa',
    ]);

    $export = new StudentsExport(new Request);

    [$headings, $row] = ActiveSchool::runFor($school->id, fn () => [
        $export->headings(),
        $export->map($export->query()->first()),
    ]);

    // ALIGNMENT FIRST. A row with more or fewer cells than headings does not error — it silently
    // shifts every column after the gap, which is the failure mode nobody notices in a spreadsheet.
    expect($row)->toHaveCount(count($headings));

    // Read the values back BY HEADING rather than by index, so this test cannot pass while the
    // columns are in the wrong order.
    $byHeading = array_combine($headings, $row);

    expect($byHeading['Admission Date'])->toBe('2020-09-05')
        ->and($byHeading['Sport House'])->toBe('Sapphire')
        ->and($byHeading['Scholarship'])->toBe('C2C')
        ->and($byHeading['Nationality'])->toBe('Nigerian')
        ->and($byHeading['Other Nationality'])->toBe('British')
        ->and($byHeading['State of Origin'])->toBe('Bayelsa State')
        ->and($byHeading['Religion'])->toBe('Christianity')
        ->and($byHeading['Previous School'])->toBe('C P S Biogbolo')
        ->and($byHeading['Address'])->toBe('Tunuama Community, Yenagoa');
});

it('renders a student with none of the optional details as empty strings, not nulls or errors', function () {
    // The common case: most of these columns are optional, and a null reaching the spreadsheet
    // writer is a different thing from an empty cell.
    $school = al_makeSchool();
    se_student($school->id);

    $export = new StudentsExport(new Request);
    $row = ActiveSchool::runFor($school->id, fn () => $export->map($export->query()->first()));

    expect($row)->toHaveCount(count($export->headings()));
    foreach ($row as $cell) {
        expect($cell)->toBeString();
    }
});

it('does not lazy-load the new relations per row', function () {
    // sportHouse and scholarship are read once per student in map(), so without the eager load this
    // is two extra queries per row on an export that can span a whole school.
    $school = al_makeSchool();
    $house = ActiveSchool::runFor($school->id, fn () => SportHouse::create([
        'school_id' => $school->id, 'name' => 'Ruby',
    ]));

    foreach (range(1, 5) as $i) {
        se_student($school->id, ['sport_house_id' => $house->id]);
    }

    $export = new StudentsExport(new Request);

    ActiveSchool::runFor($school->id, function () use ($export) {
        $students = $export->query()->get();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        foreach ($students as $student) {
            $export->map($student);
        }

        expect($queries)->toBe(0);
    });
});

it('never exports another schools students', function () {
    $mine = al_makeSchool();
    $theirs = al_makeSchool();

    se_student($mine->id, ['first_name' => 'Mine']);
    se_student($theirs->id, ['first_name' => 'Theirs']);

    $export = new StudentsExport(new Request);
    $names = ActiveSchool::runFor($mine->id, fn () => $export->query()->pluck('first_name')->all());

    expect($names)->toBe(['Mine']);
});

// ---------------------------------------------------------------------------
// The two export scopes — defect 2, and the selection export beside it
// ---------------------------------------------------------------------------

/**
 * THE DEFECT: the export honoured `search` and nothing else, while the index it sits on also
 * filtered by class level and arm. An operator who narrowed to one class and pressed Export
 * silently downloaded the whole school — and a spreadsheet gives no clue that it is wider than the
 * screen that produced it.
 *
 * Asserted through StudentIndexFilters, the definition both readers now share, so the two cannot
 * drift apart again.
 */
it('applies the class-level filter to the export, not only the search term', function () {
    $w = sr_world();

    // In Year 8 B — the class the filter names.
    $inCohort = $w['student'];

    // In Year 9 B — same school, same term, different level. Only the class-level filter excludes
    // this pupil; `search` alone would return them.
    $outsider = ActiveSchool::runFor($w['school']->id, fn () => Student::create([
        'school_id' => $w['school']->id,
        'first_name' => 'Ada',
        'last_name' => Str::random(6),
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]));
    ActiveSchool::runFor($w['school']->id, fn () => StudentCurriculum::create([
        'student_id' => $outsider->id,
        'curriculum_id' => $w['c9B']->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]));

    $request = Request::create('/api/students/export', 'GET', [
        'class_level' => $w['y8']->uuid,
    ]);

    $ids = ActiveSchool::runFor(
        $w['school']->id,
        fn () => (new StudentsExport($request))->query()->pluck('students.id')->all(),
    );

    // By ID, never by name — the two pupils are deliberately similar.
    expect($ids)->toContain($inCohort->id)
        ->and($ids)->not->toContain($outsider->id);
});

/**
 * SEARCH AND A FILTER COMPOSE, which they did not before: the index's search clause was ungrouped,
 * so `A OR B OR C AND EXISTS(class level)` bound as `A OR B OR (C AND EXISTS)` and a first- or
 * last-name search silently ignored the class filter entirely.
 */
it('composes the search term with the class-level filter instead of widening past it', function () {
    $w = sr_world();

    $outsider = ActiveSchool::runFor($w['school']->id, fn () => Student::create([
        'school_id' => $w['school']->id,
        // Matches the search term on FIRST NAME — the arm of the OR chain that escaped the filter.
        'first_name' => 'Pupil',
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]));
    ActiveSchool::runFor($w['school']->id, fn () => StudentCurriculum::create([
        'student_id' => $outsider->id,
        'curriculum_id' => $w['c9B']->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]));

    $request = Request::create('/api/students/export', 'GET', [
        'search' => 'Pupil',
        'class_level' => $w['y8']->uuid,
    ]);

    $ids = ActiveSchool::runFor(
        $w['school']->id,
        fn () => (new StudentsExport($request))->query()->pluck('students.id')->all(),
    );

    expect($ids)->toContain($w['student']->id)
        ->and($ids)->not->toContain($outsider->id);
});

/**
 * THE SELECTION SCOPE IGNORES THE FILTERS, deliberately. The control says "Export selected (N)", so
 * N rows come back — composing it with the filters behind the page would quietly return fewer than
 * the label promised.
 */
it('exports exactly the selected ids, regardless of the filters on the request', function () {
    $w = sr_world();

    $other = se_student($w['school']->id);

    $request = Request::create('/api/students/export-selected', 'POST', [
        // A filter that would exclude the picked pupil if the two scopes composed.
        'class_level' => $w['y9']->uuid,
    ]);

    $ids = ActiveSchool::runFor(
        $w['school']->id,
        fn () => (new StudentsExport($request, [$w['student']->uuid]))->query()->pluck('students.id')->all(),
    );

    expect($ids)->toBe([$w['student']->id])
        ->and($ids)->not->toContain($other->id);
});

/** A uuid from another school contributes no row — isolation, checked by id. */
it('drops a selected id belonging to another school', function () {
    $w = sr_world();
    $other = sr_school('second');

    $request = Request::create('/api/students/export-selected', 'POST');

    $ids = ActiveSchool::runFor(
        $w['school']->id,
        fn () => (new StudentsExport($request, [$w['student']->uuid, $other['student']->uuid]))
            ->query()->pluck('students.id')->all(),
    );

    expect($ids)->toBe([$w['student']->id]);
});
