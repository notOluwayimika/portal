<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operational alerting
    |--------------------------------------------------------------------------
    |
    | Read by routes/console.php, and by nothing else. Every scheduled task there
    | is a DETECTOR: it exits non-zero to say something is wrong. This file
    | answers one question about that signal — who, if anyone, is pushed a copy.
    |
    | DELIBERATELY NOT config/notifications.php. That subsystem ships dark
    | (`enabled => false`), and an operator reading the scheduler alert inside it
    | would reasonably conclude the alert was off too. One concern per file, and
    | this concern is live.
    |
    | COMMA-SEPARATED, because the value comes from a single .env line and there
    | is more than one person who might need to know a nightly detector failed.
    | Trimmed and filtered so `a@x, ,b@x` is two recipients rather than three,
    | and so a trailing comma is not an empty address the mailer would choke on.
    |
    | EMPTY IS A LEGITIMATE DEPLOYMENT STATE, not a misconfiguration. With no
    | recipients the scheduler attaches no mail callback at all and the channel
    | is the Log::error that routes/console.php attaches unconditionally — a
    | failure stays findable, it is simply not pushed. There is deliberately no
    | default address: inventing one is how an alert arrives somewhere nobody
    | reads, which is worse than no alert because it looks like coverage.
    |
    | Mail is escalation ON TOP OF the log, never a replacement for it —
    | `emailOutputOnFailure` fails silently when the mailer is misconfigured.
    |
    */

    'alerts' => [
        'recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MONITORING_ALERT_RECIPIENTS', '')),
        ))),
    ],

];
