<?php

namespace App\Notifications\Services\Resolvers;

use App\Models\User;
use App\Notifications\Contracts\Notification;
use App\Notifications\Contracts\RecipientResolver;
use App\Notifications\DTOs\Recipient;
use App\Notifications\Enums\RecipientReason;
use App\Support\ApprovalAbility;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Everyone who may DECIDE a pending approval, in one School.
 *
 * Serves every approval family at once — credit notes, invoice voids,
 * discount-policy changes, fee-schedule changes, result approvals — because
 * `ApprovalAbility` derives the maker–checker vocabulary by convention rather
 * than from a list. A new family needs no new resolver and no new notification
 * type, on the same terms as ADR 0040's bypass exclusion.
 *
 * ── 1 · CHECKER ABILITIES ONLY, ENFORCED AT RUNTIME ─────────────────────────
 *
 * This resolver is safe ONLY for abilities whose terminal segment is
 * `approve`/`reject`. The reason is §2 below: it reads STORED grants, which is
 * the correct recipient set precisely because ADR 0040 excludes checker actions
 * from the `super_admin` `Gate::before` bypass — a super admin's power over any
 * OTHER ability comes from the bypass and leaves no stored grant, so pointing
 * this resolver at a non-checker ability would produce a recipient set that
 * silently omits them while the UI shows they can act.
 *
 * The guard is a THROWN LogicException, deliberately not `assert()`:
 * `zend.assertions` is compiled out in production, so an assert here would be a
 * guard that exists everywhere except where it matters. Belt and braces with
 * `NotificationCheckerAbilityTest`, which enumerates `App\Enums\Permission` and
 * asserts every ability this resolver is reachable with is terminally
 * approve/reject — convention-not-a-list, the same enforcement the bypass rule
 * already uses.
 *
 * ── 2 · SET QUERY, NOT AN ALL-USERS `can()` SWEEP ───────────────────────────
 *
 * `$user->can($ability)` is the right oracle for "may THIS user approve?" and
 * the wrong tool for "who are ALL the checkers here?" — the inverse question.
 * Answering it by looping users is O(users) queries and, worse, would sweep in
 * every bypass-only super admin.
 *
 * So the recipient set is a query over the stored grant, both ways a grant can
 * reach a user: through a role, and directly. Both are scoped by the spatie team
 * column (`school_id`), so a user who is a checker at one school is not notified
 * about another's. `can()` keeps its place — in the TEST that proves this set is
 * right, not in the runtime path that builds it.
 */
class CheckerAbilityResolver implements RecipientResolver
{
    public function resolve(Notification $notification): iterable
    {
        $ability = $notification->payload()['checker_ability'] ?? null;

        if (! is_string($ability) || $ability === '') {
            throw new LogicException(
                'CheckerAbilityResolver requires a `checker_ability` string in the '
                .'notification payload; none was given.'
            );
        }

        if (! ApprovalAbility::isExcludedFromSuperAdminBypass($ability)) {
            throw new LogicException(
                "CheckerAbilityResolver was given [{$ability}], whose terminal segment is not "
                .'one of ['.implode(', ', ApprovalAbility::CHECKER_SEGMENTS).']. This resolver '
                .'reads STORED grants, which is only the correct recipient set for checker '
                .'abilities — those are excluded from the super-admin Gate::before bypass '
                .'(ADR 0040), so no one holds them by bypass alone. Pointing it at any other '
                .'ability yields a set that silently omits every super admin.'
            );
        }

        $schoolId = $notification->schoolId();

        // Grants held THROUGH A ROLE, in this School's team.
        $viaRole = DB::table('users')
            ->select('users.id')
            ->join('model_has_roles as mhr', function ($join) use ($schoolId) {
                $join->on('mhr.model_id', '=', 'users.id')
                    ->where('mhr.model_type', '=', User::class)
                    ->where('mhr.school_id', '=', $schoolId);
            })
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'mhr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->where('p.name', $ability)
            ->where('p.guard_name', 'web')
            // Withdrawn and disabled accounts hold their grants until someone
            // revokes them, so both have to be filtered here or a departed
            // checker keeps accruing an unread queue no one will ever clear.
            ->whereNull('users.deleted_at')
            ->whereNull('users.disabled_at');

        // Grants held DIRECTLY. Rare, but a recipient set that ignores them tells
        // someone who can act that there was nothing to act on.
        $direct = DB::table('users')
            ->select('users.id')
            ->join('model_has_permissions as mhp', function ($join) use ($schoolId) {
                $join->on('mhp.model_id', '=', 'users.id')
                    ->where('mhp.model_type', '=', User::class)
                    ->where('mhp.school_id', '=', $schoolId);
            })
            ->join('permissions as p', 'p.id', '=', 'mhp.permission_id')
            ->where('p.name', $ability)
            ->where('p.guard_name', 'web')
            ->whereNull('users.deleted_at')
            ->whereNull('users.disabled_at');

        // UNION, not two passes: a user holding the grant both ways appears once.
        foreach ($viaRole->union($direct)->pluck('id') as $userId) {
            yield Recipient::user((int) $userId, RecipientReason::ROLE);
        }
    }
}
