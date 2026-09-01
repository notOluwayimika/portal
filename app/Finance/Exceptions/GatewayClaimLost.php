<?php

namespace App\Finance\Exceptions;

use RuntimeException;

/**
 * Thrown inside the settlement transaction when the compare-and-swap affects zero rows: another
 * delivery claimed this gateway transaction between the lock and the swap.
 *
 * IT EXISTS TO ROLL BACK, which is why it is a throw and not a return. By the time the swap runs, a
 * Payment row and a ledger entry have already been written in this transaction. Returning would
 * commit them — a payment against the invoice with nothing linking it to the gateway transaction,
 * which is the double-count this whole path is built to prevent. The throw unwinds both.
 *
 * The controller catches it and answers 200. It is a race resolving correctly, not a fault.
 */
final class GatewayClaimLost extends RuntimeException {}
