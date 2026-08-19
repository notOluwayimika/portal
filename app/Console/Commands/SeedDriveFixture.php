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
 * GUARDED (structural refusals, proven not asserted): refuses unless APP_ENV=drive, AND requires
 * the database name to be an explicit drive DB (an ALLOWLIST, not a denylist — Rider A: a denylist
 * only refuses the names someone remembered). It migrate:fresh-es, so it must be incapable of
 * touching a real DB. IDEMPOTENT: migrate:fresh first, so a re-run reproduces the same fixture.
 */
class SeedDriveFixture extends Command
{
    protected $signature = 'finance:seed-drive-fixture';

    protected $description = 'Seed the Finance visual-drive fixture (drive env ONLY) — every state via the real Actions';

    /**
     * The database name MUST look like a drive DB (ALLOWLIST, Rider A). A denylist only refuses the
     * names someone thought of — the first `finance_demo`, `school_uat` or `brookstone_pilot` walks
     * straight through. Requiring an explicit `drive` token and refusing everything else by default
     * makes an accidental hit on a real database unrepresentable rather than merely unlisted.
     * Matches e.g. `portal_drive`, `drive`, `my_drive_db`; refuses `finance_demo`, `school_uat`.
     */
    private const DRIVE_DB_PATTERN = '/(^|_)drive(_|$)/';

