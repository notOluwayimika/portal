<?php

namespace App\Console\Commands;

use App\Models\StudentCurriculum;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * S1 promotion-link closure — repair the `status='promoted'` rows that carry no `promoted_to_id`.
 *
 * These rows were produced by MoveFromCcmJob writing status='promoted' while holding the target episode in
 * a variable it did not record (fixed in commit 5 / this closure). They are a RECORDING failure, not a
 * data-integrity unknown: each should point at the student's next same-school episode. This command writes
 * that link — AFTER the composite (promoted_to_id, student_id, school_id) FK is live, so every link is
 * validated by the engine at write time (a wrong-student or wrong-school target is REFUSED, never written).
 *
 * NOT a migration, deliberately: this is one environment's accumulated history, not a schema change (a
 * migration would run against every fresh test DB where these rows do not exist), it must be re-runnable
 * and must report what it did, and it needs a dry run. `--dry-run` is the DEFAULT; writing needs `--commit`.
 *
 * SAFETY GATE (the numbers behind the "safe backfill" claim are dev, not production): before touching a
 * single row this counts ORPHANS (a promoted-NULL row with no later episode — nothing to point at) and
 * MULTI-CANDIDATE rows (more than one later episode — "the next by id" is then a choice, not a fact). If
 * EITHER is above zero it stops and reports, because both are decisions a human must make, not a command.
 * It spans ALL schools (withoutGlobalScopes + school_id in the join), so it can never silently repair only
 * the operator's active school.
 */
class BackfillPromotionLinks extends Command
{
    protected $signature = 'academics:backfill-promotion-links {--commit : Write the links (default is a dry run)}';

    protected $description = 'Backfill promoted_to_id on status=promoted episodes that lost their link (S1 closure).';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        // ── Safety gate: orphans and multi-candidates must both be zero, or a human decides ──
        $orphaned = $this->orphanCount();
        $multi = $this->multiCandidateCount();

        if ($orphaned > 0 || $multi > 0) {
            $this->error('Refusing to backfill — the data needs a human decision first:');
            $this->line("  orphaned (promoted, no later episode to link):  {$orphaned}");
            $this->line("  multi-candidate (more than one later episode):  {$multi}");
            $this->line('An orphan has nothing to point at (resolve by moving its status off promoted). A');
            $this->line('multi-candidate makes "the next episode by id" a choice, not a fact — review a sample');
            $this->line('before any write. Neither is this command\'s call. Nothing was written.');

            return self::FAILURE;
        }

        // ── Link each promoted-NULL row to its (unique) next same-student, same-school episode ──
        $targets = StudentCurriculum::withoutGlobalScopes()
            ->where('status', 'promoted')
            ->whereNull('promoted_to_id')
            ->orderBy('id')
            ->get(['id', 'student_id', 'school_id']);

        $examined = $targets->count();
        $linked = 0;
        $skippedOrphan = 0;
        $refused = [];

        foreach ($targets as $row) {
            // getAttribute for school_id: it was added by a raw ALTER migration, so static analysis cannot
            // see it as a typed model property (id/student_id come from the parseable create migration).
            $nextId = StudentCurriculum::withoutGlobalScopes()
                ->where('student_id', $row->student_id)
                ->where('school_id', $row->getAttribute('school_id'))
                ->where('id', '>', $row->id)
                ->orderBy('id')
                ->value('id');

            if ($nextId === null) {
                // Impossible once the gate above passes (orphaned === 0); kept so a mid-run change of the
                // data cannot make the command invent a link it has no basis for.
                $skippedOrphan++;

                continue;
            }

            if (! $commit) {
                $linked++; // would-link

                continue;
            }

            // Each write is wrapped alone: one row refused by the FK must not roll back the other 365.
            try {
                StudentCurriculum::withoutGlobalScopes()
                    ->whereKey($row->id)
                    ->update(['promoted_to_id' => $nextId, 'updated_at' => now()]);
                $linked++;
            } catch (QueryException $e) {
                $refused[] = $row->id;
            }
        }

        $verb = $commit ? 'linked' : 'would link';
        $this->info(($commit ? 'Backfill committed.' : 'DRY RUN (pass --commit to write).'));
        $this->line("  examined (promoted, NULL link):  {$examined}");
        $this->line("  {$verb}:  {$linked}");
        $this->line("  skipped as orphaned:  {$skippedOrphan}");
        $this->line('  refused by the database:  '.count($refused));

        if ($refused !== []) {
            // A refusal means the "next episode by id" rule picked a row the composite FK rejects — a fact
            // about the data worth understanding before it is worked around. Do not retry blindly.
            $this->error('Some links were REFUSED by the composite FK — student_curricula ids: '.implode(', ', $refused));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function orphanCount(): int
    {
        return (int) DB::table('student_curricula as sc')
            ->where('sc.status', 'promoted')
            ->whereNull('sc.promoted_to_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('student_curricula as t')
                ->whereColumn('t.student_id', 'sc.student_id')
                ->whereColumn('t.school_id', 'sc.school_id')
                ->whereColumn('t.id', '>', 'sc.id'))
            ->count();
    }

    private function multiCandidateCount(): int
    {
        return (int) DB::table('student_curricula as sc')
            ->where('sc.status', 'promoted')
            ->whereNull('sc.promoted_to_id')
            ->whereRaw('(SELECT COUNT(*) FROM student_curricula t
                         WHERE t.student_id = sc.student_id AND t.school_id = sc.school_id AND t.id > sc.id) > 1')
            ->count();
    }
}
