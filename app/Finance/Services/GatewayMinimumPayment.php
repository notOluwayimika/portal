<?php

namespace App\Finance\Services;

use App\Exceptions\BusinessRuleException;
use App\Support\Money;

/**
 * The smallest amount the gateway will accept — read from config, with NO DEFAULT.
 *
 * Modelled on {@see SettlementBankAccount}, deliberately: an unset value is refused loudly at the
 * point of use, naming the key, rather than silently standing in for a decision nobody took.
 *
 * WHY NOT PICK A SENSIBLE NUMBER. Because a sensible number is indistinguishable from a ruling once
 * it is in the file, and this project has repeatedly paid for decisions arrived at by omission — a
 * retention period that became indefinite by silence, an origin default that made "the caller said
 * portal" and "the caller said nothing" the same row. Developer 1 has not answered this one.
 *
 * WHY IT IS NOT COSMETIC. Paystack waives its NGN 100 flat fee below a NGN 2,500 gross, so the fee STEPS
 * as the gross crosses that line and there is a band of bills — up to roughly NGN 2,562 — where the
 * school nets less than it would have from a larger payment. A minimum above the band removes it by
 * construction. Unset, the band is reachable and every payment in it quietly under-recovers.
 */
final class GatewayMinimumPayment
{
    /**
     * @throws BusinessRuleException when the minimum has not been configured
     */
    public function forCurrency(string $currency = Money::DEFAULT_CURRENCY): Money
    {
        $minor = config('finance.gateway.minimum_part_payment_minor');

        if ($minor === null || $minor === '') {
            throw new BusinessRuleException(
                'No minimum gateway part-payment is configured '
                .'(finance.gateway.minimum_part_payment_minor / FINANCE_GATEWAY_MINIMUM_PART_PAYMENT_MINOR). '
                .'Refusing to take a payment until it is set: below roughly NGN 2,562 the provider fee step '
                .'means the school nets less than a larger payment would have returned.'
            );
        }

        if (! is_numeric($minor) || (int) $minor <= 0) {
            throw new BusinessRuleException(
                'The configured minimum gateway part-payment must be a positive integer number of minor units.'
            );
        }

        return Money::fromKobo((int) $minor, $currency);
    }
}
