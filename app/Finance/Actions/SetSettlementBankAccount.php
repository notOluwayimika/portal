<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Models\BankAccount;
use App\Finance\Models\SchoolFinanceSettings;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Support\Facades\DB;

/**
 * CHOOSE WHERE A SCHOOL'S GATEWAY MONEY SETTLES — the writer
 * `finance_school_settings.settlement_bank_account_id` has never had.
 *
 * `2026_08_29_100000` added that column and `SettlementBankAccount::forSchool()` reads it. Between
 * those two there was nothing: the only way to set it was direct SQL, which records no actor, no
 * time and no previous value. Brookstone's production account is configured this week.
 *
 * ─── IT WRITES THREE THINGS, AND THAT IS NOT REDUNDANCY ──────────────────────────────────────────
 *
 *   the destination   `settlement_bank_account_id`
 *   the provenance    `settlement_bank_account_set_by_user_id` + `..._set_at` on the same row
 *   the history       a `finance.settlement_account_changed` activity entry carrying from → to
 *
 * The row answers "who chose the account we are settling into now". The log answers "what has this
 * ever been". A settings row is UNIQUE(school_id) and every write overwrites, so the previous
 * destination survives nowhere else. `finance_school_settings` deliberately carries no immutability
 * trigger — it is configuration, not an event log (`2026_08_29_100000`) — so the trail here cannot
 * be "the row cannot change"; it has to be a recorded act.
 *
 * ─── THE ACTOR IS A REQUIRED ARGUMENT, NOT A LOOKUP ──────────────────────────────────────────────
 *
 * `auth()->user()` is null on the path this will actually be used on first — a console command run
 * by an operator on Friday. An action that reached for the authenticated user would record NULL for
 * exactly the change this exists to attribute. So the caller must name a human, and the console
 * command refuses to run without one.
 *
 * ─── TWO REFUSALS, BOTH FAIL-CLOSED ─────────────────────────────────────────────────────────────
 *
 * A DEACTIVATED account is refused. Settling into a retired account is money arriving somewhere the
 * school has said it no longer uses, and it is silent — the gateway succeeds, the ledger balances,
 * and the funds are in a closed or unwatched account. `deactivated_at` exists precisely to withdraw
 * an account from choice; this is a choice.
 *
 * A CROSS-SCHOOL account is refused, and refused twice. `BankAccount` carries `SchoolScope`, so a
 * foreign account resolves to nothing here; and the column's composite foreign key
 * `(settlement_bank_account_id, school_id) -> finance_bank_accounts (id, school_id)` makes the pair
 * non-existent at the database, which is what `2026_08_29_100000` added it for. This layer produces
 * a sentence an operator can act on; the FK is what makes it true even for a hand-written UPDATE.
 *
 * ─── NO APPROVAL STEP, AND THAT IS A KNOWN GAP ──────────────────────────────────────────────────
 *
 * Re-pointing settlement almost certainly wants Executive Director approval — it is the single
 * gesture that redirects every naira of gateway fee income. That is a request table, a policy, a
 * sixth approval feed and the count tests that go with them, and it is not in this change.
 * An audited unapproved change is strictly better than the unaudited invisible one that exists
 * today. Deliberately left open for one week and ticketed:
 * docs/handoff/tickets/settlement-account-change-has-no-approval-step.md
 */
final class SetSettlementBankAccount
{
    /**
     * Point a school's gateway settlement at $accountUuid.
     *
     * @return array{from: ?int, to: int} the ids either side of the change, for the caller to report
     *
     * @throws BusinessRuleException when the account is unknown to the school or deactivated
     */
    public function handle(int $schoolId, string $accountUuid, User $actor): array
    {
        // Enter the school's context explicitly. Off-request this is the ONLY legitimate way to set
        // it (Constitution 13), and on-request it is re-entrant and restores what it found — so the
        // one implementation serves the console command today and an HTTP caller later.
        return ActiveSchool::runFor($schoolId, function () use ($schoolId, $accountUuid, $actor): array {
            $account = BankAccount::query()->where('uuid', $accountUuid)->first();

            // Named by uuid and school id, never by label or account number: an error message is
            // written to logs, transcripts and API responses, and an id answers every question an
            // operator actually has.
            if ($account === null) {
                throw new BusinessRuleException(
                    'No bank account '.$accountUuid.' exists for school#'.$schoolId.'.'
                );
            }

            if (! $account->isActive()) {
                throw new BusinessRuleException(
                    'Bank account '.$accountUuid.' is deactivated and cannot receive settlement. '
                    .'Reactivate it, or choose an active account.'
                );
            }

            $settings = SchoolFinanceSettings::query()->where('school_id', $schoolId)->first();
            $from = $settings?->settlement_bank_account_id === null
                ? null
                : (int) $settings->settlement_bank_account_id;

            // The row write and the audit row in ONE transaction. A settlement change recorded
            // without its trail, or a trail describing a change that rolled back, are both worse
            // than the failure — the same reasoning `AwardStudentDiscount` gives for logging inside
            // the write it describes.
            DB::transaction(function () use ($schoolId, $account, $actor, $from): void {
                SchoolFinanceSettings::query()->updateOrCreate(
                    ['school_id' => $schoolId],
                    [
                        'settlement_bank_account_id' => $account->id,
                        'settlement_bank_account_set_by_user_id' => $actor->getKey(),
                        'settlement_bank_account_set_at' => now(),
                    ],
                );

                activity('finance')
                    ->performedOn($account)
                    ->causedBy($actor)
                    ->event('settlement_account_changed')
                    ->withProperties([
                        // from is null on FIRST configuration. That is a real and distinct state —
                        // "nobody had chosen" is not the same act as "somebody re-pointed it" — and
                        // it is carried as a property rather than as a second event name so a
                        // reader filtering one key sees the whole history of the destination.
                        'from' => ['bank_account_id' => $from],
                        'to' => ['bank_account_id' => $account->id],
                        // Identity WITHOUT the account number: these rows are read by every holder
                        // of activity_log.view, which is the point of the event.
                        'bank_account_uuid' => $account->uuid,
                        'label' => $account->label,
                        'bank_name' => $account->bank_name,
                    ])
                    ->log('settlement_account_changed: '.$account->label);
            });

            return ['from' => $from, 'to' => (int) $account->id];
        });
    }
}
