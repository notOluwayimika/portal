<?php

namespace App\Enums;

use Fully\Qualified;

/**
 * The canonical, magic-string-free registry of application permissions.
 *
 * Values are the exact permission names stored in the `permissions` table and
 * checked via $user->can(...). The three `manage_*` / assessment cases keep
 * their legacy non-dotted names deliberately — renaming them to the dotted
 * convention would change the stored value and break existing checks, so that
 * is a separate migration, not part of introducing this enum.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * COINING A PERMISSION HAS THREE OBLIGATIONS. ADDING A CASE HERE IS ONE OF THEM.
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * This is the file you open to coin one, so the whole checklist lives here rather than in a doc
 * nobody is reading at the moment they need it. Doing only step 1 costs a full gate cycle: it fails
 * FIVE tests at build (PermissionGroupTest ×2, PermissionEnumTest, RbacConsoleTest,
 * SchoolRbacConsoleTest), which is the good kind of failure and still an avoidable hour.
 *
 *   1. THE CASE, here.
 *
 *   2. ITS GROUP, in App\Enums\PermissionGroup. {@see Permission::group()} resolves through
 *      PermissionGroup::lookup() with NO FALLBACK, and that is deliberate: an unfiled case must be
 *      a failing test rather than a permission that silently vanishes from the RBAC console, where
 *      nobody could ever grant it. Do NOT "fix" a missing-key error by adding a default — the
 *      absence of a fallback IS the mechanism, and a default would convert a red build into a
 *      permission that exists in code and cannot be administered.
 *
 *   3. ITS GRANT, in Database\Seeders\RbacSeeder::grantsMap() — and CHECK WHICH ROLE. A role
 *      governed by a forcing convergence migration has its namespace slice frozen: the seeder
 *      writes the grant and the migration REVOKES it on the next deploy, silently, which fails at
 *      DEPLOY rather than at build. The invariant and the `@converges` escape are pinned by
 *      tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php — named as a PATH, not as a
 *      `{@see Qualified}` reference: Pint's fully_qualified_strict_types would promote that
 *      into a real `use Tests\…;` import, and `Tests\` is autoload-DEV, so the production enum
 *      would carry a class that does not exist under --no-dev. See bin/ci-dev-namespace-lint.php.
 *
 *   Then REGENERATE THE ORACLES, in this order:
 *     php artisan rbac:sync
 *     php artisan rbac:derive-access          → tests/fixtures/route-access-map.json
 *     tests/fixtures/rbac-grants-baseline.json (re-dumped from a freshly seeded database)
 *
 * A NEW permission is exempt from the grants-convergence lint (exemption 1) because rbac:sync
 * grants it in the same run. That exemption answers "will the grant LAND?" and not "will it
 * SURVIVE?" — obligation 3's warning is the second question, and the lint does not ask it.
 */
enum Permission: string
{
    // Activity log
    case ACTIVITY_LOG_VIEW = 'activity_log.view';
    case ACTIVITY_LOG_VIEW_ALL = 'activity_log.view_all';
    case ACTIVITY_LOG_VIEW_OWN = 'activity_log.view_own';
    case ACTIVITY_LOG_VIEW_SYSTEM = 'activity_log.view_system';
    case ACTIVITY_LOG_VIEW_CROSS_SCHOOL = 'activity_log.view_cross_school';
    case ACTIVITY_LOG_EXPORT = 'activity_log.export';
    case ACTIVITY_LOG_VIEW_SENSITIVE = 'activity_log.view_sensitive';

    // Guardian
    case GUARDIAN_VIEW = 'guardian.view';
    case GUARDIAN_UPDATE = 'guardian.update';
    case GUARDIAN_UPDATE_CREDENTIALS = 'guardian.update_credentials';
    case GUARDIAN_DETACH = 'guardian.detach';
    case GUARDIAN_ENABLE_LOGIN = 'guardian.enable_login';
    case GUARDIAN_CREATE = 'guardian.create';
    case GUARDIAN_EXPORT = 'guardian.export';
    case GUARDIAN_MESSAGE = 'guardian.message';
    case GUARDIAN_VIEW_AUDIT = 'guardian.view_audit';
    case GUARDIAN_IMPORT = 'guardian.import';

