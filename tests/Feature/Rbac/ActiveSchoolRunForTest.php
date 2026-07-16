<?php

use App\Jobs\Middleware\SchoolAware;
use App\Support\ActiveSchool;

it('sets the active school for the duration of the callback and restores after', function () {
    expect(ActiveSchool::id())->toBeNull();

    $inside = ActiveSchool::runFor(7, fn () => ActiveSchool::id());

    expect($inside)->toBe(7)
        ->and(ActiveSchool::id())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();
});

it('restores context even when the callback throws (finally)', function () {
    try {
        ActiveSchool::runFor(9, function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(ActiveSchool::id())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();
});

it('nested runFor restores the outer context', function () {
    $result = ActiveSchool::runFor(1, fn () => [
        ActiveSchool::runFor(2, fn () => ActiveSchool::id()),
        ActiveSchool::id(),
    ]);

    expect($result)->toBe([2, 1])
        ->and(ActiveSchool::id())->toBeNull();
});

it('does not leak the team id between two jobs on one worker', function () {
    // Simulate two SchoolAware jobs for different Schools running back-to-back.
    ActiveSchool::runFor(3, fn () => expect(getPermissionsTeamId())->toBe(3));
    ActiveSchool::runFor(5, fn () => expect(getPermissionsTeamId())->toBe(5));

    expect(getPermissionsTeamId())->toBeNull();
});

it('SchoolAware middleware runs the job inside its School context', function () {
    $job = new class
    {
        public int $schoolId = 42;

        public ?int $seen = null;
    };

    (new SchoolAware)->handle($job, function ($j) {
        $j->seen = ActiveSchool::id();
    });

    expect($job->seen)->toBe(42)
        ->and(ActiveSchool::id())->toBeNull();
});
