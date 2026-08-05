<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\File;
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

// ── config/monitoring.php itself ─────────────────────────────────────────────
//
// The two mail arms above are BLIND to this file. They call `config()->set(…)`, which writes the key
// into the runtime repository whether or not `config/monitoring.php` exists — so deleting the file
// leaves them green while routes/console.php:87 falls through to its `[]` default and the mail
// channel is dead in production with no red anywhere.
//
// Nothing else reaches this file either: not bin/quality, not a lint, not another test. Its docblock
// argues the parsing rules — trim, drop empties, no default, empty-is-legitimate — at length, and
// two statements being in a file is not a gate. Same failure mode as the attach order pinned below.
//
// These arms therefore do NOT use config()->set. They read the real file.

/**
 * Snapshot the three places an env var can live, so a restore is total rather than best-effort.
 *
 * All three are set/read because the arm must not depend on which Dotenv adapter is active, and all
 * three are restored because a leaked MONITORING_ALERT_RECIPIENTS would silently change what every
 * later arm in this file sees.
 *
 * @return array{env: ?string, server: ?string, put: string|false}
 */
function sch_envSnapshot(string $key): array
{
    return [
        'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
        'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
        'put' => getenv($key),
    ];
}

/** @param  array{env: ?string, server: ?string, put: string|false}  $snapshot */
function sch_envRestore(string $key, array $snapshot): void
{
    $snapshot['env'] === null ? ($_ENV = array_diff_key($_ENV, [$key => null])) : $_ENV[$key] = $snapshot['env'];
    $snapshot['server'] === null ? ($_SERVER = array_diff_key($_SERVER, [$key => null])) : $_SERVER[$key] = $snapshot['server'];
    $snapshot['put'] === false ? putenv($key) : putenv($key.'='.$snapshot['put']);
}

function sch_envSet(string $key, string $value): void
{
    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv($key.'='.$value);
}

function sch_envUnset(string $key): void
{
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);
}

it('loads config/monitoring.php under the exact key the consumer reads', function () {
    // routes/console.php:87 is `config('monitoring.alerts.recipients', [])`. That `[]` default is
    // what makes a rename SILENT: a moved file or a renamed key degrades into "no recipients
    // configured", which is a SUPPORTED state — the scheduler simply attaches no mail callback and
    // logs as usual. Nothing is broken-looking. This arm is the only thing that can tell a
    // deliberate empty config from a config that is no longer being read at all.
    expect(File::exists(base_path('config/monitoring.php')))->toBeTrue();
    expect(config()->has('monitoring.alerts.recipients'))->toBeTrue();
});

it('parses MONITORING_ALERT_RECIPIENTS: trimmed, empties dropped, re-indexed', function () {
    // Asserted as an exact list, never a count. The point is that the empty middle field and the
    // trailing comma are GONE and the survivors are trimmed and re-indexed by array_values — an
    // empty string surviving into emailOutputOnFailure produces the same
    // `An email must have a "To", "Cc", or "Bcc" header` throw MUTANT I produced on commit 4, which
    // (per the attach-order arm below) escapes callAfterCallbacks for real.
    //
    // All three of $_ENV, $_SERVER and putenv() are set so the arm does not depend on which Dotenv
    // adapter is active, and all three are restored in a finally so no later arm inherits the value.
    $key = 'MONITORING_ALERT_RECIPIENTS';
    $snapshot = sch_envSnapshot($key);

    try {
        sch_envSet($key, 'a@x.test, ,b@x.test,');

        $config = require base_path('config/monitoring.php');

        expect($config['alerts']['recipients'])->toBe(['a@x.test', 'b@x.test']);
    } finally {
        sch_envRestore($key, $snapshot);
    }
});

it('treats an UNSET env var as empty — there is no default address', function () {
    // A default address is worse than none: it looks like coverage while arriving somewhere nobody
    // reads. The config docblock says so; this arm is what makes it true.
    $key = 'MONITORING_ALERT_RECIPIENTS';
    $snapshot = sch_envSnapshot($key);

    try {
        sch_envUnset($key);

        $config = require base_path('config/monitoring.php');

        expect($config['alerts']['recipients'])->toBe([]);
    } finally {
        sch_envRestore($key, $snapshot);
    }
});

it('keeps the log line when the mailer THROWS — the attach order is what makes the log a floor', function () {
    // WHY THIS ARM EXISTS. `Event::callAfterCallbacks` (vendor Event.php:251-256) is a bare foreach
    // with no try/catch, so an exception in one callback aborts every callback registered AFTER it.
    // In $observed, Log::error's onFailure is attached BEFORE emailOutputOnFailure. That ordering —
    // two statements, in that sequence, and nothing else — is the entire reason a throwing mailer
    // cannot take the log line with it.
    //
    // It is not hypothetical: MUTANT I (emailOutputOnFailure attached unconditionally, so it ran with
    // an empty list) produced a real escaping exception, `An email must have a "To", "Cc", or "Bcc"
    // header`. A misconfigured mailer on the server does the same thing every night. Mail is
    // escalation; the log is the floor; swap the two lines and the floor is gone precisely when it is
    // needed most — the run that was already failing.
    $events = sch_rebuild(['ops@example.test']);

    $event = collect($events)->first(fn ($e) => str_contains($e->command ?? '', 'finance:audit-duty-separation'));
    expect($event)->not->toBeNull();

    $mailerCalled = false;
    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('raw')->andReturnUsing(function () use (&$mailerCalled): void {
        $mailerCalled = true;

        throw new RuntimeException('mailer is misconfigured');
    });
    app()->instance(Mailer::class, $mailer);

    Log::spy();

    $event->exitCode = 1;

    // The exception escapes callAfterCallbacks — that is the vendor behaviour this arm is about, so
    // it is caught here rather than papered over.
    try {
        $event->callAfterCallbacks(app());
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('mailer is misconfigured');
    }

    // The mail path really ran, so this arm cannot pass by the mailer having been skipped...
    expect($mailerCalled)->toBeTrue('the mailer was never called — this arm would pass vacuously');

    // ...and the log line survived it, because it was attached first.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Scheduled detector failed'
            && ($context['command'] ?? null) === 'finance:audit-duty-separation')
        ->once();
});