    // Student subject / curriculum
    case STUDENT_SUBJECT_VIEW = 'student_subject.view';
    case STUDENT_SUBJECT_ADD_OPTIONAL = 'student_subject.add_optional';
    case STUDENT_SUBJECT_DROP_OPTIONAL = 'student_subject.drop_optional';
    case STUDENT_SUBJECT_RESTORE = 'student_subject.restore';
    case STUDENT_SUBJECT_VIEW_HISTORY = 'student_subject.view_history';
    case STUDENT_CURRICULUM_UNENROLL = 'student_curriculum.unenroll';
    case CURRICULUM_SUBJECT_ARCHIVE = 'curriculum_subject.archive';
    case CURRICULUM_SUBJECT_RESTORE = 'curriculum_subject.restore';
    // CURRICULUM_SUBJECT_FORCE_DELETE was removed in C1: zero call sites ever
    // checked it, and its only holder (super_admin) passes via Gate::before —
    // dead in both directions. RbacSeeder prunes the orphaned row on sync.

    // Result lifecycle — ADR 0044 (maker–checker: submit is the maker side,
    // approve/reject the checker side; one role must never hold both).
    case RESULT_SUBMIT = 'result.submit';
    case RESULT_APPROVE = 'result.approve';
    case RESULT_REJECT = 'result.reject';
    case RESULT_VIEW_SCORES = 'result.view_scores';

    // Enrollment lifecycle — ADR 0044.
    case STUDENT_CURRICULUM_REGISTER = 'student_curriculum.register';
    case STUDENT_CURRICULUM_PROMOTE = 'student_curriculum.promote';
    case STUDENT_CURRICULUM_UPDATE_STATUS = 'student_curriculum.update_status';

