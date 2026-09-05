<?php

namespace Database\Seeders;

use App\Enums\ScholarshipKind;
use App\Enums\TeacherAssignmentRoleEnum;
use App\Enums\TermStatusEnum;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmTeacher;
use App\Models\Curriculum;
use App\Models\Guardian;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Scholarship;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The NON-Finance half of the drive fixture: schools, the cast of users (with their real RBAC
 * roles), students, and their ENROLLMENTS. It imports no Finance code — the Finance Actions bill
 * the enrollment UUIDs this exposes ({@see App\Finance\Console\DriveFinanceStates}), exactly as
 * production bills a UUID resolved through the ACL port.
 *
 * It lives in database/seeders (not app/): it references role NAMES like `accounts_supervisor`, which
 * the boundary lint's `finance_*` table-literal rule would false-positive on inside app/, and it
 * touches Academics (enrollments), which the arch boundary forbids inside app/Finance. Seeders sit
 * outside both — the same reason cross-module test fixtures do.
 *
 * The command drives the billing from the manifest exposed here. Emma has NO enrollment on purpose
 * (the "no invoices at all" advance-payment edge).
 */
class DriveCastSeeder extends Seeder
{
    public const PASSWORD = 'drive-password';

    public User $maker;

    public User $checker;

    /**
     * Internal Audit's seat — the ONLY one that can open /internal-audit/review-queue.
     *
     * Exposed because the review-queue fixture state has to be authored AS this seat: releasing or
     * returning a bill is refused for every other login in the cast, and the states this fixture
     * stages are produced by executing the real Actions rather than by writing rows.
     */
    public User $auditor;

    /**
     * School B's bursar — the isolation seat, exposed because the FINANCE half needs a maker inside
     * School B. `maker` above is a School A user, and a discount-policy change proposed by them in
     * School B's context would attribute the proposal to someone with no access to that school. The
     * checker is shared (ED holds every finance checker side; maker ≠ checker is what the DB CHECK
     * actually requires, and it holds either way).
     */
    public User $schoolBMaker;

    public int $schoolAId;

    public int $schoolBId;

    /** @var array<string, string> enrollment UUIDs keyed by state name */
    public array $enrollments = [];

    /**
     * THE PRICING COORDINATES PER SCHOOL — `['term_id' => …, 'class_level_id' => …, 'arm_id' => …]`,
     * keyed by school id. Added for U6's bulk-run screen, which asks for a (term, class level) pair and
     * bills every billable enrollment sitting at it.
     *
     * Before this the fixture had NO enrollment at any coordinates at all: `enrollFor()` built each
     * episode on a bare `Curriculum::factory()`, whose `term_id` and `class_level_arm_id` are both
     * nullable and unset (CurriculumFactory::definition). Every drive episode was therefore
     * UNPLACEABLE, no cohort query could ever return one, and a bulk run would have billed nobody on
     * a fixture that looked fully populated. Same class of failure as the empty term select U1 commit 1
     * fixed here, one join further in — and invisible for the same reason: no test can run this seeder
     * (`SeedDriveFixture` refuses outside `APP_ENV=drive`; the suite is pinned to `APP_ENV=testing`).
     *
     * @var array<int, array{term_id: int, class_level_id: int, arm_id: int}>
     */
    public array $coordinates = [];

    /**
     * The two PLACED students per school who are to receive a discount award, in
     * {@see DriveCastSeeder::seedCohortAwardHolders()} order — [ A (discountable base), B (whole bill) ]
     * — keyed by school id.
     *
     * EXPOSED AS IDS BECAUSE THE AWARD IS NOT MADE HERE. This class imports no Finance code by design,
     * so `SeedDriveFixture` makes both awards through the real Action afterwards and needs to know who
     * on. Ids rather than models: the command re-reads nothing off them, it only names two students.
     *
     * ORDER IS LOAD-BEARING and is the reason this is a list and not a set — the command pairs
     * element 0 with the discountable-base policy and element 1 with the whole-bill one, which is the
     * only thing that makes A and B mean different bases.
     *
     * @var array<int, list<int>>
     */
    public array $cohortAwardees = [];

    /**
     * THE PAYER SEAT — `parent@drive.test`, School A, holding `guardian`.
     *
     * Every other seat in this cast is STAFF. `guardian-editor@drive.test` holds
     * `drive_guardian_editor`, which is the ability to ADMINISTER guardians, not `parent_portal.access`
     * — so before this seat existed nobody in the fixture could log in and see the parent Fees screen
     * at all, and a drive of it was impossible rather than merely empty.
     */
    public ?User $parent = null;

    /**
     * The payer seat's three wards, keyed by the CONDITION each one exists to render.
     *
     * THREE, BECAUSE THE SCREEN HAS THREE ANSWERS AND ONLY ONE IS THE HAPPY PATH:
     *
     *   `released`  a bill the payer can see and pay — the only ward with a Pay control.
     *   `withheld`  a bill exists and Internal Audit has not released it. **Renders identically to
     *               a ward with nothing owed**, deliberately: the endpoint withholds unreleased bills
     *               on BOTH keys, so the screen cannot hint that something is hidden. A fixture
     *               without this ward cannot show that the withholding is real rather than asserted.
     *   `credit`    account credit and NOTHING released. This is the screen most parents get, and it
     *               is the only one where the copy does any work — two numbers with no account of
     *               their relationship is what makes a parent telephone the school.
     *
     * @var array<string, string> enrollment UUIDs keyed by condition
     */
    public array $parentWards = [];

