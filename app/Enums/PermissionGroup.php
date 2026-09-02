<?php

namespace App\Enums;

/**
 * The taxonomy the RBAC console groups permissions by.
 *
 * WHY THIS EXISTS RATHER THAN PREFIX PARSING. The obvious move — bucket a permission by the segment
 * before its first dot — is what the old matrix did, and it is unusable: 18 route-access gates and
 * the 9 legacy non-dotted names have no shared prefix, so they collapse into one 27-item "general"
 * bucket, while `finance.credit-note.submit` (three segments, where nearly everything else is two)
 * files under a prefix that tells you nothing about it being a maker action. The real taxonomy has
 * always existed as SECTION COMMENTS in {@see Permission}; this enum makes them executable.
 *
 * COINING A PERMISSION? FILING IT HERE IS OBLIGATION 2 OF 3 — the full checklist is in
 * {@see Permission}'s header, which is the file you open first. If you arrived here from
 * "Undefined array key" in Permission::group(), that error IS the design working: add the case to
 * the right group below rather than giving lookup() a fallback, because a fallback would turn this
 * red build into a permission that exists in code and can never be granted from the console.
 *
 * MEMBERSHIP IS EXPLICIT, NOT DERIVED. Each group lists its permissions. A `match` over 74 cases
 * would need a `default` arm, and a default arm silently swallows every new case — the permission
 * would simply vanish from the console with nothing going red. Instead PermissionGroupTest asserts
 * the groups PARTITION Permission::cases() exactly: add a case without filing it and the test
 * fails, naming it.
 *
 * ROUTE_ACCESS is the one group defined by role rather than by name shape. Those 18 are the C2
 * per-surface entry gates, and `result.view` belongs there rather than with the result lifecycle
 * because that is what it is — a route gate, not a step in the submit/approve flow. Splitting the
 * taxonomy along a second axis (domain × tier) would be cleaner but forces every C2 gate to be
 * re-filed into a domain; deliberately deferred.
 */
