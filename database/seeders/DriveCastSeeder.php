<?php

namespace Database\Seeders;

use App\Enums\TermStatusEnum;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Seeder;
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

    public int $schoolAId;

    public int $schoolBId;

    /** @var array<string, string> enrollment UUIDs keyed by state name */
    public array $enrollments = [];

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

        Term::create([
            'academic_session_id' => $session->id,
            'school_id' => $school->id,
            'name' => 'First Term',
            'slug' => 'drive-first-term-'.$school->id,
            'order' => 1,
            'status' => TermStatusEnum::ACTIVE->value,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2),
        ]);

        foreach ([['JSS 1', 1], ['JSS 2', 2]] as [$name, $order]) {
            ClassLevel::create([
                'school_id' => $school->id,
                'name' => $name,
                'order' => $order,
                'level_type' => 'JSS',
            ]);
        }
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

        $this->driveUser('school-b@drive.test', $schoolB, 'accounts_officer');
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
