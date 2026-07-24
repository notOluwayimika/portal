<?php

use App\Http\Requests\ImportStudentRequest;
use App\Http\Requests\ImportTeacherRequest;
use App\Models\Curriculum;
use App\Services\Validators\GuardianImportRowValidator;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A client-side xlsx/csv parser types a bare-digit cell (e.g. an admission or
 * staff number like `20251237`) as a JSON number. The import FormRequests rule
 * those fields as `string`, so the number was rejected with "the field must be
 * a string". These fields must ACCEPT the number and store it as a string.
 */

/** Run a payload through a FormRequest's prepareForValidation + rules. */
function al_validateImport(string $class, array $payload): array
{
    $request = $class::create('/', 'POST', $payload);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request->validated();
}

it('accepts a numeric student admission_number and stores it as a string', function () {
    $school = al_makeSchool();

    ActiveSchool::runFor($school->id, function () use ($school) {
        $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

        $validated = al_validateImport(ImportStudentRequest::class, [
            'curriculum_id' => $curriculum->id,
            'students' => [
                ['first_name' => 'Ada', 'last_name' => 'Obi', 'admission_number' => 20251237],
            ],
        ]);

        expect($validated['students'][0]['admission_number'])
            ->toBe('20251237')
            ->toBeString();
    });
});

it('accepts a numeric teacher staff_number and stores it as a string', function () {
    $validated = al_validateImport(ImportTeacherRequest::class, [
        'teachers' => [
            ['first_name' => 'Grace', 'last_name' => 'Eze', 'staff_number' => 4471],
        ],
    ]);

    expect($validated['teachers'][0]['staff_number'])
        ->toBe('4471')
        ->toBeString();
});

it('leaves genuine string identifiers untouched', function () {
    $validated = al_validateImport(ImportTeacherRequest::class, [
        'teachers' => [
            ['first_name' => 'Grace', 'last_name' => 'Eze', 'staff_number' => 'STF-1'],
        ],
    ]);

    expect($validated['teachers'][0]['staff_number'])->toBe('STF-1');
});

it('coerces a numeric guardian-import admission_number to a string for lookup', function () {
    $result = (new GuardianImportRowValidator)->validate([
        'admission_number' => 20251237,
        'relationship' => 'father',
        'is_primary' => 'yes',
        'first_name' => 'John',
        'last_name' => 'Obi',
        'phone' => '+2348000000000',
    ]);

    expect($result['normalized']['admission_number'])
        ->toBe('20251237')
        ->toBeString();
});