    /** The guardian-create drive's operator seat (School A) and its isolation counterpart (School B). */
    public ?User $adminA = null;

    public ?User $adminB = null;

    /** Holds guardian.update WITHOUT guardian.update_credentials — the partial editor. */
    public ?User $guardianEditor = null;

    public function run(): void
    {
        $schoolA = School::create(['name' => 'Drive School A', 'slug' => (string) Str::uuid()]);
        $schoolB = School::create(['name' => 'Drive School B', 'slug' => (string) Str::uuid()]);
        $this->schoolAId = (int) $schoolA->id;
        $this->schoolBId = (int) $schoolB->id;

        $this->seedAcademicSlot($schoolA);
        $this->seedAcademicSlot($schoolB);

        $this->seedScholarships($schoolA);
        $this->seedScholarships($schoolB);

        $this->seedCast($schoolA, $schoolB);

        // One episode per state (F7: one active invoice per episode). Emma gets a student but no
        // enrollment — the "no invoices" edge.
        $this->enrollments = [
            'ursula' => $this->enroll($schoolA, 'Ursula', 'Unpaid'),
            'paula' => $this->enroll($schoolA, 'Paula', 'Part'),
            'sam' => $this->enroll($schoolA, 'Sam', 'Settled'),
            'cara' => $this->enroll($schoolA, 'Cara', 'Credited'),
            'oscar' => $this->enroll($schoolA, 'Oscar', 'Overcredit'),
            'otto' => $this->enroll($schoolA, 'Otto', 'Onlyvoid'),
            'bola' => $this->enroll($schoolB, 'Bola', 'SchoolB'),

            /*
             * U10 — two students who will each hold an unallocated payment and two open invoices.
             *
             * TWO AND NOT ONE, because the allocation screen's bank-account comparison has three
             * outcomes and a single student can only show two of them. `alma`'s payment lands in the
             * school's SECOND account, so her term bill's destination DIFFERS from where the money is;
             * `arun`'s lands in the primary one, so his MATCHES. The third state, `unrecorded`, comes
             * free on both — their supplementary invoices are free text with no fee item, which is what
             * every line the "New invoice" modal writes looks like today.
             *
             * Unplaced, like every episode above except the cohort pair: U10 bills them directly rather
             * than through a run, so they need no coordinates, and placing them would move the
             * `Cohort at slot` count the bulk-run drive reads.
             */
            'allocAlma' => $this->enroll($schoolA, 'Alma', 'Allocate'),
            'allocArun' => $this->enroll($schoolA, 'Arun', 'Allocate'),
        ];

        /*
         * PLACED, AND CARRYING NO INVOICE — the cohort a bulk run bills (U6). Two per school, because
         * one proves a run can bill and two proves it iterates. They are deliberately kept OUT of the
         * Finance states below: an episode that already carries a term bill comes back `already_billed`,
         * which is a real outcome and is covered by simply RE-RUNNING on the drive.
         *
         * EVERY OTHER EPISODE ABOVE STAYS UNPLACED, and that is the other half of the fixture rather
         * than an omission. They have no term and no class level, so they are exactly the `unplaceable`
         * bucket — the one list on the run report that somebody has to act on. A fixture in which every
         * episode was placeable could not render it.
         */
        foreach ([$schoolA, $schoolB] as $school) {
            $this->enrollments['cohort'.$school->id.'a'] = $this->enrollAt($school, $this->student($school, 'Cody', 'Cohort'));
            $this->enrollments['cohort'.$school->id.'b'] = $this->enrollAt($school, $this->student($school, 'Cleo', 'Cohort'));
        }

        // Pat: two episodes — a pending credit note on one, a pending void on the other.
        $pat = $this->student($schoolA, 'Pat', 'Pending');
        $this->enrollments['patCredit'] = $this->enrollFor($schoolA, $pat);
        $this->enrollments['patVoid'] = $this->enrollFor($schoolA, $pat);

        $this->student($schoolA, 'Emma', 'Empty'); // no enrollment — the "no invoices" edge

        // THE PAYER'S THREE WARDS. Unplaced on purpose, like every episode that is not the bulk-run
        // cohort: they are billed directly by the states below, never through a run, so coordinates
        // would only move the `Cohort at slot` count another drive reads.
        $this->parentWards = [
            'released' => $this->enroll($schoolA, 'Ada', 'Payer'),
            'withheld' => $this->enroll($schoolA, 'Bem', 'Payer'),
            'credit' => $this->enroll($schoolA, 'Chidi', 'Payer'),
        ];

        $this->linkPayerWards();

        // LAST, so these four land at the END of each school's printed admission-number list and a
        // driver of the award import can pick them out without cross-referencing the cast.
        $this->seedScholarshipHolders($schoolA);
        $this->seedScholarshipHolders($schoolB);

        // And then the money drive's three, after them, for the same reason.
        $this->seedCohortAwardHolders($schoolA);
        $this->seedCohortAwardHolders($schoolB);
    }

