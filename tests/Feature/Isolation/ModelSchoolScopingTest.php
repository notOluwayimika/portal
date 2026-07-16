<?php

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\MarkingComponent;
use App\Models\Stream;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function actingInSchool($school): void
{
    test()->actingAs(al_makeUser($school->id));
}

function makeTermIn($school): Term
{
    $session = AcademicSession::forceCreate([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'name' => '2026/2027 '.Str::random(4),
        'slug' => Str::slug(Str::random(10)),
    ]);

    return Term::forceCreate([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'academic_session_id' => $session->id,
        'name' => 'First Term',
        'slug' => (string) Str::uuid(),
        'order' => 1,
        'status' => 'active',
        'start_date' => '2026-09-01',
        'end_date' => '2026-12-15',
    ]);
}

function makeClassLevelArmIn($school): ClassLevelArm
{
    $level = ClassLevel::forceCreate(['uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'name' => 'JSS1 '.Str::random(4)]);
    $arm = Arm::forceCreate(['uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'label' => 'A'.Str::random(3)]);
    $stream = Stream::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'Science '.Str::random(4)]);

    return ClassLevelArm::forceCreate([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'class_level_id' => $level->id,
        'arm_id' => $arm->id,
        'stream_id' => $stream->id,
    ]);
}

function makeMarkingComponentIn($school): MarkingComponent
{
    return MarkingComponent::forceCreate([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'name' => 'Test '.Str::random(4),
        'weight' => 0.4,
    ]);
}

it('scopes Term to the active school', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    makeTermIn($a);
    makeTermIn($b);

    actingInSchool($a);

    expect(Term::count())->toBe(1)
        ->and(Term::first()->school_id)->toBe((int) $a->id);
});

it('scopes ClassLevelArm to the active school', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    makeClassLevelArmIn($a);
    makeClassLevelArmIn($b);

    actingInSchool($a);

    expect(ClassLevelArm::count())->toBe(1)
        ->and(ClassLevelArm::first()->school_id)->toBe((int) $a->id);
});

it('scopes MarkingComponent to the active school', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    makeMarkingComponentIn($a);
    makeMarkingComponentIn($b);

    actingInSchool($a);

    expect(MarkingComponent::count())->toBe(1)
        ->and(MarkingComponent::first()->school_id)->toBe((int) $a->id);
});
