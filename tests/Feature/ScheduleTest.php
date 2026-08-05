<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

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

// ── The mail channel: configuration, and strictly on top of the log ───────────
//
// `Event::emailOutputOnFailure()` captures its recipients inside a closure
// (vendor Event.php:434-445) — there is NO readable accessor for them. Read that method before
// changing these arms: the only honest assertion is behavioural, so they swap the container's Mailer
// for a double, drive the event's after-callbacks, and read the addresses off the `to()` call the
// event actually makes.

/**
 * Rebuild the schedule with a given recipient list. routes/console.php reads the config ONCE at
 * boot, so the config must be in place before the file is evaluated — forgetting the singleton and
 * re-requiring is what makes both states reachable in one process.
 *
 * @param  list<string>  $recipients
 * @return list<Event>
 */
function sch_rebuild(array $recipients): array
{
    config()->set('monitoring.alerts.recipients', $recipients);

    // BOTH are needed. forgetInstance() drops the container binding, but the Schedule FACADE caches
    // its own resolved instance — without clearing that, `Schedule::command()` inside the required
    // file registers into the old object and `app(Schedule::class)` comes back empty.
    app()->forgetInstance(Schedule::class);
    ScheduleFacade::clearResolvedInstance(Schedule::class);

    require base_path('routes/console.php');

    return app(Schedule::class)->events();
}

/**
 * Drive one event's failure path and report what it did with the mailer.
 *
 * `raw` being CALLED AT ALL is the load-bearing half. Asserting only the address list cannot tell
 * "no mail callback was attached" from "a mail callback ran with an empty list" — and those are
 * exactly the two states the empty-recipient config has to distinguish.
 *
 * @return array{called: bool, to: list<string>}
 */
function sch_mailAttempt(Event $event): array
{
    $captured = [];
    $called = false;

    $message = Mockery::mock();
    $message->shouldReceive('to')->andReturnUsing(function ($addresses) use (&$captured, $message) {
        $captured = array_merge($captured, (array) $addresses);

        return $message;
    });
    $message->shouldReceive('subject')->andReturnSelf();

    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('raw')->andReturnUsing(function ($text, $callback) use ($message, &$called) {
        $called = true;
        $callback($message);
    });
    app()->instance(Mailer::class, $mailer);

    $event->exitCode = 1;
    $event->callAfterCallbacks(app());

    return ['called' => $called, 'to' => array_values($captured)];
}

it('mails every scheduled detector to the configured recipients — and still logs', function () {
    $recipients = ['ops@example.test', 'second@example.test'];
    $events = sch_rebuild($recipients);

    expect($events)->toHaveCount(5);

    Log::spy();

    foreach ($events as $event) {
        $attempt = sch_mailAttempt($event);

        expect($attempt['called'])->toBeTrue("[{$event->command}] attached no mail callback")
            ->and($attempt['to'])->toBe($recipients, "[{$event->command}] did not mail the configured recipients");
    }

    // MAIL IS ESCALATION, NOT REPLACEMENT. Log::error is the floor and stays attached even when a
    // recipient list exists — `emailOutputOnFailure` fails silently on a misconfigured mailer, and
    // .env.example still points MAIL_HOST at smtp.mailtrap.io, so a channel that can vanish without
    // saying so cannot be the only one. Without this assertion, moving the log into an `else` is
    // invisible.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => $message === 'Scheduled detector failed')
        ->times(5);
});

it('attaches NO mail callback at all when no recipients are configured, and still logs', function () {
    // Empty is a SUPPORTED deployment state, not a broken one. The distinction that matters is
    // "no callback" versus "a callback with an empty list": the second throws at send time
    // (`An email must have a "To", "Cc", or "Bcc" header`) on a real mailer, so an unconditional
    // `emailOutputOnFailure([])` would turn every nightly failure into a second, unrelated failure.
    // Asserting the address list is empty would NOT catch that — a mock happily accepts `to([])`.
    $events = sch_rebuild([]);

    expect($events)->toHaveCount(5);

    Log::spy();

    foreach ($events as $event) {
        $attempt = sch_mailAttempt($event);

        expect($attempt['called'])->toBeFalse("[{$event->command}] attached a mail callback with no recipients configured")
            ->and($attempt['to'])->toBe([]);
    }

    // The floor is still there — five failures driven above, five log lines.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => $message === 'Scheduled detector failed')
        ->times(5);
});
