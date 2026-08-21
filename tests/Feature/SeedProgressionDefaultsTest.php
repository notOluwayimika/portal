<?php

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelExamType;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------

function spd_session(School $school, string $name = '2025/2026')
{
    return AcademicSession::create([
        'school_id' => $school->id,
        'name' => $name,
        'slug' => 'sess-'.Str::random(8),
        'is_current' => false,
    ]);
}

function spd_term($session, int $order): Term
{
    return Term::create([
        'academic_session_id' => $session->id,
        'school_id' => $session->school_id,
        'name' => "Term {$order}",
        'slug' => 'term-'.Str::random(8),
        'order' => $order,
        'start_date' => now()->addMonths($order * 3),
        'end_date' => now()->addMonths($order * 3 + 2),
        'status' => 'active',
    ]);
}

function spd_level(School $school, string $name, int $order, array $attrs = []): ClassLevel
{
    return ClassLevel::forceCreate(array_merge([
        'school_id' => $school->id, 'name' => $name, 'order' => $order,
    ], $attrs));
}

function spd_arm(School $school, ClassLevel $level, string $label = 'B'): ClassLevelArm
{
    return ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $level->id,
        'arm_id' => Arm::firstOrCreate(['school_id' => $school->id, 'label' => $label])->id,
    ]);
}

function spd_curriculum(School $school, ClassLevelArm $arm, Term $term, ExamType $examType): Curriculum
{
    return Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $arm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
    ]);
}

function spd_examType(School $school, string $name): ExamType
{
    return ExamType::create([
        'school_id' => $school->id, 'name' => $name, 'slug' => 'et-'.Str::random(8),
    ]);
}

/** A school with Year 7 (slots 1,2) -> Year 8 (slot 3), one exam type each. */
function spd_world(): array
{
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    $session = spd_session($school);
    $t1 = spd_term($session, 1);
    $t2 = spd_term($session, 2);
    $t3 = spd_term($session, 3);
    $bss = spd_examType($school, 'BSS Grading');

    $y7 = spd_level($school, 'Year 7', 1);
    $y8 = spd_level($school, 'Year 8', 2);
    $arm7 = spd_arm($school, $y7);
    $arm8 = spd_arm($school, $y8);

    // Year 7 runs slots 1 and 2; Year 8 runs slot 3 only.
    spd_curriculum($school, $arm7, $t1, $bss);
    spd_curriculum($school, $arm7, $t2, $bss);
    spd_curriculum($school, $arm8, $t3, $bss);

    return compact('school', 'admin', 'session', 't1', 't2', 't3', 'bss', 'y7', 'y8', 'arm7', 'arm8');
}

function spd_run(array $w, bool $commit = false)
{
    $args = ['school' => $w['school']->id];

    if ($commit) {
        $args['--user'] = $w['admin']->id;
        $args['--commit'] = true;
    }

    return test()->artisan('academics:seed-progression-defaults', $args);
}

function spd_participation(ClassLevel $level): array
{
    return ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)
        ->where('class_level_id', $level->id)
        ->orderBy('term_order')
        ->pluck('term_order')
        ->all();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('infers participation, the exam-type set and the next level from existing curricula', function () {
    $w = spd_world();

    spd_run($w, commit: true)->assertExitCode(0);

    // Participation is the term ORDERS each level's curricula occupy — per level, not per school.
    expect(spd_participation($w['y7']))->toBe([1, 2]);
    expect(spd_participation($w['y8']))->toBe([3]);

    // Exam-type set from the curricula that exist.
    expect(
        ClassLevelExamType::withoutGlobalScope(SchoolScope::class)
            ->where('class_level_id', $w['y7']->id)->pluck('exam_type_id')->all()
    )->toBe([$w['bss']->id]);

    // Single exam type -> the default is unambiguous, so it is proposed.
    expect($w['y7']->fresh()->default_exam_type_id)->toBe($w['bss']->id);

    // Exactly one level at order+1 -> the link is proposed.
    expect($w['y7']->fresh()->next_class_level_id)->toBe($w['y8']->id);
    // Nothing above Year 8 -> terminal, left NULL.
    expect($w['y8']->fresh()->next_class_level_id)->toBeNull();
});

it('seeds participation as non-CCM, since a CCM slot is an explicit decision', function () {
    $w = spd_world();

    spd_run($w, commit: true)->assertExitCode(0);

    expect(
        ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)
            ->where('class_level_id', $w['y7']->id)->pluck('is_ccm')->unique()->all()
    )->toBe([false]);
});

