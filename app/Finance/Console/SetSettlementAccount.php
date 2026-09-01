<?php

namespace App\Finance\Console;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\SetSettlementBankAccount;
use App\Finance\Models\BankAccount;
use App\Models\School;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Console\Command;

/**
 * The reachable surface for choosing where a school's gateway money settles.
 *
 * ─── WHY A COMMAND AND NOT A SCREEN ─────────────────────────────────────────────────────────────
 *
 * `app/Support/SchoolDay.php:46 (SchoolDay)` records the state of this table: it "carries one
 * substantive column today and no screen to set it from", and it argues that "a setting nobody can
 * reach through a UI is another unreachable surface, and this platform has produced four of those in
 * a fortnight". Adding a fifth — an HTTP endpoint with no screen behind it, a new permission, a
 * FormRequest and a route-access baseline entry — to serve one operator action on one Friday would
 * be exactly that. A command is a surface an operator can actually reach today.
 *
 * The domain logic lives in {@see SetSettlementBankAccount}, not here, so the screen that eventually
 * arrives changes the caller and nothing else.
 *
 * ─── THE ACTOR IS REQUIRED, AND THAT IS THE WHOLE POINT ─────────────────────────────────────────
 *
 * A console command has no authenticated user, and the change this performs is the one that decides
 * where every naira of gateway fee income lands. `--actor` is mandatory: the command refuses rather
 * than recording an anonymous act. It takes a user id or an email and resolves it WITHIN the target
 * school (or a super_admin, who is team-less), so a typo names nobody rather than the wrong person.
 *
 * ─── IT PREVIEWS BEFORE IT WRITES ───────────────────────────────────────────────────────────────
 *
 * `--dry-run` reports what would change and writes nothing — including no activity row, because a
 * preview that leaves a trail claiming a change happened is worse than no preview. The same shape
 * `finance:reconcile-accounts` uses.
 *
 *   php artisan finance:set-settlement-account --school=2 --account=<uuid> --actor=bursar@example.test
 *   php artisan finance:set-settlement-account --school=2 --account=<uuid> --actor=17 --dry-run
 */
class SetSettlementAccount extends Command
{
    protected $signature = 'finance:set-settlement-account
        {--school= : School id whose settlement account is being set}
        {--account= : Bank account UUID to settle into (must be active and belong to that school)}
        {--actor= : User id or email performing this change — required, and recorded}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Point a school\'s gateway settlement at a bank account, recording who chose it and when';

    public function handle(SetSettlementBankAccount $action): int
    {
        $schoolId = (int) $this->option('school');
        $accountUuid = (string) $this->option('account');
        $actorRef = (string) $this->option('actor');

        if ($schoolId <= 0 || $accountUuid === '' || $actorRef === '') {
            $this->error('--school, --account and --actor are all required.');
            $this->line('  --actor is not optional by oversight: this change decides where a school\'s');
            $this->line('  gateway income lands, and an unattributed one is the state this command exists to end.');

            return self::FAILURE;
        }

        $school = School::query()->find($schoolId);

        if ($school === null) {
            $this->error("No school#{$schoolId}.");

            return self::FAILURE;
        }

        $actor = $this->resolveActor($actorRef, $schoolId);

        if ($actor === null) {
            $this->error("No user matching [{$actorRef}] in school#{$schoolId}.");
            $this->line('  Pass a user id or an email. The user must belong to that school.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->preview($schoolId, $accountUuid, $actor);
        }

        try {
            $result = $action->handle($schoolId, $accountUuid, $actor);
        } catch (BusinessRuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'school#%d settlement account: %s -> finance_bank_accounts#%d, set by user#%d.',
            $schoolId,
            $result['from'] === null ? '(none configured)' : 'finance_bank_accounts#'.$result['from'],
            $result['to'],
            $actor->getKey(),
        ));
        $this->line('  Recorded on the settings row and in activity_log as finance.settlement_account_changed.');

        return self::SUCCESS;
    }

    /**
     * Report the change without performing it — no row write, and NO activity row.
     *
     * It re-reads the same two facts the action checks (the account resolves within the school, and
     * it is active) so a preview that says "would change" is one the real run will not refuse. It
     * does not re-implement the refusals: the action is still the authority, and a preview that
     * disagreed with it would be the more dangerous failure.
     */
    private function preview(int $schoolId, string $accountUuid, User $actor): int
    {
        return ActiveSchool::runFor($schoolId, function () use ($schoolId, $accountUuid, $actor): int {
            $account = BankAccount::query()->where('uuid', $accountUuid)->first();

            if ($account === null) {
                $this->error("No bank account {$accountUuid} exists for school#{$schoolId}.");

                return self::FAILURE;
            }

            if (! $account->isActive()) {
                $this->error("Bank account {$accountUuid} is deactivated and cannot receive settlement.");

                return self::FAILURE;
            }

            $this->info(sprintf(
                'DRY RUN — would point school#%d settlement at finance_bank_accounts#%d (%s), as user#%d.',
                $schoolId,
                $account->id,
                $account->label,
                $actor->getKey(),
            ));
            $this->line('  Nothing written: no settings row, no activity entry.');

            return self::SUCCESS;
        });
    }

    /**
     * A user id or an email, resolved WITHIN the target school.
     *
     * School-scoped so a typo that happens to match a user in another school names nobody rather
     * than attributing the act to a stranger. `User` carries no global SchoolScope, so the
     * `school_id` predicate is explicit and is what does the isolating here.
     */
    private function resolveActor(string $ref, int $schoolId): ?User
    {
        $query = User::query()->where('school_id', $schoolId);

        return ctype_digit($ref)
            ? $query->find((int) $ref)
            : $query->where('email', $ref)->first();
    }
}
