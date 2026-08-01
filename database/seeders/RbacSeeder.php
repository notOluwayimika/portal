<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The single authoritative RBAC seeder (C1): roles + permissions + the
 * role→permission grants map, consolidated from the five seeders it replaces
 * (RoleSeeder, GuardianPermissionSeeder, StudentSubjectPermissionSeeder,
 * TeacherAssignmentPermissionSeeder, ActivityLogPermissionSeeder).
 *
 * Grants are WEB-guard only. The api-guard `super_admin` row created by
 * 2026_07_12_000004_seed_first_super_admin is deliberately untouched — that
 * migration owns the guard pair; this seeder never grants against it.
 *
 * Re-run semantics (non-destructive by default):
 *  - roles/permissions: firstOrCreate — never duplicated, never deleted
 *    (except pruning permissions the enum no longer declares, which by
 *    definition no code checks).
 *  - grants: applied only where the permission OR the role is newly created
 *    this run, so runtime grant/revoke edits survive re-seeding.
 *  - --fresh (via `php artisan rbac:sync --fresh`): exact syncPermissions
 *    reset to this map. Dev/CI/fresh installs.
 *
 * super_admin: granted the fallback set below even though Gate::before
 * (auth.gate_before_superadmin, default on) already passes it everything —
 * stripping the rows would silently couple super-admin access to that flag.
 * It deliberately holds NO maker–checker permission (result.submit/approve/
 * reject): ADR 0040 — super_admin never overrides maker–checker, and one
 * actor must not hold both sides of an SoD pair.
 */
class RbacSeeder extends Seeder
{
    public const GUARD = 'web';

    /**
     * Roles that require two-factor enrolment (C7). super_admin + admin only:
     * the 4 Finance roles are not seeded (step-0), their default is I6/Finance.
     */
    public const TWO_FACTOR_REQUIRED = ['super_admin', 'admin'];

    /**
     * super_admin's explicit PLATFORM-ADMIN set (ADR 0045 A2/A3, slice B2).
     * rbac.impersonate is the MASTER KEY: post-de-bypass its absence strands
     * every super_admin domain capability, which is why super_admin is
     * SELF-HEALED to exactly this set every run — the deliberate,
     * C6-immutability-justified exception to the non-destructive contract
     * (the matrix cannot edit this row, so there are no runtime grants to
     * preserve, and drift here is catastrophic rather than degrading).
     */
    public const SUPER_ADMIN_PLATFORM = [
        'rbac.impersonate',
        'rbac.manage_users',
        'activity_log.view_system',
        'activity_log.view_cross_school',
    ];

    /** Global (null-team) roles. Assignment to users is per-School (teams). */
    public const ROLES = [
        'super_admin',
        'admin',
        'principal',
        'head_of_school',
        'teacher',
        'guardian',
        'boarding_parent',
        'form_teacher',
        // Primary's senior commenter — see the grants map below.
        'key_stage_coordinator',
        'registrar',
        // Finance seats — Brookstone's business roles (2026-08-01 realignment,
        // docs/rbac/finance-seat-realignment.md). Segregation of duties: makers propose, checkers
        // (≠ maker) decide; the SyncRolePermissionsRequest grant guard makes any one role holding
        // both sides of a pair impossible.
        'accounts_officer',      // AO — bursar / maker on every finance flow
        'accounts_supervisor',   // AS — renamed from finance_director; CHECKS credit-note + void
        'finance_lead',          // FL — proposer (credit-note + discount submit)
        'internal_auditor',      // IA — read-all/act-nothing (finance.access gate + activity-log)
        // NOTE: finance_void_approver (a one-sided void checker, seeded only so the access oracle
        // exercised the D1 single-side-checker case) was DELETED 2026-08-01 — Brookstone has no such
        // seat and it had zero holders in production. The D1 oracle row is a recorded coverage loss;
        // head_of_school (checker-only, no makers) is the nearest remaining single-side-checker role.
    ];

