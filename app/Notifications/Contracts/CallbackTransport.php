<?php

namespace App\Notifications\Contracts;

use App\Notifications\DTOs\CallbackResult;
use App\Notifications\Exceptions\CallbackUnconfirmed;
use App\Notifications\Models\NotificationAction;

/**
 * Relays a claimed action to the external service that owns the decision.
 *
 * AN INTERFACE BECAUSE THE TEST SEAM IS THE POINT. The resolver's exactly-once
 * guarantee has to be verifiable without a network, and "the callback fired exactly
 * once" is only checkable against a double that counts. Binding a fake is how the
 * concurrency tests assert the thing that actually matters.
 *
 * TIMEOUT IS AN EXCEPTION, NOT A RESULT — and that distinction is the contract's most
 * important line. A `CallbackResult` means the service reached a DECISION. A timeout
 * means we do not know: the request may have been received and acted upon. Modelling
 * it as a result would force implementations to invent an answer, and the invented
 * answer would eventually be "failed", which is the dangerous direction — telling a
 * parent their revoke did not happen when it may have.
 */
interface CallbackTransport
{
    /**
     * @throws CallbackUnconfirmed when the service did not answer in time, or the
     *                             answer could not be trusted. The caller records UNCONFIRMED and reconciles
     *                             later; it must not retry blind, because the first attempt may have landed.
     */
    public function send(NotificationAction $action): CallbackResult;
}
