<?php

namespace App\Console\Commands;

use App\Models\Guardian;
use Illuminate\Console\Command;

/**
 * Find guardians flagged `can_login` who cannot actually be logged into.
 *
 * THE FORWARD GUARD IS ONLY HALF THE INVARIANT. GuardianService now refuses to raise
 * `can_login` without a deliverable email — but the transition has been unguarded in
 * production, so every historical flip on a guardian with a synthetic address left
 * exactly that state behind. Those rows are parents who were recorded as having
 * portal access and do not have it: the password was regenerated, the notification
 * early-returned on an undeliverable address, and the flip reported success.
 *
 * Stopping new violations does not heal old ones, and TWO THINGS FOLLOW. The
 * invariant does not actually HOLD until the existing rows clear — so the
 * password-reset safety argument that depends on it ("a login account always has a
 * real email, therefore the broker always resolves a user") is not yet true. And
 * separately, these are broken user-facing accounts regardless of any migration.
 *
 * ⚠️ IT IS A FLOOR, NOT A FIGURE, for the same reason the reroute census is a ceiling.
 * It walks the application's own deliverability predicate — a superset of the minted
 * sentinel, catching an empty or whitespace address too — but nothing here reaches
 * "the mailbox bounces", which needs the provider. The strict sentinel-only floor is
 * one SQL query away when a narrower number is wanted:
 *
 *   SELECT COUNT(DISTINCT u.id) FROM users u
 *   JOIN guardians g ON g.user_id = u.id
 *   JOIN guardian_student gs ON gs.guardian_id = g.id AND gs.can_login = 1
 *   WHERE u.email LIKE '%@no-email.local';
 *
 * IT IS DETECT-ONLY. Whether a violating account should be downgraded to
 * `can_login = false` (it was never usable) or given a real email and re-issued
 * credentials (it should have access) is a product decision about the school's
 * relationship with that parent, not a technical one — the same call the no-contact
 * census hands over. Exits non-zero when any exist, so it doubles as the pre-cutover
 * gate: the cutover's reset-safety rests on this returning zero.
 */
class AuditGuardianLoginInvariant extends Command
{
    protected $signature = 'guardians:audit-login-invariant';

    protected $description = 'Report guardians with can_login=true whose account has no deliverable email';

    public function handle(): int
    {
        $violations = Guardian::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            // The aggregate: `can_login` lives on the pivot, so "is a login account"
            // is ANY pivot true, never a column on users.
            ->whereHas('students', fn ($q) => $q->where('guardian_student.can_login', true))
            ->with('user')
            ->get()
            ->filter(function (Guardian $guardian) {
                $user = $guardian->user;

                // The application's OWN deliverability predicate, not a second
                // definition of "undeliverable" — a superset of the minted sentinel
                // that also catches an empty or whitespace address.
                return $user === null || ! $user->hasDeliverableEmail();
            });

        if ($violations->isEmpty()) {
            $this->info('No violations: every login-enabled guardian has a deliverable email.');

            return self::SUCCESS;
        }

        $this->error($violations->count().' login-enabled guardian(s) cannot receive credentials.');

        $this->table(
            ['guardian', 'school_id', 'address'],
            $violations->take(50)->map(fn (Guardian $g) => [
                // No names in output beyond what an operator already sees in the app;
                // the id is what a remediation would act on.
                'guardian#'.$g->id,
                $g->school_id,
                $g->user ? (string) $g->user->email : '(no user account)',
            ])->all(),
        );

        if ($violations->count() > 50) {
            $this->line('… '.($violations->count() - 50).' more not shown.');
        }

        $this->newLine();
        $this->line('Detect-only. Remediation is a product call: downgrade to can_login=false');
        $this->line('(never usable), or supply a real email and re-issue credentials (should have access).');

        return self::FAILURE;
    }
}
