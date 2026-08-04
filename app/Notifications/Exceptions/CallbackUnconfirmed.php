<?php

namespace App\Notifications\Exceptions;

use RuntimeException;

/**
 * The external service did not give us a trustworthy answer in time.
 *
 * NOT A FAILURE — an UNKNOWN. The request may have been received and acted upon, so
 * the only honest record is UNCONFIRMED, and the only safe behaviour is not to retry
 * blind. A retry would be a second revocation attempt against a service that may have
 * already honoured the first.
 */
class CallbackUnconfirmed extends RuntimeException {}
