<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Authorization enforcement (S5 rollout)
    |--------------------------------------------------------------------------
    |
    | false = OBSERVE mode: App\Support\Authz records would-be denials to the
    |         authz_observations table and lets the request continue.
    | true  = ENFORCE mode: failed checks abort(403).
    |
    | Stays OFF until every observed denial is classified and the enforcement
    | slice is approved (§24). Per-environment via AUTHZ_ENFORCE.
    |
    */
    'enforce' => env('AUTHZ_ENFORCE', false),
];
