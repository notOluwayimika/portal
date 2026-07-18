-- ============================================================================
-- Production read-only query set — S7 access divergence (A) + CASCADE damage (B)
-- ============================================================================
-- READ-ONLY. Run against LIVE production by the environment owner. No writes,
-- no DDL. Validated on the local dev DB (brookstone_portal_db) 2026-07-18:
-- every filter matched real rows and each result was partition-proven, so a
-- zero means zero, never "the filter missed."
--
-- CRITICAL LITERAL: model_has_roles.model_type / activity_log.subject_type store
-- 'App\Models\User' etc. with a SINGLE backslash. In a MySQL string literal a
-- backslash is an escape char, so it MUST be written DOUBLED ('App\\Models\\User')
-- — a single backslash silently drops it and the filter matches nothing. All
-- literals below are already doubled. (If your client runs with
-- NO_BACKSLASH_ESCAPES, use a single backslash instead.)
--
-- super_admin is excluded everywhere in group A: it is team-less by design
-- (model_has_roles.school_id IS NULL) and holds access through Gate::before, not
-- a per-School role, so it is never a divergence. It has TWO role ids in this
-- data (5 and 9), so the exclusion joins on roles.name, never a role id.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- A1 — school_user pivot access NOT mirrored in model_has_roles (join school_id)
-- Zero      = every school_user grant has a matching role for that School; the
--             pivot can be dropped with no access loss.
-- Non-zero  = these (School, user) grants exist ONLY in school_user; the listed
--             users lose that School's access at the drop = STOP + backfill.
-- ---------------------------------------------------------------------------
SELECT su.school_id, COUNT(*) AS unmirrored_grants
FROM school_user su
WHERE NOT EXISTS (
        SELECT 1 FROM model_has_roles mhr
        WHERE mhr.model_type = 'App\\Models\\User'
          AND mhr.model_id   = su.user_id
          AND mhr.school_id   = su.school_id
      )
  AND NOT EXISTS (                                   -- exclude team-less super_admin
        SELECT 1 FROM model_has_roles sa
        JOIN roles r ON r.id = sa.role_id
        WHERE sa.model_type = 'App\\Models\\User'
          AND sa.model_id   = su.user_id
          AND r.name        = 'super_admin'
      )
GROUP BY su.school_id
ORDER BY unmirrored_grants DESC;


-- ---------------------------------------------------------------------------
-- A2 — users.school_id set with no matching role for that School
-- (deleted_at IS NULL: a soft-deleted user cannot "lose" access, so it is
--  excluded to avoid false positives — remove that line to count them too.)
-- Zero      = every live user's home School is mirrored by a role there.
-- Non-zero  = these users' School access lives only in users.school_id and
--             disappears at the column drop = STOP + backfill decision.
-- ---------------------------------------------------------------------------
SELECT u.school_id, COUNT(*) AS unmirrored_users
FROM users u
WHERE u.school_id IS NOT NULL
  AND u.deleted_at IS NULL
  AND NOT EXISTS (
        SELECT 1 FROM model_has_roles mhr
        WHERE mhr.model_type = 'App\\Models\\User'
          AND mhr.model_id   = u.id
          AND mhr.school_id   = u.school_id
      )
  AND NOT EXISTS (
        SELECT 1 FROM model_has_roles sa
        JOIN roles r ON r.id = sa.role_id
        WHERE sa.model_type = 'App\\Models\\User'
          AND sa.model_id   = u.id
          AND r.name        = 'super_admin'
      )
GROUP BY u.school_id
ORDER BY unmirrored_users DESC;


