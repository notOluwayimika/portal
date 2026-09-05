<?php

namespace App\Notifications\Types;

use App\Notifications\Contracts\Notification;
use App\Notifications\Enums\NotificationType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment has been recorded against a student's account, and their guardians are told.
 *
 * ── THE SUBJECT IS THE PAYMENT, AND THE RECIPIENTS COME FROM THE STUDENT ──
 *
 * `GuardiansOfStudentResolver` reads `student_id` from the payload, so `payload()` carries it. One
 * notification per PAYMENT: a guardian with two children who pays both bills gets two, each naming
 * its own child, for the same reason `ResultReady` does not collapse per guardian.
 *
 * ── THE AMOUNT IN THE PAYLOAD IS WHAT THE BILL WAS CREDITED ──
 *
 * Not the gross the payer was charged. Under parent-bears those differ by the provider's fee, and
 * `finance_payments.amount_minor` is the credited figure. Telling a parent the school "received" the
 * gross would misstate what it got; the gross was already explained on the confirmation screen.
 *
 * ── IT IS DISPATCHED ONLY BY THE WINNING CLAIM ──
 *
 * `SettleGatewayTransaction::claim()` is a compare-and-swap on `payment_id IS NULL` whose
 * affected-row count is the answer. Paystack redelivers, and since #370 every redelivery re-verifies
 * — so a notification fired per DELIVERY would reach a parent once per retry. It is fired by the one
 * caller that won, which is the same place the money is written.
 */
final class PaymentReceived implements Notification
{
    public function __construct(
        /**
         * The payment row, as a bare `Model`.
         *
         * NOT TYPE-HINTED `Payment`, and that is the arch boundary rather than a preference:
         * `NotificationsArchTest` asserts `App\Notifications` does not use `App\Finance`, so this
         * module cannot name a Finance model even to describe its own subject. The first version of
         * this class did, and three arch arms refused it.
         *
         * IT HAS A USEFUL SIDE EFFECT worth stating: the migrated-payment refusal CANNOT migrate
         * into this class later, because nothing here can ask a payment about its origin. The guard
         * is Finance-side at the dispatch site by construction, not by discipline.
         */
        private readonly Model $subject,
        private readonly int $schoolId,
        private readonly int $studentId,
        private readonly int $paymentId,
        /** `App\Support\Money` is shared-kernel, not Finance — it crosses this boundary legally. */
        private readonly Money $amount,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::PAYMENT_RECEIVED;
    }

    public function schoolId(): int
    {
        return $this->schoolId;
    }

    /** Narrower than the contract's `?Model`: a payment notification always has its payment. */
    public function subject(): Model
    {
        return $this->subject;
    }

    /**
     * NO ACTOR. A gateway payment has no staff causer — the payer is the recipient's own household,
     * and `excludeActor` would otherwise need a user id that does not exist. `RecordPayment` writes
     * `received_by_user_id` as null on this origin for the same reason.
     */
    public function actorId(): ?int
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'student_id' => $this->studentId,
            'payment_id' => $this->paymentId,
            // THE CREDITED FIGURE, in minor units and its currency — the wire shape money always
            // takes here, never a formatted string. The feed formats at read time.
            'amount_minor' => $this->amount->toKobo(),
            'amount_currency' => $this->amount->currency,
        ];
    }

    /**
     * ONE PAYMENT, ONE NOTIFICATION — and the key carries NO recipient identifier.
     *
     * ── THE FAILURE HERE IS THE MIRROR OF `ResultReady`'s, NOT THE SAME ONE ──
     *
     * `ResultReady` warns that a recipient in the key DESTROYS data, and it is right for its axes:
     * one guardian with three children produces the SAME key three times, so the second and third
     * collide on the UNIQUE index and those children's rows vanish.
     *
     * This type's axes are inverted — one payment, several guardians — so a key of
     * `payment.received:{$paymentId}:{$guardianId}` would be DISTINCT per guardian and collide with
     * nothing. It would OVER-PRODUCE: N notification rows for one event, and the fan-out then
     * produces its own deliveries for each, so a household with two guardians on the account sees
     * the payment announced twice on the feed and twice by email.
     *
     * Both are wrong and the fix is the same — the key identifies the EVENT, and fanning out to
     * recipients is the layer below it. Written out rather than borrowed, because the borrowed
     * sentence describes a collision this type cannot have, and a reader checking it against the
     * code would find the words do not match what is in front of them.
     */
    public function dedupKey(): string
    {
        return 'payment.received:'.$this->paymentId;
    }

    /**
     * No stored fallback: the feed renders the child's name and the amount from the payload at read
     * time, so a row can never be stale, and no payer name or naira figure is written into a JSON
     * column that lands in every backup.
     */
    public function renderedFallback(): ?string
    {
        return null;
    }
}
