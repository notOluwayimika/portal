<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/**
 * Locks the codebase's scheduling registration point (routes/console.php): which detectors run, how
 * often, and — the part that was missing — that a failure is observed rather than discarded.
 *
 * WHY THE onFailure ASSERTIONS EXIST. Every scheduled command here is a DETECTOR: it exits non-zero
 * to say something is wrong. Until 2026-08-05 nothing consumed that signal — no onFailure, no
 * emailOutputOnFailure, no sendOutputTo, no ping anywhere in the file — and
 * `finance:audit-duty-separation` had been exiting non-zero on every run since 43dfbe8 (2026-07-25).
 * A detector nobody hears is not a control, so removing the observation must be as red as removing
 * the schedule.
 */
/** @return list<Event> */
function sch_events(): array
{
    return app(Schedule::class)->events();
}

function sch_find(string $needle): ?Event
{
    return collect(sch_events())->first(fn ($e) => str_contains($e->command ?? '', $needle));
}

dataset('scheduled detectors', [
    'authz:prune' => ['authz:prune', '0 0 * * *'],
    'finance:reconcile-accounts' => ['finance:reconcile-accounts', '0 0 * * *'],
    'finance:audit-ledger-coherence' => ['finance:audit-ledger-coherence', '0 0 * * *'],
    'finance:audit-duty-separation' => ['finance:audit-duty-separation', '0 0 * * *'],
    'finance:check-staffing-readiness' => ['finance:check-staffing-readiness', '0 0 * * *'],
]);

it('registers each scheduled detector, daily', function (string $command, string $cron) {
    $event = sch_find($command);

    expect($event)->not->toBeNull("[{$command}] is not registered in routes/console.php")
        ->and($event->expression)->toBe($cron);
})->with('scheduled detectors');

it('schedules exactly these five and no unobserved sixth', function () {
    // A sixth task added without a cron assertion above would slip through the per-command arms;
    // this one makes ADDING a task red too, so the next author has to come here and say what its
    // failure means.
    expect(sch_events())->toHaveCount(5);
});

it('logs the command and its exit code when ANY scheduled detector fails', function () {
    // Asserting "a callback is registered" would pass on an empty closure, so this drives each event
    // for real: set a failing exit code and invoke its after-callbacks the way the scheduler does,
    // then assert the log line carries WHAT failed and HOW. All five, not the two RBAC ones — the
    // finance detectors had the same hole and fixing it for two would be arbitrary.
    Log::spy();

    $expected = [
        'authz:prune',
        'finance:reconcile-accounts',
        'finance:audit-ledger-coherence',
        'finance:audit-duty-separation',
        'finance:check-staffing-readiness',
    ];

    foreach ($expected as $i => $command) {
        $event = sch_find($command);
        expect($event)->not->toBeNull("[{$command}] is not registered");

        $event->exitCode = 40 + $i;   // a distinct code per command, so a cross-wired log line fails
        $event->callAfterCallbacks(app());
    }

    foreach ($expected as $i => $command) {
        $code = 40 + $i;
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Scheduled detector failed'
                && ($context['command'] ?? null) === $command
                && ($context['exit_code'] ?? null) === $code)
            ->once();
    }
});

it('does NOT log when a scheduled detector succeeds', function () {
    // onFailure only. A detector that logged an error on every clean run would train the reader to
    // ignore the line, which is the same failure as not logging at all.
    Log::spy();

    $event = sch_find('finance:audit-duty-separation');
    $event->exitCode = 0;
    $event->callAfterCallbacks(app());

    Log::shouldNotHaveReceived('error');
});

it('runs the duty-separation audit against the committed baseline', function () {
    // Without --baseline the command exits non-zero on the 10 pre-existing result.* findings the
    // production copy carries, every night, forever — so "failed" carries no information. The
    // baseline is what makes its exit code mean "something appeared that nobody accepted".
    $event = sch_find('finance:audit-duty-separation');

    expect($event->command)->toContain('--baseline=')
        ->and($event->command)->toContain('duty-separation-baseline.txt');
});
