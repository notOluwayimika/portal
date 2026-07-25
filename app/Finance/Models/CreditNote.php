<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Enums\CreditNoteStatus;
use App\Finance\Exceptions\LedgerImmutableException;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A credit note (or write-off) issued against an invoice — its OWN aggregate (§10 C1),
 * never an allocation. It carries a compensating credit against the receivable; the
 * invoice it references stays frozen (F6).
 *
 * Ph3 — MAKER-CHECKER lifecycle. No longer plain append-only: a credit note is a money
 * DOCUMENT, so it is money-immutable but STATUS-mutable, exactly like {@see Invoice}.
 * The money/identity columns never change (DB trigger + not a mass-assignable path); only
 * the decision columns (status / decided_by / decided_at / rejection_reason) move, and
 * only along the legal {@see self::TRANSITIONS}. DELETE stays denied at both layers. A
 * mis-issue is corrected by an opposing entry later, never by a money edit.
 *
 * Money moves ONLY on approval: submit posts no ledger entry; ApproveCreditNote posts the
 * compensating credit in one transaction with the status flip; RejectCreditNote never does.
 *
 * `number` is the stored per-School sequence; the human form is presentation-derived
 * (displayNumber, `CN-000001`), NEVER stored — same rule as the invoice number.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $student_id
 * @property int $invoice_id
 * @property int $number
 * @property Money $amount
 * @property CreditNoteKind $kind
 * @property string|null $note
 * @property CreditNoteStatus $status
 * @property int|null $submitted_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $rejection_reason
 * @property int|null $created_by_user_id
 * @property Carbon $created_at
 */
class CreditNote extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_credit_notes';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
        'kind' => CreditNoteKind::class,
        'status' => CreditNoteStatus::class,
        'decided_at' => 'datetime',
    ];

    /**
     * Legal state machine (mirrors SubjectResultStatus::TRANSITIONS). A credit note is
     * created directly in `submitted` — there is no `draft` because a credit note is a
     * complete proposal made in one action, not accumulated like scores. Both `approved`
     * and `rejected` are TERMINAL: a rejected proposal stays for audit; the maker submits
     * a fresh note rather than resurrecting it.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'submitted' => ['approved', 'rejected'],
        'approved' => [],
        'rejected' => [],
    ];

    /**
     * Model-level defense-in-depth: DELETE is denied (a money document is never deleted;
     * a mis-issue is reversed). Money-immutability is the DB trigger's job (the real
     * guarantee), exactly as Invoice relies on finance_invoices_total_immutable — so
     * UPDATE is permitted here for the decision-column transitions.
     */
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LedgerImmutableException('DELETE');
        });
    }

    /**
     * The credit-note number prefix. A GLOBAL constant, distinct from the invoice's
     * per-School prefix so a credit note is never mistaken for an invoice. It is not a
     * per-School setting because there is no consumer for configuring it yet — adding a
     * settings column with no reader is the front-load mistake §7 avoids; it stays a
     * constant until a School needs to change it (an additive column then).
     */
    public const NUMBER_PREFIX = 'CN';

    /** Minimum width of the numeric portion — a MINIMUM, not a maximum (see Invoice). */
    public const NUMBER_PAD_WIDTH = 6;

    /**
     * The number as a human reads it: `CN-000042`. PRESENTATION-DERIVED, never stored —
     * `finance_credit_notes.number` remains the integer the UNIQUE(school_id, number)
     * and the Sequences kernel depend on. str_pad pads to a MINIMUM and otherwise returns
     * the string unchanged, so a number outgrowing six digits renders in full.
     */
    public function displayNumber(): string
    {
        return self::NUMBER_PREFIX.'-'.str_pad((string) $this->number, self::NUMBER_PAD_WIDTH, '0', STR_PAD_LEFT);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === CreditNoteStatus::Submitted;
    }

    public function isApproved(): bool
    {
        return $this->status === CreditNoteStatus::Approved;
    }

    /** Is $to a legal next state from the current status? */
    public function canTransitionTo(CreditNoteStatus $to): bool
    {
        // $this->status is a CreditNoteStatus enum, so its value is always one of the
        // TRANSITIONS keys — the offset is guaranteed, no null-coalesce needed.
        return in_array($to->value, self::TRANSITIONS[$this->status->value], true);
    }

    /**
     * Apply a decision (approve / reject), recording the checker in decided_by and
     * stamping decided_at. Mirrors SubjectResultStatus::transitionTo: an illegal
     * transition throws, and a rejection with no reason is refused at the domain layer
     * (so no call site can persist a reasonless rejection). This performs ONLY the status
     * write — the caller (ApproveCreditNote) posts the ledger credit in the SAME
     * transaction, so money and the decision commit together or not at all.
     *
     * decided_by is the CHECKER; the DB CHECK (submitted_by <> decided_by) and the Policy
     * both refuse a checker who is the maker, so this method never needs to re-check it.
     */
    public function transitionTo(CreditNoteStatus $to, int $checkerId, ?string $reason = null): void
    {
        if (! $this->canTransitionTo($to)) {
            throw new \DomainException("Cannot transition credit note from [{$this->status->value}] to [{$to->value}].");
        }

        if ($to === CreditNoteStatus::Rejected && ($reason === null || trim($reason) === '')) {
            throw new \InvalidArgumentException('A rejection reason is required.');
        }

        $this->update([
            'status' => $to,
            'decided_by' => $checkerId,
            'decided_at' => now(),
            'rejection_reason' => $to === CreditNoteStatus::Rejected ? $reason : null,
        ]);
    }
}
