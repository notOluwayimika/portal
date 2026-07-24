<?php

namespace App\Finance\Console;

use App\Finance\Models\LedgerTransaction;
use App\Finance\Models\StudentAccount;
use App\Models\School;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * The drift DETECTOR for the wallet projection (§15F). It re-derives, per account,
 *
 *     balance_minor  ?=  SUM(signed ledger amount_minor)   for that (school, student)
 *
 * and RAISES (non-zero exit) on any mismatch. It is NOT what maintains the balance —
 * SubledgerPoster::post does that, atomically, on every ledger movement. This is the
 * independent check that the projection has not silently drifted from truth (a raw
 * poke, a missed maintenance path, a bug).
 *
 * WHY A SCHEDULED COMMAND, NOT A TRIGGER (design fork 2): recomputing a full
 * SUM(ledger) on every ledger insert would put a table aggregate in the hot write
 * path of the busiest finance table. The balance is maintained by an O(1) increment
 * at write time; this command verifies it out of band, on the authz:prune cadence.
 *
 * IT LIVES IN App\Finance (not app/Console/Commands): it touches Finance models, and
 * the arch boundary keeps those private to the module (tests/Arch). It is registered
 * in bootstrap/app.php via ->withCommands and scheduled in routes/console.php.
 *
 * §5.4: it reads School-owned data (accounts + ledger), so it iterates Schools
 * EXPLICITLY via ActiveSchool::runFor — NOT School-agnostic like authz:prune. Inside
 * runFor the SchoolScope narrows every query to that School.
 *
 *   (default)   detect and report; exit FAILURE if any account drifted.
 *   --fix       correct drifted balances to the ledger truth (still reports); the
 *               operational repair path. Under --dry-run, --fix reports only.
 *   --dry-run   never write; report what --fix would change.
 */
class ReconcileAccounts extends Command
{
    protected $signature = 'finance:reconcile-accounts
        {--fix : Correct drifted balances to the ledger-derived truth}
        {--dry-run : Report only; never write (pairs with --fix to preview a repair)}';

    protected $description = 'Reconcile finance_student_accounts.balance_minor against SUM(signed ledger) and report drift (§15F)';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $dryRun = (bool) $this->option('dry-run');

        $drifted = 0;
        $checked = 0;

        foreach (School::query()->get() as $school) {
            ActiveSchool::runFor($school->id, function () use (&$drifted, &$checked, $fix, $dryRun) {
                StudentAccount::query()->chunkById(200, function ($accounts) use (&$drifted, &$checked, $fix, $dryRun) {
                    foreach ($accounts as $account) {
                        $checked++;

                        // The authoritative figure: SUM over the append-only ledger
                        // (scoped to this School by the active context, filtered to
                        // this student). Reads the raw column, not the Money cast.
                        $truthKobo = (int) LedgerTransaction::query()
                            ->where('student_id', $account->student_id)
                            ->sum('amount_minor');

                        $storedKobo = $account->balance->toKobo();

                        if ($truthKobo === $storedKobo) {
                            continue;
                        }

                        $drifted++;
                        $this->warn(sprintf(
                            'DRIFT school=%d student=%d: stored=%d ledger=%d (Δ=%d)',
                            $account->school_id, $account->student_id,
                            $storedKobo, $truthKobo, $truthKobo - $storedKobo,
                        ));

                        if ($fix && ! $dryRun) {
                            $account->balance = Money::fromKobo($truthKobo, $account->balance->currency);
                            $account->save();
                            $this->info(sprintf(
                                '  fixed school=%d student=%d → %d',
                                $account->school_id, $account->student_id, $truthKobo,
                            ));
                        }
                    }
                });
            });
        }

        if ($drifted === 0) {
            $this->info("Reconciled {$checked} account(s): no drift.");

            return self::SUCCESS;
        }

        // A repair that actually wrote leaves the projection clean; report success so
        // an operator's `--fix` run is not a red herring. A detect-only run (or a
        // dry-run) that FOUND drift exits non-zero — that is the signal §15F wants.
        if ($fix && ! $dryRun) {
            $this->info("Reconciled {$checked} account(s): fixed {$drifted} drifted.");

            return self::SUCCESS;
        }

        $this->error("Reconciled {$checked} account(s): {$drifted} DRIFTED (run with --fix to correct).");

        return self::FAILURE;
    }
}