    // Route-access tier (C2): one coarse per-surface permission per pre-swap
    // role: middleware group, granted to exactly the roles that group listed
    // (parity-proven by RouteAccessParityTest). These gate route entry only;
    // finer per-action redistribution is later slices' work (C3 policies,
    // C6 matrix editing). super_admin holds none of them — its passage is
    // the Gate::before bypass, per the authority probe.
    case ADMIN_AREA_ACCESS = 'admin_area.access';
    case STUDENT_DIRECTORY_VIEW = 'student_directory.view';
    case RESULT_REVIEW_ACCESS = 'result_review.access';
    case REPORT_VIEW = 'report.view';
    case CURRICULUM_SUBJECT_VIEW = 'curriculum_subject.view';
    case STUDENT_CURRICULUM_VIEW = 'student_curriculum.view';
    case DASHBOARD_VIEW = 'dashboard.view';
    case RESULT_SIGNATURE_MANAGE = 'result_signature.manage';
    case RESULT_VIEW = 'result.view';
    case PARENT_PORTAL_ACCESS = 'parent_portal.access';
    case BOARDING_PORTAL_ACCESS = 'boarding_portal.access';
    case ACADEMIC_SETUP_MANAGE = 'academic_setup.manage';
    // ── ROLLOVER IS ITS OWN PERMISSION, NOT academic_setup.manage (M4) ────────────────────────────
    // Deliberately separate, and the plan deferred creating it until something checked it — M4's
    // controller is the first checker.
    //
    // academic_setup.manage gates CONFIG: reversible, one-row-at-a-time edits to class structure
    // and progression pointers, held by roles including form_teacher. A rollover is a once-a-term
    // act that moves EVERY pupil in a school across a year boundary and cannot be undone by
    // re-editing a row. Sharing the permission would mean anyone who can rename an arm can roll the
    // year over — which was tolerable only while shell access was the real gate, and M4 exists
    // precisely because it no longer is.
    case ACADEMICS_ROLLOVER = 'academics.rollover';
    case PRINCIPAL_APPROVAL_MANAGE = 'principal_approval.manage';
    // Interim gate for /api/v1/finance/* (was role:admin|super_admin).
    // Superseded when Finance's Ph2 permission scheme (finance.<resource>.
    // <action>, v10 §343) lands with the 4 Finance roles — I1/I6 coordination.
    case FINANCE_ACCESS = 'finance.access';
    // Record money IN (ADR 0048 D1): the two payment doors (invoice-scoped and account-scoped) shipped
    // under `finance.access` with NO ability of their own, so anyone who could view finance could take a
    // payment — and a fabricated payment discharges real receivables (ADR 0048 D2). This narrows both
    // doors to an explicit grant, held by `accounts_officer` only, so "takes the money in" separates from
    // "approves the write-off". NOT a maker-checker ability — its terminal segment is `record`, not
    // approve/reject, so ApprovalAbility derives no matching maker and the super-admin Gate::before bypass
    // still applies (super_admin stays on both payment routes).
    case FINANCE_PAYMENT_RECORD = 'finance.payment.record';
    // Direct where an ALREADY-RECORDED payment's unallocated remainder settles (U10). Distinct from
    // FINANCE_PAYMENT_RECORD above and deliberately NOT folded into it: `record` is the authority to
    // bring money in, `allocate` is the authority to say which receivables it discharges. They are the
    // same operator's job today — both are granted to `accounts_officer` and to nobody else, which is
    // ADR 0048 D1's reasoning about who takes money in, applied to who directs it — but they are two
    // acts, and a school that later wants the second on a different seat can move it without moving
    // the first. Splitting later is a permission rename with a convergence migration; splitting now is
    // one enum case.
    //
    // NOT a maker-checker ability. Its terminal segment is `allocate`, not approve/reject, so
    // ApprovalAbility derives no matching maker and the super-admin Gate::before bypass still applies.
    // Why an allocation is not maker-checker at all is argued in App\Finance\Actions\AllocatePayment.
    case FINANCE_PAYMENT_ALLOCATE = 'finance.payment.allocate';
    // Credit-note issuance is MAKER-CHECKER (Ph3): forgiving money takes two people.
    // `submit` is the maker side (propose, no money moves); `approve`/`reject` are the
    // checker side. The terminal `approve`/`reject` names are load-bearing — the Kernel's
    // ApprovalAbility convention derives the matching maker (`...submit`) and the
    // super-admin bypass-exclusion recognises the checker actions from those names alone
    // (ADR 0040/0044). One role must never hold both a maker and its matching checker
    // (SyncRolePermissionsRequest grant guard). Supersedes the C1 one-step
    // `finance.credit-note.issue`, which is retired.
    case FINANCE_CREDIT_NOTE_SUBMIT = 'finance.credit-note.submit';
    case FINANCE_CREDIT_NOTE_APPROVE = 'finance.credit-note.approve';
    case FINANCE_CREDIT_NOTE_REJECT = 'finance.credit-note.reject';
    // Invoice VOID is the SECOND maker-checker instance (Ph3b): reversing a whole charge takes
    // two people, same template as credit-note. `submit` proposes (no money moves, invoice stays
    // issued); `approve` voids the invoice + posts the reversal; `reject` leaves the charge
    // standing. The terminal `approve`/`reject` names drive the same Kernel conventions
    // (ApprovalAbility maker-derivation + super-admin bypass-exclusion) and the same
    // no-role-holds-both grant guard. Supersedes the one-step `finance.invoice.cancel`, retired.
    case FINANCE_INVOICE_VOID_REQUEST_SUBMIT = 'finance.invoice.void-request.submit';
    case FINANCE_INVOICE_VOID_REQUEST_APPROVE = 'finance.invoice.void-request.approve';
    case FINANCE_INVOICE_VOID_REQUEST_REJECT = 'finance.invoice.void-request.reject';
    // Raising an invoice at all (S1 Part 0). Until now the ONLY gate on invoice generation was the
    // group's `finance.access` — the same permission that lets someone look at the finance page —
    // so anyone who could view finance could bill. This narrows generation to an explicit grant.
    case FINANCE_INVOICE_GENERATE = 'finance.invoice.generate';
    // Applying ANY reduction (waiver/discount) line on an invoice (S1 Part 0). Checked in the
    // controller, not the route, because it depends on the request body. Closes the audit hole: a
    // 100%-discount line raised by anyone with `finance.access`, naming no one. Not a maker-checker
    // pair (no terminal approve/reject verb) — the second axis is enforced at the DB reduction guard.
    case FINANCE_INVOICE_REDUCTION_APPLY = 'finance.invoice.reduction.apply';
    // Author a fee schedule (S1 commit 2): create a draft and edit its items. In commit 2 this covers
    // the direct-publish path too; commit 4 narrows it to DRAFT authorship, with a separate
    // finance.fee-schedule.change.submit for proposing the draft for ED approval.
    case FINANCE_FEE_SCHEDULE_MANAGE = 'finance.fee-schedule.manage';
    // The school's bank accounts (S6/U3 commit 1): create, edit and DEACTIVATE the accounts money
    // lands in. Named on finance.fee-schedule.manage's pattern — a `manage` verb, not a maker-checker
    // triple — because it is finance CONFIGURATION and has no second signature: there is no
    // …bank-account.approve, and coining one would invent a checker for a description rather than a
    // decision.
    //
    // DELIBERATELY NOT GRANTED TO A GOVERNED ROLE. The forcing migration
    // 2026_08_06_100000_move_head_of_school_finance_to_executive_director makes head_of_school,
    // accounts_supervisor and executive_director's `finance.` slice EQUAL a frozen literal, so a
    // grant to any of those three is written by the seeder and revoked by that migration on the next
    // deploy — the trap ForcingMigrationsDoNotStripLaterGrantsTest exists for. admin and
    // accounts_officer are ungoverned, which is where fee-schedule.manage already sits.
    case FINANCE_BANK_ACCOUNT_MANAGE = 'finance.bank-account.manage';
    // Discount-policy governance (S1 commit 3, axis A): the Head proposes create/amend/retire; the ED
    // approves/rejects. Four-segment names so the terminal verb (submit/approve/reject) drives the
    // ApprovalAbility maker-derivation and the super-admin bypass exclusion by CONVENTION — nothing is
    // registered in a list. `…change.approve`'s maker is `…change.submit` for free.
    case FINANCE_DISCOUNT_POLICY_CHANGE_SUBMIT = 'finance.discount-policy.change.submit';
    case FINANCE_DISCOUNT_POLICY_CHANGE_APPROVE = 'finance.discount-policy.change.approve';
    case FINANCE_DISCOUNT_POLICY_CHANGE_REJECT = 'finance.discount-policy.change.reject';
    // Fee-schedule governance (S1 commit 4, the SECOND governance pair): the Head proposes publishing a
    // draft or retiring an active schedule; the ED approves/rejects. Same four-segment convention as the
    // discount pair — the terminal verb (submit/approve/reject) drives the ApprovalAbility maker-derivation
    // and super-admin bypass exclusion for free; nothing is registered in a list. Distinct from
    // finance.fee-schedule.manage (draft AUTHORSHIP), which commit 4 narrows to exactly that: a school may
    // let a bursar assemble the numbers and only the Head submit them (Part 4.3).
    case FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT = 'finance.fee-schedule.change.submit';
    case FINANCE_FEE_SCHEDULE_CHANGE_APPROVE = 'finance.fee-schedule.change.approve';
    case FINANCE_FEE_SCHEDULE_CHANGE_REJECT = 'finance.fee-schedule.change.reject';
    // Opening-balance cutover governance (§9 step 4c, the FIFTH maker-checker instance): the bursar
    // office submits a validated WCBS extract; the Head approves, and approval POSTS it into the
    // subledger in the same transaction. THE BATCH IS THE UNIT OF APPROVAL, not the row (spec §8).
    //
    // THE TERMINAL SEGMENT IS THE WHOLE MECHANISM, and three separate things read it rather than a
    // list: DutySeparation::pairs() derives (submit ↔ approve, submit ↔ reject) from these names;
    // ApprovalAbility::isExcludedFromSuperAdminBypass() takes the checker halves out of the
    // super_admin Gate::before bypass (ADR 0040); and bin/ci-boundary-lint.php's approval-seam-count
    // requires exactly one app/Finance/Actions/Submit*.php per finance `*_SUBMIT` case, which is why
    // this triple could not land ahead of SubmitOpeningBalanceBatch. A differently-shaped name would
    // silently opt out of all three.
    //
    // THREE SEGMENTS, NOT FOUR (`finance.opening-balance.submit`, not `…batch.submit`): the target
    // of the act is the batch and there is no second opening-balance noun to disambiguate from, so a
    // fourth segment would name nothing. spec §2's U12b note coins these three exact strings and
    // states the route follows the triple, not the other way round.
    case FINANCE_OPENING_BALANCE_SUBMIT = 'finance.opening-balance.submit';
    case FINANCE_OPENING_BALANCE_APPROVE = 'finance.opening-balance.approve';
    case FINANCE_OPENING_BALANCE_REJECT = 'finance.opening-balance.reject';
    // Putting ONE student on ONE already-approved discount policy — the write side of
    // `finance_student_discount_awards`, whose first request-borne caller is the BSS award import.
    //
    // WHY A NEW ABILITY AND NOT AN ADJACENT `finance.*` ONE. An award decides what a named family
    // pays, every term, until someone changes it. `finance.access` is the door onto the finance
    // pages; `finance.invoice.reduction.apply` is a ONE-OFF line on ONE invoice a bursar is looking
    // at. Neither is the authority to set a child's standing price, and borrowing either would mean
    // the seat that can read a statement, or discount a single bill, can also re-price a cohort.
    //
    // WHY `manage` AND NOT A MAKER-CHECKER TRIPLE, stated here so the next reader does not add the
    // chain. Brookstone's approval is on the VALUE — which percentages, off which part of the bill,
    // exist at all — and that is `finance.discount-policy.change.*`, already built, with the ED as
    // checker. This ability only says WHICH of those approved policies a student sits on, and the
    // catalog's single writer (ApproveDiscountPolicyChange) means there is nothing here to approve
    // that has not been approved already. A second chain would ask the ED to re-sign their own
    // decision once per child.
    //
    // THE TERMINAL SEGMENT IS LOAD-BEARING IN THE NEGATIVE. `manage` is deliberately not
    // submit/approve/reject: those names make DutySeparation::pairs() derive a checker, take the
    // ability out of the super_admin Gate::before bypass (ADR 0040), and — for a finance `*_SUBMIT`
    // — oblige bin/ci-boundary-lint.php's approval-seam-count to find a matching
    // app/Finance/Actions/Submit*.php. Naming this `…award.submit` would silently enlist all three
    // for a flow that has no second signature. Same shape, and the same reasoning, as
    // finance.bank-account.manage and finance.fee-schedule.manage above.
    //
    // GRANTED TO `accounts_officer` ONLY (obligation 3, checked rather than assumed): the bursar
    // office holds the BSS list and runs the import. That role is NOT governed by
    // 2026_08_06_100000_move_head_of_school_finance_to_executive_director, whose docblock names the
    // three roles it freezes, so this grant lands via rbac:sync AND survives the next deploy.
    case FINANCE_DISCOUNT_AWARD_MANAGE = 'finance.discount-award.manage';
    case ACADEMIC_DATA_VIEW = 'academic_data.view';
    case SCORE_MANAGE = 'score.manage';
    case STUDENT_STATUS_VIEW = 'student_status.view';
    case STUDENT_VIEW = 'student.view';
    case ASSESSMENT_RECORD = 'assessment.record';

