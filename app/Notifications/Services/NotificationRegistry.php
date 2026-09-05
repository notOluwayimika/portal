<?php

namespace App\Notifications\Services;

use App\Notifications\Contracts\RecipientResolver;
use App\Notifications\DTOs\TypeDefinition;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\Enums\NotificationType;
use App\Notifications\Services\Resolvers\CheckerAbilityResolver;
use App\Notifications\Services\Resolvers\GuardiansOfStudentResolver;
use RuntimeException;

/**
 * The catalogue: what each notification type resolves to, and over which channels.
 *
 * A TYPE WITH NO DEFINITION THROWS. `NotificationType` is vocabulary — a case may
 * be declared a phase before it is implemented — so dispatching an unimplemented
 * type must fail loudly at the call site rather than resolve to zero recipients
 * and look like a working send that nobody received.
 *
 * v1 defines two types, chosen because between them they exercise both resolver
 * shapes everything else reuses: APPROVAL_REQUESTED is permission-derived,
 * RESULT_READY is relationship-derived.
 */
class NotificationRegistry
{
    /** @var array<string, TypeDefinition>|null */
    private ?array $definitions = null;

    /** @return array<string, TypeDefinition> */
    public function all(): array
    {
        return $this->definitions ??= $this->define();
    }

    public function has(NotificationType $type): bool
    {
        return isset($this->all()[$type->value]);
    }

    public function get(NotificationType $type): TypeDefinition
    {
        return $this->all()[$type->value] ?? throw new RuntimeException(
            "Notification type [{$type->value}] has no definition. A case in the enum is "
            .'vocabulary, not an implementation — register it in NotificationRegistry '
            .'(resolver + channels) before dispatching it.'
        );
    }

    public function resolverFor(NotificationType $type): RecipientResolver
    {
        return app($this->get($type)->resolver);
    }

    /** @return array<string, TypeDefinition> */
    private function define(): array
    {
        $definitions = [
            new TypeDefinition(
                type: NotificationType::APPROVAL_REQUESTED,
                resolver: CheckerAbilityResolver::class,
                // EMAIL joins IN_APP now the channel exists. A checker is not
                // sitting in the portal waiting; the whole point of notifying them
                // is to reach them where they are.
                defaultChannels: [ChannelKey::IN_APP, ChannelKey::EMAIL],
                // An approval request is an obligation of the role, not a
                // subscription — a checker cannot opt out of being asked.
                userConfigurable: false,
                // Mandatory here, not merely polite: `submitted_by <> decided_by`
                // is enforced at the database, so the submitter CANNOT decide
                // their own request. Notifying them invites a refused action.
                excludeActor: true,
            ),
            new TypeDefinition(
                type: NotificationType::RESULT_READY,
                resolver: GuardiansOfStudentResolver::class,
                defaultChannels: [ChannelKey::IN_APP, ChannelKey::EMAIL],
                userConfigurable: true,
                excludeActor: true,
            ),
            new TypeDefinition(
                type: NotificationType::PAYMENT_RECEIVED,
                // The same relationship shape as RESULT_READY: the payload names a student and the
                // resolver reads their guardians. Guardians PLURAL and school-scoped — the resolver
                // relies on the global SchoolScope inside `ActiveSchool::runFor` rather than
                // filtering `users.school_id`, which is the legacy fallback and simply wrong for a
                // parent with children at two schools.
                resolver: GuardiansOfStudentResolver::class,
                defaultChannels: [ChannelKey::IN_APP, ChannelKey::EMAIL],
                // NOT CONFIGURABLE — a receipt is an obligation, not a subscription. A parent may
                // not opt out of being told money left their account, and the preferences UI shows
                // it locked with the reason rather than offering a toggle that is ignored.
                userConfigurable: false,
                // NO ACTOR EXISTS on a gateway payment: the payer is the recipient's own household
                // and `received_by_user_id` is null on this origin. Left true because the exclusion
                // is a no-op against a null actor, and flipping it would state something false
                // about the type.
                excludeActor: true,
            ),
        ];

        return collect($definitions)->keyBy(fn (TypeDefinition $d) => $d->type->value)->all();
    }
}
