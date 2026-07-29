<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum session length (minutes)
    |--------------------------------------------------------------------------
    |
    | An impersonation session ends automatically this long after it started,
    | writing its normal `impersonation_ended` audit row. ADR 0045 §4 requires
    | the session to be BOUNDED; per-request restore makes it bounded within a
    | request, and this makes it bounded in wall-clock time, so an unattended
    | browser cannot sit inside someone else's identity indefinitely.
    |
    | Set to 0 to disable expiry (not recommended — it removes a control).
    |
    */

    'max_minutes' => (int) env('IMPERSONATION_MAX_MINUTES', 30),

];