it('leaves an AMBIGUOUS next order NULL and names the level in the review block', function () {
    // Asserts the REPORT, not merely that the column stayed null — a silent skip and a reported skip
    // are the same database state and completely different operator experiences.
    $w = spd_world();
    spd_level($w['school'], 'Year 8 (Science)', 2); // a second level at order 2

    spd_run($w)
        ->expectsOutputToContain('REVIEW THESE')
        ->expectsOutputToContain('Year 7')
        ->assertExitCode(0);

    spd_run($w, commit: true)->assertExitCode(0);

    expect($w['y7']->fresh()->next_class_level_id)->toBeNull();
});

it('leaves the default exam type NULL and reports it when a level runs several', function () {
    $w = spd_world();
    $waec = spd_examType($w['school'], 'WAEC Grading');
    spd_curriculum($w['school'], $w['arm7'], $w['t1'], $waec);

    spd_run($w)
        ->expectsOutputToContain('REVIEW THESE')
        ->expectsOutputToContain('exam types')
        ->assertExitCode(0);

    spd_run($w, commit: true)->assertExitCode(0);

    expect($w['y7']->fresh()->default_exam_type_id)->toBeNull();
    // But the SET still gets both — membership is inferable even when the default is not.
    expect(
        ClassLevelExamType::withoutGlobalScope(SchoolScope::class)
            ->where('class_level_id', $w['y7']->id)->count()
    )->toBe(2);
});

it('is a dry run by default — it writes nothing at all', function () {
    $w = spd_world();

    spd_run($w)->expectsOutputToContain('DRY RUN')->assertExitCode(0);

    expect(ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)->count())->toBe(0);
    expect(ClassLevelExamType::withoutGlobalScope(SchoolScope::class)->count())->toBe(0);
    expect($w['y7']->fresh()->next_class_level_id)->toBeNull();
    expect($w['y7']->fresh()->default_exam_type_id)->toBeNull();
});

it('refuses --commit without --user rather than attributing the config to nobody', function () {
    $w = spd_world();

    test()->artisan('academics:seed-progression-defaults', [
        'school' => $w['school']->id, '--commit' => true,
    ])->assertExitCode(1);

    expect(ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)->count())->toBe(0);
});

it('FILLS BLANKS ONLY — a re-run changes nothing, and never overwrites a hand edit', function () {
    // The property that lets this be re-run after an operator has corrected something. Asserted on
    // BOTH paths: an unchanged second run, and a run after a deliberate manual edit.
    $w = spd_world();

    spd_run($w, commit: true)->assertExitCode(0);

    $participationAfterFirst = ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)->count();
    $examTypesAfterFirst = ClassLevelExamType::withoutGlobalScope(SchoolScope::class)->count();

    // A human disagrees with the drafted link and points Year 7 somewhere else.
    $y9 = spd_level($w['school'], 'Year 9', 3);
    $w['y7']->update(['next_class_level_id' => $y9->id]);

    spd_run($w, commit: true)->assertExitCode(0);

    // The hand edit SURVIVES — this is the whole point.
    expect($w['y7']->fresh()->next_class_level_id)->toBe($y9->id);
    // And no duplicate rows.
    expect(ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)->count())->toBe($participationAfterFirst);
    expect(ClassLevelExamType::withoutGlobalScope(SchoolScope::class)->count())->toBe($examTypesAfterFirst);
});

it('never touches another schools levels', function () {
    $mine = spd_world();
    $theirs = spd_world();

    spd_run($mine, commit: true)->assertExitCode(0);

    // Mine drafted...
    expect($mine['y7']->fresh()->next_class_level_id)->toBe($mine['y8']->id);
    // ...theirs untouched, by id rather than by count.
    expect($theirs['y7']->fresh()->next_class_level_id)->toBeNull();
    expect($theirs['y7']->fresh()->default_exam_type_id)->toBeNull();
    expect(spd_participation($theirs['y7']))->toBe([]);
});

it('reports a level with no curricula to infer from rather than seeding it silently', function () {
    // A level with no curricula yields no participation, which makes both jobs no-op for it with no
    // error anywhere — so the command has to say so out loud.
    $w = spd_world();
    spd_level($w['school'], 'Year 9', 3);

    spd_run($w)
        ->expectsOutputToContain('NO TERM SLOTS INFERRED')
        ->expectsOutputToContain('Year 9')
        ->assertExitCode(0);
});
