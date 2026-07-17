<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * S7 parity soak instrument. Given a user's two School-access derivations — the
 * legacy union (school_user pivot + guardian records + users.school_id) and the
 * single source (model_has_roles) — logs any divergence so the two can be proven
 * identical on live traffic BEFORE the legacy columns are dropped.
 *
 * Dual-compute by design: the caller passes BOTH sets (computed in the same
 * request), so this catches per-user divergence a flag-flip-between-runs soak
 * would miss. A row is emitted per divergent (user, School):
 *
 *   reason = "lost"   → School is in the legacy set but NOT model_has_roles
 *                       (dropping the columns would REVOKE this access — the
 *                       dangerous direction; means a missing role backfill).
 *   reason = "gained" → School is in model_has_roles but NOT the legacy set
 *                       (the single source would GRANT access the legacy union
 *                       did not — usually benign, but flagged for review).
 *
 * Zero rows across a covered soak (all user categories, ≥2 Schools, HTTP+queue)
 * is the only evidence that permits the column-drop migration.
 */
class SchoolAccessParity
{
    public const CHANNEL = 'school-access-parity';

    public static function compare(User $user, Collection $legacy, Collection $singleSource): void
    {
        $legacyIds = $legacy->map(fn ($id) => (int) $id)->unique();
        $roleIds = $singleSource->map(fn ($id) => (int) $id)->unique();

        foreach ($legacyIds->diff($roleIds) as $schoolId) {
            self::record($user, (int) $schoolId, true, false, 'lost');
        }

        foreach ($roleIds->diff($legacyIds) as $schoolId) {
            self::record($user, (int) $schoolId, false, true, 'gained');
        }
    }

    private static function record(User $user, int $schoolId, bool $old, bool $new, string $reason): void
    {
        Log::channel(self::resolveChannel())->warning('school-access parity mismatch', [
            'user_id' => $user->getKey(),
            'school_id' => $schoolId,
            'old_has_access' => $old,     // legacy union (school_user | users.school_id | guardian)
            'new_has_access' => $new,     // model_has_roles single source
            'source' => 'legacy_union_vs_model_has_roles',
            'reason' => $reason,          // "lost" = legacy-only (revocation risk); "gained" = roles-only
        ]);
    }

    /**
     * Use the dedicated channel when configured, else fall back to the default
     * stack so the soak never fails for want of a log channel.
     */
    private static function resolveChannel(): string
    {
        return config('logging.channels.'.self::CHANNEL) ? self::CHANNEL : config('logging.default');
    }
}
