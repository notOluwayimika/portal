<?php

namespace App\Notifications\Services;

use App\Models\User;
use App\Notifications\DTOs\Recipient;
use App\Notifications\DTOs\TypeDefinition;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\Models\NotificationPreference;

/**
 * Does this recipient want this type on this channel?
 *
 * PRECEDENCE, most specific first:
 *
 *   1. type is not userConfigurable  → ALWAYS send (transactional obligation)
 *   2. preference row (type, channel) → its value
 *   3. preference row ('*',  channel) → its value  (the whole-channel switch)
 *   4. otherwise                      → the type's default channels
 *
 * WHAT IS DELIBERATELY NOT HERE: suppression. A legal opt-out — SMS STOP, a hard
 * bounce, an unsubscribe — is a separate, TERMINAL gate that arrives with the
 * first intrusive channel in v2. Keeping the two apart is the point: a school may
 * force a fee reminder past a PREFERENCE, and may never force it past a
 * SUPPRESSION. Consent law beats school policy, so the two must not share a code
 * path where one could ever be mistaken for the other.
 */
class PreferenceGate
{
    public function allows(Recipient $recipient, TypeDefinition $definition, ChannelKey $channel, int $schoolId): bool
    {
        // A transactional type is not a subscription. The preferences UI shows
        // these locked with the reason rather than offering a toggle that is
        // quietly ignored.
        if (! $definition->userConfigurable) {
            return true;
        }

        // Preferences are per USER. A non-user notifiable has none, so it falls
        // through to the type default.
        if ($recipient->notifiableType !== User::class) {
            return in_array($channel, $definition->defaultChannels, true);
        }

        $rows = NotificationPreference::query()
            ->where('user_id', $recipient->notifiableId)
            ->where('school_id', $schoolId)
            ->where('channel', $channel->value)
            ->whereIn('type', [$definition->type->value, NotificationPreference::ALL_TYPES])
            ->get()
            ->keyBy('type');

        if ($row = $rows->get($definition->type->value)) {
            return (bool) $row->enabled;
        }

        if ($row = $rows->get(NotificationPreference::ALL_TYPES)) {
            return (bool) $row->enabled;
        }

        return in_array($channel, $definition->defaultChannels, true);
    }
}