    /**
     * THREE SCHOLARSHIPS PER SCHOOL, ONE PER `kind` — one classified `discount`, one `sponsored`, one
     * left NULL. Read {@see seedScholarships()} for why the NULL one is written directly and why the
     * other two are not the same question.
     *
     * @var array<string, ?ScholarshipKind>
     */
    private const SCHOLARSHIPS = [
        self::SCHOLARSHIP_DISCOUNT => ScholarshipKind::Discount,
        self::SCHOLARSHIP_SPONSORED => ScholarshipKind::Sponsored,
        self::SCHOLARSHIP_UNCONFIGURED => null,
    ];

    /** Brookstone's own name for the scheme the discount-award import carries in. */
    private const SCHOLARSHIP_DISCOUNT = 'BSS';

    private const SCHOLARSHIP_SPONSORED = 'Endowed';

    /** The one the Scholarships tab can still act on. Its name is unchanged from before this fixture grew. */
    private const SCHOLARSHIP_UNCONFIGURED = 'C2C';

    /**
     * THREE SCHOLARSHIPS PER SCHOOL, ONE PER KIND — and the NULL one is the Scholarships tab's whole
     * subject.
     *
     * That tab classifies a scholarship as `discount` (the school reduces the bill; the family still
     * gets a smaller one) or `sponsored` (an outside body pays; the family is not billed at all), and
     * an UNCONFIGURED row is the only state it can act on. Before this the fixture contained the
     * string "scholarship" exactly zero times, so the tab would have opened onto an empty list and
     * proved nothing — the same class of failure as the empty term select U1 commit 1 fixed, and
     * invisible for the same reason (`SeedDriveFixture` refuses outside `APP_ENV=drive`; the suite is
     * pinned to `APP_ENV=testing`, so no test can read this seeder at all).
     *
     * ── THE ROW WRITE, AND THE TWO DIFFERENT ARGUMENTS BEHIND IT ─────────────────────────────────────
     *
     * THE `C2C` ROW carries the SAME EXEMPTION `Payments (migrated)` already does: a state that EXISTS
     * IN PRODUCTION and that NO CURRENT CODE PATH CAN CREATE. `2026_08_26_100000` backfilled every
     * existing `scholarships` row to NULL deliberately, because nothing in the data said which scheme
     * any of them was — the local production copy holds two scholarships and both are NULL. And since
     * `ScholarshipController::store()` now makes `kind` REQUIRED, there is no endpoint, no console
     * command and no Action anywhere that can mint an unconfigured row.
     *
     * SO DO NOT "FIX" THAT ONE BY ROUTING IT THROUGH THE ENDPOINT. Doing so would classify the only
     * state the Scholarships tab is about, while leaving the fixture looking more correct than it was.
     * If a legitimate writer for NULL ever appears, this method should move to it.
     *
     * THE `BSS` AND `Endowed` ROWS ARE A WEAKER CASE AND ARE MARKED AS ONE. A classified scholarship IS
     * reachable — `ScholarshipController::store()` mints exactly this — so the "no writer exists"
     * argument does not cover them. What covers them is narrower: that writer is a controller and not
     * an Action, its whole body is `Scholarship::create(['school_id', 'name', 'kind'])`, and there is
     * nothing else for a seeder to call. The write here is byte-identical to the sanctioned one rather
     * than a shortcut past it. THE MOMENT AN ACTION EXISTS, THESE TWO MOVE TO IT and only `C2C` stays.
     *
     * ── STUDENTS ARE NOW PUT ON THEM, WHICH REVERSES WHAT THIS DOCBLOCK USED TO SAY ──────────────────
     *
     * It used to argue that seeding holders "would add rows nothing on the screen can see", and that
     * was true of the screen it was written for: the Scholarships tab renders a name, a kind control
     * and row actions, counts no holders and never reads `students.scholarship_id`
     * (`resources/js/pages/admin/scholarships-tab.tsx`). It stopped being true when a SECOND screen
     * started reading these rows. The BSS discount-award import asks a student's scholarship whether a
     * discount may be awarded at all, and answers three different refusals depending on what it finds
     * — so with no holders that screen refuses every row identically and can demonstrate none of them.
     * The holders are seeded in {@see seedScholarshipHolders()}, which argues the shape there.
     *
     * ── IDENTICAL NAMES ACROSS THE TWO SCHOOLS, ON PURPOSE ───────────────────────────────────────────
     *
     * `BSS`, `Endowed` and `C2C` in both, exactly as `First Term` and `JSS 1` are identical by
     * construction. A screen showing "BSS" therefore proves nothing about WHICH school's row it is,
     * which forces the isolation check onto the ids and uuids where it belongs.
     */
    private function seedScholarships(School $school): void
    {
        foreach (self::SCHOLARSHIPS as $name => $kind) {
            Scholarship::create([
                'school_id' => $school->id,
                'name' => $name,
                'kind' => $kind,
            ]);
        }
    }

