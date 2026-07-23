<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Services\SubledgerPoster;
use App\Models\User;
use App\Support\Money;
use App\Support\Sequences\Sequences;
use Illuminate\Support\Facades\DB;

/**
 * Issue one credit note (or write-off) against an invoice and post its compensating
 * ledger credit — the credit note, its number, and the ledger entry commit together.
 *
 * The invoice is NOT modified (F6): the credit is a separate document beside it. The
 * ledger credit (−amount) reduces the receivable via SubledgerPoster, whose atomic
 * increment (W1+W2) moves the account balance; if that balance goes negative the excess
 * is an account credit balance — the wallet — which W3 carries forward to the next
 * invoice. No allocation, no payment_id: a credit note is sourced to ITSELF.
 */
final class IssueCreditNote
{
    public function __construct(private readonly SubledgerPoster $ledger) {}

    public function handle(Invoice $invoice, Money $amount, CreditNoteKind $kind, ?string $note, User $actor): CreditNote
    {
        if ($amount->isZero() || $amount->isNegative()) {
            throw new BusinessRuleException('A credit note amount must be positive.');
        }

        // A void invoice's charge is already fully reversed in the ledger — crediting it
        // again would double-count. Mirrors RecordPayment's not-void guard.
        if ($invoice->isVoid()) {
            throw new BusinessRuleException('Cannot issue a credit note against a void invoice.');
        }

        return DB::transaction(function () use ($invoice, $amount, $kind, $note, $actor) {
            // Ceiling anchor (the #94 footprint). Lock the INVOICE ROW first so concurrent
            // credit notes to the same invoice serialise: the loser blocks here, then reads
            // the winner's committed sum and is rejected. The account is touched only by
            // post()'s atomic increment (no separate account decision-lock needed), so the
            // account-before-invoice convention is not violated — this action holds only
            // the invoice row.
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            // Σ of existing credits for this invoice (through the model, not DB::table —
            // the boundary lint forbids that escape hatch in app/Finance; CreditNote is
            // School-scoped and we are in the invoice's School context).
            $alreadyCredited = (int) CreditNote::query()
                ->where('invoice_id', $locked->id)
                ->sum('amount_minor');

            // Friendly 422; the DB trigger is the real guarantee. Σcredits ≤ total — an
            // INDEPENDENT ceiling from #94's Σallocations ≤ total.
            if ($alreadyCredited + $amount->toKobo() > $locked->total->toKobo()) {
                throw new BusinessRuleException(
                    'This credit note would exceed the invoice total. The invoice can still be credited by at most '
                    .($locked->total->toKobo() - $alreadyCredited).' minor units.'
                );
            }

            $number = Sequences::next('finance_credit_note', (string) $invoice->school_id);

            $creditNote = CreditNote::create([
                'school_id' => $invoice->school_id,
                'student_id' => $invoice->student_id,
                'invoice_id' => $locked->id,
                'number' => $number,
                'amount' => $amount,
                'kind' => $kind,
                'note' => $note,
                'created_by_user_id' => $actor->id,
            ]);

            // The compensating credit — a credit note reduces the receivable, so the
            // ledger amount is negative. Sourced to the credit note itself (no allocation).
            $this->ledger->post(
                $invoice->school_id,
                $invoice->student_id,
                LedgerEntryType::CreditNote,
                $amount->times(-1),
                'credit_note',
                (int) $creditNote->getKey(),
                "Credit note #{$creditNote->displayNumber()} against invoice #{$invoice->number}",
            );

            return $creditNote;
        });
    }
}