    // RBAC administration (C5). Gates the school-admin Users module
    // (/setup/users) — listing a School's users and syncing their roles.
    // Granted to `admin`; super_admin reaches the module through the
    // Gate::before bypass and is deliberately NOT granted this explicitly
    // (that would break its exactly-15 authority-probe precondition).
    case RBAC_MANAGE_USERS = 'rbac.manage_users';
    // Platform-admin: start an impersonation session (ADR 0045 A1). Seeded to
    // no role until 0045-B2 seeds super_admin's explicit platform set — the
    // bypass covers super_admin meanwhile (inert), and the coverage test
    // carries it as a justified exception until B2.
    case RBAC_IMPERSONATE = 'rbac.impersonate';

    // Teacher assignment / assessments (legacy non-dotted names, preserved)
    case MANAGE_TEACHER_ASSIGNMENTS = 'manage_teacher_assignments';
    case MANAGE_FORM_TEACHER_COMMENTS = 'manage_form_teacher_comments';
    case MANAGE_HEAD_OF_SCHOOL_COMMENTS = 'manage_head_of_school_comments';
    case MANAGE_KEY_STAGE_COORDINATOR_COMMENTS = 'manage_key_stage_coordinator_comments';
    case VIEW_BEHAVIORAL_ASSESSMENTS = 'view_behavioral_assessments';
    case CREATE_BEHAVIORAL_ASSESSMENTS = 'create_behavioral_assessments';
    case EDIT_BEHAVIORAL_ASSESSMENTS = 'edit_behavioral_assessments';
    case VIEW_PSYCHOMOTOR_SKILLS = 'view_psychomotor_skills';
    case CREATE_PSYCHOMOTOR_SKILLS = 'create_psychomotor_skills';
    case EDIT_PSYCHOMOTOR_SKILLS = 'edit_psychomotor_skills';

