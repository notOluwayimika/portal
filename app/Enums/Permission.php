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