enum PermissionGroup: string
{
    case ACTIVITY_LOG = 'activity_log';
    case GUARDIANS = 'guardians';
    case STUDENT_RECORDS = 'student_records';
    case RESULT_LIFECYCLE = 'result_lifecycle';
    case ENROLLMENT_LIFECYCLE = 'enrollment_lifecycle';
    case ROUTE_ACCESS = 'route_access';
    case FINANCE = 'finance';
    case RBAC_ADMIN = 'rbac_admin';
    case TEACHER_ASSESSMENTS = 'teacher_assessments';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVITY_LOG => 'Activity log',
            self::GUARDIANS => 'Guardians',
            self::STUDENT_RECORDS => 'Student records',
            self::RESULT_LIFECYCLE => 'Result lifecycle',
            self::ENROLLMENT_LIFECYCLE => 'Enrollment lifecycle',
            self::ROUTE_ACCESS => 'Route access',
            self::FINANCE => 'Finance',
            self::RBAC_ADMIN => 'RBAC administration',
            self::TEACHER_ASSESSMENTS => 'Teaching & assessments',
        };
    }

    /** One line, shown under the group name in the catalogue. */
    public function description(): string
    {
        return match ($this) {
            self::ACTIVITY_LOG => 'Who may read the audit trail, and how much of it.',
            self::GUARDIANS => 'Managing guardian records and their portal logins.',
            self::STUDENT_RECORDS => 'A student\'s subject list and its history.',
            self::RESULT_LIFECYCLE => 'Submitting and approving results — a maker-checker flow.',
            self::ENROLLMENT_LIFECYCLE => 'Registering, promoting and changing a student\'s status.',
            self::ROUTE_ACCESS => 'Coarse entry gates: which surfaces a role can open at all.',
            self::FINANCE => 'Billing, credit notes and void requests — money leaves a trail.',
            self::RBAC_ADMIN => 'Administering roles and impersonation. The keys to the building.',
            self::TEACHER_ASSESSMENTS => 'Teacher assignment, comments and pastoral assessments.',
        };
    }

    /** A lucide icon NAME; the name-to-component map lives in the frontend. */
    public function icon(): string
    {
        return match ($this) {
            self::ACTIVITY_LOG => 'ScrollText',
            self::GUARDIANS => 'Users',
            self::STUDENT_RECORDS => 'BookOpen',
            self::RESULT_LIFECYCLE => 'ClipboardCheck',
            self::ENROLLMENT_LIFECYCLE => 'UserPlus',
            self::ROUTE_ACCESS => 'DoorOpen',
            self::FINANCE => 'Wallet',
            self::RBAC_ADMIN => 'ShieldAlert',
            self::TEACHER_ASSESSMENTS => 'GraduationCap',
        };
    }

    /**
     * The permissions in this group. Together the groups partition Permission::cases() exactly —
     * asserted by PermissionGroupTest, which is what keeps this honest as the catalog grows.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::ACTIVITY_LOG => [
                Permission::ACTIVITY_LOG_VIEW,
                Permission::ACTIVITY_LOG_VIEW_ALL,
                Permission::ACTIVITY_LOG_VIEW_OWN,
                Permission::ACTIVITY_LOG_VIEW_SYSTEM,
                Permission::ACTIVITY_LOG_VIEW_CROSS_SCHOOL,
                Permission::ACTIVITY_LOG_EXPORT,
                Permission::ACTIVITY_LOG_VIEW_SENSITIVE,
            ],
            self::GUARDIANS => [
                Permission::GUARDIAN_VIEW,
                Permission::GUARDIAN_UPDATE,
                Permission::GUARDIAN_UPDATE_CREDENTIALS,
                Permission::GUARDIAN_DETACH,
                Permission::GUARDIAN_ENABLE_LOGIN,
                Permission::GUARDIAN_CREATE,
                Permission::GUARDIAN_EXPORT,
                Permission::GUARDIAN_MESSAGE,
                Permission::GUARDIAN_VIEW_AUDIT,
                Permission::GUARDIAN_IMPORT,
            ],
            self::STUDENT_RECORDS => [
                Permission::STUDENT_SUBJECT_VIEW,
                Permission::STUDENT_SUBJECT_ADD_OPTIONAL,
                Permission::STUDENT_SUBJECT_DROP_OPTIONAL,
                Permission::STUDENT_SUBJECT_RESTORE,
                Permission::STUDENT_SUBJECT_VIEW_HISTORY,
                Permission::STUDENT_CURRICULUM_UNENROLL,
                Permission::CURRICULUM_SUBJECT_ARCHIVE,
                Permission::CURRICULUM_SUBJECT_RESTORE,
            ],
            self::RESULT_LIFECYCLE => [
                Permission::RESULT_SUBMIT,
                Permission::RESULT_APPROVE,
                Permission::RESULT_REJECT,
                Permission::RESULT_VIEW_SCORES,
            ],
            self::ENROLLMENT_LIFECYCLE => [
                Permission::STUDENT_CURRICULUM_REGISTER,
                Permission::STUDENT_CURRICULUM_PROMOTE,
                // Bulk promotion across a year boundary. Filed beside PROMOTE rather than
                // with academic_setup.manage in ROUTE_ACCESS: what it MOVES is enrolments,
                // which is what this group is the taxonomy for.
                Permission::ACADEMICS_ROLLOVER,
                Permission::STUDENT_CURRICULUM_UPDATE_STATUS,
            ],
            self::ROUTE_ACCESS => [
                Permission::ADMIN_AREA_ACCESS,
                Permission::STUDENT_DIRECTORY_VIEW,
                Permission::RESULT_REVIEW_ACCESS,
                Permission::REPORT_VIEW,
                Permission::CURRICULUM_SUBJECT_VIEW,
                Permission::STUDENT_CURRICULUM_VIEW,
                Permission::DASHBOARD_VIEW,
                Permission::RESULT_SIGNATURE_MANAGE,
                Permission::RESULT_VIEW,
                Permission::PARENT_PORTAL_ACCESS,
                Permission::BOARDING_PORTAL_ACCESS,
                Permission::ACADEMIC_SETUP_MANAGE,
                Permission::PRINCIPAL_APPROVAL_MANAGE,
                Permission::ACADEMIC_DATA_VIEW,
                Permission::SCORE_MANAGE,
                Permission::STUDENT_STATUS_VIEW,
                Permission::STUDENT_VIEW,
                Permission::ASSESSMENT_RECORD,
            ],
            self::FINANCE => [
                Permission::FINANCE_ACCESS,
                Permission::FINANCE_PAYMENT_RECORD,
                Permission::FINANCE_PAYMENT_ALLOCATE,
                Permission::FINANCE_CREDIT_NOTE_SUBMIT,
                Permission::FINANCE_CREDIT_NOTE_APPROVE,
                Permission::FINANCE_CREDIT_NOTE_REJECT,
                Permission::FINANCE_INVOICE_VOID_REQUEST_SUBMIT,
                Permission::FINANCE_INVOICE_VOID_REQUEST_APPROVE,
                Permission::FINANCE_INVOICE_VOID_REQUEST_REJECT,
                Permission::FINANCE_INVOICE_GENERATE,
                Permission::FINANCE_INVOICE_APPROVE,
                Permission::FINANCE_INVOICE_REDUCTION_APPLY,
                Permission::FINANCE_FEE_SCHEDULE_MANAGE,
                Permission::FINANCE_BANK_ACCOUNT_MANAGE,
                Permission::FINANCE_DISCOUNT_POLICY_CHANGE_SUBMIT,
                Permission::FINANCE_DISCOUNT_POLICY_CHANGE_APPROVE,
                Permission::FINANCE_DISCOUNT_POLICY_CHANGE_REJECT,
                Permission::FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT,
                Permission::FINANCE_FEE_SCHEDULE_CHANGE_APPROVE,
                Permission::FINANCE_FEE_SCHEDULE_CHANGE_REJECT,
                Permission::FINANCE_OPENING_BALANCE_SUBMIT,
                Permission::FINANCE_OPENING_BALANCE_APPROVE,
                Permission::FINANCE_OPENING_BALANCE_REJECT,
                Permission::FINANCE_DISCOUNT_AWARD_MANAGE,
            ],
            self::RBAC_ADMIN => [
                Permission::RBAC_MANAGE_USERS,
                Permission::RBAC_IMPERSONATE,
            ],
            self::TEACHER_ASSESSMENTS => [
                Permission::MANAGE_TEACHER_ASSIGNMENTS,
                Permission::MANAGE_FORM_TEACHER_COMMENTS,
                Permission::MANAGE_HEAD_OF_SCHOOL_COMMENTS,
                Permission::MANAGE_KEY_STAGE_COORDINATOR_COMMENTS,
                Permission::VIEW_BEHAVIORAL_ASSESSMENTS,
                Permission::CREATE_BEHAVIORAL_ASSESSMENTS,
                Permission::EDIT_BEHAVIORAL_ASSESSMENTS,
                Permission::VIEW_PSYCHOMOTOR_SKILLS,
                Permission::CREATE_PSYCHOMOTOR_SKILLS,
                Permission::EDIT_PSYCHOMOTOR_SKILLS,
            ],
        };
    }

    /**
     * Permission value => group, built once per request.
     *
     * @return array<string, self>
     */
    public static function lookup(): array
    {
        static $map = null;

        if ($map === null) {
            $map = [];

            foreach (self::cases() as $group) {
                foreach ($group->permissions() as $permission) {
                    $map[$permission->value] = $group;
                }
            }
        }

        return $map;
    }
}
