<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\User;
use App\Support\DutySeparation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY per-school STAFFING readiness — "are there enough people to run the two-person flow?"
 * The DB CHECK guarantees no one person can do both halves of an ACT; it does not guarantee a
 * school has two humans who between them can do either. A school that grants everything to one
 * person (or grants only one side) is configured into a state where nothing can ever be
 * approved — discoverable today only when a bursar tries. This is the pre-flight for enabling the
 * module at a school.
 *
 * For each school and each maker-checker pair, it passes only when TWO DISTINCT users cover the
 * sides — at least one holding the maker and a DIFFERENT one holding the checker. Evaluated on RAW
 * GRANTS ({@see DutySeparation::holdsViaGrant}), so super_admin — which holds every maker via the
 * Gate::before bypass but is a platform admin, not school staff — is not miscounted as an operator.
 * (Its pair with {@see AuditDutySeparation}: that says "nobody holds both", this says "enough hold
 * each".)
 *
 * RESIDUAL it cannot see (written down, not solved): two "distinct" users who are the same human
 * with two accounts read as staffed here. Identity de-duplication is out of this control's reach.
 */
class CheckStaffingReadiness extends Command
{
    protected $signature = 'finance:check-staffing-readiness';

    protected $description = 'READ-ONLY: per school, is each maker-checker pair covered by TWO distinct users (exit non-zero on gaps)';

    public function handle(): int
    {
        $pairs = DutySeparation::pairs();
        $rows = [];
        $anyGap = false;

        foreach (School::query()->orderBy('id')->get() as $school) {
            // Users with any role in THIS school (recon #3: permissions are held only via roles).
            $userIds = DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('school_id', $school->id)
                ->pluck('model_id')->unique();
            $users = User::query()->whereIn('id', $userIds)->get();

            foreach ($pairs as $pair) {
                $makers = $users->filter(fn (User $u) => DutySeparation::holdsViaGrant($u, (int) $school->id, $pair['maker']));
                $checkers = $users->filter(fn (User $u) => DutySeparation::holdsViaGrant($u, (int) $school->id, $pair['checker']));

                // Covered ⇔ ∃ a maker and a checker who are DISTINCT users.
                $distinctPair = $makers->isNotEmpty() && $checkers->isNotEmpty()
                    && $makers->pluck('id')->merge($checkers->pluck('id'))->unique()->count() >= 2;

                if (! $distinctPair) {
                    $anyGap = true;
                }

                $rows[] = [
                    'school' => $school->name,
                    // The checker permission verbatim. Stripping only `.approve` printed the approve pair as
                    // `finance.credit-note` and the reject pair as `finance.credit-note.reject`, reading as if
                    // the second were a variant of the first — they are two separate pairs of the same shape.
                    'pair' => $pair['checker'],
                    'makers' => $makers->count(),
                    'checkers' => $checkers->count(),
                    'status' => $distinctPair ? 'OK' : 'GAP',
                ];
            }
        }
        setPermissionsTeamId(null);

        $this->table(
            ['School', 'Pair', '#makers', '#checkers', 'Two-person flow'],
            array_map(fn ($r) => [$r['school'], $r['pair'], $r['makers'], $r['checkers'], $r['status']], $rows),
        );

        if ($anyGap) {
            $this->error('Staffing GAP: at least one school/pair lacks two distinct users to run the two-person flow. That module cannot approve there until staffed.');

            return self::FAILURE;
        }

        $this->info('Every school covers every maker-checker pair with two distinct users.');

        return self::SUCCESS;
    }
}