    public function handle(): int
    {
        // Guard 1 — drive env ONLY. The dev instance is `local`, staging its own, prod `production`.
        if (! $this->getLaravel()->environment('drive')) {
            $this->error('REFUSED: finance:seed-drive-fixture runs ONLY in APP_ENV=drive (got: '
                .$this->getLaravel()->environment().'). It migrate:fresh-es the database — it must never touch dev, staging or prod.');

            return self::FAILURE;
        }

        // Guard 2 — the database name must EXPLICITLY be a drive DB (allowlist, not a denylist).
        $db = (string) DB::connection()->getDatabaseName();
        if (! preg_match(self::DRIVE_DB_PATTERN, strtolower($db))) {
            $this->error("REFUSED: database [{$db}] is not a recognised drive database — its name must contain a `drive` token (e.g. portal_drive). "
                .'This is an ALLOWLIST: everything that is not explicitly a drive DB is refused, so a name a denylist never anticipated (finance_demo, school_uat, brookstone_pilot) cannot slip through.');

            return self::FAILURE;
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

        // A BANK ACCOUNT PER SCHOOL, BEFORE ANY STATE RUNS. School B's only state is a plain invoice,
        // which records no payment — so nothing would ever have created an account there, and the
        // isolation seat (school-b@drive.test holds accounts_officer, which holds
        // finance.fee-schedule.manage) would open the fee-schedules author screen onto an EMPTY account
        // picker and be unable to create a single line. Same failure the academic slot above was written
        // to prevent, one field over. Seeded through DriveFinanceStates::ensureBankAccount so the
        // account_number formula has exactly one definition: the payment paths call the same
        // firstOrCreate and FIND this row rather than making a second one.
        ActiveSchool::runFor($cast->schoolAId, fn () => $states->ensureBankAccount($cast->schoolAId));
        ActiveSchool::runFor($cast->schoolBId, fn () => $states->ensureBankAccount($cast->schoolBId));

        // ONE ACTIVE DISCOUNT POLICY PER SCHOOL, for the same reason and by the same argument as the
        // account above: the U2 discount-policies screen offers propose / amend / retire, and the last
        // two have nothing to act on in a school whose catalog is empty. Seeded through the real
        // submit-then-approve path — the only thing that writes that table — so School B's proposal is
        // made by School B's own bursar. See DriveFinanceStates::ensureDiscountPolicy().
        ActiveSchool::runFor($cast->schoolAId, fn () => $states->ensureDiscountPolicy($cast->schoolAId));
        ActiveSchool::runFor($cast->schoolBId, fn () => $states->ensureDiscountPolicy($cast->schoolBId, $cast->schoolBMaker));

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

        $this->report($cast, $states);

        return self::SUCCESS;
    }

    private function report(DriveCastSeeder $cast, DriveFinanceStates $states): void
    {
        $this->newLine();
        $this->info('Drive fixture seeded. Sign in at APP_URL with any user below (password: '.DriveCastSeeder::PASSWORD.'):');
        $this->table(
            ['Role in the drive', 'Email'],
            [
                ['Maker (accounts_officer)', 'maker@drive.test'],
                ['Full checker (executive_director)', 'checker@drive.test'],
                ['Void-only checker (no credit-note.approve)', 'void-checker@drive.test'],
                ['Super admin', 'super@drive.test'],
                ['School B bursar (isolation)', 'school-b@drive.test'],
                ['Admin (guardians screen)', 'admin@drive.test'],
                ['School B admin (guardian isolation)', 'admin-b@drive.test'],
                ['Guardian editor, NO update_credentials', 'guardian-editor@drive.test'],
            ],
        );
        $this->newLine();

        /*
         * WHAT THE FEE-SCHEDULES SCREEN NEEDS, COUNTED FROM THE DATABASE — not from the seeder's own
         * variables, which would only ever report what the seeder intended. That screen selects a term,
         * a class level and a destination bank account per line; before U1 commit 1 this fixture seeded
         * none of the four, and a drive would have opened on empty selects. These counts are what the
         * next drive reads instead of trusting a paragraph in a brief. Zero in any column means the
         * screen cannot author anything — which is why the bank-accounts column is here beside the
         * academic three rather than left to the comment above it.
         *
         * Read through DB::table rather than the models because AcademicSession, Term and ClassLevel all
         * carry SchoolScope and this runs with no active-School context — the scoped models would return
         * every row or none depending on the context, which is exactly the ambiguity a count must not have.
         */
        $count = fn (string $table, int $schoolId): int => (int) DB::table($table)->where('school_id', $schoolId)->count();

        // The account count comes through DriveFinanceStates, not through the closure above: the boundary
        // lint forbids a `finance_*` table literal outside app/Finance, so the Finance side counts its own
        // table. It reads the scoped model, hence the runFor.
        $accounts = fn (int $schoolId): int => ActiveSchool::runFor($schoolId, fn () => $states->bankAccountCount($schoolId));

        // Same route and same reason as the accounts column: the discount catalog is a `finance_` table
        // the command may not name, and a zero here means the discount-policies screen can only be
        // driven along its create path — amend and retire would have no target.
        $policies = fn (int $schoolId): int => ActiveSchool::runFor($schoolId, fn () => $states->discountPolicyCount($schoolId));

        // Same route and same reason again, for U11's receipt screen — and split by `origin` on
        // purpose. A single payments count would read as coverage while the MIGRATED half, the one
        // the receipt refuses for, still had nothing to render. See DriveFinanceStates::paymentCount.
        $payments = fn (int $schoolId, string $origin): int => ActiveSchool::runFor(
            $schoolId, fn () => $states->paymentCount($schoolId, $origin),
        );

        // STUDENTS AND GUARDIANS, added for the guardian-create drive and counted for the
        // same reason as every column beside them. That screen links a new guardian to
        // children BY ADMISSION NUMBER, so a zero in the Students column means the drive
        // cannot author a single link and is worthless before it opens a browser — the
        // exact failure the academic three were added to prevent. Guardians is expected to
        // be ZERO on a fresh fixture and is printed anyway: it is the denominator the
        // duplicate-warning and the reuse backstop are measured against, so a drive that
        // reports "one guardian row after two submissions" can be checked against where it
        // started rather than asserted.
        $this->info('Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the guardians screen links a new guardian to students by admission number:');
        $this->table(
            ['School', 'Academic sessions', 'Terms', 'Class levels', 'Bank accounts', 'Discount policies', 'Payments (portal)', 'Payments (migrated)', 'Students', 'Guardians'],
            [
                ['A (school#'.$cast->schoolAId.')', $count('academic_sessions', $cast->schoolAId), $count('terms', $cast->schoolAId), $count('class_levels', $cast->schoolAId), $accounts($cast->schoolAId), $policies($cast->schoolAId), $payments($cast->schoolAId, 'portal'), $payments($cast->schoolAId, 'migrated'), $count('students', $cast->schoolAId), $count('guardians', $cast->schoolAId)],
                ['B (school#'.$cast->schoolBId.')', $count('academic_sessions', $cast->schoolBId), $count('terms', $cast->schoolBId), $count('class_levels', $cast->schoolBId), $accounts($cast->schoolBId), $policies($cast->schoolBId), $payments($cast->schoolBId, 'portal'), $payments($cast->schoolBId, 'migrated'), $count('students', $cast->schoolBId), $count('guardians', $cast->schoolBId)],
            ],
        );

        // The admission numbers the guardians screen needs, printed because they are
        // GENERATED (HasAdmissionNumber + the Sequences counter) and therefore unknowable
        // from the seeder source. Both schools are printed side by side so the isolation
        // check is done by comparing two disjoint sets rather than by trusting a label —
        // every other string in this fixture is identical across the two schools by
        // construction.
        foreach ([['A', $cast->schoolAId], ['B', $cast->schoolBId]] as [$label, $schoolId]) {
            $numbers = DB::table('students')->where('school_id', $schoolId)->whereNull('deleted_at')
                ->orderBy('id')->pluck('admission_number')->all();
            $this->line("  School {$label} (school#{$schoolId}) admission numbers: ".implode(', ', $numbers));
        }

        $this->info('Statements: open /finance and click a student; the queue is /finance/approvals.');
    }
}