    /**
     * The canonical role→permission map (web guard). Consolidates the exact
     * grants the five legacy seeders produced — including the super_admin
     * grants the old fixture masked (guard-pair name collision, see C1 PR).
     *
     * The route-access tier (C2) gave `guardian` and `principal` their first
     * permissions: each route_access grant below mirrors, exactly, the role
     * list of the pre-swap role: middleware group it replaced —
     * RouteAccessParityTest holds the equivalence.
     *
     * @return array<string, list<string>>
     */
    public static function grantsMap(): array
    {
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_OWN->value,
        ];

        $activityAdmin = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_OWN->value,
            PermissionEnum::ACTIVITY_LOG_EXPORT->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_SENSITIVE->value,
        ];

        $guardianFull = [
            PermissionEnum::GUARDIAN_VIEW->value,
            PermissionEnum::GUARDIAN_UPDATE->value,
            PermissionEnum::GUARDIAN_UPDATE_CREDENTIALS->value,
            PermissionEnum::GUARDIAN_DETACH->value,
            PermissionEnum::GUARDIAN_ENABLE_LOGIN->value,
            PermissionEnum::GUARDIAN_CREATE->value,
            PermissionEnum::GUARDIAN_EXPORT->value,
            PermissionEnum::GUARDIAN_MESSAGE->value,
            PermissionEnum::GUARDIAN_VIEW_AUDIT->value,
            PermissionEnum::GUARDIAN_IMPORT->value,
        ];

        $studentSubjectFull = [
            PermissionEnum::STUDENT_SUBJECT_VIEW->value,
            PermissionEnum::STUDENT_SUBJECT_ADD_OPTIONAL->value,
            PermissionEnum::STUDENT_SUBJECT_DROP_OPTIONAL->value,
            PermissionEnum::STUDENT_SUBJECT_RESTORE->value,
            PermissionEnum::STUDENT_SUBJECT_VIEW_HISTORY->value,
        ];

        $assessments = [
            PermissionEnum::VIEW_BEHAVIORAL_ASSESSMENTS->value,
            PermissionEnum::CREATE_BEHAVIORAL_ASSESSMENTS->value,
            PermissionEnum::EDIT_BEHAVIORAL_ASSESSMENTS->value,
            PermissionEnum::VIEW_PSYCHOMOTOR_SKILLS->value,
            PermissionEnum::CREATE_PSYCHOMOTOR_SKILLS->value,
            PermissionEnum::EDIT_PSYCHOMOTOR_SKILLS->value,
        ];

        $enrollmentAdmin = [
            PermissionEnum::STUDENT_CURRICULUM_UNENROLL->value,
            PermissionEnum::CURRICULUM_SUBJECT_ARCHIVE->value,
            PermissionEnum::CURRICULUM_SUBJECT_RESTORE->value,
            // ADR 0044 enrollment lifecycle — admin + head_of_school.
            PermissionEnum::STUDENT_CURRICULUM_REGISTER->value,
            PermissionEnum::STUDENT_CURRICULUM_PROMOTE->value,
            PermissionEnum::STUDENT_CURRICULUM_UPDATE_STATUS->value,
        ];

        // ADR 0044 result checker side. Admin follows the ADR's recommendation
        // (a): approve/reject + view, and deliberately NOT result.submit — one
        // actor holding maker AND checker for the same result defeats SoD.
        $resultChecker = [
            PermissionEnum::RESULT_APPROVE->value,
            PermissionEnum::RESULT_REJECT->value,
            PermissionEnum::RESULT_VIEW_SCORES->value,
        ];

        // C2 route-access tier: each permission's holder set below reproduces
        // the role list of the pre-swap role: middleware group it replaced —
        // NOT a redesign of who should see what. RouteAccessParityTest diffs
        // live route access against the pre-swap fixture, so any drift here
        // (or in the route files) from the pre-swap sets is a red test.
        // super_admin deliberately gets none: its passage is Gate::before.

        return [
            'admin' => [
                ...$guardianFull,
                ...$studentSubjectFull,
                ...$enrollmentAdmin,
                ...$assessments,
                ...$activityAdmin,
                ...$resultChecker,
                PermissionEnum::MANAGE_TEACHER_ASSIGNMENTS->value,
                PermissionEnum::MANAGE_HEAD_OF_SCHOOL_COMMENTS->value,
                // Route access (C2)
                PermissionEnum::ADMIN_AREA_ACCESS->value,
                PermissionEnum::STUDENT_DIRECTORY_VIEW->value,
                PermissionEnum::RESULT_REVIEW_ACCESS->value,
                PermissionEnum::REPORT_VIEW->value,
                PermissionEnum::CURRICULUM_SUBJECT_VIEW->value,
                PermissionEnum::STUDENT_CURRICULUM_VIEW->value,
                PermissionEnum::DASHBOARD_VIEW->value,
                PermissionEnum::RESULT_VIEW->value,
                PermissionEnum::ACADEMIC_SETUP_MANAGE->value,
                PermissionEnum::PRINCIPAL_APPROVAL_MANAGE->value,
                PermissionEnum::FINANCE_ACCESS->value,
                // Billing (S1 Part 0): admin may raise invoices and apply policy-backed reductions.
                PermissionEnum::FINANCE_INVOICE_GENERATE->value,
                PermissionEnum::FINANCE_INVOICE_REDUCTION_APPLY->value,
                // Fee-schedule authorship (S1 commit 2).
                PermissionEnum::FINANCE_FEE_SCHEDULE_MANAGE->value,
                // Credit-note issuance is now maker-checker (Ph3): admin keeps finance
                // read access but holds NEITHER the maker nor the checker permission —
                // even admin cannot forgive money alone. The dedicated accounts_officer /
                // finance_director roles own the two-person flow.
                PermissionEnum::ACADEMIC_DATA_VIEW->value,
                PermissionEnum::SCORE_MANAGE->value,
                PermissionEnum::STUDENT_STATUS_VIEW->value,
                PermissionEnum::STUDENT_VIEW->value,
                // RBAC administration (C5): the school-admin Users module.
                PermissionEnum::RBAC_MANAGE_USERS->value,
            ],
            'head_of_school' => [
                ...$guardianFull,
                ...$studentSubjectFull,
                ...$enrollmentAdmin,
                ...$assessments,
                ...$activityAdmin,
                ...$resultChecker,
                PermissionEnum::MANAGE_HEAD_OF_SCHOOL_COMMENTS->value,
                // Finance governance — HoS is the APPROVER (2026-08-01 seat realignment, docs/rbac/
                // finance-seat-realignment.md). Brookstone's matrix: HoS approves the fee-schedule change
                // (row 2, HoS=A) and the discount-policy change (row 20, derived by analogy with row 2).
                // The SUBMIT sides moved OFF HoS to AO/AS/FL — HoS must never hold both sides of a pair
                // (DutySeparation enforces it at grant time), and approving what you proposed is the whole
                // thing SoD forbids.
                PermissionEnum::FINANCE_ACCESS->value,
                PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_APPROVE->value,
                PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_REJECT->value,
                PermissionEnum::FINANCE_DISCOUNT_POLICY_CHANGE_APPROVE->value,
                PermissionEnum::FINANCE_DISCOUNT_POLICY_CHANGE_REJECT->value,
                // Route access (C2)
                PermissionEnum::RESULT_REVIEW_ACCESS->value,
                PermissionEnum::REPORT_VIEW->value,
                PermissionEnum::CURRICULUM_SUBJECT_VIEW->value,
                PermissionEnum::STUDENT_CURRICULUM_VIEW->value,
                PermissionEnum::DASHBOARD_VIEW->value,
                PermissionEnum::RESULT_SIGNATURE_MANAGE->value,
                PermissionEnum::RESULT_VIEW->value,
                PermissionEnum::ACADEMIC_SETUP_MANAGE->value,
                PermissionEnum::ACADEMIC_DATA_VIEW->value,
                PermissionEnum::SCORE_MANAGE->value,
                PermissionEnum::STUDENT_STATUS_VIEW->value,
                PermissionEnum::STUDENT_VIEW->value,
            ],
            'teacher' => [
                PermissionEnum::STUDENT_SUBJECT_VIEW->value,
                ...$activityStaff,
                // ADR 0044 maker side: submit + read, never approve/reject.
                PermissionEnum::RESULT_SUBMIT->value,
                PermissionEnum::RESULT_VIEW_SCORES->value,
                // Route access (C2)
                PermissionEnum::CURRICULUM_SUBJECT_VIEW->value,
                PermissionEnum::STUDENT_CURRICULUM_VIEW->value,
                PermissionEnum::DASHBOARD_VIEW->value,
                PermissionEnum::ACADEMIC_DATA_VIEW->value,
                PermissionEnum::SCORE_MANAGE->value,
                PermissionEnum::STUDENT_STATUS_VIEW->value,
            ],
            'registrar' => [
                PermissionEnum::GUARDIAN_VIEW->value,
                PermissionEnum::GUARDIAN_UPDATE->value,
                PermissionEnum::GUARDIAN_DETACH->value,
                PermissionEnum::GUARDIAN_CREATE->value,
                // No route access: registrar appeared in no pre-swap role:
                // group, so it reaches no role-gated route — unchanged.
            ],
            'guardian' => [
                // Route access (C2) — guardian's first grants; exactly the
                // groups that listed `guardian` pre-swap.
                PermissionEnum::CURRICULUM_SUBJECT_VIEW->value,
                PermissionEnum::STUDENT_CURRICULUM_VIEW->value,
                PermissionEnum::DASHBOARD_VIEW->value,
                PermissionEnum::RESULT_VIEW->value,
                PermissionEnum::PARENT_PORTAL_ACCESS->value,
                PermissionEnum::STUDENT_STATUS_VIEW->value,
            ],
            'principal' => [
                // finance.access (route gate) only. The discount-policy and fee-schedule approve/reject
                // grants were REMOVED 2026-08-01 (docs/rbac/finance-seat-realignment.md): `principal`
                // appears nowhere in Brookstone's finance matrix and had been holding a finance approval
                // authority the business never sanctioned. Those approvals moved to head_of_school (HoS=A).
                PermissionEnum::FINANCE_ACCESS->value,
                // Route access (C2) — principal's first grants; exactly the
                // groups that listed `principal` pre-swap.
                PermissionEnum::STUDENT_DIRECTORY_VIEW->value,
                PermissionEnum::REPORT_VIEW->value,
                PermissionEnum::STUDENT_CURRICULUM_VIEW->value,
                PermissionEnum::DASHBOARD_VIEW->value,
                PermissionEnum::RESULT_SIGNATURE_MANAGE->value,
                PermissionEnum::RESULT_VIEW->value,
                PermissionEnum::PRINCIPAL_APPROVAL_MANAGE->value,
                PermissionEnum::STUDENT_STATUS_VIEW->value,
                PermissionEnum::STUDENT_VIEW->value,
            ],
            'boarding_parent' => [
                ...$assessments,
                // Route access (C2)
                PermissionEnum::BOARDING_PORTAL_ACCESS->value,
                PermissionEnum::ASSESSMENT_RECORD->value,
            ],
            // Primary's senior commenter — the same job head_of_school does in
            // secondary, under the name that school uses. Modelled on form_teacher,
            // NOT on head_of_school: a Key Stage Coordinator writes a comment for the
            // arms they are assigned, and holds none of head_of_school's admin or
            // finance maker grants.
            'key_stage_coordinator' => [
                PermissionEnum::MANAGE_KEY_STAGE_COORDINATOR_COMMENTS->value,
                // Route access: the comment screen's term filter reads
                // GET /api/class-structure, which sits behind academic_data.view.
                //
                // DELIBERATELY NOT academic_setup.manage, which form_teacher holds:
                // the route-access oracle showed that grant would hand a Key Stage
                // Coordinator DELETE on class levels, arms, streams and comment
                // bands. form_teacher carrying it is pre-existing and out of scope,
                // but there is no reason to copy it into a new role.
                PermissionEnum::ACADEMIC_DATA_VIEW->value,
            ],
            'form_teacher' => [
                PermissionEnum::MANAGE_FORM_TEACHER_COMMENTS->value,
                ...$assessments,
                // Route access (C2)
                PermissionEnum::ACADEMIC_SETUP_MANAGE->value,
                PermissionEnum::ASSESSMENT_RECORD->value,
                PermissionEnum::STUDENT_VIEW->value,
            ],
            // Finance credit-note maker-checker (Ph3). Each holds finance.access (the
            // group gate for the finance pages) plus EXACTLY one side of the split — never
            // both (the grant guard enforces it). super_admin is absent by design: ADR 0040
            // — a platform authority never holds a maker-checker permission.
            // Account Officer (AO) — the bursar. MAKER on every finance flow; never a checker.
            'accounts_officer' => [
                PermissionEnum::FINANCE_ACCESS->value,
                // Billing (S1 Part 0): the bursar raises invoices and applies policy-backed reductions.
                PermissionEnum::FINANCE_INVOICE_GENERATE->value,
                PermissionEnum::FINANCE_INVOICE_REDUCTION_APPLY->value,
                // Fee-schedule authorship (S1 commit 2): the bursar assembles the numbers.
                PermissionEnum::FINANCE_FEE_SCHEDULE_MANAGE->value,
                PermissionEnum::FINANCE_CREDIT_NOTE_SUBMIT->value,
                // Ph3b — maker side of the void instance too. Holding two MAKER permissions is
                // fine; the grant guard only forbids a maker + its MATCHING checker in one role.
                PermissionEnum::FINANCE_INVOICE_VOID_REQUEST_SUBMIT->value,
                // Seat realignment 2026-08-01: AO proposes the fee-schedule change (matrix row 2, AO=P)
                // and the discount-policy change (row 20, derived) — both submit-side, so still maker-only.
                PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT->value,
                PermissionEnum::FINANCE_DISCOUNT_POLICY_CHANGE_SUBMIT->value,
            ],
            // Accounts Supervisor (AS) — renamed from finance_director 2026-08-01 (it is the SUPERVISOR,
            // not the lead: it CHECKS credit-note + void, matrix rows 15/16 = AS=A). The 2026_08_01 rename
            // migration carries the role row + its holders; this map defines its grants.
            'accounts_supervisor' => [
                PermissionEnum::FINANCE_ACCESS->value,
                PermissionEnum::FINANCE_CREDIT_NOTE_APPROVE->value,
                PermissionEnum::FINANCE_CREDIT_NOTE_REJECT->value,
                // Ph3b — checker side of the void instance.
                PermissionEnum::FINANCE_INVOICE_VOID_REQUEST_APPROVE->value,
                PermissionEnum::FINANCE_INVOICE_VOID_REQUEST_REJECT->value,
                // Seat realignment: AS also proposes the fee-schedule change (row 2, AS=P) — a maker side,
                // distinct pair from its credit-note/void checker sides, so no both-sides violation.
                PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT->value,
            ],
            // Finance Lead (FL) — new 2026-08-01. A PROPOSER in the matrix (rows 10, 12, 13, 16, 17):
            // submits credit notes (row 16, FL=P) and discount-policy changes (row 20, derived). Holds no
            // checker ability — never approves what the maker-checker split reserves for AS/HoS.
            'finance_lead' => [
                PermissionEnum::FINANCE_ACCESS->value,
                PermissionEnum::FINANCE_CREDIT_NOTE_SUBMIT->value,
                PermissionEnum::FINANCE_DISCOUNT_POLICY_CHANGE_SUBMIT->value,
            ],
            // Internal Auditor (IA) — new 2026-08-01, activity-log-only. NO finance.access, deliberately:
            // finance.access is not a read-only gate — routes/endpoints/finance.php:24 and :143 (POST
            // …/payments) carry finance.access and NO further permission, PaymentController calls no
            // authorize(), and the payment FormRequests authorize()=true, so finance.access ALONE posts a
            // payment. Granting it to the control role would let the auditor CREATE financial transactions —
            // the exact V→(should-not-be-D) inversion the matrix forbids (IA=V on rows 3-6). IA ships as
            // activity-log-only (matrix rows 8/9, IA=D — cross-school read/export). Its finance-screen READ
            // access (rows 3-6, IA=V) is DEFERRED until finance.access splits read from act; recorded as a
            // named deferral in docs/rbac/finance-seat-realignment.md, not an oversight.
            'internal_auditor' => [
                PermissionEnum::ACTIVITY_LOG_VIEW->value,
                PermissionEnum::ACTIVITY_LOG_EXPORT->value,
                PermissionEnum::ACTIVITY_LOG_VIEW_CROSS_SCHOOL->value,
            ],
            // ADR 0045 (B2): the explicit set IS the platform-admin set — no
            // ambient domain grants. Self-healed every run (see const).
            'super_admin' => self::SUPER_ADMIN_PLATFORM,
        ];
    }

    public function run(): void
    {
        $this->sync(fresh: false);
    }

    public function sync(bool $fresh): void
    {
        // Seed-time mutations are provenance-by-code-review, not audit events:
        // without this, every fresh seed writes hundreds of 'rbac' activity
        // rows through LogRbacChange + LogsActivity. Runtime mutations (the
        // matrix UI, artisan tinkering) remain fully audited.
        activity()->withoutLogs(fn () => $this->syncLogged($fresh));
    }

    private function syncLogged(bool $fresh): void
    {
        // Roles are global; make the null-team context explicit.
        setPermissionsTeamId(null);

        $enumValues = PermissionEnum::values();

        $existingPermissions = Permission::where('guard_name', self::GUARD)
            ->pluck('name')->all();
        $existingRoles = Role::where('guard_name', self::GUARD)
            ->whereNull('school_id')->pluck('name')->all();

        foreach ($enumValues as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);
        }

        // Prune permissions the enum no longer declares (enum-exactness): by
        // definition no code checks them, so the rows are dead weight. Pivot
        // rows go with them.
        Permission::where('guard_name', self::GUARD)
            ->whereNotIn('name', $enumValues)
            ->get()
            ->each(fn (Permission $p) => $p->delete());

        foreach (self::ROLES as $name) {
            $role = Role::firstOrCreate([
                'name' => $name,
                'guard_name' => self::GUARD,
                'school_id' => null,
            ], [
                // C7 defaults apply at creation; on re-runs the flag is a
                // runtime-editable value (the matrix toggle) and is preserved
                // unless --fresh resets it below.
                'two_factor_required' => in_array($name, self::TWO_FACTOR_REQUIRED, true),
            ]);

            if ($fresh) {
                // forceFill, not update(): mass-assignment silently drops this
                // key on existing Role instances (see RbacMatrixController).
                $role->forceFill(['two_factor_required' => in_array($name, self::TWO_FACTOR_REQUIRED, true)])->save();
            }
        }

        $newPermissions = array_diff($enumValues, $existingPermissions);

        foreach (self::grantsMap() as $roleName => $permissions) {
            $role = Role::where('name', $roleName)
                ->where('guard_name', self::GUARD)
                ->whereNull('school_id')
                ->firstOrFail();

            if ($fresh) {
                $role->syncPermissions($permissions);

                continue;
            }

            // Non-destructive: only grants involving something newly created
            // this run — runtime matrix edits (grants AND revokes) survive.
            $toGrant = in_array($roleName, $existingRoles, true)
                ? array_values(array_intersect($permissions, $newPermissions))
                : $permissions;

            if ($toGrant !== []) {
                $role->givePermissionTo($toGrant);
            }
        }

        // Self-heal super_admin to canonical (ADR 0045 A3): syncPermissions in
        // a transaction (the C6 vendor lesson — the trait holds none), inside
        // withoutLogs like all seed-time mutations.
        DB::transaction(function () {
            Role::where('name', 'super_admin')
                ->where('guard_name', self::GUARD)
                ->whereNull('school_id')
                ->firstOrFail()
                ->syncPermissions(self::SUPER_ADMIN_PLATFORM);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
