<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * At most ONE live Guardian row per (user ACCOUNT, school) — as a database fact.
 *
 * `guardians` shipped with non-unique indexes on `user_id` and `school_id` and no unique key
 * beyond `uuid` (2026_05_13_132246_create_guardians_table.php), so nothing at the schema level
 * stopped one ACCOUNT holding two live guardian rows in one school.
 * GuardianService::forUserInActiveSchool documents that gap and works around it with
 * `orderBy('id')`; resolveOrCreateGuardianForUserInSchool guards it in application code. Both are
 * conventions — they hold only for callers that go through them. This is the layer that survives
 * a future writer that reads none of that code.
 *
 * READ THIS BEFORE CITING THIS MIGRATION AS CLOSING THE DUPLICATE-PARENT INCIDENT. IT DOES NOT.
 *
 * The key is (user_id, school_id), so it constrains one guardian row per ACCOUNT per school. It
 * cannot see, and will never prevent, the shape actually reported — one PERSON holding several
 * accounts, appearing as several guardians. Derived on the production copy before writing this,
 * and the numbers say so plainly: 776 live guardian rows over 776 distinct `user_id` values, 0
 * accounts holding more than one live guardian in any school, and 0 soft-deleted rows. Every live
 * guardian already has its own account. Yet 14 groups of live rows in school#1 share a phone
 * number, one of which also shares a name — near-duplicates this index is blind to, because each
 * row hangs off a different `user_id`.
 *
 * So the zero this migration was verified against is NOT evidence that the incident is fixed. It
 * is evidence that the incident was never of this shape. What addresses the reported shape is the
 * interactive-form dedupe on `fix/guardian-create-duplicates` — match on email, then on normalised
 * phone, before creating anyone — and the merge command for consolidating what already exists.
 * This migration is the third layer: it makes the (account, school) class unrepeatable and leaves
 * the person-level class to those two. The residual is recorded, not assumed closed, in
 * docs/handoff/tickets/guardian-duplicates-are-user-level-not-row-level.md.
 *
 * DEPLOY ORDER — THIS MIGRATION MUST NOT REACH AN ENVIRONMENT WHOSE
 * GuardianService::createGuardianWithUser STILL RESOLVES A NULL EMAIL THROUGH
 * `User::where('email', $userEmail)->first()`.
 *
 * `fix/guardian-create-duplicates` merges to staging BEFORE this branch; it carries the guard
 * (`$user = $userEmail ? User::where('email', $userEmail)->first() : null;`). The guard is
 * deliberately NOT duplicated here — two copies of one fix collide at merge.
 *
 * If the order is violated, the symptom is this. A guardian with no email is legal (email is
 * required only when `can_login` is true), and `Builder::where()` turns a null value into
 * `whereNull`, so the lookup returns the first account in the system with a NULL email — a
 * stranger. Before this index that silently bound the new guardian to the wrong account. After
 * it, the second email-less guardian in a school trips `guardians_live_identity_unique` and MySQL
 * refuses with 1062, which bootstrap/app.php renders as a bare 409 "Duplicate entry detected."
 * Through StudentController::store that refusal happens inside the student's DB::transaction, so
 * THE WHOLE STUDENT REGISTRATION ROLLS BACK and the operator is told only "duplicate entry" about
 * a duplicate they cannot see. `users` with a NULL email numbered 0 on the production copy when
 * this was written, so the trap is prospective — it springs on the first email-less guardian.
 *
 * That ordering is a rule with no mechanism, i.e. a wish. The mechanism is specified but not built
 * in docs/handoff/tickets/null-email-guardian-lookup-has-no-deploy-order-gate.md.
 *
 * PARTIAL UNIQUENESS VIA THE GENERATED-COLUMN IDIOM — the established pattern here
 * (2026_07_28_120001_add_current_session_uniqueness.php, which took it from
 * finance_fee_schedules, which took it from finance_void_requests). MySQL has no partial
 * indexes, but it EXEMPTS a row from a unique index when any indexed column is NULL. So a
 * column that is `user_id:school_id` while the row is live and NULL once it is soft-deleted,
 * plus a UNIQUE index on it, gives exactly "at most one LIVE guardian per (user, school)":
 *
 *   - soft-deleted rows evaluate to NULL and are exempt, so a guardian can be soft-deleted and
 *     re-created;
 *   - any number of soft-deleted rows may coexist for the same pair;
 *   - the same user in a DIFFERENT school is a different key, so the multi-school parent
 *     (§6.2, one Guardian record per School) keeps working.
 *
 * Deliberately NOT `UNIQUE(user_id, school_id, deleted_at)`: MySQL treats NULLs as distinct in
 * a unique index, so that index would permit unlimited live rows and enforce nothing.
 *
 * VIRTUAL, NOT STORED — forced, not preferred. Adding a STORED generated column rebuilds the
 * table, and `guardians` carries three outbound foreign keys (school_id, user_id, photo_id)
 * plus an inbound one from `guardian_student`; MySQL 9.7.1 aborts the rebuild with error 1215
 * ("Cannot add foreign key constraint"). Confirmed by trying it against the live schema before
 * writing this — STORED fails, VIRTUAL succeeds, and the same deviation is recorded on
 * academic_sessions for the same reason. A unique index on a VIRTUAL generated column is
 * materialised in the index and the NULL exemption behaves identically, so nothing about the
 * guarantee changes.
 *
 * VARCHAR(64) is comfortably wide: both operands are BIGINT UNSIGNED after the hybrid-id
 * conversion (2026_04_29_000000), so the widest possible value is 20 + 1 + 20 = 41 characters.
 * The separator matters — without it, (1, 23) and (12, 3) would collide.
 *
 * VERIFIED BEFORE WRITING, and read for what it means: the `HAVING COUNT(*) > 1` group query over
 * live rows returns the empty set on the production copy (776 guardian rows, 0 offending groups),
 * so the index applies to existing data unchanged and no data migration is needed. Had it not,
 * this migration would fail at deploy rather than silently pick a winner — the correct failure.
 * That empty set is a DEPLOYABILITY fact only; see the paragraph above for why it is not also
 * evidence that duplicate parents are solved.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE guardians
                ADD COLUMN live_identity VARCHAR(64)
                    GENERATED ALWAYS AS (
                        IF(deleted_at IS NULL, CONCAT(user_id, ':', school_id), NULL)
                    ) VIRTUAL"
        );

        DB::statement(
            'ALTER TABLE guardians
                ADD UNIQUE guardians_live_identity_unique (live_identity)'
        );
    }

    public function down(): void
    {
        // Index first: MySQL refuses to drop a column an index still references.
        DB::statement('ALTER TABLE guardians DROP INDEX guardians_live_identity_unique');
        DB::statement('ALTER TABLE guardians DROP COLUMN live_identity');
    }
};
