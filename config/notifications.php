<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rollout
    |--------------------------------------------------------------------------
    |
    | Ships DARK, per the repo convention for new subsystems. With this false,
    | Notifier::send() records nothing and enqueues nothing, so every call site
    | added in this release is inert until the flag is turned on — and turning it
    | back off is instant and needs no redeploy.
    |
    */

    'enabled' => env('NOTIFICATIONS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Notification work runs on its OWN database queue table (`notification_jobs`,
    | see config/queue.php), not on the shared `jobs` table. No second database is
    | available on the current host, so this is the reachable form of isolation:
    | a fan-out burst cannot starve an import or an export, which is the whole
    | benefit a Redis queue would have bought here.
    |
    */

    'queue' => [
        'connection' => env('NOTIFICATIONS_QUEUE_CONNECTION', 'notifications'),
        'fanout' => env('NOTIFICATIONS_QUEUE_FANOUT', 'default'),
        // The per-delivery send queue. Defaults to the fan-out queue so ONE worker
        // covers both — splitting them is an option, not a requirement, and a second
        // queue nobody drains is worse than a shared one that works.
        'send' => env('NOTIFICATIONS_QUEUE_SEND', env('NOTIFICATIONS_QUEUE_FANOUT', 'default')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue health
    |--------------------------------------------------------------------------
    |
    | The worker is a cron-invoked `queue:work`, not a supervised daemon, so
    | "did it run?" is the failure most likely to pass unnoticed. A delivery still
    | pending after this many minutes means the scheduler has stopped — the
    | threshold is minutes rather than the hour a daemon would warrant, because
    | the invocation is supposed to be every minute.
    |
    */

    'health' => [
        'stuck_after_minutes' => env('NOTIFICATIONS_STUCK_AFTER_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Address normalization
    |--------------------------------------------------------------------------
    |
    | The ISO region assumed when a phone number is written in national format
    | (`08031234567`), which is the ordinary way a number is typed here and is
    | meaningless without a country.
    |
    | A REGION, not a calling code: validity is evaluated against that region's
    | actual number plan (libphonenumber), so this genuinely makes a second country
    | a deployment change. The earlier calling-code form could only prefix digits —
    | it could not tell a real mobile from `+2341234567890`.
    |
    */

    'default_region' => env('NOTIFICATIONS_DEFAULT_REGION', 'NG'),

    /*
    |--------------------------------------------------------------------------
    | Feed
    |--------------------------------------------------------------------------
    |
    | `poll_seconds` drives the client's unread-count interval. v1 has no push
    | transport — BROADCAST_CONNECTION is `log` and there is no Reverb — so this
    | interval IS the real-time story, and it is stated in one place rather than
    | hard-coded in a hook.
    |
    */

    'feed' => [
        'page_size' => 20,
        'poll_seconds' => env('NOTIFICATIONS_POLL_SECONDS', 45),
    ],

];