-- ---------------------------------------------------------------------------
-- A3 — guardians whose user_id lacks a role for the guardian record's School
-- (guardians is per-School: school_id = which School, user_id = access holder.
--  Excludes soft-deleted guardians and any guardian with no user account.)
-- Zero      = every live guardian's portal access is mirrored by a role there.
-- Non-zero  = these guardians' School access exists only via the guardians row
--             and is lost at the drop = STOP + backfill (guardian re-grant).
-- ---------------------------------------------------------------------------
SELECT g.school_id, COUNT(*) AS unmirrored_guardians
FROM guardians g
WHERE g.deleted_at IS NULL
  AND g.user_id IS NOT NULL
  AND NOT EXISTS (
        SELECT 1 FROM model_has_roles mhr
        WHERE mhr.model_type = 'App\\Models\\User'
          AND mhr.model_id   = g.user_id
          AND mhr.school_id   = g.school_id
      )
  AND NOT EXISTS (
        SELECT 1 FROM model_has_roles sa
        JOIN roles r ON r.id = sa.role_id
        WHERE sa.model_type = 'App\\Models\\User'
          AND sa.model_id   = g.user_id
          AND r.name        = 'super_admin'
      )
GROUP BY g.school_id
ORDER BY unmirrored_guardians DESC;


-- ---------------------------------------------------------------------------
-- B1 — enrollment (StudentCurriculum) delete events, + audit-coverage window
-- The count is meaningful ONLY within the coverage window: a 0 means "zero
-- enrollment deletions since audit logging began (coverage_start)", NOT "never."
-- Deletions before coverage_start leave no surviving evidence.
-- Zero      = no withdraw-delete CASCADE fired within audit coverage.
-- Non-zero  = that many enrollments were deleted; each MAY have cascaded
--             behavioural/psychomotor rows away (see B2). Disclosure figure.
-- ---------------------------------------------------------------------------
SELECT COUNT(*) AS deleted_enrollment_events
FROM activity_log
WHERE event = 'deleted'
  AND subject_type = 'App\\Models\\StudentCurriculum';

SELECT MIN(created_at) AS coverage_start,
       MAX(created_at) AS coverage_end,
       COUNT(*)        AS total_audit_rows
FROM activity_log;


-- ---------------------------------------------------------------------------
-- B2 — orphaned assessment rows (child whose student_curricula parent is gone)
-- The FKs are ON DELETE CASCADE, so a parent delete REMOVES children rather than
-- orphaning them — orphans should therefore be impossible under normal integrity.
-- Zero      = referential integrity intact (expected).
-- Non-zero  = children survived a vanished parent => the FK was bypassed/disabled
--             at some point => investigate (and it bounds recoverable rows).
-- ---------------------------------------------------------------------------
SELECT 'behavioral_assessments' AS table_name, COUNT(*) AS orphaned_rows
FROM behavioral_assessments ba
WHERE NOT EXISTS (SELECT 1 FROM student_curricula sc WHERE sc.id = ba.student_curriculum_id)
UNION ALL
SELECT 'psychomotor_skills', COUNT(*)
FROM psychomotor_skills ps
WHERE NOT EXISTS (SELECT 1 FROM student_curricula sc WHERE sc.id = ps.student_curriculum_id);


-- ---------------------------------------------------------------------------
-- B3 — current withdrawn enrollments, and how many carry assessment history
-- Context for "what the soft-end now protects": these rows survive under the new
-- soft-end path; under the old delete path their assessments would have cascaded.
-- Zero withdrawn      = none in this state yet (e.g. pre-adoption); a real zero.
-- Non-zero + with_assessments>0 = that many withdrawn students retain assessment
--             history that the old delete would have destroyed.
-- ---------------------------------------------------------------------------
SELECT
    COUNT(*) AS withdrawn_enrollments,
    COALESCE(SUM(
        CASE WHEN EXISTS (SELECT 1 FROM behavioral_assessments b WHERE b.student_curriculum_id = sc.id)
               OR EXISTS (SELECT 1 FROM psychomotor_skills   p WHERE p.student_curriculum_id = sc.id)
             THEN 1 ELSE 0 END
    ), 0) AS withdrawn_with_assessments
FROM student_curricula sc
WHERE sc.status = 'withdrawn';