    /**
     * THE STUDENTS THE BSS DISCOUNT-AWARD IMPORT ACTS ON — four per school, one per outcome that
     * screen can produce, and NONE of them enrolled.
     *
     * WHY IT WAS ADDED. `/finance/discount-award-imports` joins a sheet to students by admission number
     * and then asks the student's SCHOLARSHIP whether a discount may be awarded at all
     * (`App\Finance\Actions\AwardStudentDiscount` — NAMED IN PROSE and not as a `{@see}`, because
     * pint's fully_qualified_strict_types rewrites a fully-qualified see-tag into a real `use`
     * statement, and this seeder imports no Finance code by design. Paid for twice inside one minute:
     * the first fix wrote that rule out with an illustrative tag in it, and pint imported THAT too).
     * Before this the fixture's scholarships had no
     * holders — the docblock above says so and gave the right reason for the screen it was written for,
     * the Scholarships tab, which reads no holder. This screen reads nothing else. So the import would
     * have refused every row for the same reason, and a report of four identical refusals cannot show
     * that four different refusals exist.
     *
     * THREE OF THE FOUR ARE REFUSALS, and each refusal has a DIFFERENT subject: a sponsored holder (an
     * outside body pays, so there is no bill to discount), an unconfigured holder (nobody has said
     * which scheme it is), and — supplied by the sheet rather than by the fixture — a percentage-and-
     * base pair no policy covers, plus an admission number nobody holds. A fixture with one refusable
     * student could only ever demonstrate one sentence.
     *
     * TWO ON THE DISCOUNT SCHEME AND NOT ONE. One holder can show "awarded"; it cannot show a second
     * upload reporting ALREADY AWARDED beside a still-refused row, and it cannot carry both bases.
     *
     * NOT ENROLLED, DELIBERATELY. An award needs no episode — the Action reads the scholarship and the
     * policy and nothing else — while enrolling them would move `Cohort at slot` or `Unplaceable`,
     * which two other drives read. New students with no episode move exactly one column, `Students`.
     * A SPONSORED student in particular must not be placed: the bulk run excludes them, so placing one
     * would silently change what U6's drive bills.
     */
    private function seedScholarshipHolders(School $school): void
    {
        $of = fn (string $name): Scholarship => Scholarship::query()
            ->where('school_id', $school->id)->where('name', $name)->sole();

        $award = $of(self::SCHOLARSHIP_DISCOUNT);

        foreach ([['Bisi', 'Bursary'], ['Dele', 'Discount']] as [$first, $last]) {
            $this->student($school, $first, $last)->update(['scholarship_id' => $award->id]);
        }

        $this->student($school, 'Sonia', 'Sponsored')
            ->update(['scholarship_id' => $of(self::SCHOLARSHIP_SPONSORED)->id]);

        $this->student($school, 'Nadia', 'Nullkind')
            ->update(['scholarship_id' => $of(self::SCHOLARSHIP_UNCONFIGURED)->id]);
    }

    /**
     * THE MONEY DRIVE'S COHORT — three students PLACED at the school's pricing coordinates, so that a
     * bulk run bills them and the arithmetic of a discount can be read off a real invoice.
     *
     * WHY IT EXISTS. Everything before this proves an AWARD ROW was written; nothing proves a naira
     * left an invoice. The two meet only in a bulk run, and a run bills a COHORT — so the award has to
     * sit on somebody the cohort query returns. {@see seedScholarshipHolders()} above deliberately
     * leaves its four UNPLACED, so not one of them can be billed.
     *
     * THEY ARE NOT THOSE FOUR, AND THE SEPARATION IS THE POINT. The award-import drive needs holders
     * with NO award — its first upload must be able to report `awarded` — while this one needs holders
     * WITH one. The same student cannot be both, and reusing them would make whichever drive ran second
     * measure the first one's leftovers.
     *
     * THREE, AND THE FOURTH COMES FREE. A (awarded on the discountable base) and B (awarded on the
     * whole bill) are what the base axis is read from; C is on a SPONSORED scheme, which the run
     * excludes rather than bills. The CONTROL — a placed student with no award at all, billed at full
     * price — is already here: `Cody Cohort` and `Cleo Cohort` are placed, carry no scholarship and
     * carry no award, and every reduced total is read against theirs. Without an unreduced number
     * beside them the reduced ones cannot be checked at all.
     *
     * NO AWARD IS MADE HERE. This class touches no Finance code by design; `SeedDriveFixture` awards
     * A and B through the real Action afterwards, and the sponsored student C is left holding nothing,
     * which is the state a run must refuse to bill rather than bill at zero.
     */
    private function seedCohortAwardHolders(School $school): void
    {
        $of = fn (string $name): Scholarship => Scholarship::query()
            ->where('school_id', $school->id)->where('name', $name)->sole();

        $discount = $of(self::SCHOLARSHIP_DISCOUNT)->id;

        foreach ([['Ada', 'Awarded'], ['Bode', 'Bothbases']] as [$first, $last]) {
            $student = $this->student($school, $first, $last);
            $student->update(['scholarship_id' => $discount]);
            $this->enrollAt($school, $student);
            $this->cohortAwardees[(int) $school->id][] = (int) $student->id;
        }

        $sponsored = $this->student($school, 'Chidi', 'Coveredfor');
        $sponsored->update(['scholarship_id' => $of(self::SCHOLARSHIP_SPONSORED)->id]);
        $this->enrollAt($school, $sponsored);
    }