    /**
     * The permissions whose effect is to CROSS the `school_id` isolation boundary.
     *
     * v10 §7.2 (docs/Finance Module — Implementation Master Plan - v10.md:375) requires this to be
     * "an explicit list, itself asserted": no segment rule can derive it — `view_cross_school` is
     * read-shaped like `view` and `export`, and the thing that makes it different is its EFFECT
     * (ActivityLogQueryService::baseQuery drops the school predicate entirely when the holder has
     * it), not its name. ADR 0036 makes isolation un-bypassable by role, so membership here means
     * "no business role may hold this, and the C6 matrix may not grant it at runtime".
     *
     * The one member today. `super_admin` is the ONE sanctioned holder (ADR 0045 A3, via
     * RbacSeeder::SUPER_ADMIN_PLATFORM) and is unreachable through the matrix
     * (SyncRolePermissionsRequest::authorize()), so this list needs no exemption mechanism of its own.
     *
     * Two consumers read it and neither hardcodes the string: the seeded-map pin
     * (tests/Feature/Rbac/GrantsMapSeparationTest.php) and the runtime matrix guard
     * (App\Http\Requests\SyncRolePermissionsRequest). Adding a member here arms both at once.
     *
     * `activity_log.view_system` is deliberately NOT a member: it widens a read to school-less
     * (system) rows within the holder's own context, it does not read another School's rows.
     *
     * @var list<string>
     */
    public const ISOLATION_CROSSING = [
        self::ACTIVITY_LOG_VIEW_CROSS_SCHOOL->value,
    ];

    /**
     * All permission string values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * The catalogue group this permission belongs to.
     *
     * Never falls back to a default: {@see PermissionGroup} lists membership explicitly and
     * PermissionGroupTest asserts the groups partition this enum exactly, so an unfiled case is a
     * failing test rather than a permission that quietly disappears from the RBAC console.
     */
    public function group(): PermissionGroup
    {
        return PermissionGroup::lookup()[$this->value];
    }

    /**
     * A human-readable name, for display and search only.
     *
     * DERIVED, not hand-written: `guardian.update_credentials` reads as "Guardian update
     * credentials". Ninety-odd hand-written labels would drift the moment someone adds a case
     * without touching them, and the permission NAME remains the identifier of record — this is a
     * reading aid beside it, never a replacement for it.
     */
    public function label(): string
    {
        return ucfirst(str_replace(['.', '_', '-'], ' ', $this->value));
    }
}
