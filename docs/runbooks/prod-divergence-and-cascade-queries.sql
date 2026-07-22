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


-- ============================================================================
-- C — slice (i) PRE-FLIGHT: episodes that will FAIL the new composite FK
-- ============================================================================
-- READ-ONLY. Run against LIVE production by the environment owner BEFORE the
-- slice (i) migration (2026_07_19_130000_add_school_id_to_student_curricula).
--
-- WHY THIS IS THE INTEGRITY TEST, NOT A RE-CONFIRMATION.
-- The migration backfills student_curricula.school_id FROM students.school_id,
-- then adds two composite FKs. Only ONE of them can fail on real data:
--
--   (student_id,    school_id) -> students  (id, school_id)   CANNOT fail:
--       tautological — school_id was copied from that very student. (A NULL
--       students.school_id is caught first by the migration's own guard.)
--   (curriculum_id, school_id) -> curricula (id, school_id)   CAN fail:
--       fails for EVERY episode where students.school_id <> curricula.school_id.
--   finance_invoices (student_curriculum_id, school_id)       CANNOT fail at the
--       first Phase-1 deploy: finance_invoices is CREATED EMPTY in that same
--       deploy, so it has no rows to validate. It becomes live on any re-run or
--       in an environment that already holds Finance data — see C2.
--
-- So the whole slice-(i) deploy risk is C1. And these rows are NOT hypothetical:
-- slice (i) exists precisely because cross-School enrollment paths were live and
-- unguarded (StudentService::update's dead guard; the unscoped
-- exists:curricula,id in StudentRequest/ImportStudentRequest). Those paths
-- produce exactly "local student + FOREIGN curriculum". Finding zero would be the
-- surprise; finding some is the expected case.
--
-- VALIDATION CAVEAT — read this before trusting a zero.
-- Unlike groups A and B, this filter could NOT be partition-proven against real
-- matching rows: the dev DB (brookstone_portal_db, 2026-07-19) holds exactly ONE
-- school in both `students` and `curricula`, so a mismatch is structurally
-- impossible there. What WAS proven on dev is the mechanics: agree(977) +
-- disagree(0) = total(977), and 0 episodes fail to join either parent. The join
-- and the comparison are therefore correct and nothing is silently dropped — but
-- the disagree branch has never matched a real row. **On prod, treat a non-zero
-- result as the expected outcome and a zero as pleasant news, not as
-- confirmation that the query works.**
-- ----------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
-- C1 — THE BLOCKING QUERY. Episodes whose student's School disagrees with their
--      curriculum's School (or whose parent School is NULL). Each listed row
--      WILL fail the curriculum composite FK and abort the migration.
-- Zero      = the migration can run.
-- Non-zero  = STOP. Remediate to zero first (docs/runbooks/slice-i-preflight-and-
--             remediation.md). Ending the episode does NOT fix it — the FK
--             constrains curriculum_id regardless of status.
-- ---------------------------------------------------------------------------
SELECT sc.id            AS episode_id,
       sc.uuid          AS episode_uuid,
       sc.status,
       sc.ended_at,
       sc.student_id,
       s.school_id      AS student_school_id,
       s.admission_number,
       sc.curriculum_id,
       c.school_id      AS curriculum_school_id,
       (SELECT COUNT(*) FROM student_subjects ss
         WHERE ss.student_curriculum_id = sc.id)        AS subject_rows,
       (SELECT COUNT(*) FROM behavioral_assessments ba
         WHERE ba.student_curriculum_id = sc.id)        AS behavioural_rows,
       (SELECT COUNT(*) FROM psychomotor_skills ps
         WHERE ps.student_curriculum_id = sc.id)        AS psychomotor_rows
FROM student_curricula sc
JOIN students  s ON s.id = sc.student_id
JOIN curricula c ON c.id = sc.curriculum_id
WHERE s.school_id <> c.school_id
   OR s.school_id IS NULL
   OR c.school_id IS NULL
ORDER BY sc.id;


-- ---------------------------------------------------------------------------
-- C1b — partition proof for C1. Run it in the SAME session: agree + disagree
--       MUST equal total, and orphans MUST be 0. If they don't, C1's joins are
--       dropping rows and its zero means nothing.
-- ---------------------------------------------------------------------------
SELECT
  (SELECT COUNT(*) FROM student_curricula sc
     JOIN students s ON s.id = sc.student_id
     JOIN curricula c ON c.id = sc.curriculum_id
    WHERE s.school_id = c.school_id)                                AS agree,
  (SELECT COUNT(*) FROM student_curricula sc
     JOIN students s ON s.id = sc.student_id
     JOIN curricula c ON c.id = sc.curriculum_id
    WHERE s.school_id <> c.school_id
       OR s.school_id IS NULL OR c.school_id IS NULL)               AS disagree,
  (SELECT COUNT(*) FROM student_curricula sc
     JOIN students s ON s.id = sc.student_id
     JOIN curricula c ON c.id = sc.curriculum_id)                   AS joined_total,
  (SELECT COUNT(*) FROM student_curricula)                          AS episodes_total;


-- ---------------------------------------------------------------------------
-- C2 — invoice linkage for any C1 offender. RUN ONLY where finance_invoices
--      already exists (a re-run, or a staging env that already holds Finance
--      data). At the first Phase-1 deploy this table is created empty in the
--      same deploy, so this query is not applicable and C1 is sufficient.
-- Any row here = remediation crosses the Finance<->Academic seam and touches
--      append-only rows: it is NOT a SQL fix. See the remediation runbook.
-- ---------------------------------------------------------------------------
SELECT sc.id  AS episode_id,
       fi.id  AS invoice_id,
       fi.uuid AS invoice_uuid,
       fi.status AS invoice_status,
       fi.school_id AS invoice_school_id,
       s.school_id  AS student_school_id,
       c.school_id  AS curriculum_school_id,
       fi.total_minor, fi.total_currency
FROM student_curricula sc
JOIN students  s ON s.id = sc.student_id
JOIN curricula c ON c.id = sc.curriculum_id
JOIN finance_invoices fi ON fi.student_curriculum_id = sc.id
WHERE s.school_id <> c.school_id
   OR s.school_id IS NULL
   OR c.school_id IS NULL
ORDER BY sc.id, fi.id;


-- ---------------------------------------------------------------------------
-- D1 — OVER-ALLOCATION pre-flight (for the over-allocation guard slice).
--
--      The BEFORE INSERT trigger finance_allocation_not_over_invoice_total rejects
--      FUTURE over-allocations; it does NOT retroactively fix rows already written
--      before it existed. An already-over-allocated invoice would silently poison
--      reconciliation later, so run this BEFORE the trigger migration lands.
--
-- Zero      = safe; every invoice's allocations already sum to ≤ its total.
-- Non-zero  = STOP. Each listed invoice has Σ(allocations) > total_minor and needs a
--             reversal decision (a reversing ledger entry / allocation correction),
--             NOT a trigger that ignores it. Surface these; do not deploy over them.
--
-- Expected on first deploy: ZERO. The payment route existed but was never driven by a
-- UI, and the overpayment bite-test that produced a −1 balance was test-DB only.
-- ---------------------------------------------------------------------------
SELECT i.id                                   AS invoice_id,
       i.number                               AS invoice_number,
       i.school_id,
       i.total_minor,
       SUM(a.amount_minor)                     AS allocated_minor,
       SUM(a.amount_minor) - i.total_minor      AS over_by_minor
  FROM finance_invoices i
  JOIN finance_payment_allocations a ON a.invoice_id = i.id
 GROUP BY i.id, i.number, i.school_id, i.total_minor
HAVING SUM(a.amount_minor) > i.total_minor;
