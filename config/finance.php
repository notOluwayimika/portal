<?php

return [

    'gateway' => [

        /*
        |----------------------------------------------------------------------
        | Minimum part-payment — DECIDED AT NGN 1,000, AND STILL HAS NO DEFAULT
        |----------------------------------------------------------------------
        |
        | The smallest amount a payer may send through the gateway, in MINOR UNITS (kobo).
        | NGN 1,000 = 100000. Ruled by Segun, 2026-09-01.
        |
        | THE VALUE IS DECIDED; THE DEFAULT IS STILL DELIBERATELY ABSENT. A decided number and a
        | fallback are different things: a fallback would mean an environment that never set this
        | still takes payments, silently, at whatever the file happened to say. `GatewayMinimumPayment`
        | throws when it is unset, the way `SettlementBankAccount` throws on an unconfigured
        | settlement account. Until the environment sets it the endpoint refuses every payment, which
        | is the correct direction to fail.
        |
        | WHY 1,000 AND NOT HIGHER — READ THE RATIO, NOT THE CONCLUSION:
        |
        |   payer pays   fee      as a %
        |   ----------   ------   ------
        |   NGN  1,000   NGN  15    1.5%
        |   NGN  2,600   NGN 139    5.3%
        |   NGN  4,000   NGN 160    4.0%
        |
        | Paystack waives its NGN 100 flat below a NGN 2,500 gross. So the fee ratio is WORST JUST
        | ABOVE that line, not at the bottom of the range. NGN 1,000 keeps small payers inside the
        | 1.5% band.
        |
        | AN EARLIER VERSION OF THIS COMMENT ARGUED THE OPPOSITE — that the minimum existed to push
        | payers above the waiver band. That is backwards, and a comment reasoning toward a HIGHER
        | number is worse than no comment: it is an instruction to make the fee ratio worse, sitting
        | beside the value, carrying the authority of having been written deliberately. The table is
        | here so the next person can check the reasoning rather than take it on trust.
        |
        | THE FLOOR IS ENFORCED, NOT ASSUMED. `GatewayMinimumPayment` refuses a configured value
        | below MINIMUM_FLOOR_MINOR — see that class. A minimum set below the floor would put small
        | payers in the 5% band, which is the thing this number exists to prevent.
        */
        'minimum_part_payment_minor' => env('FINANCE_GATEWAY_MINIMUM_PART_PAYMENT_MINOR'),

    ],

];
