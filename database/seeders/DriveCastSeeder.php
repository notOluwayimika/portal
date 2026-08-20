<?php

namespace Database\Seeders;

use App\Enums\TermStatusEnum;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    }

    /** A drive user: fixed password, verified email, NO 2FA secret, optionally school-scoped to $role. */
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