    /**
     * ONE ACADEMIC SLOT PER DRIVE SCHOOL: a session, a term inside it, and TWO class levels.
     *
     * Before U1 commit 1 this fixture seeded NONE of the three — enrollments were built straight onto a
     * Curriculum factory, and the Finance half touches none of them. A drive of the fee-schedules screen
     * would therefore have landed on an EMPTY term select and an EMPTY class level select and been able
     * to create nothing: the same class of failure as the opening-balance operator screen (routes/web.php
     * `->id` vs the model), except caused by the fixture rather than by the query. Fixed here so U1
     * commit 2's drive does not discover it.
     *
     * TWO class levels, not one, deliberately — one renders a select and proves nothing about whether it
     * LISTS. The counts are printed by `SeedDriveFixture::report()` so the next drive reads them
     * rather than trusting this comment.
     *
     * Columns are the ones the tables actually require, read from information_schema rather than
     * inferred from $fillable: `terms` needs academic_session_id, school_id, name, slug, order,
     * start_date and end_date all NOT NULL (status defaults to 'upcoming'; uuid is filled by the model);
     * `class_levels` needs name, with order defaulting to 0 and level_type nullable;
     * `academic_sessions` needs name and slug, with is_current defaulting to 0. The unique keys that
     * bite are terms(academic_session_id, slug), terms(academic_session_id, order) and
     * academic_sessions(slug, school_id) — hence the per-school slug suffix.
     */
    private function seedAcademicSlot(School $school): void
    {
        $session = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '2026/2027',
            'slug' => 'drive-2026-2027-'.$school->id,
            'is_current' => true,
        ]);

        $term = Term::create([
            'academic_session_id' => $session->id,
            'school_id' => $school->id,
            'name' => 'First Term',
            'slug' => 'drive-first-term-'.$school->id,
            'order' => 1,
            'status' => TermStatusEnum::ACTIVE->value,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2),
        ]);

        $levels = [];

        foreach ([['JSS 1', 1], ['JSS 2', 2]] as [$name, $order]) {
            $levels[$name] = ClassLevel::create([
                'school_id' => $school->id,
                'name' => $name,
                'order' => $order,
                'level_type' => 'JSS',
            ]);
        }

        /*
         * AN ARM ON JSS 1, so an enrollment can be PLACED there (U6). A curriculum points at a
         * `class_level_arm`, not at a class level, so without this row no episode in this fixture could
         * ever sit at pricing coordinates and the bulk-run screen would have nothing to bill.
         *
         * ONE arm and on ONE of the two levels, deliberately: JSS 2 stays unarmed so the screen has a
         * class level whose cohort is genuinely EMPTY — a real and unremarkable state (a level nobody is
         * enrolled in), and the one a preview must be able to report without looking broken.
         */
        $arm = Arm::create(['school_id' => $school->id, 'label' => 'A']);

        $classLevelArm = ClassLevelArm::create([
            'school_id' => $school->id,
            'class_level_id' => $levels['JSS 1']->id,
            'arm_id' => $arm->id,
        ]);

        /*
         * A SECOND, NON-CURRENT SESSION WITH ITS OWN TERM — so "the term is defaulted and remains
         * overridable" (U6) is something a drive can actually SEE. With one term per school the
         * override control renders a list of one, which proves nothing about whether the default is
         * a default or a pin, and the U1 rule applies verbatim: a select rendering one option is
         * rendering one option whether that option is real or a placeholder.
         *
         * IT IS OLDER, NOT NEWER, and `is_current` is false on it. That makes the default
         * DISCRIMINATING in the fixture as well as in the suite: a screen defaulting to "the newest
         * term", "the highest `order`", "the first in the props list" or "any active term" would
         * land on the current one here by luck, so the decoy is placed where only the wrong answers
         * differ — an EARLIER session, whose term is also `active`.
         */
        $pastSession = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '2025/2026',
            'slug' => 'drive-2025-2026-'.$school->id,
            'is_current' => false,
        ]);

        Term::create([
            'academic_session_id' => $pastSession->id,
            'school_id' => $school->id,
            'name' => 'Third Term',
            'slug' => 'drive-third-term-'.$school->id,
            'order' => 3,
            'status' => TermStatusEnum::ACTIVE->value,
            'start_date' => now()->subMonths(8),
            'end_date' => now()->subMonths(5),
        ]);

        $this->coordinates[(int) $school->id] = [
            'term_id' => (int) $term->id,
            'class_level_id' => (int) $levels['JSS 1']->id,
            'arm_id' => (int) $classLevelArm->id,
        ];
    }

    private function seedCast(School $schoolA, School $schoolB): void
    {
        $this->maker = $this->driveUser('maker@drive.test', $schoolA, 'accounts_officer');
        // executive_director since 2026-08-04 — it holds every finance checker side. accounts_supervisor
        // is now maker-and-viewer and could not approve anything the drive walks through.
        $this->checker = $this->driveUser('checker@drive.test', $schoolA, 'executive_director');

        // The one-permission checker — the exact user the per-feed 403-tolerant queue was written
        // for. A dedicated role holding ONLY the void checker permissions (legal under the grant
        // guard — two checkers of one instance, never a maker + its matching checker).
        setPermissionsTeamId($schoolA->id);
        $voidOnly = Role::firstOrCreate(['name' => 'drive_void_checker', 'guard_name' => 'web']);
        foreach (['finance.access', 'finance.invoice.void-request.approve', 'finance.invoice.void-request.reject'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $voidOnly->syncPermissions(['finance.access', 'finance.invoice.void-request.approve', 'finance.invoice.void-request.reject']);
        setPermissionsTeamId(null);
        $this->driveUser('void-checker@drive.test', $schoolA, 'drive_void_checker');

        $super = $this->driveUser('super@drive.test', $schoolA, null);
        setPermissionsTeamId(null);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $super->assignRole('super_admin');
        $super->flushSchoolAccessCache();

        $this->schoolBMaker = $this->driveUser('school-b@drive.test', $schoolB, 'accounts_officer');

        // ── ONE TEACHER AND ONE ASSIGNMENT, ADDED FOR THE ConfirmDialog DRIVE ──────────────────
        //
        // THE FIXTURE SEEDS NO TEACHERS AT ALL — `DriveCastSeeder` and `SeedDriveFixture` between
        // them contained the word zero times, the same shape as the scholarships gap this file
        // already records. So `/setup/teacher-assignments` opened onto an empty table, its Remove
        // button never rendered, and the shared `ConfirmDialog` could not be opened by any drive.
        // A screen you could not open is not a screen you proved.
        //
        // WRITTEN DIRECTLY, AND THE ARGUMENT IS THE WEAKER FORM THIS FILE ALREADY DOCUMENTS for the
        // two CLASSIFIED scholarship rows: the sanctioned writer is a CONTROLLER rather than an
        // Action (`TeacherAssignmentController::store`), and the row it creates is
        // `ClassLevelArmTeacher::create(['class_level_arm_id','teacher_id','role','gender',
        // 'assigned_by'])` — so this write is byte-identical to the endpoint's rather than a
        // shortcut past it. The moment an Action exists, this moves to it.
        $teacherUser = $this->driveUser('teacher@drive.test', $schoolA, null);
        $teacher = Teacher::firstOrCreate(
            ['school_id' => $schoolA->id, 'user_id' => $teacherUser->id],
            [
                'staff_number' => 'DRIVE-T-001',
                'first_name' => 'Drive',
                'last_name' => 'Teacher',
                'gender' => 'female',
                'status' => 'active',
            ],
        );

        // THIRTY MORE, AND THE NUMBER IS THE POINT RATHER THAN PADDING. The teachers screen
        // paginates at 25 by default, so a fixture with one teacher has ONE page: its `Next` button
        // renders DISABLED and the page control cannot be exercised at all. A drive that clicked it
        // would report "no spinner" and be measuring a disabled button rather than the code under
        // test. Thirty-one rows is the smallest count that puts a second page on screen.
        //
        // They carry no user account: this screen lists teachers and changes their status, and
        // neither read needs one. `teacher@drive.test` above is the one that does, because it is
        // the row the assignment — and therefore the ConfirmDialog — hangs off.
        foreach (range(2, 31) as $n) {
            Teacher::firstOrCreate(
                ['school_id' => $schoolA->id, 'staff_number' => sprintf('DRIVE-T-%03d', $n)],
                [
                    'first_name' => 'Drive',
                    'last_name' => 'Teacher '.$n,
                    'gender' => $n % 2 === 0 ? 'female' : 'male',
                    'status' => 'active',
                ],
            );
        }

        // The arm the academic slot already seeds on JSS 1 — see seedAcademicSlot(). JSS 2 is left
        // unarmed on purpose, so this assignment can only land on the one that exists.
        $arm = ClassLevelArm::query()
            ->whereIn('class_level_id', ClassLevel::where('school_id', $schoolA->id)->pluck('id'))
            ->first();

        if ($arm !== null) {
            ClassLevelArmTeacher::firstOrCreate(
                [
                    'class_level_arm_id' => $arm->id,
                    'role' => TeacherAssignmentRoleEnum::FORM_TEACHER->value,
                ],
                ['teacher_id' => $teacher->id, 'gender' => null, 'assigned_by' => $this->adminA?->id],
            );
        }

        // ── TWO INTERNAL-AUDIT SEATS, ADDED FOR THE REVIEW-QUEUE DRIVE ──────────────────────────
        //
        // NOT ONE OF THE SEATS ABOVE CAN OPEN /internal-audit/review-queue. That page and its API
        // group are gated on `finance.invoice.approve`, which `internal_auditor` is the ONLY role
        // holding — RbacSeeder withholds it from `admin` and `accounts_officer` deliberately,
        // because both hold `finance.invoice.generate` and MAKER_OVERRIDES names that as this
        // checker's maker. super_admin does not help either: the ability terminates in a
        // CHECKER_SEGMENT, so ApprovalAbility excludes it from the Gate::before bypass (ADR 0040).
        //
        // Without this seat the review queue could not be driven at all — the fifth instance of the
        // failure SKILL.md enumerates, and the same shape as the scholarships one, where the
        // fixture contained the string "scholarship" zero times.
        $this->auditor = $this->driveUser('auditor@drive.test', $schoolA, 'internal_auditor');

        // THE SECOND SEAT HOLDS `approve` AND NOT `reject`, AND IT IS NOT A SEAT THAT EXISTS IN
        // PRODUCTION — which is precisely why the fixture has to build it.
        //
        // The review queue is reached through `finance.invoice.approve`; the return route adds
        // `permission:finance.invoice.reject` on top of that group. So the seat that distinguishes
        // the route's OWN gate from its group's gate must hold the first and not the second — and
        // no seeded role does, because `internal_auditor` holds both by design. A drive using only
        // `auditor@drive.test` cannot tell a gated Return control from an ungated one.
        //
        // Same shape and same justification as `drive_void_checker` above: a dedicated role, legal
        // under the grant guard because it holds one checker side and no maker.
        setPermissionsTeamId($schoolA->id);
        $approveOnly = Role::firstOrCreate(['name' => 'drive_ia_approve_only', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.invoice.approve', 'guard_name' => 'web']);
        $approveOnly->syncPermissions(['finance.invoice.approve']);
        setPermissionsTeamId(null);
        $this->driveUser('approve-only@drive.test', $schoolA, 'drive_ia_approve_only');

        // TWO GUARDIAN-CAPABLE SEATS, ADDED FOR THE GUARDIAN-CREATE DRIVE.
        //
        // Every seat above is a FINANCE seat, and not one of them can open /guardians:
        // that route group is gated on `academic_setup.manage`, and creating a guardian
        // additionally needs `guardian.create` — neither is held by accounts_officer,
        // executive_director or the void-only role, and super_admin's Gate::before
        // bypass is authorization, never a substitute for a real operator seat. The
        // canonical `admin` role holds both (RbacSeeder's $guardianFull plus
        // ACADEMIC_SETUP_MANAGE), so the seat is the seeded role rather than a
        // fixture-local invention that could drift from what a school actually grants.
        //
        // A SECOND SCHOOL'S admin is the isolation seat, and it has to be its own
        // account rather than school-b@drive.test: that seat holds accounts_officer and
        // would 403 on /guardians before it could demonstrate anything about admission
        // numbers not resolving across schools.
        $this->adminA = $this->driveUser('admin@drive.test', $schoolA, 'admin');
        $this->adminB = $this->driveUser('admin-b@drive.test', $schoolB, 'admin');

        // THE PARTIAL GUARDIAN EDITOR — the exact seat GuardianUpdateRequest's
        // credential refusal is written for, and one no canonical role produces:
        // RbacSeeder bundles GUARDIAN_UPDATE with GUARDIAN_UPDATE_CREDENTIALS in
        // $guardianFull (`:153-164`), and `registrar`, which holds the first without
        // the second, reaches no route at all (`:299-306`). So the refusal is only
        // reachable through a per-school runtime matrix edit — a supported operation —
        // and without a seat for it the behaviour can be asserted but never SEEN.
        //
        // Same shape and same justification as `void-checker@drive.test` above: a
        // dedicated fixture-local role holding a deliberately PARTIAL set, existing
        // because a partial holder is the case that breaks. That seat was added after
        // a drive found a full-page 403 for a void-only checker; this one is added
        // after a review found the opposite failure — a hard 403 on EVERY save,
        // including edits that touch no credential field at all.
        //
        // givePermissionTo, not syncPermissions: the role is new, so there is nothing
        // to detach, and Spatie's sync is non-atomic with post-write events
        // (CLAUDE.md). Wrapped anyway, because the rule is the rule.
        setPermissionsTeamId($schoolA->id);
        DB::transaction(function () {
            $editor = Role::firstOrCreate(['name' => 'drive_guardian_editor', 'guard_name' => 'web']);
            // BOTH gates, because the screen and its API are gated DIFFERENTLY: the
            // `/guardians` page sits behind `permission:admin_area.access`
            // (routes/web.php:353) while `/api/guardians*` sits behind
            // `permission:academic_setup.manage` (routes/api.php:47). A seat holding
            // only the API one signs in, reaches the page, and gets a full-page 403 —
            // which is exactly what the first run of this drive produced, and it looked
            // like a broken login rather than a missing grant.
            foreach (['guardian.view', 'guardian.update', 'academic_setup.manage', 'admin_area.access'] as $p) {
                Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
            }
            // NOT guardian.update_credentials. That absence is the entire seat.
            $editor->givePermissionTo(['guardian.view', 'guardian.update', 'academic_setup.manage', 'admin_area.access']);
        });
        setPermissionsTeamId(null);
        $this->guardianEditor = $this->driveUser('guardian-editor@drive.test', $schoolA, 'drive_guardian_editor');

        /*
         * THE PAYER SEAT, and the first non-staff login in this cast.
         *
         * `guardian` is a canonical role holding `parent_portal.access` (RbacSeeder), which is what
         * gates every parent route — the Fees page, the wards page and the payment endpoints. Nothing
         * fixture-local is needed: unlike `void-checker` and `guardian-editor`, this seat is not a
         * deliberately partial holder, it is an ordinary parent.
         *
         * THE GUARDIAN ROW AND THE PIVOT ARE THE POINT. The role alone reaches the page and sees
         * NOTHING: `GuardianFinanceController::wards` derives the wards from a `guardians` row for
         * this user in the active school and its `students` pivot. A seat with the role and no row is
         * the legitimate "no children linked" state, not a payer — so a drive of the screen's actual
         * subject needs the relationship, and it is built here rather than in the Finance states
         * because this class owns the cast and imports no Finance code.
         */
        $this->parent = $this->driveUser('parent@drive.test', $schoolA, 'guardian');

        // THE ROW ONLY. The PIVOT is attached in `run()` after `$parentWards` is populated — see
        // `linkPayerWards()`. It was attached here first and silently did nothing: `seedCast()` runs
        // BEFORE the enrollments block that fills that array, so the loop iterated an empty array,
        // wrote no pivot rows, and raised nothing. The seat existed, the guardian row existed, the
        // count table read `Guardians 1`, and the payer had no children.
        Guardian::forceCreate([
            'uuid' => (string) Str::uuid(),
            'school_id' => $schoolA->id,
            'user_id' => $this->parent->id,
            'first_name' => 'Payer',
            'last_name' => 'Drive',
            'phone' => '08000000001',
            'status' => 'active',
        ]);
    }

    /** A drive user: fixed password, verified email, NO 2FA secret, optionally school-scoped to $role. */
    /**
     * Attach the payer's three wards — AFTER `$parentWards` is populated, which is the whole point.
     *
     * The first version did this inside `seedCast()`, which runs BEFORE the enrollments block that
     * fills that array. The loop iterated nothing, wrote nothing, and raised nothing: the seat, the
     * role and the guardian row were all correct, `Guardians` counted 1, and the payer had no
     * children. It surfaced only when a human signed in and looked — which is the failure a drive
     * exists to catch, reproduced inside the fixture built for the drive.
     *
     * IT ASSERTS ITS OWN WORK. A seeder that attaches nothing is indistinguishable from one that
     * attaches correctly, and this method is the one place that knows how many wards there should
     * be. `sizeof` on both sides rather than a bare non-empty check: two of three attaching is the
     * failure that would leave a drive missing exactly one condition.
     */
    private function linkPayerWards(): void
    {
        $guardian = Guardian::withoutGlobalScopes()->where('user_id', $this->parent->id)->firstOrFail();

        foreach ($this->parentWards as $condition => $enrollmentUuid) {
            $studentId = StudentCurriculum::withoutGlobalScopes()
                ->where('uuid', $enrollmentUuid)
                ->value('student_id');

            if ($studentId === null) {
                throw new RuntimeException("Payer ward [{$condition}] resolved no student from enrollment {$enrollmentUuid}.");
            }

            $guardian->students()->syncWithoutDetaching([
                $studentId => ['relationship' => 'mother', 'is_primary' => true, 'can_login' => true],
            ]);
        }

        $linked = DB::table('guardian_student')->where('guardian_id', $guardian->id)->count();

        if ($linked !== count($this->parentWards)) {
            throw new RuntimeException(
                "Payer seat linked {$linked} ward(s); expected ".count($this->parentWards).'.'
            );
        }
    }

    private function driveUser(string $email, School $school, ?string $role): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'first_name' => Str::title(Str::before($email, '@')),
            'last_name' => 'Drive',
            'email' => $email,
            'password' => bcrypt(self::PASSWORD),
            'school_id' => $school->id,
            'email_verified_at' => now(),
        ]);

        if ($role !== null) {
            $user->grantSchoolAccess($school, $role);
            $user->flushSchoolAccessCache();
        }

        return $user;
    }

    private function student(School $school, string $first, string $last): Student
    {
        return Student::factory()->create(['school_id' => $school->id, 'first_name' => $first, 'last_name' => $last]);
    }

    private function enroll(School $school, string $first, string $last): string
    {
        return $this->enrollFor($school, $this->student($school, $first, $last));
    }

    /**
     * AN ACTIVE EPISODE AT THE SCHOOL'S PRICING COORDINATES — the placed counterpart of
     * {@see enrollFor()}, which leaves both coordinates NULL.
     *
     * The difference is entirely in the Curriculum: `term_id` and `class_level_arm_id` are what a
     * cohort read matches on, and a factory-default Curriculum sets neither.
     */
    private function enrollAt(School $school, Student $student): string
    {
        $slot = $this->coordinates[(int) $school->id];

        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $school->id,
            'curriculum_id' => Curriculum::factory()->create([
                'school_id' => $school->id,
                'class_level_arm_id' => $slot['arm_id'],
                'term_id' => $slot['term_id'],
            ])->id,
            'status' => 'active',
        ]);

        return (string) $enrollment->getAttribute('uuid');
    }

    private function enrollFor(School $school, Student $student): string
    {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        return (string) $enrollment->getAttribute('uuid');
    }
}
