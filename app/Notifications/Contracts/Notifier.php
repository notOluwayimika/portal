<?php

namespace App\Notifications\Contracts;

use App\Notifications\Models\Notification as NotificationRecord;

/**
 * THE MODULE'S ONE PUBLIC ENTRY POINT.
 *
 * Other modules depend on this interface, never on the implementation in
 * `App\Notifications\Services` — module internals are private (module blueprint
 * §9/§10, and the same rule the armed Finance arch rules already enforce in the
 * other direction). `NotificationsArchTest` holds this side of the boundary.
 *
 * Deliberately one method. Everything a call site could want to vary — who
 * receives it, over which channels, whether it is bundled — is declared by the
 * notification's TYPE, so there is nothing else to pass and no way for a caller
 * to reach past the registry.
 */
interface Notifier
{
    /**
     * Raise a notification.
     *
     * Returns the persisted event row, or NULL when the subsystem is switched
     * off — it ships dark, so a caller must not treat null as failure.
     */
    public function send(Notification $notification): ?NotificationRecord;
}
