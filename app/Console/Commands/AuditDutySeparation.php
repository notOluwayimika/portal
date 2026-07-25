<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DutySeparation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
    protected $signature = 'finance:audit-duty-separation';

    protected $description = 'READ-ONLY: per school, list users holding BOTH sides of any maker-checker pair (exit non-zero on findings)';

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
                    'user' => $user->email ?? ('user#'.$user->id),
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

        return self::FAILURE;
    }
}
