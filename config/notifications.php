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
    | The calling code assumed when a phone number is written in national format
    | (`08031234567`), which is the ordinary way a number is typed here and is
    | meaningless without a country. Config rather than a constant so a school in
    | another country is a deployment change, not a code change.
    |
    */

    'default_calling_code' => env('NOTIFICATIONS_DEFAULT_CALLING_CODE', '234'),

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
