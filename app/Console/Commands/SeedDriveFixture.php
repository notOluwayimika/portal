<?php

namespace App\Console\Commands;

use App\Enums\ScholarshipKind;
use App\Finance\Console\DriveFinanceStates;
use App\Finance\Contracts\BillableEnrollmentProvider;
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

        // TWO MORE ACTIVE PERCENTAGE POLICIES PER SCHOOL, ONE ON EACH BASE — the pairs the BSS
        // discount-award import resolves a sheet against. The single policy above cannot serve this
        // screen: its rows name a (percentage, base) PAIR, and with one base seeded a resolver that
        // ignored the base column entirely would award every row and read as correct. Same real
        // submit-then-approve path, and for a sharper reason than anywhere else here — that import's
        // whole design rests on ApproveDiscountPolicyChange being the catalog's single writer, so a
        // fixture writing these rows directly would drive a screen whose central claim it had just
        // falsified. See DriveFinanceStates::ensureAwardPolicies().
        $awardPolicies = [
            $cast->schoolAId => ActiveSchool::runFor($cast->schoolAId, fn () => $states->ensureAwardPolicies($cast->schoolAId)),
            $cast->schoolBId => ActiveSchool::runFor($cast->schoolBId, fn () => $states->ensureAwardPolicies($cast->schoolBId, $cast->schoolBMaker)),
        ];

        /*
         * TWO STANDING AWARDS PER SCHOOL, ON PLACED STUDENTS — the state a bulk run has to find before
         * a discount can reach an invoice.
         *
         * WITHOUT THEM THE MONEY IS UNDRIVABLE FROM A BARE SEED. Every award in this fixture used to be
         * made by a human uploading a sheet, which is right for driving the IMPORT and useless for
         * driving the RUN: the arithmetic drive would begin with a manual step and nobody could
         * reproduce its numbers. The award-import drive's own four holders stay unplaced and unawarded,
         * so its first upload can still report `awarded` — see DriveCastSeeder::seedCohortAwardHolders().
         *
         * PAIRED BY POSITION, and that is what makes A and B mean different BASES: element 0 of both
         * lists is the discountable-base side and element 1 is the whole-bill side. A set on either
         * side would let the two collapse onto one base and the drive would be reading one number
         * twice.
         *
         * THE ACTOR IS EACH SCHOOL'S OWN BURSAR, because AwardStudentDiscount asserts
         * `finance.discount-award.manage` against the actor IN THE ACTIVE SCHOOL — School A's maker
         * holds nothing in School B, and passing them would meet the gate rather than the fixture.
         */
        foreach ([$cast->schoolAId => $cast->maker, $cast->schoolBId => $cast->schoolBMaker] as $schoolId => $actor) {
            ActiveSchool::runFor($schoolId, function () use ($states, $cast, $awardPolicies, $schoolId, $actor) {
                foreach ($cast->cohortAwardees[$schoolId] ?? [] as $index => $studentId) {
                    $states->awardDiscount($studentId, $awardPolicies[$schoolId][$index], $actor);
                }
            });
        }

        // ONE ACTIVE FEE SCHEDULE PER SCHOOL, at the coordinates the cast placed its cohort at (U6).
        // Without it EVERY bulk-run preview answers "No active fee schedule exists at these
        // coordinates" and every run fails before writing a row — the screen would render exactly one
        // sentence. Seeded through the real draft → submit → approve path, because an approved publish
        // is the only thing that makes a schedule `active`; a status write would put a state in this
        // fixture the application cannot reach. School B's proposal is made by School B's own bursar,
        // as the discount policy above is, and the ED approves both.
        foreach ([$cast->schoolAId, $cast->schoolBId] as $schoolId) {
            $slot = $cast->coordinates[$schoolId];
            $maker = $schoolId === $cast->schoolBId ? $cast->schoolBMaker : null;

            ActiveSchool::runFor($schoolId, fn () => $states->ensureActiveFeeSchedule(
                $schoolId, $slot['term_id'], $slot['class_level_id'], $maker,
            ));
        }

        ActiveSchool::runFor($cast->schoolAId, function () use ($cast, $states, $e) {
            $states->unpaid($e['ursula']);
            $states->partPaid($e['paula']);
            $states->settledByPayment($e['sam']);
            $states->settledByCreditNote($e['cara']);
            $states->settledThenCredited($e['oscar']);
            $states->pendingCreditNote($e['patCredit']);
            $states->pendingVoid($e['patVoid']);
            $states->approvedVoid($e['otto']); // only invoice is void

            /*
             * U10 — the allocation screen's subject, and the fixture had no way to reach it. Every
             * other payment above is recorded AGAINST an invoice and capped at its outstanding, so its
             * remainder is zero; `settledThenCredited` leaves the ACCOUNT in credit, which lives on the
             * balance rather than on an unallocated payment. Without these two the screen opens on
             * nothing.
             *
             * ALMA'S MONEY LANDS IN THE SECOND ACCOUNT AND ARUN'S IN THE FIRST. That is the whole
             * bank-account mismatch axis the MVP cut brief (§9 item 6) requires this screen to show
             * rather than allocate across silently — with one account per school it is unreachable,
             * which is why ensureSecondBankAccount exists and why it is seeded for School A only.
             */
            $states->ensureSecondBankAccount($cast->schoolAId);
            $states->unallocatedRemainder($e['allocAlma'], $cast->schoolAId, intoSecondAccount: true);
            $states->unallocatedRemainder($e['allocArun'], $cast->schoolAId, intoSecondAccount: false);
        });
        ActiveSchool::runFor($cast->schoolBId, fn () => $states->plainInvoice($e['bola'], 250000));

        /*
         * ONE BILL OUT WITH FINANCE — the review queue's second stat card, which is the ONLY surface
         * a returned bill has anywhere in the system until Phase B builds Finance's own queue.
         *
         * Without it that card renders a bare `0` on a fresh fixture, and a `0` is exactly what a
         * card wired to the wrong number looks like — the drive would prove nothing about it. Every
         * other invoice this fixture stages is unreleased and NOT returned, so the awaiting column
         * is populated either way; this is the only state that separates the two cards.
         *
         * AS THE AUDITOR, because ReturnInvoice asserts `finance.invoice.reject` against the actor
         * in the active school and `internal_auditor` is the only role holding it.
         */
        ActiveSchool::runFor($cast->schoolAId, fn () => $states->returnedToFinance($cast->auditor));

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

        // U10's TWO COLUMNS, and they answer two different questions rather than one twice. The
        // allocation screen's entire subject is a payment with something left ON it — every other
        // payment in this fixture is recorded against an invoice and capped at its outstanding, so its
        // remainder is zero and the screen opens on nothing. `Open invoices` is the other half: a
        // payment with a remainder and no open invoice is a real state (the money banks as credit) but
        // it is a screen with an empty table, so the payments column alone could still read as
        // coverage. Both come through DriveFinanceStates for the boundary-lint reason above.
        $remainders = fn (int $schoolId): int => ActiveSchool::runFor($schoolId, fn () => $states->paymentsWithRemainderCount($schoolId));

        $openInvoices = fn (int $schoolId): int => ActiveSchool::runFor($schoolId, fn () => $states->openInvoiceCount($schoolId));

        // U13/U14's TWO COLUMNS, added for the same reason as every pair before them and counted
        // through DriveFinanceStates for the boundary-lint reason above. The decisions surface
        // (/finance/decisions) renders documents that have ALREADY LEFT the pending queue, which is
        // the one thing no column here counted: `Payments (portal)` and `Open invoices` can both be
        // healthy on a fixture where nothing has ever been approved or rejected, and that screen
        // would open onto an empty table looking exactly like a broken feed.
        //
        // SPLIT BY TYPE, not summed. The surface merges two feeds, and a fixture with decided credit
        // notes and no decided voids renders a full-looking table in which one of the two types is
        // absent entirely — the type badge would be the only witness, which is precisely the reading
        // a single combined column would hide.
        // THE REVIEW-QUEUE PAIR, added for the Internal Audit drive, and counted through
        // DriveFinanceStates for the boundary-lint reason above.
        //
        // NEITHER IS DERIVABLE FROM `Open invoices`. That column asks whether money is still owed;
        // these ask whether a HUMAN has signed the bill off, and the two are independent — a
        // fully-paid bill can sit unreleased and a released one can be wide open. A fixture healthy
        // on `Open invoices` can open the review queue onto nothing at all.
        //
        // SPLIT, NOT SUMMED, for the reason the decided-documents pair gives one comment up: the
        // screen renders them as two stat cards whose SUM is the omission detector, and a fixture
        // with a queue and zero returns renders the second card as a bare `0` — which is exactly
        // what a card reading the wrong number looks like. Until Phase B builds Finance's own queue
        // that card is the ONLY surface a returned bill has anywhere, so a zero in the second
        // column means the drive cannot see the thing the card exists for.
        $awaitingReview = fn (int $schoolId): int => ActiveSchool::runFor($schoolId, fn () => $states->awaitingReviewCount($schoolId));

        $returnedToFinance = fn (int $schoolId): int => ActiveSchool::runFor($schoolId, fn () => $states->returnedToFinanceCount($schoolId));

        $decidedNotes = fn (int $schoolId): int => ActiveSchool::runFor($schoolId, fn () => $states->decidedCreditNoteCount($schoolId));

        $decidedVoids = fn (int $schoolId): int => ActiveSchool::runFor($schoolId, fn () => $states->decidedVoidRequestCount($schoolId));

        // U6's three columns. `Active schedules` is filtered to `active` because that is the only
        // status a run may bill from — a count of drafts would report a catalog the screen cannot use.
        // The two enrollment columns come through the ACL PORT rather than through a join written here:
        // the port is the single definition of "billable" and of what "placeable" means, and a second
        // expression of either in this command would report a population the run does not bill.
        $schedules = fn (int $schoolId): int => ActiveSchool::runFor($schoolId, fn () => $states->activeFeeScheduleCount($schoolId));

        /*
         * THE SCHOLARSHIPS TAB'S TWO COLUMNS, and the split is the point rather than a nicety — the
         * same shape as `Payments (portal)` / `Payments (migrated)` beside them.
         *
         * That screen classifies a scholarship as `discount` or `sponsored`, and the ONLY state it can
         * act on is an UNCONFIGURED one (`kind IS NULL`). A single `Scholarships` count would read as
         * coverage on a fixture whose rows were all already classified — which is precisely what the
         * fixture looks like AFTER a drive has run, since re-seeding is the only thing that puts them
         * back. So the second column is the one a drive actually reads before opening a browser: zero
         * there means there is nothing to classify and the drive is worthless before it starts.
         *
         * `scholarships` is a core table, not a `finance_` one, so the boundary lint does not apply and
         * the count is taken here rather than routed through DriveFinanceStates.
         */
        $scholarships = fn (int $schoolId): int => $count('scholarships', $schoolId);

        $unconfiguredScholarships = fn (int $schoolId): int => (int) DB::table('scholarships')
            ->where('school_id', $schoolId)->whereNull('kind')->count();

        /*
         * THE DISCOUNT-AWARD IMPORT'S FOUR COLUMNS — a THIRD table below, not a widening of either one
         * above, following the precedent the guardians fixture set when it added the second.
         *
         * NONE OF THEM IS ANSWERABLE FROM A COLUMN THAT ALREADY EXISTS, which is the test this file's
         * own rule applies before a column is added:
         *
         *  - `Discount policies` counts rows. The award screen groups ACTIVE PERCENTAGE policies into
         *    distinct (percent, base) PAIRS, and a catalog of three could be three drafts, three fixed
         *    amounts, or three rows on one pair — all of which render the empty state or the ambiguity
         *    refusal while that column reads healthy. Hence `Award pairs`.
         *  - `Scholarships` and `Scholarships (unconfigured)` count SCHEMES. This screen acts on
         *    HOLDERS, and asks a different question of each kind: a discount holder can be awarded, a
         *    sponsored or unconfigured one is refused with a different sentence each. Zero in the
         *    discount column means no row of any sheet can ever be awarded; zero in the other means the
         *    scholarship refusals cannot be shown at all.
         *  - `Discount awards` is School-WIDE and counts holders nobody has placed. It was zero on a
         *    fresh fixture until the money drive seeded two standing awards on placed students; it is
         *    still the denominator the import drive's re-upload check is measured against ("no second
         *    award row after a second upload"), and that check now starts from two rather than nought.
         *    The import drive's own four holders remain unawarded, which is what keeps its first upload
         *    able to report `awarded` — the two sets of holders are deliberately different students.
         *
         * The award columns come through DriveFinanceStates for the boundary-lint reason the accounts
         * column gives; the two holder columns read `students`, a core table, so they are counted here.
         */
        $awardPairs = fn (int $schoolId): int => ActiveSchool::runFor($schoolId, fn () => $states->awardPairCount($schoolId));

        $awards = fn (int $schoolId): int => ActiveSchool::runFor($schoolId, fn () => $states->discountAwardCount($schoolId));

        $holders = fn (int $schoolId, bool $discount): int => (int) DB::table('students')
            ->join('scholarships', 'scholarships.id', '=', 'students.scholarship_id')
            ->where('students.school_id', $schoolId)
            ->whereNull('students.deleted_at')
            ->when(
                $discount,
                fn ($q) => $q->where('scholarships.kind', ScholarshipKind::Discount->value),
                // NOT `!= discount` — a NULL kind fails every inequality in SQL, and the unconfigured
                // holder is precisely one of the two refusals this column exists to count.
                fn ($q) => $q->where(fn ($w) => $w->whereNull('scholarships.kind')
                    ->orWhere('scholarships.kind', '!=', ScholarshipKind::Discount->value)),
            )
            ->count();

        $port = app(BillableEnrollmentProvider::class);

        $cohort = fn (int $schoolId): int => count($port->listForCohort(
            $schoolId, $cast->coordinates[$schoolId]['term_id'], $cast->coordinates[$schoolId]['class_level_id'],
        ));

        $unplaceable = fn (int $schoolId): int => count($port->listUnplaceableForSchool($schoolId));

        /*
         * U6's TWO NEW COLUMNS, added 2026-08-28 by the money drive, and NEITHER is derivable from a
         * column beside it — which is the test this file applies before a column is added.
         *
         * `Awarded in cohort` is not `Discount awards`: that one is School-wide and counts the award
         * import's four holders, who are deliberately UNPLACED and can never be billed. A School can
         * therefore show awards and bill none of them, which is a run whose every invoice is full price
         * — the arithmetic drive reading one number with nothing to compare it to.
         *
         * `Sponsored in cohort` is not `On another scholarship` (table 3): that one counts sponsored AND
         * unconfigured holders anywhere in the School, while the run's exclusion arm fires only for a
         * SPONSORED student who is actually AT the coordinates. Zero here and the run reports no
         * exclusions, which is the one outcome that must not be confused with "billed at zero".
         *
         * Both read the cohort through the PORT rather than a join written here, so they count exactly
         * the population the run bills — a second expression of "billable" is the drift the three
         * columns beside them were added to avoid.
         */
        $cohortStudentIds = fn (int $schoolId): array => array_map(
            fn ($enrollment) => $enrollment->studentId,
            $port->listForCohort(
                $schoolId, $cast->coordinates[$schoolId]['term_id'], $cast->coordinates[$schoolId]['class_level_id'],
            ),
        );

        $awardedInCohort = fn (int $schoolId): int => ActiveSchool::runFor(
            $schoolId, fn () => $states->awardedAmong($cohortStudentIds($schoolId)),
        );

        $sponsoredInCohort = function (int $schoolId) use ($cohortStudentIds): int {
            $ids = $cohortStudentIds($schoolId);

            return $ids === [] ? 0 : (int) DB::table('students')
                ->join('scholarships', 'scholarships.id', '=', 'students.scholarship_id')
                ->whereIn('students.id', $ids)
                ->where('scholarships.kind', ScholarshipKind::Sponsored->value)
                ->count();
        };

        $this->info('Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the bulk-run screen (U6) prices a COHORT from an ACTIVE schedule and reports the unplaceable; the decisions surface (U13/U14) reads back what a checker has already settled:');
        $this->table(
            ['School', 'Academic sessions', 'Terms', 'Class levels', 'Bank accounts', 'Discount policies', 'Payments (portal)', 'Payments (migrated)', 'Payments w/ remainder', 'Open invoices', 'Active schedules', 'Cohort at slot', 'Awarded in cohort', 'Sponsored in cohort', 'Unplaceable', 'Decided credit notes', 'Decided voids', 'Awaiting review', 'Returned to Finance'],
            [
                ['A (school#'.$cast->schoolAId.')', $count('academic_sessions', $cast->schoolAId), $count('terms', $cast->schoolAId), $count('class_levels', $cast->schoolAId), $accounts($cast->schoolAId), $policies($cast->schoolAId), $payments($cast->schoolAId, 'portal'), $payments($cast->schoolAId, 'migrated'), $remainders($cast->schoolAId), $openInvoices($cast->schoolAId), $schedules($cast->schoolAId), $cohort($cast->schoolAId), $awardedInCohort($cast->schoolAId), $sponsoredInCohort($cast->schoolAId), $unplaceable($cast->schoolAId), $decidedNotes($cast->schoolAId), $decidedVoids($cast->schoolAId), $awaitingReview($cast->schoolAId), $returnedToFinance($cast->schoolAId)],
                ['B (school#'.$cast->schoolBId.')', $count('academic_sessions', $cast->schoolBId), $count('terms', $cast->schoolBId), $count('class_levels', $cast->schoolBId), $accounts($cast->schoolBId), $policies($cast->schoolBId), $payments($cast->schoolBId, 'portal'), $payments($cast->schoolBId, 'migrated'), $remainders($cast->schoolBId), $openInvoices($cast->schoolBId), $schedules($cast->schoolBId), $cohort($cast->schoolBId), $awardedInCohort($cast->schoolBId), $sponsoredInCohort($cast->schoolBId), $unplaceable($cast->schoolBId), $decidedNotes($cast->schoolBId), $decidedVoids($cast->schoolBId), $awaitingReview($cast->schoolBId), $returnedToFinance($cast->schoolBId)],
            ],
        );

        $this->info('Bulk invoice runs: /finance/bulk-invoice-runs — the cohort above sits at (term, JSS 1); JSS 2 has an empty one on purpose.');

        // THE MANDATORY LINES OF THE PRICE LIST, printed for the reason the admission numbers and the
        // award pairs are: they are the INPUTS to every total a money drive checks by hand, and an
        // arithmetic claim that does not state what it was computed from is not checkable. Filtered to
        // MANDATORY because that is what a run bills — the optional bus line is on the schedule and can
        // never reach an invoice a run raises, so printing it here would invite it into the sum.
        //
        // READ THE `discountable` FLAGS AS A SET, not one at a time: with every mandatory line
        // discountable the two discount BASES denote the same money and no run can tell them apart.
        foreach ([['A', $cast->schoolAId], ['B', $cast->schoolBId]] as [$label, $schoolId]) {
            $lines = ActiveSchool::runFor($schoolId, fn () => $states->billableScheduleLines($schoolId));
            $this->line("  School {$label} (school#{$schoolId}) billable schedule lines: ".implode(' · ', $lines));
        }
        // STUDENTS AND GUARDIANS, added for the guardian-create drive and counted for the
        // same reason as every column beside them. That screen links a new guardian to
        // children BY ADMISSION NUMBER, so a zero in the Students column means the drive
        // cannot author a single link and is worthless before it opens a browser — the
        // exact failure the academic three were added to prevent. Guardians is expected to
        // be ZERO on a fresh fixture and is printed anyway: it is the denominator the
        // duplicate-warning and the reuse backstop are measured against, so a drive that
        // reports "one guardian row after two submissions" can be checked against where it
        // started rather than asserted.
        $this->info('Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the guardians screen links a new guardian to students by admission number; the Scholarships tab classifies an UNCONFIGURED scholarship:');
        $this->table(
            ['School', 'Academic sessions', 'Terms', 'Class levels', 'Bank accounts', 'Discount policies', 'Payments (portal)', 'Payments (migrated)', 'Payments w/ remainder', 'Open invoices', 'Students', 'Guardians', 'Teachers', 'Scholarships', 'Scholarships (unconfigured)'],
            [
                ['A (school#'.$cast->schoolAId.')', $count('academic_sessions', $cast->schoolAId), $count('terms', $cast->schoolAId), $count('class_levels', $cast->schoolAId), $accounts($cast->schoolAId), $policies($cast->schoolAId), $payments($cast->schoolAId, 'portal'), $payments($cast->schoolAId, 'migrated'), $remainders($cast->schoolAId), $openInvoices($cast->schoolAId), $count('students', $cast->schoolAId), $count('guardians', $cast->schoolAId), $count('teachers', $cast->schoolAId), $scholarships($cast->schoolAId), $unconfiguredScholarships($cast->schoolAId)],
                ['B (school#'.$cast->schoolBId.')', $count('academic_sessions', $cast->schoolBId), $count('terms', $cast->schoolBId), $count('class_levels', $cast->schoolBId), $accounts($cast->schoolBId), $policies($cast->schoolBId), $payments($cast->schoolBId, 'portal'), $payments($cast->schoolBId, 'migrated'), $remainders($cast->schoolBId), $openInvoices($cast->schoolBId), $count('students', $cast->schoolBId), $count('guardians', $cast->schoolBId), $count('teachers', $cast->schoolBId), $scholarships($cast->schoolBId), $unconfiguredScholarships($cast->schoolBId)],
            ],
        );

        /*
         * TABLE 3 — the discount-award import slot. A THIRD table and a NARROW one.
         *
         * IT DOES NOT REPEAT THE SHARED TEN that tables 1 and 2 both carry. Those two duplicate each
         * other value for value, which is a useful cross-check between them; printing them a third time
         * would be waste, and this screen reads none of them — no term, no class level, no bank account
         * and no invoice is involved in putting a named child on an approved figure.
         */
        $this->info('Authoring slot per school — the BSS discount-award import (/finance/discount-award-imports) resolves each row of a sheet to an ACTIVE percentage policy on a (percentage, base) PAIR, and asks the student\'s SCHOLARSHIP whether a discount may be awarded at all:');
        $this->table(
            ['School', 'Award pairs', 'Discount policies', 'Students', 'On a discount scholarship', 'On another scholarship', 'Discount awards'],
            [
                ['A (school#'.$cast->schoolAId.')', $awardPairs($cast->schoolAId), $policies($cast->schoolAId), $count('students', $cast->schoolAId), $holders($cast->schoolAId, true), $holders($cast->schoolAId, false), $awards($cast->schoolAId)],
                ['B (school#'.$cast->schoolBId.')', $awardPairs($cast->schoolBId), $policies($cast->schoolBId), $count('students', $cast->schoolBId), $holders($cast->schoolBId, true), $holders($cast->schoolBId, false), $awards($cast->schoolBId)],
            ],
        );

        // THE PAIRS THEMSELVES, printed for the reason the admission numbers below are: a driver has to
        // TYPE them into the third column of the sheet, and a pair that is merely counted still has to
        // be looked up in the seeder. Read through the same scoped model the screen's own prop query
        // uses, so what is printed here is what that screen will offer.
        foreach ([['A', $cast->schoolAId], ['B', $cast->schoolBId]] as [$label, $schoolId]) {
            $pairs = ActiveSchool::runFor($schoolId, fn () => $states->awardPairsFor($schoolId));
            $this->line("  School {$label} (school#{$schoolId}) award pairs: ".implode(' · ', $pairs));
        }

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
