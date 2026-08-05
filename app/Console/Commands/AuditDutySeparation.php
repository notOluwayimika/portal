<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DutySeparation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * READ-ONLY segregation-of-duties audit — "does anyone hold BOTH sides?" Per school, per
 * maker-checker pair, lists every user who effectively holds both the maker and the checker
 * ability. It REVOKES NOTHING; it reports and exits non-zero on findings, so it slots into a
 * pre-pilot checklist.
 *
 * The act-level guarantee (no one approves their own request) is absolute in the database and is
 * NOT what this checks. This finds the CONFIGURATION hole the DB CHECK cannot: a both-sides user
 * who can approve a colleague's work in both directions. Whether a finding is fixed by revoking a
 * grant or by accepting the arrangement is a human decision — this only surfaces it.
 *
 * Covers EVERY maker-checker pair (finance and academic result), derived from the ApprovalAbility
 * convention — a future instance (refunds) is audited the day its permission exists, no edit here.
 * Its pair with {@see CheckStaffingReadiness}: this says "nobody holds both", that says "enough
 * people hold each".
 */
class AuditDutySeparation extends Command
{
    protected $signature = 'finance:audit-duty-separation {--baseline= : Path to a committed file of ACCEPTED result.* findings; exit 0 while the current set is a subset of it}';

    protected $description = 'READ-ONLY: per school, list users holding BOTH sides of any maker-checker pair (exit non-zero on findings)';

    /**
     * The namespace no baseline may ever amnesty. HARD-CODED on purpose: the whole reason this
     * command exists is finance segregation of duties, and a baseline is an editable file. If a
     * finance finding could be silenced by adding a line to it, the control would be exactly as
     * strong as whoever last edited the file — which is not a control.
     *
     * Zero finance findings is the invariant. The baseline is only ever an amnesty for `result.*`.
     *
     * PUBLIC because it is pinned from outside. This string is duplicated in
     * {@see DutySeparation::enforcedPairs()}, and that duplication is DELIBERATE —
     * that method's docblock explains why its scope boundary is one obvious literal rather than a
     * shared constant. Deriving one from the other would collapse two decisions into one. Instead
     * DutySeparationBaselineTest asserts every enforced pair's checker starts with this value, so
     * renaming the namespace on either side goes red rather than silently making finance findings
     * baselineable.
     */
    public const NEVER_BASELINEABLE = 'finance.';

    public function handle(): int
    {
        $pairs = DutySeparation::pairs();
        $this->info('Auditing '.count($pairs).' maker-checker pair(s) for both-sides users (effective ability, per school)…');

        // Distinct (user, school) from role assignments — the only way a permission is held here
        // (recon #3: no direct user permissions). A both-sides user is found via their roles.
        $assignments = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->whereNotNull('school_id')
            ->select('model_id', 'school_id')
            ->distinct()
            ->get();

        $findings = [];
        foreach ($assignments as $row) {
            $user = User::find($row->model_id);
            if ($user === null) {
                continue;
            }
            foreach (DutySeparation::violations($user, (int) $row->school_id) as $pair) {
                $findings[] = [
                    'school_id' => (int) $row->school_id,
                    // ids, never credential material. PR #195 stopped email addresses reaching
                    // activity_log.properties; this output is the same class of leak by another
                    // route, and an id answers every question a duty-separation finding asks.
                    'user_id' => (int) $user->id,
                    'user' => 'user#'.$user->id,
                    'checker' => $pair['checker'],
                    'maker' => $pair['maker'],
                ];
            }
        }
        setPermissionsTeamId(null);

        if ($findings === []) {
            $this->info('Clean: no user holds both sides of any pair in any school.');

            return self::SUCCESS;
        }

        $this->error(count($findings).' both-sides finding(s) — a user holding a checker AND its matching maker in one school:');
        usort($findings, fn ($a, $b) => [$a['school_id'], $a['user']] <=> [$b['school_id'], $b['user']]);
        $this->table(
            ['School', 'User', 'Checker held', 'Maker held'],
            array_map(fn ($f) => [$f['school_id'], $f['user'], $f['checker'], $f['maker']], $findings),
        );
        $this->warn('DETECTION ONLY — nothing was revoked. Resolve by revoking a grant or accepting the arrangement (project-lead decision).');

        $baselinePath = $this->option('baseline');

        if ($baselinePath === null) {
            return self::FAILURE;
        }

        return $this->judgeAgainstBaseline($findings, (string) $baselinePath);
    }

