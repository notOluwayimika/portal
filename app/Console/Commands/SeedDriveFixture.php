<?php

namespace App\Console\Commands;

use App\Finance\Console\DriveFinanceStates;
use App\Support\ActiveSchool;
use Database\Seeders\DriveCastSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stands up the Finance visual-drive fixture (docs/finance/drive-environment.md): every state a
 * human needs to look at, produced by executing the REAL Actions, never by writing rows.
 *
 * Three parts, split to respect the module boundaries: {@see DriveCastSeeder} (database/seeders)
 * creates the schools / users / students / ENROLLMENTS; {@see DriveFinanceStates} (App\Finance —
 * the only place the Finance Actions may be used) bills the enrollment UUIDs; and THIS command is
 * the GUARD + the idempotent reset that orchestrates them. Finance never reaches into Academics;
 * it bills a UUID it is handed, exactly as production bills a UUID resolved through the ACL port.
 *
 * 2FA is satisfied HONESTLY, not bypassed: seeded users carry no 2FA secret and a drive env has
 * `rbac.two_factor_enforced` off by design (non-production default) — plain login reaches the page.
 * No auth path is touched.
 *
 * GUARDED (structural refusals, proven not asserted): refuses unless APP_ENV=drive, and again if
 * the connected database name looks like the dev/staging/test DB — it migrate:fresh-es, so it must
 * be incapable of touching a real DB. IDEMPOTENT: migrate:fresh first, so a re-run reproduces the
 * same fixture with no duplication.
 */
class SeedDriveFixture extends Command
{
    protected $signature = 'finance:seed-drive-fixture';

    protected $description = 'Seed the Finance visual-drive fixture (drive env ONLY) — every state via the real Actions';

    /** Databases this command must NEVER touch, whatever the env says. */
    private const FORBIDDEN_DB_MARKERS = ['brookstone', 'staging', 'prod', 'portal_testing'];

    public function handle(): int
    {
        // Guard 1 — drive env ONLY. The dev instance is `local`, staging its own, prod `production`.
        if (! $this->getLaravel()->environment('drive')) {
            $this->error('REFUSED: finance:seed-drive-fixture runs ONLY in APP_ENV=drive (got: '
                .$this->getLaravel()->environment().'). It migrate:fresh-es the database — it must never touch dev, staging or prod.');

            return self::FAILURE;
        }

        // Guard 2 — the database name itself must not look like a real one (belt to Guard 1's braces).
        $db = (string) DB::connection()->getDatabaseName();
        foreach (self::FORBIDDEN_DB_MARKERS as $marker) {
            if (str_contains(strtolower($db), $marker)) {
                $this->error("REFUSED: database [{$db}] matches a forbidden marker [{$marker}]. Point the drive env at a throwaway database.");

                return self::FAILURE;
            }
        }

        $this->warn("Drive fixture → database [{$db}]. Wiping and reseeding from zero…");

        // Idempotent reset (drive DB only; guarded above), then the RBAC map + the cast.
        $this->call('migrate:fresh', ['--force' => true]);
        (new RbacSeeder)->run();

        $cast = new DriveCastSeeder;
        $cast->run();

        // Finance states — the cast handed us the enrollment UUIDs; the driver bills them.
        $states = new DriveFinanceStates($cast->maker, $cast->checker);
        $e = $cast->enrollments;

        ActiveSchool::runFor($cast->schoolAId, function () use ($states, $e) {
            $states->unpaid($e['ursula']);
            $states->partPaid($e['paula']);
            $states->settledByPayment($e['sam']);
            $states->settledByCreditNote($e['cara']);
            $states->settledThenCredited($e['oscar']);
            $states->pendingCreditNote($e['patCredit']);
            $states->pendingVoid($e['patVoid']);
            $states->approvedVoid($e['otto']); // only invoice is void
        });
        ActiveSchool::runFor($cast->schoolBId, fn () => $states->plainInvoice($e['bola'], 250000));

        $this->report();

        return self::SUCCESS;
    }

    private function report(): void
    {
        $this->newLine();
        $this->info('Drive fixture seeded. Sign in at APP_URL with any user below (password: '.DriveCastSeeder::PASSWORD.'):');
        $this->table(
            ['Role in the drive', 'Email'],
            [
                ['Maker (accounts_officer)', 'maker@drive.test'],
                ['Full checker (finance_director)', 'checker@drive.test'],
                ['Void-only checker (no credit-note.approve)', 'void-checker@drive.test'],
                ['Super admin', 'super@drive.test'],
                ['School B bursar (isolation)', 'school-b@drive.test'],
            ],
        );
        $this->info('Statements: open /finance and click a student; the queue is /finance/approvals.');
    }
}
