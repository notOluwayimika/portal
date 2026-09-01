<?php

namespace App\Finance\Services;

use App\Exceptions\BusinessRuleException;
use App\Finance\Console\GatewayDiscrepancyReport;

/**
 * How long a checkout may sit unanswered before the discrepancy report calls it a finding — read
 * from config, with NO DEFAULT, and overridable per run.
 *
 * Same shape as {@see SettlementBankAccount}: refuse loudly at the point of use, naming the key,
 * rather than standing in for a decision nobody took. (`GatewayMinimumPayment`, the third of the
 * family, is not referenced by symbol here because it is not on this base — it arrives with the
 * initiation branch. A `{@see}` at a class that does not exist is a citation nobody can follow.)
 * This one refuses for a reason neither of those has.
 *
 * THE NUMBER IS THE REPORT'S MEANING, NOT A TUNING PARAMETER. At one hour every half-finished
 * checkout is a finding and the report is a list nobody reads; at a week, money the provider took
 * and this system never recorded is invisible for seven days. Those are two different reports, not
 * two settings of one. A default would be that choice made by omission — and this project has paid
 * for decisions arrived at by silence often enough to have written the rule down.
 *
 * IT IS UNRULED, AND THE GAP IS NAMED RATHER THAN FILLED. It is not a data-model question, so it is
 * not Developer 1's; it has not been put to Segun. See {@see GatewayDiscrepancyReport}'s
 * docblock, which states the operational half open rather than inventing an owner for it.
 *
 * THE OVERRIDE DOES NOT BECOME A DEFAULT. `--pending-hours=N` lets an operator run against a
 * different window by hand. When the flag is absent the config value is used; when the config value
 * is absent the command refuses. An override with nothing to override is still a refusal — which is
 * the whole point, because the tempting shortcut is to let the flag's own default paper over the
 * missing ruling.
 */
final class GatewayPendingWindow
{
    /**
     * @param  int|string|null  $override  the value of `--pending-hours`, absent as null
     *
     * @throws BusinessRuleException when neither the override nor the config carries a usable value
     */
    public function hours(int|string|null $override = null): int
    {
        if ($override !== null && $override !== '') {
            return $this->validated($override, 'The --pending-hours option');
        }

        $configured = config('finance.discrepancy.pending_hours');

        if ($configured === null || $configured === '') {
            throw new BusinessRuleException(
                'No pending window is configured '
                .'(finance.discrepancy.pending_hours / FINANCE_GATEWAY_DISCREPANCY_PENDING_HOURS), '
                .'and --pending-hours was not given. This one has no default on purpose: the number is '
                .'the report\'s meaning, not a tuning parameter, and nobody has ruled it yet.'
            );
        }

        return $this->validated($configured, 'The configured pending window');
    }

    /**
     * @throws BusinessRuleException
     */
    private function validated(int|string $value, string $subject): int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value <= 0) {
            throw new BusinessRuleException(
                $subject.' must be a positive whole number of hours; received: '.var_export($value, true)
            );
        }

        return (int) $value;
    }
}
