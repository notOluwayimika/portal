<?php

namespace App\Enums;

/**
 * The canonical, magic-string-free registry of application permissions.
 *
 * Values are the exact permission names stored in the `permissions` table and
 * checked via $user->can(...). The three `manage_*` / assessment cases keep
 * their legacy non-dotted names deliberately — renaming them to the dotted
 * convention would change the stored value and break existing checks, so that
 * is a separate migration, not part of introducing this enum.
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
    case PRINCIPAL_APPROVAL_MANAGE = 'principal_approval.manage';
    // Interim gate for /api/v1/finance/* (was role:admin|super_admin).
    // Superseded when Finance's Ph2 permission scheme (finance.<resource>.
    // <action>, v10 §343) lands with the 4 Finance roles — I1/I6 coordination.
    case FINANCE_ACCESS = 'finance.access';
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
    // Discount-policy governance (S1 commit 3, axis A): the Head proposes create/amend/retire; the ED
    // approves/rejects. Four-segment names so the terminal verb (submit/approve/reject) drives the
    // ApprovalAbility maker-derivation and the super-admin bypass exclusion by CONVENTION — nothing is
    // registered in a list. `…change.approve`'s maker is `…change.submit` for free.
    case FINANCE_DISCOUNT_POLICY_CHANGE_SUBMIT = 'finance.discount-policy.change.submit';
    case FINANCE_DISCOUNT_POLICY_CHANGE_APPROVE = 'finance.discount-policy.change.approve';
    case FINANCE_DISCOUNT_POLICY_CHANGE_REJECT = 'finance.discount-policy.change.reject';
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
    case VIEW_BEHAVIORAL_ASSESSMENTS = 'view_behavioral_assessments';
    case CREATE_BEHAVIORAL_ASSESSMENTS = 'create_behavioral_assessments';
    case EDIT_BEHAVIORAL_ASSESSMENTS = 'edit_behavioral_assessments';
    case VIEW_PSYCHOMOTOR_SKILLS = 'view_psychomotor_skills';
    case CREATE_PSYCHOMOTOR_SKILLS = 'create_psychomotor_skills';
    case EDIT_PSYCHOMOTOR_SKILLS = 'edit_psychomotor_skills';

    /**
     * All permission string values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
