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
 * SKIP-AND-REPORT, not abort-all. Every UNAMBIGUOUS row is linked; the odd rows are reported and the command
 * exits FAILURE, so a human rules on what remains rather than the whole batch waiting behind them:
 *   • ORPHAN (a promoted-NULL row with no later episode — nothing to point at): skipped and its id reported.
 *     Resolve by moving its status off promoted; this command cannot invent a target.
 *   • MULTI-CANDIDATE (more than one later episode): NOT a problem — "the next by id" is deterministic, so a
 *     student with three later episodes still has an unambiguous next one. The count + a sample are printed
 *     as information only.
 * A skipped or refused row is STILL a violating row the CHECK refuses, so exiting FAILURE while any remain
 * is the point — the deploy is not unblocked until the odd rows are ruled on. It spans ALL schools
 * (withoutGlobalScopes + school_id in the join), so it can never silently repair only the active school.
 */
class BackfillPromotionLinks extends Command
{
    protected $signature = 'academics:backfill-promotion-links {--commit : Write the links (default is a dry run)}';

    protected $description = 'Backfill promoted_to_id on status=promoted episodes that lost their link (S1 closure).';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        // Multi-candidate rows are REPORTED, not blocked (the next by id is still unambiguous).
        $multi = $this->multiCandidateIds();
        if ($multi !== []) {
            $this->warn(count($multi).' promoted-NULL row(s) have more than one later episode; the NEXT by id '
                .'is still unambiguous. Sample ids: '.implode(', ', array_slice($multi, 0, 10)));
        }

        $targets = StudentCurriculum::withoutGlobalScopes()
            ->whereRaw("status = BINARY 'promoted'")   // BINARY per house discipline (matches the migration)
            ->whereNull('promoted_to_id')
            ->orderBy('id')
            ->get(['id', 'student_id', 'school_id']);

        $examined = $targets->count();
        $linked = 0;
        $skipped = [];              // orphan ids (no later episode)
        $refused = [];              // id => driver message

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
                $skipped[] = $row->id;   // orphan — skip and report; never invent a target

                continue;
            }

            if (! $commit) {
                $linked++;               // would-link

                continue;
            }

            // Each write is wrapped alone: one row the FK refuses must not roll back the rest.
            try {
                StudentCurriculum::withoutGlobalScopes()
                    ->whereKey($row->id)
                    ->update(['promoted_to_id' => $nextId, 'updated_at' => now()]);
                $linked++;
            } catch (QueryException $e) {
                $refused[$row->id] = $e->getMessage();
            }
        }

        $verb = $commit ? 'linked' : 'would link';
        $this->info($commit ? 'Backfill committed.' : 'DRY RUN (pass --commit to write).');
        $this->line("  examined (promoted, NULL link):  {$examined}");
        $this->line("  {$verb}:  {$linked}");
        $this->line('  skipped as orphaned:  '.count($skipped).($skipped !== [] ? ' — ids: '.implode(', ', $skipped) : ''));
        $this->line('  refused by the database:  '.count($refused));

        if ($refused !== []) {
            $firstId = array_key_first($refused);
            $this->error('Some links were REFUSED — student_curricula ids: '.implode(', ', array_keys($refused)));
            // The reason lives in the driver message, not the id — surface it (A4) so the operator does not
            // have to reproduce the failure to find out what happened.
            $this->error("First refusal (#{$firstId}): ".$refused[$firstId]);
        }

        if ($skipped !== []) {
            $this->warn('Orphaned rows remain status=promoted with a NULL link — they STILL violate the CHECK. '
                .'Resolve each by moving its status off promoted; this command cannot invent a target.');
        }

        // SUCCESS only when nothing was skipped or refused — i.e. no violating row remains. A skipped orphan
        // or a refused row is still a CHECK violation, so the command must not exit 0 with one outstanding.
        return $skipped === [] && $refused === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * ids of promoted-NULL rows whose student has MORE THAN ONE later same-school episode. Reported only.
     *
     * @return list<int>
     */
    private function multiCandidateIds(): array
    {
        return DB::table('student_curricula as sc')
            ->whereRaw("sc.status = BINARY 'promoted'")
            ->whereNull('sc.promoted_to_id')
            ->whereRaw('(SELECT COUNT(*) FROM student_curricula t
                         WHERE t.student_id = sc.student_id AND t.school_id = sc.school_id AND t.id > sc.id) > 1')
            ->orderBy('sc.id')
            ->pluck('sc.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
