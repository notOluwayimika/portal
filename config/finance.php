<?php

return [

    'gateway' => [

        /*
        |----------------------------------------------------------------------
        | Minimum part-payment — DELIBERATELY HAS NO DEFAULT
        |----------------------------------------------------------------------
        |
        | The smallest amount a payer may send through the gateway, in MINOR UNITS (kobo).
        |
        | THERE IS NO FALLBACK, AND THAT IS THE POINT. Developer 1 has not ruled on this number, and
        | picking one here would settle it by omission — the shape that has bitten this workstream
        | repeatedly, because a default is indistinguishable from a decision once it is in the file.
        | `GatewayMinimumPayment` throws when this is unset, the way `SettlementBankAccount` throws
        | on an unconfigured settlement account: loudly, naming the config key, before any money
        | moves.
        |
        | IT IS NOT COSMETIC. Paystack's NGN 100 flat fee is waived below a NGN 2,500 gross and applies
        | above it, so the fee jumps as the gross crosses that line. Below roughly NGN 2,562 of bill the
        | school nets less than it would have by charging NGN 2,500 — the "dead band" measured in
        | docs/handoff/decisions/paystack-fee-is-solve-for-gross.md §3.1. A minimum set above that
        | band makes it unreachable by construction, which is option 3 in that document. An UNSET
        | minimum is therefore a live hazard, not a gap in configuration.
        |
        | Set FINANCE_GATEWAY_MINIMUM_PART_PAYMENT_MINOR once the ruling arrives.
        */
        'minimum_part_payment_minor' => env('FINANCE_GATEWAY_MINIMUM_PART_PAYMENT_MINOR'),

    ],

];
