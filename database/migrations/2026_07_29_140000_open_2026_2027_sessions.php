<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Item 9 (term-calendar hardening, PR #142 follow-up) — open the 2026/2027
 * session for both schools with their planned calendars, and make it current.
 *
 * WHY THIS SHAPE, NOT AN IN-PLACE CORRECTION. The follow-up brief assumed the
 * supplied dates were a correction to the CURRENT session's terms. They are
 * not. Both schools' only session is `2025/2026`, whose last term ended
 * 2026-06-27 (school 1) / 2026-07-31 (school 2) — a finished year — while the
 * dates the school supplied (2026-09-05 → 2027-06-26) are the NEXT academic
 * year. Writing them onto the 2025/2026 rows would have left a session named
 * `2025/2026` holding 2026/2027 windows, with `completed`/`active` statuses
 * that no longer describe anything, and would have moved the curricula attached
 * to those terms onto a window a year after they were taught. Neither school
 * ever rolled over; that is the actual defect, and rolling over is the fix.
 *
 * ONE CALENDAR PER SCHOOL. `CALENDARS` is keyed by school precisely because the
 * two schools are separate calendars, not one shared one — school 1 is the
 * Secondary school, school 2 is Nursery & Primary, and the school has already
 * said their term dates differ. Only school 1's dates have been supplied so far
 * (H1 questionnaire: first day pupils resume, last day pupils attend, per term,
 * PLANNED for all three). School 2 reuses them AS A DELIBERATE PLACEHOLDER at
 * the school's instruction, to get it off a finished 2025/2026 session; its
 * real dates are pending its own calendar. Correcting them later needs no
 * migration — sessions and terms are full CRUD through the app
 * (`POST/PUT /api/sessions`, `POST/PUT /api/sessions/{session}/terms`), so the
 * school edits them in the UI.
 *
 * WHAT REMAINS BROKEN, DELIBERATELY. The 2025/2026 term rows are still corrupt
 * for both schools — school 1's terms 1 and 3 share the window 2026-04-07 →
 * 2026-06-27 and term 1 starts after term 2; school 2's are clean month
 * boundaries (May 1–30, Jun 1–30, Jul 1–31), plainly `TermSeeder` output rather
 * than a calendar. That is the `TermSeeder` + 2026_05_06_111742 damage item 9
 * describes, independently confirmed against the live rows before this was
 * written. They are NOT touched here: nobody has the real 2025/2026 dates, that
 * year is closed, and inventing them is the thing the original agent was right
 * to refuse. They stay as historical record.
 *
 * A NOTE ON "PER-SECTION DATES". That open question (nursery/primary/secondary
 * running to different dates) needs no schema change: the sections are already
 * separate SCHOOLS here, each with its own session and terms — not a missing
 * dimension on `terms`. The one genuine gap is that nursery and primary share
 * school 2, so if those two differ from EACH OTHER that still has nowhere to
 * live.
 *
 * SAFE ON AN EMPTY DATABASE. Each school is guarded on existing, so a
 * migrate-from-zero (bin/quality-clean-db) is a clean no-op rather than a
 * failure. Idempotent on a populated one: re-running resolves the same sessions
 * by slug and writes the same dates.
 */
return new class extends Migration
{
    private const SESSION_NAME = '2026/2027';

    private const SESSION_SLUG = '2026-2027';

    /**
     * school_id => (order => term row). One entry per school: these are separate
     * calendars that currently hold the same values, NOT a shared calendar.
     * Names and slugs match the convention already in `terms`.
     */
    private const CALENDARS = [
        // Secondary School — supplied by the school, planned 2026/2027.
        1 => [
            1 => ['name' => 'Autumn/Term 1', 'slug' => 'autumn-term-1', 'start' => '2026-09-05', 'end' => '2026-12-18'],
            2 => ['name' => 'Spring/Term 2', 'slug' => 'spring-term-2', 'start' => '2027-01-10', 'end' => '2027-03-26'],
            3 => ['name' => 'Summer/Term 3', 'slug' => 'summer-term-3', 'start' => '2027-04-12', 'end' => '2027-06-26'],
        ],
        // NURSERY AND PRIMARY — PLACEHOLDER: school 1's dates, reused at the
        // school's instruction until the nursery/primary calendar is issued.
        // Expected to be edited in the UI, not by another migration.
        2 => [
            1 => ['name' => 'Autumn/Term 1', 'slug' => 'autumn-term-1', 'start' => '2026-09-05', 'end' => '2026-12-18'],
            2 => ['name' => 'Spring/Term 2', 'slug' => 'spring-term-2', 'start' => '2027-01-10', 'end' => '2027-03-26'],
            3 => ['name' => 'Summer/Term 3', 'slug' => 'summer-term-3', 'start' => '2027-04-12', 'end' => '2027-06-26'],
        ],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::CALENDARS as $schoolId => $terms) {
                $this->openSession($schoolId, $terms);
            }
        });
    }

    /** @param  array<int, array{name: string, slug: string, start: string, end: string}>  $terms */
    private function openSession(int $schoolId, array $terms): void
    {
        // No such school => nothing to roll over. A fresh/clean database, not an
        // error: this is a data fix for named schools, not a schema change.
        if (! DB::table('schools')->where('id', $schoolId)->exists()) {
            return;
        }

        $now = now();

        $sessionId = DB::table('academic_sessions')
            ->where('school_id', $schoolId)
            ->where('slug', self::SESSION_SLUG)
            ->value('id');

        if ($sessionId === null) {
            $sessionId = DB::table('academic_sessions')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'school_id' => $schoolId,
                'name' => self::SESSION_NAME,
                'slug' => self::SESSION_SLUG,
                // Inserted NOT current, promoted at the end. The unique index on
                // the generated `current_school_key` (2026_07_28_120001) permits
                // exactly one current session per school, so the old one must be
                // demoted before this is raised — never both at once.
                'is_current' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // If a 2026/2027 session already exists with a term shape this does not
        // describe, something else created it — refuse rather than half-write.
        $unexpected = DB::table('terms')
            ->where('academic_session_id', $sessionId)
            ->whereNotIn('order', array_keys($terms))
            ->count();

        if ($unexpected > 0) {
            throw new RuntimeException(
                'Item 9 rollover: session '.$sessionId.' (school '.$schoolId.') already holds '
                .$unexpected.' term(s) outside orders ['.implode(',', array_keys($terms))
                .'] — refusing to run against an unexpected shape.'
            );
        }

        foreach ($terms as $order => $term) {
            $existingId = DB::table('terms')
                ->where('academic_session_id', $sessionId)
                ->where('order', $order)
                ->value('id');

            if ($existingId !== null) {
                // Idempotent re-run: correct the dates, leave name/status/
                // registration_deadline/result_visible_at alone.
                DB::table('terms')->where('id', $existingId)->update([
                    'start_date' => $term['start'],
                    'end_date' => $term['end'],
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('terms')->insert([
                'uuid' => (string) Str::uuid(),
                'academic_session_id' => $sessionId,
                'school_id' => $schoolId,
                'name' => $term['name'],
                'slug' => $term['slug'],
                'order' => $order,
                'start_date' => $term['start'],
                'end_date' => $term['end'],
                // All three are in the future as of this migration; the school
                // advances them through its own lifecycle, not from here.
                'status' => 'upcoming',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Demote THEN promote — see the is_current note above.
        DB::table('academic_sessions')
            ->where('school_id', $schoolId)
            ->where('id', '!=', $sessionId)
            ->where('is_current', true)
            ->update(['is_current' => false, 'updated_at' => $now]);

        DB::table('academic_sessions')
            ->where('id', $sessionId)
            ->update(['is_current' => true, 'updated_at' => $now]);
    }

    /**
     * Genuinely reversible, unlike an in-place date overwrite: this migration
     * ADDS a session per school, so removing them restores the prior state
     * exactly.
     *
     * Refuses if anything has attached to the new terms in the meantime —
     * deleting a term that curricula or a fee schedule point at is not a
     * rollback, it is data loss with a foreign key in the way.
     */
    public function down(): void
    {
        DB::transaction(function () {
            foreach (array_keys(self::CALENDARS) as $schoolId) {
                $this->closeSession((int) $schoolId);
            }
        });
    }

    private function closeSession(int $schoolId): void
    {
        $sessionId = DB::table('academic_sessions')
            ->where('school_id', $schoolId)
            ->where('slug', self::SESSION_SLUG)
            ->value('id');

        if ($sessionId === null) {
            return;
        }

        $termIds = DB::table('terms')
            ->where('academic_session_id', $sessionId)
            ->pluck('id')
            ->all();

        if ($termIds !== []) {
            $attached = DB::table('curricula')->whereIn('term_id', $termIds)->count();

            if ($attached > 0) {
                throw new RuntimeException(
                    'Item 9 rollover down(): '.$attached.' curricula are attached to school '
                    .$schoolId.'\'s '.self::SESSION_NAME
                    .' terms — refusing to delete a session already in use.'
                );
            }

            if (Schema::hasTable('finance_fee_schedules')) {
                $scheduled = DB::table('finance_fee_schedules')->whereIn('term_id', $termIds)->count();

                if ($scheduled > 0) {
                    throw new RuntimeException(
                        'Item 9 rollover down(): '.$scheduled.' fee schedules reference school '
                        .$schoolId.'\'s '.self::SESSION_NAME
                        .' terms — refusing to delete a session already in use.'
                    );
                }
            }
        }

        DB::table('terms')->where('academic_session_id', $sessionId)->delete();
        DB::table('academic_sessions')->where('id', $sessionId)->delete();

        // Hand `current` back to this school's most recent remaining session —
        // for both schools that is 2025/2026, the session this rollover demoted.
        $previousId = DB::table('academic_sessions')
            ->where('school_id', $schoolId)
            ->orderByDesc('id')
            ->value('id');

        if ($previousId !== null) {
            DB::table('academic_sessions')
                ->where('id', $previousId)
                ->update(['is_current' => true, 'updated_at' => now()]);
        }
    }
};