    /**
     * With `--baseline`, "non-zero" recovers its meaning: it stops meaning "this database has
     * findings" (which has been true every day since the command was scheduled) and starts meaning
     * "something appeared that nobody has accepted".
     *
     * KEYED BY TUPLE, NEVER BY COUNT. `school_id|user_id|checker|maker`, one per line. A count
     * ratchet passes on the day one `result.*` finding is resolved and one finance finding appears —
     * which is precisely the event this whole control exists to catch.
     *
     * @param  list<array{school_id: int, user_id: int, user: string, checker: string, maker: string}>  $findings
     */
    private function judgeAgainstBaseline(array $findings, string $path): int
    {
        // A baseline that cannot be read must NOT pass. An unreadable file looks identical to a clean
        // database from the exit code, and a control that cannot look is worse than no control —
        // the same rule bin/ci-grants-convergence-lint.php states as NOT LINTED.
        if (! File::exists($path)) {
            $this->error("NOT AUDITED — baseline file [{$path}] does not exist. A missing baseline is not an empty one; "
                .'create it, or run without --baseline for the unfiltered exit code.');

            return self::FAILURE;
        }

        $baseline = collect(explode("\n", (string) File::get($path)))
            ->map(fn (string $line): string => trim($line))
            ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, '#'))
            ->values();

        $current = collect($findings)->map(fn (array $f): string => self::key($f));

        // The hard-coded half, checked BEFORE the baseline is consulted so no file edit can reach it.
        $financeFindings = collect($findings)
            ->filter(fn (array $f): bool => str_starts_with($f['checker'], self::NEVER_BASELINEABLE)
                || str_starts_with($f['maker'], self::NEVER_BASELINEABLE))
            ->map(fn (array $f): string => self::key($f))
            ->unique()->sort()->values();

        $unaccepted = $current->unique()->reject(fn (string $k): bool => $baseline->contains($k))->sort()->values();

        // Stale entries are not a failure — the comparison is a SUBSET test, so a resolved finding
        // passes. Reported because the repo's baselines only ever shrink (ADR 0041): a line that no
        // longer occurs is a line to delete, and nobody deletes what nobody prints.
        $stale = $baseline->unique()->reject(fn (string $k): bool => $current->contains($k))->sort()->values();

        if ($stale->isNotEmpty()) {
            $this->line('');
            $this->info($stale->count().' baseline entry/entries no longer occur — prune them (baselines only shrink):');
            $stale->each(fn (string $k) => $this->line('  - '.$k));
        }

        if ($financeFindings->isNotEmpty()) {
            $this->line('');
            $this->error($financeFindings->count().' FINANCE both-sides finding(s). A finance finding is NEVER '
                .'baselineable — zero is the invariant, and adding these lines to the baseline will not silence them:');
            $financeFindings->each(fn (string $k) => $this->line('  '.$k));

            return self::FAILURE;
        }

        if ($unaccepted->isNotEmpty()) {
            $this->line('');
            $this->error($unaccepted->count().' finding(s) NOT in the baseline ['.$path.'] — this is the regression:');
            $unaccepted->each(fn (string $k) => $this->line('  '.$k));
            $this->warn('Fix the grant, or — if the arrangement is accepted — add the line to the baseline in a diff somebody reads.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('All '.$current->unique()->count().' finding(s) are accepted in the baseline ['.$path
            .'] and none is in the ['.self::NEVER_BASELINEABLE.'] namespace.');

        return self::SUCCESS;
    }

    /**
     * One finding's baseline key. Integer ids only — the same shape the table above prints, and the
     * same reason: an id answers every question a duty-separation finding asks, and this file is
     * committed.
     *
     * @param  array{school_id: int, user_id: int, checker: string, maker: string}  $finding
     */
    private static function key(array $finding): string
    {
        return $finding['school_id'].'|'.$finding['user_id'].'|'.$finding['checker'].'|'.$finding['maker'];
    }
}
