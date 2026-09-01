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
     * Roles that require two-factor enrolment (C7).
     *
     * The default applies ONLY at role creation (`firstOrCreate` below, and `--fresh`); afterwards
     * `two_factor_required` is a runtime-editable matrix toggle (RbacMatrixController). So creation is
     * the only cheap moment to get a new role right. The flag is TEAM-AGNOSTIC at enforcement —
     * `EnsureTwoFactorEnrolled::requiresTwoFactor()` matches the role in ANY school or globally — and
     * sits under the `rbac.two_factor_enforced` master switch, which defaults ON in production.
     *
     * `executive_director` is here deliberately, and it is the FIRST finance seat to carry the flag.
     * That asymmetry is the point, not an oversight to normalise away: ED is the sole checker on all
     * four built finance pairs, so it is the only seat that can approve money leaving four different
     * ways. The operational finance seats (AO/AS/FL/IA) stay out — they propose and view.
     *
     * (The prior note here said the finance roles were "not seeded (step-0)". That stopped being true
     * with the 2026-08-01 realignment, which put all four in ROLES. Their absence below is now a
     * decision, not a consequence.)
     */
    public const TWO_FACTOR_REQUIRED = ['super_admin', 'admin', 'executive_director'];

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
        // Executive Director (ED) — new 2026-08-04. The single approval authority for every finance
        // decision that is built: fee-schedule change, discount-policy change, credit note, invoice
        // void. Took all five of head_of_school's finance grants and all four of accounts_supervisor's
        // checker sides. "Access across schools" is ASSIGNMENT to each school, not a passage through
        // the isolation boundary — nothing here goes near ISOLATION_CROSSING or the Gate::before.
        'executive_director',
        'accounts_officer',      // AO — bursar / maker on every finance flow
        'accounts_supervisor',   // AS — renamed from finance_director; maker + viewer since 2026-08-04
        'finance_lead',          // FL — proposer (credit-note + discount submit)
        'internal_auditor',      // IA — activity-log only. Still no finance.access, but the SAFETY reason is
        // gone: 001fd1f gated both payment doors on finance.payment.record
        // (endpoints/finance.php:24-25, :145-146), so finance.access now reaches
        // GET reads only. The grant is DECIDED and UNIMPLEMENTED, not open — see
        // the internal_auditor block in the grants map below.
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
    /*
     * COINING A PERMISSION? ADDING IT HERE IS OBLIGATION 3 OF 3 — the checklist is in
     * {@see \App\Enums\Permission}'s header. Two things this file cannot tell you on its own:
     *
     *   - A NEW permission is EXEMPT from the grants-convergence lint, because rbac:sync grants it
     *     in the same run. That exemption answers "will the grant LAND?", never "will it SURVIVE?".
     *   - The second question is the dangerous one. A role governed by a FORCING convergence
     *     migration has its namespace slice frozen to a literal, so the seeder writes the grant and
     *     the migration revokes it on the next deploy — at DEPLOY, not at build. Check the role
     *     before you add it; {@see \Tests\Feature\Rbac\ForcingMigrationsDoNotStripLaterGrantsTest}.
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
                // ── ROLLOVER (M4) — ADMIN ONLY FOR NOW, AND THAT IS A DECISION ────────────────
                // Separate from academic_setup.manage on purpose: config edits are reversible and
                // one row at a time; a rollover moves every pupil in the school across a year
                // boundary and cannot be undone by re-editing a row.
                //
                // ADMIN-ONLY IS THE DECISION, NOT AN OMISSION. This is the single most destructive
                // action in the system — it moves every pupil in a school across a year boundary —
                // so it ships with the smallest grant that can actually exercise it, to be widened
                // deliberately rather than narrowed after the fact.
                //
                // `registrar` is a FAST-FOLLOW, not a gap: the milestone's trigger was "a registrar
                // is expected to run one themselves", and that is blocked on a prerequisite this
                // work surfaced — registrar reaches NO role-gated route at all (see its block
                // below), so granting rollover alone would produce a permission it cannot exercise.
                // Ticket: docs/handoff/tickets/registrar-reaches-no-role-gated-route.md
                PermissionEnum::ACADEMICS_ROLLOVER->value,
                PermissionEnum::PRINCIPAL_APPROVAL_MANAGE->value,
                PermissionEnum::FINANCE_ACCESS->value,
                // Billing (S1 Part 0): admin may raise invoices and apply policy-backed reductions.
                PermissionEnum::FINANCE_INVOICE_GENERATE->value,
                PermissionEnum::FINANCE_INVOICE_REDUCTION_APPLY->value,
                // Fee-schedule authorship (S1 commit 2).
                PermissionEnum::FINANCE_FEE_SCHEDULE_MANAGE->value,
                PermissionEnum::FINANCE_BANK_ACCOUNT_MANAGE->value,
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
                // NO FINANCE AT ALL — 2026-08-04, Brookstone: "The heads of school have never approved
                // any of the items listed — they initiate it for my approval", and "nothing changed
                // except switching every permission and ability held by HoS to ED. HoS doesn't have
                // access to finance." The five grants that stood here (finance.access, and the
                // fee-schedule + discount-policy approve/reject pairs, added by the 2026-08-01
                // realignment) all moved to `executive_director` below. HoS keeps everything
                // non-finance; do not re-add a finance grant here without a business decision.
                //
                // `principal` KEEPS `finance.access` and that is deliberate, not a miss — answered
                // 2026-08-04: "The Principal role should be able to view finance." A secondary
                // Principal who also holds head_of_school therefore still sees the finance area.
                // `finance.access` alone is VIEW: no record, no generate, no approve.
                //
                // §9 step 4c's opening-balance checker side (finance.opening-balance.approve/.reject)
                // is NOT here either, for the same decision — it sits with `executive_director`.
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
                // Record money IN (ADR 0048 D1): the bursar takes payments at both doors. Held by AO ONLY —
                // NOT accounts_supervisor (matrix row 4 gives AS view, not do), finance_lead, head_of_school,
                // principal or admin — so "takes the money in" separates from "approves the write-off".
                PermissionEnum::FINANCE_PAYMENT_RECORD->value,
                // Direct where a recorded payment's unallocated remainder settles (U10). Held by AO ONLY,
                // for the same reason and on the same seat as FINANCE_PAYMENT_RECORD directly above:
                // directing money that has already arrived is the same operator's job as recording it
                // arriving. NOT held by accounts_supervisor, finance_lead, head_of_school, principal or
                // admin — an allocation moves which receivable a payment discharges, and the seat that
                // approves write-offs must not also be the seat that decides what a payment settles.
                //
                // `accounts_officer` is NOT governed by the forcing convergence migration
                // 2026_08_06_100000_move_head_of_school_finance_to_executive_director (its docblock names
                // the three roles it freezes, and this is not one), so this new grant lands via rbac:sync
                // and is not revoked on the next deploy. That is obligation 3 in Permission's checklist,
                // checked rather than assumed.
                PermissionEnum::FINANCE_PAYMENT_ALLOCATE->value,
                // Billing (S1 Part 0): the bursar raises invoices and applies policy-backed reductions.
                PermissionEnum::FINANCE_INVOICE_GENERATE->value,
                PermissionEnum::FINANCE_INVOICE_REDUCTION_APPLY->value,
                // Fee-schedule authorship (S1 commit 2): the bursar assembles the numbers.
                PermissionEnum::FINANCE_FEE_SCHEDULE_MANAGE->value,
                PermissionEnum::FINANCE_BANK_ACCOUNT_MANAGE->value,
                PermissionEnum::FINANCE_CREDIT_NOTE_SUBMIT->value,
                // Ph3b — maker side of the void instance too. Holding two MAKER permissions is
                // fine; the grant guard only forbids a maker + its MATCHING checker in one role.
                PermissionEnum::FINANCE_INVOICE_VOID_REQUEST_SUBMIT->value,
                // Seat realignment 2026-08-01: AO proposes the fee-schedule change (matrix row 2, AO=P)
                // and the discount-policy change (row 20, derived) — both submit-side, so still maker-only.
                PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT->value,
                PermissionEnum::FINANCE_DISCOUNT_POLICY_CHANGE_SUBMIT->value,
                // Opening-balance cutover (§9 step 4c) — the MAKER side, on the same two roles that hold
                // finance.fee-schedule.change.submit (AO here, AS below). Read off this map, not chosen:
                // the bursar office is who runs the WCBS extract. finance_lead does NOT get it, because
                // finance_lead does not hold fee-schedule.change.submit either. The CHECKER side is on
                // `executive_director` (2026-08-04), never on a maker seat.
                PermissionEnum::FINANCE_OPENING_BALANCE_SUBMIT->value,
                // BSS per-student discount awards (the award import). The bursar office holds the
                // scholarship list Brookstone keeps outside the system and is who uploads it, so the
                // ability sits on the same seat as the opening-balance maker directly above.
                //
                // AO ONLY, and the omissions are the decision. NOT `admin`: admin holds
                // finance.invoice.reduction.apply — one reduction on one invoice they are looking at —
                // and this is a STANDING price for a named child, a different act. NOT
                // `accounts_supervisor`, `head_of_school` or `executive_director`: all three are frozen
                // by 2026_08_06_100000_move_head_of_school_finance_to_executive_director, so a grant
                // there would be written by this seeder and revoked on the next deploy.
                // `accounts_officer` is ungoverned (that migration's docblock names the three it
                // freezes), so this lands AND survives — obligation 3 in Permission's checklist,
                // checked rather than assumed.
                PermissionEnum::FINANCE_DISCOUNT_AWARD_MANAGE->value,
            ],
            // Executive Director (ED) — new 2026-08-04. Brookstone: "The executive director approves
            // scholarships and discounts, concessions, refunds, write offs and other high impact
            // financial decisions… The heads of school have never approved any of the items listed."
            // Nine grants: `finance.access` plus the CHECKER side of all four built finance pairs —
            // five taken from head_of_school, four from accounts_supervisor.
            //
            // CHECKER SIDES ONLY. ED MUST NEVER HOLD A `*.submit`. Four maker-checker pairs now
            // terminate on this one role, so a single stray submit grant makes ED a both-sides holder
            // and DutySeparation throws at grant time — the seat that approves everything is exactly
            // the seat that must propose nothing. If you are here to add "just enough to raise a credit
            // note", that is a second role on the user, not a grant on this one.
            //
            // "Access across schools" is ASSIGNMENT: ED is assigned to every school and sees each one
            // through the school switcher. There is no combined all-schools view and this map cannot
            // create one. No new permission case exists for ED — all nine already existed.
            'executive_director' => [
                PermissionEnum::FINANCE_ACCESS->value,
                // From head_of_school (2026-08-04).
                PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_APPROVE->value,
                PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_REJECT->value,
                PermissionEnum::FINANCE_DISCOUNT_POLICY_CHANGE_APPROVE->value,
                PermissionEnum::FINANCE_DISCOUNT_POLICY_CHANGE_REJECT->value,
                // From accounts_supervisor (2026-08-04) — matrix rows 15/16.
                PermissionEnum::FINANCE_CREDIT_NOTE_APPROVE->value,
                PermissionEnum::FINANCE_CREDIT_NOTE_REJECT->value,
                PermissionEnum::FINANCE_INVOICE_VOID_REQUEST_APPROVE->value,
                PermissionEnum::FINANCE_INVOICE_VOID_REQUEST_REJECT->value,
                // Opening-balance cutover (§9 step 4c) — the CHECKER side of the FIFTH pair, and it sits
                // here because 2026_08_06_100000_move_head_of_school_finance_to_executive_director.php
                // moved EVERY finance checker side to this role and left `head_of_school` holding no
                // finance at all (the 2026-08-04 decision; the file is dated by its landing). That is the
                // placement rule now: a new finance checker ability lands on ED, full stop. It is NOT
                // derived from where the fee-schedule-change checker happens to sit — that reasoning was
                // right against the tree it read and wrong against the decision, which is exactly how a
                // grant ends up on a seat nobody chose for it.
                //
                // THESE TWO DO SHIP WITH A CONVERGENCE MIGRATION, and the reason is not the one the
                // convergence LINT cares about. Exemption 1 waives a migration for a new permission,
                // correctly: these land in $newPermissions and rbac:sync grants them per this map
                // everywhere. But 2026_08_06_100000's TARGET is FORCING — it makes this role's
                // `finance.` slice EQUAL a frozen literal — so on the deploy order (rbac:sync, then
                // migrate) it REVOKES what the seeder just wrote, and no later sync restores it.
                // 2026_08_09_110000_converge_opening_balance_grants.php puts them back. Measured, not
                // reasoned; see ADR 0052 § "A FORCING target freezes a namespace, not a row set".
                PermissionEnum::FINANCE_OPENING_BALANCE_APPROVE->value,
                PermissionEnum::FINANCE_OPENING_BALANCE_REJECT->value,
            ],
            // Accounts Supervisor (AS) — renamed from finance_director 2026-08-01. The 2026_08_01 rename
            // migration carries the role row + its holders; this map defines its grants.
            //
            // AS APPROVES NOTHING THAT IS BUILT, as of 2026-08-04. Its credit-note and invoice-void
            // checker sides (matrix rows 15/16) moved to `executive_director`, and matrix rows 14 and 19
            // — its other checker sides — were given to ED by the same decision and do not exist in code.
            // What remains is a maker-and-viewer seat: view the finance area, propose the fee-schedule
            // change (row 2, AS=P). Recorded rather than re-litigated: if that reads wrong to Brookstone
            // it is a business correction, not a code one.
            'accounts_supervisor' => [
                PermissionEnum::FINANCE_ACCESS->value,
                PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT->value,
                // Opening-balance cutover (§9 step 4c) — maker side, following fee-schedule.change.submit
                // above onto the same role: AS is a maker-and-viewer seat and this is a maker ability.
                // Its checker side is on `executive_director`, which holds no submit at all, so the pair
                // cannot land on one role and cannot land on one person without two deliberate role
                // assignments.
                PermissionEnum::FINANCE_OPENING_BALANCE_SUBMIT->value,
            ],
            // Finance Lead (FL) — new 2026-08-01. A PROPOSER in the matrix (rows 10, 12, 13, 16, 17):
            // submits credit notes (row 16, FL=P) and discount-policy changes (row 20, derived). Holds no
            // checker ability — never approves what the maker-checker split reserves for AS/HoS.
            'finance_lead' => [
                PermissionEnum::FINANCE_ACCESS->value,
                PermissionEnum::FINANCE_CREDIT_NOTE_SUBMIT->value,
                PermissionEnum::FINANCE_DISCOUNT_POLICY_CHANGE_SUBMIT->value,
            ],
            // Internal Auditor (IA) — new 2026-08-01, activity-log-only. Still NO finance.access, but the
            // ORIGINAL REASON NO LONGER HOLDS. It was: finance.access is not a read-only gate — both payment
            // doors carried it with NO ability of their own, PaymentController calls no authorize() and both
            // payment FormRequests authorize()=true, so finance.access ALONE posted a payment; granting it to
            // the control role would have let the auditor CREATE financial transactions — the inversion a
            // read-only control seat exists to prevent. 001fd1f (ADR 0048 D1) closed that: both doors now gate
            // on finance.payment.record — routes/endpoints/finance.php:24-25 (invoice-addressed) and :145-146
            // (student-addressed) — granted to accounts_officer alone (see AO above). Every other mutating
            // finance route already carried its own permission, so finance.access today reaches only the six
            // GET reads in that file plus the page shells, and confers NO payment capability on any holder.
            // The grant is therefore UNIMPLEMENTED, not undecided — do not re-open it as a question. v10 §7.2
            // (docs/Finance Module — Implementation Master Plan - v10.md:375, under DECIDED 2026-07-29) records
            // that the auditor NEEDS finance.access; :379 makes it a Phase 2 deliverable. What :377 adds is
            // why that is a deliverable and not a one-line edit here: NO finance.* read permission exists yet,
            // so finance.access on its own would buy entry to the surface with nothing financial to read. The
            // Phase 2 symmetry gate (every Finance resource with a write permission must carry a read one) is
            // what makes the grant meaningful. IA ships activity-log-only until then;
            // docs/rbac/finance-seat-realignment.md carries the same record.
            //
            // REMOVED 2026-08-04: activity_log.view_cross_school, which a0ab3d7 granted here. v10 §7.2
            // (docs/Finance Module — Implementation Master Plan - v10.md:375, the same DECIDED 2026-07-29
            // block cited above) says of that exact permission that it "is read-shaped, is in scope, and
            // must NOT be granted" — it is a CROSS-School read, and ADR 0036 makes isolation
            // un-bypassable by role. It is not a narrow widening: ActivityLogQueryService::baseQuery:42-52
            // drops the school predicate ENTIRELY for a holder, there being no narrower cross-school path.
            // What bounded it in practice was :55-57 of that same file restricting to self-caused rows
            // without activity_log.view_all — which IA does not hold, and which the Phase 2 auditor
            // derivation (every read segment) would grant. So this was armed, not safe. The seeder is
            // non-destructive (sync() below), so removing the line here changes nothing on an environment
            // where the role row already exists — the revocation itself is
            // 2026_08_04_100000_revoke_internal_auditor_cross_school. `super_admin` KEEPS the permission,
            // legitimately and by a different route: SUPER_ADMIN_PLATFORM (:57-62), ADR 0045 A3, self-healed
            // every run (:503-512). The forbidden set is PermissionEnum::ISOLATION_CROSSING, pinned by
            // GrantsMapSeparationTest and enforced at runtime by SyncRolePermissionsRequest.
            'internal_auditor' => [
                PermissionEnum::ACTIVITY_LOG_VIEW->value,
                PermissionEnum::ACTIVITY_LOG_EXPORT->value,
                // ADDED 2026-09-01, and it is what makes the two lines above mean anything.
                // ActivityLogQueryService::baseQuery restricts a viewer WITHOUT view_all to rows
                // they THEMSELVES caused. An auditor only ever reads other people's acts, so the
                // seat was reading an empty feed by construction — and the paragraph above already
                // says so ("restricting to self-caused rows without activity_log.view_all — which
                // IA does not hold"), as the reason view_cross_school was armed rather than safe.
                // With view_cross_school revoked (2026_08_04_100000) view_all is bounded to the
                // active school by the same baseQuery's school predicate and by SchoolScope, so it
                // is a WITHIN-school read and not a member of PermissionEnum::ISOLATION_CROSSING.
                // It is deliberately NOT accompanied by view_sensitive: the auditor sees acts, not
                // the entries the catalogue marks confidential.
                // Converged by 2026_09_01_120000_grant_internal_auditor_activity_log_view_all.
                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
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
