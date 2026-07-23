<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Models\Concerns\AppendOnly;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A credit note (or write-off) issued against an invoice — its OWN append-only
 * aggregate (§10 C1), never an allocation. It carries a compensating credit against
 * the receivable; the invoice it references stays frozen (F6). Immutable at the DB
 * (1.4c triggers) and the model (AppendOnly): a mis-issue is corrected by an opposing
 * entry later, never by an edit.
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
 * @property int|null $created_by_user_id
 * @property Carbon $created_at
 */
class CreditNote extends Model
{
    use AddUuid, AppendOnly, BelongsToSchool;

    protected $table = 'finance_credit_notes';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
        'kind' => CreditNoteKind::class,
    ];

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
}
