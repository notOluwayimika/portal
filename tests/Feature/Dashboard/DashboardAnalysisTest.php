<?php

use App\Exceptions\Dashboard\PiiDetectedException;
use App\Services\Dashboard\DashboardAnalysisService;
use App\Services\Dashboard\ModuleClassificationService;
use App\Services\Dashboard\PiiSanitizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure roles exist for middleware
    foreach (['admin', 'head_of_school', 'teacher', 'guardian'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

test('analysis service generates a structured result', function () {
    $school = al_makeSchool();

    $service = app(DashboardAnalysisService::class);
    $analysis = $service->generate($school);

    expect($analysis)
        ->toBeArray()
        ->toHaveKeys(['school_id', 'school_name', 'analyzed_at', 'active_modules_count', 'is_onboarding_state', 'entities', 'modules', 'data_gaps', 'distributions', 'recent_activities']);
});

test('analysis result contains threshold_used per module', function () {
    $school = al_makeSchool();
    $service = app(DashboardAnalysisService::class);
    $analysis = $service->generate($school);

    foreach ($analysis['modules'] as $moduleName => $module) {
        expect($module)->toHaveKey('threshold_used', "Module '{$moduleName}' missing threshold_used");
        expect($module['threshold_used'])->toHaveKeys(['active_threshold', 'dormant_threshold', 'recency_window_days']);
    }
});

test('new school with no data is in onboarding state', function () {
    $school = al_makeSchool();
    $service = app(DashboardAnalysisService::class);
    $analysis = $service->generate($school);

    expect($analysis['is_onboarding_state'])->toBeTrue();
    expect($analysis['active_modules_count'])->toBe(0);
});

test('module classification reads thresholds from config', function () {
    $school = al_makeSchool();

    // Lower the active threshold so the empty school triggers a different classification
    Config::set('dashboard_thresholds.modules.students.dormant_threshold', 0);
    Config::set('dashboard_thresholds.modules.students.active_threshold', 0);

    $classifier = new ModuleClassificationService((int) $school->id);
    $result = $classifier->classifyAll();

    // With threshold = 0, a school with 0 students should now be 'active' (count >= active_threshold AND is recent is null issue)
    // More precisely: count=0 >= 0 active_threshold but no recency → dormant
    expect($result['students']['status'])->toBe('dormant');
});

test('module classification: rows below dormant_threshold → empty', function () {
    $school = al_makeSchool();

    Config::set('dashboard_thresholds.modules.students.dormant_threshold', 100);
    Config::set('dashboard_thresholds.modules.students.active_threshold', 500);

    $classifier = new ModuleClassificationService((int) $school->id);
    $result = $classifier->classifyAll();

    // School has 0 students, dormant_threshold is 100 → should be empty
    expect($result['students']['status'])->toBe('empty');
});

test('module classification: rows >= dormant_threshold but stale → dormant', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);

    // Insert students with old created_at
    foreach (range(1, 10) as $i) {
        \Illuminate\Support\Facades\DB::table('students')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'school_id' => $school->id,
            'user_id' => $user->id,
            'first_name' => "Student{$i}",
            'last_name' => 'Test',
            'admission_number' => "ADM{$i}",
            'gender' => 'male',
            'date_of_birth' => '2010-01-01',
            'created_at' => now()->subDays(60),
            'updated_at' => now()->subDays(60),
        ]);
    }

    Config::set('dashboard_thresholds.modules.students.dormant_threshold', 5);
    Config::set('dashboard_thresholds.modules.students.active_threshold', 50);
    Config::set('dashboard_thresholds.modules.students.recency_window_days', 30);

    $classifier = new ModuleClassificationService((int) $school->id);
    $result = $classifier->classifyAll();

    // 10 students >= dormant_threshold (5), but last created 60 days ago (> 30-day window) → dormant
    expect($result['students']['status'])->toBe('dormant');
});

test('missing table for a module does not abort analysis', function () {
    $school = al_makeSchool();
    $service = app(DashboardAnalysisService::class);

    // attendance_records table doesn't exist — should not throw
    $analysis = $service->generate($school);

    expect($analysis['modules']['attendance']['status'])->toBe('empty');
});

test('pii sanitization service rejects emails', function () {
    $pii = new PiiSanitizationService();

    expect(fn() => $pii->scan(['field' => 'test@example.com']))
        ->toThrow(PiiDetectedException::class);
});

test('pii sanitization service rejects person names in name fields', function () {
    $pii = new PiiSanitizationService();

    expect(fn() => $pii->scan(['first_name' => 'John Smith']))
        ->toThrow(PiiDetectedException::class);
});

test('pii sanitization service allows synthetic placeholder names', function () {
    $pii = new PiiSanitizationService();

    // Should not throw for synthetic placeholders
    $pii->scan(['first_name' => 'student_1']);
    $pii->scan(['first_name' => 'teacher_42']);

    expect(true)->toBeTrue();
});

test('pii sanitization service allows school_name', function () {
    $pii = new PiiSanitizationService();

    // school_name is metadata, not a person name
    $pii->scan(['school_name' => 'Sunrise Academy']);

    expect(true)->toBeTrue();
});

test('analysis file is written to storage', function () {
    $school = al_makeSchool();
    $service = app(DashboardAnalysisService::class);
    $service->generate($school);

    $dir = storage_path('app/dashboard-analysis');
    $files = glob("{$dir}/{$school->id}-*.json");

    expect($files)->not->toBeEmpty();

    $data = json_decode(file_get_contents($files[0]), true);
    expect($data)->toHaveKey('school_id');
});
