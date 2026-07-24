<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Exceptions\LedgerImmutableException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Finance-owned invoice bound to one enrollment episode. Never deleted (voiding
 * is a status change + a reversing ledger entry); its money and lines are
 * immutable, only its status and void metadata mutate.
 *
 * `total` is the SNAPSHOT of SUM(lines), derived once inside the creating
 * transaction (F6) and thereafter immutable — the `finance_invoices_total_immutable`
 * BEFORE UPDATE trigger denies any change to the money columns at the DB.
 *
 * `active_enrollment_key` is a STORED GENERATED column (= student_curriculum_id
 * while issued, NULL once void) carrying a UNIQUE(school_id, active_enrollment_key).
 * It is the DB expression of the set-based invariant "at most one ACTIVE invoice
 * per enrollment episode" — read-only here; never write it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $student_id
 * @property int $student_curriculum_id
 * @property int $number
 * @property InvoiceStatus $status
 * @property string $billed_to_name
 * @property string $academic_context
 * @property Money $total
 * @property int|null $active_enrollment_key
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancel_reason
 * @property Carbon $created_at
 */
class Invoice extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_invoices';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'total' => MoneyCast::class.':total_minor,total_currency',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Un-deletable at the model layer too (the DB trigger is primary).
        static::deleting(function () {
            throw new LedgerImmutableException('DELETE');
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * Minimum width of the numeric portion of a rendered invoice number.
     *
     * A MINIMUM, NOT A MAXIMUM — and a GLOBAL constant, not per-School. Padding is a
     * formatting convention, so it lives here rather than as a column on
     * `finance_school_settings`: the prefix is tenant data, the width is not.
     */
    public const NUMBER_PAD_WIDTH = 6;

    /**
     * The number as a human reads it: `BSS-000042`.
     *
     * PRESENTATION-DERIVED, NEVER STORED. `finance_invoices.number` remains the
     * integer that `UNIQUE(school_id, number)` and the Sequences kernel depend on;
     * storing the rendered form would have meant altering a deployed table, backfilling
     * live invoices, and re-deciding the unique index — for a string that can simply be
     * composed on the way out.
     *
     * Three format rules, all from the signed policy §2:
     *
     * 1. The number is zero-padded to a MINIMUM of NUMBER_PAD_WIDTH digits. `str_pad`
     *    is deliberate: it pads up to the width and otherwise returns the string
     *    UNCHANGED, so 1_000_000 renders `1000000` in full rather than being truncated
     *    or wrapped. A fixed-width formatter (`%06d` is fine, but a substr/wrap is not)
     *    would silently change format the day a School's numbering outgrew six digits.
     * 2. The separator is added HERE, not stored. Prefixes are stored separator-less
     *    (`BSS`, `BSP`, `BSPH`, `BSA`) so all four are uniform and the `-` is defined in
     *    one place, rather than depending on each registrar typing a trailing dash.
     * 3. Only the NUMBER is padded — the prefix never is.
     *
     * Defensive: a prefix still carrying a trailing `-` from the earlier mixed model is
     * normalised, so it renders `BSS-000042` and never `BSS--000042`.
     */
    public function displayNumber(): string
    {
        $padded = str_pad((string) $this->number, self::NUMBER_PAD_WIDTH, '0', STR_PAD_LEFT);

        $prefix = SchoolFinanceSettings::invoiceNumberPrefixFor((int) $this->school_id);

        // No prefix configured — the bare padded number, with no leading separator.
        if ($prefix === null) {
            return $padded;
        }

        return rtrim($prefix, '-').'-'.$padded;
    }

    public function isVoid(): bool
    {
        return $this->status === InvoiceStatus::Void;
    }

    /**
     * Exclude voided invoices — the reporting default.
     *
     * Deliberately a NAMED scope, not a global scope. Voidness is a *reporting*
     * concern, not an *existence* one: a global scope would make route-model
     * binding on {invoice:uuid} miss a voided invoice, turning the double-void
     * guard's 422 into a 404 and silently destroying the guard this slice adds.
     * Read models opt in; the audit view simply does not.
     */
    public function scopeExcludingVoid(Builder $query): Builder
    {
        return $query->where('status', '!=', InvoiceStatus::Void->value);
    }
}
