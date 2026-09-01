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
     * The lowest a configured minimum may be — NGN 1,000, the value Segun ruled on 2026-09-01.
     *
     * IT IS A FLOOR, NOT A DEFAULT. An unset minimum still refuses (see below); this refuses a
     * minimum that is SET too low. The two are different failures and both are real.
     *
     * WHY A FLOOR AT ALL. Paystack waives its NGN 100 flat below a NGN 2,500 gross, so the fee
     * ratio is worst JUST ABOVE that line (5.3% at NGN 2,600) and best at the bottom (1.5% at
     * NGN 1,000). A minimum below this floor puts small payers back in the expensive band — the
     * exact outcome the ruling chose 1,000 to avoid.
     *
     * IT ALSO KEEPS THE DEAD BAND UNREACHABLE, and that is why it is enforced rather than noted.
     * The gross-up has no handling for the discontinuity around NGN 2,462.50 because no bill can
     * reach it — but that is only true while the minimum sits above it. Documenting the dependency
     * would leave it resting on a value someone may later change; refusing the change makes the
     * precondition hold by construction.
     */
    public const MINIMUM_FLOOR_MINOR = 100_000;

    /**
     * @throws BusinessRuleException when the minimum is unset, malformed, or below the floor
     */
    public function forCurrency(string $currency = Money::DEFAULT_CURRENCY): Money
    {
        $minor = config('finance.gateway.minimum_part_payment_minor');

        if ($minor === null || $minor === '') {
            throw new BusinessRuleException(
                'No minimum gateway part-payment is configured '
                .'(finance.gateway.minimum_part_payment_minor / FINANCE_GATEWAY_MINIMUM_PART_PAYMENT_MINOR). '
                .'The value is decided — NGN 1,000 — but a decided value is not a default: an environment '
                .'that never set it must not take payments at whatever this file happened to say.'
            );
        }

        if (! is_numeric($minor) || (int) $minor <= 0) {
            throw new BusinessRuleException(
                'The configured minimum gateway part-payment must be a positive integer number of minor units.'
            );
        }

        if ((int) $minor < self::MINIMUM_FLOOR_MINOR) {
            throw new BusinessRuleException(
                'The configured minimum gateway part-payment is below the floor of '
                .self::MINIMUM_FLOOR_MINOR.' minor units. Below it, small payers fall into the band just '
                .'above the provider fee waiver where the ratio is worst (5.3% at NGN 2,600 against 1.5% at '
                .'NGN 1,000), and the gross-up discontinuity stops being unreachable.'
            );
        }

        return Money::fromKobo((int) $minor, $currency);
    }
}
